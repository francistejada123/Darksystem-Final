<?php
// modules/subjects/delete.php
// Delete subject (soft delete)

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$subject_code = isset($_GET['code']) ? urldecode($_GET['code']) : '';

if (empty($subject_code)) {
    header('Location: index.php');
    exit;
}

// Get subject name for logging
$subject = getRecord("SELECT subject_code, subject_name FROM subjects WHERE subject_code = ? AND is_active = 1", [$subject_code]);
if (!$subject) {
    $_SESSION['error_message'] = 'Subject not found.';
    header('Location: index.php');
    exit;
}

// Check if subject is used in grades or teacher assignments
$used = getRecord("SELECT COUNT(*) as count FROM grades WHERE subject_code = ?", [$subject_code]);
if ($used && $used['count'] > 0) {
    $_SESSION['error_message'] = 'Cannot delete subject. It is already used in grading records.';
    header('Location: index.php');
    exit;
}

$used_teacher = getRecord("SELECT COUNT(*) as count FROM teacher_subjects WHERE subject_code = ?", [$subject_code]);
if ($used_teacher && $used_teacher['count'] > 0) {
    $_SESSION['error_message'] = 'Cannot delete subject. It is assigned to a teacher. Remove assignments first.';
    header('Location: index.php');
    exit;
}

// Soft delete (set is_active = 0)
$result = executeQuery("UPDATE subjects SET is_active = 0 WHERE subject_code = ?", [$subject_code]);

if ($result) {
    logActivity($_SESSION['user_id'], 'Deleted subject', 'subjects', "Deleted subject: {$subject['subject_code']} - {$subject['subject_name']}");
    $_SESSION['success_message'] = 'Subject deleted successfully!';
} else {
    $_SESSION['error_message'] = 'Failed to delete subject.';
}

header('Location: index.php');
exit;
?>