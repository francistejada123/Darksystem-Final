<?php
require_once __DIR__ . '/config/database.php';

$counts = getRecord("
    SELECT
      COALESCE(SUM(status = 'Pending'), 0) AS pending,
      COALESCE(SUM(status = 'Under Review'), 0) AS reviewing,
      COALESCE(SUM(status IN ('Incomplete Requirements','For Correction','Rejected')
                   OR (status = 'Approved' AND (requirements_status IS NULL OR requirements_status = 'Incomplete Requirements'))), 0) AS incomplete,
      COALESCE(SUM(status = 'Approved'), 0) AS approved,
      0 AS rejected
    FROM online_applications
    WHERE deleted_at IS NULL
");

echo "== NEW stat-card counts ==\n";
foreach ($counts as $k => $v)
  echo "  " . str_pad($k, 12) . " = " . $v . "\n";

echo "\n== sanity: which approved apps are counted as incomplete? ==\n";
foreach (getRecords("SELECT application_id, reference_no, first_name, last_name, requirements_status
                       FROM online_applications
                      WHERE deleted_at IS NULL AND status='Approved'
                        AND (requirements_status IS NULL OR requirements_status='Incomplete Requirements')
                      ORDER BY application_id DESC") as $a) {
  echo "  " . $a['reference_no'] . "  " . $a['first_name'] . " " . $a['last_name']
    . "  req=[" . ($a['requirements_status'] ?? 'NULL') . "]\n";
}