<?php
// modules/students/send_portal_link.php
// Registrar / Admin: email a student a one-time "Create / Set New Password"
// link for the Student Portal (walk-in students, lost emails, forgotten
// passwords). The school never sees or sets the student's password.

require_once __DIR__ . '/../../config/database.php';
if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student_password.php';

if (!hasAnyRole(['Administrator', 'Registrar'])) {
    header('Location: ../../index.php');
    exit;
}
$id = (int) ($_POST['student_id'] ?? 0);
$back = 'view.php?id=' . $id . (!empty($_POST['embed']) ? '&embed=1' : '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['error_message'] = 'Invalid or expired request. Please try again.';
    header('Location: ' . $back);
    exit;
}
spwEnsureSchema();
$st = getRecord("SELECT student_id, lrn, first_name, last_name, email, portal_password_hash FROM students WHERE student_id = ? AND is_active = 1", [$id]);
if (!$st) {
    $_SESSION['error_message'] = 'Student not found.';
    header('Location: index.php');
    exit;
}
$r = spwSendSetupLink($st, empty($st['portal_password_hash']) ? 'setup' : 'reset');
logActivity($_SESSION['user_id'], 'students', 'Sent portal password link', "{$st['last_name']}, {$st['first_name']} (LRN {$st['lrn']})" . ($r['success'] ? '' : ' - not sent: ' . $r['error']));
// Shown on the student's card (view.php also runs embedded, without the page toasts).
$_SESSION['portal_link_flash'] = $r['success']
    ? ['success', 'Password link sent to ' . $r['masked'] . '. It works once and expires in ' . SPT_VALID_DAYS . ' days.']
    : ['danger', 'The link was not sent: ' . $r['error'] . ($st['email'] ? '' : ' Add the student\'s email first (Edit).')];
header('Location: ' . $back);
