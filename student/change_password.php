<?php
// student/change_password.php
// FIRST-LOGIN password change.
//
// When the Admin approves an online application the Student Portal account is
// created automatically with a random TEMPORARY password (see
// spwProvisionTemporaryPassword()) that is emailed to the student. Signing in
// with LRN + that temporary password authenticates the student but must not yet
// open the portal: student_login.php sends them straight here, and the portal's
// own guard (modules/students/portal/dashboard.php) blocks every other page
// until the change is done.
//
// The temporary password stops working the moment it is replaced, because the
// only stored copy is its hash and that hash is overwritten here.

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/student_password.php';
spwEnsureSchema();

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$studentId = (int) ($_SESSION['student_id'] ?? 0);
$student = $studentId
    ? getRecord("SELECT student_id, first_name, last_name, lrn, portal_password_hash, portal_password_temp FROM students WHERE student_id = ? AND is_active = 1", [$studentId])
    : null;
$isStudent = ($_SESSION['role'] ?? '') === 'Student';

// Only a signed-in student holding a temporary password is sent here.
if (!$student || !$isStudent || !spwMustChangePassword($student)) {
    header('Location: ' . ($student && $isStudent ? '../modules/students/portal/dashboard.php' : '../student_login.php'));
    exit;
}

$error = '';
$done = false;
$csrf = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (isStudentIpRateLimited()) {
        $error = 'Too many attempts from this device. Please try again in a few minutes.';
    } else {
        // The project's existing password rules, with the LRN explicitly banned.
        $problem = spwProblem($new, $confirm, $student['lrn']);
        if ($problem !== '') {
            $error = $problem;
        } elseif ($new === $student['portal_password_hash']) {
            $error = 'Your new password must be different from the temporary one.';
        } else {
            spwSetPermanentPassword((int) $student['student_id'], $new);
            logActivity(null, 'student', 'Password changed', 'LRN ' . $student['lrn'] . ' changed the temporary password');

            // The student already proved who they are (LRN + temporary password) and
            // have now completed the required change, so the session is kept rather
            // than dropped: they go straight to the Dashboard instead of signing in
            // again. sptStartStudentSession() is the same helper student_login.php
            // uses, so the resulting session is identical to a normal sign-in --
            // nothing is bypassed, the credentials were genuinely verified earlier.
            // It also rotates the session id, so keeping the session stays safe.
            require_once __DIR__ . '/../includes/student_access_tokens.php';
            $full = getRecord(
                "SELECT s.*, e.enrollment_status, e.section_id, sec.section_name, sec.grade_level
                   FROM students s
                   LEFT JOIN enrollments e ON e.student_id = s.student_id
                       AND e.school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1)
                   LEFT JOIN sections sec ON sec.section_id = e.section_id
                  WHERE s.student_id = ?", [(int) $student['student_id']]
            );
            if ($full) {
                sptStartStudentSession($full);
            } else {
                session_regenerate_id(true);
            }
            unset($_SESSION['spw_sid'], $_SESSION['spw_until']);
            $_SESSION['password_changed_message'] = 'Your password has been changed successfully.';
            // Straight to the dashboard -- a refresh lands there too, and the
            // temporary password no longer works anywhere.
            header('Location: ../modules/students/portal/dashboard.php', true, 303);
            exit;
        }
    }
}

$name = trim($student['first_name'] . ' ' . $student['last_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
    (function () {
        try {
            var t = localStorage.getItem('egradepro-theme');
            if (!t) { t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light'; }
            if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        } catch (e) {}
    })();
    </script>
    <title>Change Password - EGRADEPRO Student Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="static/css/theme.css" rel="stylesheet">
    <style>
        body { font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:#eef2f7; min-height:100vh; display:flex; align-items:center; justify-content:center; margin:0; padding:20px; }
        .card-wrap { max-width:440px; width:100%; }
        .panel { background:#fff; border-radius:16px; box-shadow:0 10px 40px rgba(13,71,161,.12); padding:26px 24px; }
        .panel-head { text-align:center; margin-bottom:20px; }
        .panel-head h3 { font-weight:700; font-size:18px; color:#14213D; margin:0 0 4px; }
        .panel-head p { color:#667085; font-size:14.5px; margin:0; }
        .who { background:#F0F7FF; border:1px solid #BFDBFE; border-radius:12px; padding:12px 14px; margin-bottom:18px; font-size:14.5px; color:#1E3A5F; }
        .who strong { display:block; font-size:15px; }
        label { font-weight:600; font-size:14.5px; color:#334155; }
        .form-control { border-radius:10px; padding:12px 14px; border:1px solid #cbd5e1; font-size:15px; height:48px; }
        .form-control:focus { border-color:#1565c0; box-shadow:0 0 0 3px rgba(21,101,192,.12); }
        .btn-save { background:#1565c0; color:#fff; border:0; border-radius:10px; padding:13px; font-weight:600; width:100%; font-size:15px; }
        .btn-save:hover { background:#0d47a1; }
        .rules { font-size:13.5px; color:#667085; margin:10px 0 0; line-height:1.5; }
        .rules li { margin:2px 0; }
    </style>
</head>
<body>
<div class="card-wrap">
    <div class="panel">
        <div class="panel-head">
            <i class="fas fa-key text-primary" style="font-size:34px"></i>
            <h3>Change Your Password</h3>
            <p>You are signed in with a temporary password.</p>
        </div>

        <?php /* The change succeeds straight into the dashboard, so there is
                 no "done" screen here -- reaching this page at all means the
                 student still has to change the password. */ ?>
        <div class="who">
            Student<strong><?php echo htmlspecialchars($name); ?></strong>
            LRN / Username<strong><?php echo htmlspecialchars($student['lrn']); ?></strong>
        </div>

        <div class="alert alert-warning py-2" style="font-size:14px;">
            <i class="fas fa-triangle-exclamation me-1"></i>Please set your own password before using the portal.
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

            <form method="POST" action="" autocomplete="off" id="pwForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <div class="mb-3">
                    <label for="pw1">New Password</label>
                    <input type="password" id="pw1" name="new_password" class="form-control" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters">
                </div>
                <div class="mb-3">
                    <label for="pw2">Confirm Password</label>
                    <input type="password" id="pw2" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password" placeholder="Repeat the password">
                </div>
                <ul class="rules">
                    <li>At least 8 characters.</li>
                    <li>Use both letters and numbers.</li>
                    <li>Do not use your LRN as your password.</li>
                    <li>The two passwords must match.</li>
                </ul>
                <button type="submit" class="btn btn-save mt-3"><i class="fas fa-check me-1"></i>Save Password &amp; Continue</button>
            </form>
    </div>
</div>
<script src="static/js/theme.js"></script>
<script>
// Catch a mismatch before submitting, so the student is never surprised after saving.
(function () {
    var f = document.getElementById('pwForm');
    if (!f) return;
    f.addEventListener('submit', function (e) {
        var a = f.elements.new_password, b = f.elements.confirm_password;
        if (a.value !== b.value) {
            e.preventDefault();
            b.setCustomValidity('The two passwords do not match.');
            b.reportValidity();
            b.setCustomValidity('');
        }
    });
})();
</script>
</body>
</html>
