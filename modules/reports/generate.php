<?php
// modules/reports/generate.php
// Outputs ONE report and nothing else — no header, no sidebar, no Live
// Reports dashboard. This is what the "Generate Report" form (and the
// per-student report link on at_risk.php) opens in a new tab, so the
// tab shows just the report instead of the whole Reports page.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';
require_once __DIR__ . '/report_functions.php';

if (!hasAnyRole(['Administrator', 'Registrar', 'Teacher'])) {
    header('Location: ../../index.php');
    exit;
}

$report_type = isset($_GET['report_type']) ? sanitize($_GET['report_type']) : '';
$section_id = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;
$grade_level = isset($_GET['grade_level']) ? sanitize($_GET['grade_level']) : '';
// Kept as a digit string, NOT intval()'d — this may be either the
// internal Student ID or the LRN (LRNs can start with 0, which
// intval() would silently strip). report_functions.php looks it up
// against both columns via findStudentByIdentifier().
$student_id = isset($_GET['student_id']) ? preg_replace('/[^0-9]/', '', trim($_GET['student_id'])) : '';

if (!$report_type) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><title>Missing report type</title></head><body style="font-family:Arial,sans-serif;">';
    echo '<p>Please choose a report type.</p>';
    echo '<p><a href="index.php">&larr; Back to Reports</a></p>';
    echo '</body></html>';
    exit;
}

if (($report_type === 'sf9' || $report_type === 'report_card' || $report_type === 'at_risk_student') && !$student_id) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><title>Student required</title></head><body style="font-family:Arial,sans-serif;">';
    echo '<p>This report needs a student. Please enter a Student ID.</p>';
    echo '<p><a href="index.php">&larr; Back to Reports</a></p>';
    echo '</body></html>';
    exit;
}

// A specific student was given for SF5 — that almost always means the
// person picked one student they're concerned about (e.g. from the
// At-Risk list), so give them a focused single-student report with
// recommendations instead of the whole section's SF5 table.
if ($report_type === 'sf5' && $student_id) {
    generateAtRiskStudentReport($student_id);
    exit;
}

switch ($report_type) {
    case 'sf1':
        generateSF1($section_id, $grade_level);
        break;
    case 'sf5':
        generateSF5($section_id);
        break;
    case 'sf9':
        generateSF9($student_id);
        break;
    case 'grade_summary':
        generateGradeSummary($section_id);
        break;
    case 'report_card':
        generateReportCard($student_id);
        break;
    case 'at_risk_student':
        generateAtRiskStudentReport($student_id);
        break;
    default:
        http_response_code(400);
        echo '<!DOCTYPE html><html><head><title>Unknown report type</title></head><body style="font-family:Arial,sans-serif;">';
        echo '<p>Unknown report type.</p>';
        echo '<p><a href="index.php">&larr; Back to Reports</a></p>';
        echo '</body></html>';
}