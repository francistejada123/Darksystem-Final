<?php
// Separate general-purpose Teacher AI endpoint. This does not use Ask AI's
// student-risk workflow or its conversation endpoint.
//
// Replies come back in one of three shapes (all carry `reply`, so older
// clients keep working):
//   mode "text"     an ordinary answer or a clarifying question (unchanged).
//   mode "resource" the model generated a lesson / quiz / activity / assessment /
//                   learning material. It is saved as this teacher's Draft and the
//                   browser offers PDF / Word / PowerPoint / Google Form, then
//                   Send to Students (modules/teachers/teacher_ai_resource.php).
//   mode "convert"  "Generate this as PDF" style commands about the draft already
//                   on screen: no Gemini call, the browser just creates the file.
// Nothing here creates a file or sends anything to a student.
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/gemini_service.php';
require_once __DIR__ . '/../../includes/student_activity_helpers.php';
require_once __DIR__ . '/../../includes/teacher_ai_resources.php'; // generate -> file/Form -> send to class
require_once __DIR__ . '/../../includes/ai_chat_attachment.php';   // optional "+" file (image / PDF / Word / text)
require_once __DIR__ . '/../../includes/assessment_material_helpers.php'; // Quiz/Examination tasks: Missing status + exam topic coverage

if (!defined('TEACHER_AI_MODEL')) {
    define('TEACHER_AI_MODEL', GEMINI_MODEL);
}
if (!defined('TEACHER_AI_TIMEOUT_SECONDS')) {
    define('TEACHER_AI_TIMEOUT_SECONDS', 60);
}

/**
 * Teacher AI focused-topic analysis: names the specific skill/subtopic each
 * ACTUAL quiz question tests (from gh_topicItemLevelEvidence()), so the
 * questions can be grouped by skill. A quiz question that already carries
 * its own `skill` is used as-is. Only labels are asked of the model -- every
 * percentage is computed in PHP. Returns [] on any failure (the caller then
 * falls back to the lowest individual items).
 * @return array "<item_id>:<question number>" => skill name
 */
function teacherAiLabelQuestionSkills($topic, array $evidence)
{
    $labels = [];
    $toLabel = [];
    foreach ($evidence['items'] ?? [] as $item) {
        foreach ($item['questions'] as $q) {
            $key = $item['item_id'] . ':' . $q['number'];
            if ($q['skill'] !== '') {
                $labels[$key] = mb_substr($q['skill'], 0, 60);
            } elseif ($q['text'] !== '' && count($toLabel) < 60) {
                $toLabel[$key] = $q['text'];
            }
        }
    }
    if (!$toLabel) {
        return $labels;
    }

    $schema = [
        'type' => 'object',
        'properties' => [
            'labels' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => ['key' => ['type' => 'string'], 'skill' => ['type' => 'string']],
                    'required' => ['key', 'skill'],
                ],
            ],
        ],
        'required' => ['labels'],
    ];
    $system = "You classify quiz questions by the specific skill or subtopic each one tests, inside ONE topic.\n"
        . "RESPOND WITH ONLY A SINGLE JSON OBJECT: {\"labels\": [{\"key\": the given key, \"skill\": a short skill name of 2-5 words}]} with exactly one entry per given key.\n"
        . "Base each label only on what that question actually asks. Give questions that test the same skill the identical skill name, and use at most 5 different skill names in total. "
        . "Skill names must be specific parts of the topic (e.g. for 'Pros and Cons': 'Identifying Pros', 'Identifying Cons', 'Weighing Pros and Cons'), written in the same language as the questions. Never mention students, scores or grades.";
    $input = "Topic: " . $topic . "\nQuestions:\n";
    foreach ($toLabel as $key => $text) {
        $input .= $key . ' | ' . $text . "\n";
    }

    $api = callGeminiAPI($system, $input, ['type' => 'text', 'mime_type' => 'application/json', 'schema' => $schema], 45);
    if (empty($api['success'])) {
        return $labels ?: [];
    }
    $text = extractGeminiRawText($api['response'] ?? null);
    if ($text === null) {
        return $labels ?: [];
    }
    $text = trim(preg_replace('/\s*```$/', '', preg_replace('/^```(?:json)?\s*/i', '', trim($text))));
    $decoded = json_decode($text, true);
    foreach ((is_array($decoded) ? ($decoded['labels'] ?? []) : []) as $row) {
        $key = (string) ($row['key'] ?? '');
        $skill = trim(preg_replace('/\s+/', ' ', (string) ($row['skill'] ?? '')));
        if (isset($toLabel[$key]) && $skill !== '') {
            $labels[$key] = mb_substr($skill, 0, 60);
        }
    }
    // Every question must be labeled, or the grouping would silently drop items.
    foreach ($toLabel as $key => $unused) {
        if (!isset($labels[$key])) {
            return [];
        }
    }
    return $labels;
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isLoggedIn() || !hasRole('Teacher')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Teacher AI is available only to authenticated teachers.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$csrfToken = (string)($payload['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your session token expired. Refresh the page and try again.']);
    exit;
}
$message = trim((string)($payload['message'] ?? ''));
$history = is_array($payload['history'] ?? null) ? $payload['history'] : [];

// ---- Student Monitoring dashboard (data only, no Gemini call). See the
// short-circuit further below, right after the roster/topic data is built.
// A separate "Analyze this topic" click goes through the normal message
// flow below it instead, reusing the same Teacher AI/Gemini setup. ----
$dashboardAction = trim((string)($payload['dashboard'] ?? ''));
// "Ask Teacher AI to analyze this topic" (from a Needs Improvement topic on
// the dashboard). Only a label; it is matched against this teacher's own
// tagged topics below and ignored if it does not match one.
$focusTopic = mb_substr(trim((string)($payload['focus_topic'] ?? '')), 0, 150);
// "AI Recommendation" on one student's weak topic (Students to Monitor on
// the dashboard). Only used together with focus_topic, and only when the
// student is on this teacher's own roster and really has that topic.
$focusStudentId = (int)($payload['focus_student_id'] ?? 0);
$reviewTarget = null; // set when the answer is one student's AI Recommendation (it can then be sent to that student)

// Optional file attached with the "+" button. Validated by its own bytes and
// used for this one request only -- never stored.
$attachmentRead = aiChatReadAttachment($payload);
if (!$attachmentRead['ok']) {
    echo json_encode(['success' => false, 'error' => $attachmentRead['error']]);
    exit;
}
$attachment = $attachmentRead['attachment'];
$attachmentFilePart = aiChatAttachmentFilePart($attachment);
if ($attachment && $message === '') {
    $message = 'Please review the attached file.';
}

if ($message === '' && $dashboardAction === '') {
    echo json_encode(['success' => false, 'error' => 'Please enter a question.']);
    exit;
}
if (mb_strlen($message) > 2000) {
    echo json_encode(['success' => false, 'error' => 'Please keep your question under 2,000 characters.']);
    exit;
}

$teacherId = (int)$_SESSION['user_id'];

// ---- Subject mode (optional). The teacher picked one of THEIR subjects on the
// Teacher AI screen; everything below is then limited to that subject. The code
// is never trusted: it must be one of this teacher's assigned subjects. ----
$subjectMode = null;
$postedSubject = trim((string)($payload['subject_code'] ?? ''));
if ($postedSubject !== '') {
    $subjectMode = teacherAiTeacherSubject($teacherId, $postedSubject);
    if (!$subjectMode) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'That subject is not assigned to you this school year. Choose one of your subjects.']);
        exit;
    }
}
// ---- "<item> AI" (Manage Assessment Items -> "Send to <item> AI"). The
// item is re-read and must be this teacher's own; its subject becomes the
// subject mode. Examinations are manual only and never generated here. ----
$assessCtx = null;
$assessItemId = (int)($payload['assessment_item_id'] ?? 0);
$assessStart = !empty($payload['assessment_start']);
if ($assessItemId > 0) {
    $assessCtx = aim_itemContext($assessItemId, $teacherId);
    if (!$assessCtx) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'That assessment item could not be found in your Manage Assessment Items.']);
        exit;
    }
    if ($assessCtx['kind'] === 'exam') {
        echo json_encode(['success' => false, 'error' => 'Examinations are manual only: conduct them in class and encode the scores in Grade Management.']);
        exit;
    }
    if (!$subjectMode || $subjectMode['subject_code'] !== $assessCtx['item']['subject_code']) {
        $subjectMode = teacherAiTeacherSubject($teacherId, $assessCtx['item']['subject_code']) ?: $subjectMode;
    }
}
$subjectFilter = $subjectMode ? ' AND ts.subject_code = ?' : '';
$subjectParams = $subjectMode ? [$subjectMode['subject_code']] : [];

// ---- Generate -> create file/Form -> send flow inputs. All optional; every id is
// re-checked against this teacher's own rows, never trusted. ----
$allowedOutputs = ['pdf', 'docx', 'pptx', 'gform'];
$requestedOutput = teacherAiDetectOutput($message);
$postedOutput = (string)($payload['output_format'] ?? '');
if (in_array($postedOutput, $allowedOutputs, true)) {
    $requestedOutput = $postedOutput; // e.g. Regenerate on a PDF card
}
if ($assessCtx && $assessStart && $assessCtx['kind'] === 'quiz' && $requestedOutput === null) {
    $requestedOutput = 'gform'; // online quiz: prepare the auto-graded Google Form right away
}
$draft = null;
if ((int)($payload['draft_id'] ?? 0) > 0) {
    $draft = teacherAiGetResource($teacherId, (int)$payload['draft_id']);
}
$regenerateOf = null;
if ((int)($payload['regenerate_of'] ?? 0) > 0) {
    $regenerateOf = teacherAiGetResource($teacherId, (int)$payload['regenerate_of']);
    if ($regenerateOf) {
        $draft = $regenerateOf;
    }
}

