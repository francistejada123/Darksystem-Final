<?php
// includes/parent_portal.php
// Parent Portal: parents/guardians see their child's grades, attendance, tasks
// and class updates (read-only).
//
// Account = the parent's mobile number + a 6-digit PIN (no email needed).
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
        'wrong_login' => ['Mali ang cellphone number o PIN.', 'Wrong mobile number or PIN.'],
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
 * $in: code, birth_date, mobile, pin, confirm_pin, full_name, relationship
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
        if (($p = ppPinProblem($in['pin'] ?? '', $in['confirm_pin'] ?? '')) !== '') return ['success' => false, 'error' => $p];
        $name = mb_substr(trim((string) ($in['full_name'] ?? '')), 0, 150);
        $email = trim((string) ($in['email'] ?? ''));
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        $parentId = (int) insertRecord(
            "INSERT INTO parents (mobile, full_name, email, pin_hash, lang) VALUES (?, ?, ?, ?, ?)",
            [$mobile, $name ?: null, $email, password_hash((string) $in['pin'], PASSWORD_DEFAULT), ppLang()]
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
    return getRecords(
        "SELECT p.parent_id, p.mobile, p.full_name, p.last_login, p.is_active, ps.relationship, ps.linked_via, ps.linked_at
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

// ---------------------------------------------------------------- forgot PIN (6-digit code by SMS)
// The parent types their number, gets a 6-digit code by text (no link: the
// networks block texts with links), types it, then chooses a new PIN.
// Requests for numbers without an account are recorded too (no code, no
// text), so the screen and the limits are the same either way.
const PP_OTP_MINUTES = 10;    // a code works this long
const PP_OTP_TRIES = 5;       // wrong tries before the code stops working
const PP_OTP_GAP = 60;        // seconds between two codes for one number
const PP_OTP_PER_HOUR = 3;    // codes per number per hour

function ppOtpEnsureSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('parent_pin_otp_v1')) return;
    try {
        executeQuery(
            "CREATE TABLE IF NOT EXISTS parent_pin_otps (
                otp_id INT AUTO_INCREMENT PRIMARY KEY,
                mobile VARCHAR(11) NOT NULL,
                parent_id INT NULL COMMENT 'NULL when the number has no account (nothing was texted)',
                code_hash VARCHAR(255) NULL COMMENT '6-digit code (hash only)',
                attempts INT NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_otp_mobile (mobile, created_at),
                CONSTRAINT fk_otp_parent FOREIGN KEY (parent_id) REFERENCES parents (parent_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        schemaMarkReady('parent_pin_otp_v1');
    } catch (\Throwable $e) {
        error_log('ppOtpEnsureSchema: ' . $e->getMessage());
    }
}

/** Seconds before this number may get another code (0 = now). */
function ppOtpWait($mobile)
{
    ppOtpEnsureSchema();
    $r = getRecord("SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS ago, SUM(created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS hr FROM parent_pin_otps WHERE mobile = ?", [$mobile]);
    if (!$r || $r['ago'] === null) return 0;
    if ((int) $r['hr'] >= PP_OTP_PER_HOUR) {
        $first = getRecord("SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MIN(created_at), INTERVAL 1 HOUR)) AS s FROM (SELECT created_at FROM parent_pin_otps WHERE mobile = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) ORDER BY created_at DESC LIMIT " . (int) PP_OTP_PER_HOUR . ") t", [$mobile]);
        return max(1, (int) ($first['s'] ?? 60));
    }
    return max(0, PP_OTP_GAP - (int) $r['ago']);
}

/**
 * Send a new code to this number (only when it has an active account).
 * @return array{ok:bool, wait:int}
 */
function ppOtpRequest($mobile)
{
    ppOtpEnsureSchema();
    $wait = ppOtpWait($mobile);
    if ($wait > 0) return ['ok' => false, 'wait' => $wait];
    executeQuery("UPDATE parent_pin_otps SET used_at = NOW() WHERE mobile = ? AND used_at IS NULL", [$mobile]);   // one code at a time
    $p = getRecord("SELECT parent_id, lang FROM parents WHERE mobile = ? AND is_active = 1", [$mobile]);
    $code = $p ? sprintf('%06d', random_int(0, 999999)) : null;
    executeQuery("INSERT INTO parent_pin_otps (mobile, parent_id, code_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL " . (int) PP_OTP_MINUTES . " MINUTE))",
                 [$mobile, $p ? (int) $p['parent_id'] : null, $code ? password_hash($code, PASSWORD_DEFAULT) : null]);
    if ($p) {
        // Plain letters only and no link: one SMS part, and the networks deliver it.
        $text = ppLang() === 'en'
            ? 'EGradePro: Your code for a new Parent Portal PIN is ' . $code . '. Valid for ' . PP_OTP_MINUTES . ' minutes. Do not give it to anyone, even school staff.'
            : 'EGradePro: Ang code mo para sa bagong PIN ng Parent Portal ay ' . $code . '. Valid ng ' . PP_OTP_MINUTES . ' minuto. Huwag itong ibigay kaninuman, kahit sa school staff.';
        smsSend($mobile, $text, ['kind' => 'otp']);
        logActivity(null, 'parents', 'Parent PIN code sent', 'Forgot PIN code texted to ' . smsMask($mobile));
    } else {
        logActivity(null, 'student', 'Failed login', 'Parent Forgot PIN for a number without an account (' . smsMask($mobile) . ')');
    }
    return ['ok' => true, 'wait' => PP_OTP_GAP];
}

/**
 * Check a typed code. On success returns the parent_id (the code is used up).
 * @return array{ok:bool, parent_id?:int, left?:int, dead?:bool}
 */
function ppOtpVerify($mobile, $code)
{
    ppOtpEnsureSchema();
    $row = getRecord("SELECT * FROM parent_pin_otps WHERE mobile = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY otp_id DESC LIMIT 1", [$mobile]);
    if (!$row) return ['ok' => false, 'dead' => true];
    if ($row['code_hash'] && preg_match('/^\d{6}$/', (string) $code) && password_verify((string) $code, $row['code_hash'])) {
        executeQuery("UPDATE parent_pin_otps SET used_at = NOW() WHERE otp_id = ?", [(int) $row['otp_id']]);
        return ['ok' => true, 'parent_id' => (int) $row['parent_id']];
    }
    $tries = (int) $row['attempts'] + 1;
    executeQuery("UPDATE parent_pin_otps SET attempts = ?, used_at = IF(? >= ?, NOW(), NULL) WHERE otp_id = ?", [$tries, $tries, PP_OTP_TRIES, (int) $row['otp_id']]);
    logActivity(null, 'student', 'Failed login', 'Parent Forgot PIN: wrong code for ' . smsMask($mobile));
    return ['ok' => false, 'left' => max(0, PP_OTP_TRIES - $tries), 'dead' => $tries >= PP_OTP_TRIES];
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
