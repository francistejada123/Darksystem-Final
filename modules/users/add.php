<?php
// modules/users/add.php
// Add user

require_once '../../config/database.php';
require_once '../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid security token.');
    }
    
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = sanitize($_POST['email'] ?? '');
    $first_name = sanitize($_POST['first_name'] ?? '');
    $last_name = sanitize($_POST['last_name'] ?? '');
    $role_id = intval($_POST['role_id'] ?? 0);
    
    $errors = [];
    
    if (empty($username)) $errors[] = 'Username is required.';
    if (empty($password) || strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if (empty($email)) $errors[] = 'Email is required.';
    if (!validateEmail($email)) $errors[] = 'Invalid email address.';
    if (empty($first_name)) $errors[] = 'First name is required.';
    if (empty($last_name)) $errors[] = 'Last name is required.';
    if ($role_id <= 0) {
        $errors[] = 'Role is required.';
    } else {
        // Enforced here too, not just by which options the Add User modal
        // renders — Teacher/Student/Parent/Registrar accounts are created
        // through their own dedicated flows (e.g. Teachers > Add Teacher),
        // not this generic form. Checked by name against the live roles
        // table rather than a hardcoded ID list, since role IDs aren't fixed.
        $addable_role = getRecord(
            "SELECT role_id FROM roles WHERE role_id = ? AND role_name IN ('Administrator', 'Enrollment Officer')",
            [$role_id]
        );
        if (!$addable_role) {
            $errors[] = 'That role cannot be assigned from Add User.';
        }
    }
    
    if (empty($errors)) {
        $check = getRecord("SELECT user_id FROM users WHERE username = ? OR email = ?", [$username, $email]);
        if ($check) {
            $errors[] = 'Username or email already exists.';
        }
    }
    
    if (empty($errors)) {
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $sql = "INSERT INTO users (username, password_hash, email, first_name, last_name, role_id, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $result = executeQuery($sql, [$username, $password_hash, $email, $first_name, $last_name, $role_id, 1]);
        
        if ($result) {
            logActivity($_SESSION['user_id'], 'Added user', 'users', "Added user: $username");
            $_SESSION['success_message'] = 'User added successfully!';
            header('Location: index.php?success=1');
            exit;
        }
    }
    
    $_SESSION['error_message'] = implode('<br>', $errors);
    header('Location: index.php');
    exit;
}
?>