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
    if (schemaIsReady('student_portal_password_v1')) return;
    foreach ([
        'portal_password_hash' => "VARCHAR(255) NULL DEFAULT NULL COMMENT 'Student Portal password (hash only)'",
        'portal_password_set_at' => "DATETIME NULL DEFAULT NULL",
        'portal_failed_logins' => "INT NOT NULL DEFAULT 0",
        'portal_locked_until' => "DATETIME NULL DEFAULT NULL",
    ] as $col => $def) {
        if (!getRecord("SHOW COLUMNS FROM students LIKE '$col'")) executeQuery("ALTER TABLE students ADD COLUMN $col $def");
    }
    schemaMarkReady('student_portal_password_v1');
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
    executeQuery(
        "UPDATE students SET portal_password_hash = ?, portal_password_set_at = NOW(), portal_failed_logins = 0, portal_locked_until = NULL WHERE student_id = ?",
        [password_hash((string) $pw, PASSWORD_DEFAULT), (int) $studentId]
    );
}

/** Minutes left on a lock (0 = not locked). */
function spwLockedMinutes(array $student)
{
    if (empty($student['portal_locked_until'])) return 0;
    $left = strtotime($student['portal_locked_until']) - time();
    return $left > 0 ? (int) ceil($left / 60) : 0;
}

/** Check the password; counts wrong tries and locks the account for a while after too many. */
function spwVerify(array $student, $pw)
{
    spwEnsureSchema();
    $ok = !empty($student['portal_password_hash']) && password_verify((string) $pw, $student['portal_password_hash']);
    if ($ok) {
        executeQuery("UPDATE students SET portal_failed_logins = 0, portal_locked_until = NULL WHERE student_id = ?", [(int) $student['student_id']]);
    } else {
        $fails = (int) ($student['portal_failed_logins'] ?? 0) + 1;
        executeQuery(
            "UPDATE students SET portal_failed_logins = ?, portal_locked_until = IF(? >= ?, DATE_ADD(NOW(), INTERVAL " . (int) SPW_LOCK_MINUTES . " MINUTE), portal_locked_until) WHERE student_id = ?",
            [$fails >= SPW_MAX_FAILS ? 0 : $fails, $fails, SPW_MAX_FAILS, (int) $student['student_id']]
        );
    }
    return $ok;
}

/** j***z@gmail.com */
function spwMaskEmail($email)
{
    $email = (string) $email;
    if (strpos($email, '@') === false) return '';
    [$u, $d] = explode('@', $email, 2);
    return mb_substr($u, 0, 1) . str_repeat('*', max(2, mb_strlen($u) - 2)) . (mb_strlen($u) > 1 ? mb_substr($u, -1) : '') . '@' . $d;
}

/**
 * Email the student a one-time "Create your password" link.
 * @return array{success:bool, error?:string, masked?:string}
 */
function spwSendSetupLink(array $student, $purpose = 'setup')
{
    spwEnsureSchema();
    $email = trim((string) ($student['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'No email address is saved for this student.'];
    }
    $link = sptAccessUrl(sptIssue((int) $student['student_id'], $purpose === 'reset' ? 'reset' : 'setup'));
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $title = $purpose === 'reset' ? 'Reset your Student Portal password' : 'Create your Student Portal password';
    $html = '<div style="background:#f1f4f8;padding:28px 12px;font-family:Segoe UI,Arial,sans-serif;">'
        . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">'
        . '<div style="background:#14213D;padding:20px 28px;color:#fff;font-size:18px;font-weight:700;">EGradePro Student Portal</div>'
        . '<div style="padding:28px;">'
        . '<h2 style="margin:0 0 14px;font-size:21px;color:#14213D;">' . $e($title) . '</h2>'
        . '<p style="font-size:15px;color:#334155;line-height:1.6;">Hi ' . $e($student['first_name'] ?? '') . ', click the button below to ' . ($purpose === 'reset' ? 'set a new' : 'create your') . ' password. You will sign in with your <strong>LRN ' . $e($student['lrn'] ?? '') . '</strong> and this password.</p>'
        . '<p style="margin:22px 0;"><a href="' . $e($link) . '" style="display:inline-block;background:#2563eb;color:#ffffff !important;text-decoration:none;font-weight:700;font-size:15px;padding:12px 24px;border-radius:8px;">🔐 ' . ($purpose === 'reset' ? 'Set New Password' : 'Create My Password') . '</a></p>'
        . '<p style="font-size:12.5px;color:#64748b;">This link is only for you, works once and expires in ' . SPT_VALID_DAYS . ' days. If you did not ask for it, you can ignore this email.<br>Link: <span style="word-break:break-all;">' . $e($link) . '</span></p>'
        . '</div></div></div>';
    $r = mailSendOne($email, $title . ' - EGradePro', $html);
    return $r['success'] ? ['success' => true, 'masked' => spwMaskEmail($email)] : ['success' => false, 'error' => $r['error']];
}
