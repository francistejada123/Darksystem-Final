<?php
// includes/parent_portal.php
// Parent Portal: parents/guardians see their child's grades, attendance, tasks
// and class updates (read-only).
//
// Account = the parent's mobile number (or their checked email) + a 6-digit PIN.
// A parent is linked to a child with a one-time SETUP CODE (8 characters,
// expires in 7 days, stored only as a hash). The code reaches the parent by:
//   - SMS when the enrollment is approved (includes/sms.php),
//   - the pop-up on the Enrollment Tracking page (same device that applied),
//   - a printed Parent Access Slip (registrar / adviser), or the child's Student Portal,
//   - setup at school, helped by staff (the parent types the PIN).
// Setup also asks the child's birthday. One parent may have several children;
// a child may have several parents. The school never sees the PIN.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mail_config.php';   // appUrl()
require_once __DIR__ . '/sms.php';

const PP_CODE_DAYS = 7;
const PP_MAX_FAILS = 5;
const PP_LOCK_MINUTES = 15;
const PP_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';   // no 0/O, 1/I/L

function ppEnsureSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('parent_portal_v1')) return;
    executeQuery(
        "CREATE TABLE IF NOT EXISTS parents (
            parent_id INT AUTO_INCREMENT PRIMARY KEY,
            mobile VARCHAR(11) NOT NULL,
            full_name VARCHAR(150) NULL,
            email VARCHAR(100) NULL,
            pin_hash VARCHAR(255) NOT NULL COMMENT '6-digit PIN (hash only)',
            failed_logins INT NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            lang CHAR(2) NOT NULL DEFAULT 'tl',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_login DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_parent_mobile (mobile)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    executeQuery(
        "CREATE TABLE IF NOT EXISTS parent_students (
            link_id INT AUTO_INCREMENT PRIMARY KEY,
            parent_id INT NOT NULL,
            student_id INT NOT NULL,
            relationship VARCHAR(40) NULL,
            linked_via VARCHAR(20) NULL,
            linked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_parent_student (parent_id, student_id),
            KEY idx_ps_student (student_id),
            CONSTRAINT fk_ps_parent FOREIGN KEY (parent_id) REFERENCES parents (parent_id) ON DELETE CASCADE,
            CONSTRAINT fk_ps_student FOREIGN KEY (student_id) REFERENCES students (student_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    executeQuery(
        "CREATE TABLE IF NOT EXISTS parent_setup_codes (
            code_id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            code_hash CHAR(64) NOT NULL COMMENT 'sha256 of the code (the code itself is never stored)',
            channel VARCHAR(20) NOT NULL DEFAULT 'slip' COMMENT 'sms / tracking / slip / student / school',
            mobile_hint VARCHAR(11) NULL COMMENT 'guardian number from the enrollment, pre-filled on the setup page',
            created_by INT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            used_by_parent INT NULL,
            revoked_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_psc_hash (code_hash),
            KEY idx_psc_student (student_id),
            CONSTRAINT fk_psc_student FOREIGN KEY (student_id) REFERENCES students (student_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    schemaMarkReady('parent_portal_v1');
}

// ---------------------------------------------------------------- language
/** Tagalog (default) or English, per device and per parent. */
function ppLang()
{
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['tl', 'en'], true)) {
        $_SESSION['pp_lang'] = $_GET['lang'];
        setcookie('pp_lang', $_GET['lang'], ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
        if (!empty($_SESSION['parent_id'])) executeQuery("UPDATE parents SET lang = ? WHERE parent_id = ?", [$_GET['lang'], (int) $_SESSION['parent_id']]);
    }
    $l = $_SESSION['pp_lang'] ?? ($_COOKIE['pp_lang'] ?? 'tl');
    return in_array($l, ['tl', 'en'], true) ? $l : 'tl';
}

function ppT($key)
{
    static $d = [
        'portal' => ['Parent Portal', 'Parent Portal'],
        'home' => ['Home', 'Home'], 'grades' => ['Grades', 'Grades'], 'attendance' => ['Attendance', 'Attendance'],
        'tasks' => ['Gawain', 'Tasks'], 'updates' => ['Balita', 'Updates'],
        'login_title' => ['Mag-login sa Parent Portal', 'Sign in to the Parent Portal'],
        'mobile' => ['Cellphone number', 'Mobile number'], 'pin' => ['6-digit PIN', '6-digit PIN'],
        'login' => ['Mag-login', 'Sign in'], 'logout' => ['Mag-logout', 'Sign out'],
        'forgot_pin' => ['Nakalimutan ang PIN?', 'Forgot your PIN?'],
        'first_time' => ['Unang beses? Mayroon kang setup code?', 'First time? Have a setup code?'],
        'setup' => ['I-setup ang account', 'Set up my account'],
        'wrong_login' => ['Mali ang number/email o PIN.', 'Wrong mobile number/email or PIN.'],
        'login_id' => ['Cellphone number o Email', 'Mobile number or Email'],
        'account' => ['Aking Account', 'My Account'],
        'locked' => ['Maraming maling subok. Subukan ulit pagkalipas ng %d minuto.', 'Too many wrong tries. Try again in %d minute(s).'],
        'child' => ['Anak', 'Child'], 'choose_child' => ['Piliin ang anak', 'Choose child'], 'add_child' => ['Magdagdag ng anak', 'Add a child'],
        'section' => ['Section', 'Section'], 'adviser' => ['Adviser', 'Adviser'],
        'gen_ave' => ['General Average', 'General Average'], 'att_rate' => ['Attendance', 'Attendance'],
        'pending' => ['Hindi pa tapos', 'Pending'], 'missing' => ['Hindi naipasa', 'Missing'],
        'no_grades' => ['Wala pang grades na nailalabas.', 'No grades released yet.'],
        'no_att' => ['Wala pang attendance record.', 'No attendance records yet.'],
        'no_tasks' => ['Walang gawain sa ngayon.', 'No tasks right now.'],
        'no_updates' => ['Wala pang balita mula sa mga guro.', 'No updates from teachers yet.'],
        'at_risk' => ['Paalala: mababa ang grade sa %d subject. Kausapin ang adviser.', 'Reminder: low grade in %d subject(s). Please talk to the adviser.'],
        'present' => ['Pumasok', 'Present'], 'late' => ['Late', 'Late'], 'absent' => ['Absent', 'Absent'], 'excused' => ['Excused', 'Excused'],
        'subject' => ['Subject', 'Subject'], 'term' => ['Term', 'Term'], 'final' => ['Final', 'Final'],
        'due' => ['Due', 'Due'], 'status' => ['Status', 'Status'],
        'read_only' => ['Para makita lang. Hindi ito mapapalitan dito.', 'View only. Nothing can be changed here.'],
        'add_home' => ['Idagdag sa Home Screen', 'Add to Home Screen'],
        'welcome_to' => ['Maligayang pagdating sa', 'Welcome to'],
        'welcome_sub' => ['Katuwang ninyo sa pag-aaral ng inyong anak.', "Your partner in your child's education journey."],
        'f_grades' => ['Tingnan ang Grades', 'Check Grades'], 'f_grades_d' => ['Makita ang progreso ng anak sa pag-aaral', "View your child's academic progress"],
        'f_att' => ['Bantayan ang Attendance', 'Track Attendance'], 'f_att_d' => ['Alamin kung pumapasok ang anak araw-araw', 'Know if your child is in school every day'],
        'f_conn' => ['Laging Updated', 'Stay Connected'], 'f_conn_d' => ['Mga gawain at balita mula sa mga guro', 'Tasks and announcements from teachers'],
        'login_sub' => ['Makita ang records, grades, at balita ng inyong anak kahit kailan, kahit saan.', "Access your child's records, grades, and school updates anytime, anywhere."],
    ];
    $i = ppLang() === 'en' ? 1 : 0;
    return $d[$key][$i] ?? $key;
}

// ---------------------------------------------------------------- codes
function ppNormCode($raw)
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $raw));
}

