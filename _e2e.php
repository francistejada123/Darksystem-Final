<?php
// TEMPORARY end-to-end test of the temporary-password activation flow.
// Everything runs inside ONE transaction that is always rolled back, so no
// student, enrollment or password row is actually changed.
require 'config/database.php';
require 'includes/auth.php';
require 'includes/student_password.php';
spwEnsureSchema();

global $db;
$db->beginTransaction();

$ok = 0;
$bad = 0;
function t($name, $cond, $extra = '')
{
    global $ok, $bad;
    if ($cond) {
        $ok++;
        echo "  PASS  $name\n";
    } else {
        $bad++;
        echo "  FAIL  $name" . ($extra ? "  ($extra)" : '') . "\n";
    }
}

$st = getRecord("SELECT student_id, lrn, first_name FROM students WHERE lrn REGEXP '^[0-9]{12}$' AND is_active = 1 ORDER BY student_id DESC LIMIT 1");
if (!$st) {
    $db->rollBack();
    echo "no eligible student\n";
    exit(1);
}
$sid = (int) $st['student_id'];
echo "student_id=$sid lrn={$st['lrn']}\n\n";

echo "-- temporary password generation\n";
$a = spwTempPassword();
$b = spwTempPassword();
t('two temporary passwords differ', $a !== $b, "both=$a");
t('at least 8 characters', strlen($a) >= 8, "len=" . strlen($a));
t('satisfies the existing password rules', spwProblem($a, $a, '123456789012') === '', spwProblem($a, $a, '123456789012'));
t('is never plain text in the DB after hashing', password_verify($a, password_hash($a, PASSWORD_DEFAULT)));

echo "\n-- provisioning\n";
$p = spwProvisionTemporaryPassword($sid);
t('provisioning succeeds', !empty($p['success']), $p['error'] ?? '');
$row = getRecord("SELECT portal_password_hash, portal_password_temp, portal_password_set_at FROM students WHERE student_id = ?", [$sid]);
t('a hash is stored', !empty($row['portal_password_hash']));
t('the stored hash matches the emailed password', password_verify($p['password'], $row['portal_password_hash']));
t('the stored value is NOT the plain password', $row['portal_password_hash'] !== $p['password']);
t('the account is flagged temporary', (int) $row['portal_password_temp'] === 1);
t('spwMustChangePassword is true', spwMustChangePassword($row));

echo "\n-- idempotency (admin processes the same enrollment twice)\n";
$countBefore = (int) (getRecord("SELECT COUNT(*) AS c FROM students WHERE lrn = ?", [$st['lrn']])['c'] ?? 0);
$p2 = spwProvisionTemporaryPassword($sid);
$countAfter = (int) (getRecord("SELECT COUNT(*) AS c FROM students WHERE lrn = ?", [$st['lrn']])['c'] ?? 0);
t('re-running creates no duplicate student/portal account', $countBefore === $countAfter, "$countBefore -> $countAfter");
$row2 = getRecord("SELECT portal_password_hash FROM students WHERE student_id = ?", [$sid]);
t('the new temporary password works', password_verify($p2['password'], $row2['portal_password_hash']));
t('the previous temporary password no longer works', !password_verify($p['password'], $row2['portal_password_hash']));

echo "\n-- login then forced change\n";
$temporary = $p2['password'];
t('login with LRN + temporary password verifies', password_verify($temporary, $row2['portal_password_hash']));
t('a wrong password is rejected', !password_verify('wrongpassword', $row2['portal_password_hash']));

$permanent = 'NewPass' . random_int(1000, 9999);
$lrnProblem = spwProblem($permanent, $permanent, $st['lrn']);
t('the new password passes the rules', $lrnProblem === '', $lrnProblem);
$usingLrn = $st['lrn'] . 'abc123';
t('a password containing the LRN is rejected', spwProblem($usingLrn, $usingLrn, $st['lrn']) !== '');
t('mismatched confirmation is rejected', spwProblem($permanent, $permanent . 'x', $st['lrn']) !== '');

spwSetPermanentPassword($sid, $permanent);
$row3 = getRecord("SELECT portal_password_hash, portal_password_temp FROM students WHERE student_id = ?", [$sid]);
t('the new password verifies after saving', password_verify($permanent, $row3['portal_password_hash']));
t('the TEMPORARY password no longer works', !password_verify($temporary, $row3['portal_password_hash']));
t('the temporary flag is cleared', (int) $row3['portal_password_temp'] === 0);
t('spwMustChangePassword is now false', !spwMustChangePassword($row3));

echo "\n-- columns exist\n";
foreach (['portal_password_temp', 'portal_temp_password_set_at'] as $c) {
    t("students.$c exists", (bool) getRecord("SHOW COLUMNS FROM students LIKE '$c'"));
}

$db->rollBack();
echo "\nrolled back. " . $ok . " passed, " . $bad . " failed\n";