// A draft made for another subject is never carried into this subject's chat.
if ($subjectMode && $draft && trim((string)($draft['subject_code'] ?? '')) !== '' && $draft['subject_code'] !== $subjectMode['subject_code']) {
    $draft = null;
    $regenerateOf = null;
}

// "Generate this as PDF / Word / PowerPoint / Google Form" about the draft on
// screen needs no new content -- skip the model (and the roster work below).
if ($requestedOutput !== null && $draft && !$regenerateOf && aiIsConversionCommand($message)) {
    $formatLabel = teacherAiFormatMeta($requestedOutput)['label'];
    echo json_encode([
        'success' => true,
        'mode' => 'convert',
        'reply' => 'Creating your ' . $formatLabel . ' from this resource...',
        'draft_id' => (int)($draft['parent_id'] ?: $draft['resource_id']),
        'auto_output' => $requestedOutput,
    ]);
    exit;
}

// Subject mode: a request that names another of the teacher's subjects is
// declined here (no model call). The model is given the same rule below.
if ($subjectMode) {
    $otherSubject = teacherAiOtherSubjectMentioned($teacherId, $subjectMode, $message);
    if ($otherSubject !== null) {
        echo json_encode([
            'success' => true,
            'mode' => 'text',
            'title' => $subjectMode['subject_name'],
            'reply' => '# Staying in ' . $subjectMode['subject_name'] . "\n\nThis chat is set to " . $subjectMode['subject_name']
                . ' only, so I can\'t help with ' . $otherSubject . ' here. Tap "Change subject" at the top to switch to ' . $otherSubject
                . ', or ask me something about ' . $subjectMode['subject_name'] . '.',
        ]);
        exit;
    }
}