function ppFormatCode($code)
{
    return substr($code, 0, 4) . '-' . substr($code, 4);
}

/** New one-time setup code for a child. @return string the code (shown once; only its hash is stored) */
function ppIssueCode($studentId, $channel, $mobileHint = null, $createdBy = null)
{
    ppEnsureSchema();
    $code = '';
    for ($i = 0; $i < 8; $i++) $code .= PP_CODE_ALPHABET[random_int(0, strlen(PP_CODE_ALPHABET) - 1)];
    executeQuery(
        "INSERT INTO parent_setup_codes (student_id, code_hash, channel, mobile_hint, created_by, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL " . (int) PP_CODE_DAYS . " DAY))",
        [(int) $studentId, hash('sha256', $code), mb_substr((string) $channel, 0, 20), smsNormalizeNumber($mobileHint) ?: null, $createdBy]
    );
    return $code;
}

/** A mobile number as typed: exactly 11 digits starting with 09 (spaces/dashes ignored), or ''. */
function ppStrictMobile($raw)
{
    $d = preg_replace('/[\s-]/', '', (string) $raw);
    return preg_match('/^09\d{9}$/', $d) ? $d : '';
}

function ppMobileFormatError()
{
    return ppLang() === 'en'
        ? 'The mobile number must be exactly 11 digits and start with 09 (e.g. 09171234567).'
        : 'Dapat 11 digits ang cellphone number at nagsisimula sa 09 (hal. 09171234567).';
}

