<?php
// reset_password.php
// Step 2 of password reset: user arrives here via the emailed link with
// ?token=..., picks a new password.

require_once 'config/database.php';
require_once 'includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$token = sanitize($_GET['token'] ?? $_POST['token'] ?? '');
$csrf_token = generateCSRFToken();

// Validate the token up front so we can hide the form if it's bad/expired.
$user = null;
if (!empty($token)) {
    $user = getRecord(
        "SELECT * FROM users WHERE reset_token = ? AND reset_token_expires > NOW()",
        [$token]
    );
}
$token_valid = $user !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid security token. Please refresh the page and try again.');
    }

    if (!$token_valid) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } else {
            $new_hash = hashPassword($password);
            executeQuery(
                "UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL, failed_attempts = 0, locked_until = NULL WHERE user_id = ?",
                [$new_hash, $user['user_id']]
            );
            logActivity($user['user_id'], 'Password Reset Completed', 'auth', 'Password changed via reset link');

            $success = 'Your password has been changed. You can now sign in with your new password.';
            $token_valid = false; // hide the form after a successful reset
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
    <title>Reset Password - EGRADEPRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="static/css/style.css" rel="stylesheet">
    <link href="static/css/theme.css" rel="stylesheet">
    <style>
        html, body {
            height: 100%;
        }
        body {
            background-image: url('static/images/bg_logo.png');
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
            max-width: 420px;
            width: 100%;
        }
        .login-card {
            background: rgba(0, 0, 0, 0.35);
            -webkit-backdrop-filter: blur(14px) saturate(150%);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 18px;
            padding: 35px 30px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.45);
            animation: slideUp 0.4s ease;
        }
        @keyframes slideUp {
            from { transform: translateY(24px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .login-header {
            text-align: center;
            margin-bottom: 22px;
        }
        .login-header .logo-img {
            max-width: 75px;
            height: auto;
            margin-bottom: 12px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }
        .login-header h3 {
            color: #ffffff;
            font-weight: 700;
            margin: 0;
            font-size: 22px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.7);
        }
        .login-header p {
            color: #ffffff;
            font-size: 15px;
            margin-top: 5px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.7);
        }

        .form-label {
            color: #ffffff;
            text-shadow: 0 2px 8px rgba(0,0,0,0.8);
            font-weight: 600;
        }

        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
            border: 2px solid rgba(255, 255, 255, 0.5);
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
            box-shadow: 0 0 0 0.25rem rgba(21, 101, 192, 0.15);
        }

        .input-group-text {
            background: rgba(255, 255, 255, 0.7);
            border: 2px solid rgba(255, 255, 255, 0.5);
            border-right: none;
            border-radius: 10px 0 0 10px;
            color: #1a1a2e;
        }
        .input-group .form-control {
            border-radius: 0 10px 10px 0;
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
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(21, 101, 192, 0.3);
        }

        .btn-warning-custom {
            background: #ff9800;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 13px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s;
            font-size: 15px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .btn-warning-custom:hover {
            background: #f57c00;
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(255, 152, 0, 0.3);
        }

        .alert {
            border-radius: 10px;
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
        .alert-warning {
            background: rgba(255, 193, 7, 0.85);
            color: #ffffff;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .alert .btn-close {
            filter: brightness(0) invert(1);
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 18px;
            color: #ffffff;
            text-decoration: none !important;
            font-weight: 600;
            font-size: 15px;
            text-shadow: 0 2px 6px rgba(0,0,0,0.6);
            transition: all 0.2s;
        }
        .back-link:hover {
            color: #90caf9;
            text-decoration: none !important;
        }
        .back-link i {
            color: #ffffff;
        }
        .back-link:hover i {
            color: #90caf9;
        }

        hr {
            border-color: rgba(255,255,255,0.15);
            margin: 18px 0;
        }

        .footer-text {
            color: #ffffff;
            font-size: 12.5px;
            text-align: center;
            text-shadow: 0 2px 8px rgba(0,0,0,0.7);
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <button type="button" class="theme-toggle-btn theme-toggle-btn-onbrand" id="themeToggleBtn"
        style="position:fixed;top:16px;right:16px;z-index:500;"
        aria-label="Switch to dark mode" title="Switch to dark mode" aria-pressed="false">
        <i class="fas fa-sun theme-toggle-icon-sun" aria-hidden="true"></i>
        <i class="fas fa-moon theme-toggle-icon-moon" aria-hidden="true"></i>
    </button>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <img src="static/images/logo.png" alt="Logo" class="logo-img">
                <h3>Reset Password</h3>
                <p>Choose a new password for your account</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <div style="margin-top: 15px;">
                    <a href="login.php" class="btn btn-login">
                        <i class="fas fa-sign-in-alt me-2"></i> Go to Login
                    </a>
                </div>
            <?php endif; ?>

            <?php if (!$token_valid && !$success): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    This reset link is invalid or has expired.
                </div>
                <a href="forgot_password.php" class="btn-warning-custom">
                    <i class="fas fa-redo me-2"></i> Request a New Link
                </a>
            <?php elseif ($token_valid): ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <div class="mb-3">
                    <label class="form-label fw-semibold">New Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" name="password" class="form-control" placeholder="At least 8 characters" minlength="8" required autofocus>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Confirm New Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter new password" minlength="8" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-login">
                    <i class="fas fa-check me-2"></i> Reset Password
                </button>
            </form>
            <?php endif; ?>

            <?php if (!$success): ?>
            <a href="login.php" class="back-link">
                <i class="fas fa-arrow-left me-1"></i> Back to Login
            </a>
            <?php endif; ?>

            <hr>

            <div class="footer-text">
                <p class="mb-0">© <?php echo date('Y'); ?> Imelda Integrated Secondary School</p>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="static/js/theme.js"></script>
</body>
</html>