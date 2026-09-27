<?php
// includes/teacher_invitations.php
// New-teacher invitation flow:
//   Admin enters the teacher's official email  -> tiInvite()
//   Teacher opens the link in that email        -> teacher_register.php (tiFind())
//   Teacher fills in their own details + password -> tiRegister() creates the account,
//   marks the invitation used and sends the Teacher Portal access email.
//
// The link carries only a random token. Only its SHA-256 hash is stored, it
// expires, works once, and the account is always created with the invited
// email (not whatever is typed in a form). The admin never sees the password.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mail_config.php';          // mailSendOne(), appUrl()
require_once __DIR__ . '/teacher_validation.php';   // tvValidate()

const TI_VALID_DAYS = 7;
const TI_EMPLOYMENT_OPTIONS = ['Permanent', 'Contractual', 'Part-time', 'Substitute'];

function tiEnsureSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('teacher_invitations_v1')) return;
    executeQuery(
        "CREATE TABLE IF NOT EXISTS teacher_invitations (
            invite_id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(100) NOT NULL,
            token_hash CHAR(64) NOT NULL COMMENT 'sha256 of the token in the email link (the token itself is never stored)',
            invited_by INT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            revoked_at DATETIME NULL,
            user_id INT NULL COMMENT 'teacher account created from this invitation',
            email_sent TINYINT(1) NOT NULL DEFAULT 0,
            email_error VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ti_hash (token_hash),
            KEY idx_ti_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    foreach ([
        'employee_id' => "VARCHAR(50) NULL DEFAULT NULL",
        'department_level' => "VARCHAR(10) NULL DEFAULT NULL",
        'employment_status' => "VARCHAR(30) NULL DEFAULT NULL COMMENT 'Permanent / Contractual / Part-time / Substitute'",
    ] as $col => $def) {
        if (!getRecord("SHOW COLUMNS FROM users LIKE '$col'")) executeQuery("ALTER TABLE users ADD COLUMN $col $def");
    }
    schemaMarkReady('teacher_invitations_v1');
}

/** Pending / Active (registered) / Expired / Cancelled. */
function tiStatus(array $inv)
{
    if (!empty($inv['used_at'])) return 'Active';
    if (!empty($inv['revoked_at'])) return 'Cancelled';
    if (strtotime($inv['expires_at']) < time()) return 'Expired';
    return 'Pending Account Setup';
}

/** Branded email body used by both emails. */
function tiEmailHtml($title, $lines, $buttonText, $buttonUrl, $footnote = '')
{
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $body = '';
    foreach ($lines as $l) $body .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#334155;">' . $l . '</p>';
    return '<div style="background:#f1f4f8;padding:28px 12px;font-family:Segoe UI,Arial,sans-serif;">'
        . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">'
        . '<div style="background:#14213D;padding:20px 28px;color:#fff;font-size:18px;font-weight:700;">EGradePro</div>'
        . '<div style="padding:28px;">'
        . '<h2 style="margin:0 0 16px;font-size:21px;color:#14213D;">' . $e($title) . '</h2>'
        . $body
        . '<p style="margin:22px 0;"><a href="' . $e($buttonUrl) . '" style="display:inline-block;background:#2563eb;color:#ffffff !important;text-decoration:none;font-weight:700;font-size:15px;padding:12px 24px;border-radius:8px;">' . $e($buttonText) . '</a></p>'
        . '<p style="margin:0;font-size:12.5px;color:#64748b;line-height:1.5;">If the button does not work, copy this link into your browser:<br><span style="word-break:break-all;">' . $e($buttonUrl) . '</span></p>'
        . ($footnote !== '' ? '<p style="margin:14px 0 0;font-size:12.5px;color:#64748b;">' . $footnote . '</p>' : '')
        . '</div></div></div>';
}

/**
 * Invite (or re-invite) a teacher by email. Older unused links for the same
 * email are cancelled. @return array{success:bool, error?:string, email_error?:string}
 */
