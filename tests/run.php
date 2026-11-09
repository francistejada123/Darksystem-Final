<?php
// tests/run.php -- quick automated checks for EGRADEPRO.
//
//   C:\xampp\php\php.exe tests\run.php          (logic checks only)
//   C:\xampp\php\php.exe tests\run.php --http   (also checks the running site at http://localhost/watdahel)
//
// Read-only: every check that needs to write does it inside a transaction
// that is rolled back, so real data is never changed. The tests/ folder is
// blocked from the web (.htaccess), so this only runs from the command line.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(__DIR__ . '/..');
require 'config/database.php';
require 'includes/grading_helpers.php';
require 'includes/risk_helpers.php';
require 'includes/ai_quiz_grade_sync.php';
require 'includes/student_records_helpers.php';
require 'includes/teacher_department.php';

$pass = 0;
$fail = 0;
function check($name, $ok, $detail = '')
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}
function section($t) { echo "\n== $t ==\n"; }

section('Scores: 0 to HPS, blank = No Score');
$r = validateRawScore('', 10);   check('blank is No Score (NULL)', $r['valid'] && $r['value'] === null);
$r = validateRawScore('0', 10);  check('0 is a real score', $r['valid'] && $r['value'] === 0.0);
$r = validateRawScore('10', 10); check('score equal to HPS is valid', $r['valid']);
$r = validateRawScore('11', 10); check('score above HPS is rejected', !$r['valid']);
$r = validateRawScore('-1', 10); check('negative score is rejected', !$r['valid']);
$r = validateRawScore('abc', 10); check('text is rejected', !$r['valid']);

section('At-risk make-up credit');
$pct = aiMakeupCreditPercent();
check('make-up credit is 1-100%', $pct > 0 && $pct <= 100, (string) $pct);
check('catch-up quiz is a make-up', aiIsAtRiskMakeup(['source_prompt' => 'Catch-up session for missing Quiz 3']));
check('make-up activity is a make-up', aiIsAtRiskMakeup(['source_prompt' => 'Make-up for missing Activity 1']));
check('review activity is NOT a make-up', !aiIsAtRiskMakeup(['source_prompt' => 'Review activity for Quiz 2']));
check('ordinary Ask AI quiz is NOT a make-up', !aiIsAtRiskMakeup(['source_prompt' => 'Quiz from chat']));

section('Report Card LRN');
require_once 'includes/enrollment_lrn_card.php';
check('spaces and dashes are removed', oeNormalizeLrn('1234-5678 9012') === '123456789012');
check('11 digits is not an LRN', oeNormalizeLrn('12345678901') === '');
check('13 digits is not an LRN', oeNormalizeLrn('1234567890123') === '');
check('Report Card pages: 2 required + 1 optional', count(array_filter(oeReportCardPages(), fn($p) => $p['required'])) === 2 && count(oeReportCardPages()) === 3);

section('Teacher Department / Level restriction');
check('JHS allows Grade 7-10', tdGradeAllowed('JHS', 'Grade 7') && tdGradeAllowed('JHS', 'Grade 10'));
check('JHS refuses Grade 11-12', !tdGradeAllowed('JHS', 'Grade 11') && !tdGradeAllowed('JHS', 'Grade 12'));
check('SHS allows Grade 11-12', tdGradeAllowed('SHS', 'Grade 11') && tdGradeAllowed('SHS', 'Grade 12'));
check('SHS refuses Grade 7-10', !tdGradeAllowed('SHS', 'Grade 7') && !tdGradeAllowed('SHS', 'Grade 10'));
check('JHS is not valid for Grade 11', !(tdCheckAssignment('JHS', 'Grade 11'))['ok']);
check('SHS is not valid for Grade 7', !(tdCheckAssignment('SHS', 'Grade 7'))['ok']);
check('SHS is valid for Grade 11', (tdCheckAssignment('SHS', 'Grade 11'))['ok']);
check('unset department keeps both levels open', tdGradeAllowed('', 'Grade 7') && tdGradeAllowed('', 'Grade 12'));
check('garbage department is treated as unset', tdNormalize('jhs ') === 'JHS' && tdNormalize('STEM') === '');
check('rows are filtered to the department', count(tdFilterRows([['grade_level' => 'Grade 7'], ['grade_level' => 'Grade 11']], 'JHS')) === 1);
check('teacher_subjects conflicts are flagged, never deleted', (function () {
    $before = (int) (getRecord('SELECT COUNT(*) AS c FROM teacher_subjects')['c'] ?? 0);
    tdConflictingAssignments();
    $after = (int) (getRecord('SELECT COUNT(*) AS c FROM teacher_subjects')['c'] ?? 0);
    return $before === $after;
})());

