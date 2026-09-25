<?php
// includes/classwork_helpers.php
//
// Classwork assignments (Classroom -> Classwork -> Create) as real,
// gradable work:
//   - The assessment TYPE the teacher picks decides the grading category --
//     Written Works, Performance Task or Examination -- never whether the
//     work is done online or offline (CLASSWORK_TYPES below).
//   - A graded assignment is saved as an item in Manage Assessment Items
//     (aiAssessmentEnsureItem() -- an item with the same name is reused, so
//     nothing is duplicated). The teacher's score goes to assessment_scores
//     for THAT item, so it only counts toward its own category.
//   - Students see it under "Your Work" on their subject page, download the
//     teacher's files, attach their own and Turn In. Late work is still
//     accepted, and labelled "Missing – Submitted Late".
// Self-migrating and additive (new columns + one new table), like the rest
// of the codebase.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/classroom_helpers.php';

/**
 * Assessment type => [grading category (WW / PT / QA, null = not graded),
 * kind used by the AI, group label].
 */
const CLASSWORK_TYPES = [
    'Quiz'                 => ['WW', 'quiz', 'Written Works'],
    'Written Activity'     => ['WW', 'written_activity', 'Written Works'],
    'Worksheet'            => ['WW', 'worksheet', 'Written Works'],
    'Recitation'           => ['WW', 'recitation', 'Written Works'],
    'Performance Activity' => ['PT', 'performance_activity', 'Performance Task'],
    'Project'              => ['PT', 'project', 'Performance Task'],
    'Presentation'         => ['PT', 'presentation', 'Performance Task'],
    'PETA'                 => ['PT', 'peta', 'Performance Task'],
    'Creative Output'      => ['PT', 'creative_output', 'Performance Task'],
    'Examination'          => ['QA', 'examination', 'Examination'],
    'Learning Material'    => [null, 'learning_material', 'Not graded'],
];

/** Categories saved before CLASSWORK_TYPES existed -- still shown and graded correctly. */
const CLASSWORK_LEGACY_CATEGORIES = [
    'Assignment' => 'WW', 'Written Work' => 'WW', 'Activity' => 'WW', 'Performance Task' => 'PT',
    'Term Examination' => 'QA', 'Reviewer' => null,
];

const CLASSWORK_GRADING_LABELS = ['WW' => 'Written Works', 'PT' => 'Performance Task', 'QA' => 'Examination'];

define('CLASSWORK_SUBMISSION_DIR', __DIR__ . '/../static/uploads/classwork_submissions/');

/** WW / PT / QA for a category (new or legacy), or null when it is not graded. */
function classworkGradingCategory($category)
{
    if (isset(CLASSWORK_TYPES[$category])) {
        return CLASSWORK_TYPES[$category][0];
    }
    return CLASSWORK_LEGACY_CATEGORIES[$category] ?? null;
}

function classworkEnsureSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('classwork_v2')) return;
    $cols = function ($table) {
        return array_column(getRecords(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$table]
        ), 'COLUMN_NAME');
    };
    $a = $cols('classwork_assignments');
    $adds = [
        'grading_category'   => "ADD COLUMN grading_category VARCHAR(2) DEFAULT NULL COMMENT 'WW / PT / QA from the assessment type -- never from online/offline'",
        'assessment_item_id' => "ADD COLUMN assessment_item_id INT(11) DEFAULT NULL COMMENT 'Manage Assessment Items row the score goes to'",
        'max_points'         => "ADD COLUMN max_points DECIMAL(6,2) DEFAULT NULL",
        'assigned_at'        => "ADD COLUMN assigned_at DATETIME DEFAULT NULL",
        'answer_key'         => "ADD COLUMN answer_key TEXT DEFAULT NULL COMMENT 'Teacher-only answer key / rubric the AI checks against'",
        'lock_after_due'     => "ADD COLUMN lock_after_due TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = no turn-in after the due date (teacher choice)'",
    ];
    foreach ($adds as $col => $ddl) {
        if ($a && !in_array($col, $a, true)) {
            executeQuery("ALTER TABLE classwork_assignments $ddl");
        }
    }
    $s = $cols('classwork_assignment_students');
    $adds = [
        'assigned_at'      => "ADD COLUMN assigned_at DATETIME DEFAULT NULL",
        'submitted_at'     => "ADD COLUMN submitted_at DATETIME DEFAULT NULL",
        'is_late'          => "ADD COLUMN is_late TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = turned in after the due date (still accepted)'",
        'score'            => "ADD COLUMN score DECIMAL(6,2) DEFAULT NULL",
        'graded_at'        => "ADD COLUMN graded_at DATETIME DEFAULT NULL",
        'teacher_feedback' => "ADD COLUMN teacher_feedback TEXT DEFAULT NULL",
        'score_source'     => "ADD COLUMN score_source VARCHAR(10) DEFAULT NULL COMMENT 'AI = checked by AI first; Teacher = checked/changed by the teacher'",
        'ai_score'         => "ADD COLUMN ai_score DECIMAL(6,2) DEFAULT NULL",
        'ai_feedback'      => "ADD COLUMN ai_feedback TEXT DEFAULT NULL",
        'ai_checked_at'    => "ADD COLUMN ai_checked_at DATETIME DEFAULT NULL",
    ];
    foreach ($adds as $col => $ddl) {
        if ($s && !in_array($col, $s, true)) {
            executeQuery("ALTER TABLE classwork_assignment_students $ddl");
        }
    }
    executeQuery(
        "CREATE TABLE IF NOT EXISTS classwork_submission_files (
            file_id INT(11) NOT NULL AUTO_INCREMENT,
            assignment_id INT(11) NOT NULL,
            student_id INT(11) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL COMMENT 'random name under static/uploads/classwork_submissions/',
            mime_type VARCHAR(100) DEFAULT NULL,
            file_size INT(11) DEFAULT NULL,
            uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (file_id),
            KEY idx_csf (assignment_id, student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    schemaMarkReady('classwork_v2');
}

/** Save one student upload under classwork_submissions (same validation as every classroom upload). */
function classworkSaveSubmissionFile(array $file)
{
    if (!is_dir(CLASSWORK_SUBMISSION_DIR)) {
        @mkdir(CLASSWORK_SUBMISSION_DIR, 0755, true);
    }
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'error' => 'No file was chosen.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'The file could not be uploaded. Please try again.'];
    }
    if ($file['size'] > CLASSROOM_ATTACHMENT_MAX_BYTES) {
        return ['success' => false, 'error' => 'File is too large (max 20 MB).'];
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, CLASSROOM_ATTACHMENT_ALLOWED_EXT, true)) {
        return ['success' => false, 'error' => 'That file type is not allowed (PDF, Word, PowerPoint, Excel, image, video or text).'];
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], CLASSWORK_SUBMISSION_DIR . $stored)) {
        return ['success' => false, 'error' => 'Could not save the file. Please try again.'];
    }
    return [
        'success' => true,
        'stored_filename' => $stored,
        'original_filename' => mb_substr(basename((string) $file['name']), 0, 255),
        'mime_type' => $file['type'] ?: null,
        'file_size' => (int) $file['size'],
    ];
}

/**
 * What a student's row means right now.
 * @return array{label:string, key:string}  key: assigned | missing | turned_in | late | graded
 */
function classworkStatus(array $row, array $assignment)
{
    $overdue = !empty($assignment['due_date']) && date('Y-m-d H:i:s') > $assignment['due_date'];
    if (($row['status'] ?? '') === 'Reviewed' && $row['score'] !== null) {
        return ['label' => 'Graded', 'key' => 'graded'];
    }
    if (($row['status'] ?? '') === 'Submitted' || ($row['status'] ?? '') === 'Reviewed') {
        return !empty($row['is_late'])
            ? ['label' => 'Missing – Submitted Late', 'key' => 'late']
            : ['label' => 'Turned In', 'key' => 'turned_in'];
    }
    return $overdue ? ['label' => 'Missing', 'key' => 'missing'] : ['label' => 'Assigned', 'key' => 'assigned'];
}

/** Uploaded files for (assignment, student) pairs: "aid|sid" => rows. */
function classworkFilesFor(array $assignmentIds, $studentId = null)
{
    classworkEnsureSchema();
    $assignmentIds = array_values(array_filter(array_map('intval', $assignmentIds)));
    if (!$assignmentIds) return [];
    $ph = implode(',', array_fill(0, count($assignmentIds), '?'));
    $params = $assignmentIds;
    $sql = "SELECT * FROM classwork_submission_files WHERE assignment_id IN ($ph)";
    if ($studentId !== null) {
        $sql .= " AND student_id = ?";
        $params[] = (int) $studentId;
    }
    $out = [];
    foreach (getRecords($sql . " ORDER BY uploaded_at, file_id", $params) as $f) {
        $out[(int) $f['assignment_id'] . '|' . (int) $f['student_id']][] = $f;
    }
    return $out;
}

/**
 * Teacher: record a score for one student. Saves it on the assignment row
 * and in assessment_scores for the linked Manage Assessment Items row, so it
 * counts only toward that item's own category (WW / PT / QA).
 * @return array{success:bool, error?:string}
 */
