<?php
// student_login.php
// Student Portal Login: LRN + the student's own password (includes/student_password.php).
// The password is created from the one-time link in the "Enrollment Approved" email.

session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/enrollment_helpers_online.php'; // deleted_at column (Recycle Bin) used in the application status message
require_once 'includes/student_password.php';          // LRN + password
spwEnsureSchema();

// If already logged in as student, redirect to dashboard
if (isset($_SESSION['student_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'Student') {
    header('Location: modules/students/portal/dashboard.php');
    exit;
}

$error = '';
$error_link = null; // optional [url, label] shown under the error
$csrf_token = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid security token. Please refresh the page and try again.');
    }

    $lrn = preg_replace('/\s+/', '', sanitize($_POST['lrn'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // The LRN must be the same one the learner entered under Personal
    // Information in Online Enrollment -- no online application with this
    // LRN means no portal access (and it must be approved first).
    $application = null;
    $walkin = null; // portal access given by the registrar (walk-in / older records, no online application)
    if ($lrn !== '' && preg_match('/^\d{12}$/', $lrn)) {
        require_once __DIR__ . '/includes/student_records_helpers.php';
        srEnsureSchema();
        $walkin = getRecord("SELECT student_id FROM students WHERE lrn = ? AND is_active = 1 AND portal_access = 1", [$lrn]);
        $application = getRecord(
            "SELECT reference_no, status FROM online_applications WHERE lrn = ? AND deleted_at IS NULL
             ORDER BY FIELD(status, 'Approved', 'Under Review', 'Pending', 'Incomplete Requirements', 'Rejected'), application_id DESC LIMIT 1",
            [$lrn]
        );
    }

    if (empty($lrn)) {
        $error = 'Please enter your LRN.';
    } elseif (!preg_match('/^\d{12}$/', $lrn)) {
        $error = 'Your LRN must be exactly 12 digits (numbers only).';
    } elseif ($password === '') {
        $error = 'Please enter your password.';
    } elseif (isStudentIpRateLimited()) {
        $error = 'Too many attempts from this device. Please try again in a few minutes.';
    } elseif (!$walkin && !$application) {
        logActivity(null, 'student', 'Failed login', "LRN not found in Online Enrollment: $lrn");
        $error = 'This LRN is not registered in Online Enrollment. Please enroll first using the same LRN you will use to sign in.';
        $error_link = ['online_enrollment.php', 'Go to Online Enrollment'];
    } elseif (!$walkin && $application['status'] !== 'Approved') {
        logActivity(null, 'student', 'Failed login', "LRN $lrn application not approved ({$application['status']})");
        $error = in_array($application['status'], ['Incomplete Requirements', 'Rejected', 'For Correction'], true)
            ? 'Your enrollment application (Ref. ' . $application['reference_no'] . ') has Incomplete Requirements. Submit the missing requirement on your Enrollment Tracking page, then you can sign in once it is approved.'
            : 'Your enrollment application (Ref. ' . $application['reference_no'] . ') is still ' . $application['status'] . '. You can sign in once it is approved.';
        $error_link = ['enrollment_status.php', 'Check enrollment status'];
    } else {
        // Check if student exists with this LRN
        $student = getRecord("
            SELECT s.*, e.enrollment_status, e.section_id, sec.section_name, sec.grade_level
            FROM students s
            LEFT JOIN enrollments e ON s.student_id = e.student_id 
                AND e.school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1)
            LEFT JOIN sections sec ON e.section_id = sec.section_id
            WHERE s.lrn = ? AND s.is_active = 1
        ", [$lrn]);
        
        if ($student && empty($student['portal_password_hash'])) {
            // Approved, but no password yet: it is created from the emailed link.
            $error = 'You have not created your password yet. Open the "Create My Password" link in your Enrollment Approved email, or get a new link below.';
            $error_link = ['student/forgot_password.php', 'Create / Forgot password'];
        } elseif ($student && ($mins = spwLockedMinutes($student)) > 0) {
            $error = "Too many wrong passwords. Please try again in $mins minute(s), or reset your password.";
            $error_link = ['student/forgot_password.php', 'Create / Forgot password'];
        } elseif ($student && !spwVerify($student, $password)) {
            logActivity(null, 'student', 'Failed login', "Wrong password for LRN $lrn");
            $error = 'Wrong LRN or password.';
            $error_link = ['student/forgot_password.php', 'Forgot your password?'];
        } elseif ($student) {
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
            
            // Log the login
            logActivity(null, 'student', 'Portal login', "Student $lrn logged in");

            // One-time flash — shown once on the dashboard right after the
            // loading screen fades, then cleared (see dashboard.php +
            // static/js/dashboard-animations.js). A later refresh of the
            // dashboard will not show it again.
            $_SESSION['login_success_message'] = 'Successful Login! Welcome to the Student Portal.';

            // Redirect to student dashboard
            header('Location: modules/students/portal/dashboard.php');
            exit;
        } else {
            logActivity(null, 'student', 'Failed login', "Approved application but no active student record: $lrn");
            $error = 'Your enrollment was approved, but your student account is not active yet. Please contact the registrar.';
        }
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
    </style>
    <?php include __DIR__ . '/includes/pwa_head.php'; ?>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <img src="static/images/logo.png" alt="Imelda Integrated Secondary School" class="logo-img">
                <h3>Student Portal</h3>
                <p>Sign in with your LRN and password</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <?php if ($error_link): ?>
                        <div class="mt-2"><a class="login-error-link" href="<?php echo htmlspecialchars($error_link[0]); ?>"><?php echo htmlspecialchars($error_link[1]); ?> <i class="fas fa-arrow-right ms-1"></i></a></div>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
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
    </script>
</body>
</html>