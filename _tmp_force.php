<?php
// TEMP: force the Form 137 to a "received" state on app 62 so the plain
// Approve branch renders, then we restore it right after the browser check.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/enrollment_helpers_online.php';
$id = 62;
$app = getRecord("SELECT * FROM online_applications WHERE application_id=?", [$id]);
echo "BEFORE: " . json_encode(oeRequirementsPreview($id, $app['application_type'])) . "\n";

// Find which column holds the Form 137 state.
$cols = array_column(getRecords("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='online_applications'"), 'COLUMN_NAME');
echo "form137-ish columns: " . json_encode(array_values(array_filter($cols, function ($c) {
    return stripos($c, '137') !== false;
}))) . "\n";
print_r(getRecord("SELECT * FROM online_applications WHERE application_id=?", [$id]));