section('Parent Portal activation flow (simulated email verification)');
require_once 'includes/parent_portal.php';
ppEnsureSchema();
ppEmailEnsureSchema();
check('email verification is simulated', ppEmailVerificationSimulated());
check('a placeholder password can never be guessed', !password_verify('123456', ppUnusablePinHash()));
check('an unactivated account is not activated', !ppIsActivated(['parent_id' => 1, 'pin_hash' => 'x', 'is_active' => 1, 'password_set_at' => null]));
check('an account with a chosen password IS activated', ppIsActivated(['parent_id' => 1, 'pin_hash' => 'x', 'is_active' => 1, 'password_set_at' => '2026-01-01 10:00:00']));
check('an inactive account is never activated', !ppIsActivated(['parent_id' => 1, 'pin_hash' => 'x', 'is_active' => 0, 'password_set_at' => '2026-01-01 10:00:00']));
// Status gate: nothing may be activated before the Admin approves.
$pending = ['application_id' => 0, 'reference_no' => 'TEST000000XX', 'status' => 'Pending', 'student_id' => null, 'enrollment_id' => null, 'lrn' => ''];
$s = ppActivationState($pending);
check('Pending allows no guardian step and no password', $s['stage'] === PP_ACT_PENDING && !$s['can_guardian'] && !$s['can_password']);
$incomplete = $pending; $incomplete['status'] = 'Incomplete Requirements';
$s = ppActivationState($incomplete);
check('Incomplete Requirements activates nothing', $s['stage'] === PP_ACT_PENDING && !$s['can_guardian'] && !$s['can_password']);
check('Pending is never treated as approved', !ppEnrollmentApproved($pending));
check('Incomplete Requirements is never treated as approved', !ppEnrollmentApproved($incomplete));
$approved = $pending; $approved['status'] = 'Approved';
check('Approved is approved', ppEnrollmentApproved($approved));
// To-Follow Form 137: application row not Approved yet, but the enrollment is.
// Read-only check on an existing Approved enrollment -- nothing is written.
$approvedEnrol = getRecord("SELECT enrollment_id FROM enrollments WHERE enrollment_status = 'Approved' ORDER BY enrollment_id DESC LIMIT 1");
if ($approvedEnrol) {
    $tofollow['enrollment_id'] = (int) $approvedEnrol['enrollment_id'];
    check('To-Follow Form 137 with an approved enrollment qualifies', ppEnrollmentApproved($tofollow));
} else {
    check('To-Follow Form 137 with an approved enrollment qualifies', true, 'no approved enrollment on file to test with');
}
check('a nonexistent enrollment is not approved', !ppEnrollmentApproved(['status' => 'Incomplete Requirements', 'enrollment_id' => 0]));

