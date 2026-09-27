<?php
// TEMPLATE: copied to config/email.php on a new install (.devcontainer/setup.sh). No secrets here --
// the SMTP settings come from .env / Settings > Email Settings (includes/mail_config.php).
// config/email.php
// Email configuration using PHPMailer

// ✅ GAMITIN ANG COMPOSER AUTOLOADER
require_once __DIR__ . '/../vendor/autoload.php';

// I-require ang database
require_once __DIR__ . '/database.php';

// Use PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function getEmailSettings() {
    $settings = getRecords("SELECT setting_key, setting_value FROM system_settings WHERE setting_group = 'email'");
    $result = [];
    foreach ($settings as $s) {
        $result[$s['setting_key']] = $s['setting_value'];
    }
    // .env / environment variables (MAIL_*) win over Settings > Email Settings.
    require_once __DIR__ . '/../includes/mail_config.php';
    $c = mailConfig();
    $result['smtp_host'] = $c['host'];
    $result['smtp_port'] = (string) $c['port'];
    $result['smtp_username'] = $c['username'];
    $result['smtp_password'] = $c['password'];
    $result['smtp_from'] = $c['from'];
    $result['smtp_from_name'] = $c['from_name'];
    $result['smtp_secure'] = $c['secure'];
    return $result;
}

function sendEmail($to, $subject, $body, $isHTML = true) {
    $settings = getEmailSettings();
    
    if (empty($settings['smtp_host']) || empty($settings['smtp_username'])) {
        error_log("Email not sent: SMTP settings not configured");
        return ['success' => false, 'message' => 'SMTP settings not configured'];
    }
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $settings['smtp_host'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $settings['smtp_username'] ?? '';
        $mail->Password   = $settings['smtp_password'] ?? '';
        $mail->SMTPSecure = ($settings['smtp_secure'] ?? 'tls') === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = intval($settings['smtp_port'] ?? 587);
        
        // Sender and recipients
        $from_email = $settings['smtp_from'] ?? $settings['smtp_username'] ?? '';
        $from_name = $settings['smtp_from_name'] ?? 'EGRADEPRO System';
        
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($to);
        $mail->addReplyTo($from_email, $from_name);
        
        // Content
        $mail->isHTML($isHTML);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);
        
        $mail->send();
        return ['success' => true, 'message' => 'Email sent successfully'];
    } catch (Exception $e) {
        $reason = mailFriendlyError($mail->ErrorInfo, mailConfig());
        error_log("Email error: " . $reason);
        return ['success' => false, 'message' => "Email could not be sent: " . $reason];
    }
}

