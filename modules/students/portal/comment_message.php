<?php
// modules/students/portal/comment_message.php
//
// A student posts a comment/question under one of their own messages on a
// subject page. The teacher who sent the message is notified (bell). See
// includes/message_comment_helpers.php.

session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once '../../../includes/student_password.php';   // spwRequireChangedPassword()
require_once __DIR__ . '/../../../includes/message_comment_helpers.php';

if (!isset($_SESSION['student_id']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: ../../../student_login.php');
    exit;
}

// First login: the account still holds the temporary password issued when the
// Admin approved the enrollment, so the portal may not be used until it is
// changed (see includes/student_password.php).
if (spwRequireChangedPassword()) exit;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$student_id = (int) $_SESSION['student_id'];
$message_id = (int) ($_POST['message_id'] ?? 0);
// Where to go back to comes from the message itself, never from $_POST.
$msg = getRecord("SELECT subject_code FROM student_support_messages WHERE message_id = ? AND student_id = ?", [$message_id, $student_id]);
$back = $msg ? 'subject.php?subject=' . urlencode($msg['subject_code']) : 'dashboard.php';

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    header('Location: ' . $back . ($msg ? '&submit_error=' . urlencode('Invalid security token. Please try again.') : ''));
    exit;
}

$result = mc_addStudentComment($student_id, $message_id, $_POST['comment'] ?? '');
if (!$result['success']) {
    header('Location: ' . $back . ($msg ? '&submit_error=' . urlencode($result['error']) . '#msg-' . $message_id : ''));
    exit;
}
header('Location: ' . $back . '&commented=1#msg-' . $message_id);
exit;
