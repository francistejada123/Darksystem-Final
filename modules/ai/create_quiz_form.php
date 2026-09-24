<?php
// modules/ai/create_quiz_form.php
// AJAX endpoint: the teacher has a "Generate a quiz" draft showing in the
// Ask AI chat (chatbot.php's response_type "resource", resource_type one
// of AI_GFORM_ELIGIBLE_TYPES, with a structured quiz_questions array) and
// clicks "Create Google Form Quiz". This is the Google-Forms counterpart
// of generate_resource.php's "Generate PDF"/"Generate Word" buttons — same
// re-validation shape, different output.
//
// quiz_questions arrives from the browser as the draft it already has —
// NEVER trusted directly: it is re-sanitized here with the exact same
// aiSanitizeQuizQuestions() used when Gemini's own output was first
// validated, so a tampered POST can't smuggle in something malformed or
// oversized.
//
// The created Form always belongs to exactly one student (this
// conversation's student_id) — its link is only ever handed out via
// Send to Student afterward, the teacher's own explicit action, same as
// every other generated resource.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/gemini_service.php'; // validateTeacherSubjectForStudent(), aiSanitizeQuizQuestions(), AI_GFORM_ELIGIBLE_TYPES
require_once __DIR__ . '/../../includes/ai_document_generator.php'; // AI_RESOURCE_TYPES, aiResourceTypeLabel()
require_once __DIR__ . '/../../includes/google_forms_service.php';
require_once __DIR__ . '/../../includes/grading_helpers.php'; // isValidTerm()

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

if (!googleFormsIsConfigured()) {
    echo json_encode(['success' => false, 'error' => 'Google Forms is not set up yet. Ask an Administrator to finish the Google Forms setup (Settings → Google Forms Setup) first.']);
    exit;
}

$teacher_id = (int) ($_SESSION['user_id'] ?? 0);
$student_id = (int) ($_POST['student_id'] ?? 0);
$subject_code = trim((string) ($_POST['subject_code'] ?? ''));
$resource_type = trim((string) ($_POST['resource_type'] ?? 'quiz'));
$title = trim((string) ($_POST['title'] ?? ''));
$student_content = trim((string) ($_POST['student_content'] ?? ''));
$teacher_answer_key = trim((string) ($_POST['teacher_answer_key'] ?? ''));
$source_prompt = trim((string) ($_POST['source_prompt'] ?? ''));
$quiz_questions_raw = $_POST['quiz_questions'] ?? '[]';
$one_attempt = in_array((string) ($_POST['one_attempt'] ?? ''), ['1', 'true', 'on'], true) ? 1 : 0;
// Which real, EXISTING Assessment Item this quiz's score counts toward —
// required so the score can sync into Grade Management automatically
// instead of the teacher re-typing it. This quiz is always a make-up /
// recovery assessment for something the student already missed (e.g. the
// class's "Quiz 3") — there is no "create a brand-new item" fallback; the
// item to recover has to already exist in Manage Assessment Items first.
// NEVER trust the client-disabled button alone: everything here is
// re-validated from scratch below, the same as every other field on this
// endpoint.
$assessment_item_id = (int) ($_POST['assessment_item_id'] ?? 0);
$assessment_term = trim((string) ($_POST['assessment_term'] ?? ''));

if (!$teacher_id || !$student_id || $subject_code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing student or subject.']);
    exit;
}
if (!in_array($resource_type, AI_GFORM_ELIGIBLE_TYPES, true)) {
    echo json_encode(['success' => false, 'error' => 'This kind of resource cannot be turned into a Google Form quiz.']);
    exit;
}
if (!array_key_exists($resource_type, AI_RESOURCE_TYPES)) {
    $resource_type = 'quiz';
}
if ($title === '') {
    $title = aiResourceTypeLabel($resource_type);
}
if (mb_strlen($title) > 150) {
    $title = mb_substr($title, 0, 150);
}
if (mb_strlen($student_content) > 12000) {
    $student_content = mb_substr($student_content, 0, 12000);
}
if (mb_strlen($teacher_answer_key) > 8000) {
    $teacher_answer_key = mb_substr($teacher_answer_key, 0, 8000);
}

$decoded_questions = json_decode((string) $quiz_questions_raw, true);
$quiz_questions = aiSanitizeQuizQuestions(is_array($decoded_questions) ? $decoded_questions : []);
if (empty($quiz_questions)) {
    echo json_encode(['success' => false, 'error' => 'No gradable multiple-choice questions were found in this quiz. Try regenerating it, or use Generate PDF/Word instead.']);
    exit;
}

// ---- Re-validate everything from scratch — never trust that an earlier
// chat turn, or the posted fields, already checked this. ----
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
if (!$active_sy) {
    echo json_encode(['success' => false, 'error' => 'No active school year is set.']);
    exit;
}

$check = validateTeacherSubjectForStudent($teacher_id, $student_id, $subject_code, $active_sy['sy_id']);
if (!$check['success']) {
    echo json_encode(['success' => false, 'error' => $check['error']]);
    exit;
}

$student = getRecord(
    "SELECT s.*, sec.section_name FROM students s LEFT JOIN sections sec ON s.section_id = sec.section_id WHERE s.student_id = ? AND s.is_active = 1",
    [$student_id]
);
if (!$student) {
    echo json_encode(['success' => false, 'error' => 'Student not found.']);
    exit;
}

$student_display_name = trim($student['first_name'] . ' ' . $student['last_name']);
$description = $title . ' — for ' . $student_display_name . ' (' . $check['subject']['subject_name'] . '). Auto-generated by E-GradePro Ask AI; your teacher will see your score automatically once you submit.';
if ($one_attempt) {
    $description .= ' You get ONE attempt only — the form closes right after you submit.';
}

