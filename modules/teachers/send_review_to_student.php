<?php
// modules/teachers/send_review_to_student.php
//
// "Send review to <student>" under an AI Recommendation on the Student
// Monitoring dashboard (Teacher AI). The teacher reviews/edits the
// student-facing review and sends it; it lands in that student's subject
// page (student_support_messages -- the same stream every Teacher AI send
// uses, so the portal's "New" badge shows it). Nothing is sent unless the
// teacher clicks, and only to a student enrolled in one of this teacher's
// own sections for that subject this school year.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

function sendReviewFail($message, $code = 400)
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isLoggedIn() || !hasRole('Teacher')) {
    sendReviewFail('Only teachers can send reviews.', 403);
}
$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
if (!verifyCSRFToken((string) ($payload['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    sendReviewFail('Your session token expired. Refresh the page and try again.', 403);
}

$teacherId = (int) $_SESSION['user_id'];
$studentId = (int) ($payload['student_id'] ?? 0);
$subjectCode = trim((string) ($payload['subject_code'] ?? ''));
$topic = mb_substr(trim((string) ($payload['topic'] ?? '')), 0, 150);
$message = trim((string) ($payload['message'] ?? ''));
$message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $message);
if ($message === '') {
    sendReviewFail('The review is empty.');
}
if (mb_strlen($message) > 6000) {
    sendReviewFail('Please keep the review under 6,000 characters.');
}

// One student (AI Recommendation) or several (a topic's students needing
// attention). Every id is checked on its own.
$studentIds = is_array($payload['student_ids'] ?? null) ? $payload['student_ids'] : [$studentId];
$studentIds = array_values(array_unique(array_filter(array_map('intval', array_filter($studentIds, 'is_scalar')), function ($id) { return $id > 0; })));
if (!$studentIds || count($studentIds) > 200) {
    sendReviewFail('Choose at least one student.');
}

$sy = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
if (!$sy) {
    sendReviewFail('No active school year is set.');
}
$sent = 0;
$messageIds = [];
foreach ($studentIds as $sid) {
    $class = getRecord(
        "SELECT e.section_id FROM enrollments e
         INNER JOIN teacher_subjects ts ON ts.section_id = e.section_id AND ts.school_year_id = e.school_year_id
         WHERE e.student_id = ? AND e.school_year_id = ? AND e.enrollment_status = 'Approved'
           AND ts.teacher_id = ? AND ts.subject_code = ?
         LIMIT 1",
        [$sid, (int) $sy['sy_id'], $teacherId, $subjectCode]
    );
    if (!$class) {
        sendReviewFail(count($studentIds) === 1 ? 'This student is not in one of your classes for this subject.' : 'One of the selected students is not in your class for this subject. Nothing was sent.', 403);
    }
}
foreach ($studentIds as $sid) {
    $messageId = insertRecord(
        "INSERT INTO student_support_messages
            (teacher_id, student_id, subject_code, school_year_id, support_type, teacher_instruction, final_message, source, status, is_seen, sent_at)
         VALUES (?, ?, ?, ?, 'AI Review', ?, ?, 'ai_review', 'Sent', 0, NOW())",
        [$teacherId, $sid, $subjectCode, (int) $sy['sy_id'], $topic !== '' ? 'Review topic: ' . $topic : null, $message]
    );
    if ($messageId) {
        $sent++;
        $messageIds[] = (int) $messageId;
    }
}
if ($sent === 0) {
    sendReviewFail('The review could not be sent. Please try again.', 500);
}
logActivity($teacherId, 'Teacher AI', 'Sent review to student', "AI review" . ($topic !== '' ? " on '$topic'" : '') . " sent to $sent student(s) ($subjectCode): #" . implode(', #', $studentIds));
echo json_encode(['success' => true, 'sent' => $sent, 'message_id' => $messageIds[0], 'message_ids' => $messageIds]);
