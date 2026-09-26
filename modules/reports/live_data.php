<?php
// modules/reports/live_data.php
// AJAX JSON endpoint — powers the realtime "Live Reports" dashboard on
// modules/reports/index.php. Every query here reads straight from the
// database, so every call reflects the current state (no caching), and
// index.php polls this endpoint on an interval to keep the dashboard
// connected/live without a full page reload.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';
require_once __DIR__ . '/../../includes/risk_helpers.php';

header('Content-Type: application/json');

if (!hasAnyRole(['Administrator', 'Registrar', 'Teacher'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// A Teacher hitting this endpoint only ever sees THEIR OWN grade-submission
// progress and THEIR OWN students -- never another teacher's name/progress
// or the whole school's at-risk/failing list, per teacher_subjects. This
// endpoint is not reachable by Administrator/Registrar restrictions, so the
// scoping happens here, in the query, not just by hiding UI elsewhere.
$is_teacher_scoped = hasRole('Teacher') && !hasAnyRole(['Administrator', 'Registrar']);
$scoped_teacher_id = $_SESSION['user_id'] ?? 0;

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
$school_year_id = $active_sy['sy_id'] ?? 0;
syncSectionEnrollmentCounts($school_year_id); // "approved" per section = students actually enrolled

$pass_mark_row = getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'grading_pass_mark'");
$pass_mark = floatval($pass_mark_row['setting_value'] ?? 75);

$term = isset($_GET['term']) ? sanitize($_GET['term']) : getDefaultTerm();
$valid_terms = getActiveTerms();
if (!in_array($term, $valid_terms, true)) {
    $term = getDefaultTerm();
}

// Optional grade-level filter — when the person clicks a grade row on
// the Enrollment Status table, the dashboard re-requests this endpoint
// with ?grade_level=Grade 7 so Grade Submission Tracker, At-Risk &
// Failing Students, and Teacher Load all narrow down to just that
// grade instead of showing every grade mixed together.
$grade_filter = isset($_GET['grade_level']) ? sanitize($_GET['grade_level']) : '';
$valid_grades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
if (!in_array($grade_filter, $valid_grades, true)) {
    $grade_filter = '';
}

// Natural (numeric) grade-level ordering shared by every query below —
// plain "ORDER BY grade_level" sorts alphabetically as text, which
// puts "Grade 10"/"Grade 11" before "Grade 7", "Grade 8", "Grade 9".
// Pulling out the trailing number and casting it fixes that regardless
// of how many grade levels exist.
$GRADE_ORDER_SQL = "CAST(SUBSTRING_INDEX(grade_level, ' ', -1) AS UNSIGNED)";

// ============================================================
// 1. ENROLLMENT STATUS — live counts per grade level
//
// LEFT JOINed against a fixed Grade 7-12 list (rather than a plain
// GROUP BY on enrollments) so every grade level always gets a row,
// even one with zero enrollments for the active school year — a
// grade with no applicants yet used to disappear from the table
// entirely instead of showing a 0 row like the others.
// ============================================================
$enrollment_status = getRecords("
    SELECT gl.grade_level,
           COALESCE(SUM(CASE WHEN e.enrollment_status = 'Pending' THEN 1 ELSE 0 END), 0) AS pending,
           COALESCE(SUM(CASE WHEN e.enrollment_status = 'Approved' THEN 1 ELSE 0 END), 0) AS approved,
           COALESCE(SUM(CASE WHEN e.enrollment_status IN ('Incomplete Requirements','Rejected') THEN 1 ELSE 0 END), 0) AS rejected,
           COUNT(e.enrollment_id) AS total
    FROM (
        SELECT 'Grade 7' AS grade_level UNION ALL
        SELECT 'Grade 8' UNION ALL
        SELECT 'Grade 9' UNION ALL
        SELECT 'Grade 10' UNION ALL
        SELECT 'Grade 11' UNION ALL
        SELECT 'Grade 12'
    ) gl
    LEFT JOIN enrollments e ON e.grade_level = gl.grade_level AND e.school_year_id = ?
    GROUP BY gl.grade_level
    ORDER BY CAST(SUBSTRING_INDEX(gl.grade_level, ' ', -1) AS UNSIGNED)
", [$school_year_id]);

$enrollment_totals = getRecord("
    SELECT
        SUM(CASE WHEN enrollment_status = 'Pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN enrollment_status = 'Approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN enrollment_status IN ('Incomplete Requirements','Rejected') THEN 1 ELSE 0 END) AS rejected,
        COUNT(*) AS total
    FROM enrollments
    WHERE school_year_id = ?
", [$school_year_id]);

// ============================================================
// 2. SECTION OCCUPANCY — capacity vs. approved vs. still-pending walk-ins
// ============================================================
$section_occupancy = getRecords("
    SELECT sec.section_id, sec.section_name, sec.grade_level, sec.strand,
           sec.capacity, sec.current_enrollment AS approved,
           COALESCE(p.pending_count, 0) AS pending
    FROM sections sec
    LEFT JOIN (
        SELECT section_id, COUNT(*) AS pending_count
        FROM enrollments
        WHERE school_year_id = ? AND enrollment_status = 'Pending'
        GROUP BY section_id
    ) p ON p.section_id = sec.section_id
    WHERE sec.is_active = 1
    ORDER BY CAST(SUBSTRING_INDEX(sec.grade_level, ' ', -1) AS UNSIGNED), sec.section_name
", [$school_year_id]);

foreach ($section_occupancy as &$sec) {
    $sec['capacity'] = intval($sec['capacity']);
    $sec['approved'] = intval($sec['approved']);
    $sec['pending'] = intval($sec['pending']);
    $occupied = $sec['approved'] + $sec['pending'];
    $sec['remaining'] = max(0, $sec['capacity'] - $occupied);
    $sec['occupied'] = $occupied;
    $sec['percent_full'] = $sec['capacity'] > 0 ? round(min(100, ($occupied / $sec['capacity']) * 100)) : 0;
}
unset($sec);

// ============================================================
// 3. GRADE SUBMISSION / COMPLETION TRACKER (per selected term)
// ============================================================
$grade_completion_sql = "
    SELECT ts.teacher_id, u.first_name, u.last_name, ts.subject_code, sub.subject_name,
           ts.section_id, sec.section_name, sec.grade_level,
           (SELECT COUNT(*) FROM enrollments e 
            WHERE e.section_id = ts.section_id AND e.school_year_id = ts.school_year_id 
            AND e.enrollment_status = 'Approved') AS expected_students,
           (SELECT COUNT(DISTINCT g.student_id) FROM grades g 
            WHERE g.subject_code = ts.subject_code AND g.section_id = ts.section_id 
            AND g.school_year_id = ts.school_year_id AND g.term = ?
            AND " . gh_validTermSql('g') . ") AS graded_students
    FROM teacher_subjects ts
    JOIN users u ON u.user_id = ts.teacher_id
    JOIN subjects sub ON sub.subject_code = ts.subject_code
    JOIN sections sec ON sec.section_id = ts.section_id
    WHERE ts.school_year_id = ?";
$grade_completion_params = [$term, $school_year_id];
if ($grade_filter !== '') {
    $grade_completion_sql .= " AND sec.grade_level = ?";
    $grade_completion_params[] = $grade_filter;
}
if ($is_teacher_scoped) {
    $grade_completion_sql .= " AND ts.teacher_id = ?";
    $grade_completion_params[] = $scoped_teacher_id;
}
$grade_completion_sql .= " ORDER BY CAST(SUBSTRING_INDEX(sec.grade_level, ' ', -1) AS UNSIGNED), sec.section_name, sub.subject_name";
$grade_completion = getRecords($grade_completion_sql, $grade_completion_params);

foreach ($grade_completion as &$row) {
    $row['expected_students'] = intval($row['expected_students']);
    $row['graded_students'] = intval($row['graded_students']);
    $row['complete'] = $row['expected_students'] > 0 && $row['graded_students'] >= $row['expected_students'];
    $row['teacher_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
}
unset($row);

// ============================================================
// 4. AT-RISK STUDENTS — average grade within +/-5 of the passing mark
// ============================================================
$at_risk_low = $pass_mark - 5;
$at_risk_high = $pass_mark + 5;

$at_risk_sql = "
    SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.grade_level,
           sec.section_name, AVG(g.final_grade) AS average
    FROM students s
    JOIN enrollments e ON e.student_id = s.student_id AND e.school_year_id = ?
    LEFT JOIN sections sec ON sec.section_id = e.section_id
    JOIN grades g ON g.student_id = s.student_id AND g.school_year_id = ?
                  AND " . gh_encodedTermSql('g') . "
    WHERE s.is_active = 1 AND e.enrollment_status = 'Approved'";
$at_risk_params = [$school_year_id, $school_year_id];
if ($is_teacher_scoped) {
    // A subquery, not a JOIN, so a teacher who teaches this section more
    // than once (multiple subjects) never fans out and double-counts a
    // student's grade rows in the AVG() below.
    $at_risk_sql .= " AND e.section_id IN (SELECT section_id FROM teacher_subjects WHERE teacher_id = ? AND school_year_id = ?)";
    $at_risk_params[] = $scoped_teacher_id;
    $at_risk_params[] = $school_year_id;
}
if ($grade_filter !== '') {
    $at_risk_sql .= " AND s.grade_level = ?";
    $at_risk_params[] = $grade_filter;
}
$at_risk_sql .= "
    GROUP BY s.student_id
    ORDER BY average ASC";
$at_risk = getRecords($at_risk_sql, $at_risk_params);

foreach ($at_risk as &$stu) {
    $stu['average'] = round(floatval($stu['average']), 2);
    $stu['student_name'] = trim($stu['last_name'] . ', ' . $stu['first_name']);
}
unset($stu);

// Include students whose current academic record is Incomplete, matching
// the Teacher AI Academic At-Risk view instead of excluding them as the
// computation-only valid-grade helper does.
$at_risk = array_values(array_filter($at_risk, function ($stu) use ($school_year_id, $at_risk_low, $at_risk_high) {
    $subjects = getAtRiskSubjectRecords((int)$stu['student_id'], $school_year_id);
    $average = (float)$stu['average'];
    return !empty($subjects) || ($average >= $at_risk_low && $average < $at_risk_high);
}));

// Students already failing (below the at-risk band) — surfaced separately
$failing_sql = "
    SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.grade_level,
           sec.section_name, AVG(g.final_grade) AS average
    FROM students s
    JOIN enrollments e ON e.student_id = s.student_id AND e.school_year_id = ?
    LEFT JOIN sections sec ON sec.section_id = e.section_id
    JOIN grades g ON g.student_id = s.student_id AND g.school_year_id = ?
                  AND " . gh_encodedTermSql('g') . "
    WHERE s.is_active = 1 AND e.enrollment_status = 'Approved'";
$failing_params = [$school_year_id, $school_year_id];
if ($is_teacher_scoped) {
    $failing_sql .= " AND e.section_id IN (SELECT section_id FROM teacher_subjects WHERE teacher_id = ? AND school_year_id = ?)";
    $failing_params[] = $scoped_teacher_id;
    $failing_params[] = $school_year_id;
}
if ($grade_filter !== '') {
    $failing_sql .= " AND s.grade_level = ?";
    $failing_params[] = $grade_filter;
}
$failing_sql .= "
    GROUP BY s.student_id
    ORDER BY average ASC";
$failing = getRecords($failing_sql, $failing_params);

foreach ($failing as &$stu) {
    $stu['average'] = round(floatval($stu['average']), 2);
    $stu['student_name'] = trim($stu['last_name'] . ', ' . $stu['first_name']);
}
unset($stu);

$failing = array_values(array_filter($failing, function ($stu) use ($school_year_id, $at_risk_low) {
    $subjects = getAtRiskSubjectRecords((int)$stu['student_id'], $school_year_id);
    $has_incomplete = false;
    foreach ($subjects as $subject) {
        if (($subject['remarks'] ?? '') === 'Incomplete') {
            $has_incomplete = true;
            break;
        }
    }
    return !$has_incomplete && (float)$stu['average'] < $at_risk_low;
}));

echo json_encode([
    'generated_at' => date('Y-m-d H:i:s'),
    'school_year' => $active_sy['sy_name'] ?? null,
    'term' => $term,
    'grade_filter' => $grade_filter,
    'pass_mark' => $pass_mark,
    'enrollment_status' => $enrollment_status,
    'enrollment_totals' => $enrollment_totals,
    'section_occupancy' => $section_occupancy,
    'grade_completion' => $grade_completion,
    'at_risk' => $at_risk,
    'failing' => $failing,
]);