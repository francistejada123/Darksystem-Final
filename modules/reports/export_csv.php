<?php
// modules/reports/export_csv.php
// Real CSV export for the Live Reports dashboard (replaces the old
// "Export feature coming soon!" stub). Exports whichever dataset the
// dashboard is currently showing — respects the same ?term= and
// ?grade_level= filters as live_data.php so what downloads matches
// what's on screen.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';

if (!hasAnyRole(['Administrator', 'Registrar', 'Teacher'])) {
    http_response_code(403);
    die('Forbidden');
}

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
$school_year_id = $active_sy['sy_id'] ?? 0;
syncSectionEnrollmentCounts($school_year_id); // "approved" per section = students actually enrolled

$term = isset($_GET['term']) ? sanitize($_GET['term']) : getDefaultTerm();
$valid_terms = getActiveTerms();
if (!in_array($term, $valid_terms, true)) {
    $term = getDefaultTerm();
}

$grade_filter = isset($_GET['grade_level']) ? sanitize($_GET['grade_level']) : '';
$valid_grades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
if (!in_array($grade_filter, $valid_grades, true)) {
    $grade_filter = '';
}

$type = isset($_GET['type']) ? sanitize($_GET['type']) : 'all';
$GRADE_ORDER_SQL = "CAST(SUBSTRING_INDEX(grade_level, ' ', -1) AS UNSIGNED)";

// ============================================================
// Data — same queries as live_data.php, kept in sync intentionally.
// ============================================================
$datasets = [];

