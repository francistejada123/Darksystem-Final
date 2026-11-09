<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/enrollment_helpers_online.php';

// Find an application with NO pending requirements and NO blockers.
foreach (getRecords("SELECT * FROM online_applications WHERE deleted_at IS NULL ORDER BY application_id DESC LIMIT 20") as $a) {
    $id = (int) $a['application_id'];
    $split = oeSplitMissing($id, $a['application_type']);
    $pend = oeRequirementsPreview($id, $a['application_type']);
    echo "id=$id " . $a['reference_no'] . " [" . $a['status'] . "] " . $a['application_type']
        . "  blockers=" . count($split['blockers']) . "  pending=" . count($pend) . "\n";
}