/** Active learners whose guardian number on record is this number. */
function ppStudentsByGuardian($mobile)
{
    $rows = getRecords(
        "SELECT student_id, first_name, last_name, grade_level, guardian_contact FROM students
          WHERE is_active = 1 AND guardian_contact LIKE ?", ['%' . substr($mobile, -7) . '%']) ?: [];
    return array_values(array_filter($rows, function ($r) use ($mobile) { return smsNormalizeNumber($r['guardian_contact']) === $mobile; }));
}

/**
 * "Create account" with the number only: when the number is the guardian
 * number on record, a setup code is made for each such child right away
 * (older self-made codes of that child and number stop working). The child's
 * birthday and the email code on the next steps prove it is the parent.
 * @return array{ok:bool, reason?:string, wait?:int, kids?:array}
 */
function ppSelfServeCodes($mobile, $exceptParentId = 0)
{
    ppEnsureSchema();
    $kids = ppStudentsByGuardian($mobile);
    if ($exceptParentId) {   // "Add a child": only children not linked to this account yet
        $linked = array_column(getRecords("SELECT student_id FROM parent_students WHERE parent_id = ?", [(int) $exceptParentId]) ?: [], 'student_id');
        $kids = array_values(array_filter($kids, function ($k) use ($linked) { return !in_array($k['student_id'], $linked); }));
    }
    if (!$kids) return ['ok' => false, 'reason' => 'none'];
    $recent = getRecord("SELECT COUNT(*) AS n FROM parent_setup_codes WHERE channel = 'self' AND mobile_hint = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [$mobile]);
    if ((int) ($recent['n'] ?? 0) >= 3 * count($kids)) return ['ok' => false, 'reason' => 'limit'];
    $out = [];
    foreach ($kids as $k) {
        executeQuery("UPDATE parent_setup_codes SET revoked_at = NOW() WHERE student_id = ? AND channel = 'self' AND mobile_hint = ? AND used_at IS NULL AND revoked_at IS NULL", [(int) $k['student_id'], $mobile]);
        $k['code'] = ppIssueCode((int) $k['student_id'], 'self', $mobile);
        $out[] = $k;
    }
    logActivity(null, 'parents', 'Parent setup code', 'Self-serve setup code for ' . smsMask($mobile) . ' (' . count($out) . ' child/children)');
    return ['ok' => true, 'kids' => $out];
}

/** The short setup link printed on slips and sent by SMS. */
function ppSetupLink($code)
{
    return appUrl('m.php?c=' . $code);
}

/** Valid (unused, unexpired) code with its child, or null. */
function ppFindCode($raw)
{
    ppEnsureSchema();
    $code = ppNormCode($raw);
    if (strlen($code) !== 8) return null;
    $row = getRecord(
        "SELECT c.*, s.first_name, s.last_name, s.birth_date, s.grade_level, s.is_active
           FROM parent_setup_codes c JOIN students s ON s.student_id = c.student_id
          WHERE c.code_hash = ? AND c.used_at IS NULL AND c.revoked_at IS NULL AND c.expires_at > NOW()",
        [hash('sha256', $code)]
    );
    return ($row && (int) $row['is_active'] === 1) ? $row : null;
}

function ppRevokeCodes($studentId)
{
    ppEnsureSchema();
    executeQuery("UPDATE parent_setup_codes SET revoked_at = NOW() WHERE student_id = ? AND used_at IS NULL AND revoked_at IS NULL", [(int) $studentId]);
}

// ---------------------------------------------------------------- accounts
function ppPinProblem($pin, $confirm)
{
    $pin = (string) $pin;
    if (!preg_match('/^\d{6}$/', $pin)) return ppLang() === 'en' ? 'The PIN must be exactly 6 numbers.' : 'Dapat 6 na numero ang PIN.';
    if (preg_match('/^(\d)\1{5}$/', $pin) || in_array($pin, ['123456', '654321', '012345', '543210'], true)) {
        return ppLang() === 'en' ? 'That PIN is too easy to guess. Choose another.' : 'Masyadong madaling hulaan ang PIN na iyan. Pumili ng iba.';
    }
    if ($pin !== (string) $confirm) return ppLang() === 'en' ? 'The two PINs do not match.' : 'Hindi magkapareho ang dalawang PIN.';
    return '';
}

/**
 * Finish setup: check the code + child's birthday, create (or reuse) the parent account, link the child.
 * $in: code, birth_date, mobile, pin, confirm_pin, full_name, relationship,
 *      email (+ email_verified), or pin_hash instead of pin/confirm_pin (setup after the email check)
 * @return array{success:bool, error?:string, parent?:array, existing?:bool}
 */
function ppRegister(array $in)
{
    ppEnsureSchema();
    $en = ppLang() === 'en';
    $c = ppFindCode($in['code'] ?? '');
    if (!$c) return ['success' => false, 'error' => $en ? 'This setup code is invalid, already used or expired. Ask the school for a new one.' : 'Hindi valid, nagamit na, o expired ang setup code. Humingi ng bago sa school.'];
    $bd = trim((string) ($in['birth_date'] ?? ''));
    if ($bd === '' || strtotime($bd) === false || date('Y-m-d', strtotime($bd)) !== date('Y-m-d', strtotime($c['birth_date']))) {
        logActivity(null, 'student', 'Failed login', 'Parent setup: wrong birthday for the setup code of student #' . $c['student_id']);   // counts toward the per-device limit
        return ['success' => false, 'error' => $en ? "The child's birthday does not match our record." : 'Hindi tugma ang birthday ng anak sa record ng school.'];
    }
    $mobile = smsNormalizeNumber($in['mobile'] ?? '');
    if ($mobile === '') return ['success' => false, 'error' => $en ? 'Enter a valid mobile number (09XXXXXXXXX).' : 'Ilagay ang tamang cellphone number (09XXXXXXXXX).'];
    $rel = mb_substr(trim((string) ($in['relationship'] ?? '')), 0, 40);

    $parent = getRecord("SELECT * FROM parents WHERE mobile = ?", [$mobile]);
    if ($parent) {
        // Existing account: prove it with the current PIN, then add this child.
        if (($m = ppLockedMinutes($parent)) > 0) return ['success' => false, 'error' => sprintf(ppT('locked'), $m)];
        if (!ppCheckPin($parent, $in['pin'] ?? '')) return ['success' => false, 'error' => $en ? 'This number already has an account. Enter its current PIN to add this child.' : 'May account na ang number na ito. Ilagay ang kasalukuyang PIN para maidagdag ang anak.', 'existing' => true];
        $parentId = (int) $parent['parent_id'];
    } else {
        if (empty($in['pin_hash']) && ($p = ppPinProblem($in['pin'] ?? '', $in['confirm_pin'] ?? '')) !== '') return ['success' => false, 'error' => $p];
        $name = mb_substr(trim((string) ($in['full_name'] ?? '')), 0, 150);
        ppEmailEnsureSchema();
        $email = ppNormEmail($in['email'] ?? '') ?: null;
        if ($email && ppEmailTaken($email)) return ['success' => false, 'error' => $en ? 'This email is already used by another Parent Portal account.' : 'Ginagamit na ng ibang Parent Portal account ang email na ito.'];
        $parentId = (int) insertRecord(
            "INSERT INTO parents (mobile, full_name, email, email_verified_at, pin_hash, lang) VALUES (?, ?, ?, ?, ?, ?)",
            [$mobile, $name ?: null, $email, $email && !empty($in['email_verified']) ? date('Y-m-d H:i:s') : null,
             !empty($in['pin_hash']) ? $in['pin_hash'] : password_hash((string) $in['pin'], PASSWORD_DEFAULT), ppLang()]
        );
        if (!$parentId) return ['success' => false, 'error' => $en ? 'The account could not be created. Please try again.' : 'Hindi nagawa ang account. Pakisubukan ulit.'];
    }
    executeQuery("INSERT IGNORE INTO parent_students (parent_id, student_id, relationship, linked_via) VALUES (?, ?, ?, ?)",
                 [$parentId, (int) $c['student_id'], $rel ?: null, $c['channel']]);
    executeQuery("UPDATE parent_setup_codes SET used_at = NOW(), used_by_parent = ? WHERE code_id = ? AND used_at IS NULL", [$parentId, (int) $c['code_id']]);
    logActivity(null, 'parents', 'Parent linked', 'Parent ' . smsMask($mobile) . ' linked to ' . $c['last_name'] . ', ' . $c['first_name'] . ' via ' . $c['channel']);
    return ['success' => true, 'parent' => getRecord("SELECT * FROM parents WHERE parent_id = ?", [$parentId]), 'existing' => (bool) $parent];
}

function ppLockedMinutes(array $p)
{
    if (empty($p['locked_until'])) return 0;
    $left = strtotime($p['locked_until']) - time();
    return $left > 0 ? (int) ceil($left / 60) : 0;
}

function ppCheckPin(array $p, $pin)
{
    $ok = password_verify((string) $pin, $p['pin_hash']);
    if ($ok) {
        executeQuery("UPDATE parents SET failed_logins = 0, locked_until = NULL WHERE parent_id = ?", [(int) $p['parent_id']]);
    } else {
        $f = (int) $p['failed_logins'] + 1;
        executeQuery("UPDATE parents SET failed_logins = ?, locked_until = IF(? >= ?, DATE_ADD(NOW(), INTERVAL " . (int) PP_LOCK_MINUTES . " MINUTE), locked_until) WHERE parent_id = ?",
                     [$f >= PP_MAX_FAILS ? 0 : $f, $f, PP_MAX_FAILS, (int) $p['parent_id']]);
    }
    return $ok;
}

function ppStartSession(array $parent)
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $lang = $_SESSION['pp_lang'] ?? null;
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['parent_id'] = (int) $parent['parent_id'];
    $_SESSION['role'] = 'Parent';
    $_SESSION['pp_lang'] = $lang ?: ($parent['lang'] ?? 'tl');
    $_SESSION['last_activity'] = time();
    executeQuery("UPDATE parents SET last_login = NOW() WHERE parent_id = ?", [(int) $parent['parent_id']]);
}

/** Children linked to a parent (active students), with section + adviser. */
function ppChildren($parentId)
{
    return getRecords(
        "SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.grade_level, ps.relationship,
                sec.section_name, CONCAT(adv.first_name, ' ', adv.last_name) AS adviser_name
           FROM parent_students ps
           JOIN students s ON s.student_id = ps.student_id AND s.is_active = 1
           LEFT JOIN enrollments e ON e.student_id = s.student_id AND e.school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1)
           LEFT JOIN sections sec ON sec.section_id = e.section_id
           LEFT JOIN users adv ON adv.user_id = sec.adviser_id
          WHERE ps.parent_id = ?
          ORDER BY s.first_name",
        [(int) $parentId]
    );
}

