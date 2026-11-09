<?php
// TEMPORARY: inspect approved applications vs their student's credential state.
require 'config/database.php';
require 'includes/auth.php';
require 'includes/student_password.php';
spwEnsureSchema();

echo "== Approved applications ==\n";
$apps = getRecords(
    "SELECT a.application_id, a.reference_no, a.status, a.lrn AS app_lrn, a.student_id,
            s.lrn, s.portal_password_hash, s.portal_password_temp, s.portal_temp_password_enc
       FROM online_applications a
       LEFT JOIN students s ON s.student_id = a.student_id
      WHERE a.status = 'Approved' AND a.deleted_at IS NULL
      ORDER BY a.application_id DESC LIMIT 20"
);
foreach ($apps as $a) {
    $hasHash = !empty($a['portal_password_hash']);
    $isTemp = (int) ($a['portal_password_temp'] ?? 0) === 1;
    $hasEnc = !empty($a['portal_temp_password_enc']);
    $readable = ($hasEnc && $isTemp) ? (spwReadTemporaryPassword([
        'portal_password_temp' => $isTemp,
        'portal_temp_password_enc' => $a['portal_temp_password_enc'],
    ]) !== '') : false;
    printf(
        "app#%-4d ref=%-16s sid=%-5s lrn=%-13s hash=%-3s temp=%-3s enc=%-3s readable=%-3s => %s\n",
        $a['application_id'],
        $a['reference_no'],
        $a['student_id'] ?? '-',
        $a['lrn'] ?: '-',
        $hasHash ? 'yes' : 'NO',
        $isTemp ? 'yes' : 'no',
        $hasEnc ? 'yes' : 'no',
        $readable ? 'yes' : 'no',
        $readable ? 'CREDENTIALS SHOWN' : ($hasHash ? 'activated' : 'LOCKED OUT (no password at all)')
    );
}

echo "\n== Totals ==\n";
print_r(getRecord(
    "SELECT COUNT(*) AS approved_apps,
            SUM(CASE WHEN s.portal_password_hash IS NULL OR s.portal_password_hash = '' THEN 1 ELSE 0 END) AS no_password,
            SUM(CASE WHEN s.portal_password_temp = 1 THEN 1 ELSE 0 END) AS flagged_temp
       FROM online_applications a
       LEFT JOIN students s ON s.student_id = a.student_id
      WHERE a.status = 'Approved' AND a.deleted_at IS NULL"
));