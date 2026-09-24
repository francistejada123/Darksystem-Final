<?php
// modules/ai/generate_resource.php
// AJAX endpoint: the teacher already has a generated/edited resource draft
// showing in the Ask AI chat (from chatbot.php's response_type "resource",
// possibly hand-edited afterward) and clicks "Generate PDF" or
// "Generate Word" on it — WITHOUT asking Gemini again. This is the fast
// path Example 2/3's "Generate PDF"/"Generate Word" buttons use when the
// file wasn't already requested in the original chat message.
//
// This is also where a teacher can generate a TEACHER COPY (content +
// answer key) for their own records — a clearly separate action
// (audience=teacher) from the student-facing file, and one this endpoint
// refuses to ever hand to modules/ai/send_to_student.php (that endpoint
// only ever accepts a 'student'-audience resource_id — see its own
// re-check).
//
// Ownership is re-validated here from scratch, the same way
// chatbot.php / send_to_student.php do it — never trusting that an
// earlier chat turn already checked this pair.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/gemini_service.php'; // validateTeacherSubjectForStudent()
require_once __DIR__ . '/../../includes/ai_document_generator.php';

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
$student_id = (int) ($_POST['student_id'] ?? 0);
$subject_code = trim((string) ($_POST['subject_code'] ?? ''));
$resource_type = trim((string) ($_POST['resource_type'] ?? 'other'));
$title = trim((string) ($_POST['title'] ?? ''));
$student_content = trim((string) ($_POST['student_content'] ?? ''));
$teacher_answer_key = trim((string) ($_POST['teacher_answer_key'] ?? ''));
$output_format = (string) ($_POST['output_format'] ?? '');
$audience = ($_POST['audience'] ?? '') === 'teacher' ? 'teacher' : 'student';
$source_prompt = trim((string) ($_POST['source_prompt'] ?? ''));
// Optional: which REAL, EXISTING Assessment Item this PDF/Word activity
// recovers -- same link_category/link_term/link_item_id columns the
// Google Form quiz path (create_quiz_form.php) already uses, so a
// confirmed score on the student's attached-file submission can sync
// automatically into Grade Management instead of the teacher re-typing it
// (see includes/ai_resource_submission_helpers.php's aiResourceMarkReviewed()).
// UNLIKE the quiz path, this is OPTIONAL here -- not every PDF/Word
// activity is a graded recovery for a specific missing item (some are just
// extra practice) -- so a blank/zero value simply leaves this resource
// unlinked; modules/ai/link_resource_item.php lets a teacher link it later.
$assessment_item_id = (int) ($_POST['assessment_item_id'] ?? 0);
$assessment_term = trim((string) ($_POST['assessment_term'] ?? ''));

