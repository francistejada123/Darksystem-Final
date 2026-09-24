<?php
// modules/teachers/classroom_ai_chat.php
// AJAX endpoint for the "Generate with AI" panel inside the Classwork tab's
// "+ New Assignment" composer (modules/teachers/classroom.php). Mirrors
// modules/ai/chatbot.php's contract closely, but is CLASS-scoped (Teacher +
// Section + Subject + School Year + Term) instead of student-scoped, via
// getGeminiClassResourceReply() (includes/gemini_service.php).
//
// Deliberately does NOT generate a downloadable file here (unlike
// chatbot.php) -- a class-wide assignment isn't saved anywhere until the
// teacher clicks "Assign / Post to Class" on the composer, so generating a
// PDF/DOCX at chat time would risk creating an orphaned file for a draft
// the teacher never posts. The file (if requested) is generated once, at
// save time, by the create_assignment handler in classroom.php, from the
// final reviewed/edited content the teacher actually submits.
//
// Every request is independently re-validated server-side inside
// getGeminiClassResourceReply() (teacher role + real teacher_subjects
// assignment for this exact section+subject+school_year+term) — the
// browser's section_id/subject_code/term are never trusted on their own.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';
require_once __DIR__ . '/../../includes/gemini_service.php';
require_once __DIR__ . '/../../includes/classwork_helpers.php';            // CLASSWORK_TYPES (assessment type -> WW / PT / QA)
require_once __DIR__ . '/../../includes/assessment_material_helpers.php';  // aim_readFileForAi() -- read the attached lesson

header('Content-Type: application/json');

if (!hasRole('Teacher')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token. Please refresh the page and try again.']);
    exit;
}

