<?php
// includes/mail_config.php
// SMTP settings for every email the app sends, and one place that turns a
// PHPMailer failure into a clear reason.
//
// Where the settings come from (first one set wins, per value):
//   1. environment variables  MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD,
//      MAIL_FROM_ADDRESS, MAIL_FROM_NAME, MAIL_ENCRYPTION (tls | ssl)
//   2. the .env file in the project root (same names; never committed, and
//      blocked from the web by .htaccess)
//   3. Settings > Email Settings (system_settings, group 'email')
// The Gmail App Password therefore never has to live in the source code.

require_once __DIR__ . '/../config/database.php';

/** Read the project .env once into getenv()/$_ENV (real environment variables win). */
function mailLoadDotEnv()
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    $file = __DIR__ . '/../.env';
    if (!is_file($file) || !is_readable($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($k, $v) = array_map('trim', explode('=', $line, 2));
        if (!preg_match('/^[A-Z0-9_]+$/', $k)) continue;
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = substr($v, 1, -1);
        if (getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}

function mailEnv($key)
{
    mailLoadDotEnv();
    $v = getenv($key);
    return ($v === false || trim($v) === '') ? null : trim($v);
}

/**
 * The SMTP settings to use.
 * @return array host, port, secure ('tls'|'ssl'), username, password, from, from_name,
 *               source (per value: 'env' or 'settings')
 */
function mailConfig()
{
    $db = [];
    foreach (getRecords("SELECT setting_key, setting_value FROM system_settings WHERE setting_group = 'email'") as $r) {
        $db[$r['setting_key']] = (string) $r['setting_value'];
    }
    $pick = function ($env, $dbKey, $default = '') use ($db, &$source) {
        $e = mailEnv($env);
        if ($e !== null) { $source[$dbKey] = 'env'; return $e; }
        $source[$dbKey] = 'settings';
        return trim((string) ($db[$dbKey] ?? '')) !== '' ? trim((string) $db[$dbKey]) : $default;
    };
    $source = [];
    $cfg = [
        'host'      => $pick('MAIL_HOST', 'smtp_host', 'smtp.gmail.com'),
        'port'      => (int) $pick('MAIL_PORT', 'smtp_port', '587'),
        'username'  => $pick('MAIL_USERNAME', 'smtp_username'),
        // Gmail shows the App Password in groups of 4 ("abcd efgh ijkl mnop"): spaces are not part of it.
        'password'  => str_replace(' ', '', $pick('MAIL_PASSWORD', 'smtp_password')),
        'from'      => $pick('MAIL_FROM_ADDRESS', 'smtp_from'),
        'from_name' => html_entity_decode($pick('MAIL_FROM_NAME', 'smtp_from_name', 'EGradePro'), ENT_QUOTES, 'UTF-8'),
    ];
    if ($cfg['from'] === '') $cfg['from'] = $cfg['username'];
    $enc = strtolower((string) mailEnv('MAIL_ENCRYPTION'));
    $cfg['secure'] = in_array($enc, ['ssl', 'tls'], true) ? $enc : ($cfg['port'] === 465 ? 'ssl' : 'tls');
    $cfg['source'] = $source;
    return $cfg;
}

/** Why sending cannot even start (missing settings), or null when it can. */
function mailConfigProblem(array $cfg)
{
    $missing = [];
    if ($cfg['host'] === '') $missing[] = 'SMTP host (MAIL_HOST)';
    if ($cfg['port'] <= 0) $missing[] = 'SMTP port (MAIL_PORT)';
    if ($cfg['username'] === '') $missing[] = 'SMTP username (MAIL_USERNAME)';
    if ($cfg['password'] === '') $missing[] = 'SMTP password / Gmail App Password (MAIL_PASSWORD)';
    if ($missing) return 'SMTP configuration missing: ' . implode(', ', $missing) . '.';
    if (!filter_var($cfg['from'], FILTER_VALIDATE_EMAIL)) return 'SMTP configuration missing: a valid sender email (MAIL_FROM_ADDRESS).';
    return null;
}

/** A PHPMailer ready to send with these settings (exceptions on). */
function mailNewMailer(array $cfg)
{
    require_once __DIR__ . '/../vendor/autoload.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $cfg['host'];
    $mail->Port = $cfg['port'];
    $mail->SMTPAuth = true;
    $mail->Username = $cfg['username'];
    $mail->Password = $cfg['password'];
    $mail->SMTPSecure = $cfg['secure'] === 'ssl' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Timeout = 20;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($cfg['from'], $cfg['from_name']);
    $mail->isHTML(true);
    return $mail;
}

/** A PHPMailer error as a reason a person can act on. */
function mailFriendlyError($raw, array $cfg = [])
{
    $raw = trim((string) $raw);
    $host = ($cfg['host'] ?? 'the SMTP server') . (isset($cfg['port']) ? ':' . $cfg['port'] : '');
    if (stripos($raw, 'Could not authenticate') !== false || stripos($raw, 'Username and Password not accepted') !== false || stripos($raw, '535') !== false) {
        $gmail = stripos($cfg['host'] ?? '', 'gmail') !== false;
        return 'SMTP authentication failed' . ($gmail ? ' — invalid Gmail App Password or username. Use a 16-letter App Password (Google Account › Security › 2-Step Verification › App passwords), not the normal Gmail password.' : ' — check the SMTP username and password.');
    }
    if (stripos($raw, 'Could not connect') !== false || stripos($raw, 'connect()') !== false || stripos($raw, 'timed out') !== false || stripos($raw, 'Connection refused') !== false) {
        return 'Connection timeout — could not reach ' . $host . '. Check the host, the port (587 = TLS, 465 = SSL) and the internet connection / firewall.';
    }
    if (stripos($raw, 'Invalid address') !== false || stripos($raw, 'recipients failed') !== false || stripos($raw, '550') !== false || stripos($raw, '553') !== false) {
        return 'Invalid recipient email — the address was refused.';
    }
    if (stripos($raw, 'STARTTLS') !== false || stripos($raw, 'SSL') !== false || stripos($raw, 'certificate') !== false) {
        return 'TLS/SSL problem with ' . $host . ' — use port 587 with TLS or 465 with SSL. (' . mb_strimwidth($raw, 0, 160, '…') . ')';
    }
    return $raw !== '' ? mb_strimwidth($raw, 0, 300, '…') : 'The email could not be sent (no reason given by the server).';
}

/**
 * Send one email now. Used by the Send Test Email button.
 * @return array{success:bool, error?:string}
 */
function mailSendOne($to, $subject, $html)
{
    $cfg = mailConfig();
    if (($p = mailConfigProblem($cfg)) !== null) return ['success' => false, 'error' => $p];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'error' => 'Invalid recipient email: "' . $to . '".'];
    $mail = null;
    try {
        $mail = mailNewMailer($cfg);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '</p>'], "\n", $html)));
        $mail->send();
        return ['success' => true];
    } catch (\Throwable $e) {
        $reason = mailFriendlyError($mail ? ($mail->ErrorInfo ?: $e->getMessage()) : $e->getMessage(), $cfg);
        error_log('Email to ' . $to . ' failed: ' . $reason);
        return ['success' => false, 'error' => $reason];
    }
}
