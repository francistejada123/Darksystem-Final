<?php
// modules/users/toggle_status.php
// Activate or deactivate a user account (soft delete / restore)

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
$activate = isset($_GET['activate']) ? intval($_GET['activate']) : null;

if ($user_id <= 0 || $activate === null) {
    header('Location: index.php');
    exit;
}

// Prevent an admin from deactivating their own account
if ($user_id == $_SESSION['user_id']) {
    $_SESSION['error_message'] = 'You cannot change the status of your own account.';
    header('Location: index.php');
    exit;
}

// Get user info
$user = getRecord("SELECT username, first_name, last_name, is_active FROM users WHERE user_id = ?", [$user_id]);
if (!$user) {
    $_SESSION['error_message'] = 'User not found.';
    header('Location: index.php');
    exit;
}

$new_status = $activate ? 1 : 0;

// When deactivating, make sure that doesn't silently orphan active teaching assignments
if ($new_status === 0) {
    $has_teachers = getRecord("SELECT COUNT(*) as count FROM teacher_subjects WHERE teacher_id = ?", [$user_id]);
    if ($has_teachers && $has_teachers['count'] > 0) {
        $_SESSION['error_message'] = 'Cannot deactivate this user. They are still assigned to subjects — reassign those first.';
        header('Location: index.php');
        exit;
    }
}

$result = executeQuery("UPDATE users SET is_active = ? WHERE user_id = ?", [$new_status, $user_id]);
$full_name = trim($user['first_name'] . ' ' . $user['last_name']);

if ($result && $result->rowCount() > 0) {
    $action_label = $new_status ? 'Reactivated user' : 'Deactivated user';
    logActivity($_SESSION['user_id'], 'users', $action_label, "$action_label: {$user['username']} ($full_name)");
    $_SESSION['success_message'] = $new_status
        ? "$full_name's account has been reactivated."
        : "$full_name's account has been deactivated.";
} else {
    $_SESSION['error_message'] = 'Failed to update the account status. Please try again.';
}

header('Location: index.php');
exit;