$teacher_id = (int) ($_SESSION['user_id'] ?? 0);
$section_id = (int) ($_POST['section_id'] ?? 0);
$subject_code = trim((string) ($_POST['subject_code'] ?? ''));
$school_year_id = (int) ($_POST['school_year_id'] ?? 0);
$term = trim((string) ($_POST['term'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

if (!$teacher_id || !$section_id || $subject_code === '' || !$school_year_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing class information.']);
    exit;
}

$history = [];
$decoded_history = json_decode((string) ($_POST['history'] ?? '[]'), true);
if (is_array($decoded_history)) {
    foreach ($decoded_history as $turn) {
        if (!is_array($turn)) continue;
        $role = ($turn['role'] ?? '') === 'ai' ? 'ai' : 'teacher';
        $text = trim((string) ($turn['text'] ?? ''));
        if ($text === '') continue;
        $history[] = ['role' => $role, 'text' => $text];
    }
}

$current_draft = null;
$draft_raw = $_POST['current_draft'] ?? '';
if ($draft_raw !== '') {
    $decoded_draft = json_decode((string) $draft_raw, true);
    if (is_array($decoded_draft) && trim((string) ($decoded_draft['student_content'] ?? '')) !== '') {
        $current_draft = [
            'resource_type' => (string) ($decoded_draft['resource_type'] ?? ''),
            'title' => (string) ($decoded_draft['title'] ?? ''),
            'student_content' => (string) $decoded_draft['student_content'],
            'teacher_answer_key' => (string) ($decoded_draft['teacher_answer_key'] ?? ''),
        ];
    }
}

// ---- The assignment being set up (Classwork -> Create): its assessment
// type, title, instruction, points and attached lesson. The AI follows the
// selected type -- an Activity is generated as an activity, never a quiz. ----
$assignment = null;
$assessment_type = trim((string) ($_POST['assessment_type'] ?? ''));
if (array_key_exists($assessment_type, CLASSWORK_TYPES)) {
    list($gradingCode, $kind) = CLASSWORK_TYPES[$assessment_type];
    $points = is_numeric($_POST['max_points'] ?? null) ? (float) $_POST['max_points'] : 0;
    $pointsText = $points > 0 ? rtrim(rtrim(number_format($points, 2), '0'), '.') . ' points' : 'the total points';
    $rules = [
        'quiz'                 => "a QUIZ: numbered objective questions (multiple choice / true-false / identification) worth $pointsText in total, with an answer key.",
        'written_activity'     => "a WRITTEN ACTIVITY (NOT a quiz and NOT multiple choice): clear directions and tasks where students analyse, identify, explain or write about the lesson (e.g. read a sample, identify and explain its elements), worth $pointsText in total, with a scoring guide.",
        'worksheet'            => "a WORKSHEET (NOT a multiple-choice quiz): short practice exercises with directions and spaces for answers, worth $pointsText in total, with an answer key.",
        'recitation'           => "RECITATION prompts (NOT multiple choice): 8-12 oral questions the teacher asks in class, with expected answer points and a simple scoring guide for $pointsText.",
        'performance_activity' => "a PERFORMANCE TASK: goal, role, audience, situation, the product/performance to create, step-by-step instructions and a rubric totalling $pointsText. No multiple-choice questions.",
        'project'              => "a PROJECT: objective, materials, step-by-step instructions, timeline, what to submit, and a rubric totalling $pointsText. No multiple-choice questions.",
        'presentation'         => "a PRESENTATION task: topic, what to present, format and time limit, guide questions, and a rubric totalling $pointsText. No multiple-choice questions.",
        'peta'                 => "a PETA (performance task) using GRASPS (goal, role, audience, situation, product, standards) with a rubric totalling $pointsText. No multiple-choice questions.",
        'creative_output'      => "a CREATIVE OUTPUT task: what to create (e.g. poem, poster, video), requirements, steps, and a rubric totalling $pointsText. No multiple-choice questions.",
        'examination'          => "an EXAMINATION covering the lesson(s): test parts with directions (e.g. Test I multiple choice, Test II identification, Test III essay), worth $pointsText in total, with an answer key.",
        'learning_material'    => "a LEARNING MATERIAL / REVIEWER that explains the lesson simply with examples (not graded, no quiz).",
    ];
    $resourceTypes = [
        'quiz' => 'quiz', 'written_activity' => 'learning_activity', 'worksheet' => 'worksheet', 'recitation' => 'question_bank',
        'performance_activity' => 'performance_task', 'project' => 'performance_task', 'presentation' => 'performance_task',
        'peta' => 'performance_task', 'creative_output' => 'performance_task', 'examination' => 'short_assessment', 'learning_material' => 'reviewer',
    ];

    // Attached lesson (read only for this request, never stored here).
    $lessonNames = [];
    $lessonText = '';
    $filePart = null;
    if (isset($_FILES['lesson_files']) && is_array($_FILES['lesson_files']['name'] ?? null)) {
        foreach ($_FILES['lesson_files']['name'] as $i => $name) {
            if (($_FILES['lesson_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $file = ['name' => $name, 'type' => $_FILES['lesson_files']['type'][$i] ?? '', 'tmp_name' => $_FILES['lesson_files']['tmp_name'][$i], 'error' => UPLOAD_ERR_OK, 'size' => $_FILES['lesson_files']['size'][$i] ?? 0];
            $lessonNames[] = preg_replace('/\.[A-Za-z0-9]+$/', '', basename((string) $name));
            $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
            $ftype = tutorialMaterialTypeForExtension($ext);
            if ($ftype === null || !tutorialValidateUploadedMaterial($file, $ftype)['ok']) continue;
            $read = aim_readFileForAi($file['tmp_name'], $ftype, $ext);
            if (!$read['ok']) continue;
            if (isset($read['text'])) {
                $lessonText .= "\n--- " . basename((string) $name) . " ---\n" . $read['text'];
            } elseif (isset($read['file_part']) && $filePart === null) {
                $filePart = $read['file_part'];
            }
        }
    }

    $assignment = [
        'type'          => $assessment_type,
        'kind'          => $kind,
        'grading_label' => $gradingCode ? CLASSWORK_GRADING_LABELS[$gradingCode] : 'not graded',
        'title'         => mb_substr(trim((string) ($_POST['assignment_title'] ?? '')), 0, 200),
        'instructions'  => mb_substr(trim((string) ($_POST['assignment_instructions'] ?? '')), 0, 2000),
        'points'        => $points,
        'rule'          => $rules[$kind],
        'resource_type' => $resourceTypes[$kind],
        'lesson_names'  => $lessonNames,
        'lesson_text'   => $lessonText,
        'file_part'     => $filePart,
    ];

    // Opening message: fixed and specific to the selected type (no model call).
    if ($message === '' && !$history) {
        $lesson = $lessonNames ? 'the attached ' . $lessonNames[0] . ' lesson' : 'the lesson';
        $greetings = [
            'quiz'                 => "I can generate a quiz based on $lesson.",
            'written_activity'     => "I can create an activity based on $lesson and your instructions.",
            'worksheet'            => "I can create a worksheet based on $lesson and your instructions.",
            'recitation'           => "I can prepare recitation questions based on $lesson.",
            'performance_activity' => "I can create a performance task with instructions and rubric based on $lesson.",
            'project'              => "I can create a project with instructions and rubric based on $lesson.",
            'presentation'         => "I can create a presentation task with instructions and rubric based on $lesson.",
            'peta'                 => "I can create a PETA with instructions and rubric based on $lesson.",
            'creative_output'      => "I can create a creative output task with instructions and rubric based on $lesson.",
            'examination'          => "I can generate an examination based on $lesson and the selected coverage.",
            'learning_material'    => "I can prepare a learning material that explains $lesson.",
        ];
        $reply = $greetings[$kind]
            . ' (' . $assessment_type . ' · ' . $assignment['grading_label'] . ($points > 0 ? ' · ' . $pointsText : '') . ')'
            . ($lessonNames ? '' : ' Attach the lesson file above so I can base it on your actual lesson.')
            . ' Type "Generate" or tell me what to include.';
        echo json_encode(['success' => true, 'response_type' => 'text', 'reply' => $reply]);
        exit;
    }
}

$result = getGeminiClassResourceReply($teacher_id, $section_id, $subject_code, $school_year_id, $term, $history, $message, $current_draft, $assignment);

if (!$result['success']) {
    echo json_encode(['success' => false, 'error' => $result['error']]);
    exit;
}

if ($result['response_type'] !== 'resource') {
    echo json_encode(['success' => true, 'response_type' => 'text', 'reply' => $result['reply_text']]);
    exit;
}

echo json_encode([
    'success' => true,
    'response_type' => 'resource',
    'reply' => $result['reply_text'],
    'resource_type' => $result['resource_type'],
    'resource_type_label' => aiResourceTypeLabel($result['resource_type']),
    'suggested_category' => classroomCategoryForResourceType($result['resource_type']),
    'title' => $result['title'],
    'student_content' => $result['student_content'],
    'teacher_answer_key' => $result['teacher_answer_key'],
    'output_format' => $result['output_format'], // 'pdf' | 'docx' | null
    'quiz_questions' => $result['quiz_questions'] ?? null,
]);
