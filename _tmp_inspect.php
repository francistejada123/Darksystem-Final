<?php
// TEMP: verify the "no pending requirements" branch renders the plain Approve button.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/enrollment_helpers_online.php';
$id = 62;
$app = getRecord("SELECT * FROM online_applications WHERE application_id=?", [$id]);
$split = oeSplitMissing($id, $app['application_type']);
$pend = oeRequirementsPreview($id, $app['application_type']);
echo "id=$id type=" . $app['application_type'] . "\n";
echo "blockers=" . json_encode($split['blockers']) . "\n";
echo "pending =" . json_encode($pend) . "\n";
// What does the 'form137' requirement look like for this app?
echo "\nform137 doc row:\n";
print_r(getRecord("SELECT * FROM application_documents WHERE application_id=? AND doc_key='form137'", [$id]));