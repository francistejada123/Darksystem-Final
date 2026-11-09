<?php
// TEMPORARY: verify the "lost temporary password" recovery path clears the flag
// (otherwise the student would loop between login and Change Password).
require 'config/database.php';
require 'includes/auth.php';
require 'includes/student_password.php';
spwEnsureSchema();

global $db;
$db->beginTransaction();

$ok = 0;
$bad = 0;
function t($n, $c, $e = '')
{
    global $ok, $bad;
    if ($c) {
        $ok++;
        echo "  PASS  $n\n";
    } else {
        $bad++;
        echo "  FAIL  $n" . ($e ? "  ($e)" : '') . "\n";
    }
}

$st = getRecord("SELECT student_id, lrn FROM students WHERE lrn REGEXP '^[0-9]{12}$' AND is_active = 1 ORDER BY student_id DESC LIMIT 1");
$sid = (int) $st['student_id'];

$p = spwProvisionTemporaryPassword($sid);
t('provisioned a temporary password', !empty($p['success']));
t('flag is set', (int) (getRecord("SELECT portal_password_temp FROM students WHERE student_id = ?", [$sid])['portal_password_temp']) === 1);

// The recovery path used by student/access.php -> set_password.php calls spwSet().
spwSet($sid, 'Recovered9x');
$row = getRecord("SELECT portal_password_hash, portal_password_temp FROM students WHERE student_id = ?", [$sid]);
t('recovery password works for login', password_verify('Recovered9x', $row['portal_password_hash']));
t('recovery CLEARS the temporary flag (no loop)', (int) $row['portal_password_temp'] === 0);
t('the portal gate is open after recovery', !spwMustChangePassword($row));

$db->rollBack();
echo "\nrolled back. $ok passed, $bad failed\n";