if (!$teacher_id || !$student_id || $subject_code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing student or subject.']);
    exit;
}
if (!in_array($output_format, ['pdf', 'docx'], true)) {
    echo json_encode(['success' => false, 'error' => 'Please choose PDF or Word.']);
    exit;
}
if ($student_content === '') {
    echo json_encode(['success' => false, 'error' => 'There is no content to generate yet.']);
    exit;
}
if (mb_strlen($student_content) > 12000) {
    $student_content = mb_substr($student_content, 0, 12000);
}
if (mb_strlen($teacher_answer_key) > 8000) {
    $teacher_answer_key = mb_substr($teacher_answer_key, 0, 8000);
}
if (!array_key_exists($resource_type, AI_RESOURCE_TYPES)) {
    $resource_type = 'other';
}
if ($title === '') {
    $title = aiResourceTypeLabel($resource_type);
}
if (mb_strlen($title) > 150) {
    $title = mb_substr($title, 0, 150);
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
$teacher_row = getRecord("SELECT first_name, last_name FROM users WHERE user_id = ?", [$teacher_id]);

if ($audience === 'teacher' && $teacher_answer_key === '') {
    echo json_encode(['success' => false, 'error' => 'There is no answer key to include for a teacher copy.']);
    exit;
}

// ---- Optional gradebook link (student-facing resources only — a
// teacher's own answer-key copy is never itself a gradable assessment).
// EXISTING ITEM ONLY, same rule as create_quiz_form.php: never creates a
// brand-new assessment_items row here. ----
$link_category = null;
$link_term = null;
$link_item_id = null;
if ($audience === 'student' && $assessment_item_id > 0) {
    require_once __DIR__ . '/../../includes/grading_helpers.php'; // isValidTerm()
    if (!isValidTerm($assessment_term)) {
        echo json_encode(['success' => false, 'error' => 'Please select a valid grading term.']);
        exit;
    }
    $teacher_subject = getRecord(
        "SELECT ts.* FROM teacher_subjects ts WHERE ts.teacher_id = ? AND ts.subject_code = ? AND ts.section_id = ? AND ts.school_year_id = ?",
        [$teacher_id, $subject_code, $student['section_id'], $active_sy['sy_id']]
    );
    if (!$teacher_subject || empty($student['section_id'])) {
        echo json_encode(['success' => false, 'error' => 'You are not assigned to teach this subject for this student\'s current section.']);
        exit;
    }
    $section_id = (int) $teacher_subject['section_id'];
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
    // Performance Tasks are encoded and computed manually by the teacher; AI
    // activities may only be linked to Written Works or Examinations items.
    if (!gh_isAiAssistedCategory($existing_item['category'])) {
        echo json_encode(['success' => false, 'error' => 'Performance Tasks are encoded manually by the teacher, so an AI activity cannot be linked to one. Pick a Written Works or Examinations item instead.']);
        exit;
    }
    // The item's own category is authoritative — never anything the browser posted.
    $link_category = $existing_item['category'];
    $link_term = $assessment_term;
    $link_item_id = (int) $existing_item['item_id'];
}

$resource = [
    'resource_type' => $resource_type,
    'title' => $title,
    'student_content' => $student_content,
    'teacher_answer_key' => $teacher_answer_key !== '' ? $teacher_answer_key : null,
];
$doc_context = [
    'student_name' => trim($student['first_name'] . ' ' . $student['last_name']),
    'grade_level' => $student['grade_level'],
    'subject_name' => $check['subject']['subject_name'],
    'teacher_name' => $teacher_row ? trim($teacher_row['first_name'] . ' ' . $teacher_row['last_name']) : '',
    'date' => date('F j, Y'),
];

$file_result = aiGenerateResourceFile($resource, $doc_context, $output_format, $audience);
if (!$file_result['success']) {
    echo json_encode(['success' => false, 'error' => $file_result['error']]);
    exit;
}

$resource_id = insertRecord(
    "INSERT INTO ai_generated_resources
        (teacher_id, student_id, subject_code, school_year_id, resource_type, title, source_prompt,
         student_content, teacher_answer_key, audience, output_format, stored_filename, original_filename,
         mime_type, file_size, link_category, link_term, link_item_id, status, generated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Generated', NOW())",
    [
        $teacher_id,
        $student_id,
        $subject_code,
        $active_sy['sy_id'],
        $resource_type,
        $title,
        $source_prompt !== '' ? mb_substr($source_prompt, 0, 500) : null,
        $student_content,
        $teacher_answer_key !== '' ? $teacher_answer_key : null,
        $audience,
        $output_format,
        $file_result['stored_filename'],
        $file_result['original_filename'],
        $file_result['mime_type'],
        $file_result['file_size'],
        $link_category,
        $link_term,
        $link_item_id,
    ]
);

if (!$resource_id) {
    echo json_encode(['success' => false, 'error' => 'The document was created, but could not be saved. Please try again.']);
    exit;
}

logActivity(
    $teacher_id,
    'AI Resource',
    'Generated ' . strtoupper($output_format) . ' resource' . ($audience === 'teacher' ? ' (teacher copy)' : ''),
    "Resource #$resource_id ({$title}) for student #$student_id, subject $subject_code"
        . ($link_item_id ? " — linked to Assessment Item #$link_item_id ($link_category, $link_term)." : '')
);

echo json_encode([
    'success' => true,
    'resource_id' => (int) $resource_id,
    'title' => $title,
    'resource_type' => $resource_type,
    'resource_type_label' => aiResourceTypeLabel($resource_type),
    'output_format' => $output_format,
    'audience' => $audience,
    'original_filename' => $file_result['original_filename'],
    'file_size' => (int) $file_result['file_size'],
    'link_category' => $link_category,
    'link_term' => $link_term,
    'link_item_id' => $link_item_id,
]);
