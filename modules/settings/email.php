<?php
// modules/settings/email.php
// Email settings

require_once '../../config/database.php';
require_once '../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Email Settings';
$current_page = 'settings';
$base_url = BASE_URL;

// Get current settings
$settings = getRecords("SELECT * FROM system_settings WHERE setting_group = 'email'");

$email_settings = [];
foreach ($settings as $setting) {
    $email_settings[$setting['setting_key']] = $setting['setting_value'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } elseif (($_POST['form_action'] ?? '') === 'retry_failed_notifications') {
        // Queue every failed / not-sent student notification again (one email per student per post, never duplicated).
        require_once '../../includes/student_email_notify.php';
        senEnsureSchema();
        $n = executeQuery("UPDATE student_email_notifications SET status = 'Queued', error = NULL WHERE status IN ('Failed','Skipped')");
        $n = $n ? $n->rowCount() : 0;
        logActivity($_SESSION['user_id'], 'Settings', 'Retried student email notifications', "$n email(s) queued again");
        if ($n > 0) {
            @set_time_limit(60 + 5 * $n);
            $d = senDispatchQueued(500);
            if ($d['failed'] || $d['skipped']) {
                $_SESSION['error_message'] = "Sent again: {$d['sent']} of $n email(s) sent. " . ($d['failed'] + $d['skipped']) . ' failed: ' . $d['reason'];
            } else {
                $_SESSION['success_message'] = "Sent again: all {$d['sent']} email(s) were sent.";
            }
        } else {
            $_SESSION['success_message'] = 'There are no failed emails to send again.';
        }
        header('Location: email.php#notification-status');
        exit;
    } elseif (($_POST['form_action'] ?? '') === 'send_test_email') {
        // Development / setup check: is SMTP really working? Shows the exact reason when not.
        require_once '../../includes/mail_config.php';
        $test_to = trim((string) ($_POST['test_to'] ?? ''));
        $cfg = mailConfig();
        $r = mailSendOne($test_to, 'EGradePro test email',
            '<div style="font-family:Arial,sans-serif;font-size:14px;"><p>This is a test email from <strong>EGradePro</strong>.</p>'
            . '<p>If you can read this, SMTP is working: students will receive their new-work notifications.</p>'
            . '<p style="color:#64748b;font-size:12px;">Sent ' . date('F j, Y g:i A') . ' via ' . htmlspecialchars($cfg['host'] . ':' . $cfg['port'] . ' (' . strtoupper($cfg['secure']) . ')') . ' as ' . htmlspecialchars($cfg['username']) . '.</p></div>');
        logActivity($_SESSION['user_id'], 'Settings', 'Sent test email', ($r['success'] ? 'OK' : 'Failed: ' . $r['error']) . " to $test_to");
        if ($r['success']) {
            $_SESSION['success_message'] = 'Test email sent to ' . $test_to . '. SMTP is working — check that inbox (and the Spam folder).';
        } else {
            $_SESSION['error_message'] = 'Test email failed: ' . $r['error'];
        }
        header('Location: email.php#test-email');
        exit;
    } else {
        $smtp_host = sanitize($_POST['smtp_host'] ?? '');
        $smtp_port = sanitize($_POST['smtp_port'] ?? '');
        $smtp_username = sanitize($_POST['smtp_username'] ?? '');
        // Leave the App Password blank to keep the saved one. Stored as typed (spaces removed).
        $smtp_password = str_replace(' ', '', trim((string) ($_POST['smtp_password'] ?? '')));
        if ($smtp_password === '') $smtp_password = (string) ($email_settings['smtp_password'] ?? '');
        $smtp_from = sanitize($_POST['smtp_from'] ?? '');
        $smtp_from_name = sanitize($_POST['smtp_from_name'] ?? '');
        
        $settings_data = [
            'smtp_host' => $smtp_host,
            'smtp_port' => $smtp_port,
            'smtp_username' => $smtp_username,
            'smtp_password' => $smtp_password,
            'smtp_from' => $smtp_from,
            'smtp_from_name' => $smtp_from_name,
            // Email students when a teacher publishes new work (includes/student_email_notify.php)
            'email_task_notifications' => !empty($_POST['email_task_notifications']) ? '1' : '0',
        ];
        
        foreach ($settings_data as $key => $value) {
            $existing = getRecord("SELECT setting_id FROM system_settings WHERE setting_key = ? AND setting_group = 'email'", [$key]);
            if ($existing) {
                executeQuery("UPDATE system_settings SET setting_value = ? WHERE setting_key = ? AND setting_group = 'email'", [$value, $key]);
            } else {
                executeQuery("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'email')", [$key, $value]);
            }
        }
        
        logActivity($_SESSION['user_id'], 'Updated email settings', 'settings', 'Updated email configuration');
        $_SESSION['success_message'] = 'Email settings updated successfully!';
        header('Location: email.php?success=1');
        exit;
    }
}