$datasets['enrollment_status'] = [
    'title' => 'Enrollment Status by Grade Level',
    'headers' => ['Grade Level', 'Pending', 'Approved', 'Incomplete Requirements', 'Total'],
    'rows' => getRecords("
        SELECT grade_level, 
               SUM(CASE WHEN enrollment_status = 'Pending' THEN 1 ELSE 0 END) AS pending,
               SUM(CASE WHEN enrollment_status = 'Approved' THEN 1 ELSE 0 END) AS approved,
               SUM(CASE WHEN enrollment_status IN ('Incomplete Requirements','Rejected') THEN 1 ELSE 0 END) AS rejected,
               COUNT(*) AS total
        FROM enrollments WHERE school_year_id = ?
        GROUP BY grade_level ORDER BY $GRADE_ORDER_SQL
    ", [$school_year_id]),
    'cols' => ['grade_level', 'pending', 'approved', 'rejected', 'total'],
];

$datasets['section_occupancy'] = [
    'title' => 'Section Occupancy',
    'headers' => ['Section', 'Grade Level', 'Strand', 'Capacity', 'Approved', 'Pending', 'Remaining', '% Full'],
    'rows' => (function () use ($school_year_id, $GRADE_ORDER_SQL) {
        $rows = getRecords("
            SELECT sec.section_name, sec.grade_level, sec.strand,
                   sec.capacity, sec.current_enrollment AS approved,
                   COALESCE(p.pending_count, 0) AS pending
            FROM sections sec
            LEFT JOIN (
                SELECT section_id, COUNT(*) AS pending_count FROM enrollments
                WHERE school_year_id = ? AND enrollment_status = 'Pending' GROUP BY section_id
            ) p ON p.section_id = sec.section_id
            WHERE sec.is_active = 1
            ORDER BY $GRADE_ORDER_SQL, sec.section_name
        ", [$school_year_id]);
        foreach ($rows as &$r) {
            $occupied = intval($r['approved']) + intval($r['pending']);
            $r['remaining'] = max(0, intval($r['capacity']) - $occupied);
            $r['percent_full'] = intval($r['capacity']) > 0 ? round(min(100, ($occupied / intval($r['capacity'])) * 100)) . '%' : '0%';
        }
        return $rows;
    })(),
    'cols' => ['section_name', 'grade_level', 'strand', 'capacity', 'approved', 'pending', 'remaining', 'percent_full'],
];

$gc_sql = "
    SELECT u.first_name, u.last_name, sub.subject_name, sec.section_name, sec.grade_level,
           (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = ts.section_id 
            AND e.school_year_id = ts.school_year_id AND e.enrollment_status = 'Approved') AS expected_students,
           (SELECT COUNT(DISTINCT g.student_id) FROM grades g WHERE g.subject_code = ts.subject_code 
            AND g.section_id = ts.section_id AND g.school_year_id = ts.school_year_id AND g.term = ?
            AND " . gh_validTermSql('g') . ") AS graded_students
    FROM teacher_subjects ts
    JOIN users u ON u.user_id = ts.teacher_id
    JOIN subjects sub ON sub.subject_code = ts.subject_code
    JOIN sections sec ON sec.section_id = ts.section_id
    WHERE ts.school_year_id = ?";
$gc_params = [$term, $school_year_id];
if ($grade_filter !== '') { $gc_sql .= " AND sec.grade_level = ?"; $gc_params[] = $grade_filter; }
$gc_sql .= " ORDER BY $GRADE_ORDER_SQL, sec.section_name, sub.subject_name";
$gc_rows = getRecords($gc_sql, $gc_params);
foreach ($gc_rows as &$r) {
    $r['teacher_name'] = trim($r['first_name'] . ' ' . $r['last_name']);
    $r['status'] = ($r['expected_students'] > 0 && $r['graded_students'] >= $r['expected_students']) ? 'Complete' : 'Incomplete';
}
unset($r);
$datasets['grade_completion'] = [
    'title' => "Grade Submission Tracker ($term)",
    'headers' => ['Teacher', 'Subject', 'Section', 'Grade Level', 'Expected Students', 'Graded Students', 'Status'],
    'rows' => $gc_rows,
    'cols' => ['teacher_name', 'subject_name', 'section_name', 'grade_level', 'expected_students', 'graded_students', 'status'],
];

$pass_mark_row = getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'grading_pass_mark'");
$pass_mark = floatval($pass_mark_row['setting_value'] ?? 75);
$ar_sql = "
    SELECT s.lrn, s.first_name, s.last_name, s.grade_level, sec.section_name, AVG(g.final_grade) AS average
    FROM students s
    JOIN enrollments e ON e.student_id = s.student_id AND e.school_year_id = ?
    LEFT JOIN sections sec ON sec.section_id = e.section_id
    JOIN grades g ON g.student_id = s.student_id AND g.school_year_id = ?
                  AND " . gh_validTermSql('g') . "
    WHERE s.is_active = 1 AND e.enrollment_status = 'Approved'";
$ar_params = [$school_year_id, $school_year_id];
if ($grade_filter !== '') { $ar_sql .= " AND s.grade_level = ?"; $ar_params[] = $grade_filter; }
$ar_sql .= " GROUP BY s.student_id HAVING average < ? ORDER BY average ASC";
$ar_params[] = $pass_mark + 5;
$ar_rows = getRecords($ar_sql, $ar_params);
foreach ($ar_rows as &$r) {
    $r['student_name'] = trim($r['last_name'] . ', ' . $r['first_name']);
    $r['average'] = round(floatval($r['average']), 2);
    $r['risk_level'] = $r['average'] < ($pass_mark - 5) ? 'Failing' : 'At-Risk';
}
unset($r);
$datasets['at_risk'] = [
    'title' => 'At-Risk & Failing Students',
    'headers' => ['LRN', 'Student', 'Grade Level', 'Section', 'Average', 'Risk Level'],
    'rows' => $ar_rows,
    'cols' => ['lrn', 'student_name', 'grade_level', 'section_name', 'average', 'risk_level'],
];

if (!isset($datasets[$type]) && $type !== 'all') {
    http_response_code(400);
    die('Unknown export type.');
}

// ============================================================
// OUTPUT CSV
// ============================================================
$filename = 'egradepro_report_' . ($type === 'all' ? 'full' : $type) . '_' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders special characters correctly

fputcsv($out, ['EGRADEPRO Report Export']);
fputcsv($out, ['Generated', date('F j, Y g:i A')]);
fputcsv($out, ['School Year', $active_sy['sy_name'] ?? '']);
if ($grade_filter !== '') { fputcsv($out, ['Filtered to', $grade_filter]); }
fputcsv($out, []);

$to_export = ($type === 'all') ? $datasets : [$type => $datasets[$type]];

foreach ($to_export as $key => $set) {
    fputcsv($out, [strtoupper($set['title'])]);
    fputcsv($out, $set['headers']);
    if (empty($set['rows'])) {
        fputcsv($out, ['No data available.']);
    } else {
        foreach ($set['rows'] as $row) {
            $line = [];
            foreach ($set['cols'] as $col) {
                $line[] = $row[$col] ?? '';
            }
            fputcsv($out, $line);
        }
    }
    fputcsv($out, []); // blank line between sections
}

fclose($out);
logActivity($_SESSION['user_id'] ?? null, 'Report Exported', 'reports', "CSV export: $type");
exit;