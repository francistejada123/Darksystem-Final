<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/enrollment_helpers_online.php';
oeEnsureRequirementsSchema();
oeEnsureForm137Schema();

function countIncomplete()
{
    $n = 0;
    foreach (getRecords("SELECT application_id, application_type, status FROM online_applications
                          WHERE deleted_at IS NULL AND status IN ('Incomplete Requirements','For Correction','Rejected','Approved')") as $a) {
        if (oePendingRequirements((int) $a['application_id'], $a['application_type']))
            $n++;
    }
    return $n;
}

$id = 62;
$before = countIncomplete();
$app = getRecord("SELECT form137_status FROM online_applications WHERE application_id=?", [$id]);
echo "id=$id form137_status=" . var_export($app['form137_status'], true) . "\n";
echo "INCOMPLETE count BEFORE            = $before\n";

// Step 1: Received (what the screenshot shows) -> should still count.
executeQuery("UPDATE online_applications SET form137_status='Received' WHERE application_id=?", [$id]);
oeSyncRequirementsStatus($id);
echo "after Received  -> count = " . countIncomplete() . "  (still counted: not yet verified)\n";

// Step 2: Verified -> must DROP.
executeQuery("UPDATE online_applications SET form137_status='Verified' WHERE application_id=?", [$id]);
oeSyncRequirementsStatus($id);
echo "after Verified  -> count = " . countIncomplete() . "  <== should be one less\n";
echo "   requirements_status = " . var_export(getRecord("SELECT requirements_status FROM online_applications WHERE application_id=?", [$id])['requirements_status'], true) . "\n";

// Restore.
executeQuery("UPDATE online_applications SET form137_status = ? WHERE application_id = ?", [$app['form137_status'], $id]);
oeSyncRequirementsStatus($id);
echo "\nrestored -> count = " . countIncomplete() . "  form137_status=" . var_export($app['form137_status'], true) . "\n";