function classworkRecordScore($teacherId, $assignmentId, $studentId, $score, $feedback = '', $source = 'Teacher')
{
    classworkEnsureSchema();
    $a = getRecord("SELECT * FROM classwork_assignments WHERE assignment_id = ? AND teacher_id = ?", [(int) $assignmentId, (int) $teacherId]);
    if (!$a) {
        return ['success' => false, 'error' => 'That assignment could not be found.'];
    }
    $row = getRecord("SELECT * FROM classwork_assignment_students WHERE assignment_id = ? AND student_id = ?", [(int) $assignmentId, (int) $studentId]);
    if (!$row) {
        return ['success' => false, 'error' => 'That student is not assigned to this work.'];
    }
    if (!is_numeric($score) || (float) $score < 0) {
        return ['success' => false, 'error' => 'Enter a valid score.'];
    }
    $score = round((float) $score, 2);
    $item = !empty($a['assessment_item_id'])
        ? getRecord("SELECT * FROM assessment_items WHERE item_id = ? AND teacher_id = ?", [(int) $a['assessment_item_id'], (int) $teacherId])
        : null;
    $max = $item ? (float) $item['highest_score'] : (float) ($a['max_points'] ?? 0);
    if ($max > 0 && $score > $max) {
        return ['success' => false, 'error' => 'The score cannot be higher than ' . rtrim(rtrim(number_format($max, 2), '0'), '.') . ' points.'];
    }
    if ($item && function_exists('gh_isSectionTermCompleted') && gh_isSectionTermCompleted((int) $item['section_id'], (int) $item['school_year_id'], $item['term'])) {
        return ['success' => false, 'error' => $item['term'] . ' is already Completed for this section, so its scores are locked.'];
    }
    $feedback = trim((string) $feedback);
    $source = $source === 'AI' ? 'AI' : 'Teacher';
    if ($source === 'AI') {
        executeQuery(
            "UPDATE classwork_assignment_students SET score = ?, status = 'Reviewed', graded_at = NOW(), score_source = 'AI',
                    ai_score = ?, ai_feedback = ?, ai_checked_at = NOW(), teacher_feedback = ?
             WHERE assignment_id = ? AND student_id = ?",
            [$score, $score, $feedback !== '' ? mb_substr($feedback, 0, 2000) : null, $feedback !== '' ? mb_substr($feedback, 0, 2000) : null, (int) $assignmentId, (int) $studentId]
        );
    } else {
        // The teacher's check always wins; the AI's suggestion stays on record.
        executeQuery(
            "UPDATE classwork_assignment_students SET score = ?, status = 'Reviewed', graded_at = NOW(), score_source = 'Teacher',
                    teacher_feedback = COALESCE(?, teacher_feedback)
             WHERE assignment_id = ? AND student_id = ?",
            [$score, $feedback !== '' ? mb_substr($feedback, 0, 2000) : null, (int) $assignmentId, (int) $studentId]
        );
    }
    if ($item) {
        // Same check-then-write as Encode Grades (modules/grading/index.php).
        $existing = getRecord("SELECT score_id FROM assessment_scores WHERE item_id = ? AND student_id = ?", [(int) $item['item_id'], (int) $studentId]);
        if ($existing) {
            executeQuery("UPDATE assessment_scores SET raw_score = ?, updated_at = NOW() WHERE score_id = ?", [$score, (int) $existing['score_id']]);
        } else {
            executeQuery("INSERT INTO assessment_scores (item_id, student_id, raw_score) VALUES (?, ?, ?)", [(int) $item['item_id'], (int) $studentId, $score]);
        }
    }
    return ['success' => true];
}

/**
 * Tell each student about new classwork the same way every other teacher
 * message reaches them: a student_support_messages row, which lights the
 * "New" badge on the class card and the portal bell.
 */
function classworkNotifyStudents(array $assignment, array $studentIds, $teacherId)
{
    $due = !empty($assignment['due_date']) ? date('M j, Y g:i A', strtotime($assignment['due_date'])) : 'no due date';
    $message = 'New ' . $assignment['category'] . ': ' . $assignment['title'] . "\n"
        . 'Due: ' . $due . "\n"
        . 'Open Classwork on this page to see the instructions and turn in your work.';
    foreach ($studentIds as $sid) {
        executeQuery(
            "INSERT INTO student_support_messages
                (teacher_id, student_id, subject_code, school_year_id, support_type, final_message, source, status, is_seen, sent_at)
             VALUES (?, ?, ?, ?, 'Classwork', ?, 'classwork', 'Sent', 0, NOW())",
            [(int) $teacherId, (int) $sid, $assignment['subject_code'], (int) $assignment['school_year_id'], $message]
        );
    }
    // Email too (once per student per classwork).
    if (!empty($assignment['assignment_id'])) {
        require_once __DIR__ . '/student_email_notify.php';
        senNotifyStudents($studentIds, [
            'key' => 'classwork:' . (int) $assignment['assignment_id'],
            'subject_code' => $assignment['subject_code'], 'teacher_id' => (int) $teacherId,
            'title' => $assignment['title'], 'kind' => $assignment['category'] ?: 'Classwork',
            'deadline' => $assignment['due_date'] ?? null,
        ]);
    }
}

