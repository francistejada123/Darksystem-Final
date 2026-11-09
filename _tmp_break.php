<?php
require_once __DIR__ . '/config/database.php';

$counts = getRecord("
    SELECT
      COALESCE(SUM(status = 'Pending'), 0) AS pending,
      COALESCE(SUM(status = 'Under Review'), 0) AS reviewing,
      COALESCE(SUM(status IN ('Incomplete Requirements','For Correction','Rejected')
                   OR (status = 'Approved' AND (requirements_status IS NULL OR requirements_status = 'Incomplete Requirements'))), 0) AS incomplete,
      COALESCE(SUM(status = 'Approved'), 0) AS approved
    FROM online_applications
    WHERE deleted_at IS NULL
");
echo "== current stat cards ==\n";
foreach ($counts as $k => $v)
  echo "  " . str_pad($k, 12) . " = " . $v . "\n";

echo "\n== breakdown of the 17 'incomplete' ones by form137_status ==\n";
foreach (getRecords("SELECT form137_status, COUNT(*) AS n FROM online_applications
                      WHERE deleted_at IS NULL AND status='Approved'
                        AND (requirements_status IS NULL OR requirements_status='Incomplete Requirements')
                      GROUP BY form137_status") as $r) {
  echo "  form137_status=[" . ($r['form137_status'] ?? 'NULL') . "] => " . $r['n'] . "\n";
}