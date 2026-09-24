<?php
// modules/users/delete.php
// Delete user

require_once '../../config/database.php';
require_once '../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$user_id = intval($_GET['id'] ?? 0);

if ($user_id <= 0) {
    header('Location: index.php');
    exit;
}

// Prevent deleting yourself
if ($user_id == $_SESSION['user_id']) {
    $_SESSION['error_message'] = 'You cannot delete your own account.';
    header('Location: index.php');
    exit;
}

// Get user info
$user = getRecord("SELECT username, first_name, last_name FROM users WHERE user_id = ?", [$user_id]);
if (!$user) {
    $_SESSION['error_message'] = 'User not found.';
    header('Location: index.php');
    exit;
}

// Check if user has related records
$has_students = getRecord("SELECT COUNT(*) as count FROM students WHERE user_id = ?", [$user_id]);
if ($has_students && $has_students['count'] > 0) {
    $_SESSION['error_message'] = 'Cannot delete user. They are linked to student records.';
    header('Location: index.php');
    exit;
}

$has_teachers = getRecord("SELECT COUNT(*) as count FROM teacher_subjects WHERE teacher_id = ?", [$user_id]);
if ($has_teachers && $has_teachers['count'] > 0) {
    $_SESSION['error_message'] = 'Cannot delete user. They are assigned to subjects.';
    header('Location: index.php');
    exit;
}

// Soft delete
$result = executeQuery("UPDATE users SET is_active = 0 WHERE user_id = ?", [$user_id]);

if ($result) {
    logActivity($_SESSION['user_id'], 'Deleted user', 'users', "Deleted user: {$user['username']} ({$user['first_name']} {$user['last_name']})");
    $_SESSION['success_message'] = 'User deleted successfully!';
} else {
    $_SESSION['error_message'] = 'Failed to delete user.';
}

header('Location: index.php');
exit;
?>