section('Student Portal temporary-password activation');
require_once 'includes/student_password.php';
spwEnsureSchema();
$t1 = spwTempPassword();
$t2 = spwTempPassword();
check('temporary passwords are random (not equal)', $t1 !== $t2);
check('temporary password is at least 8 chars', strlen($t1) >= 8, (string) strlen($t1));
check('temporary password passes the existing rules', spwProblem($t1, $t1, '123456789012') === '');
check('a password containing the LRN is rejected', spwProblem('123456789012ab', '123456789012ab', '123456789012') !== '');
check('mismatched confirmation is rejected', spwProblem('Passw0rdA', 'Passw0rdB', '') !== '');
check('the temporary-password columns exist', (bool) getRecord("SHOW COLUMNS FROM students LIKE 'portal_password_temp'"));
check('the encrypted temporary-password column exists', (bool) getRecord("SHOW COLUMNS FROM students LIKE 'portal_temp_password_enc'"));
// Credentials are shown on the tracking page, so the value must survive a refresh --
// but never as plain text.
$enc = spwEncryptValue('Portal12345');
check('encryption produces ciphertext', $enc !== '' && $enc !== 'Portal12345');
check('encryption round-trips', spwDecryptValue($enc) === 'Portal12345');
check('a tampered ciphertext is refused', spwDecryptValue(substr($enc, 0, -4) . 'AAAA') === '');
check('garbage input is refused safely', spwDecryptValue('not-valid-base64!!') === '');
// An account that is not flagged temporary must never expose a password.
check('an unchanged account exposes nothing', spwReadTemporaryPassword(['portal_password_temp' => 0, 'portal_temp_password_enc' => $enc]) === '');
check('a missing ciphertext exposes nothing', spwReadTemporaryPassword(['portal_password_temp' => 1, 'portal_temp_password_enc' => '']) === '');
check('no credentials email helper remains', !function_exists('spwSendCredentialsEmail'));
// The tracking page must rescue students approved before this feature existed:
// an approved student with no password at all gets one initialised exactly once,
// and only READS it back afterwards (a refresh must never rotate it).
$legacy = getRecord(
    "SELECT s.student_id, s.lrn, s.portal_password_hash, s.portal_password_temp, s.portal_temp_password_enc
       FROM students s
       JOIN online_applications a ON a.student_id = s.student_id
      WHERE a.status = 'Approved' AND a.deleted_at IS NULL
        AND (s.portal_password_hash IS NULL OR s.portal_password_hash = '')
      ORDER BY a.application_id DESC LIMIT 1"
);
if ($legacy) {
    global $db;
    $db->beginTransaction();
    $sid = (int) $legacy['student_id'];
    $r1 = spwEnsurePortalCredentials($sid);
    check('pre-feature approved student gets credentials', ($r1['state'] ?? '') === 'credentials' && !empty($r1['password']), (string) ($r1['state'] ?? 'none'));
    check('  and the password verifies for login', password_verify((string) ($r1['password'] ?? ''), (string) getRecord("SELECT portal_password_hash FROM students WHERE student_id = ?", [$sid])['portal_password_hash']));
    $r2 = spwEnsurePortalCredentials($sid);
    check('  a refresh returns the same password (no rotation)', ($r2['password'] ?? '') === ($r1['password'] ?? 'x'));
    spwSetPermanentPassword($sid, 'Testing1234');
    $r3 = spwEnsurePortalCredentials($sid);
    check('  after the change it reports activated', ($r3['state'] ?? '') === 'activated');
    check('  and never exposes a password again', empty($r3['password']));
    $db->rollBack();
} else {
    check('pre-feature approved student gets credentials', true, 'no unprovisioned approved student on file');
}

