<?php
// login.php
// Administrator / Registrar login page.
// Teachers now have their own dedicated page (teacher_login.php), same
// pattern as the Student Portal — no more cosmetic role toggle here.

require_once 'config/database.php';
require_once 'includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

$error = '';
$success = '';
$login_role = $_POST['login_role'] ?? 'Administrator';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid security token. Please refresh the page and try again.');
    }

    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $login_role = sanitize($_POST['login_role'] ?? 'Administrator');

    if (!in_array($login_role, ['Administrator', 'Enrollment Officer'], true)) {
        $error = 'Please select a valid portal.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Please enter username and password.';
    } else {
        $user = getUserByUsername($username);

        if ($user && isAccountLocked($user)) {
            $mins = lockoutMinutesRemaining($user['locked_until']);
            $error = "Too many failed attempts. Please try again in $mins minute(s).";
        } elseif ($user && verifyPassword($password, $user['password_hash'])) {
            if ($user['is_active'] != 1) {
                $error = 'Your account has been deactivated. Please contact the administrator.';
            } elseif ($user['role_name'] !== $login_role) {
                $error = 'This login is for ' . htmlspecialchars($login_role) . ' accounts only.';
            } else {
                resetFailedLogin($user['user_id']);

                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role_name'];
                $_SESSION['role_id'] = $user['role_id'];
                $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];

                updateLastLogin($user['user_id']);
                logActivity($user['user_id'], 'auth', 'Login', 'User logged in');

                // One-time flash — shown once on the dashboard right after
                // the loading screen fades, then cleared (see
                // includes/header.php + static/js/dashboard-animations.js).
                // A later refresh of the dashboard will not show it again.
                $_SESSION['login_success_message'] = 'Successful Login! Welcome to the ' . $user['role_name'] . ' Portal.';

                header('Location: index.php');
                exit;
            }
        } else {
            if ($user) {
                registerFailedLogin($user['user_id']);
            }
            $error = 'Invalid username or password.';
            logActivity(null, 'auth', 'Failed login', 'Failed login attempt for: ' . $username);
        }
    }
}

$csrf_token = generateCSRFToken();
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
    <title>Admin Login - Imelda Integrated Secondary School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="static/css/style.css" rel="stylesheet">
    <link href="static/css/theme.css" rel="stylesheet">
    <style>
        html, body {
            height: 100%;
        }
        body {
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
            padding: 16px;
        }

        .login-container {
            max-width: 360px;
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
            from { transform: translateY(24px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .login-header {
            text-align: center;
            margin-bottom: 14px;
        }

        .login-header .logo-img {
            max-width: 60px;
            height: auto;
            margin-bottom: 6px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }

        .login-header h3 {
            color: #ffffff;
            font-weight: 800;
            margin: 0;
            font-size: 17px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.7);
        }

        .login-header p {
            color: #ffffff;
            font-size: 13.5px;
            margin-top: 2px;
            font-weight: 600;
            text-shadow: 0 2px 8px rgba(0,0,0,0.7);
        }

        .role-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(21, 101, 192, 0.85);
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            margin-bottom: 14px;
            text-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }

        .form-label {
            font-size: 14.5px;
            margin-bottom: 4px;
            font-weight: 700;
            color: #ffffff;
            text-shadow: 0 2px 8px rgba(0,0,0,0.8);
        }

        .form-control {
            border-radius: 9px;
            padding: 9px 12px;
            border: 1.5px solid rgba(255, 255, 255, 0.6);
            background: rgba(255, 255, 255, 0.7);
            font-size: 15px;
            color: #1a1a2e;
        }

        .form-control::placeholder {
            color: #555;
        }

        .form-control:focus {
            border-color: #1565c0;
            background: rgba(255, 255, 255, 0.85);
            box-shadow: 0 0 0 0.2rem rgba(21, 101, 192, 0.15);
        }

        .btn-login {
            background: #1565c0;
            color: #ffffff;
            border: none;
            border-radius: 9px;
            padding: 10px;
            font-weight: 700;
            font-size: 15px;
            width: 100%;
            transition: all 0.25s;
        }

        .btn-login:hover {
            background: #0d47a1;
            box-shadow: 0 5px 18px rgba(21, 101, 192, 0.35);
            color: #ffffff;
        }

        .input-group-text {
            background: rgba(255, 255, 255, 0.7);
            border: 1.5px solid rgba(255, 255, 255, 0.6);
            border-right: none;
            border-radius: 9px 0 0 9px;
            color: #1a1a2e;
        }

        .input-group .form-control {
            border-radius: 0 9px 9px 0;
        }

        .alert {
            border-radius: 9px;
            font-size: 14.5px;
            padding: 10px 12px;
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.85);
            color: #ffffff;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.85);
            color: #ffffff;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .alert .btn-close {
            filter: brightness(0) invert(1);
        }

        .portal-links {
            display: flex;
            flex-direction: column;
            gap: 7px;
            margin: 12px 0 10px 0;
        }

        .portal-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 8px 12px;
            border-radius: 9px;
            border: 1.5px solid rgba(255, 255, 255, 0.4);
            color: #ffffff;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            background: rgba(0, 0, 0, 0.3);
            transition: all 0.2s;
            text-shadow: 0 2px 6px rgba(0,0,0,0.6);
        }

        .portal-link:hover {
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.7);
            transform: translateY(-1px);
        }

        .portal-link i {
            color: #ffffff;
        }

        .footer-text {
            color: #ffffff;
            font-size: 12.5px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.7);
        }

        hr {
            margin: 12px 0;
            border-color: rgba(255,255,255,0.2);
        }

        /* Forgot password link - NO UNDERLINE */
        .forgot-link {
            color: #ffffff !important;
            text-shadow: 0 2px 6px rgba(0,0,0,0.7);
            text-decoration: none !important;
            font-weight: 700;
            font-size: 13.5px;
            transition: all 0.2s;
        }

        .forgot-link:hover {
            color: #90caf9 !important;
            text-decoration: none !important;
        }

        .login-header h2 {
            color: #ffffff;
            font-weight: 800;
            margin: 0;
            font-size: 20px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.7);
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <img src="static/images/logo.png" alt="School Logo" class="logo-img">
                <h2>EGRADEPRO</h2>
                <p>AI-Assisted Grading &amp; Enrollment</p>
            </div>

            <div class="text-center">
                <select name="login_role" form="loginForm" class="role-pill" aria-label="Select login portal" style="border:0;cursor:pointer;">
                    <option value="Administrator" <?php echo $login_role === 'Administrator' ? 'selected' : ''; ?>>Administrator Login</option>
                    <option value="Enrollment Officer" <?php echo $login_role === 'Enrollment Officer' ? 'selected' : ''; ?>>Enrollment Officer</option>
                </select>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-1"></i>
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-1"></i>
                    <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                <div class="mb-2">
                    <label class="form-label">Username or Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" name="username" class="form-control" placeholder="Enter username or email"
                            required autofocus>
                    </div>
                </div>

                <div class="mb-1">
                    <label class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" name="password" class="form-control" placeholder="Enter password"
                            required>
                    </div>
                </div>

                <div class="mb-3 text-end">
                    <a href="forgot_password.php" class="forgot-link">
                        Forgot password?
                    </a>
                </div>

                <button type="submit" class="btn btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i> Sign In
                </button>
            </form>

            <div class="text-center footer-text">
                <p class="mb-0">© <?php echo date('Y'); ?> Imelda Integrated Secondary School</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="static/js/theme.js"></script>
</body>

</html>