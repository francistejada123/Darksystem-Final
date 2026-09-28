<?php
// teacher_register.php
// Opened from the "Complete Teacher Registration" email. The invited teacher
// fills in their own Basic and Professional Information and creates their own
// password. The account is created with the invited email only; the link works
// once. See includes/teacher_invitations.php.

require_once __DIR__ . '/config/database.php';
if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/includes/auth.php';               // session, hashPassword()
require_once __DIR__ . '/includes/teacher_invitations.php';

header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');   // keep the token out of Referer headers

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$invite = tiFind($token);
$errors = [];
$done = null;
$v = [];

if ($invite && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please submit the form again.';
    } else {
        $r = tiRegister($token, $_POST);
        if ($r['success']) {
            $done = $r;
            $invite = null;
        } else {
            $errors = $r['errors'];
        }
    }
    foreach ($_POST as $k => $val) {
        if (!in_array($k, ['password', 'confirm_password', 'csrf_token', 'token'], true) && is_string($val)) $v[$k] = $val;
    }
}
$val = function ($k, $default = '') use ($v) { return htmlspecialchars((string) ($v[$k] ?? $default), ENT_QUOTES, 'UTF-8'); };
$sel = function ($k, $opt) use ($v) { return (($v[$k] ?? '') === $opt) ? ' selected' : ''; };
$csrf = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Teacher Registration - EGRADEPRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #F1F4F8; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #14213D; }
        .reg-top { background: #14213D; color: #fff; padding: 16px 0; }
        .reg-top .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 18px; }
        .reg-top img { width: 36px; height: 36px; border-radius: 8px; background: #fff; object-fit: contain; }
        .reg-wrap { max-width: 980px; margin: 28px auto 48px; padding: 0 16px; }
        .reg-card { background: #fff; border: 1px solid #E2E8F0; border-radius: 12px; margin-bottom: 20px; overflow: hidden; }
        .reg-card-head { padding: 16px 22px; border-bottom: 1px solid #EEF2F6; display: flex; align-items: center; gap: 10px; }
        .reg-card-head h5 { margin: 0; font-size: 17px; font-weight: 700; }
        .reg-card-body { padding: 22px; }
        .reg-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        .reg-grid label { display: block; font-size: 15px; font-weight: 600; margin-bottom: 5px; color: #344054; }
        .reg-grid .form-control, .reg-grid .form-select { height: 44px; font-size: 15px; }
        .req { color: #C94A4A; }
        .hint { font-size: 14px; color: #667085; margin-top: 4px; }
        .reg-intro h1 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
        .reg-intro p { color: #52607A; font-size: 15px; margin: 0 0 20px; }
        .btn-reg { background: #14213D; color: #fff; border: 0; height: 48px; padding: 0 28px; border-radius: 8px; font-weight: 600; font-size: 16px; }
        .btn-reg:hover { background: #22345c; color: #fff; }
        .state-card { max-width: 620px; margin: 60px auto; background: #fff; border: 1px solid #E2E8F0; border-radius: 14px; padding: 36px; text-align: center; }
        .state-card .icon { width: 72px; height: 72px; border-radius: 50%; display: grid; place-items: center; margin: 0 auto 16px; font-size: 30px; }
        .state-card h2 { font-size: 23px; font-weight: 700; margin-bottom: 10px; }
        .state-card p { color: #52607A; font-size: 15.5px; }
        .status-flow { display: inline-flex; align-items: center; gap: 10px; margin: 8px 0 18px; font-size: 15px; font-weight: 600; }
        .status-flow span { padding: 5px 12px; border-radius: 999px; }
        .pw-meter { height: 6px; border-radius: 3px; background: #E5E7EB; margin-top: 6px; overflow: hidden; }
        .pw-meter div { height: 100%; width: 0; transition: width .2s, background .2s; }
        @media (max-width: 800px) { .reg-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="reg-top"><div class="container"><div class="brand"><img src="static/images/logo.png" alt="" onerror="this.style.display='none'"> EGradePro <span style="font-weight:400;opacity:.7;font-size:15px;">Teacher Registration</span></div></div></div>

<?php if ($done): ?>
    <div class="state-card">
        <div class="icon" style="background:#EAF7F1;color:#238863;"><i class="fas fa-circle-check"></i></div>
        <h2>Your EGradePro Teacher Account is being created.</h2>
        <p>Please wait for your Teacher Portal access email.</p>
        <div class="status-flow">
            <span style="background:#F2F4F7;color:#667085;text-decoration:line-through;">Pending Account Setup</span>
            <i class="fas fa-arrow-right" style="color:#98A2B3;"></i>
            <span style="background:#EAF7F1;color:#238863;">Active</span>
        </div>
        <?php if ($done['portal_email']): ?>
            <p>Your account is ready. We sent <strong>"Welcome to Your EGradePro Teacher Portal"</strong> to <strong><?php echo htmlspecialchars($done['email']); ?></strong>. Open it and click <strong>Go to Teacher Portal</strong>.</p>
        <?php else: ?>
            <p>Your account is ready, but the access email could not be sent right now. You can sign in here with your email and the password you created:</p>
            <a class="btn btn-reg" href="<?php echo htmlspecialchars($done['portal_url']); ?>"><i class="fas fa-right-to-bracket me-2"></i>Go to Teacher Portal</a>
        <?php endif; ?>
        <p class="mt-3" style="font-size:15px;">The Teacher Portal is used on a desktop or laptop computer.</p>
    </div>

<?php elseif (!$invite): ?>
    <div class="state-card">
        <div class="icon" style="background:#FDECEC;color:#C23A3A;"><i class="fas fa-link-slash"></i></div>
        <h2>This registration link can't be used</h2>
        <p>It may have expired, already been used, or been replaced by a newer invitation. Please ask the school administrator to send you a new registration link.</p>
        <a class="btn btn-outline-secondary mt-2" href="teacher_login.php">Go to Teacher Login</a>
    </div>

<?php else: ?>
<div class="reg-wrap">
    <div class="reg-intro">
        <h1>Complete your Teacher Registration</h1>
        <p>Fill in your information and create your own password. Fields marked <span class="req">*</span> are required. Only you will know your password.</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger" style="font-size:15.5px;"><strong>Please fix the following:</strong>
            <ul class="mb-0 mt-1"><?php foreach ($errors as $er): ?><li><?php echo htmlspecialchars($er); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="teacher_register.php" id="regForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

        <div class="reg-card">
            <div class="reg-card-head"><i class="fas fa-user"></i><h5>Basic Information</h5></div>
            <div class="reg-card-body reg-grid">
                <div><label>First Name <span class="req">*</span></label><input class="form-control" name="first_name" required maxlength="100" value="<?php echo $val('first_name'); ?>"></div>
                <div><label>Middle Name</label><input class="form-control" name="middle_name" maxlength="100" value="<?php echo $val('middle_name'); ?>"></div>
                <div><label>Last Name <span class="req">*</span></label><input class="form-control" name="last_name" required maxlength="100" value="<?php echo $val('last_name'); ?>"></div>
                <div><label>Suffix</label><input class="form-control" name="suffix" maxlength="20" placeholder="Jr., Sr., III" value="<?php echo $val('suffix'); ?>"></div>
                <div><label>Sex / Gender <span class="req">*</span></label>
                    <select class="form-select" name="sex" required><option value="">-- Select --</option><?php foreach (TV_SEX_OPTIONS as $o): ?><option<?php echo $sel('sex', $o); ?>><?php echo $o; ?></option><?php endforeach; ?></select></div>
                <div><label>Contact Number <span class="req">*</span></label><input class="form-control" name="contact_number" required inputmode="numeric" maxlength="13" pattern="(09|\+639)[0-9]{9}" title="Mobile number, e.g. 09171234567" placeholder="09XXXXXXXXX" value="<?php echo $val('contact_number'); ?>"></div>
            </div>
        </div>

        <div class="reg-card">
            <div class="reg-card-head"><i class="fas fa-graduation-cap"></i><h5>Professional Information</h5></div>
            <div class="reg-card-body reg-grid">
                <div><label>Teacher ID / Employee ID <span class="req">*</span></label><input class="form-control" name="employee_id" required maxlength="50" pattern="[A-Za-z0-9\-]{3,50}" placeholder="e.g. 1234567" value="<?php echo $val('employee_id'); ?>"></div>
                <div><label>Position / Designation <span class="req">*</span></label><input class="form-control" name="position_designation" required maxlength="100" placeholder="e.g. Teacher I" value="<?php echo $val('position_designation'); ?>"></div>
                <div><label>Specialization / Subject Area <span class="req">*</span></label>
                    <select class="form-select" name="specialization" required><option value="">-- Select --</option><?php foreach (TV_SPECIALIZATIONS as $o): ?><option<?php echo $sel('specialization', $o); ?>><?php echo $o; ?></option><?php endforeach; ?></select></div>
                <div><label>Department / Level <span class="req">*</span></label>
                    <select class="form-select" name="department_level" required><option value="">-- Select --</option><option value="JHS"<?php echo $sel('department_level', 'JHS'); ?>>JHS (Junior High School)</option><option value="SHS"<?php echo $sel('department_level', 'SHS'); ?>>SHS (Senior High School)</option></select></div>
                <div><label>Employment Status <span class="req">*</span></label>
                    <select class="form-select" name="employment_status" required><option value="">-- Select --</option><?php foreach (TI_EMPLOYMENT_OPTIONS as $o): ?><option<?php echo $sel('employment_status', $o); ?>><?php echo $o; ?></option><?php endforeach; ?></select></div>
                <div><label>Highest Educational Attainment <span class="req">*</span></label>
                    <select class="form-select" name="highest_educ_attainment" required><option value="">-- Select --</option><?php foreach (TV_EDUC_OPTIONS as $o): ?><option value="<?php echo htmlspecialchars($o); ?>"<?php echo $sel('highest_educ_attainment', $o); ?>><?php echo htmlspecialchars($o); ?></option><?php endforeach; ?></select></div>
                <div><label>Degree / Course <span class="req">*</span></label><input class="form-control" name="degree_course" required maxlength="150" placeholder="e.g. Bachelor of Secondary Education" value="<?php echo $val('degree_course'); ?>"></div>
                <div><label>Years of Teaching Experience <span class="req">*</span></label><input class="form-control" type="number" name="years_experience" required min="0" max="50" value="<?php echo $val('years_experience', '0'); ?>"></div>
                <div><label>PRC License Number</label><input class="form-control" name="prc_license_number" inputmode="numeric" pattern="[0-9]{6,10}" title="6 to 10 digits" placeholder="License # (digits)" value="<?php echo $val('prc_license_number'); ?>"><div class="hint">Professional Teacher license, if you have one.</div></div>
            </div>
        </div>

        <div class="reg-card">
            <div class="reg-card-head"><i class="fas fa-lock"></i><h5>Private Account Information</h5></div>
            <div class="reg-card-body reg-grid">
                <div><label>Email</label><input class="form-control" value="<?php echo htmlspecialchars($invite['email']); ?>" readonly disabled><div class="hint">From your invitation. You will sign in with this email.</div></div>
                <div><label>Password <span class="req">*</span></label><input class="form-control" type="password" name="password" id="pw" required minlength="8" autocomplete="new-password"><div class="pw-meter"><div id="pwBar"></div></div><div class="hint">At least 8 characters, with letters and numbers.</div></div>
                <div><label>Confirm Password <span class="req">*</span></label><input class="form-control" type="password" name="confirm_password" id="pw2" required minlength="8" autocomplete="new-password"><div class="hint" id="pwMatch"></div></div>
            </div>
        </div>

        <div class="d-flex justify-content-end align-items-center gap-3">
            <span style="font-size:15px;color:#667085;"><i class="fas fa-shield-halved me-1"></i>The administrator can see your Basic and Professional Information, never your password.</span>
            <button type="submit" class="btn btn-reg"><i class="fas fa-user-check me-2"></i>Create My Teacher Account</button>
        </div>
    </form>
</div>
<script>
(function () {
    var pw = document.getElementById('pw'), pw2 = document.getElementById('pw2'), bar = document.getElementById('pwBar'), msg = document.getElementById('pwMatch');
    function update() {
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
    pw.addEventListener('input', update); pw2.addEventListener('input', update);
})();
</script>
<?php endif; ?>
</body>
</html>
