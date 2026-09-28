<?php
// includes/sms.php
// Text messages to parents/guardians. The sender is chosen in .env:
//
//   SMS_PROVIDER=android_gateway   "SMS Gateway for Android" app on a phone (defense/demo)
//     SMS_GATEWAY_URL=http://192.168.1.20:8080         (Local mode: same Wi-Fi as this server)
//                   or https://api.sms-gate.app/3rdparty/v1  (Cloud mode)
//     SMS_GATEWAY_USER=...   SMS_GATEWAY_PASSWORD=...   (shown in the app)
//   SMS_PROVIDER=traccar           "Traccar SMS Gateway" app (Google Play) on a phone
//     SMS_GATEWAY_URL=http://192.168.1.20:8082   SMS_GATEWAY_TOKEN=...   (both shown in the app)
//   SMS_PROVIDER=semaphore         Semaphore (deployment)
//     SEMAPHORE_API_KEY=...   SEMAPHORE_SENDER=EGradePro (optional, once approved)
//   (empty)                        no sender: the text is shown so staff can send it from a phone
//
// Every text is logged in sms_messages (status, reason). Kinds of text can be
// switched on/off in Settings > SMS. Secrets live only in .env.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mail_config.php';   // mailEnv()

const SMS_KINDS = [
    'welcome' => 'Welcome + Parent Portal setup link (when enrollment is approved)',
    'absent'  => 'Absent today',
    'test'    => 'Test message from Settings',
    'manual'  => 'Sent by staff',
];

function smsEnsureSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('sms_messages_v1')) return;
    executeQuery(
        "CREATE TABLE IF NOT EXISTS sms_messages (
            sms_id INT AUTO_INCREMENT PRIMARY KEY,
            to_number VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'manual',
            student_id INT NULL,
            dedupe_key VARCHAR(80) NULL COMMENT 'one text per key (e.g. absent:STUDENT:DATE)',
            provider VARCHAR(30) NULL,
            status ENUM('Sent','Failed','Not sent') NOT NULL DEFAULT 'Not sent',
            error VARCHAR(255) NULL,
            parts TINYINT NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_sms_dedupe (dedupe_key),
            KEY idx_sms_student (student_id),
            KEY idx_sms_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    schemaMarkReady('sms_messages_v1');
}

/** 09XXXXXXXXX, or '' when it is not a Philippine mobile number. */
function smsNormalizeNumber($n)
{
    $d = preg_replace('/\D/', '', (string) $n);
    if (preg_match('/^639(\d{9})$/', $d, $m)) return '09' . $m[1];
    if (preg_match('/^9(\d{9})$/', $d, $m)) return '09' . $m[1];
    return preg_match('/^09\d{9}$/', $d) ? $d : '';
}

function smsMask($n)
{
    $n = smsNormalizeNumber($n);
    return $n === '' ? '' : substr($n, 0, 4) . '•••' . substr($n, -4);
}

/** Number of physical SMS a text needs (160 per SMS, 153 when split). */
function smsParts($text)
{
    $len = mb_strlen((string) $text);
    return $len <= 160 ? 1 : (int) ceil($len / 153);
}

/** The configured sender: ['provider' => 'android_gateway'|'semaphore'|'', 'label' => ..., 'problem' => ''|reason]. */
function smsProvider()
{
    $p = strtolower((string) mailEnv('SMS_PROVIDER'));
    if ($p === 'android_gateway') {
        $ok = mailEnv('SMS_GATEWAY_URL') && mailEnv('SMS_GATEWAY_USER') && mailEnv('SMS_GATEWAY_PASSWORD');
        return ['provider' => $p, 'label' => 'Android phone (SMS Gateway app)', 'problem' => $ok ? '' : 'SMS_GATEWAY_URL, SMS_GATEWAY_USER and SMS_GATEWAY_PASSWORD must be set in .env.'];
    }
    if ($p === 'traccar') {
        $ok = mailEnv('SMS_GATEWAY_URL') && mailEnv('SMS_GATEWAY_TOKEN');
        return ['provider' => $p, 'label' => 'Android phone (Traccar SMS Gateway app)', 'problem' => $ok ? '' : 'The address and the key from the Traccar SMS Gateway app must be saved.'];
    }
    if ($p === 'semaphore') {
        return ['provider' => $p, 'label' => 'Semaphore', 'problem' => mailEnv('SEMAPHORE_API_KEY') ? '' : 'SEMAPHORE_API_KEY must be set in .env.'];
    }
    return ['provider' => '', 'label' => 'None (texts are sent by staff from a phone)', 'problem' => 'No SMS sender is set up (SMS_PROVIDER in .env).'];
}

function smsReady()
{
    $p = smsProvider();
    return $p['provider'] !== '' && $p['problem'] === '';
}

/** Is this kind of text switched on in Settings > SMS? (welcome and absent are on by default) */
function smsKindOn($kind)
{
    if (in_array($kind, ['test', 'manual'], true)) return true;
    $r = getRecord("SELECT setting_value FROM system_settings WHERE setting_key = ?", ['sms_on_' . $kind]);
    return $r ? $r['setting_value'] === '1' : in_array($kind, ['welcome', 'absent'], true);
}

function smsSetKind($kind, $on)
{
    $key = 'sms_on_' . $kind;
    if (getRecord("SELECT 1 FROM system_settings WHERE setting_key = ?", [$key])) {
        executeQuery("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?", [$on ? '1' : '0', $key]);
    } else {
        executeQuery("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'sms')", [$key, $on ? '1' : '0']);
    }
}

/** POST JSON / form and return [httpCode, body, curlError]. */
function smsHttp($url, $body, array $headers, $user = null, $pass = null)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
    ]);
    if ($user !== null) curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, (string) $res, $err];
}

