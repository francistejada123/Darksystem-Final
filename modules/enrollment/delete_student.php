<?php
// modules/enrollment/delete_student.php
// Enrollment Management -> "Delete Student" (Administrator only, POST only).
//
// Permanently removes an enrolled student together with every related record
// (enrollment, requirements, grades, attendance, assignments/scores, AI data,
// online application, uploaded files ...) in one all-or-nothing transaction,
// so test / dummy students can be cleared out and replaced with real ones.
// All of the heavy lifting is in includes/student_delete_helpers.php.

require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/student_delete_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

// Enrollment statuses that may be deleted from this screen ("enrolled").
// To also allow test rows that are still Pending / Rejected / Transferred,
// just add them here (and to $deletable_statuses in index.php).
const EGP_DELETABLE_ENROLLMENT_STATUSES = ['Approved'];

// Administrators only — Registrar / Enrollment Officer can see the page but not delete.
if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

// Build the "go back to the list" URL, keeping the filters the admin had on.
$back = [];
foreach (['search', 'status', 'grade_level', 'date_range', 'school_year'] as $k) {
    $v = trim((string)($_POST[$k] ?? ''));
    if ($v !== '' && strlen($v) <= 100) $back[$k] = $v;
}
$back_page = intval($_POST['page'] ?? 1);
if ($back_page > 1) $back['page'] = $back_page;
$back_url = 'index.php' . ($back ? '?' . http_build_query($back) : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['error_message'] = 'Invalid or expired request. Please try the delete action again.';
    header('Location: ' . $back_url);
    exit;
}

// Nothing is deleted unless the admin explicitly confirmed in the modal.
if (($_POST['confirm'] ?? '') !== 'yes') {
    $_SESSION['error_message'] = 'Delete was not confirmed. Nothing was deleted.';
    header('Location: ' . $back_url);
    exit;
}

$enrollment_id = intval($_POST['enrollment_id'] ?? 0);
$student_id    = intval($_POST['student_id'] ?? 0);
if ($enrollment_id <= 0 || $student_id <= 0) {
    $_SESSION['error_message'] = 'Invalid student selected. Nothing was deleted.';
    header('Location: ' . $back_url);
    exit;
}

// The enrollment must exist, belong to that student, and be in a deletable state.
$enrollment = getRecord(
    "SELECT enrollment_id, student_id, enrollment_status FROM enrollments WHERE enrollment_id = ?",
    [$enrollment_id]
);
if (!$enrollment || (int)$enrollment['student_id'] !== $student_id) {
    $_SESSION['error_message'] = 'That enrollment record was not found (it may already have been deleted). Nothing was deleted.';
    header('Location: ' . $back_url);
    exit;
}
if (!in_array($enrollment['enrollment_status'], EGP_DELETABLE_ENROLLMENT_STATUSES, true)) {
    $_SESSION['error_message'] = 'Only enrolled (Approved) students can be deleted from here. Nothing was deleted.';
    header('Location: ' . $back_url);
    exit;
}

$result = egp_deleteStudentPermanently($db, $student_id, (int)($_SESSION['user_id'] ?? 0));

if ($result['success']) {
    $s = $result['student'];
    $_SESSION['success_message'] = 'Student deleted permanently: ' . $s['name'] . ' (LRN: ' . $s['lrn'] . '). '
        . 'Their enrollment and related records were removed.';
} else {
    $_SESSION['error_message'] = $result['error'] ?: 'The student could not be deleted. Nothing was removed.';
}

// (index.php falls back to the last page if this was the last row on its page.)
header('Location: ' . $back_url);
exit;
