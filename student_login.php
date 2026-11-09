<?php
// student_login.php
// Student Portal Login: LRN + the student's own password (includes/student_password.php).
// The password is created from the one-time link in the "Enrollment Approved" email.
//
// Security notes:
//  - Every failed sign-in (LRN not enrolled, application not approved, no password
//    created yet, wrong password, inactive record) shows the SAME message and the
//    SAME help links, so the page cannot be used to find out which LRNs exist.
//    The real reason is written to the audit log (Admin > Audit Trail) only.
//  - Wrong tries are throttled per LRN + device, not by locking the student's
//    account, so a classmate who knows the LRN cannot lock the student out
//    (see spwLoginBlocked() in includes/student_password.php).
//  - When nothing exists to check, a dummy password check runs so the response
//    time does not reveal whether the LRN exists.

session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/enrollment_helpers_online.php'; // deleted_at column (Recycle Bin) used in the application lookup
require_once 'includes/student_password.php';          // LRN + password
require_once 'includes/parent_portal.php';             // Parent tab: LRN + parent name
spwEnsureSchema();
spwAttemptEnsureSchema();

// If already logged in as student, redirect to dashboard
if (isset($_SESSION['student_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'Student') {
    header('Location: modules/students/portal/dashboard.php');
    exit;
}
// Already signed in as a parent: go straight to the Parent Portal.
if (!empty($_SESSION['parent_id']) && ($_SESSION['role'] ?? '') === 'Parent') {
    header('Location: parent/index.php');
    exit;
}

$error = '';
$error_links = []; // optional list of [url, label] shown under the error
$csrf_token = generateCSRFToken();
// Which tab is active: 'student' (LRN + password) or 'parent' (LRN + parent/guardian name).
$tab = ($_POST['login_as'] ?? '') === 'parent' || ($_GET['as'] ?? '') === 'parent' ? 'parent' : 'student';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'parent') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid security token. Please refresh the page and try again.');
    }
    $p_lrn = preg_replace('/\s+/', '', sanitize($_POST['lrn'] ?? ''));
    $p_name = sanitize($_POST['parent_name'] ?? '');
    if (empty($p_lrn)) {
        $error = 'Please enter the student’s LRN.';
    } elseif (!preg_match('/^\d{12}$/', $p_lrn)) {
        $error = 'The LRN must be exactly 12 digits (numbers only).';
    } elseif (trim($p_name) === '') {
        $error = 'Please enter the Parent / Guardian name.';
    } elseif (isStudentIpRateLimited()) {
        $error = 'Too many attempts. Please try again in a few minutes.';
    } else {
        $res = ppLoginByLrnAndName($p_lrn, $p_name);
        if (!empty($res['ok'])) {
            ppStartSession($res['parent']);
            // The LRN decides which child this sign-in is for: even when brothers
            // or sisters share the same parent account, the portal opens on the
            // learner whose LRN was typed -- never on a sibling chosen earlier.
            $_SESSION['pp_child'] = (int) ($res['student_id'] ?? 0);
            header('Location: parent/index.php');
            exit;
        }
        logActivity(null, 'student', 'Failed login', 'Parent Portal (LRN login): ' . ($res['reason'] ?? 'no match') . ' for LRN ' . $p_lrn);
        $error = 'Wrong LRN or Parent / Guardian name. Please check the name on record for this learner. '
               . 'If you just enrolled, your application must be approved first.';
        $error_links = [['enrollment_status.php', 'Check enrollment status']];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid security token. Please refresh the page and try again.');
    }

    $lrn = preg_replace('/\s+/', '', sanitize($_POST['lrn'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (empty($lrn)) {
        $error = 'Please enter your LRN.';
    } elseif (!preg_match('/^\d{12}$/', $lrn)) {
        $error = 'Your LRN must be exactly 12 digits (numbers only).';
    } elseif ($password === '') {
        $error = 'Please enter your password.';
    } elseif (isStudentIpRateLimited() || spwLoginBlocked($lrn)) {
        $error = 'Too many attempts. Please try again in a few minutes.';
    } else {
        require_once __DIR__ . '/includes/student_records_helpers.php';
        srEnsureSchema();

        // Portal access: registrar-granted (walk-in / older records) OR an APPROVED
        // online application with this LRN.
        $walkin = getRecord("SELECT student_id FROM students WHERE lrn = ? AND is_active = 1 AND portal_access = 1", [$lrn]);
        $application = getRecord(
            "SELECT reference_no, status FROM online_applications WHERE lrn = ? AND deleted_at IS NULL
             ORDER BY FIELD(status, 'Approved', 'Under Review', 'Pending', 'Incomplete Requirements', 'Rejected'), application_id DESC LIMIT 1",
            [$lrn]
        );
        $eligible = $walkin || ($application && in_array($application['status'], ['Approved', 'Incomplete Requirements'], true));

        $student = null;
        if ($eligible) {
            $student = getRecord("
                SELECT s.*, e.enrollment_status, e.section_id, sec.section_name, sec.grade_level
                FROM students s
                LEFT JOIN enrollments e ON s.student_id = e.student_id
                    AND e.school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1)
                LEFT JOIN sections sec ON e.section_id = sec.section_id
                WHERE s.lrn = ? AND s.is_active = 1
            ", [$lrn]);
        }

        $hash = $student['portal_password_hash'] ?? '';
        if ($hash === '') {
            spwFakeVerify($password);              // same time cost as a real check
            $password_ok = false;
        } else {
            $password_ok = password_verify($password, $hash);
        }

        if ($student && $password_ok) {
            spwLoginSucceeded($lrn, (int) $student['student_id']);
            session_regenerate_id(true);
            // Set student session
            $_SESSION['student_id'] = $student['student_id'];
            $_SESSION['student_lrn'] = $student['lrn'];
            $_SESSION['student_name'] = $student['first_name'] . ' ' . $student['last_name'];
            $_SESSION['student_grade'] = $student['grade_level'];
            $_SESSION['student_section'] = $student['section_name'] ?? 'Not Assigned';
            $_SESSION['enrollment_status'] = $student['enrollment_status'] ?? 'Not Enrolled';
            $_SESSION['role'] = 'Student';
            $_SESSION['is_student_portal'] = true;
            $_SESSION['last_activity'] = time();

            // Log the login
            logActivity(null, 'student', 'Portal login', "Student $lrn logged in");

            // First login with the temporary password issued on approval: the
            // authentication succeeded, but the student may not use the portal
            // until the password has been changed. No other page is reachable
            // in the meantime (see modules/students/portal/dashboard.php).
            if (spwMustChangePassword($student)) {
                header('Location: student/change_password.php');
                exit;
            }

            // One-time flash — shown once on the dashboard right after the
            // loading screen fades, then cleared (see dashboard.php +
            // static/js/dashboard-animations.js). A later refresh of the
            // dashboard will not show it again.
            $_SESSION['login_success_message'] = 'Successful Login! Welcome to the Student Portal.';

            // Redirect to student dashboard
            header('Location: modules/students/portal/dashboard.php');
            exit;
        }

        // Failed. The real reason goes to the audit log only, never to the screen.
        if (!$walkin && !$application) {
            $why = 'LRN not found in Online Enrollment';
        } elseif (!$eligible) {
            $why = 'application not approved (' . $application['status'] . ')';
        } elseif (!$student) {
            $why = 'approved but no active student record';
        } elseif ($hash === '') {
            $why = 'password not created yet';
        } else {
            $why = 'wrong password';
        }
        logActivity(null, 'student', 'Failed login', "LRN $lrn: $why");   // also feeds isStudentIpRateLimited()
        spwLoginFailed($lrn);

        // One message for every failure, with the same help links for everyone.
        $error = 'Wrong LRN or password. First time signing in, or forgot your password? Use the links below. '
               . 'If you just enrolled, your application must be approved first.';
        $error_links = [
            ['student/forgot_password.php', 'Create / Forgot password'],
            ['enrollment_status.php', 'Check enrollment status'],
        ];
    }
}
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
    <title>Student Portal - EGRADEPRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="static/css/theme.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', ;
            background-image: url('static/images/bg_logo.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        .login-container {
            max-width: 380px;
            width: 100%;
        }
        .login-card {
            background: rgba(0, 0, 0, 0.35);
            -webkit-backdrop-filter: blur(14px) saturate(150%);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 18px;
            padding: 22px 22px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.45);
            animation: slideUp 0.4s ease;
        }
        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-header .logo-img {
            max-width: 78px;
            height: auto;
            margin-bottom: 10px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        .login-header h3 {
            color: #ffffff;
            font-weight: 700;
            margin: 0;
            font-size: 18px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.7);
        }
        .login-header p {
            color: #ffffff;
            font-size: 14.5px;
            margin-top: 4px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.7);
        }
        .form-label {
            color: #ffffff;
            text-shadow: 0 2px 8px rgba(0,0,0,0.8);
            font-weight: 600;
        }
        .form-control {
            border-radius: 10px;
            padding: 12px 16px;
            border: 2px solid rgba(224, 224, 224, 0.8);
            background: rgba(255, 255, 255, 0.7);
            transition: all 0.3s;
            font-size: 16px;
            text-align: left;
            letter-spacing: normal;
            color: #1a1a2e;
            height: 50px;
        }
        #lrnInput { letter-spacing: 1.5px; }
        /* Edge / IE draw their own eye button inside password boxes: hide it (we have one). */
        input[type="password"]::-ms-reveal, input[type="password"]::-ms-clear { display: none; }
        .field { margin-bottom: 14px; }
        .field .form-label { display: block; margin-bottom: 5px; font-size: 15px; }
        .input-group-text { width: 52px; justify-content: center; }
        .input-group .pw-toggle {
            width: 52px; justify-content: center; cursor: pointer;
            border: 2px solid rgba(224, 224, 224, 0.8); border-left: none;
            border-radius: 0 10px 10px 0; background: rgba(245, 245, 245, 0.8); color: #1a1a2e;
        }
        .input-group .pw-toggle:hover { background: #fff; }
        .input-group #pwInput { border-radius: 0; }
        .field-note { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-top: 5px; min-height: 16px; }
        .forgot-link { color: #fff; font-size: 15px; font-weight: 600; text-decoration: underline; text-shadow: 0 2px 6px rgba(0,0,0,0.6); white-space: nowrap; }
        .forgot-link:hover { color: #90caf9; }
        .form-control:focus {
            border-color: #1565c0;
            box-shadow: 0 0 0 0.25rem rgba(21, 101, 192, 0.1);
            background: rgba(255, 255, 255, 0.85);
        }
        .btn-login {
            background: #1565c0;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 13px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s;
            font-size: 15px;
        }
        .btn-login:hover {
            background: #0d47a1;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(21, 101, 192, 0.3);
            color: #ffffff;
        }
        .input-group-text {
            background: rgba(245, 245, 245, 0.8);
            border: 2px solid rgba(224, 224, 224, 0.8);
            border-right: none;
            border-radius: 10px 0 0 10px;
            font-size: 17px;
            color: #1a1a2e;
        }
        .input-group .form-control {
            border-radius: 0 10px 10px 0;
        }
        .alert {
            border-radius: 10px;
        }
        .alert-danger {
            background: rgba(220, 53, 69, 0.85);
            color: #ffffff;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .login-error-link { color: #fff; font-weight: 700; text-decoration: underline; }
        .login-error-link:hover { color: #fff; }
        .lrn-help { font-size: 13.5px; color: rgba(255,255,255,0.85); margin-top: 6px; }
        .lrn-help.is-bad { color: #ffd166; font-weight: 600; }
        .alert .btn-close {
            filter: brightness(0) invert(1);
        }
        .info-text {
            font-size: 14px;
            color: #ffffff;
            text-align: center;
            margin-top: 14px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.7);
        }
        .info-text i {
            color: #90caf9;
        }
        .apply-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 16px;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1.5px solid rgba(255, 255, 255, 0.4);
            background: rgba(0, 0, 0, 0.3);
            color: #ffffff;
            font-weight: 600;
            font-size: 15px;
            text-decoration: none;
            transition: all 0.25s;
            text-shadow: 0 2px 6px rgba(0,0,0,0.6);
        }
        .apply-link:hover {
            background: rgba(21, 101, 192, 0.3);
            border-color: rgba(255, 255, 255, 0.7);
            color: #ffffff;
            transform: translateY(-1px);
        }
        .apply-link i {
            color: #ffffff;
        }
        .back-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 10px;
            color: #ffffff;
            text-decoration: none;
            font-size: 14.5px;
            font-weight: 600;
            text-shadow: 0 2px 6px rgba(0,0,0,0.6);
        }
        .back-link:hover {
            color: #90caf9;
            text-decoration: underline;
        }
        .back-link i {
            color: #ffffff;
        }
        .back-link:hover i {
            color: #90caf9;
        }
        .text-muted {
            color: rgba(255, 255, 255, 0.7) !important;
        }
        hr {
            border-color: rgba(255,255,255,0.2);
        }
        /* Student / Parent selector */
        .login-tabs {
            display: flex;
            gap: 6px;
            padding: 5px;
            margin-bottom: 20px;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 12px;
        }
        .login-tab {
            flex: 1;
            border: none;
            background: transparent;
            color: rgba(255, 255, 255, 0.85);
            font-weight: 600;
            font-size: 15px;
            padding: 10px 8px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.25s;
            text-shadow: 0 2px 6px rgba(0,0,0,0.6);
        }
        .login-tab:hover { color: #ffffff; background: rgba(255, 255, 255, 0.1); }
        .login-tab.on {
            background: #1565c0;
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(21, 101, 192, 0.4);
        }
        .login-pane[hidden] { display: none; }
    </style>
    <?php include __DIR__ . '/includes/pwa_head.php'; ?>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <img src="static/images/logo.png" alt="Imelda Integrated Secondary School" class="logo-img">
                <h3 id="loginTitle"><?php echo $tab === 'parent' ? 'Parent Portal' : 'Student Portal'; ?></h3>
                <p id="loginSub"><?php echo $tab === 'parent' ? 'Sign in with your child’s LRN and your name' : 'Sign in with your LRN and password'; ?></p>
            </div>

            <div class="login-tabs" role="tablist" aria-label="Choose portal">
                <button type="button" class="login-tab<?php echo $tab === 'student' ? ' on' : ''; ?>" data-tab="student" role="tab" aria-selected="<?php echo $tab === 'student' ? 'true' : 'false'; ?>">
                    <i class="fas fa-user-graduate me-1"></i>Student
                </button>
                <button type="button" class="login-tab<?php echo $tab === 'parent' ? ' on' : ''; ?>" data-tab="parent" role="tab" aria-selected="<?php echo $tab === 'parent' ? 'true' : 'false'; ?>">
                    <i class="fas fa-people-roof me-1"></i>Parent
                </button>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <?php foreach ($error_links as $el): ?>
                        <div class="mt-2"><a class="login-error-link" href="<?php echo htmlspecialchars($el[0]); ?>"><?php echo htmlspecialchars($el[1]); ?> <i class="fas fa-arrow-right ms-1"></i></a></div>
                    <?php endforeach; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="studentForm" class="login-pane"<?php echo $tab === 'parent' ? ' hidden' : ''; ?>>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="login_as" value="student">
                <div class="field">
                    <label class="form-label fw-semibold" for="lrnInput">LRN</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                        <input type="text" name="lrn" class="form-control" id="lrnInput"
                               placeholder="Enter your 12-digit LRN"
                               inputmode="numeric" pattern="\d{12}" minlength="12" maxlength="12"
                               title="Enter your 12-digit LRN (numbers only)"
                               value="<?php echo htmlspecialchars(preg_replace('/\D/', '', (string) ($_POST['lrn'] ?? ''))); ?>"
                               required autofocus autocomplete="username">
                    </div>
                    <div class="field-note"><div class="lrn-help" id="lrnHelp" style="margin-top:0;">Use the same LRN you entered in Online Enrollment.</div></div>
                </div>
                <div class="field">
                    <label class="form-label fw-semibold" for="pwInput">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" name="password" class="form-control" id="pwInput" placeholder="Enter your password" required autocomplete="current-password">
                        <button type="button" class="input-group-text pw-toggle" id="pwToggle" aria-label="Show password"><i class="fas fa-eye"></i></button>
                    </div>
                    <div class="field-note" style="justify-content:flex-end;">
                        <a href="student/forgot_password.php" class="forgot-link"><i class="fas fa-key me-1"></i>Forgot password?</a>
                    </div>
                </div>

                <button type="submit" class="btn btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i> Sign In
                </button>
            </form>

            <form method="POST" action="" id="parentForm" class="login-pane"<?php echo $tab === 'student' ? ' hidden' : ''; ?>>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="login_as" value="parent">
                <div class="field">
                    <label class="form-label fw-semibold" for="pLrnInput">Student's LRN</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                        <input type="text" name="lrn" class="form-control" id="pLrnInput"
                               placeholder="Enter your child's 12-digit LRN"
                               inputmode="numeric" pattern="\d{12}" minlength="12" maxlength="12"
                               title="Enter the student's 12-digit LRN (numbers only)"
                               value="<?php echo htmlspecialchars(preg_replace('/\D/', '', (string) ($_POST['lrn'] ?? ''))); ?>"
                               required autocomplete="off">
                    </div>
                    <div class="field-note"><div class="lrn-help" id="pLrnHelp" style="margin-top:0;">The LRN your child used in Online Enrollment.</div></div>
                </div>
                <div class="field">
                    <label class="form-label fw-semibold" for="parentNameInput">Parent / Guardian Name</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" name="parent_name" class="form-control" id="parentNameInput"
                               placeholder="Full name of the parent / guardian"
                               value="<?php echo htmlspecialchars((string) ($_POST['parent_name'] ?? '')); ?>"
                               required autocomplete="name">
                    </div>
                    <div class="field-note"><div class="lrn-help" style="margin-top:0;">The Parent / Guardian name on record for this learner.</div></div>
                </div>
                <button type="submit" class="btn btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i> Sign In as Parent
                </button>
            </form>

            <hr class="my-4">

            <div class="text-center text-muted small">
                <p>© <?php echo date('Y'); ?> EGRADEPRO. All rights reserved.</p>
                <p class="mb-0">Imelda Integrated Secondary School</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="static/js/theme.js"></script>
    <script>
    (function () {
        var b = document.getElementById('pwToggle'), i = document.getElementById('pwInput');
        if (b && i) b.addEventListener('click', function () {
            i.type = i.type === 'password' ? 'text' : 'password';
            b.innerHTML = '<i class="fas fa-eye' + (i.type === 'password' ? '' : '-slash') + '"></i>';
        });
    })();
    // Student / Parent tabs: show one form at a time and update the header text.
    (function () {
        var tabs = document.querySelectorAll('.login-tab');
        var panes = { student: document.getElementById('studentForm'), parent: document.getElementById('parentForm') };
        var title = document.getElementById('loginTitle'), sub = document.getElementById('loginSub');
        var COPY = {
            student: { t: 'Student Portal', s: 'Sign in with your LRN and password' },
            parent:  { t: 'Parent Portal',  s: 'Sign in with your child’s LRN and your name' }
        };
        function show(which) {
            tabs.forEach(function (t) {
                var on = t.getAttribute('data-tab') === which;
                t.classList.toggle('on', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            Object.keys(panes).forEach(function (k) { if (panes[k]) panes[k].hidden = k !== which; });
            if (title && COPY[which]) title.textContent = COPY[which].t;
            if (sub && COPY[which]) sub.textContent = COPY[which].s;
        }
        tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-tab')); }); });
    })();
    // Digits only, exactly 12 -- the server checks the same rule.
    (function () {
        var input = document.getElementById('lrnInput'), help = document.getElementById('lrnHelp');
        if (!input) return;
        input.addEventListener('input', function () {
            var digits = input.value.replace(/\D/g, '').slice(0, 12);
            if (digits !== input.value) input.value = digits;
            help.textContent = digits.length === 0 || digits.length === 12
                ? 'Use the same LRN you entered in Online Enrollment.'
                : digits.length + ' of 12 digits';
            help.classList.toggle('is-bad', digits.length > 0 && digits.length < 12);
        });
    })();
    // Parent tab: the child's LRN, digits only, exactly 12.
    (function () {
        var input = document.getElementById('pLrnInput'), help = document.getElementById('pLrnHelp');
        if (!input) return;
        input.addEventListener('input', function () {
            var digits = input.value.replace(/\D/g, '').slice(0, 12);
            if (digits !== input.value) input.value = digits;
            help.textContent = digits.length === 0 || digits.length === 12
                ? 'The LRN your child used in Online Enrollment.'
                : digits.length + ' of 12 digits';
            help.classList.toggle('is-bad', digits.length > 0 && digits.length < 12);
        });
    })();
    </script>
</body>
</html>