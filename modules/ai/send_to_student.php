<?php
// modules/ai/send_to_student.php
// AJAX endpoint: the teacher clicks "Send to Student" on a message inside
// the Ask AI chat (an AI reply bubble, or something the teacher typed
// themselves) and it becomes an actual message the student can see in
// their portal (modules/students/portal/messages.php).
//
// This is the ONLY place a chat message actually becomes visible to a
// student — nothing in gemini_service.php, chatbot.php, or
// generate_resource.php saves or sends anything on its own; generating a
// file only ever reaches ai_generated_resources.status = 'Generated',
// never 'Sent'. Ownership is re-validated here from scratch, the same way
// chatbot.php does it, rather than trusting that an earlier chat turn in
// the same conversation already checked it.
//
// UPGRADED: can now optionally attach a previously generated resource
// (resource_id) alongside the text message — see the resource_id block
// below. The plain text-only flow (no resource_id posted) is completely
// unchanged from before this upgrade.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/gemini_service.php'; // validateTeacherSubjectForStudent(), buildStudentRiskContext()

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
$message = trim((string) ($_POST['message'] ?? ''));
$ai_draft = trim((string) ($_POST['ai_draft'] ?? ''));
$source = ($_POST['source'] ?? '') === 'ai' ? 'ai' : 'manual';
$resource_id = (int) ($_POST['resource_id'] ?? 0);

if (!$teacher_id || !$student_id || $subject_code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing student or subject.']);
    exit;
}
if ($message === '') {
    echo json_encode(['success' => false, 'error' => 'Message cannot be empty.']);
    exit;
}
if (mb_strlen($message) > 2000) {
    echo json_encode(['success' => false, 'error' => 'Message is too long.']);
    exit;
}

// ---- Re-validate everything from scratch — never trust that a prior
// chat turn already checked this. ----
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
if (!$active_sy) {
    echo json_encode(['success' => false, 'error' => 'No active school year is set.']);
    exit;
}

$student = getRecord(
    "SELECT s.*, sec.section_name
     FROM students s
     LEFT JOIN sections sec ON s.section_id = sec.section_id
     WHERE s.student_id = ? AND s.is_active = 1",
    [$student_id]
);
if (!$student) {
    echo json_encode(['success' => false, 'error' => 'Student not found.']);
    exit;
}

$check = validateTeacherSubjectForStudent($teacher_id, $student_id, $subject_code, $active_sy['sy_id']);
if (!$check['success']) {
    echo json_encode(['success' => false, 'error' => $check['error']]);
    exit;
}
$matched = $check['subject'];
$subject_name = $matched['subject_name'];
$current_grade = (float) $matched['final_grade'];
$pass_mark = getGradingPassMark();

$context = buildStudentRiskContext($student, $subject_name, $subject_code, $current_grade, $pass_mark, $student_id, $active_sy['sy_id']);

// ---- Optional generated-resource attachment. Re-validated completely
// independently of chatbot.php/generate_resource.php's own checks — a
// resource_id is only ever attached here if it demonstrably belongs to
// THIS teacher, THIS student, THIS subject, is the STUDENT-facing version
// (never a teacher answer-key copy — audience must be 'student'), and
// already has an actual generated file. Anything else is a silent no-op:
// the text message still sends, just without an attachment, rather than
// failing the whole send. ----
$attach_resource_id = null;
if ($resource_id > 0) {
    $resource = getRecord(
        "SELECT resource_id, output_format, audience FROM ai_generated_resources
         WHERE resource_id = ? AND teacher_id = ? AND student_id = ? AND subject_code = ? AND status IN ('Generated', 'Sent')",
        [$resource_id, $teacher_id, $student_id, $subject_code]
    );
    if ($resource && $resource['audience'] === 'student' && !empty($resource['output_format'])) {
        $attach_resource_id = (int) $resource['resource_id'];
    }
}

$message_id = insertRecord(
    "INSERT INTO student_support_messages
        (teacher_id, student_id, subject_code, school_year_id, risk_level, risk_reason, current_grade,
         support_type, ai_draft, final_message, resource_id, source, status, sent_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Sent', NOW())",
    [
        $teacher_id,
        $student_id,
        $subject_code,
        $active_sy['sy_id'],
        $context['risk_level'],
        $context['risk_reason'],
        $current_grade,
        'AI Chat',
        $ai_draft !== '' ? $ai_draft : null,
        $message,
        $attach_resource_id,
        $source,
    ]
);

if (!$message_id) {
    echo json_encode(['success' => false, 'error' => 'Could not save the message. Please try again.']);
    exit;
}

if ($attach_resource_id !== null) {
    updateRecord(
        "UPDATE ai_generated_resources SET status = 'Sent', sent_at = NOW() WHERE resource_id = ?",
        [$attach_resource_id]
    );
    logActivity(
        $teacher_id,
        'AI Resource',
        'Sent resource to student',
        "Resource #$attach_resource_id sent to student #$student_id via message #$message_id, subject $subject_code"
    );
}

echo json_encode([
    'success' => true,
    'message_id' => (int) $message_id,
    'resource_id' => $attach_resource_id,
    'sent_at' => date('M j, Y g:i A'),
]);