// ---- Assessment-item link: REQUIRED. This is what lets the score sync
// automatically into Grade Management instead of the teacher re-typing it.
// Every piece is re-derived and re-checked here — never trusted from the
// POST alone. ----
if (!isValidTerm($assessment_term)) {
    echo json_encode(['success' => false, 'error' => 'Please select a valid grading term.']);
    exit;
}

// Confirm this teacher is actually assigned to teach this subject for the
// STUDENT'S OWN section this school year — the same ownership check
// modules/grading/items.php uses before it will let an item be touched.
// This also gives us the section_id an assessment_items row requires.
$teacher_subject = getRecord(
    "SELECT ts.* FROM teacher_subjects ts WHERE ts.teacher_id = ? AND ts.subject_code = ? AND ts.section_id = ? AND ts.school_year_id = ?",
    [$teacher_id, $subject_code, $student['section_id'], $active_sy['sy_id']]
);
if (!$teacher_subject || empty($student['section_id'])) {
    echo json_encode(['success' => false, 'error' => 'You are not assigned to teach this subject for this student\'s current section.']);
    exit;
}
$section_id = (int) $teacher_subject['section_id'];

// ---- EXISTING ITEM ONLY: make-up / recovery. A Google Form quiz for an
// At-Risk student is never a brand-new assessment — it is a make-up for
// something already missed (e.g. the class's "Quiz 3"), so its score has to
// land on that SAME existing assessment_items row. If nothing fits yet, the
// teacher has to add the item first in Manage Assessment Items — this
// endpoint refuses rather than silently creating one. ----
if ($assessment_item_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Please select which existing assessment this quiz recovers. If none fits yet, add it first in Manage Assessment Items.']);
    exit;
}
$existing_item = getRecord(
    "SELECT * FROM assessment_items WHERE item_id = ? AND teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND is_active = 1",
    [$assessment_item_id, $teacher_id, $subject_code, $section_id, $active_sy['sy_id']]
);
if (!$existing_item) {
    echo json_encode(['success' => false, 'error' => 'That assessment item could not be found, or does not belong to you. Please refresh and try again.']);
    exit;
}
if ($existing_item['term'] !== $assessment_term) {
    echo json_encode(['success' => false, 'error' => 'That assessment item belongs to a different term. Please refresh and try again.']);
    exit;
}
// The item's own category is authoritative — never the posted one.
$assessment_category = $existing_item['category'];
// Performance Tasks are encoded and computed manually by the teacher; an AI
// quiz may only recover a Written Works or Examinations item.
if (!gh_isAiAssistedCategory($assessment_category)) {
    echo json_encode(['success' => false, 'error' => 'Performance Tasks are encoded manually by the teacher, so an AI quiz cannot be linked to one. Pick a Written Works or Examinations item instead.']);
    exit;
}
$link_item_id = (int) $existing_item['item_id'];

$form_result = googleFormsCreateQuizForm($title, $description, $quiz_questions);
if (!$form_result['success']) {
    echo json_encode(['success' => false, 'error' => $form_result['error']]);
    exit;
}

$resource_id = insertRecord(
    "INSERT INTO ai_generated_resources
        (teacher_id, student_id, subject_code, school_year_id, resource_type, title, source_prompt,
         student_content, teacher_answer_key, audience, output_format,
         google_form_id, google_form_edit_url, google_form_view_url, quiz_questions_json, quiz_max_score, quiz_one_attempt,
         link_category, link_term, link_item_id, grade_sync_status,
         status, generated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'student', 'gform', ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 'Generated', NOW())",
    [
        $teacher_id,
        $student_id,
        $subject_code,
        $active_sy['sy_id'],
        $resource_type,
        $title,
        $source_prompt !== '' ? mb_substr($source_prompt, 0, 500) : null,
        $student_content !== '' ? $student_content : $title,
        $teacher_answer_key !== '' ? $teacher_answer_key : null,
        $form_result['form_id'],
        $form_result['edit_url'],
        $form_result['view_url'],
        json_encode($quiz_questions),
        $form_result['max_score'],
        $one_attempt,
        $assessment_category,
        $assessment_term,
        $link_item_id,
    ]
);

if (!$resource_id) {
    // The Google Form already exists at this point — only the link between
    // it and this chat conversation failed to save. The existing Assessment
    // Item the teacher picked to recover is never touched, however this
    // request ends.
    echo json_encode(['success' => false, 'error' => 'The Google Form was created, but could not be saved here. Please try again.']);
    exit;
}

logActivity(
    $teacher_id,
    'AI Resource',
    'Created Google Form quiz',
    "Resource #$resource_id ({$title}) for student #$student_id, subject $subject_code — " . count($quiz_questions) . ' questions, ' . $form_result['max_score'] . ' points. '
        . 'Linked to EXISTING Assessment Item #' . $link_item_id . " ($assessment_category, $assessment_term)."
);

echo json_encode([
    'success' => true,
    'resource_id' => (int) $resource_id,
    'title' => $title,
    'resource_type' => $resource_type,
    'resource_type_label' => aiResourceTypeLabel($resource_type),
    'output_format' => 'gform',
    'edit_url' => $form_result['edit_url'],
    'view_url' => $form_result['view_url'],
    'max_score' => (int) $form_result['max_score'],
    'question_count' => count($quiz_questions),
    'one_attempt' => (bool) $one_attempt,
    'link_category' => $assessment_category,
    'link_term' => $assessment_term,
    'link_item_id' => (int) $link_item_id,
]);