/** Parents linked to a child (for the school and the Student Portal). Never the PIN. */
function ppParentsOf($studentId)
{
    ppEnsureSchema();
    ppEmailEnsureSchema();
    return getRecords(
        "SELECT p.parent_id, p.mobile, p.email, p.full_name, p.last_login, p.is_active, ps.relationship, ps.linked_via, ps.linked_at
           FROM parent_students ps JOIN parents p ON p.parent_id = ps.parent_id
          WHERE ps.student_id = ? ORDER BY ps.linked_at",
        [(int) $studentId]
    );
}

/** For parent pages: the signed-in parent, their children and the chosen child (or redirect to login). */
function ppRequireParent()
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    ppEnsureSchema();
    $pid = (int) ($_SESSION['parent_id'] ?? 0);
    $parent = $pid ? getRecord("SELECT * FROM parents WHERE parent_id = ? AND is_active = 1", [$pid]) : null;
    if (!$parent || ($_SESSION['role'] ?? '') !== 'Parent') {
        header('Location: login.php');
        exit;
    }
    $kids = ppChildren($pid);
    if (isset($_GET['child'])) $_SESSION['pp_child'] = (int) $_GET['child'];
    $sel = null;
    foreach ($kids as $k) {
        if ((int) $k['student_id'] === (int) ($_SESSION['pp_child'] ?? 0)) $sel = $k;
    }
    if (!$sel && $kids) { $sel = $kids[0]; $_SESSION['pp_child'] = (int) $sel['student_id']; }
    return [$parent, $kids, $sel];
}

