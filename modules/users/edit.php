<?php
// modules/users/edit.php
// Edit an existing user's profile, role, and (optionally) password

require_once '../../config/database.php';
require_once '../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['error_message'] = 'Invalid security token.';
    header('Location: index.php');
    exit;
}

$user_id = intval($_POST['user_id'] ?? 0);
$username = sanitize($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$email = sanitize($_POST['email'] ?? '');
$first_name = sanitize($_POST['first_name'] ?? '');
$last_name = sanitize($_POST['last_name'] ?? '');
$role_id = intval($_POST['role_id'] ?? 0);

$errors = [];

if ($user_id <= 0) $errors[] = 'Invalid user.';
if (empty($username)) $errors[] = 'Username is required.';
if (empty($email)) $errors[] = 'Email is required.';
if (!empty($email) && !validateEmail($email)) $errors[] = 'Invalid email address.';
if (empty($first_name)) $errors[] = 'First name is required.';
if (empty($last_name)) $errors[] = 'Last name is required.';
if ($role_id <= 0) $errors[] = 'Role is required.';
if (!empty($password) && strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';

if (empty($errors)) {
    $existing_user = getRecord("SELECT user_id FROM users WHERE user_id = ?", [$user_id]);
    if (!$existing_user) {
        $errors[] = 'User not found.';
    }
}

if (empty($errors)) {
    // Username/email must stay unique across OTHER accounts
    $conflict = getRecord(
        "SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ?",
        [$username, $email, $user_id]
    );
    if ($conflict) {
        $errors[] = 'Username or email is already used by another account.';
    }
}

if (empty($errors)) {
    if (!empty($password)) {
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $sql = "UPDATE users SET username = ?, password_hash = ?, email = ?, first_name = ?, last_name = ?, role_id = ? WHERE user_id = ?";
        $params = [$username, $password_hash, $email, $first_name, $last_name, $role_id, $user_id];
    } else {
        $sql = "UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ?, role_id = ? WHERE user_id = ?";
        $params = [$username, $email, $first_name, $last_name, $role_id, $user_id];
    }

    $result = executeQuery($sql, $params);

    if ($result) {
        logActivity($_SESSION['user_id'], 'users', 'Updated user', "Updated user: $username (ID $user_id)");
        $_SESSION['success_message'] = 'User updated successfully!';
        header('Location: index.php');
        exit;
    }

    $errors[] = 'The user could not be updated. Please try again.';
}

$_SESSION['error_message'] = implode('<br>', $errors);
header('Location: index.php');
exit;
