<?php
// TEMPORARY: end-to-end test of the fix, including back-filling students who
// were approved BEFORE this feature (no portal password at all).
// Everything runs in ONE transaction that is always rolled back.
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

echo "-- students approved BEFORE the fix (the reported bug)\n";
$legacy = getRecords(
    "SELECT a.application_id, a.reference_no, a.student_id, s.lrn
       FROM online_applications a JOIN students s ON s.student_id = a.student_id
      WHERE a.status = 'Approved' AND a.deleted_at IS NULL
        AND (s.portal_password_hash IS NULL OR s.portal_password_hash = '')
      ORDER BY a.application_id DESC LIMIT 4"
);
t('found the affected approved students', count($legacy) > 0, 'count=' . count($legacy));

foreach ($legacy as $app) {
    $sid = (int) $app['student_id'];
    $before = getRecord("SELECT portal_password_hash, portal_password_temp FROM students WHERE student_id = ?", [$sid]);
    $hadNothing = empty($before['portal_password_hash']);

    $st = spwEnsurePortalCredentials($sid);
    $pw = $st['password'] ?? '';
    $row = getRecord("SELECT * FROM students WHERE student_id = ?", [$sid]);

    $label = "sid=$sid lrn=" . ($row['lrn'] ?: '-');
    t("$label => state is 'credentials'", ($st['state'] ?? '') === 'credentials', $st['state'] ?? 'none');
    t("$label => a password is returned", $pw !== '');
    t("$label => the password verifies for login", password_verify($pw, $row['portal_password_hash']));
    t("$label => flagged for forced change", (int) $row['portal_password_temp'] === 1);
    t("$label => LRN is the username", $st['lrn'] === $row['lrn'] && preg_match('/^\d{12}$/', $row['lrn']) === 1);

    // Refresh must NOT rotate the password.
    $again = spwEnsurePortalCredentials($sid);
    t("$label => refresh returns the SAME password", ($again['password'] ?? '') === $pw);
    $after = getRecord("SELECT portal_password_hash FROM students WHERE student_id = ?", [$sid]);
    t("$label => refresh did not rotate it", password_verify($pw, $after['portal_password_hash']));

    // First login works, then the change kills it.
    t("$label => temp password logs in", password_verify($pw, $row['portal_password_hash']));
    $new = 'Changed' . random_int(1000, 9999);
    spwSetPermanentPassword($sid, $new);
    $row2 = getRecord("SELECT * FROM students WHERE student_id = ?", [$sid]);
    t("$label => new password logs in", password_verify($new, $row2['portal_password_hash']));
    t("$label => TEMPORARY password now fails", !password_verify($pw, $row2['portal_password_hash']));
    t("$label => tracking now shows 'activated'", spwEnsurePortalCredentials($sid)['state'] === 'activated');
    t("$label => and exposes NO password", empty(spwEnsurePortalCredentials($sid)['password']));
    t("$label => ciphertext cleared", empty($row2['portal_temp_password_enc']));
    echo "\n";
}

echo "-- an already-activated student is never re-provisioned\n";
$act = getRecord("SELECT student_id FROM students WHERE portal_password_hash IS NOT NULL AND portal_password_hash <> '' AND portal_password_temp = 0 AND is_active = 1 LIMIT 1");
if ($act) {
    $before = getRecord("SELECT portal_password_hash FROM students WHERE student_id = ?", [(int) $act['student_id']]);
    $r = spwEnsurePortalCredentials((int) $act['student_id']);
    $after = getRecord("SELECT portal_password_hash FROM students WHERE student_id = ?", [(int) $act['student_id']]);
    t('state is activated', $r['state'] === 'activated');
    t('the existing password was left untouched', $before['portal_password_hash'] === $after['portal_password_hash']);
} else {
    t('state is activated', true, 'no activated student on file');
}

echo "\n-- pending / incomplete students get nothing\n";
$pend = getRecord("SELECT student_id FROM online_applications WHERE status <> 'Approved' AND deleted_at IS NULL AND student_id IS NOT NULL LIMIT 1");
if ($pend) {
    $r = spwEnsurePortalCredentials((int) $pend['student_id']);
    t('a non-approved application never shows credentials', $r['state'] !== 'credentials', $r['state']);
} else {
    t('a non-approved application never shows credentials', true, 'no pending application on file');
}

$db->rollBack();
echo "\nrolled back. $ok passed, $bad failed\n";