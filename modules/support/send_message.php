<?php
// modules/support/send_message.php
// TEACHER — final step: save the (possibly AI-drafted, possibly
// teacher-edited, possibly entirely hand-written) message and make it
// visible to the student. This is the ONLY place a support message
// actually becomes visible to a student — Gemini itself never writes to
// student_support_messages or marks anything "Sent" (see
// includes/gemini_service.php header note #5).
//
// Re-validates teacher/student/subject ownership independently of
// generate_draft.php — a submitted form is just data from the browser and
// is never trusted for authorization on its own.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/gemini_service.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Teacher')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['error_message'] = 'Invalid request. Please try again.';
    header('Location: ' . BASE_URL . 'modules/ai/index.php');
    exit;
}

$teacher_id = (int) $_SESSION['user_id'];
$student_id = intval($_POST['student_id'] ?? 0);
$subject_code = sanitize($_POST['subject_code'] ?? '');
$support_type = sanitize($_POST['support_type'] ?? '');
$teacher_instruction = sanitize($_POST['teacher_instruction'] ?? '');
$ai_draft = trim($_POST['ai_draft'] ?? '');
$source = ($_POST['source'] ?? '') === 'ai' ? 'ai' : 'manual';
$final_message = trim($_POST['final_message'] ?? '');

$back_url = BASE_URL . 'modules/support/index.php?student_id=' . $student_id;

if ($final_message === '') {
    $_SESSION['error_message'] = 'Please write or generate a message before sending.';
    header('Location: ' . $back_url);
    exit;
}
if (mb_strlen($final_message) > 2000) {
    $_SESSION['error_message'] = 'Message is too long.';
    header('Location: ' . $back_url);
    exit;
}
if (!in_array($support_type, GEMINI_SUPPORT_TYPES, true)) {
    $support_type = 'Manual';
}

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
if (!$active_sy) {
    $_SESSION['error_message'] = 'No active school year is set.';
    header('Location: ' . BASE_URL . 'modules/ai/index.php');
    exit;
}

$check = validateTeacherSubjectForStudent($teacher_id, $student_id, $subject_code, $active_sy['sy_id']);
if (!$check['success']) {
    $_SESSION['error_message'] = $check['error'];
    header('Location: ' . BASE_URL . 'modules/ai/index.php');
    exit;
}
$subject = $check['subject'];
$pass_mark = getGradingPassMark();
$current_grade = (float) $subject['final_grade'];
$risk_level = $current_grade < ($pass_mark - 5) ? 'High Risk' : 'Medium Risk';
$risk_reason = 'Current ' . $subject['subject_name'] . ' grade (' . number_format($current_grade, 2) . ') is below the school\'s passing mark of ' . number_format($pass_mark, 2) . '.';

$message_id = insertRecord(
    "INSERT INTO student_support_messages
     (teacher_id, student_id, subject_code, school_year_id, risk_level, risk_reason, current_grade,
      support_type, teacher_instruction, ai_draft, final_message, source, status, sent_at)
     VALUES (?,?,?,?,?,?,?, ?,?,?,?,?, 'Sent', NOW())",
    [
        $teacher_id, $student_id, $subject_code, $active_sy['sy_id'], $risk_level, $risk_reason, $current_grade,
        $support_type, ($teacher_instruction ?: null), ($ai_draft ?: null), $final_message, $source,
    ]
);

if (!$message_id) {
    $_SESSION['error_message'] = 'Could not save the message. Please try again.';
    header('Location: ' . $back_url);
    exit;
}

logActivity($teacher_id, 'student_support', 'Sent support message', "Student ID $student_id — {$subject['subject_name']} ($support_type, source: $source)");

$_SESSION['success_message'] = 'Support message sent.';
header('Location: ' . BASE_URL . 'modules/ai/index.php');
exit;
