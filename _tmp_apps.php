<?php
require_once __DIR__ . '/config/database.php';
echo "== applications and their status ==\n";
foreach (getRecords("SELECT application_id, reference_no, status, grade_level, application_type, first_name, last_name
                       FROM online_applications WHERE deleted_at IS NULL ORDER BY application_id") as $a) {
    echo "  id=" . $a['application_id'] . "  " . $a['reference_no'] . "  [" . $a['status'] . "]  "
        . $a['grade_level'] . " " . $a['application_type'] . "  " . $a['first_name'] . " " . $a['last_name'] . "\n";
}
echo "\n== application_documents ==\n";
foreach (getRecords("SELECT application_id, doc_key, original_name, status FROM application_documents ORDER BY application_id, doc_key") as $d) {
    echo "  app=" . $d['application_id'] . "  " . $d['doc_key'] . "  " . $d['original_name'] . "  [" . ($d['status'] ?? '') . "]\n";
}