$csrf_token = generateCSRFToken();

// The settings actually used for sending (.env / environment variables win over this form).
require_once '../../includes/mail_config.php';
$mail_cfg = mailConfig();
$mail_problem = mailConfigProblem($mail_cfg);
$mail_env_keys = array_keys(array_filter($mail_cfg['source'], function ($src) { return $src === 'env'; }));

// Student email notifications (new classwork / tasks): status for the admin.
require_once '../../includes/student_email_notify.php';
senEnsureSchema();
$sen_counts = [];
foreach (getRecords("SELECT status, COUNT(*) AS n FROM student_email_notifications GROUP BY status") as $r) $sen_counts[$r['status']] = (int) $r['n'];
$sen_rows = getRecords(
    "SELECT n.*, TRIM(CONCAT(s.last_name, ', ', s.first_name)) AS student_name
       FROM student_email_notifications n
       LEFT JOIN students s ON s.student_id = n.student_id
      ORDER BY n.id DESC LIMIT 50"
);

include '../../includes/header.php';
?>

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-envelope text-primary me-2"></i>Email Settings</h2>
            <a href="index.php?tab=general" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Settings
            </a>
        </div>
    </div>
</div>

<div class="alert alert-info">
    <i class="fas fa-info-circle me-2"></i>
    <strong>SMTP Setup Required:</strong> To send email notifications, you need to configure SMTP settings. 
    For Gmail, use <code>smtp.gmail.com</code> with port <code>587</code> and enable "Less secure app access" 
    or use an App Password.
</div>

<div class="card shadow-sm rounded-3">
    <div class="card-body">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">SMTP Host</label>
                    <input type="text" name="smtp_host" class="form-control" 
                           value="<?php echo htmlspecialchars($email_settings['smtp_host'] ?? 'smtp.gmail.com'); ?>" 
                           placeholder="e.g., smtp.gmail.com" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">SMTP Port</label>
                    <input type="number" name="smtp_port" class="form-control" 
                           value="<?php echo htmlspecialchars($email_settings['smtp_port'] ?? '587'); ?>" 
                           placeholder="e.g., 587" required>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label">SMTP Username (Email)</label>
                    <input type="email" name="smtp_username" class="form-control" 
                           value="<?php echo htmlspecialchars($email_settings['smtp_username'] ?? ''); ?>" 
                           placeholder="your_email@gmail.com" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">SMTP Password (App Password)</label>
                    <input type="password" name="smtp_password" class="form-control" autocomplete="new-password"
                           value=""
                           placeholder="<?php echo $mail_cfg['source']['smtp_password'] === 'env' ? 'Set in .env (MAIL_PASSWORD)' : (($email_settings['smtp_password'] ?? '') !== '' ? 'Saved — leave blank to keep it' : 'Enter the 16-letter Gmail App Password'); ?>">
                    <small class="text-muted">Not shown for security. <?php echo ($mail_cfg['password'] ?? '') === '' ? '<span class="text-danger fw-bold">No password is set — emails cannot be sent.</span>' : 'A password is set.'; ?></small>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label">From Email</label>
                    <input type="email" name="smtp_from" class="form-control" 
                           value="<?php echo htmlspecialchars($email_settings['smtp_from'] ?? ''); ?>" 
                           placeholder="sender@email.com" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">From Name</label>
                    <input type="text" name="smtp_from_name" class="form-control" 
                           value="<?php echo htmlspecialchars($email_settings['smtp_from_name'] ?? 'EGRADEPRO System'); ?>" 
                           placeholder="EGRADEPRO System" required>
                </div>
                <div class="col-12">
                    <label class="form-check" style="display:flex;gap:8px;align-items:flex-start;">
                        <input type="checkbox" class="form-check-input mt-1" name="email_task_notifications" value="1" <?php echo ($email_settings['email_task_notifications'] ?? '1') !== '0' ? 'checked' : ''; ?>>
                        <span><strong>Email students about new work</strong><br>
                            <small class="text-muted">When a teacher publishes a quiz, activity, assignment or learning material task, each student gets an email at the address from their enrollment (subject, teacher, title, deadline).</small></span>
                    </label>
                </div>
            </div>
            
            <div class="alert alert-warning mt-3">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Gmail Users:</strong> 
                1. Enable 2-Factor Authentication on your Google Account<br>
                2. Generate an App Password: Google Account → Security → App Passwords<br>
                3. Use the App Password here (not your regular password)
            </div>
            
            <div class="d-flex justify-content-end gap-2 mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Save Email Settings
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm rounded-3 mt-4" id="test-email">
    <div class="card-body">
        <h5 class="mb-2"><i class="fas fa-vial text-primary me-2"></i>Send Test Email</h5>
        <p class="text-muted small mb-2">Check that SMTP works before testing assessment notifications. The exact reason is shown when it fails.</p>
        <div class="small mb-3">
            Sending with: <strong><?php echo htmlspecialchars($mail_cfg['host'] . ':' . $mail_cfg['port']); ?></strong> (<?php echo strtoupper($mail_cfg['secure']); ?>)
            as <strong><?php echo htmlspecialchars($mail_cfg['username'] ?: '—'); ?></strong>,
            from <strong><?php echo htmlspecialchars($mail_cfg['from_name'] . ' <' . $mail_cfg['from'] . '>'); ?></strong>
            <?php if ($mail_env_keys): ?><br><span class="text-success"><i class="fas fa-lock me-1"></i>From the .env file / environment: <?php echo htmlspecialchars(implode(', ', $mail_env_keys)); ?></span><?php endif; ?>
            <?php if ($mail_problem): ?><div class="text-danger fw-bold mt-1"><i class="fas fa-triangle-exclamation me-1"></i><?php echo htmlspecialchars($mail_problem); ?></div><?php endif; ?>
        </div>
        <form method="POST" class="d-flex flex-wrap gap-2 m-0">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="form_action" value="send_test_email">
            <input type="email" name="test_to" class="form-control" style="max-width:340px" required placeholder="Send the test to…" value="<?php echo htmlspecialchars($mail_cfg['username']); ?>">
            <button type="submit" class="btn btn-primary" onclick="var b=this;setTimeout(function(){b.disabled=true;b.innerHTML='<i class=&quot;fas fa-spinner fa-spin me-1&quot;></i>Sending…';},0);"><i class="fas fa-paper-plane me-1"></i>Send Test Email</button>
        </form>
    </div>
