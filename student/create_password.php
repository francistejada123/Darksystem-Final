<?php
// student/create_password.php?ref=OE-...
// "Create My Password" straight from the Enrollment Tracking page -- no email
// needed, FIRST TIME ONLY. Allowed only when:
//   - this is the same device that submitted the application (tracking cookie),
//   - the application is Approved and its student has no password yet,
//   - the learner confirms their birthday.
// Then set_password.php creates the password, and an email tells the student it
// was created (so they would notice if it was not them). Once a password exists,
// only the emailed Forgot Password link can change it.

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/enrollment_helpers_online.php';   // oeSubmittedRef()
require_once __DIR__ . '/../includes/student_password.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
spwEnsureSchema();

/** The approved student this device may create a password for, or null. */
function cpEligibleStudent($ref)
{
    if ($ref === '' || oeSubmittedRef() !== $ref) return null;          // not the device that applied
    $app = getRecord("SELECT student_id, lrn, status FROM online_applications WHERE reference_no = ? AND deleted_at IS NULL", [$ref]);
    if (!$app || $app['status'] !== 'Approved') return null;
    $st = !empty($app['student_id'])
        ? getRecord("SELECT student_id, lrn, first_name, email, birth_date, portal_password_hash FROM students WHERE student_id = ? AND is_active = 1", [(int) $app['student_id']])
        : getRecord("SELECT student_id, lrn, first_name, email, birth_date, portal_password_hash FROM students WHERE lrn = ? AND is_active = 1", [$app['lrn']]);
    if (!$st || !empty($st['portal_password_hash'])) return null;         // first time only
    return $st;
}

$ref = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($_GET['ref'] ?? $_POST['ref'] ?? ''));
$student = cpEligibleStudent($ref);
$error = '';

if ($student && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $bday = trim((string) ($_POST['birth_date'] ?? ''));
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (isStudentIpRateLimited()) {
        $error = 'Too many attempts from this device. Please try again in a few minutes.';
    } elseif ($bday === '' || strtotime($bday) === false || date('Y-m-d', strtotime($bday)) !== date('Y-m-d', strtotime($student['birth_date']))) {
        logActivity(null, 'student', 'Failed login', 'Create password from tracking page: wrong birthday for LRN ' . $student['lrn']);
        $error = 'That birthday does not match our record. Please check it and try again.';
    } else {
        session_regenerate_id(true);
        $_SESSION['spw_sid'] = (int) $student['student_id'];
        $_SESSION['spw_until'] = time() + 30 * 60;
        $_SESSION['spw_notify'] = 1;    // set_password.php emails "your password was created"
        header('Location: set_password.php');
        exit;
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
    <title>Create My Password - EGRADEPRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #eef2f7; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #14213D; }
        .box { max-width: 460px; margin: 48px auto; background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(13, 71, 161, .12); padding: 30px 26px; }
        .box .icon { width: 64px; height: 64px; border-radius: 50%; display: grid; place-items: center; margin: 0 auto 12px; font-size: 26px; background: #ECFDF5; color: #15803D; }
        .box h4 { font-weight: 800; text-align: center; }
        .box .sub { text-align: center; color: #52607A; font-size: 15px; }
        .lrn-pill { display: block; text-align: center; margin: 12px auto 18px; padding: 10px; border-radius: 10px; background: #F1F5F9; font-size: 15px; }
        .lrn-pill b { font-family: ui-monospace, Consolas, monospace; letter-spacing: .05em; }
        label { font-weight: 600; font-size: 15px; }
        .form-control { height: 50px; font-size: 16px; }
        .btn-go { height: 50px; font-weight: 700; font-size: 16px; }
        @media (max-width: 500px) { .box { margin: 16px; } }
    </style>
    <?php include __DIR__ . '/../includes/pwa_head.php'; ?>
</head>
<body>
<div class="box">
<?php if (!$student): ?>
    <div class="icon" style="background:#EEF4FF;color:#1D4ED8;"><i class="fas fa-key"></i></div>
    <h4>Create your password another way</h4>
    <p class="sub">Creating a password here works only once, on the same phone or computer used for the online enrollment, before a password exists. Use the link in your approval email, or get a new link by email.</p>
    <div class="d-grid gap-2 mt-3">
        <a href="forgot_password.php" class="btn btn-primary btn-go"><i class="fas fa-key me-1"></i> Create / Forgot Password</a>
        <a href="../student_login.php" class="btn btn-outline-secondary">Go to Student Login</a>
    </div>
<?php else: ?>
    <div class="icon"><i class="fas fa-graduation-cap"></i></div>
    <h4>Create My Password</h4>
    <p class="sub">Welcome, <?php echo htmlspecialchars($student['first_name']); ?>! To make sure it is you, enter your birthday.</p>
    <div class="lrn-pill">Your username is your LRN: <b><?php echo htmlspecialchars($student['lrn']); ?></b></div>
    <?php if ($error): ?><div class="alert alert-danger py-2" style="font-size:15.5px;"><i class="fas fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="ref" value="<?php echo htmlspecialchars($ref); ?>">
        <div class="mb-3">
            <label for="bday">Your birthday</label>
            <input type="date" class="form-control" id="bday" name="birth_date" required max="<?php echo date('Y-m-d'); ?>">
        </div>
        <button type="submit" class="btn btn-primary w-100 btn-go">Next: Create Password <i class="fas fa-arrow-right ms-1"></i></button>
        <a href="../enrollment_status.php?ref=<?php echo urlencode($ref); ?>" class="btn btn-link w-100 mt-2">Back to Enrollment Tracking</a>
    </form>
<?php endif; ?>
</div>
</body>
</html>