/**
 * AI checks one student's turned-in work first and records the score
 * (score_source = 'AI'); the teacher can open the work and change it at any
 * time (score_source becomes 'Teacher'). Reuses the existing grader
 * aiGradeResourceSubmission() -- the same one Student Submissions uses.
 * @return array{success:bool, score?:float, error?:string}
 */
function classworkAiCheck($assignmentId, $studentId)
{
    require_once __DIR__ . '/gemini_service.php';            // aiGradeResourceSubmission()
    require_once __DIR__ . '/assessment_material_helpers.php'; // aim_pptxText()
    require_once __DIR__ . '/ai_chat_attachment.php';          // aiChatDocxText()
    classworkEnsureSchema();

    $a = getRecord("SELECT * FROM classwork_assignments WHERE assignment_id = ?", [(int) $assignmentId]);
    $row = $a ? getRecord("SELECT * FROM classwork_assignment_students WHERE assignment_id = ? AND student_id = ?", [(int) $assignmentId, (int) $studentId]) : null;
    if (!$a || !$row) {
        return ['success' => false, 'error' => 'That work could not be found.'];
    }
    if (!($a['grading_category'] ?? classworkGradingCategory($a['category']))) {
        return ['success' => false, 'error' => 'This is not graded.'];
    }
    $item = !empty($a['assessment_item_id']) ? getRecord("SELECT highest_score FROM assessment_items WHERE item_id = ?", [(int) $a['assessment_item_id']]) : null;
    $max = $item ? (float) $item['highest_score'] : (float) ($a['max_points'] ?? 0);
    if ($max <= 0) {
        return ['success' => false, 'error' => 'This work has no points to grade against.'];
    }

    // The student's files: Word / PowerPoint text is read here; a PDF or a
    // photo is sent to the AI to read directly.
    $text = '';
    $file = null;
    foreach (classworkFilesFor([(int) $assignmentId], (int) $studentId)[(int) $assignmentId . '|' . (int) $studentId] ?? [] as $f) {
        $path = CLASSWORK_SUBMISSION_DIR . basename($f['stored_filename']);
        if (!is_file($path)) continue;
        $ext = strtolower(pathinfo($f['stored_filename'], PATHINFO_EXTENSION));
        if ($ext === 'docx') {
            $t = aiChatDocxText(file_get_contents($path));
            if ($t) $text .= "\n--- " . $f['original_filename'] . " ---\n" . $t;
        } elseif ($ext === 'pptx') {
            $t = aim_pptxText(file_get_contents($path));
            if ($t) $text .= "\n--- " . $f['original_filename'] . " ---\n" . $t;
        } elseif ($ext === 'txt') {
            $text .= "\n--- " . $f['original_filename'] . " ---\n" . mb_substr((string) file_get_contents($path), 0, 8000);
        } elseif ($file === null && in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true) && filesize($path) <= 15 * 1024 * 1024) {
            $file = ['bytes' => file_get_contents($path), 'mime_type' => $ext === 'pdf' ? 'application/pdf' : ($ext === 'png' ? 'image/png' : 'image/jpeg')];
        }
    }
    if (trim($text) === '' && $file === null) {
        return ['success' => false, 'error' => 'No readable file was turned in (PDF, Word, PowerPoint or a photo), so the teacher checks it by hand.'];
    }

    @set_time_limit(150);
    $activity = ['student_content' => $a['category'] . ': ' . $a['title'] . "\n\n" . $a['instructions'], 'teacher_answer_key' => (string) ($a['answer_key'] ?? '')];
    $r = aiGradeResourceSubmission($activity, $max, trim($text) !== '' ? $text : null, trim($text) !== '' ? null : $file);
    if (empty($r['success'])) {
        executeQuery("UPDATE classwork_assignment_students SET ai_feedback = ?, ai_checked_at = NULL WHERE assignment_id = ? AND student_id = ?",
            ['AI could not check this: ' . ($r['error'] ?? 'unknown error'), (int) $assignmentId, (int) $studentId]);
        return ['success' => false, 'error' => $r['error'] ?? 'The AI could not check this work.'];
    }
    $score = max(0, min($max, round((float) $r['score'], 2)));
    $saved = classworkRecordScore((int) $a['teacher_id'], (int) $assignmentId, (int) $studentId, $score, (string) $r['feedback'], 'AI');
    if (!$saved['success']) {
        return $saved;
    }
    return ['success' => true, 'score' => $score];
}