// ---------------------------------------------------------------- email: sign-in, verify, forgot PIN
// A parent signs in with their mobile number OR their email, plus the PIN.
// The email is checked with a 6-digit code before it is saved (setup and
// "Aking Account"), and Forgot PIN sends a 6-digit code to it. Codes are kept
// as hashes: 10 minutes, 5 wrong tries, 60 s between codes, 3 per hour.
// Requests for unknown accounts are recorded too (nothing is sent), so the
// screens and the limits are the same either way.
const PP_OTP_MINUTES = 10;
const PP_OTP_TRIES = 5;
const PP_OTP_GAP = 60;
const PP_OTP_PER_HOUR = 3;

function ppEmailEnsureSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    ppEnsureSchema();
    if (schemaIsReady('parent_email_v1')) return;
    try {
        executeQuery("UPDATE parents SET email = NULLIF(LOWER(TRIM(email)), '')");
        if (!getRecord("SHOW COLUMNS FROM parents LIKE 'email_verified_at'")) {
            executeQuery("ALTER TABLE parents ADD COLUMN email_verified_at DATETIME NULL AFTER email");
        }
        if (!getRecord("SHOW INDEX FROM parents WHERE Key_name = 'uniq_parent_email'")) {
            // Older duplicates (none expected): keep the first, clear the rest.
            executeQuery("UPDATE parents p JOIN parents q ON q.email = p.email AND q.parent_id < p.parent_id SET p.email = NULL");
            executeQuery("ALTER TABLE parents ADD UNIQUE KEY uniq_parent_email (email)");
        }
        executeQuery(
            "CREATE TABLE IF NOT EXISTS parent_email_codes (
                code_id INT AUTO_INCREMENT PRIMARY KEY,
                purpose VARCHAR(10) NOT NULL COMMENT 'reset | setup | change',
                target VARCHAR(120) NOT NULL COMMENT 'what the limits count (email, or what was typed)',
                email VARCHAR(100) NULL COMMENT 'where the code went (NULL: nothing was sent)',
                parent_id INT NULL,
                code_hash VARCHAR(255) NULL COMMENT '6-digit code (hash only)',
                attempts INT NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_pec_target (target, created_at),
                CONSTRAINT fk_pec_parent FOREIGN KEY (parent_id) REFERENCES parents (parent_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        schemaMarkReady('parent_email_v1');
    } catch (\Throwable $e) {
        error_log('ppEmailEnsureSchema: ' . $e->getMessage());
    }
}

/** Lower-case email, or '' when it is not a valid address. */
function ppNormEmail($raw)
{
    $e = strtolower(trim((string) $raw));
    return (strlen($e) <= 100 && filter_var($e, FILTER_VALIDATE_EMAIL)) ? $e : '';
}

/** ju••••@gmail.com */
function ppMaskEmail($email)
{
    $email = (string) $email;
    $at = strpos($email, '@');
    if ($at === false) return '';
    return mb_substr($email, 0, min(2, $at)) . '••••' . substr($email, $at);
}

/** The parent account for what was typed at sign-in: an email (has "@") or a mobile number. */
function ppFindParentByLogin($login)
{
    ppEmailEnsureSchema();
    $login = trim((string) $login);
    if (strpos($login, '@') !== false) {
        $e = ppNormEmail($login);
        return $e !== '' ? getRecord("SELECT * FROM parents WHERE email = ? AND is_active = 1", [$e]) : null;
    }
    $m = smsNormalizeNumber($login);
    return $m !== '' ? getRecord("SELECT * FROM parents WHERE mobile = ? AND is_active = 1", [$m]) : null;
}

/** Is this email already used by another parent account? */
function ppEmailTaken($email, $exceptParentId = 0)
{
    ppEmailEnsureSchema();
    return (bool) getRecord("SELECT 1 FROM parents WHERE email = ? AND parent_id <> ?", [$email, (int) $exceptParentId]);
}

function ppMailReady()
{
    require_once __DIR__ . '/mail_config.php';
    return mailConfigProblem(mailConfig()) === null;
}

/** Email in the Parent Portal look (short, plain). */
function ppMailHtml($title, $bodyHtml)
{
    return '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;color:#14213D">'
        . '<div style="background:#14213D;color:#fff;padding:16px 20px;border-radius:12px 12px 0 0;font-weight:700;font-size:18px">EGradePro · Parent Portal</div>'
        . '<div style="border:1px solid #E3E9F2;border-top:0;border-radius:0 0 12px 12px;padding:22px 20px">'
        . '<h2 style="margin:0 0 12px;font-size:20px">' . $title . '</h2>' . $bodyHtml
        . '<p style="color:#52607A;font-size:13px;margin-top:22px">Hindi hihingi ang school ng code o PIN ninyo. / The school will never ask for your code or PIN.</p></div></div>';
}

function ppMailSend($to, $subject, $title, $bodyHtml)
{
    require_once __DIR__ . '/mail_config.php';
    $r = mailSendOne($to, $subject, ppMailHtml($title, $bodyHtml));
    if (!$r['success']) error_log('Parent Portal email failed: ' . ($r['error'] ?? ''));
    return $r['success'];
}

/** Seconds before this target may get another code (0 = now). */
function ppCodeWait($target)
{
    ppEmailEnsureSchema();
    $r = getRecord("SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS ago, SUM(created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS hr FROM parent_email_codes WHERE target = ?", [$target]);
    if (!$r || $r['ago'] === null) return 0;
    if ((int) $r['hr'] >= PP_OTP_PER_HOUR) {
        $first = getRecord("SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MIN(created_at), INTERVAL 1 HOUR)) AS s FROM (SELECT created_at FROM parent_email_codes WHERE target = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) ORDER BY created_at DESC LIMIT " . (int) PP_OTP_PER_HOUR . ") t", [$target]);
        return max(1, (int) ($first['s'] ?? 60));
    }
    return max(0, PP_OTP_GAP - (int) $r['ago']);
}

/**
 * Make a 6-digit code and email it. $email null = nothing is sent (unknown
 * account), but the request still counts toward the limits.
 * @return array{ok:bool, wait:int, sent?:bool}
 */
function ppCodeSend($purpose, $target, $email, $parentId = null)
{
    ppEmailEnsureSchema();
    $target = strtolower(trim((string) $target));
    $wait = ppCodeWait($target);
    if ($wait > 0) return ['ok' => false, 'wait' => $wait];
    executeQuery("UPDATE parent_email_codes SET used_at = NOW() WHERE target = ? AND used_at IS NULL", [$target]);   // one code at a time
    $code = $email ? sprintf('%06d', random_int(0, 999999)) : null;
    executeQuery("INSERT INTO parent_email_codes (purpose, target, email, parent_id, code_hash, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL " . (int) PP_OTP_MINUTES . " MINUTE))",
                 [$purpose, $target, $email ?: null, $parentId ?: null, $code ? password_hash($code, PASSWORD_DEFAULT) : null]);
    $sent = false;
    if ($email) {
        $en = ppLang() === 'en';
        $what = [
            'reset' => $en ? 'to choose a new PIN' : 'para gumawa ng bagong PIN',
            'setup' => $en ? 'to confirm this email for your Parent Portal account' : 'para kumpirmahin ang email na ito sa inyong Parent Portal account',
            'change' => $en ? 'to confirm your new email' : 'para kumpirmahin ang bagong email',
        ][$purpose] ?? '';
        $sent = ppMailSend($email,
            ($en ? 'EGradePro Parent Portal code: ' : 'EGradePro Parent Portal code: ') . $code,
            $en ? 'Your code' : 'Ang inyong code',
            '<p style="font-size:15px">' . ($en ? 'Use this code ' : 'Gamitin ang code na ito ') . $what . ':</p>'
            . '<div style="font-size:34px;font-weight:800;letter-spacing:8px;background:#F1F5F9;border-radius:12px;padding:14px;text-align:center;margin:10px 0">' . $code . '</div>'
            . '<p style="font-size:15px">' . sprintf($en ? 'It works for %d minutes. If you did not ask for this, ignore this email.' : 'Gagana ito sa loob ng %d minuto. Kung hindi kayo ang humingi nito, huwag pansinin ang email na ito.', PP_OTP_MINUTES) . '</p>');
        logActivity(null, 'parents', 'Parent email code', ucfirst($purpose) . ' code emailed to ' . ppMaskEmail($email) . ($sent ? '' : ' (NOT sent)'));
    }
    return ['ok' => true, 'wait' => PP_OTP_GAP, 'sent' => $sent];
}

/**
 * Check a typed code for this target. On success the code is used up.
 * @return array{ok:bool, parent_id?:int, email?:string, left?:int, dead?:bool}
 */
function ppCodeVerify($purpose, $target, $code)
{
    ppEmailEnsureSchema();
    $target = strtolower(trim((string) $target));
    $row = getRecord("SELECT * FROM parent_email_codes WHERE target = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY code_id DESC LIMIT 1", [$target, $purpose]);
    if (!$row) return ['ok' => false, 'dead' => true];
    if ($row['code_hash'] && preg_match('/^\d{6}$/', (string) $code) && password_verify((string) $code, $row['code_hash'])) {
        executeQuery("UPDATE parent_email_codes SET used_at = NOW() WHERE code_id = ?", [(int) $row['code_id']]);
        return ['ok' => true, 'parent_id' => (int) $row['parent_id'], 'email' => (string) $row['email']];
    }
    $tries = (int) $row['attempts'] + 1;
    executeQuery("UPDATE parent_email_codes SET attempts = ?, used_at = IF(? >= ?, NOW(), NULL) WHERE code_id = ?", [$tries, $tries, PP_OTP_TRIES, (int) $row['code_id']]);
    logActivity(null, 'student', 'Failed login', 'Parent Portal: wrong ' . $purpose . ' code');   // counts toward the per-device limit
    return ['ok' => false, 'left' => max(0, PP_OTP_TRIES - $tries), 'dead' => $tries >= PP_OTP_TRIES];
}

/** Save a checked email on an account (and tell the old address when it changes). */
function ppSetEmail($parentId, $email)
{
    ppEmailEnsureSchema();
    $old = getRecord("SELECT email FROM parents WHERE parent_id = ?", [(int) $parentId]);
    try {
        executeQuery("UPDATE parents SET email = ?, email_verified_at = NOW() WHERE parent_id = ?", [$email, (int) $parentId]);
    } catch (\Throwable $e) {
        return false;   // UNIQUE: taken meanwhile
    }
    if (!empty($old['email']) && $old['email'] !== $email) {
        $en = ppLang() === 'en';
        ppMailSend($old['email'], 'EGradePro Parent Portal: email changed', $en ? 'Your email was changed' : 'Napalitan ang inyong email',
            '<p style="font-size:15px">' . sprintf($en ? 'Your Parent Portal account now uses %s. If you did not do this, contact the school right away.' : 'Ang Parent Portal account ninyo ay gumagamit na ng %s. Kung hindi kayo ang gumawa nito, makipag-ugnayan agad sa school.', htmlspecialchars(ppMaskEmail($email))) . '</p>');
    }
    logActivity(null, 'parents', 'Parent email saved', 'Parent #' . (int) $parentId . ': ' . ppMaskEmail($email));
    return true;
}

/** "Your PIN was changed" notice (after Forgot PIN). */
function ppMailPinChanged($email)
{
    if (!$email) return;
    $en = ppLang() === 'en';
    ppMailSend($email, 'EGradePro Parent Portal: PIN changed', $en ? 'Your PIN was changed' : 'Napalitan ang inyong PIN',
        '<p style="font-size:15px">' . ($en ? 'The PIN of your Parent Portal account was changed on ' : 'Napalitan ang PIN ng inyong Parent Portal account noong ') . date('M j, Y g:i A') . '. '
        . ($en ? 'If you did not do this, contact the school right away.' : 'Kung hindi kayo ang gumawa nito, makipag-ugnayan agad sa school.') . '</p>');
}

// ---------------------------------------------------------------- data (same sources as the Student Portal)
/** Encoded grades of a child this school year: [term => [rows]], plus the average. */
function ppGrades($studentId)
{
    require_once __DIR__ . '/grading_helpers.php';
    $sy = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
    $rows = getRecords(
        "SELECT g.term, g.final_grade, g.remarks, sub.subject_name, sub.subject_code
           FROM grades g JOIN subjects sub ON sub.subject_code = g.subject_code
          WHERE g.student_id = ? AND g.school_year_id = ? AND " . gh_encodedTermSql('g') . "
          ORDER BY g.term, sub.subject_name",
        [(int) $studentId, (int) ($sy['sy_id'] ?? 0)]
    );
    $by = []; $sum = 0; $n = 0; $low = [];
    foreach ($rows as $r) {
        $by[$r['term']][] = $r;
        if ((float) $r['final_grade'] > 0) {
            $sum += (float) $r['final_grade']; $n++;
            if ((float) $r['final_grade'] < 75) $low[$r['subject_code']] = true;
        }
    }
    return ['by_term' => $by, 'average' => $n ? round($sum / $n, 2) : 0, 'low_subjects' => count($low)];
}

// ---------------------------------------------------------------- messages
/** Short welcome text (fits one SMS when the link is short). */
function ppWelcomeText(array $student, $code)
{
    $sec = trim(($student['grade_level'] ?? '') . ' ' . ($student['section_name'] ?? ''));
    return 'EGradePro: Naka-enroll na si ' . $student['first_name'] . ($sec !== '' ? ' (' . $sec . ')' : '') . '. Parent Portal: '
         . ppSetupLink($code) . ' Code: ' . $code . '. Wala kaming hinihinging pera o OTP.';
}

/**
 * When an enrollment is approved: make a setup code for the guardian number and text it.
 * @return array{sent:bool, status:string, error?:string, number:string, text:string}
 */
function ppWelcomeOnApproval($studentId, $createdBy = null)
{
    ppEnsureSchema();
    $st = getRecord(
        "SELECT s.student_id, s.first_name, s.last_name, s.guardian_contact, s.grade_level, sec.section_name
           FROM students s
           LEFT JOIN enrollments e ON e.student_id = s.student_id AND e.school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1)
           LEFT JOIN sections sec ON sec.section_id = e.section_id
          WHERE s.student_id = ?", [(int) $studentId]);
    $num = $st ? smsNormalizeNumber($st['guardian_contact']) : '';
    if (!$st || $num === '') return ['sent' => false, 'status' => 'Not sent', 'error' => 'No valid parent/guardian mobile number on file.', 'number' => '', 'text' => ''];
    if (ppParentsOf($studentId)) return ['sent' => false, 'status' => 'Not sent', 'error' => 'A parent is already linked.', 'number' => $num, 'text' => ''];
    $code = ppIssueCode($studentId, 'sms', $num, $createdBy);
    $text = ppWelcomeText($st, $code);
    $r = smsSend($num, $text, ['kind' => 'welcome', 'student_id' => $studentId, 'created_by' => $createdBy]);
    return ['sent' => $r['success'], 'status' => $r['status'], 'error' => $r['error'] ?? null, 'number' => $num, 'text' => $text];
}

/** "Absent today" to the parents linked to this child (once per day). */
function ppAbsentAlert($studentId, $date = null)
{
    try {
        ppEnsureSchema();
        $date = $date ?: date('Y-m-d');
        $st = getRecord("SELECT first_name FROM students WHERE student_id = ?", [(int) $studentId]);
        if (!$st) return;
        foreach (ppParentsOf($studentId) as $p) {
            if (!(int) $p['is_active']) continue;
            smsSend($p['mobile'], 'EGradePro: Si ' . $st['first_name'] . ' ay naitalang absent ngayong ' . date('M j', strtotime($date)) . '. Tingnan ang Parent Portal para sa detalye.',
                    ['kind' => 'absent', 'student_id' => (int) $studentId, 'dedupe_key' => 'absent:' . (int) $studentId . ':' . $date . ':' . $p['mobile']]);
        }
    } catch (\Throwable $e) {
        error_log('ppAbsentAlert: ' . $e->getMessage());
    }
}
