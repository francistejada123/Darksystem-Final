<?php
// modules/ai/google/oauth_setup.php
//
// ONE-TIME setup page: lets an Administrator authorize the single shared
// Google account that will own every "Generate a quiz" Google Form.
// Administrator-only (not Teacher) — this configures a system-wide
// credential, not a per-teacher one.
//
// Flow: this page sends the browser to Google's consent screen; Google
// redirects back to oauth_callback.php in this same folder with a one-time
// authorization code, which is exchanged for a refresh token and written
// into config/google_config.php automatically. After that succeeds, this
// page (reloaded) shows "Connected" instead of the Connect button and
// nothing further needs to happen manually — every quiz Form from then on
// is created silently, server-side, using that stored refresh token.

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/google_forms_service.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ../../../index.php');
    exit;
}

$page_title = 'Google Forms Setup';
$current_page = 'ai';
$base_url = '../../../';

$has_client_credentials = defined('GOOGLE_OAUTH_CLIENT_ID')
    && GOOGLE_OAUTH_CLIENT_ID !== '' && GOOGLE_OAUTH_CLIENT_ID !== 'YOUR_CLIENT_ID_HERE.apps.googleusercontent.com'
    && defined('GOOGLE_OAUTH_CLIENT_SECRET')
    && GOOGLE_OAUTH_CLIENT_SECRET !== '' && GOOGLE_OAUTH_CLIENT_SECRET !== 'YOUR_CLIENT_SECRET_HERE';

$is_connected = googleFormsIsConfigured();

$auth_url = null;
if ($has_client_credentials) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;

    $scopes = implode(' ', [
        'https://www.googleapis.com/auth/forms.body',
        'https://www.googleapis.com/auth/forms.responses.readonly',
        'https://www.googleapis.com/auth/drive.file',
    ]);

    $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => GOOGLE_OAUTH_CLIENT_ID,
        'redirect_uri' => GOOGLE_OAUTH_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => $scopes,
        'access_type' => 'offline', // required to get a refresh_token back
        'prompt' => 'consent',      // forces a refresh_token even if this account authorized before
        'state' => $state,
    ]);
}

include '../../../includes/header.php';
?>
<div class="container-fluid py-4">
    <h4 class="fw-bold mb-3" style="color: var(--ink);">Google Forms Setup (Quiz Auto-Grading)</h4>
    <div class="card border-0 shadow-sm" style="max-width:640px;">
        <div class="card-body" style="padding:24px;">
            <?php if (!$has_client_credentials): ?>
                <p style="color: var(--text-muted);">
                    Google OAuth Client ID/Secret are not in <code>config/google_config.php</code> yet.
                    Follow the Google Cloud setup steps first, then add your Client ID and Client Secret to that file
                    (copy <code>config/google_config.example.php</code> to <code>config/google_config.php</code> if you haven't yet).
                </p>
            <?php elseif ($is_connected): ?>
                <div class="d-flex align-items-center gap-2 mb-2" style="color:#238863;font-weight:700;">
                    <i class="fas fa-check-circle"></i> Connected
                </div>
                <p style="color: var(--text-muted);">
                    The shared Google account is authorized. "Generate a quiz" can now create real Google Forms.
                    If quiz creation ever starts failing with an authorization error (e.g. the shared account's access was revoked),
                    come back here and click Reconnect.
                </p>
                <a href="<?php echo htmlspecialchars($auth_url ?? '#'); ?>" class="btn btn-outline-secondary" onclick="return confirm('Re-authorize the shared Google account?');">Reconnect</a>
            <?php else: ?>
                <p style="color: var(--text-muted);">
                    Click below and sign in with the ONE Google account that should own every quiz Form this app creates.
                    You only need to do this once.
                </p>
                <a href="<?php echo htmlspecialchars($auth_url); ?>" class="btn" style="background:#14213D;color:#fff;font-weight:600;padding:10px 22px;border-radius:6px;">
                    <i class="fab fa-google me-1"></i> Connect Google Account
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include '../../../includes/footer.php'; ?>
