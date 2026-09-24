<?php
// get_dashboard_stats.php
// AJAX JSON endpoint — powers the auto-updating Admin/Registrar dashboard
// on index.php. Every query here mirrors what index.php already computes
// on page load, so index.php can poll this endpoint on an interval to
// keep the dashboard statistics current without a full page reload.

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/grading_helpers.php';

header('Content-Type: application/json');

// Same authorization the dashboard itself requires — only a logged-in
// Administrator or Registrar can pull these numbers.
if (!hasAnyRole(['Administrator', 'Registrar'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");

// ============================================================
// TOP STATISTICS CARDS (Administrator + Registrar)
// Same queries as index.php's $stats array.
// ============================================================
$stats = [
    'total_students'     => getCount('students', 'is_active = 1'),
    'total_teachers'     => getCount('users', 'role_id = 3 AND is_active = 1'),
    'active_enrollments' => getCount('enrollments', "enrollment_status = 'Approved'"),
    'passing_rate'       => 0,
];

// Passing rate counts ONLY valid, completed Term 1-3 records.
$passing_sql = "SELECT COUNT(*) as total, SUM(CASE WHEN final_grade >= " . (float)getGradingPassMark() . " THEN 1 ELSE 0 END) as passing
                FROM grades
                WHERE school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1)
                  AND " . gh_validTermSql('') . "";
$passing_result = getRecord($passing_sql);
$stats['passing_rate'] = $passing_result && $passing_result['total'] > 0
    ? round(($passing_result['passing'] / $passing_result['total']) * 100, 2)
    : 0;

$response = [
    'generated_at' => date('Y-m-d H:i:s'),
    'school_year'  => $active_sy['sy_name'] ?? null,
    'stats'        => $stats,
];

// ============================================================
// FULL ANALYTICS DASHBOARD (Administrator only)
// Same queries as index.php's "DATA FOR ANALYTICS" block.
// ============================================================
if (hasRole('Administrator') && $active_sy) {
    $sy_id = $active_sy['sy_id'];

    $students_with_grades = getRecords("
        SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.grade_level, s.strand,
               AVG(g.final_grade) as average_grade,
               MIN(g.final_grade) as min_grade,
               MAX(g.final_grade) as max_grade,
               COUNT(g.grade_id) as subject_count
        FROM students s
        JOIN enrollments e ON s.student_id = e.student_id
        JOIN grades g ON s.student_id = g.student_id AND " . gh_validTermSql('g') . "
        WHERE e.school_year_id = ? AND e.enrollment_status = 'Approved' AND s.is_active = 1
        GROUP BY s.student_id
        ORDER BY average_grade DESC
    ", [$sy_id]);

    $subject_performance = getRecords("
        SELECT sub.subject_code, sub.subject_name,
               AVG(g.final_grade) as avg_grade,
               COUNT(g.grade_id) as student_count,
               SUM(CASE WHEN g.final_grade >= 75 THEN 1 ELSE 0 END) as passing_count
        FROM grades g
        JOIN subjects sub ON g.subject_code = sub.subject_code
        WHERE g.school_year_id = ?
          AND " . gh_validTermSql('g') . "
        GROUP BY sub.subject_code
        ORDER BY avg_grade ASC
    ", [$sy_id]);

    $total_students = count($students_with_grades);
    $passing_count = 0;
    $failing_count = 0;
    $total_average = 0;
    $at_risk_students = [];

    foreach ($students_with_grades as &$s) {
        $avg = round($s['average_grade'] ?? 0, 2);
        $s['average_grade'] = $avg;
        $s['min_grade'] = round($s['min_grade'] ?? 0, 2);
        $s['max_grade'] = round($s['max_grade'] ?? 0, 2);
        if ($avg >= 75) {
            $passing_count++;
        } else {
            $failing_count++;
            $at_risk_students[] = $s;
        }
        $total_average += $avg;
    }
    unset($s);

    $overall_average = $total_students > 0 ? round($total_average / $total_students, 2) : 0;
    $passing_rate_admin = $total_students > 0 ? round(($passing_count / $total_students) * 100, 2) : 0;

    foreach ($subject_performance as &$sp) {
        $sp['avg_grade'] = round($sp['avg_grade'] ?? 0, 2);
    }
    unset($sp);

    $response['analytics'] = [
        'total_students'    => $total_students,
        'passing_count'     => $passing_count,
        'failing_count'     => $failing_count,
        'overall_average'   => $overall_average,
        'passing_rate'      => $passing_rate_admin,
        'at_risk_count'     => count($at_risk_students),
        'students'          => $students_with_grades,
        'subject_performance' => $subject_performance,
    ];
}

echo json_encode($response);