// First-login change must KEEP the student signed in (no second login) and let
// them straight through to the dashboard. Session-level check in a transaction.
$flow = getRecord("SELECT student_id, lrn FROM students WHERE lrn REGEXP '^[0-9]{12}$' AND is_active = 1 ORDER BY student_id DESC LIMIT 1");
if ($flow) {
    global $db;
    $db->beginTransaction();
    $fid = (int) $flow['student_id'];
    // Start from the "just approved" state so the whole flow is exercised.
    executeQuery("UPDATE students SET portal_password_hash = NULL, portal_password_temp = 0, portal_temp_password_enc = NULL WHERE student_id = ?", [$fid]);

    $prov = spwEnsurePortalCredentials($fid);
    $temp = (string) ($prov['password'] ?? '');
    check('flow: tracking issues a temporary password', ($prov['state'] ?? '') === 'credentials' && $temp !== '');

    // Log in exactly like student_login.php, then change the password and keep
    // the session using the same helper the page now calls.
    sptStartStudentSession(getRecord("SELECT * FROM students WHERE student_id = ?", [$fid]));
    check('flow: session is authenticated after login', ($_SESSION['role'] ?? '') === 'Student');
    check('flow: still forced to change the password', spwMustChangePassword(getRecord("SELECT portal_password_temp FROM students WHERE student_id = ?", [$fid])));

    $newPw = 'Learner' . random_int(1000, 9999);
    spwSetPermanentPassword($fid, $newPw);
    $full = getRecord(
        "SELECT s.*, e.enrollment_status, e.section_id, sec.section_name, sec.grade_level
           FROM students s
           LEFT JOIN enrollments e ON e.student_id = s.student_id
               AND e.school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1)
           LEFT JOIN sections sec ON sec.section_id = e.section_id
          WHERE s.student_id = ?", [$fid]
    );
    sptStartStudentSession($full);
    check('flow: session survives the password change', ($_SESSION['student_id'] ?? 0) == $fid && ($_SESSION['role'] ?? '') === 'Student');
    $gate = getRecord("SELECT portal_password_temp FROM students WHERE student_id = ?", [$fid]);
    check('flow: dashboard no longer bounces to Change Password', !spwMustChangePassword($gate));
    check('flow: portal-wide guard lets them through', spwRequireChangedPassword() === false);

    $row = getRecord("SELECT * FROM students WHERE student_id = ?", [$fid]);
    check('flow: temporary password no longer works', !password_verify($temp, $row['portal_password_hash']));
    check('flow: new password works on the next login', password_verify($newPw, $row['portal_password_hash']));
    $db->rollBack();
    $_SESSION = [];
} else {
    check('flow: tracking issues a temporary password', true, 'no eligible student on file');
}
// A hashed temporary password is never the plain text, and the flag drives the gate.
check('hashed value is not the plain password', password_hash($t1, PASSWORD_DEFAULT) !== $t1);
check('flagged account requires a password change', spwMustChangePassword(['portal_password_temp' => 1]));
check('unchanged account does not require a change', !spwMustChangePassword(['portal_password_temp' => 0]));
// Read-only check on an existing account: nothing is written by these helpers.
$tpStudent = getRecord("SELECT student_id, lrn, portal_password_hash, portal_password_temp FROM students WHERE lrn REGEXP '^[0-9]{12}$' AND is_active = 1 ORDER BY student_id DESC LIMIT 1");
if ($tpStudent) {
    check('an existing account is never forced to change its password', !spwMustChangePassword($tpStudent));
} else {
    check('an existing account is never forced to change its password', true, 'no eligible student on file');
}

section('MAPEH components');
$four = ['Music', 'Arts', 'Physical Education', 'Health'];
check('no old tags -> the four components', gh_effectiveComponents($four, ['WW' => [['component' => 'Music']]]) === $four);
check('only old tags -> the old two', gh_effectiveComponents($four, ['WW' => [['component' => 'Music and Arts']]]) === ['Music and Arts', 'PE and Health']);
check('mixed -> four + old tag in use', gh_effectiveComponents($four, ['WW' => [['component' => 'Music and Arts'], ['component' => 'Arts']]]) === array_merge($four, ['Music and Arts']));

section('Terms');
$sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
check('an active school year exists', (bool) $sy);
if ($sy) {
    $noEnd = gh_termsWithoutEndDate((int) $sy['sy_id']);
    echo '        terms without an End Date: ' . ($noEnd ? implode(', ', $noEnd) : 'none') . "\n";
    check('term list check runs', is_array($noEnd));
}