function tiInvite($email, $adminId)
{
    tiEnsureSchema();
    $email = strtolower(trim((string) $email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'error' => 'Please enter a valid email address.'];
    if (getRecord("SELECT user_id FROM users WHERE LOWER(email) = ?", [$email])) {
        return ['success' => false, 'error' => 'This email already has an EGradePro account.'];
    }
    if ($owner = diEmailOwner($email)) {
        return ['success' => false, 'error' => 'This email is already used by ' . $owner . '. Use the teacher\'s own official email.'];
    }
    executeQuery("UPDATE teacher_invitations SET revoked_at = NOW() WHERE email = ? AND used_at IS NULL AND revoked_at IS NULL", [$email]);
    $raw = bin2hex(random_bytes(32));
    executeQuery(
        "INSERT INTO teacher_invitations (email, token_hash, invited_by, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL " . (int) TI_VALID_DAYS . " DAY))",
        [$email, hash('sha256', $raw), $adminId ?: null]
    );
    $inviteId = (int) getRecord("SELECT invite_id FROM teacher_invitations WHERE token_hash = ?", [hash('sha256', $raw)])['invite_id'];

    $link = appUrl('teacher_register.php?token=' . $raw);
    $html = tiEmailHtml(
        'Welcome to EGradePro',
        ['You have been invited to create your EGradePro Teacher Account. Please complete your teacher information using the secure link below.',
         'This link is for <strong>' . htmlspecialchars($email) . '</strong> only, works once, and expires in ' . TI_VALID_DAYS . ' days.'],
        'Complete Teacher Registration',
        $link,
        'If you were not expecting this invitation, you can ignore this email.'
    );
    $r = mailSendOne($email, 'Welcome to EGradePro - Complete Your Teacher Registration', $html);
    executeQuery("UPDATE teacher_invitations SET email_sent = ?, email_error = ? WHERE invite_id = ?",
        [$r['success'] ? 1 : 0, $r['success'] ? null : mb_substr((string) $r['error'], 0, 255), $inviteId]);
    logActivity($adminId, 'teachers', 'Invited new teacher', "Registration link sent to $email" . ($r['success'] ? '' : ' (email failed: ' . $r['error'] . ')'));
    return $r['success'] ? ['success' => true] : ['success' => true, 'email_error' => $r['error']];
}

/** The valid (pending, unexpired) invitation for this token, or null. */
function tiFind($raw)
{
    tiEnsureSchema();
    $raw = (string) $raw;
    if (!preg_match('/^[a-f0-9]{64}$/', $raw)) return null;
    $inv = getRecord("SELECT * FROM teacher_invitations WHERE token_hash = ?", [hash('sha256', $raw)]);
    if (!$inv || tiStatus($inv) !== 'Pending Account Setup') return null;
    return $inv;
}

/** A free username made from the email (the teacher signs in with the email). */
function tiUsername($email)
{
    $base = preg_replace('/[^a-z0-9._]/', '', strtolower(strstr($email, '@', true))) ?: 'teacher';
    $base = mb_substr($base, 0, 40);
    $name = $base;
    for ($i = 2; getRecord("SELECT user_id FROM users WHERE username = ?", [$name]); $i++) $name = $base . $i;
    return $name;
}

/**
 * Create the teacher account from the registration form.
 * @return array{success:bool, errors?:string[], portal_email?:bool, portal_error?:string}
 */
function tiRegister($raw, array $in)
{
    global $db;
    $inv = tiFind($raw);
    if (!$inv) return ['success' => false, 'errors' => ['This registration link is invalid, already used, or expired. Please ask the administrator for a new invitation.']];

    $errors = tvValidate($in, 'edit');   // same rules as Edit Teacher (education, degree, years, PRC ...)
    $get = function ($k) use ($in) { return trim(html_entity_decode((string) ($in[$k] ?? ''), ENT_QUOTES, 'UTF-8')); };
    if (!in_array($get('department_level'), ['JHS', 'SHS'], true)) $errors[] = 'Department / Level is required (JHS or SHS).';
    if (!in_array($get('employment_status'), TI_EMPLOYMENT_OPTIONS, true)) $errors[] = 'Employment status is required.';
    $empId = $get('employee_id');
    if ($empId === '') $errors[] = 'Teacher ID / Employee ID is required.';
    elseif (!preg_match('/^[A-Za-z0-9\-]{3,50}$/', $empId)) $errors[] = 'Teacher ID / Employee ID must be 3 to 50 letters, numbers or dashes.';
    elseif (getRecord("SELECT user_id FROM users WHERE employee_id = ?", [$empId])) $errors[] = 'This Teacher ID / Employee ID is already used.';
    $pw = (string) ($in['password'] ?? '');
    if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) $errors[] = 'Password must be at least 8 characters with at least one letter and one number.';
    elseif ($pw !== (string) ($in['confirm_password'] ?? '')) $errors[] = 'Password and Confirm Password do not match.';
    if (getRecord("SELECT user_id FROM users WHERE LOWER(email) = ?", [strtolower($inv['email'])])) $errors[] = 'This email already has an EGradePro account.';
    if ($errors) return ['success' => false, 'errors' => $errors];

    $dept = $get('department_level');
    try {
        $db->beginTransaction();
        // Claim the invitation first so the same link can never create two accounts.
        $claim = $db->prepare("UPDATE teacher_invitations SET used_at = NOW() WHERE invite_id = ? AND used_at IS NULL AND revoked_at IS NULL AND expires_at > NOW()");
        $claim->execute([$inv['invite_id']]);
        if ($claim->rowCount() !== 1) {
            $db->rollBack();
            return ['success' => false, 'errors' => ['This registration link was already used. Please ask the administrator for a new invitation.']];
        }
        $opt = function ($k) use ($get) { $v = $get($k); return $v === '' ? null : $v; };
        $ins = $db->prepare(
            "INSERT INTO users (username, password_hash, email, contact_number, first_name, last_name, middle_name, suffix, sex,
                specialization, position_designation, employee_id, department_level, can_handle_shs, employment_status,
                highest_educ_attainment, degree_course, years_experience, prc_license_number, role_id, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 3, 1)"
        );
        $ins->execute([
            tiUsername($inv['email']), hashPassword($pw), $inv['email'], tvNormalizeMobile($get('contact_number')),
            $get('first_name'), $get('last_name'), $opt('middle_name'), $opt('suffix'), $get('sex'),
            $get('specialization'), $get('position_designation'), $empId, $dept, $dept === 'SHS' ? 1 : 0, $get('employment_status'),
            $get('highest_educ_attainment'), $get('degree_course'), (int) $get('years_experience'), $opt('prc_license_number'),
        ]);
        $userId = (int) $db->lastInsertId();
        $db->prepare("UPDATE teacher_invitations SET user_id = ? WHERE invite_id = ?")->execute([$userId, $inv['invite_id']]);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Teacher registration failed: ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Your account could not be created. Please try again.']];
    }
    logActivity($userId, 'teachers', 'Teacher registered', 'Teacher account created from invitation: ' . $get('last_name') . ', ' . $get('first_name'));

    $portal = appUrl('teacher_login.php');
    $html = tiEmailHtml(
        'Welcome to Your EGradePro Teacher Portal',
        ['Hi ' . htmlspecialchars($get('first_name')) . ',',
         'Your teacher account is now ready. You may now access your Teacher Portal.',
         'Sign in with your email <strong>' . htmlspecialchars($inv['email']) . '</strong> and the password you created. The Teacher Portal is used on a desktop or laptop computer.'],
        'Go to Teacher Portal',
        $portal
    );
    $r = mailSendOne($inv['email'], 'Welcome to Your EGradePro Teacher Portal', $html);
    return ['success' => true, 'portal_email' => $r['success'], 'portal_error' => $r['error'] ?? null, 'email' => $inv['email'], 'portal_url' => $portal];
}
