<?php
// modules/ai/save_class_content.php
// AJAX endpoint: the teacher reviews/edits AI-generated CLASS-LEVEL
// content (the "Ask AI" panel inside modules/grading/items.php —
// Section 10 of the improvement spec) and clicks "Save Draft" or
// "Publish"/"Save as Final".
//
// This is the ONLY place class-wide AI content is written to the
// database or made visible to a student — modules/ai/chatbot.php's
// generate_class_content action only ever returns a draft, never saves
// anything. Ownership is re-validated here from scratch, the same way
// save_reviewer.php / save_quiz.php do it — never trusting that an
// earlier generate call, or the posted content_id, already checked it.
//
// "Published" means different things depending on content_type:
//   - quiz, practice_quiz, reviewer, remedial_activity: becomes visible
//     to every student in this section for this subject, in the Student
//     Portal (modules/students/portal/tutorial.php).
//   - performance_task_suggestion, written_work_suggestion,
//     question_bank: these are for the TEACHER'S OWN planning only and
//     are never shown to students — "Published" here just means the
//     teacher marked it as their final version (vs. Draft).

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/gemini_service.php'; // validateTeacherSectionSubject(), parseAndValidateQuizJson(), AI_CLASS_CONTENT_*

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
$term = trim((string) ($_POST['term'] ?? ''));
$content_type = trim((string) ($_POST['content_type'] ?? ''));
$category = trim((string) ($_POST['category'] ?? ''));
$topic = trim((string) ($_POST['topic'] ?? ''));
$title = trim((string) ($_POST['title'] ?? ''));
$status = ($_POST['status'] ?? '') === 'Published' ? 'Published' : 'Draft';
$content_id = (int) ($_POST['content_id'] ?? 0);

if (!$teacher_id || !$section_id || $subject_code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing section or subject.']);
    exit;
}
if (!array_key_exists($content_type, AI_CLASS_CONTENT_TYPES)) {
    echo json_encode(['success' => false, 'error' => 'Unknown content type.']);
    exit;
}
$category = in_array($category, ['WW', 'PT', 'QA'], true) ? $category : null;
if ($topic !== '' && mb_strlen($topic) > 255) $topic = mb_substr($topic, 0, 255);
if ($title === '') $title = AI_CLASS_CONTENT_TYPES[$content_type];
if (mb_strlen($title) > 150) $title = mb_substr($title, 0, 150);

// ---- Re-validate everything from scratch — never trust that an earlier
// generate call, or the content_id itself, already checked this. ----
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
if (!$active_sy) {
    echo json_encode(['success' => false, 'error' => 'No active school year is set.']);
    exit;
}

$check = validateTeacherSectionSubject($teacher_id, $section_id, $subject_code, $active_sy['sy_id']);
if (!$check['success']) {
    echo json_encode(['success' => false, 'error' => $check['error']]);
    exit;
}

$is_quiz_type = in_array($content_type, AI_CLASS_CONTENT_QUIZ_TYPES, true);
$content_text = null;
$question_type = null;
$questions_json = null;
$question_count = 0;

if ($is_quiz_type) {
    $question_type = (string) ($_POST['question_type'] ?? 'multiple_choice');
    if (!in_array($question_type, ['multiple_choice', 'true_false', 'mixed'], true)) {
        $question_type = 'multiple_choice';
    }

    // ---- Strictly re-validate the (possibly teacher-edited) question
    // JSON — never trust the browser's shape, even though it started
    // from an AI-generated + already-validated draft. Reuses the exact
    // same validator getGeminiClassContent() runs on the model's own
    // output, same discipline as save_quiz.php. ----
    $questions_raw = (string) ($_POST['questions_json'] ?? '');
    $decoded = json_decode($questions_raw, true);
    $requested_count = is_array($decoded['questions'] ?? null) ? count($decoded['questions']) : 0;
    $questions = $requested_count > 0 ? parseAndValidateQuizJson($questions_raw, $requested_count) : null;

    if ($questions === null || empty($questions)) {
        echo json_encode(['success' => false, 'error' => 'The quiz questions are incomplete or invalid. Please check each question has text, choices, and a correct answer selected.']);
        exit;
    }
    $questions_json = json_encode(['questions' => $questions]);
    $question_count = count($questions);
} else {
    $content_text = trim((string) ($_POST['content'] ?? ''));
    if ($content_text === '') {
        echo json_encode(['success' => false, 'error' => 'Content cannot be empty.']);
        exit;
    }
    if (mb_strlen($content_text) > 8000) {
        echo json_encode(['success' => false, 'error' => 'Content is too long.']);
        exit;
    }
}

$published_at_sql = $status === 'Published' ? 'NOW()' : 'NULL';

if ($content_id > 0) {
    // Only ever update content that is actually this teacher's own draft
    // for this exact section+subject+content_type — never trust the
    // posted ID alone.
    $existing = getRecord(
        "SELECT content_id FROM ai_class_content WHERE content_id = ? AND teacher_id = ? AND section_id = ? AND subject_code = ? AND content_type = ?",
        [$content_id, $teacher_id, $section_id, $subject_code, $content_type]
    );
    if (!$existing) {
        echo json_encode(['success' => false, 'error' => 'Content not found.']);
        exit;
    }
    $ok = updateRecord(
        "UPDATE ai_class_content SET term = ?, category = ?, topic = ?, title = ?, content = ?, question_type = ?, questions_json = ?, status = ?, published_at = " . $published_at_sql . " WHERE content_id = ?",
        [$term !== '' ? $term : null, $category, $topic !== '' ? $topic : null, $title, $content_text, $question_type, $questions_json, $status, $content_id]
    );
    if ($ok === false) {
        echo json_encode(['success' => false, 'error' => 'Could not save. Please try again.']);
        exit;
    }
} else {
    $content_id = insertRecord(
        "INSERT INTO ai_class_content (teacher_id, section_id, subject_code, school_year_id, term, content_type, category, topic, title, content, question_type, questions_json, status, published_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . $published_at_sql . ")",
        [$teacher_id, $section_id, $subject_code, $active_sy['sy_id'], $term !== '' ? $term : null, $content_type, $category, $topic !== '' ? $topic : null, $title, $content_text, $question_type, $questions_json, $status]
    );
    if (!$content_id) {
        echo json_encode(['success' => false, 'error' => 'Could not save. Please try again.']);
        exit;
    }
}

logActivity(
    $teacher_id,
    'AI Class Content',
    $status === 'Published' ? 'Published class content' : 'Saved class content draft',
    "Content #$content_id (" . AI_CLASS_CONTENT_TYPES[$content_type] . ") for section #$section_id, subject $subject_code"
);

echo json_encode([
    'success' => true,
    'content_id' => (int) $content_id,
    'status' => $status,
    'question_count' => $question_count,
]);
