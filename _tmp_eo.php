<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/eo_scope.php';

echo "== Enrollment Officers and their assigned grade ==\n";
foreach (getRecords("SELECT u.user_id, u.username, u.assigned_grade_level, r.role_name
                       FROM users u JOIN roles r ON r.role_id = u.role_id
                      WHERE r.role_name = 'Enrollment Officer'") as $u) {
  echo "  #" . $u['user_id'] . " " . $u['username']
    . "  grade=[" . ($u['assigned_grade_level'] ?? 'NULL') . "]\n";
}

echo "\n== the counts query WITH the EO filter ( AND 1 = 0 ) ==\n";
$c = getRecord("
    SELECT
      SUM(status = 'Pending') AS pending,
      SUM(status = 'Under Review') AS reviewing,
      SUM(status IN ('Incomplete Requirements','For Correction','Rejected')) AS incomplete,
      SUM(status = 'Approved') AS approved
    FROM online_applications
    WHERE deleted_at IS NULL AND 1 = 0
");
var_dump($c);