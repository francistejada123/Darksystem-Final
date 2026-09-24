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
    } else {
        $smtp_host = sanitize($_POST['smtp_host'] ?? '');
        $smtp_port = sanitize($_POST['smtp_port'] ?? '');
        $smtp_username = sanitize($_POST['smtp_username'] ?? '');
        $smtp_password = sanitize($_POST['smtp_password'] ?? '');
        $smtp_from = sanitize($_POST['smtp_from'] ?? '');
        $smtp_from_name = sanitize($_POST['smtp_from_name'] ?? '');
        
        $settings_data = [
            'smtp_host' => $smtp_host,
            'smtp_port' => $smtp_port,
            'smtp_username' => $smtp_username,
            'smtp_password' => $smtp_password,
            'smtp_from' => $smtp_from,
            'smtp_from_name' => $smtp_from_name
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
                    <input type="password" name="smtp_password" class="form-control" 
                           value="<?php echo htmlspecialchars($email_settings['smtp_password'] ?? ''); ?>" 
                           placeholder="Enter app password" required>
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

<?php include '../../includes/footer.php'; ?>