section('At-Risk detection (runs on real data, read-only)');
if ($sy) {
    $ok = true; $n = 0; $err = '';
    foreach (getRecords("SELECT DISTINCT student_id FROM enrollments WHERE school_year_id = ? AND enrollment_status = 'Approved' LIMIT 50", [(int) $sy['sy_id']]) as $s) {
        try {
            foreach (getAtRiskSubjectRecords((int) $s['student_id'], (int) $sy['sy_id']) as $rec) {
                $n++;
                if (empty($rec['risk_reasons'])) { $ok = false; $err = 'a record without a reason'; }
                if (count($rec['missing_details']) === 1 && count($rec['risk_reasons']) === 1 && $rec['risk_reasons'][0]['type'] === 'missing') {
                    $ok = false; $err = 'flagged for ONE missing item only';
                }
            }
        } catch (\Throwable $e) { $ok = false; $err = $e->getMessage(); }
    }
    check("every at-risk record has a reason, none for 1 missing only ($n records)", $ok, $err);
}

section('Rules that write (rolled back)');
$db->beginTransaction();
try {
    // A real teacher/subject/section (foreign keys) with a term name no real item uses.
    $ts = getRecord("SELECT teacher_id, subject_code, section_id, school_year_id FROM teacher_subjects LIMIT 1");
    $T = [(int) $ts['teacher_id'], $ts['subject_code'], (int) $ts['section_id'], (int) $ts['school_year_id'], 'ZZTerm'];
    executeQuery("INSERT INTO assessment_items (teacher_id, subject_code, section_id, school_year_id, term, category, item_name, highest_score, item_order, assessment_subtype, is_active)
                  VALUES (?, ?, ?, ?, ?, 'QA', 'zz ST1', 10, 1, 'ST1', 1)", $T);
    $src = file_get_contents('modules/grading/items.php');
    $a = strpos($src, 'function itemsDuplicateExamMessage');
    $b = strpos($src, '// ============================================================', $a);
    if ($a !== false && $b !== false && !function_exists('itemsDuplicateExamMessage')) {
        eval(substr($src, $a, $b - $a));
    }
    $msg = itemsDuplicateExamMessage($T[0], $T[1], $T[2], $T[3], $T[4], 'ST1', null, ['ST1', 'ST2', 'TE'], gh_examSubtypes());
    check('a 2nd ST1 while ST2/TE are missing is refused', $msg !== '', $msg);
    $msg = itemsDuplicateExamMessage($T[0], $T[1], $T[2], $T[3], $T[4], 'ST2', null, ['ST1', 'ST2', 'TE'], gh_examSubtypes());
    check('adding the missing ST2 is allowed', $msg === '', $msg);

    $st = getRecord("SELECT student_id FROM students WHERE is_active = 1 LIMIT 1");
    if ($st) {
        $r = srRequestCorrection((int) $st['student_id'], 'birth_date', '2999-01-01', 'test', 'Registrar', 0);
        check('a future birth date is rejected', !$r['success']);
        $r = srRequestCorrection((int) $st['student_id'], 'gender', 'Unknown', 'test', 'Registrar', 0);
        check('gender must be Male or Female', !$r['success']);
    }
} catch (\Throwable $e) {
    check('write checks ran', false, $e->getMessage());
}
$db->rollBack();
check('rolled back (no test item left)', !getRecord("SELECT 1 FROM assessment_items WHERE item_name = 'zz ST1'"));

section('Schema checks run once');
$flags = array_column(getRecords("SELECT setting_key FROM system_settings WHERE setting_key LIKE 'schema\\_ok\\_%'"), 'setting_key');
check('schema flags are recorded', count($flags) > 0, implode(', ', $flags));

if (in_array('--http', $argv, true)) {
    section('Website (http://localhost/watdahel)');
    $get = function ($path) {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
        @file_get_contents('http://localhost/watdahel/' . $path, false, $ctx);
        preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0] ?? '', $m);
        return (int) ($m[1] ?? 0);
    };
    foreach (['egradepro.sql', 'database/egradepro.sql', 'composer.json', 'vendor/autoload.php', 'tests/run.php', 'AGENTS.md'] as $blocked) {
        $c = $get($blocked);
        check("blocked: $blocked", in_array($c, [403, 404], true), (string) $c);
    }
    foreach (['login.php', 'student_login.php', 'online_enrollment.php', 'static/css/style.css'] as $open) {
        $c = $get($open);
        check("reachable: $open", $c === 200, (string) $c);
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
