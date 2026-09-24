<?php
// logout.php
// User logout

session_start();

$logout_role = $_SESSION['role'] ?? '';
$is_student_logout = !empty($_SESSION['student_id']);

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Keep each portal's logout flow on its own login page.
$login_redirect = $is_student_logout
    ? 'student_login.php'
    : ($logout_role === 'Teacher' ? 'teacher_login.php' : 'login.php');
header('Location: ' . $login_redirect);
exit;
?>