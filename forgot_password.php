<?php
// forgot_password.php
// Step 1 of password reset: user enters their email, we send a reset link.
// Admin / Registrar / Teacher accounts only (the `users` table).

require_once 'config/database.php';
require_once 'config/email.php';
require_once 'includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

// Already logged in? No need to reset.
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$csrf_token = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid security token. Please refresh the page and try again.');
    }

    $email = sanitize($_POST['email'] ?? '');

    if (empty($email) || !validateEmail($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        $user = getRecord("SELECT * FROM users WHERE email = ? AND is_active = 1", [$email]);

        // Always show the same success message whether or not the email
        // exists — this stops someone from using this form to discover
        // which emails are registered in the system.
        $success = 'If that email is registered, a password reset link has been sent. Please check your inbox (and spam folder).';

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            executeQuery(
                "UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE user_id = ?",
                [$token, $expires, $user['user_id']]
            );

            $reset_link = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']
                . dirname($_SERVER['PHP_SELF']) . '/reset_password.php?token=' . $token;

            $subject = "🔑 Password Reset - EGRADEPRO";
            $body = "
            <html><body style='font-family: Arial, sans-serif; color: #333;'>
                <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                    <div style='background: #1565c0; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0;'>
                        <h1>🎓 EGRADEPRO</h1>
                    </div>
                    <div style='background: #f5f5f5; padding: 30px; border-radius: 0 0 10px 10px;'>
                        <p>Dear <strong>" . htmlspecialchars($user['first_name']) . "</strong>,</p>
                        <p>We received a request to reset your password. Click the button below to choose a new one. This link expires in <strong>1 hour</strong>.</p>
                        <p style='text-align: center; margin: 30px 0;'>
                            <a href='" . $reset_link . "' style='background: #1565c0; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;'>Reset My Password</a>
                        </p>
                        <p style='font-size: 14.5px; color: #777;'>Or copy this link into your browser:<br><code>" . $reset_link . "</code></p>
                        <p style='font-size: 14.5px; color: #777;'>If you did not request this, you can safely ignore this email — your password will not change.</p>
                    </div>
                    <div style='text-align: center; font-size: 13.5px; color: #999; margin-top: 20px;'>
                        <p>This is an automated message. Please do not reply to this email.</p>
                    </div>
                </div>
            </body></html>
            ";

            sendEmail($email, $subject, $body);
            logActivity($user['user_id'], 'Password Reset Requested', 'auth', 'Reset link emailed');
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
    <title>Forgot Password - EGRADEPRO</title>
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

        /* When success message is shown, hide the form but keep the back link */
        .success-message-only .login-header {
            margin-bottom: 10px;
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
        <div class="login-card <?php echo $success ? 'success-message-only' : ''; ?>">
            <div class="login-header">
                <img src="static/images/logo.png" alt="Logo" class="logo-img">
                <h3>Forgot Password</h3>
                <p>Enter your email and we'll send you a reset link</p>
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
            <?php else: ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="mb-4">
                    <label class="form-label fw-semibold">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        <input type="email" name="email" class="form-control" placeholder="Enter your registered email" required autofocus>
                    </div>
                </div>
                <button type="submit" class="btn btn-login">
                    <i class="fas fa-paper-plane me-2"></i> Send Reset Link
                </button>
            </form>
            <?php endif; ?>

            <a href="login.php" class="back-link">
                <i class="fas fa-arrow-left me-1"></i> Back to Login
            </a>

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