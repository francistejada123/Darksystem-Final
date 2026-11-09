<?php
// TEMPORARY: verify each Enrollment Tracking state picks the right branch.
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

// Reproduce the tracking page's decision logic for each state.
function trackingState(array $student)
{
    if (empty($student))
        return 'pending-none';
    $pw = spwReadTemporaryPassword($student);
    if ($pw !== '')
        return 'credentials-shown';
    if (!empty($student['portal_password_hash']) && empty($student['portal_password_temp']))
        return 'activated';
    return 'preparing';
}

t('Pending (never approved) shows no credentials', trackingState([]) === 'pending-none');

$p = spwProvisionTemporaryPassword($sid);
$row = getRecord("SELECT * FROM students WHERE student_id = ?", [$sid]);
t('Approved + fresh credentials => shows LRN + temporary password', trackingState($row) === 'credentials-shown');
t('  and the password it would show is the generated one', spwReadTemporaryPassword($row) === $p['password']);

spwSetPermanentPassword($sid, 'RealPass99');
$row2 = getRecord("SELECT * FROM students WHERE student_id = ?", [$sid]);
t('After the change => "Student Portal Activated"', trackingState($row2) === 'activated');
t('  and exposes NO password at all', spwReadTemporaryPassword($row2) === '');

// Incomplete / unapproved student must never get credentials.
t('Unapproved student row has no temp password', spwReadTemporaryPassword(['portal_password_temp' => 0, 'portal_temp_password_enc' => null]) === '');

$db->rollBack();
echo "\nrolled back. $ok passed, $bad failed\n";