// Requests to CREATE a resource never need the student roster (and class-wide
// material must not mention individual students), so the roster is only built
// for genuine student-analytics questions.
$isGenerationRequest = (bool)$regenerateOf || (bool)$assessCtx || (bool)preg_match(
    '/\b(create|generate|make|prepare|write|draft|design|gumawa|gawa|regenerate)\b.{0,80}\b(lesson|quiz|activity|activities|assessment|worksheet|reviewer|exam|test|learning material|material|performance task|rubric|study guide|question|questions|slides?|presentation|ppt|powerpoint|pdf|word|google form)\b/iu',
    $message
);
// Widened to also catch topic/competency-level performance questions (Manage
// Items' Topic + assessment_scores) -- not just attendance/overall-grade
// ones. A generation request is still excluded above, so "make a quiz about
// fractions" never pulls the roster.
$needsStudentContext = !$isGenerationRequest && preg_match(
    '/\b(attendance|absent|present|late|grades?(?!\s*(?:level|\d))|score|risk|failing|missing|inactive|roster|class list|who is|sino|mag-aaral|marka|grado|bese|liban'
    . '|topic|competency|lesson|weak|strong|struggl|improv|declin|progress|trend|quiz|activit|perform|monitor'
    . '|mahina|mahusay|pag-unlad|bumaba|umangat|analysis|analyze)\b/i',
    $message
);
if ($dashboardAction !== '' || $focusTopic !== '' || $focusStudentId > 0) {
    $needsStudentContext = true; // the dashboard / focused topic always needs the roster + topic data
}
$activeSy = getRecord("SELECT sy_id, sy_name FROM school_years WHERE is_active = 1");
$syId = (int)($activeSy['sy_id'] ?? 0);
$sections = getRecords("SELECT DISTINCT s.section_id, s.section_name, s.grade_level
    FROM sections s
    INNER JOIN teacher_subjects ts ON ts.section_id = s.section_id AND ts.school_year_id = ?
    WHERE ts.teacher_id = ? AND s.is_active = 1" . $subjectFilter . "
    ORDER BY s.grade_level, s.section_name", array_merge([$syId, $teacherId], $subjectParams));
$subjects = getRecords("SELECT DISTINCT sub.subject_code, sub.subject_name
    FROM subjects sub
    INNER JOIN teacher_subjects ts ON ts.subject_code = sub.subject_code AND ts.school_year_id = ?
    WHERE ts.teacher_id = ? AND sub.is_active = 1" . $subjectFilter . "
    ORDER BY sub.subject_name", array_merge([$syId, $teacherId], $subjectParams));
$studentCount = getRecord("SELECT COUNT(DISTINCT e.student_id) AS total
    FROM enrollments e
    INNER JOIN teacher_subjects ts ON ts.section_id = e.section_id
    WHERE ts.teacher_id = ? AND e.enrollment_status = 'Approved'" . $subjectFilter, array_merge([$teacherId], $subjectParams));

$teacherStudents = [];
$assignedSubjectsBySection = [];
$teacherSubjectKeys = [];
$gradesByStudent = [];
$attendanceByStudent = [];
$studentContextLines = [];
$topicPerformanceLines = [];
$classTopicWeakSpots = [];
$studentSummaries = [];
$topicStudentsMap = [];
$onlineScoreKeys = [];
if ($needsStudentContext) {
// Rebuild this roster on every request. It is limited to students enrolled
// in sections and subjects assigned to the authenticated teacher.
$teacherStudents = getRecords("SELECT DISTINCT
        s.student_id, s.lrn, s.first_name, s.middle_name, s.last_name,
        s.grade_level, e.section_id,
        sec.section_name
    FROM students s
    INNER JOIN enrollments e ON s.student_id = e.student_id
        AND e.enrollment_status = 'Approved'
    INNER JOIN teacher_subjects ts ON e.section_id = ts.section_id
        AND ts.teacher_id = ?" . $subjectFilter . "
    INNER JOIN sections sec ON e.section_id = sec.section_id
    ORDER BY s.last_name, s.first_name", array_merge([$teacherId], $subjectParams));

$assignedSubjectRows = getRecords("SELECT ts.section_id, ts.subject_code, sub.subject_name
    FROM teacher_subjects ts
    INNER JOIN subjects sub ON ts.subject_code = sub.subject_code
    WHERE ts.teacher_id = ?" . $subjectFilter . "
    ORDER BY sub.subject_name", array_merge([$teacherId], $subjectParams));
foreach ($assignedSubjectRows ?: [] as $subjectRow) {
    $sectionKey = (int)$subjectRow['section_id'];
    $assignedSubjectsBySection[$sectionKey][] = $subjectRow['subject_code'] . ' - ' . $subjectRow['subject_name'];
    $teacherSubjectKeys[$sectionKey . '|' . $subjectRow['subject_code']] = true;
}

$gradeRows = getRecords("SELECT g.student_id,
        COUNT(*) AS grade_entries,
        SUM(CASE WHEN g.final_grade IS NULL OR g.final_grade <= 0 THEN 1 ELSE 0 END) AS missing_grade_entries,
        AVG(NULLIF(g.final_grade, 0)) AS average_grade,
        MIN(NULLIF(g.final_grade, 0)) AS lowest_grade
    FROM grades g
    INNER JOIN teacher_subjects ts ON ts.teacher_id = ?
        AND ts.subject_code = g.subject_code AND ts.section_id = g.section_id
        AND ts.school_year_id = g.school_year_id" . $subjectFilter . "
    WHERE g.school_year_id = ?
    GROUP BY g.student_id", array_merge([$teacherId], $subjectParams, [$syId]));
foreach ($gradeRows ?: [] as $row) {
    $gradesByStudent[(int)$row['student_id']] = $row;
}

$attendanceRows = getRecords("SELECT a.student_id,
        COUNT(*) AS attendance_total,
        SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN a.status = 'Late' THEN 1 ELSE 0 END) AS late_count,
        SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) AS absent_count,
        SUM(CASE WHEN a.status = 'Excused' THEN 1 ELSE 0 END) AS excused_count
    FROM attendance a
    INNER JOIN enrollments e ON e.student_id = a.student_id
        AND e.section_id = a.section_id AND e.school_year_id = ?
        AND e.enrollment_status = 'Approved'
    INNER JOIN teacher_subjects ts ON ts.teacher_id = ?
        AND ts.subject_code = a.subject_code AND ts.section_id = a.section_id
        AND ts.school_year_id = ?" . $subjectFilter . "
    GROUP BY a.student_id", array_merge([$syId, $teacherId, $syId], $subjectParams));
foreach ($attendanceRows ?: [] as $row) {
    $attendanceByStudent[(int)$row['student_id']] = $row;
}

// ---- Topic/Lesson/Competency performance (Manage Items' `topic` +
// assessment_scores) -- read-only, feeds the new optional student-performance
// analysis capability. See gh_getScoredAssessmentRows() / gh_studentTopicPerformance()
// / gh_classTopicWeakSpots() in grading_helpers.php. ----
$passMark = getGradingPassMark();
$scoredRows = gh_getScoredAssessmentRows($teacherId, $syId, $subjectMode ? $subjectMode['subject_code'] : null);
// Quizzes/Examinations sent with "Add to All Students" whose deadline passed
// with no score (marked Missing automatically).
$missingByStudent = aim_getMissingTasksByStudent($teacherId, $syId, $subjectMode ? $subjectMode['subject_code'] : null);
// Which scores came from an online, auto-graded submission (the rest were
// encoded by hand in Grade Management).
$onlineScoreKeys = aim_onlineScoreKeys($teacherId, $syId);

foreach ($teacherStudents ?: [] as $student) {
    $studentId = (int)$student['student_id'];
    $fullName = trim(implode(' ', array_filter([
        $student['first_name'] ?? '', $student['middle_name'] ?? '', $student['last_name'] ?? ''
    ])));
    $grades = $gradesByStudent[$studentId] ?? [];
    $attendance = $attendanceByStudent[$studentId] ?? [];
    $assignedSubjects = $assignedSubjectsBySection[(int)$student['section_id']] ?? [];
    $risk = calculateStudentRisk($studentId, $syId);
    // AI Analytics treats a failing OR incomplete subject as at risk. Use the
    // same granular records here so incomplete terms are not reported as
    // "Insufficient Data" when the teacher asks about at-risk students.
    $atRiskSubjects = array_values(array_filter(
        getAtRiskSubjectRecords($studentId, $syId),
        function ($record) use ($teacherSubjectKeys) {
            $key = (int)($record['section_id'] ?? 0) . '|' . ($record['subject_code'] ?? '');
            return isset($teacherSubjectKeys[$key]);
        }
    ));
    // Subject mode: never fall back to the student's overall risk, which
    // reflects other subjects.
    $riskLabel = $atRiskSubjects
        ? 'At Risk'
        : ($subjectMode ? 'No at-risk record in this subject' : ($risk['risk_level'] ?? 'Insufficient Data'));
    $atRiskSummary = [];
    foreach ($atRiskSubjects as $record) {
        $atRiskSummary[] = $record['subject_name'] . ' (' . $record['term'] . ': ' . $record['reason'] . ')';
    }
    $activity = getStudentActivityStatus($studentId);
    $attendanceTotal = (int)($attendance['attendance_total'] ?? 0);
    $studentContextLines[] = sprintf(
        '- %s | LRN: %s | %s - %s | Subjects: %s | Activity: %s | Attendance: %d Present out of %d recorded (%d Late, %d Absent, %d Excused) | Grade entries: %d | Missing/incomplete grade entries: %d | Average: %s | Lowest grade: %s | Risk: %s | At-risk subjects: %s',
        $fullName ?: 'Unnamed student',
        (string)($student['lrn'] ?? 'not available'),
        (string)($student['grade_level'] ?? ''),
        (string)($student['section_name'] ?? ''),
        $assignedSubjects ? implode(', ', $assignedSubjects) : 'none',
        (string)($activity['label'] ?? 'No Activity Yet'),
        (int)($attendance['present_count'] ?? 0),
        $attendanceTotal,
        (int)($attendance['late_count'] ?? 0),
        (int)($attendance['absent_count'] ?? 0),
        (int)($attendance['excused_count'] ?? 0),
        (int)($grades['grade_entries'] ?? 0),
        (int)($grades['missing_grade_entries'] ?? 0),
        ($grades['average_grade'] ?? null) !== null ? number_format((float)$grades['average_grade'], 2) : 'not available',
        ($grades['lowest_grade'] ?? null) !== null ? number_format((float)$grades['lowest_grade'], 2) : 'not available',
        $riskLabel,
        $atRiskSummary ? implode('; ', $atRiskSummary) : 'none'
    );

    $topicRows = gh_studentTopicPerformance($scoredRows, $studentId, $passMark);
    if ($topicRows) {
        $topicParts = [];
        foreach ($topicRows as $t) {
            $topicParts[] = ($t['has_topic'] ? $t['label'] : $t['label'] . ' (item name used -- no Topic tagged in Manage Items)')
                . ': average ' . $t['avg_percent'] . '% across ' . $t['count'] . ' scored assessment(s) [' . implode(', ', $t['basis']) . '] -- ' . $t['status'] . '. ' . $t['trend'];
        }
        $topicPerformanceLines[] = ($fullName ?: 'Unnamed student') . ':' . "\n    " . implode("\n    ", $topicParts);
    } else {
        $topicPerformanceLines[] = ($fullName ?: 'Unnamed student') . ': No scored assessment items yet in Manage Items for this subject/school year.';
    }
    $missingTasks = $missingByStudent[$studentId] ?? [];

    // Every scored assessment of this student with its source and stored topic.
    $studentAssessments = [];
    foreach ($scoredRows ?: [] as $row) {
        if ((int) $row['student_id'] !== $studentId) continue;
        $pct = round((float) $row['raw_score'] / (float) $row['highest_score'] * 100, 1);
        $rowTopic = trim((string) ($row['topic'] ?? ''));
        $studentAssessments[] = [
            'source' => isset($onlineScoreKeys[(int) $row['item_id'] . '|' . $studentId]) ? 'Online Assessment' : aim_manualSourceLabel($row),
            'assessment' => $row['item_name'],
            'topic' => $rowTopic !== '' ? $rowTopic : ($row['category'] === 'QA' ? 'Overall' : '—'),
            'score' => rtrim(rtrim(number_format((float) $row['raw_score'], 2), '0'), '.') . '/' . rtrim(rtrim(number_format((float) $row['highest_score'], 2), '0'), '.'),
            'percent' => $pct,
            'low' => $pct < $passMark,
        ];
    }
    // Priority Weak Topic: a stored Topic with repeated low scores (2 or more
    // assessments, average below the passing mark). topicRows is weakest first.
    $priorityTopic = null;
    foreach ($topicRows as $t) {
        if ($t['has_topic'] && $t['status'] === 'Needs Improvement' && $t['count'] >= 2) {
            $priorityTopic = ['topic' => $t['label'], 'avg_percent' => $t['avg_percent'], 'basis' => $t['basis']];
            break;
        }
    }
    if ($missingTasks) {
        $topicPerformanceLines[] = ($fullName ?: 'Unnamed student') . ' -- MISSING (deadline passed, not completed): ' . implode('; ', array_map(function ($m) {
            return $m['type'] . ' "' . $m['title'] . '"' . ($m['topic'] !== '' ? ' (topic: ' . $m['topic'] . ')' : '') . ', deadline ' . $m['deadline'];
        }, $missingTasks));
    }

    // ---- Student Monitoring dashboard: keep a lightweight per-student
    // summary and, for each class-wide (tagged) Needs Improvement topic, which
    // students scored low and on which assessments -- so a topic card can be
    // expanded without another query. Topic monitoring only: it never feeds
    // or changes the Academic At-Risk status ($riskLabel stays as computed).
    $needsImprovementTopics = [];
    $decliningTopics = [];
    foreach ($topicRows as $t) {
        if ($t['status'] === 'Needs Improvement') {
            $needsImprovementTopics[] = [
                'label' => $t['label'] . ($t['has_topic'] ? '' : ' (no Topic tag)'),
                'avg_percent' => $t['avg_percent'],
                'count' => $t['count'],
                'confirmed' => $t['count'] >= 2,
                'basis' => $t['basis'],
            ];
            if ($t['has_topic']) {
                $topicStudentsMap[$t['label']][] = [
                    'student_id' => $studentId,
                    'student' => $fullName ?: 'Unnamed student',
                    'avg_percent' => $t['avg_percent'],
                    'count' => $t['count'],
                    'basis' => $t['basis'],
                    'trend' => $t['trend'],
                ];
            }
        }
        if (stripos($t['trend'], 'Declining') === 0) {
            $decliningTopics[] = $t['label'] . ' -- ' . $t['trend'];
        }
    }
    $studentSummaries[$studentId] = [
        'student_id' => $studentId,
        'missing_tasks' => $missingTasks,
        'assessments' => $studentAssessments,
        'priority_topic' => $priorityTopic,
        'name' => $fullName ?: 'Unnamed student',
        'section' => trim(($student['grade_level'] ?? '') . ' - ' . ($student['section_name'] ?? ''), ' -'),
        'attendance_present' => (int)($attendance['present_count'] ?? 0),
        'attendance_total' => $attendanceTotal,
        'missing_grade_entries' => (int)($grades['missing_grade_entries'] ?? 0),
        'risk_label' => $riskLabel,
        'needs_improvement' => $needsImprovementTopics,
        'declining' => $decliningTopics,
        'topics' => $topicRows,
    ];
}
$classTopicWeakSpots = gh_classTopicWeakSpots($scoredRows, $passMark);
} // end: roster analytics only for student-analytics questions

// Shown whenever a topic's evidence is a single assessment.
$notEnoughDataNote = 'Not enough data to confirm a consistent weakness yet. Continue monitoring future assessments.';

// ---- Student Monitoring dashboard: data-only JSON response, no Gemini
// call. Built entirely from the roster/topic analytics gathered above, so it
// respects the same "authorized students only" and "read-only, advisory"
// rules as the rest of this endpoint. Topic labels (Strong / Satisfactory /
// Needs Improvement) describe assessment scores per topic only -- they are
// not subject grades and not the Academic At-Risk status. A "Analyze this
// topic" follow-up is a normal chat message with `focus_topic` instead. ----
if ($dashboardAction === 'student_monitoring') {
    $overallRawSum = 0.0;
    $overallMaxSum = 0.0;
    foreach ($scoredRows ?: [] as $row) {
        $overallRawSum += (float) $row['raw_score'];
        $overallMaxSum += (float) $row['highest_score'];
    }
    $averageScorePercent = $overallMaxSum > 0 ? round($overallRawSum / $overallMaxSum * 100, 1) : null;

    $strongTopics = [];
    $satisfactoryTopics = [];
    $weakTopics = [];
    foreach ($classTopicWeakSpots as $t) {
        $topicCard = [
            'topic' => $t['topic'],
            'class_avg_percent' => $t['class_avg_percent'],
            'students_with_data' => $t['students_with_data'],
            'item_count' => $t['item_count'],
            'items' => $t['items'],
            // Same already-computed facts for every topic card (display only),
            // so a Strong/Satisfactory topic can still list any student below
            // the passing mark in its View Details panel.
            'students_below_pass' => $t['students_below_pass'],
            'enough_data' => $t['item_count'] >= 2,
            'data_note' => $t['item_count'] >= 2 ? '' : $notEnoughDataNote,
            'struggling_students' => $topicStudentsMap[$t['topic']] ?? [],
        ];
        if ($t['class_avg_percent'] < $passMark) {
            $weakTopics[] = $topicCard + [
                'label' => 'Needs Improvement',
                'weakest_item' => $t['weakest_item'],
                'weakest_item_avg' => $t['weakest_item_avg'],
            ];
        } elseif ($t['class_avg_percent'] >= ($passMark + 10)) {
            $strongTopics[] = $topicCard + ['label' => 'Strong'];
        } else {
            $satisfactoryTopics[] = $topicCard + ['label' => 'Satisfactory'];
        }
    }

    // Topic monitoring: a student with a Needs Improvement topic or a
    // declining topic trend. Academic At-Risk status is NOT a reason here --
    // it stays separate (see `supporting`), and one low topic never makes a
    // student "at risk".
    $studentsToMonitor = [];
    foreach ($studentSummaries as $s) {
        if (!$s['needs_improvement'] && !$s['declining'] && !$s['missing_tasks']) {
            continue;
        }
        $confirmed = false;
        foreach ($s['needs_improvement'] as $ni) {
            if ($ni['confirmed']) { $confirmed = true; }
        }
        $studentsToMonitor[] = [
            'student_id' => $s['student_id'],
            'student' => $s['name'],
            'section' => $s['section'],
            'topics' => $s['needs_improvement'],
            'declining' => $s['declining'],
            'missing' => $s['missing_tasks'],
            'assessments' => $s['assessments'],
            'priority_topic' => $s['priority_topic'],
            'monitoring_level' => ($confirmed || $s['declining'])
                ? 'Consistent pattern (2 or more assessments)'
                : ($s['needs_improvement'] ? 'Early sign only (1 assessment) -- continue monitoring' : 'Missing quiz/examination -- follow up'),
        ];
    }

    $supporting = [];
    foreach ($studentSummaries as $s) {
        $supporting[] = [
            'student' => $s['name'],
            'attendance' => $s['attendance_present'] . '/' . $s['attendance_total'] . ' present',
            'missing_grade_entries' => $s['missing_grade_entries'],
            'risk_label' => $s['risk_label'],
        ];
    }

    echo json_encode([
        'success' => true,
        'mode' => 'dashboard',
        'dashboard' => 'student_monitoring',
        'title' => 'Student Performance Analytics',
        'generated_for' => $subjectMode ? $subjectMode['subject_name'] : 'All assigned subjects',
        'not_enough_data_note' => $notEnoughDataNote,
        'overview' => [
            'school_year' => $activeSy['sy_name'] ?? '',
            'total_students' => count($teacherStudents ?: []),
            'students_with_scores' => count(array_filter($studentSummaries, function ($s) { return !empty($s['topics']); })),
            'topics_tracked' => count($classTopicWeakSpots),
            'class_average_percent' => $averageScorePercent, // avg assessment score, not a subject grade
            'pass_mark' => $passMark,
        ],
        'strong_topics' => $strongTopics,
        'satisfactory_topics' => $satisfactoryTopics,
        'weak_topics' => $weakTopics,
        'students_to_monitor' => $studentsToMonitor,
        'supporting' => $supporting,
    ]);
    exit;
}

$sectionSummary = array_map(function ($row) {
    return ($row['grade_level'] ?? '') . ' - ' . ($row['section_name'] ?? '');
}, $sections ?: []);
$subjectSummary = array_map(function ($row) {
    return ($row['subject_code'] ?? '') . ' (' . ($row['subject_name'] ?? '') . ')';
}, $subjects ?: []);

$cleanHistory = [];
foreach (array_slice($history, -12) as $turn) {
    if (!is_array($turn)) continue;
    $role = ($turn['role'] ?? '') === 'ai' ? 'Teacher AI' : 'Teacher';
    $text = trim((string)($turn['text'] ?? ''));
    if ($text !== '') $cleanHistory[] = $role . ': ' . mb_substr($text, 0, 1800);
}

$system = "You are E-GRADEPRO Teacher AI, a general-purpose assistant for authenticated teachers that also offers an OPTIONAL student-performance analysis capability.\n"
    . "Help with teaching, lessons, subjects, classroom activities, lesson planning, assessments, quizzes, strategies, classroom management, grading, attendance, reports, and reasonable teacher questions.\n"
    . "You are separate from the existing Ask AI feature (the AI Analytics dashboard's catch-up/intervention tool). Do not require a student or at-risk subject.\n"
    . "You know only the E-GRADEPRO facts included in this prompt. Never invent a feature, record, policy, grade, attendance value, or report. If exact school data is not included, say what is unavailable.\n"
    . "Do not infer or silently choose a grade level, section, subject, topic, learning objective, number of items, or difficulty when the teacher has not provided it. The assigned sections and subjects below are context only, not defaults. If a requested teaching task is missing essential details, ask one concise clarification question and wait for the teacher's answer before generating the final resource. For example, if the teacher says only 'English subject', ask which grade level, section, topic, and objective are needed instead of choosing 'English 7' or any other grade.\n"
    . "You may use the teacher's authorized aggregate context below to answer class-information questions. Do not reveal private student information, credentials, or another teacher's data.\n"
    . "You may answer questions about a named student only when that student appears in the AUTHORIZED STUDENT ROSTER below. Use exact roster facts for attendance, including Present out of recorded attendance, missing/incomplete grade entries, activity status, average, lowest grade, and risk. If attendance has no records, say 0 Present out of 0 recorded and do not guess school days.\n"
    . "At-risk status follows AI Analytics: a teacher-assigned subject is At Risk when its term grade is below the passing mark OR its term is Incomplete. Use the listed at-risk subjects and reasons, including missing assessment names, instead of calling those students Insufficient Data.\n"
    . "When asked who is missing, at risk, inactive, or has low attendance, list only matching authorized students and include supporting numbers. Risk is an indicator, not a final decision. Do not expose LRN unless the teacher asks for it.\n"
    . "You are advisory only. Never change grades, assessment scores, attendance, enrollment, student status, or any academic record -- you can only read and describe them. Every recommendation you give (review, practice, remediation, continued monitoring, etc.) is a SUGGESTION for the teacher to consider; the teacher remains the final decision-maker and you never imply an action has been taken.\n"
    . "STUDENT-PERFORMANCE ANALYSIS (optional capability, used when the teacher asks about topics, competencies, weak/strong areas, performance trends, or which students to monitor): base this ONLY on the ASSESSMENT PERFORMANCE BY TOPIC and CLASS-WIDE TOPIC SUMMARY facts below -- they are computed from actual Quiz/Activity/Written-Oral-Work/Performance-Task/Examination scores recorded in Manage Items, tagged with the Topic/Lesson/Competency the teacher gave each item. Never compute your own percentages or invent a score, topic, or item name; use only the figures given.\n"
    . "  - For an individual student: name the weak/strong topic, give its basis (the exact assessment names and scores listed), and a short observation, in this style:\n"
    . "      Needs Improvement: <topic>\n      Basis: <item> - <score>, <item> - <score>\n      Observation: <one factual sentence, plus trend if given>\n"
    . "  - Topic labels are Strong, Satisfactory, or Needs Improvement. A topic percentage is an assessment score (raw score / highest possible score), NOT the student's subject grade -- never call it a grade, never compare it to a Term Grade, and never say a student passed or failed the subject because of it.\n"
    . "  - Keep topic monitoring separate from the Academic At-Risk status in the roster. Never call a student 'at risk' (or 'academically at risk') because of a low score in one topic; report only the At-Risk status already given in the roster for that.\n"
    . "  - Only describe weaknesses the given scores show. Question-level results exist only where an ITEM-LEVEL EVIDENCE block is given below (auto-graded quizzes answered in the student portal); everywhere else only total scores are recorded, so never name a specific question, item, sub-skill or subtopic that is not in such a block, and never invent a subtopic of the Topic.\n"
    . "  - Report both weak AND strong topics when asked for an overview -- this is for monitoring every student, not only students who are struggling. A student with high scores should still be mentioned so the teacher can see they are doing well and keep monitoring them.\n"
    . "  - For a class-wide question, use the CLASS-WIDE TOPIC SUMMARY numbers directly, in this style: \"<N> of <M> students had low performance in <topic>, especially in <weakest assessment>.\"\n"
    . "  - A topic or student with very few scored assessments is not reliable evidence. If the data given says 'No scored assessment items yet' or a topic/label has only one or two data points, do not draw a strong conclusion -- say exactly: \"Not enough data to confirm a consistent weakness yet. Continue monitoring future assessments.\" instead of guessing.\n"
    . "  - An item with no Topic tagged in Manage Items is shown here under its own item name instead -- you may still mention it individually, but it cannot be counted into a class-wide topic total (that needs a shared Topic tag), so do not claim a class-wide count for it; you may suggest the teacher tag it with a Topic in Manage Items for future analysis.\n"
    . "When the teacher asks for a lesson plan, quiz, activity, reviewer, report, or explanation, make the answer presentation-ready: start with a clear heading, then use short sections, bullets, numbered steps, examples, and an optional assessment or answer key. Avoid one dense paragraph.\n"
    . "Write content so it can be exported into PDF or PowerPoint: use clear section headings (plain lines beginning with # are acceptable), keep slide-sized ideas concise, and separate major topics into sections. Never claim that a file was generated unless the teacher explicitly uses the available export action.\n"
    . "E-GRADEPRO grading stores raw assessment scores under Written/Oral Works, Performance Tasks, and Examinations; category percentages are computed from assessment items, and official Initial Grade, Term Grade, and Remarks are produced by Finalize Term. Attendance is tracked separately and does not change grades.\n"
    . "Under DO 015, s. 2026: Written/Oral Works covers quizzes, written activities, recitation, oral activities and short/structured reports. Performance Tasks covers PETA, projects, group performance/output, demonstrations, portfolios and performance-based presentations/reporting (output scored by a rubric). Examinations are Summative Test 1, Summative Test 2 and the Term Examination. Attendance is never a grading component; it is used only for attendance records and risk monitoring.\n"
    . "The teacher portal includes assigned classes/sections, subjects, student records, attendance, reports, grading, and AI Analytics. Describe only workflows supported by the application.\n"
    . "Format useful answers with a short heading and clear bullets or numbered steps when appropriate. Be concise but complete. Reply in the language used by the teacher.\n\n"
    . "AUTHORIZED TEACHER CONTEXT:\n"
    . "School year: " . ($activeSy['sy_name'] ?? 'No active school year') . "\n"
    . "Assigned sections: " . ($sectionSummary ? implode(', ', $sectionSummary) : 'None found') . "\n"
    . "Assigned subjects: " . ($subjectSummary ? implode(', ', $subjectSummary) : 'None found') . "\n"
    . "Approved students across assigned sections: " . (int)($studentCount['total'] ?? 0) . "\n";

if ($subjectMode) {
    $modeSections = array_map(function ($row) {
        return $row['grade_level'] . ' - ' . $row['section_name'];
    }, $subjectMode['sections']);
    $system .= "\nSUBJECT-ONLY MODE (the teacher chose this subject on the Teacher AI screen; this overrides every earlier instruction about asking for or choosing a subject):\n"
        . "Selected subject: " . $subjectMode['subject_name'] . " (" . $subjectMode['subject_code'] . ")\n"
        . "Grade level(s): " . implode(', ', $subjectMode['grades']) . "\n"
        . "Sections that have this subject: " . implode(', ', $modeSections) . "\n"
        . "Everything you answer and generate must be about " . $subjectMode['subject_name'] . " only, pitched at the grade level above and aligned with the DepEd curriculum for it. The subject is already chosen: never ask which subject, never switch to another subject, and never mention the teacher's other subjects. Ask for the grade level only when this subject is taught to more than one grade level; still ask for the topic, learning objective and number of items when they are missing.\n"
        . "If the teacher asks for help or material about a different subject (or a topic that clearly belongs to another subject), do not produce it: use response_type \"text\", say briefly that this chat is limited to " . $subjectMode['subject_name'] . ", and tell them to tap \"Change subject\" to switch. General teaching questions that are still about teaching " . $subjectMode['subject_name'] . " (strategies, classroom management for this class, grading of this subject) are fine.\n"
        . "Student information is limited to the students of this subject's sections and to this subject's grades, attendance and at-risk records. Do not mention or reveal data from the teacher's other subjects.\n";
}

// Only include the full roster when the question needs student analytics
// ($needsStudentContext is decided at the top of this file).
if ($needsStudentContext) {
    $system .= "AUTHORIZED STUDENT ROSTER (assigned sections/subjects only):\n"
        . ($studentContextLines ? implode("\n", $studentContextLines) : '- No approved students found.') . "\n";

    $system .= "\nASSESSMENT PERFORMANCE BY TOPIC (from Manage Items + recorded scores; percentage = raw score / highest possible score; this school's passing mark = " . rtrim(rtrim(number_format($passMark, 2), '0'), '.') . "%):\n"
        . ($topicPerformanceLines ? implode("\n", $topicPerformanceLines) : '- No scored assessment items found for these students yet.') . "\n";

    $system .= "\nCLASS-WIDE TOPIC SUMMARY (topics tagged in Manage Items, across the roster above; a topic not listed here has no shared Topic tag yet or no scores yet):\n";
    if ($classTopicWeakSpots) {
        foreach ($classTopicWeakSpots as $t) {
            $system .= '- ' . $t['topic'] . ': ' . $t['students_below_pass'] . ' of ' . $t['students_with_data']
                . ' assessed students scored below the passing mark (class average ' . $t['class_avg_percent'] . '%); weakest assessment: '
                . $t['weakest_item'] . ' (avg ' . $t['weakest_item_avg'] . "%)\n";
        }
    } else {
        $system .= "- No Topic-tagged assessment items with scores yet.\n";
    }

    // ---- Focused topic ("Ask Teacher AI to analyze this topic" on the
    // Student Monitoring dashboard). Only accepted when it matches one of this
    // teacher's own tagged topics; the model then gets that topic's facts and
    // a fixed answer format, and must stay on that topic only. ----
    $focus = null;
    if ($focusTopic !== '' && $focusStudentId <= 0) { // a per-student recommendation uses its own block below
        foreach ($classTopicWeakSpots as $t) {
            if (mb_strtolower($t['topic']) === mb_strtolower($focusTopic)) { $focus = $t; break; }
        }
    }
    if ($focus) {
        $focusBasis = [];
        foreach ($focus['items'] as $it) {
            $focusBasis[] = $it['name'] . ' (' . $it['category'] . ', out of ' . $it['highest_score'] . ') - ' . $it['students_assessed'] . ' student(s) assessed, average ' . $it['avg_percent'] . '%';
        }
        $focusStudents = [];
        foreach ($topicStudentsMap[$focus['topic']] ?? [] as $st) {
            $focusStudents[] = $st['student'] . ': ' . implode(', ', $st['basis']) . ' (topic average ' . $st['avg_percent'] . '%, ' . $st['count'] . ' assessment(s))';
        }
        // ---- Specific weak skill/subtopic, class-wide: only from real
        // question-level results (auto-graded quizzes linked to this topic's
        // assessment items). Percentages are computed in PHP; the model only
        // names the skill each actual question tests. ----
        $rosterIds = array_map(function ($st) { return (int) $st['student_id']; }, $teacherStudents ?: []);
        $itemEvidence = gh_topicItemLevelEvidence($teacherId, $syId, $subjectMode ? $subjectMode['subject_code'] : null, $focus['topic'], $rosterIds);
        $skillGroups = [];
        $lowestItems = [];
        if ($itemEvidence['items']) {
            $skillGroups = gh_groupItemEvidenceBySkill($itemEvidence, teacherAiLabelQuestionSkills($focus['topic'], $itemEvidence));
            if (!$skillGroups) {
                // Grouping unavailable: fall back to the lowest individual items (still real data).
                foreach ($itemEvidence['items'] as $it) {
                    foreach ($it['questions'] as $q) {
                        if ($q['pct'] !== null) {
                            $lowestItems[] = ['pct' => $q['pct'], 'line' => $it['item_name'] . ($it['section'] !== '' ? ' (' . $it['section'] . ')' : '') . ', Item ' . $q['number'] . ': ' . $q['pct'] . '% correct (' . $q['correct'] . '/' . $q['total'] . ') -- "' . $q['text'] . '"'];
                        }
                    }
                }
                usort($lowestItems, function ($a, $b) { return $a['pct'] <=> $b['pct']; });
                $lowestItems = array_slice($lowestItems, 0, 3);
            }
        }
        $noItemDataNote = 'The class performed below the expected level in this topic, but there is not enough item-level data to identify the exact weak skill.';

        $system .= "\nFOCUSED TOPIC ANALYSIS (the teacher clicked 'Ask Teacher AI to analyze this topic' on the Student Monitoring dashboard -- this overrides the general formatting rules for this answer). This is a CLASS-WIDE analysis for all authorized students in the selected subject; do not single out individual students.\n"
            . "Topic: " . $focus['topic'] . "\n"
            . "Class average for this topic: " . $focus['class_avg_percent'] . "% (" . $focus['students_below_pass'] . " of " . $focus['students_with_data'] . " assessed students below the passing mark of " . rtrim(rtrim(number_format($passMark, 2), '0'), '.') . "%)\n"
            . "Number of assessments tagged with this topic: " . $focus['item_count'] . "\n"
            . "Assessments (basis):\n- " . implode("\n- ", $focusBasis) . "\n";

        if ($skillGroups) {
            $weakest = $skillGroups[0];
            $system .= "ITEM-LEVEL EVIDENCE (question-level results from " . $itemEvidence['total_responses'] . " auto-graded quiz submission(s); skills grouped from the actual question text; percent correct computed by E-GRADEPRO -- copy these numbers exactly):\n";
            foreach ($skillGroups as $g) {
                $system .= "- " . $g['skill'] . ": " . $g['pct'] . "% correct (" . $g['correct'] . " of " . $g['total'] . " answers) -- " . $g['evidence'] . "\n";
            }
            $system .= "Lowest skill: " . $weakest['skill'] . " -- " . $weakest['evidence'] . ", " . $weakest['pct'] . "% correct.\n"
                . "Questions in the lowest skill:\n- " . implode("\n- ", $weakest['questions']) . "\n"
                . ($weakest['pct'] >= $passMark
                    ? "Note: even the lowest skill is at or above the passing mark, so say that no specific skill is below the expected level and keep the recommendation light.\n"
                    : '')
                . ($itemEvidence['total_responses'] < 3
                    ? "Note: fewer than 3 students answered these questions, so add that this is an early sign and should be confirmed in the next assessment.\n"
                    : '');
        } elseif ($lowestItems) {
            $system .= "ITEM-LEVEL EVIDENCE (question-level results from " . $itemEvidence['total_responses'] . " auto-graded quiz submission(s); percent correct computed by E-GRADEPRO -- copy these numbers exactly). Lowest-scoring questions:\n- "
                . implode("\n- ", array_column($lowestItems, 'line')) . "\n"
                . "Name the Specific Weakness only from what these exact questions ask (a short skill name); do not add skills that are not shown by these questions.\n";
        } else {
            $system .= "ITEM-LEVEL EVIDENCE: none. These assessments only have total scores (no question-level results), so the exact weak skill cannot be identified.\n";
        }

        $system .= "Answer with response_type \"text\" using exactly these sections, in the teacher's language:\n"
            . "  Topic: <topic>\n  Class Average: <class average>%\n"
            . ($skillGroups || $lowestItems
                ? "  Specific Weakness: <the lowest skill>\n  Basis: <assessment>, Items <numbers> had the lowest class performance at <percent>% correct.\n"
                : "  Specific Weakness: write exactly: \"" . $noItemDataNote . "\"\n  Basis: <assessment name> - <number> students assessed, average <percent>% (one line per assessment)\n")
            . "  AI Analysis: at most 2 short factual sentences using only the facts above" . ($skillGroups || $lowestItems ? " (e.g. \"The class is having difficulty with <skill>. Items <numbers> had the lowest performance.\")" : '') . ".\n"
            . "  Recommended Action: 1-2 short sentences about " . ($skillGroups || $lowestItems ? "that specific skill only, e.g. review how to do it with simple examples, then a short focused activity on it" : "this topic only, e.g. review the topic's key concepts, then a short focused practice activity") . ".\n"
            . "  Next Monitoring: 1 short sentence -- check the next assessment under the same " . ($skillGroups || $lowestItems ? 'skill' : 'topic') . " to see if performance improves.\n"
            . "Keep the whole answer short (no introduction, no closing paragraph, no extra headings). Write assessment codes in plain words (e.g. 'q1' as 'Quiz 1', 'pt1' as 'Performance Task 1') and never show database-style text or IDs.\n"
            . "Rules: do not mention other topics or give unrelated recommendations; never invent a skill, subtopic, item number, question, or percentage that is not in the facts above; do not call any student at risk; do not treat topic percentages as subject grades; recommendations are suggestions only and nothing is changed in grades, scores, attendance or records.\n"
            . ($focus['item_count'] < 2
                ? "Only ONE assessment is available for this topic, so in AI Analysis you must include this exact sentence: \"" . $notEnoughDataNote . "\"\n"
                : '');

        // The students needing attention on this topic can be sent a review
        // ("Send review to students" under this answer). One subject only:
        // the review lands on that subject's page.
        $focusStudentsList = [];
        foreach ($topicStudentsMap[$focus['topic']] ?? [] as $st) {
            $focusStudentsList[(int) $st['student_id']] = ['student_id' => (int) $st['student_id'], 'student' => $st['student']];
        }
        $focusSubject = $subjectMode ? $subjectMode['subject_code'] : null;
        if (!$focusSubject) {
            $subjRows = getRecords(
                "SELECT DISTINCT subject_code FROM assessment_items WHERE teacher_id = ? AND school_year_id = ? AND is_active = 1 AND LOWER(TRIM(topic)) = LOWER(?)",
                [$teacherId, $syId, $focus['topic']]
            );
            $focusSubject = count($subjRows) === 1 ? $subjRows[0]['subject_code'] : null;
        }
        if ($focusStudentsList && $focusSubject) {
            $system .= "  Message to Students: LAST section, on its own lines after the heading \"Message to Students:\". ONE friendly, encouraging message written directly TO the students who scored low in this topic (use 'you'; no names, no scores, no class average, no teacher notes), in simple words for their grade level: which topic to review, a 2-4 sentence review of its key idea" . ($skillGroups || $lowestItems ? ' focused on the weakest skill above' : '') . ", then 3 numbered practice questions/tasks on this topic only, and tell them to open the lesson material under \"Assessments & Lessons\" on their subject page.\n";
            $reviewTarget = [
                'students'     => array_values($focusStudentsList),
                'subject_code' => $focusSubject,
                'topic'        => $focus['topic'],
            ];
        }
    }

    // ---- Focused STUDENT recommendation ("AI Recommendation" next to one
    // student's weak topic in Students to Monitor). The student must be on
    // this teacher's roster and the topic must be one of that student's own
    // scored topics; every score comes from assessment_scores via PHP. ----
    $focusStudent = null;
    if ($focusStudentId > 0 && $focusTopic !== '' && isset($studentSummaries[$focusStudentId])) {
        foreach ($studentSummaries[$focusStudentId]['topics'] as $t) {
            if (mb_strtolower($t['label']) === mb_strtolower($focusTopic)) { $focusStudent = $t; break; }
        }
    }
    if ($focusStudent) {
        $fs = $studentSummaries[$focusStudentId];
        // The exact assessments behind this topic for this student.
        $fsItems = [];
        foreach ($scoredRows ?: [] as $row) {
            if ((int) $row['student_id'] !== $focusStudentId) continue;
            $label = trim((string) ($row['topic'] ?? '')) !== '' ? trim($row['topic']) : trim((string) $row['item_name']);
            if (mb_strtolower($label) === mb_strtolower($focusStudent['label'])) {
                $fsItems[(int) $row['item_id']] = $row;
            }
        }
        $fsLines = [];
        $fsExamTopics = [];
        $fsMaterials = [];
        $fsHasExam = false;
        $fsClass = null;
        $fsLessonText = '';
        $fsLessonFile = null;
        $fmt = function ($n) { return rtrim(rtrim(number_format((float) $n, 2), '0'), '.'); };
        foreach ($fsItems as $itemId => $row) {
            $cov = aim_itemCoverage($itemId);
            $isExam = $row['category'] === 'QA';
            $fsHasExam = $fsHasExam || $isExam;
            $fsClass = $fsClass ?: aim_itemContext($itemId, $teacherId);
            $source = isset($onlineScoreKeys[$itemId . '|' . $focusStudentId]) ? 'Online Assessment' : aim_manualSourceLabel($row);
            $fsLines[] = $row['item_name'] . ' [' . $source . ']: '
                . $fmt($row['raw_score']) . '/' . $fmt($row['highest_score'])
                . ' (' . round((float) $row['raw_score'] / (float) $row['highest_score'] * 100) . '%)';
            if ($isExam) {
                $fsExamTopics = array_merge($fsExamTopics, $cov['topics']);
            } else {
                // The actual lesson the teacher attached to this assessment.
                $lesson = aim_itemLessonForAi($itemId, 5000);
                if ($lesson['text'] !== '' && $fsLessonText === '') {
                    $fsLessonText = $lesson['text'];
                }
                if ($lesson['file_part'] && !$fsLessonFile) {
                    $fsLessonFile = $lesson['file_part'];
                }
            }
            $fsMaterials = array_merge($fsMaterials, $cov['materials']);
        }
        $fsExamTopics = array_values(array_unique($fsExamTopics));
        // An exam with only an overall score (no Topic tagged by the teacher)
        // never gets a guessed weak topic.
        $fsOverallOnly = $fsHasExam && !$focusStudent['has_topic'];
        if ($fsLessonFile && !$attachmentFilePart) {
            $attachmentFilePart = $fsLessonFile; // a PDF lesson is read by Gemini directly
        }

        $system .= "\nFOCUSED STUDENT RECOMMENDATION (the teacher clicked 'AI Recommendation' for ONE student on the Student Monitoring dashboard -- this overrides the general formatting rules for this answer):\n"
            . "Student: " . $fs['name'] . ($fs['section'] !== '' ? ' (' . $fs['section'] . ')' : '') . "\n"
            . ($fsClass ? "Subject: " . $fsClass['item']['subject_name'] . " | Grade level: " . $fsClass['item']['grade_level'] . "\n" : '')
            . ($fsOverallOnly
                ? "Result: overall examination score only (no topic-level or item-level scores were recorded).\n"
                : "Weak topic (the Topic saved with the assessment in Manage Assessment Items): " . $focusStudent['label'] . " -- topic average " . $focusStudent['avg_percent'] . "% (" . $focusStudent['status'] . ", " . $focusStudent['count'] . " assessment(s))\n")
            . "Assessments (exact scores and where they came from, computed by E-GRADEPRO -- copy them exactly):\n- " . implode("\n- ", $fsLines ?: $focusStudent['basis']) . "\n"
            . ($fsMaterials ? "Lesson materials the teacher attached to these assessments:\n- " . implode("\n- ", array_unique($fsMaterials)) . "\n" : '')
            . ($fsLessonText !== '' ? "LESSON CONTENT (read from the teacher's attached file -- base the review on this):\n" . $fsLessonText . "\n" : '')
            . ($fsLessonFile ? "The attached PDF is the teacher's lesson for this assessment -- base the review on it.\n" : '');

        // Question-level results for THIS student (auto-graded portal quiz only).
        $fsWrong = [];
        if (!$fsOverallOnly) {
            $fsEvidence = gh_topicItemLevelEvidence($teacherId, $syId, $subjectMode ? $subjectMode['subject_code'] : null, $focusStudent['label'], [$focusStudentId]);
            foreach ($fsEvidence['items'] as $it) {
                foreach ($it['questions'] as $q) {
                    if ($q['total'] > 0 && $q['correct'] === 0) {
                        $fsWrong[] = $it['item_name'] . ', Item ' . $q['number'] . ': "' . $q['text'] . '"';
                    }
                }
            }
        }
        if ($fsWrong) {
            $system .= "Questions this student answered incorrectly (from the online, auto-graded assessment):\n- " . implode("\n- ", array_slice($fsWrong, 0, 10)) . "\n";
        }

        if ($fsOverallOnly) {
            $system .= "This is a MANUAL EXAMINATION with only an overall score. Topics its coverage files list: " . ($fsExamTopics ? implode('; ', $fsExamTopics) : 'none listed') . ".\n"
                . "Do NOT name or guess a specific weak topic: there is no topic-level or item-level score. Describe overall examination performance only, and suggest reviewing the listed coverage topics in general.\n"
                . "Answer with response_type \"text\" using exactly these sections, in the teacher's language:\n"
                . "  Student: <name>\n"
                . "  Assessment: <assessment> [<source>] - <score>\n"
                . "  Result: Overall examination performance (no topic-level scores recorded)\n"
                . "  Review Coverage: the coverage topics listed above, each with one short review suggestion" . ($fsExamTopics ? '' : ' (none are listed, so say the teacher can attach the coverage files to the examination in Manage Assessment Items)') . ".\n"
                . "  Next Monitoring: 1 short sentence -- record topic-level scores next time if the exact weak topic is needed.\n";
        } else {
            $system .= "Answer with response_type \"text\" using exactly these sections, in the teacher's language:\n"
                . "  Student: <name>\n"
                . "  Assessment: <assessment> [<source>] - <score> (one line per assessment above)\n"
                . "  Weak Topic: <the weak topic above>\n"
                . "  Short Review: 2-4 simple sentences that re-teach the key idea of that topic at the student's grade level, using the lesson content above" . ($fsWrong ? " and focused on what the incorrectly answered questions ask" : '') . " (e.g. for 'Pros and Cons': what advantages and disadvantages are, and how to compare both sides of an issue, with one short example).\n"
                . "  Practice: 3 short practice questions or tasks on that topic ONLY, suitable for the student's grade level.\n"
                . ($fsMaterials ? "  Material to Review: the teacher's attached lesson material(s) above for this topic.\n" : '')
                . "  Next Monitoring: 1 short sentence -- check the next assessment on the same topic.\n";
        }
        // Last section: the part the teacher can send to the student
        // ("Send review to <student>" under this answer).
        $system .= "  Message to Student: LAST section, on its own lines after the heading \"Message to Student:\". Written directly TO " . $fs['name'] . " (use 'you'), friendly and encouraging, in simple words for their grade level: say which lesson/topic to review" . ($fsOverallOnly ? '' : ' and why (their score on it)') . ", give the short review in 2-4 sentences, then the practice questions/tasks numbered 1-3" . ($fsMaterials ? ", and tell them to open the lesson material under \"Assessments & Lessons\" on their subject page" : '') . ". No teacher notes, no other students, no class averages.\n";
        $system .= "Keep it short (no introduction, no closing paragraph). This is about this ONE student and this ONE result: never give a generic tip such as 'study more', never mention unrelated topics, never invent a score, question, topic or skill that is not in the facts above, do not call the student at risk, and do not treat percentages as subject grades. Recommendations are suggestions for the teacher only; nothing is changed in grades, scores or records.\n";
        $reviewTarget = [
            'student_id'   => $focusStudentId,
            'student'      => $fs['name'],
            'subject_code' => $fsClass ? $fsClass['item']['subject_code'] : ($subjectMode['subject_code'] ?? ''),
            'topic'        => $fsOverallOnly ? ($fsItems ? reset($fsItems)['item_name'] : $focusStudent['label']) : $focusStudent['label'],
        ];
    }
}

$input = "CONVERSATION SO FAR:\n" . ($cleanHistory ? implode("\n", $cleanHistory) : '(new conversation)')
    . "\n\nTEACHER'S NEW QUESTION:\n" . $message
    . aiChatAttachmentPrompt($attachment);

// ---- Structured reply: text answer OR a resource that can become a file/Form
// and be sent to a class. The system text above is unchanged; this only adds the
// JSON contract and, when there is one, the draft the teacher is working on. ----
$typeList = implode(', ', array_keys(AI_RESOURCE_TYPES));
$structuredSystem = $system
    . "\nTEACHER AI OUTPUT OPTIONS: this interface can turn a resource you generate into a PDF, Word file, PowerPoint or an auto-graded Google Form and let the teacher send it to their own assigned classes. The interface does that itself when the teacher clicks or asks - never say that you created a file, made a Form, or sent anything.\n"
    . "\nRESPONSE FORMAT (mandatory): reply with ONLY a single JSON object (no markdown fences, no prose outside the JSON) with these fields:\n"
    . "  response_type: \"resource\" or \"text\".\n"
    . "    \"resource\" - the teacher asks you to create/generate/make (or 'gumawa ng') a lesson or lesson plan, quiz, activity, assessment, worksheet, reviewer, learning material, performance task, rubric, study guide, question set or similar teaching material AND the essential details rule above is satisfied (grade level, subject/topic and any other detail you were told not to guess are known from the teacher's messages). The material is for a whole class: never mention or single out an individual student.\n"
    . "    \"text\" - everything else: ordinary answers, explanations, strategies, E-GRADEPRO help, class or student questions, and your one concise clarification question when essential details are missing.\n"
    . "  reply_text: always present. For \"text\" it is your complete answer (use the heading / bullet / numbered-step formatting described above; it may be long). For \"resource\" it is a brief 1-2 sentence friendly intro, never the content itself.\n"
    . "  resource_type: required for \"resource\" - exactly one of: {$typeList}. Use mini_lesson for a lesson or lesson plan.\n"
    . "  output_format: always \"none\" (the interface creates files when the teacher chooses them).\n"
    . "  title: required for \"resource\" - a short, specific title.\n"
    . "  student_content: required for \"resource\" - the complete material as plain text: a line starting with \"#\" is a section heading; blank line = paragraph break; \"- \" starts a bullet; a line starting with a number and \". \" is a numbered item (question numbering / steps), with lettered choices (\"A. ...\") on their own lines under each question. Write it so it can be handed to students as it is.\n"
    . "  teacher_answer_key: only when the material has objectively correct answers (quizzes, identification, true/false, etc.): the answers plus brief explanations, same plain-text convention. Otherwise \"\". Never put answers inside student_content.\n"
    . "  quiz_questions: ONLY when resource_type is one of: " . implode(', ', AI_GFORM_ELIGIBLE_TYPES) . " - in ADDITION to student_content / teacher_answer_key, an array with one object per question in the same order: {question (text only, no number), choices (2-6 short strings; exactly two for true/false), correct_index (0-based), points (integer, normally 1)}. Every question must be single-best-answer multiple choice or true/false, otherwise omit quiz_questions entirely. If the teacher stated an item count, produce exactly that many.\n"
    . "If a CURRENT DRAFT is given below and the teacher asks to change, add to, shorten, simplify or extend it, respond with response_type \"resource\" and the FULL updated content, not a diff.";

$structuredInput = $input;
if ($draft && trim((string)$draft['student_content']) !== '') {
    $structuredInput .= "\n\nCURRENT DRAFT (the latest resource in this conversation; edit THIS if the teacher asks for a change):\n"
        . "Resource type: " . $draft['resource_type'] . "\nTitle: " . $draft['title'] . "\n"
        . "Student content:\n" . mb_substr((string)$draft['student_content'], 0, 7000) . "\n";
    if (trim((string)($draft['teacher_answer_key'] ?? '')) !== '') {
        $structuredInput .= "Teacher answer key:\n" . mb_substr((string)$draft['teacher_answer_key'], 0, 3000) . "\n";
    }
}
if ($regenerateOf) {
    $structuredInput .= "\nREGENERATE: the teacher wants a fresh, different version of the CURRENT DRAFT - same topic, grade level, purpose, length and number of items, with new wording, examples and questions. Respond with response_type \"resource\" and the full new content.\n";
}
if ($requestedOutput === 'gform') {
    $structuredInput .= "\n(Delivery: this will be built as an auto-graded Google Form. Use response_type \"resource\", a quiz-like resource_type, and fill quiz_questions with single-best-answer multiple-choice or true/false items only.)";
} elseif ($requestedOutput === 'pptx') {
    $structuredInput .= "\n(Delivery: this will be turned into a PowerPoint. Use response_type \"resource\" and write the complete lesson content with clear section headings.)";
} elseif ($requestedOutput === 'pdf' || $requestedOutput === 'docx') {
    $structuredInput .= "\n(Delivery: this will be delivered as a " . ($requestedOutput === 'pdf' ? 'PDF' : 'Word') . " file. Use response_type \"resource\" when this is teaching material.)";
}

if ($assessCtx) {
    $it = $assessCtx['item'];
    $km = $assessCtx['kind_meta'];
    $lesson = aim_itemLessonForAi((int) $it['item_id']);
    $hps = (float) $it['highest_score'];
    $hpsText = rtrim(rtrim(number_format($hps, 2), '0'), '.');
    $itemCount = ($hps >= 1 && $hps <= 50 && floor($hps) == $hps) ? (int) $hps : 10;
    $lessonTopic = trim((string) ($it['topic'] ?? ''));
    $lessonName = $lessonTopic !== '' ? $lessonTopic : ($lesson['names'] ? preg_replace('/\.(pdf|docx?|pptx?)$/i', '', $lesson['names'][0]) : $it['item_name']);
    $kindRules = [
        'quiz'             => "Generate resource_type \"quiz\" with EXACTLY {$itemCount} single-best-answer multiple-choice or true/false questions (1 point each) and fill quiz_questions, so it becomes an auto-graded online quiz / Google Form.",
        'activity'         => "Generate resource_type \"worksheet\": a classroom activity/worksheet with clear instructions and tasks worth {$hpsText} points in total, and a scoring guide/answer key in teacher_answer_key. Do NOT include quiz_questions (it is not a Google Form).",
        'written_work'     => "Generate resource_type \"written_work\": a written exercise (short answers, sentence writing, identification, etc.) worth {$hpsText} points in total, with an answer key/scoring guide in teacher_answer_key. Do NOT include quiz_questions (it is not a Google Form).",
        'oral'             => "Generate resource_type \"question_bank\": 8-12 oral recitation questions/prompts the teacher asks in class, with expected answer points and a simple scoring guide for {$hpsText} points in teacher_answer_key. They are open-ended: do NOT include quiz_questions (it is not a Google Form).",
        'performance_task' => "Generate resource_type \"performance_task\": task instructions (goal, role, audience, situation, product/performance) and a rubric totalling {$hpsText} points. Do NOT include quiz_questions (it is not a Google Form).",
    ];
    $structuredSystem .= "\nASSESSMENT MODE -- '" . $it['item_name'] . " AI' (opened from Manage Assessment Items; this overrides every rule about asking for missing details: all details are known, never ask a clarifying question):\n"
        . "Assessment: " . $it['item_name'] . " (" . $km['label'] . ") under " . $assessCtx['category_label'] . ", highest possible score " . $hpsText . "\n"
        . "Subject: " . $it['subject_name'] . " | " . $it['grade_level'] . " | Section " . $it['section_name'] . " | " . $it['term'] . "\n"
        . "Topic: " . ($lessonTopic !== '' ? $lessonTopic : '(take it from the attached lesson)') . "\n"
        . "Attached lesson material: " . ($lesson['names'] ? implode('; ', $lesson['names']) : 'none') . "\n"
        . "Base the whole resource ONLY on this lesson material and its topic, at the " . $it['grade_level'] . " " . $it['subject_name'] . " level. Never add questions or content about any other topic.\n"
        . $kindRules[$assessCtx['kind']] . "\n"
        . "Every resource reply MUST fill ALL of: resource_type, title, reply_text, student_content (the COMPLETE material exactly as students see it -- for a quiz, every numbered question with its lettered choices), and teacher_answer_key" . ($assessCtx['kind'] === 'quiz' ? ", plus quiz_questions (the same questions, same order)" : '') . ". Never leave student_content empty.\n"
        . "Title: \"" . $it['item_name'] . ($lessonTopic !== '' ? ' – ' . $lessonTopic : '') . "\".\n"
        . ($assessStart
            ? "This is the first message of this chat: respond with response_type \"resource\" now. reply_text must begin exactly: \"I prepared " . $it['item_name'] . " for " . $it['grade_level'] . " " . $it['subject_name'] . " based on your attached lesson \u{201C}" . $lessonName . ".\u{201D}\"\n"
            : "Later messages in this chat change this same resource (respond with the full updated resource).\n");
    if ($lesson['text'] !== '') {
        $structuredInput .= "\n\nATTACHED LESSON MATERIAL (text read from the teacher's file):\n" . $lesson['text'] . "\n";
    }
    if (!$attachmentFilePart && $lesson['file_part']) {
        $attachmentFilePart = $lesson['file_part']; // a PDF lesson is read by Gemini directly
    }
}

@set_time_limit(180);
$structuredTimeout = max(TEACHER_AI_TIMEOUT_SECONDS, defined('GEMINI_RESOURCE_TIMEOUT_SECONDS') ? GEMINI_RESOURCE_TIMEOUT_SECONDS : 0);
$structured = geminiGenerateResource($structuredSystem, $structuredInput, $structuredTimeout, 20000, $attachmentFilePart);

if (!empty($structured['success'])) {
    $parsed = $structured['parsed'];

    if (($parsed['response_type'] ?? '') === 'resource') {
        $sourcePrompt = ($regenerateOf && !empty($regenerateOf['source_prompt'])) ? $regenerateOf['source_prompt'] : $message;
        $newDraftId = teacherAiSaveDraft($teacherId, $parsed, $sourcePrompt, $subjectMode ? $subjectMode['subject_code'] : '');
        // A draft made for an assessment item (or edited from one) stays tied
        // to that item, so sending it records scores there -- no new item.
        $draftItemId = $assessCtx ? (int) $assessCtx['item']['item_id'] : ($draft ? aim_draftItemId($draft) : 0);
        if ($newDraftId && $draftItemId) {
            aim_linkDraftToItem($newDraftId, $draftItemId);
        }
        if ($newDraftId) {
            echo json_encode([
                'success' => true,
                'mode' => 'resource',
                'reply' => $parsed['reply_text'],
                'title' => $parsed['title'],
                'draft' => teacherAiDraftPayload(teacherAiGetResource($teacherId, $newDraftId)),
                'auto_output' => $requestedOutput,
                'regenerated' => (bool)$regenerateOf,
            ]);
            exit;
        }
        // Could not store the draft (e.g. the new tables could not be created):
        // still show the generated material in the chat, exactly like before.
        error_log('Teacher AI: resource draft could not be saved for teacher ' . $teacherId);
        $reply = '# ' . $parsed['title'] . "\n\n" . $parsed['student_content'];
        echo json_encode(['success' => true, 'mode' => 'text', 'reply' => $reply, 'title' => $parsed['title']]);
        exit;
    }

    $reply = $parsed['reply_text'];
} elseif (($structured['error_code'] ?? '') === 'empty_response' && $assessCtx) {
    // "<item> AI" never falls back to a plain answer without the lesson
    // context (it would ask for details the item already has).
    echo json_encode(['success' => false, 'error' => $assessCtx['item']['item_name'] . ' AI could not finish preparing it this time. Please click "Open ' . $assessCtx['item']['item_name'] . ' AI" again.']);
    exit;
} elseif (($structured['error_code'] ?? '') === 'empty_response') {
    // The model answered but not in the structured shape: fall back to the
    // original plain-text call so a teacher never loses an answer to this.
    error_log('Teacher AI: structured reply unusable, using the plain-text path.');
    if (preg_match('/\b(ppt|powerpoint|presentation|slides?)\b/i', $message)) {
        $input .= "\n\nPRESENTATION OUTPUT RULE: Create the complete lesson content now. Use a clear title followed by 6-10 concise slide sections. Start each slide section on its own line with '# Slide 1: ...', '# Slide 2: ...', and so on. Include accurate explanations, examples, learner activity, and a short assessment or recap. Do not return null, an empty answer, or a plan for what you might generate later.";
    }
    $result = callGeminiAPI($system, $input, null, TEACHER_AI_TIMEOUT_SECONDS, $attachmentFilePart, TEACHER_AI_MODEL);
    if (empty($result['success'])) {
        error_log('Teacher AI Gemini request failed: ' . ($result['error_code'] ?? 'unknown') . ' HTTP ' . ($result['http_code'] ?? '0'));
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'E-GRADEPRO Teacher AI is temporarily unavailable. Please try again in a moment.']);
        exit;
    }
    $reply = validateGeminiResponse($result['response'] ?? null, 24000);
} else {
    error_log('Teacher AI Gemini request failed: ' . ($structured['error_code'] ?? 'unknown'));
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'E-GRADEPRO Teacher AI is temporarily unavailable. Please try again in a moment.']);
    exit;
}

if (!is_string($reply) || trim($reply) === '') {
    error_log('Teacher AI returned an unusable response for prompt: ' . mb_substr($message, 0, 300));
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Teacher AI returned no usable content. Please try the request again.']);
    exit;
}

$topicTitle = preg_replace('/^\s*(generate|create|make|gumawa|please)\s+(a|an|the)?\s*/i', '', $message);
$topicTitle = trim($topicTitle, " .?!\t\r\n");
$topicTitle = preg_replace('/\s+/u', ' ', $topicTitle);
if ($topicTitle === '') $topicTitle = 'E-GRADEPRO Teacher AI Resource';
if (mb_strlen($topicTitle) > 90) $topicTitle = mb_substr($topicTitle, 0, 90);
$replyTitle = ucwords($topicTitle);
if (!preg_match('/^#{1,3}\s+/m', $reply)) {
    $reply = '# ' . $replyTitle . "\n\n" . $reply;
}
echo json_encode(['success' => true, 'mode' => 'text', 'reply' => $reply, 'title' => $replyTitle, 'review_target' => $reviewTarget]);
