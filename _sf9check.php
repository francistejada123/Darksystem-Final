<?php
// Temporary verification harness for the SF9 two-page layout.
require 'config/database.php';
require 'includes/auth.php';
require 'modules/reports/report_functions.php';

$rows = getRecords("SELECT DISTINCT student_id FROM enrollments WHERE enrollment_status = 'Approved' ORDER BY student_id DESC LIMIT 5");
if (!$rows) {
    echo "no approved enrollments\n";
    exit;
}

foreach ($rows as $r) {
    $sid = (int) $r['student_id'];
    $st = findStudentByIdentifier($sid);
    if (!$st)
        continue;
    echo "--- student_id=$sid lrn=" . $st['lrn'] . " strand=" . ($st['strand'] ?: '-') . "\n";
}