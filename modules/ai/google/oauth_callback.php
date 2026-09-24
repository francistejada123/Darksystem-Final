<?php
// modules/ai/google/oauth_callback.php
//
// Google redirects here after the Administrator approves the consent
// screen in oauth_setup.php. This is registered EXACTLY as the
// "Authorized redirect URI" on the OAuth Client ID in Google Cloud
// Console (GOOGLE_OAUTH_REDIRECT_URI in config/google_config.php) — it
// must match byte-for-byte or Google will refuse the redirect.
//
// On success, writes the new refresh token into config/google_config.php
// in place (a targeted line replace, not a full rewrite of the file, so
// any other hand-edited comments/values in that file are preserved).

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/google_forms_service.php';

if (!hasRole('Administrator')) {
    header('Location: ../../../index.php');
    exit;
}

function oauthSetupFail($message)
{
    http_response_code(400);
    echo '<p style="font-family:sans-serif;max-width:640px;margin:40px auto;">'
        . '<strong>Google authorization failed:</strong> ' . htmlspecialchars($message) . '</p>'
        . '<p style="font-family:sans-serif;max-width:640px;margin:0 auto;"><a href="oauth_setup.php">Back to setup</a></p>';
    exit;
}

$state = $_GET['state'] ?? '';
$expected_state = $_SESSION['google_oauth_state'] ?? null;
unset($_SESSION['google_oauth_state']);

if (!$expected_state || !hash_equals($expected_state, (string) $state)) {
    oauthSetupFail('This authorization link expired or was already used. Please go back and click Connect Google Account again.');
}

if (isset($_GET['error'])) {
    oauthSetupFail('Google reported: ' . (string) $_GET['error']);
}

$code = $_GET['code'] ?? '';
if ($code === '') {
    oauthSetupFail('No authorization code was returned by Google.');
}

if (!defined('GOOGLE_OAUTH_CLIENT_ID') || !defined('GOOGLE_OAUTH_CLIENT_SECRET') || !defined('GOOGLE_OAUTH_REDIRECT_URI')) {
    oauthSetupFail('config/google_config.php is missing the OAuth Client ID/Secret/Redirect URI.');
}

$token_result = googleHttpRequest('POST', GOOGLE_TOKEN_URL, [], [
    'code' => $code,
    'client_id' => GOOGLE_OAUTH_CLIENT_ID,
    'client_secret' => GOOGLE_OAUTH_CLIENT_SECRET,
    'redirect_uri' => GOOGLE_OAUTH_REDIRECT_URI,
    'grant_type' => 'authorization_code',
], true);

if (!$token_result['success']) {
    error_log('Google OAuth code exchange failed: ' . ($token_result['error'] ?? 'unknown'));
    // Show Google's actual reason (never the secret) so setup problems can be fixed.
    $google_code = is_array($token_result['body'] ?? null) && isset($token_result['body']['error']) && is_string($token_result['body']['error'])
        ? $token_result['body']['error'] : '';
    $reason = trim(($google_code !== '' ? '[' . $google_code . '] ' : '') . (string) ($token_result['error'] ?? 'unknown error'));
    $reason = str_replace(GOOGLE_OAUTH_CLIENT_SECRET, '[hidden]', $reason);
    oauthSetupFail('Could not exchange the authorization code with Google. Reason: ' . $reason . ' -- Please go back and click Connect Google Account again (each authorization code works only once).');
}

$refresh_token = $token_result['body']['refresh_token'] ?? null;
if (!$refresh_token) {
    oauthSetupFail('Google did not return a refresh token. Go to myaccount.google.com/permissions, remove this app\'s access for the shared account, then try Connect Google Account again (this forces Google to issue a new one).');
}

// ---- Write the refresh token into config/google_config.php in place. ----
$config_path = __DIR__ . '/../../../config/google_config.php';
if (!is_file($config_path)) {
    oauthSetupFail('config/google_config.php does not exist yet. Copy config/google_config.example.php to config/google_config.php, fill in the Client ID/Secret, then try again.');
}

$config_contents = file_get_contents($config_path);
if ($config_contents === false) {
    oauthSetupFail('config/google_config.php could not be read.');
}

$pattern = "/define\\('GOOGLE_REFRESH_TOKEN',\\s*'[^']*'\\)/";
$replacement = "define('GOOGLE_REFRESH_TOKEN', '" . addslashes($refresh_token) . "')";

if (preg_match($pattern, $config_contents)) {
    $new_contents = preg_replace($pattern, $replacement, $config_contents, 1);
} else {
    // No existing GOOGLE_REFRESH_TOKEN define found (unexpected hand-edit
    // of the template) — refuse rather than guess at the file structure.
    oauthSetupFail('config/google_config.php does not look like the expected template (no GOOGLE_REFRESH_TOKEN line found). Please fix it manually: set GOOGLE_REFRESH_TOKEN to ' . $refresh_token);
}

if (@file_put_contents($config_path, $new_contents) === false) {
    oauthSetupFail('The refresh token was received from Google but could not be saved to config/google_config.php (check that the web server can write to the config/ folder). Ask your developer to add it manually: GOOGLE_REFRESH_TOKEN = ' . $refresh_token);
}

logActivity((int) ($_SESSION['user_id'] ?? 0), 'Google Forms', 'Connected shared Google account', 'Authorized the shared Google account for quiz Form creation.');

header('Location: oauth_setup.php');
exit;