function sendEnrollmentNotification($student_email, $student_name, $status, $grade_level, $strand, $section, $lrn = '') {
    $school_name = getSchoolName();
    
    // Links use APP_URL (.env), never a hard-coded localhost. An approved student gets
    // a one-time secure link (includes/student_access_tokens.php) that opens THEIR portal.
    require_once __DIR__ . '/../includes/student_access_tokens.php';
    $login_link = appUrl('student_login.php');
    $portal_link = $login_link;
    if ($status === 'Approved') {
        try {
            $portal_link = sptAccessUrlForLrn($lrn) ?: $login_link;
        } catch (\Throwable $e) {
            error_log('Student Portal access link could not be made: ' . $e->getMessage());
        }
    }
    $portal_link = htmlspecialchars($portal_link, ENT_QUOTES);
    
    if ($status === 'Approved') {
        $subject = "🎓 Enrollment Approved - EGRADEPRO Student Portal";
        $body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #1565c0; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f5f5f5; padding: 30px; border-radius: 0 0 10px 10px; }
                .card { background: white; padding: 20px; border-radius: 10px; margin: 15px 0; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
                .lrn-box { background: #e3f2fd; padding: 15px; border-radius: 10px; text-align: center; font-size: 24px; font-weight: bold; letter-spacing: 3px; color: #1565c0; }
                .btn { background: #1565c0; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; }
                .footer { text-align: center; font-size: 12px; color: #999; margin-top: 20px; }
                .steps { padding-left: 20px; }
                .steps li { margin: 8px 0; }
                .status-badge { background: #d4edda; color: #155724; padding: 10px; border-radius: 5px; text-align: center; font-size: 18px; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🎓 EGRADEPRO</h1>
                    <p>Enrollment Management System</p>
                </div>
                <div class='content'>
                    <h2>✅ Enrollment Approved!</h2>
                    <p>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
                    <p>We are pleased to inform you that your enrollment has been <strong>APPROVED</strong>!</p>
                    
                    <div class='status-badge'>✅ ENROLLMENT APPROVED</div>
                    
                    <div class='card'>
                        <h3>📋 Enrollment Details</h3>
                        <p><strong>Student Name:</strong> " . htmlspecialchars($student_name) . "</p>
                        <p><strong>Grade Level:</strong> " . htmlspecialchars($grade_level) . "</p>
                        <p><strong>Section:</strong> " . htmlspecialchars($section) . "</p>
                        " . (!empty($strand) ? "<p><strong>Strand:</strong> " . htmlspecialchars($strand) . "</p>" : "") . "
                    </div>
                    
                    <div class='card'>
                        <h3>🔑 Your Student Portal Access</h3>
                        <p>Use your LRN to access the Student Portal:</p>
                        <div class='lrn-box'>
                            📛 " . htmlspecialchars($lrn) . "
                        </div>
                        <br>
                        <p><strong>Your enrollment has been approved.</strong> Click <strong>Create My Password</strong> to set your own password for the EGradePro Student Portal.</p>
                        <p><a href='" . $portal_link . "' class='btn' style='color:#ffffff !important;text-decoration:none;'>🔐 Create My Password</a></p>
                        <p style='font-size: 13px; color: #666;'>This link is only for you, works once and expires in 7 days. After you create your password, sign in at <a href='" . htmlspecialchars($login_link, ENT_QUOTES) . "'>" . htmlspecialchars($login_link) . "</a> with your LRN and password. Lost this link? Use <strong>Create / Forgot password</strong> on the Student Login page.</p>
                        <p style='margin-top: 10px; font-size: 14px;'>
                            Or copy this link to your browser:<br>
                            <code>" . $portal_link . "</code>
                        </p>
                    </div>
                    
                    <div class='card'>
                        <h3>📝 Instructions</h3>
                        <ol class='steps'>
                            <li>Click <strong>Create My Password</strong> above and choose your own password — then your Student Dashboard opens</li>
                            <li>Next time, use the Student Login page: your <strong>LRN</strong> <code>" . htmlspecialchars($lrn) . "</code> and your password</li>
                            <li>You can now view your:
                                <ul>
                                    <li>📊 Dashboard</li>
                                    <li>📚 Grades</li>
                                    <li>📋 Enrollment Status</li>
                                    <li>👤 Profile</li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                    
                    <div class='card' style='background: #fff3cd; border: 1px solid #ffc107;'>
                        <h4>⚠️ Important Notes</h4>
                        <ul>
                            <li>Never share your password with anyone. The school will never ask for it.</li>
                            <li>If you encounter any issues, contact the registrar's office.</li>
                            <li>For inquiries, email: registrar@egradepro.com</li>
                        </ul>
                    </div>
                    
                    <p style='text-align: center;'>
                        <a href='" . $portal_link . "' class='btn' style='color:#ffffff !important;text-decoration:none;'>🚀 Create My Password Now</a>
                    </p>
                </div>
                <div class='footer'>
                    <p>© " . date('Y') . " EGRADEPRO | " . htmlspecialchars($school_name) . "</p>
                    <p>This is an automated message. Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    } else {
        // Online applications are never "rejected" now: the other outcome is Incomplete Requirements.
        $incomplete = ($status === 'Incomplete Requirements');
        $track_link = htmlspecialchars(function_exists('appUrl') ? appUrl('enrollment_status.php') : 'enrollment_status.php', ENT_QUOTES);
        $subject = $incomplete ? "📋 Incomplete Requirements - EGRADEPRO Enrollment" : "📋 Enrollment Update - EGRADEPRO";
        $body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f5f5f5; padding: 30px; border-radius: 0 0 10px 10px; }
                .card { background: white; padding: 20px; border-radius: 10px; margin: 15px 0; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
                .status-badge { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px; text-align: center; font-size: 18px; font-weight: bold; }
                .footer { text-align: center; font-size: 12px; color: #999; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🎓 EGRADEPRO</h1>
                    <p>Enrollment Management System</p>
                </div>
                <div class='content'>
                    <h2>" . ($incomplete ? "📄 Incomplete Requirements" : "❌ Enrollment Rejected") . "</h2>
                    <p>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
                    <p>" . ($incomplete ? "Your enrollment application has <strong>Incomplete Requirements</strong>. Please submit the missing requirement so the registrar can approve your enrollment." : "We regret to inform you that your enrollment has been <strong>REJECTED</strong>.") . "</p>
                    
                    <div class='status-badge'>" . ($incomplete ? "📄 INCOMPLETE REQUIREMENTS" : "❌ ENROLLMENT REJECTED") . "</div>
                    
                    <div class='card'>
                        <h3>📋 Enrollment Details</h3>
                        <p><strong>Student Name:</strong> " . htmlspecialchars($student_name) . "</p>
                        <p><strong>Grade Level:</strong> " . htmlspecialchars($grade_level) . "</p>
                        " . (!empty($strand) ? "<p><strong>Strand:</strong> " . htmlspecialchars($strand) . "</p>" : "") . "
                    </div>
                    
                    <div class='card' style='background: #f8d7da; border: 1px solid #dc3545;'>
                        <h4>📞 What to Do Next</h4>
                        <ul>
                            " . ($incomplete
                                ? "<li>Open your <a href='" . $track_link . "'>Enrollment Tracking page</a> to see what is missing and upload it (e.g. Form 137)</li><li>Or bring it to the registrar's office</li>"
                                : "<li>Contact the registrar's office for more information</li><li>Check if you have submitted all requirements</li><li>You may re-apply for enrollment</li>") . "
                        </ul>
                    </div>
                </div>
                <div class='footer'>
                    <p>© " . date('Y') . " EGRADEPRO | " . htmlspecialchars($school_name) . "</p>
                    <p>This is an automated message. Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    return sendEmail($student_email, $subject, $body);
}
?>