/**
 * Send one text now (or record it as "Not sent" when no sender is set up).
 * $opts: kind, student_id, dedupe_key, created_by
 * @return array{success:bool, status:string, error?:string, duplicate?:bool}
 */
function smsSend($to, $message, array $opts = [])
{
    smsEnsureSchema();
    $kind = $opts['kind'] ?? 'manual';
    $num = smsNormalizeNumber($to);
    if ($num === '') return ['success' => false, 'status' => 'Failed', 'error' => 'Not a valid Philippine mobile number (09XXXXXXXXX).'];
    if (!smsKindOn($kind)) return ['success' => false, 'status' => 'Not sent', 'error' => 'This kind of text is switched off in Settings > SMS.'];
    $dedupe = $opts['dedupe_key'] ?? null;
    if ($dedupe && getRecord("SELECT 1 FROM sms_messages WHERE dedupe_key = ?", [$dedupe])) {
        return ['success' => true, 'status' => 'Sent', 'duplicate' => true];
    }

    $p = smsProvider();
    $status = 'Not sent';
    $error = $p['problem'];
    if ($p['provider'] !== '' && $p['problem'] === '') {
        if ($p['provider'] === 'android_gateway') {
            $base = rtrim((string) mailEnv('SMS_GATEWAY_URL'), '/');
            [$code, $body, $cerr] = smsHttp($base . '/message',
                json_encode(['message' => $message, 'phoneNumbers' => ['+63' . substr($num, 1)]]),
                ['Content-Type: application/json'], (string) mailEnv('SMS_GATEWAY_USER'), (string) mailEnv('SMS_GATEWAY_PASSWORD'));
            if ($cerr !== '') $error = 'Could not reach the phone gateway (' . $cerr . '). Is the phone on, the app running, and on the same Wi-Fi?';
            elseif ($code === 401) $error = 'The phone gateway refused the username/password (check .env).';
            elseif ($code >= 200 && $code < 300) { $status = 'Sent'; $error = null; }
            else $error = 'Phone gateway error (HTTP ' . $code . '): ' . mb_substr(strip_tags($body), 0, 150);
        } elseif ($p['provider'] === 'traccar') {
            // Traccar SMS Gateway "local service": POST {to, message} with the key as Authorization.
            [$code, $body, $cerr] = smsHttp(rtrim((string) mailEnv('SMS_GATEWAY_URL'), '/'),
                json_encode(['to' => '+63' . substr($num, 1), 'message' => $message]),
                ['Content-Type: application/json', 'Authorization: ' . (string) mailEnv('SMS_GATEWAY_TOKEN')]);
            if ($cerr !== '') $error = 'Could not reach the phone (' . $cerr . '). Is the phone on, the Traccar SMS Gateway app running (service on), and on the same Wi-Fi?';
            elseif ($code === 401 || $code === 403) $error = 'The phone refused the key (check the key saved in SMS Settings).';
            elseif ($code >= 200 && $code < 300) { $status = 'Sent'; $error = null; }
            else $error = 'Phone gateway error (HTTP ' . $code . '): ' . mb_substr(strip_tags($body), 0, 150);
        } else {
            $fields = ['apikey' => (string) mailEnv('SEMAPHORE_API_KEY'), 'number' => $num, 'message' => $message];
            if ($sn = mailEnv('SEMAPHORE_SENDER')) $fields['sendername'] = $sn;
            [$code, $body, $cerr] = smsHttp('https://api.semaphore.co/api/v4/messages', http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded']);
            $json = json_decode($body, true);
            if ($cerr !== '') $error = 'Could not reach Semaphore (' . $cerr . ').';
            elseif ($code >= 200 && $code < 300 && is_array($json) && isset($json[0]['message_id'])) { $status = 'Sent'; $error = null; }
            else $error = 'Semaphore: ' . mb_substr(is_array($json) ? json_encode($json) : strip_tags($body), 0, 180);
        }
        if ($status !== 'Sent') $status = 'Failed';
    }
    try {
        executeQuery(
            "INSERT INTO sms_messages (to_number, message, kind, student_id, dedupe_key, provider, status, error, parts, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)",
            [$num, preg_replace(['/c=[A-Za-z0-9]{8}/', '/Code: [A-Z0-9]{8}/'], ['c=••••••••', 'Code: ••••••••'], $message),   // setup codes are never stored
             $kind, $opts['student_id'] ?? null, $status === 'Sent' ? $dedupe : null, $p['provider'] ?: null, $status,
             $error ? mb_substr($error, 0, 255) : null, smsParts($message), $opts['created_by'] ?? null]
        );
    } catch (\Throwable $e) {
        error_log('sms log: ' . $e->getMessage());
    }
    return ['success' => $status === 'Sent', 'status' => $status, 'error' => $error];
}

/**
 * Save SMS settings into the server's .env (Settings > SMS). Only the given keys
 * are replaced/added; an empty value for a secret keeps the saved one.
 * @return string '' when saved, otherwise the reason
 */
function smsSaveEnv(array $values)
{
    $file = __DIR__ . '/../.env';
    $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
    if ($lines === false) return 'The .env file could not be read.';
    foreach ($values as $key => $val) {
        if (!preg_match('/^[A-Z0-9_]+$/', $key)) continue;
        $val = str_replace(["\r", "\n"], '', trim((string) $val));
        $line = $key . '=' . $val;
        $found = false;
        foreach ($lines as $i => $l) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $l)) { $lines[$i] = $line; $found = true; }
        }
        if (!$found) $lines[] = $line;
        putenv($line);
        $_ENV[$key] = $val;
    }
    return file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false ? 'The .env file could not be saved.' : '';
}