</div>

<div class="card shadow-sm rounded-3 mt-4" id="notification-status">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="mb-0"><i class="fas fa-paper-plane text-primary me-2"></i>Student email notifications</h5>
            <?php if (!empty($sen_counts['Failed']) || !empty($sen_counts['Skipped'])): ?>
                <form method="POST" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="form_action" value="retry_failed_notifications">
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-rotate-right me-1"></i>Send failed emails again</button>
                </form>
            <?php endif; ?>
        </div>
        <p class="text-muted small mb-2">One email per student each time a teacher posts new work, sent to the email from the student's enrollment. A failed email never stops the post; it stays here until it is sent again.</p>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge bg-success">Sent: <?php echo (int) ($sen_counts['Sent'] ?? 0); ?></span>
            <span class="badge bg-secondary">Pending: <?php echo (int) ($sen_counts['Queued'] ?? 0); ?></span>
            <span class="badge bg-danger">Failed: <?php echo (int) ($sen_counts['Failed'] ?? 0); ?></span>
            <span class="badge bg-warning text-dark">Not sent: <?php echo (int) ($sen_counts['Skipped'] ?? 0); ?></span>
        </div>
        <?php if (!$sen_rows): ?>
            <p class="text-muted mb-0">No student emails yet.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:13px;">
                <thead><tr><th>Date</th><th>Student</th><th>Email</th><th>Subject</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($sen_rows as $r): ?>
                    <tr>
                        <td class="text-nowrap"><?php echo date('M j, g:i A', strtotime($r['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($r['student_name'] ?: ('#' . $r['student_id'])); ?></td>
                        <td><?php echo htmlspecialchars($r['email']); ?></td>
                        <td><?php echo htmlspecialchars($r['subject']); ?></td>
                        <td>
                            <span class="badge <?php echo ['Sent' => 'bg-success', 'Queued' => 'bg-secondary', 'Failed' => 'bg-danger', 'Skipped' => 'bg-warning text-dark'][$r['status']] ?? 'bg-secondary'; ?>"><?php echo $r['status'] === 'Skipped' ? 'Not sent' : ($r['status'] === 'Queued' ? 'Pending' : $r['status']); ?></span>
                            <?php if (!empty($r['error'])): ?><div class="text-danger small"><?php echo htmlspecialchars(mb_strimwidth($r['error'], 0, 140, '…')); ?></div><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
