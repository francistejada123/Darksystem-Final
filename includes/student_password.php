<?php
// includes/student_password.php
// Student Portal sign-in = LRN + the student's own password.
//
// The LRN only says WHO; the password proves it. A student creates the password
// from the one-time link in the "Enrollment Approved" email (or a new link from
// "Create / Forgot password" or from the registrar). The link is the secure
// student_portal_tokens token (hashed, expiring, one-time). Only a hash of the
// password is stored; nobody at the school can see it.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/student_access_tokens.php'; // sptIssue(), sptAccessUrl()
require_once __DIR__ . '/mail_config.php';           // mailSendOne(), appUrl()

const SPW_MAX_FAILS = 5;       // wrong passwords before a short lock
const SPW_LOCK_MINUTES = 15;

function spwEnsureSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    foreach ([
        'portal_password_hash' => "VARCHAR(255) NULL DEFAULT NULL COMMENT 'Student Portal password (hash only)'",
        'portal_password_set_at' => "DATETIME NULL DEFAULT NULL",
        'portal_failed_logins' => "INT NOT NULL DEFAULT 0",
        'portal_locked_until' => "DATETIME NULL DEFAULT NULL",
        // Temporary-password flow: the account starts with a generated temporary
        // password and must change it on first login.
        'portal_password_temp' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = password is temporary, must be changed on first login'",
        'portal_temp_password_set_at' => "DATETIME NULL DEFAULT NULL",
        // AES-256-GCM ciphertext of the temporary password, so the Enrollment
        // Tracking page can show it again without storing it as plain text.
        // Cleared as soon as the student changes their password.
        'portal_temp_password_enc' => "TEXT NULL",
    ] as $col => $def) {
        if (!getRecord("SHOW COLUMNS FROM students LIKE '$col'")) executeQuery("ALTER TABLE students ADD COLUMN $col $def");
    }
    schemaMarkReady('student_portal_password_v1');
}

// ---------------------------------------------------------------------------
// Student Portal LOGIN throttling (used by student_login.php)
//
// Old behaviour: 5 wrong passwords locked the STUDENT'S ACCOUNT for 15 minutes,
// so anyone who knew a classmate's LRN could keep that classmate locked out.
//
// New behaviour: wrong tries are counted per "LRN typed + device (IP)":
//   - SPW_PAIR_MAX_FAILS wrong tries from the same device for the same LRN
//     block only THAT device for SPW_PAIR_WINDOW_MIN minutes. The real student
//     on another phone/computer is not affected.
//   - SPW_LRN_MAX_FAILS wrong tries for one LRN from ALL devices within an hour
//     block that LRN for the rest of the hour (stops guessing spread over many
//     devices, but takes far more effort than the old 5-try lock).
// Whatever LRN is typed is counted the same way, whether or not it exists, so
// the throttle itself cannot be used to find out which LRNs are real.
// ---------------------------------------------------------------------------
const SPW_PAIR_MAX_FAILS = 5;
const SPW_PAIR_WINDOW_MIN = 15;
const SPW_LRN_MAX_FAILS = 40;
// A real bcrypt hash of a random throw-away string. Verifying against it when the
// LRN/password does not exist keeps the response time the same either way.
const SPW_DUMMY_HASH = '$2y$10$IMANIPD8M/KPW7VwB/NZU.9o.7crqrGPGDEyX..mBZ89WXB4OQaJu';

function spwAttemptEnsureSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('student_login_attempts_v1')) return;
    executeQuery(
        "CREATE TABLE IF NOT EXISTS student_login_attempts (
            attempt_id INT AUTO_INCREMENT PRIMARY KEY,
            lrn CHAR(12) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sla_lrn_ip (lrn, ip, created_at),
            KEY idx_sla_lrn (lrn, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    schemaMarkReady('student_login_attempts_v1');
}

function spwClientIp()
{
    return mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** True when this device (or this LRN overall) has had too many wrong tries recently. */
function spwLoginBlocked($lrn)
{
    spwAttemptEnsureSchema();
    $r = getRecord(
        "SELECT COALESCE(SUM(ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL " . (int) SPW_PAIR_WINDOW_MIN . " MINUTE)), 0) AS pair_n,
                COUNT(*) AS lrn_n
           FROM student_login_attempts
          WHERE lrn = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)",
        [spwClientIp(), (string) $lrn]
    );
    if (!$r) return false;
    return (int) $r['pair_n'] >= SPW_PAIR_MAX_FAILS || (int) $r['lrn_n'] >= SPW_LRN_MAX_FAILS;
}

/** Count one wrong try (for any typed LRN, existing or not). */
function spwLoginFailed($lrn)
{
    spwAttemptEnsureSchema();
    executeQuery("INSERT INTO student_login_attempts (lrn, ip) VALUES (?, ?)", [(string) $lrn, spwClientIp()]);
    if (random_int(1, 50) === 1) {   // housekeeping: keep the table small
        executeQuery("DELETE FROM student_login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    }
}

/** Successful sign-in: forget this device's wrong tries for the LRN and clear any old account lock. */
function spwLoginSucceeded($lrn, $studentId)
{
    spwAttemptEnsureSchema();
    executeQuery("DELETE FROM student_login_attempts WHERE lrn = ? AND ip = ?", [(string) $lrn, spwClientIp()]);
    executeQuery("UPDATE students SET portal_failed_logins = 0, portal_locked_until = NULL WHERE student_id = ?", [(int) $studentId]);
}

/** Burn the same time a real password check takes (call when there is no hash to check). */
function spwFakeVerify($pw)
{
    password_verify((string) $pw, SPW_DUMMY_HASH);
}

/** '' when the password is acceptable, otherwise the reason. */
function spwProblem($pw, $confirm, $lrn = '')
{
    $pw = (string) $pw;
    if (strlen($pw) < 8) return 'Your password must be at least 8 characters.';
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'Use both letters and numbers in your password.';
    if ($lrn !== '' && strpos($pw, $lrn) !== false) return 'Do not use your LRN as your password.';
    if ($pw !== (string) $confirm) return 'The two passwords do not match.';
    return '';
}

function spwSet($studentId, $pw)
{
    spwEnsureSchema();
    // Clears portal_password_temp and the stored ciphertext as well: this is the
    // recovery path for a student who lost the temporary password, and it must
    // not leave the account flagged for another change (which would loop them
    // straight back here) nor keep the old value readable.
    executeQuery(
        "UPDATE students SET portal_password_hash = ?, portal_password_set_at = NOW(), portal_password_temp = 0,
                portal_temp_password_enc = NULL, portal_failed_logins = 0, portal_locked_until = NULL
          WHERE student_id = ?",
        [password_hash((string) $pw, PASSWORD_DEFAULT), (int) $studentId]
    );
}

// ============================================================
// TEMPORARY-PASSWORD PROVISIONING  (automatic, on Admin approval)
// ------------------------------------------------------------
// When the Admin approves an online application the Student Portal account is
// created for the student right away:
//   - username  = the student's own 12-digit LRN (what student_login.php asks for)
//   - password  = a secure random TEMPORARY password, stored ONLY as a hash
//   - portal_password_temp = 1, so the first login is forced to Change Password
//
// The credentials are shown to the student on the Enrollment Tracking page (no
// email). Because the tracking page must keep showing the SAME temporary
// password across refreshes -- regenerating it on every refresh would break the
// password the student already noted down -- the value is ALSO stored encrypted
// with a per-install key, using AES-256-GCM authenticated encryption. It is
// never stored, logged or displayed in plain text in the database: only the
// hash (for login) and the ciphertext (for display) are written.
//
// Once the student changes the password the ciphertext is cleared, so the
// temporary password stops existing the moment it stops being needed.
// ============================================================

const SPW_TEMP_LENGTH = 12;

// Ambiguous characters (0/O, 1/I/l) are left out so a password read off an
// email or written on paper is not mistyped; length is kept comfortable.
const SPW_TEMP_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

/** A cryptographically random temporary password that satisfies spwProblem(). */
function spwTempPassword()
{
    $alphabet = SPW_TEMP_ALPHABET;
    $max = strlen($alphabet) - 1;
    $pw = '';
    for ($i = 0; $i < SPW_TEMP_LENGTH; $i++) $pw .= $alphabet[random_int(0, $max)];
    // Guarantee the "letters and numbers" rule regardless of the random draw.
    if (!preg_match('/\d/', $pw)) $pw[random_int(0, SPW_TEMP_LENGTH - 1)] = (string) random_int(0, 9);
    if (!preg_match('/[A-Za-z]/', $pw)) $pw[random_int(0, SPW_TEMP_LENGTH - 1)] = $alphabet[random_int(0, $max)];
    return $pw;
}

/**
 * Per-install encryption key for the temporary-password ciphertext.
 *
 * Generated once and kept in system_settings (never in source). Losing it only
 * means the Enrollment Tracking page can no longer re-display a temporary
 * password; it never affects signing in, because login uses the password HASH.
 */
function spwSecretKey()
{
    static $key = null;
    if ($key !== null) return $key;
    $row = getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'portal_temp_pw_key'");
    $k = trim((string) ($row['setting_value'] ?? ''));
    if (strlen($k) !== 64 || !ctype_xdigit($k)) {
        $k = bin2hex(random_bytes(32));
        executeQuery("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('portal_temp_pw_key', ?, 'portal') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$k]);
    }
    return $key = hex2bin($k);
}

/** AES-256-GCM encrypt (returns base64 of iv|tag|ciphertext), or '' on failure. */
function spwEncryptValue($plain)
{
    if (!function_exists('openssl_encrypt')) return '';
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt((string) $plain, 'aes-256-gcm', spwSecretKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) return '';
    return base64_encode($iv . $tag . $ct);
}

/** Decrypt a value made by spwEncryptValue(); '' when it cannot be trusted/read. */
function spwDecryptValue($b64)
{
    if (!function_exists('openssl_decrypt')) return '';
    $raw = base64_decode((string) $b64, true);
    if ($raw === false || strlen($raw) < 29) return '';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', spwSecretKey(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}

/**
 * Create the Student Portal credentials for an approved student.
 *
 * Runs ONCE, when the Admin approves. Re-running it is safe (same student row,
 * no duplicate account) but should not happen: the tracking page reads the
 * existing password back with spwReadTemporaryPassword() instead of calling this
 * again, so refreshing the page never rotates the password.
 *
 * @return array{success:bool, password?:string, error?:string}
 */
function spwProvisionTemporaryPassword($studentId)
{
    spwEnsureSchema();
    $student = getRecord("SELECT student_id, lrn FROM students WHERE student_id = ? AND is_active = 1", [(int) $studentId]);
    if (!$student) return ['success' => false, 'error' => 'No active student record to create a portal password for.'];

    $temp = spwTempPassword();
    $enc = spwEncryptValue($temp);
    $ok = executeQuery(
        "UPDATE students
            SET portal_password_hash = ?, portal_password_set_at = NOW(), portal_password_temp = 1,
                portal_temp_password_set_at = NOW(), portal_temp_password_enc = ?,
                portal_failed_logins = 0, portal_locked_until = NULL
          WHERE student_id = ? AND is_active = 1",
        [password_hash($temp, PASSWORD_DEFAULT), $enc !== '' ? $enc : null, (int) $student['student_id']]
    );
    if (!$ok) return ['success' => false, 'error' => 'The temporary password could not be saved.'];

    logActivity(null, 'student', 'Portal credentials created', 'Temporary password issued for LRN ' . $student['lrn']);
    return ['success' => true, 'password' => $temp];
}

/**
 * The temporary password to show on the Enrollment Tracking page, or '' when
 * there is none to show (not provisioned, already changed, or unreadable).
 * Returns '' rather than generating a new one, so a refresh is harmless.
 */
function spwReadTemporaryPassword(array $student)
{
    if (empty($student['portal_password_temp'])) return '';          // already changed
    if (empty($student['portal_temp_password_enc'])) return '';
    $plain = spwDecryptValue($student['portal_temp_password_enc']);
    return $plain !== '' ? $plain : '';
}

/**
 * The tracking page's entry point: makes sure an APPROVED student always has a
 * usable portal account, then returns what to display.
 *
 * Students approved before this feature existed have no password hash at all,
 * which used to leave them stuck on "Preparing your login" with no way in. They
 * are initialised here, ONCE: the first tracking-page view creates the
 * credentials and later views only read them back, so refreshing never rotates
 * the password. A student who already has a password is never touched.
 *
 * @return array{state:string, password?:string, lrn?:string}
 *         state: 'credentials' | 'activated' | 'unavailable'
 */
function spwEnsurePortalCredentials($studentId)
{
    spwEnsureSchema();
    $student = getRecord(
        "SELECT student_id, lrn, portal_password_hash, portal_password_temp, portal_temp_password_enc
           FROM students WHERE student_id = ? AND is_active = 1",
        [(int) $studentId]
    );
    if (!$student) return ['state' => 'unavailable'];

    // Already activated: a real password exists and the temporary flag is off.
    // Never re-provision here -- that would lock a working account out.
    if (!empty($student['portal_password_hash']) && empty($student['portal_password_temp'])) {
        return ['state' => 'activated', 'lrn' => $student['lrn']];
    }

    // Flagged temporary but the stored copy is unreadable (e.g. the per-install
    // key was rotated). Re-issue so the student is not stuck without a password.
    if (!empty($student['portal_password_temp']) && empty($student['portal_temp_password_enc'])) {
        spwProvisionTemporaryPassword($studentId);
        $student = getRecord(
            "SELECT lrn, portal_password_hash, portal_password_temp, portal_temp_password_enc FROM students WHERE student_id = ? AND is_active = 1",
            [(int) $studentId]
        ) ?: $student;
    }

    $password = spwReadTemporaryPassword($student);
    if ($password !== '') {
        return ['state' => 'credentials', 'password' => $password, 'lrn' => $student['lrn']];
    }

    // No password at all (approved before this feature): create it now, once.
    $provision = spwProvisionTemporaryPassword($studentId);
    if (!$provision['success']) return ['state' => 'unavailable'];
    return ['state' => 'credentials', 'password' => $provision['password'], 'lrn' => $student['lrn']];
}

/** Does this account still have to replace its temporary password? */
function spwMustChangePassword(array $student)
{
    return !empty($student['portal_password_temp']);
}

/**
 * Portal-wide first-login guard. Every Student Portal page calls this right
 * after its own "is a student signed in" check; while the account still holds
 * the temporary password the student is redirected to the Change Password page
 * and may not use the portal yet.
 *
 * @return bool true when the student was redirected (caller should exit)
 */
function spwRequireChangedPassword()
{
    $id = (int) ($_SESSION['student_id'] ?? 0);
    if (!$id) return false;
    $row = getRecord("SELECT portal_password_temp FROM students WHERE student_id = ? AND is_active = 1", [$id]);
    if (!spwMustChangePassword($row ?: [])) return false;
    header('Location: ../../../student/change_password.php');
    return true;
}

/**
 * Save the student's chosen password, clearing the temporary flag. This is what
 * makes the temporary password stop working: the hash it matched is replaced.
 */
function spwSetPermanentPassword($studentId, $pw)
{
    spwEnsureSchema();
    // Also clears the ciphertext: once the password has been changed there is
    // nothing left to display on the Enrollment Tracking page, and the temporary
    // password must not remain readable anywhere.
    executeQuery(
        "UPDATE students
            SET portal_password_hash = ?, portal_password_set_at = NOW(), portal_password_temp = 0,
                portal_temp_password_enc = NULL,
                portal_failed_logins = 0, portal_locked_until = NULL
          WHERE student_id = ?",
        [password_hash((string) $pw, PASSWORD_DEFAULT), (int) $studentId]
    );
}

/** "Your Student Portal password was created" -- sent when it was made from the tracking page (no email link). */
function spwNotifyCreated($studentId)
{
    $st = getRecord("SELECT first_name, lrn, email FROM students WHERE student_id = ?", [(int) $studentId]);
    if (!$st || !filter_var((string) $st['email'], FILTER_VALIDATE_EMAIL)) return;
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $forgot = appUrl('student/forgot_password.php');
    $html = '<div style="background:#f1f4f8;padding:28px 12px;font-family:Segoe UI,Arial,sans-serif;">'
        . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">'
        . '<div style="background:#14213D;padding:20px 28px;color:#fff;font-size:18px;font-weight:700;">EGradePro Student Portal</div>'
        . '<div style="padding:28px;font-size:15px;color:#334155;line-height:1.6;">'
        . '<h2 style="margin:0 0 14px;font-size:21px;color:#14213D;">Your password was created</h2>'
        . '<p>Hi ' . $e($st['first_name']) . ', a password for your Student Portal (LRN ' . $e($st['lrn']) . ') was just created on ' . date('M j, Y g:i A') . '. You can now sign in with your LRN and this password.</p>'
        . '<p><strong>Was this not you?</strong> Set a new password right away using <a href="' . $e($forgot) . '">Create / Forgot Password</a>, and tell the registrar.</p>'
        . '</div></div></div>';
    mailSendOne($st['email'], 'Your EGradePro Student Portal password was created', $html);
}
