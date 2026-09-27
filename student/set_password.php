<?php
// student/set_password.php
// "Congratulations! Create your password." Opened only through a valid one-time
// link (student/access.php puts the student in the session for 30 minutes).
// The student signs in afterwards with LRN + this password.

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/student_password.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
spwEnsureSchema();

$sid = (int) ($_SESSION['spw_sid'] ?? 0);
$valid = $sid > 0 && (int) ($_SESSION['spw_until'] ?? 0) > time();
$student = $valid ? getRecord("SELECT student_id, lrn, first_name, last_name, grade_level, portal_password_hash FROM students WHERE student_id = ? AND is_active = 1", [$sid]) : null;
$error = '';

if ($student && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please open the link from your email again.';
    } elseif (($problem = spwProblem($_POST['password'] ?? '', $_POST['confirm_password'] ?? '', $student['lrn'])) !== '') {
        $error = $problem;
    } else {
        $first_time = empty($student['portal_password_hash']);
        spwSet($student['student_id'], $_POST['password']);
        if (!empty($_SESSION['spw_notify'])) spwNotifyCreated((int) $student['student_id']);
        unset($_SESSION['spw_sid'], $_SESSION['spw_until'], $_SESSION['spw_notify']);
        logActivity(null, 'student', $first_time ? 'Created portal password' : 'Reset portal password', 'Student ' . $student['lrn']);
        // Sign in right away, exactly like the Student Login.
        $full = getRecord(
            "SELECT s.*, e.enrollment_status, e.section_id, sec.section_name, sec.grade_level
               FROM students s
               LEFT JOIN enrollments e ON e.student_id = s.student_id AND e.school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1)
               LEFT JOIN sections sec ON sec.section_id = e.section_id
              WHERE s.student_id = ?", [(int) $student['student_id']]);
        sptStartStudentSession($full);
        $_SESSION['login_success_message'] = $first_time
            ? 'Your password is set. Next time, sign in with your LRN and this password.'
            : 'Your new password is set.';
        header('Location: ../modules/students/portal/dashboard.php');
        exit;
    }
}
$csrf = generateCSRFToken();
$first_time = $student && empty($student['portal_password_hash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?php echo $first_time ? 'Create Your Password' : 'Set a New Password'; ?> - EGRADEPRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #eef2f7; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #14213D; }
        .box { max-width: 480px; margin: 48px auto; background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(13, 71, 161, .12); padding: 30px 26px; }
        .box .icon { width: 64px; height: 64px; border-radius: 50%; display: grid; place-items: center; margin: 0 auto 12px; font-size: 28px; }
        .box h4 { font-weight: 800; text-align: center; }
        .box .sub { text-align: center; color: #52607A; font-size: 15px; }
        .lrn-pill { display: block; text-align: center; margin: 14px auto 18px; padding: 10px; border-radius: 10px; background: #F1F5F9; font-size: 15px; }
        .lrn-pill b { font-family: ui-monospace, Consolas, monospace; letter-spacing: .05em; }
        label { font-weight: 600; font-size: 14.5px; }
        .form-control { height: 48px; font-size: 16px; }
        .pw-meter { height: 6px; border-radius: 3px; background: #E5E7EB; margin-top: 6px; overflow: hidden; }
        .pw-meter div { height: 100%; width: 0; transition: width .2s, background .2s; }
        .hint { font-size: 13px; color: #667085; margin-top: 5px; }
        .btn-go { height: 50px; font-weight: 700; font-size: 16px; }
        @media (max-width: 520px) { .box { margin: 16px; } }
    </style>
    <?php include __DIR__ . '/../includes/pwa_head.php'; ?>
</head>
<body>
<div class="box">
<?php if (!$student): ?>
    <div class="icon" style="background:#FDECEC;color:#C23A3A;"><i class="fas fa-link-slash"></i></div>
    <h4>This page has expired</h4>
    <p class="sub">Please open the <strong>Create My Password</strong> link from your email again, or get a new link.</p>
    <div class="d-grid gap-2 mt-3">
        <a href="forgot_password.php" class="btn btn-primary btn-go"><i class="fas fa-key me-1"></i> Get a new link</a>
        <a href="../student_login.php" class="btn btn-outline-secondary">Go to Student Login</a>
    </div>
<?php else: ?>
    <div class="icon" style="background:#ECFDF5;color:#15803D;"><i class="fas fa-<?php echo $first_time ? 'graduation-cap' : 'key'; ?>"></i></div>
    <h4><?php echo $first_time ? 'Congratulations, ' . htmlspecialchars($student['first_name']) . '!' : 'Set a new password'; ?></h4>
    <p class="sub"><?php echo $first_time ? 'Your enrollment is approved. Create your password to open your Student Portal.' : 'Choose a new password for your Student Portal.'; ?></p>
    <div class="lrn-pill">Your username is your LRN: <b><?php echo htmlspecialchars($student['lrn']); ?></b></div>

    <?php if ($error): ?><div class="alert alert-danger py-2" style="font-size:14.5px;"><i class="fas fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="text" name="username" value="<?php echo htmlspecialchars($student['lrn']); ?>" autocomplete="username" hidden>
        <div class="mb-3">
            <label for="pw">Password</label>
            <div class="input-group">
                <input type="password" class="form-control" id="pw" name="password" required minlength="8" autocomplete="new-password">
                <button class="btn btn-outline-secondary" type="button" data-toggle-pw="pw" aria-label="Show password"><i class="fas fa-eye"></i></button>
            </div>
            <div class="pw-meter"><div id="pwBar"></div></div>
            <div class="hint">At least 8 characters, with letters and numbers. Do not use your LRN.</div>
        </div>
        <div class="mb-3">
            <label for="pw2">Confirm Password</label>
            <div class="input-group">
                <input type="password" class="form-control" id="pw2" name="confirm_password" required minlength="8" autocomplete="new-password">
                <button class="btn btn-outline-secondary" type="button" data-toggle-pw="pw2" aria-label="Show password"><i class="fas fa-eye"></i></button>
            </div>
            <div class="hint" id="pwMatch"></div>
        </div>
        <button type="submit" class="btn btn-primary w-100 btn-go"><i class="fas fa-lock me-1"></i> <?php echo $first_time ? 'Create Password & Open My Portal' : 'Save New Password'; ?></button>
        <p class="hint text-center mt-3 mb-0"><i class="fas fa-shield-halved me-1"></i>Only you will know this password. The school cannot see it.</p>
    </form>
<?php endif; ?>
</div>
<script>
(function () {
    document.querySelectorAll('[data-toggle-pw]').forEach(function (b) {
        b.addEventListener('click', function () {
            var i = document.getElementById(b.getAttribute('data-toggle-pw'));
            i.type = i.type === 'password' ? 'text' : 'password';
            b.innerHTML = '<i class="fas fa-eye' + (i.type === 'password' ? '' : '-slash') + '"></i>';
        });
    });
    var pw = document.getElementById('pw'), pw2 = document.getElementById('pw2'), bar = document.getElementById('pwBar'), msg = document.getElementById('pwMatch');
    if (!pw) return;
    function upd() {
        var v = pw.value, s = 0;
        if (v.length >= 8) s++; if (/[A-Za-z]/.test(v) && /\d/.test(v)) s++; if (v.length >= 12) s++; if (/[^A-Za-z0-9]/.test(v)) s++;
        bar.style.width = (s * 25) + '%';
        bar.style.background = s <= 1 ? '#DC2626' : (s === 2 ? '#D97706' : '#16A34A');
        if (pw2.value) {
            var ok = pw.value === pw2.value;
            msg.textContent = ok ? 'Passwords match.' : 'Passwords do not match.';
            msg.style.color = ok ? '#16A34A' : '#DC2626';
            pw2.setCustomValidity(ok ? '' : 'Passwords do not match.');
        } else { msg.textContent = ''; pw2.setCustomValidity(''); }
    }
    pw.addEventListener('input', upd); pw2.addEventListener('input', upd);
})();
</script>
</body>
</html>
