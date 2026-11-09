<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/enrollment_helpers_online.php';

echo "== application status vs requirements_status ==\n";
foreach (getRecords("SELECT application_id, reference_no, status, requirements_status, form137_status, application_type
                       FROM online_applications WHERE deleted_at IS NULL ORDER BY application_id DESC LIMIT 12") as $a) {
    $pend = oePendingRequirements((int) $a['application_id'], $a['application_type']);
    echo "  id=" . $a['application_id'] . " " . $a['reference_no']
        . "  app=[" . $a['status'] . "]"
        . "  req=[" . ($a['requirements_status'] ?? 'NULL') . "]"
        . "  f137=[" . ($a['form137_status'] ?? 'NULL') . "]"
        . "  pending=" . count($pend)
        . " " . json_encode(array_column($pend, 'label')) . "\n";
}

echo "\n== how many are Approved but still have pending requirements? ==\n";
$n = 0;
foreach (getRecords("SELECT application_id, application_type FROM online_applications WHERE deleted_at IS NULL AND status='Approved'") as $a) {
    if (oePendingRequirements((int) $a['application_id'], $a['application_type']))
        $n++;
}
echo "  $n approved applications still have pending requirements\n";

echo "\n== requirements_status values present ==\n";
foreach (getRecords("SELECT requirements_status, COUNT(*) AS n FROM online_applications WHERE deleted_at IS NULL GROUP BY requirements_status") as $r) {
    echo "  [" . ($r['requirements_status'] ?? 'NULL') . "] => " . $r['n'] . "\n";
}