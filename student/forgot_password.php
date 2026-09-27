<?php
// student/forgot_password.php
// "Create / Forgot password": the student enters their LRN and birthday; when
// both match an approved, active student with an email on file, a one-time
// "Create / Set New Password" link is emailed there. The page answers the same
// way whether or not the LRN exists, so it cannot be used to look people up.

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/student_password.php';

header('X-Robots-Tag: noindex, nofollow');
spwEnsureSchema();

$done = false;
$error = '';
$lrn_v = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lrn_v = preg_replace('/\D/', '', (string) ($_POST['lrn'] ?? ''));
    $bday = trim((string) ($_POST['birth_date'] ?? ''));
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (!preg_match('/^\d{12}$/', $lrn_v)) {
        $error = 'Enter your 12-digit LRN.';
    } elseif ($bday === '' || strtotime($bday) === false) {
        $error = 'Enter your birthday.';
    } elseif (isStudentIpRateLimited()) {
        $error = 'Too many attempts from this device. Please try again in a few minutes.';
    } else {
        $st = getRecord("SELECT student_id, lrn, first_name, email, portal_password_hash FROM students WHERE lrn = ? AND birth_date = ? AND is_active = 1",
                        [$lrn_v, date('Y-m-d', strtotime($bday))]);
        if ($st) {
            $r = spwSendSetupLink($st, empty($st['portal_password_hash']) ? 'setup' : 'reset');
            logActivity(null, 'student', 'Password link requested', 'Student ' . $lrn_v . ($r['success'] ? ' (sent)' : ' (not sent: ' . ($r['error'] ?? '') . ')'));
        } else {
            logActivity(null, 'student', 'Failed login', 'Password link request did not match LRN + birthday: ' . $lrn_v);
        }
        $done = true;   // same answer either way
    }
}
$csrf = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Create / Forgot Password - EGRADEPRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #eef2f7; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #14213D; }
        .box { max-width: 460px; margin: 48px auto; background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(13, 71, 161, .12); padding: 30px 26px; }
        .box .icon { width: 64px; height: 64px; border-radius: 50%; display: grid; place-items: center; margin: 0 auto 12px; font-size: 26px; background: #EEF4FF; color: #1D4ED8; }
        .box h4 { font-weight: 800; text-align: center; }
        .box .sub { text-align: center; color: #52607A; font-size: 15px; }
        label { font-weight: 600; font-size: 14.5px; }
        .form-control { height: 48px; font-size: 16px; }
        .btn-go { height: 50px; font-weight: 700; font-size: 16px; }
        @media (max-width: 500px) { .box { margin: 16px; } }
    </style>
    <?php include __DIR__ . '/../includes/pwa_head.php'; ?>
</head>
<body>
<div class="box">
    <div class="icon"><i class="fas fa-key"></i></div>
<?php if ($done): ?>
    <h4>Check your email</h4>
    <p class="sub">If the LRN and birthday match an approved student with an email on file, we sent a <strong>Create / Set New Password</strong> link to that email. It works once and expires in <?php echo SPT_VALID_DAYS; ?> days.</p>
    <p class="sub" style="font-size:14px;">No email arrived after a few minutes? Check Spam, or ask the registrar to send you a new link.</p>
    <a href="../student_login.php" class="btn btn-primary w-100 btn-go mt-2">Back to Student Login</a>
<?php else: ?>
    <h4>Create / Forgot Password</h4>
    <p class="sub">Enter your LRN and birthday. We will email you a link to create or reset your password.</p>
    <?php if ($error): ?><div class="alert alert-danger py-2" style="font-size:14.5px;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <div class="mb-3">
            <label for="lrn">LRN</label>
            <input type="text" class="form-control" id="lrn" name="lrn" inputmode="numeric" pattern="\d{12}" maxlength="12" required placeholder="12-digit LRN" value="<?php echo htmlspecialchars($lrn_v); ?>">
        </div>
        <div class="mb-3">
            <label for="bday">Birthday</label>
            <input type="date" class="form-control" id="bday" name="birth_date" required max="<?php echo date('Y-m-d'); ?>">
        </div>
        <button type="submit" class="btn btn-primary w-100 btn-go"><i class="fas fa-paper-plane me-1"></i> Send me the link</button>
        <a href="../student_login.php" class="btn btn-link w-100 mt-2">Back to Student Login</a>
    </form>
<?php endif; ?>
</div>
<script>
document.getElementById('lrn') && document.getElementById('lrn').addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 12); });
</script>
</body>
</html>
