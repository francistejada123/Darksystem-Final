<?php
// student/access.php?token=...
// The "Create My Password" link from the enrollment approval email (or from
// Create / Forgot password, or sent by the registrar). A valid, unused,
// unexpired token of an approved, active student opens set_password.php, where
// the student creates their own password. Anything else shows one message --
// it never says which part failed.

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/student_access_tokens.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer'); // the token never leaks to another site

$token = (string) ($_GET['token'] ?? '');
$student = null;
if ($token !== '' && !isStudentIpRateLimited()) {
    $student = sptConsume($token);
}

if ($student) {
    // The link proves the email is theirs: next, the student creates (or resets)
    // their own password on set_password.php, then they are signed in.
    session_regenerate_id(true);
    $_SESSION['spw_sid'] = (int) $student['student_id'];
    $_SESSION['spw_until'] = time() + 30 * 60;
    logActivity(null, 'student', 'Password link opened', 'Student ' . $student['lrn'] . ' opened the create-password link');
    header('Location: set_password.php');
    exit;
}

logActivity(null, 'student', 'Failed login', 'Invalid, used or expired Student Portal access link');
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal Access</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #eef2f7; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .box { max-width: 520px; margin: 80px auto; background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(13, 71, 161, .12); padding: 32px 28px; text-align: center; }
    </style>
    <?php include __DIR__ . '/../includes/pwa_head.php'; ?>
</head>
<body>
    <div class="box">
        <i class="fas fa-link-slash text-danger" style="font-size:48px"></i>
        <h4 class="mt-3 fw-bold">Link not valid</h4>
        <p class="text-muted">This link is invalid, already used, or expired. You can get a new one from <strong>Create / Forgot password</strong> on the Student Login page.</p>
        <a href="../student_login.php" class="btn btn-primary px-4"><i class="fas fa-right-to-bracket me-1"></i>Go to Student Login</a>
        <div class="mt-2"><a href="forgot_password.php" class="btn btn-outline-primary px-4"><i class="fas fa-key me-1"></i>Get a new link</a></div>
        <div class="small text-muted mt-3">No email on file? Ask the registrar to send you a new link.</div>
    </div>
</body>
</html>
