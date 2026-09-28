<?php
// index.php
// Main dashboard page

require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/grading_helpers.php';
require_once 'includes/attendance_qr_helpers.php'; // attQrLrnLink(): LRN becomes the QR link once approved
// Shared at-risk classification (calculateStudentRisk). The dashboard must
// use the SAME risk logic as the AI module, Analytics and the student pages
// instead of its own ad-hoc threshold, so a student is never "High Risk"
// on one page and something else on another.
require_once 'includes/risk_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

$page_title = 'Dashboard';
$page_subtitle = 'Overview of the system';
$current_page = 'dashboard';
$base_url = BASE_URL;

// Redirect to login if not logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Include header
include 'includes/header.php';

// Get statistics based on role
$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// Dashboard statistics
$stats = [];
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");

if (hasAnyRole(['Administrator', 'Registrar'])) {
    // Total Students
    $stats['total_students'] = getCount('students', 'is_active = 1');
    // Total Teachers
    $stats['total_teachers'] = getCount('users', 'role_id = 3 AND is_active = 1');
    // Active Enrollments
    $stats['active_enrollments'] = getCount('enrollments', "enrollment_status = 'Approved'");
    // Passing Rate
    // Passing rate counts ONLY valid, completed Term 1-3 records.
    $passing_sql = "SELECT COUNT(*) as total, SUM(CASE WHEN final_grade >= " . (float)getGradingPassMark() . " THEN 1 ELSE 0 END) as passing
                    FROM grades
                    WHERE school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1)
                      AND " . gh_validTermSql('') . "";
    $passing_result = getRecord($passing_sql);
    $stats['passing_rate'] = $passing_result && $passing_result['total'] > 0 ? round(($passing_result['passing'] / $passing_result['total']) * 100, 2) : 0;
}

if (hasRole('Teacher')) {
    $sql = "SELECT COUNT(DISTINCT section_id) as total
            FROM teacher_subjects
            WHERE teacher_id = ?
              AND school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1)";
    $result = getRecord($sql, [$user_id]);
    $stats['my_sections'] = $result ? $result['total'] : 0;

    $sql = "SELECT COUNT(DISTINCT s.student_id) as total FROM students s JOIN enrollments e ON s.student_id = e.student_id JOIN teacher_subjects ts ON e.section_id = ts.section_id WHERE ts.teacher_id = ? AND e.enrollment_status = 'Approved'";
    $result = getRecord($sql, [$user_id]);
    $stats['my_students'] = $result ? $result['total'] : 0;

    $sql = "SELECT COUNT(DISTINCT subject_code) as total
            FROM teacher_subjects
            WHERE teacher_id = ?
              AND school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1)";
    $result = getRecord($sql, [$user_id]);
    $stats['my_subjects'] = $result ? $result['total'] : 0;

    // ✅ AI Student Risk Analysis (teacher-scoped) — same thresholds as modules/ai/index.php
    $teacher_risk_low = 0;
    $teacher_risk_medium = 0;
    $teacher_risk_high = 0;
    if ($active_sy) {
        $teacher_students_for_risk = getRecords("
            SELECT DISTINCT s.student_id
            FROM students s
            JOIN enrollments e ON s.student_id = e.student_id
            JOIN teacher_subjects ts ON e.section_id = ts.section_id
            WHERE ts.teacher_id = ? AND e.enrollment_status = 'Approved' AND e.school_year_id = ?",
            [$user_id, $active_sy['sy_id']]
        );
        foreach ($teacher_students_for_risk as $trs) {
            $grades_for_risk = getRecords(
                "SELECT final_grade FROM grades WHERE student_id = ? AND school_year_id = ?
                   AND " . gh_validTermSql('') . "",
                [$trs['student_id'], $active_sy['sy_id']]
            );
            if ($grades_for_risk && count($grades_for_risk) > 0) {
                $fg = array_column($grades_for_risk, 'final_grade');
                $avg = array_sum($fg) / count($fg);
                $min_g = min($fg);
                if ($avg < 75 || $min_g < 70) {
                    $teacher_risk_high++;
                } elseif ($avg < 80 || $min_g < 75) {
                    $teacher_risk_medium++;
                } else {
                    $teacher_risk_low++;
                }
            }
        }
    }
    $stats['risk_low'] = $teacher_risk_low;
    $stats['risk_medium'] = $teacher_risk_medium;
    $stats['risk_high'] = $teacher_risk_high;

    // ---- My Classes term filter -----------------------------------------
    // A subject's term comes from subjects.term (Junior High subjects have
    // no term value and are always shown; Senior High subjects belong to
    // exactly one term). The administrator's Term Dates determine which term
    // is shown; teachers do not manually switch the dashboard term.
    $dash_all_terms = gh_threeTerms();
    $dash_current_term = gh_getCurrentTerm($active_sy['sy_id'] ?? 0);
    $dash_selected_term = $dash_current_term ?? $dash_all_terms[0];

    $my_classes = [];
    $at_risk_list = [];
    if ($active_sy) {
        $my_classes = getRecords("
            SELECT ts.section_id, s.section_name, s.grade_level, s.strand,
                   sub.subject_code, sub.subject_name, sub.term as subject_term,
                   (SELECT COUNT(*) FROM enrollments e
                    WHERE e.section_id = ts.section_id
                    AND e.school_year_id = ts.school_year_id
                    AND e.enrollment_status = 'Approved') as student_count
            FROM teacher_subjects ts
            JOIN sections s ON ts.section_id = s.section_id
            JOIN subjects sub ON ts.subject_code = sub.subject_code
            WHERE ts.teacher_id = ?
            AND ts.school_year_id = ?
            AND (sub.term IS NULL OR sub.term = ?)
            ORDER BY CAST(REPLACE(LOWER(s.grade_level), 'grade ', '') AS UNSIGNED),
                     s.section_name, sub.subject_name
        ", [$user_id, $active_sy['sy_id'], $dash_selected_term]);
            foreach ($my_classes as &$__class) {
                $__schedule = getRecords("SELECT day_of_week, TIME_FORMAT(start_time, '%H:%i') AS start_time, TIME_FORMAT(end_time, '%H:%i') AS end_time FROM teacher_class_schedule WHERE teacher_id=? AND subject_code=? AND section_id=? AND school_year_id=? AND is_active=1 ORDER BY day_of_week, start_time", [$user_id, $__class['subject_code'], $__class['section_id'], $active_sy['sy_id']]);
                $__dayNames = [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri'];
                $__class['schedule_text'] = $__schedule ? date('g:i', strtotime($__schedule[0]['start_time'])) . '–' . date('g:i A', strtotime($__schedule[0]['end_time'])) . ' · ' . implode('-', array_map(function ($row) use ($__dayNames) { return $__dayNames[(int)$row['day_of_week']] ?? ''; }, $__schedule)) : '';
            }
            unset($__class);

        // Group the flat per-subject list above by section so the Dashboard
        // visibly matches Grade Management's own Section -> Subjects
        // hierarchy (e.g. Grade 8's "2 sections" there is the same 2 groups
        // here, whose card counts add up to however many subject-cards show:
        // 8-A's 3 + 8-C's 1). Same source data (teacher_subjects), just
        // grouped for display instead of shown as one flat list.
        $my_classes_by_section = [];
        foreach ($my_classes as $__cls) {
            $__sec_id = $__cls['section_id'];
            if (!isset($my_classes_by_section[$__sec_id])) {
                $my_classes_by_section[$__sec_id] = [
                    'section_id' => $__sec_id,
                    'section_name' => $__cls['section_name'],
                    'grade_level' => $__cls['grade_level'],
                    'strand' => $__cls['strand'],
                    'classes' => [],
                ];
            }
            $my_classes_by_section[$__sec_id]['classes'][] = $__cls;
        }

        $at_risk_list = getRecords("
            SELECT s.student_id, s.first_name, s.last_name, s.grade_level,
                   sec.section_name,
                   AVG(g.final_grade) as avg_grade,
                   MIN(g.final_grade) as min_grade
            FROM students s
            JOIN enrollments e ON s.student_id = e.student_id
            JOIN sections sec ON e.section_id = sec.section_id
            JOIN teacher_subjects ts ON e.section_id = ts.section_id
            JOIN grades g ON s.student_id = g.student_id AND g.school_year_id = ?
                          AND " . gh_validTermSql('g') . "
            WHERE ts.teacher_id = ? AND e.enrollment_status = 'Approved'
            AND e.school_year_id = ? AND ts.school_year_id = ?
            GROUP BY s.student_id, sec.section_name
            HAVING avg_grade < 80 OR min_grade < 75
            ORDER BY avg_grade ASC
        ", [$active_sy['sy_id'], $user_id, $active_sy['sy_id'], $active_sy['sy_id']]);
    }
}

if (hasRole('Student')) {
    $sql = "SELECT s.*, e.enrollment_status, sy.sy_name FROM students s LEFT JOIN enrollments e ON s.student_id = e.student_id LEFT JOIN school_years sy ON e.school_year_id = sy.sy_id WHERE s.user_id = ? AND sy.is_active = 1";
    $student = getRecord($sql, [$user_id]);

    if ($student) {
        $sql = "SELECT AVG(final_grade) as average FROM grades WHERE student_id = ? AND " . gh_validTermSql('') . "";
        $result = getRecord($sql, [$student['student_id']]);
        $stats['average_grade'] = $result && $result['average'] ? round($result['average'], 2) : 0;

        $sql = "SELECT COUNT(DISTINCT subject_code) as total FROM grades WHERE student_id = ? AND " . gh_validTermSql('') . "";
        $result = getRecord($sql, [$student['student_id']]);
        $stats['subjects_taken'] = $result ? $result['total'] : 0;
    }
}

// Enrollment Officer's own dashboard — processes the SAME online
// applications / walk-in enrollment data as the Administrator/Registrar
// pages (modules/enrollment/*), just summarized here. "Walk-in" isn't a
// separate stored flag — an enrollment is "online" when an online_applications
// row points back at it (set when application_action.php approves an
// application); everything else is walk-in (entered directly via
// modules/enrollment/new.php). No new table, no new query pattern —
// same distinction application_action.php itself already relies on.
$eo_recent_applications = [];
$eo_recent_walkins = [];
if (hasRole('Enrollment Officer')) {
    require_once 'includes/enrollment_helpers_online.php';
    require_once 'includes/eo_scope.php';
    // Own grade level only (nothing when none is assigned yet).
    [$eo_e, $eo_p] = eoSql('e.grade_level');
    [$eo_a, $eo_ap] = eoSql('grade_level');
    $stats['pending_applications'] = getPendingApplicationCount();

    $stats['online_enrolled'] = getCount(
        'enrollments e',
        "e.enrollment_status = 'Approved' AND EXISTS (SELECT 1 FROM online_applications oa WHERE oa.enrollment_id = e.enrollment_id)" . $eo_e, $eo_p
    );
    $stats['walkin_enrolled'] = getCount(
        'enrollments e',
        "e.enrollment_status = 'Approved' AND NOT EXISTS (SELECT 1 FROM online_applications oa WHERE oa.enrollment_id = e.enrollment_id)" . $eo_e, $eo_p
    );
    $stats['total_enrolled'] = $stats['online_enrolled'] + $stats['walkin_enrolled'];

    $stats['todays_walkins'] = getCount(
        'enrollments e',
        "e.enrollment_date = CURDATE() AND NOT EXISTS (SELECT 1 FROM online_applications oa WHERE oa.enrollment_id = e.enrollment_id)" . $eo_e, $eo_p
    );

    $eo_recent_applications = getRecords("
        SELECT application_id, reference_no, first_name, last_name, grade_level, status, created_at
        FROM online_applications
        WHERE deleted_at IS NULL" . $eo_a . "
        ORDER BY created_at DESC
        LIMIT 5
    ", $eo_ap);

    $eo_recent_walkins = getRecords("
        SELECT e.enrollment_id, e.enrollment_date, e.enrollment_status, e.grade_level, e.strand,
               s.first_name, s.last_name, s.lrn, sec.section_name
        FROM enrollments e
        JOIN students s ON e.student_id = s.student_id
        LEFT JOIN sections sec ON sec.section_id = e.section_id
        WHERE NOT EXISTS (SELECT 1 FROM online_applications oa WHERE oa.enrollment_id = e.enrollment_id)" . $eo_e . "
        ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
        LIMIT 5
    ", $eo_p);
}

// --- START: DATA FOR ANALYTICS (ADMIN ONLY) ---
$students_with_grades = [];
$subject_performance = [];
$total_students = 0;
$passing_count = 0;
$failing_count = 0;
$total_average = 0;
$overall_average = 0;
$passing_rate = 0;
$at_risk_students = [];

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
    foreach ($students_with_grades as $s) {
        $avg = round($s['average_grade'] ?? 0, 2);
        if ($avg >= 75) {
            $passing_count++;
        } else {
            $failing_count++;
            $at_risk_students[] = $s;
        }
        $total_average += $avg;
    }
    $overall_average = $total_students > 0 ? round($total_average / $total_students, 2) : 0;
    $passing_rate = $total_students > 0 ? round(($passing_count / $total_students) * 100, 2) : 0;
}
// --- END: DATA FOR ANALYTICS ---

// --- ADMIN: Additional data for redesigned dashboard ---
$recent_enrollments = [];
$recent_activity = [];
$dash_at_risk = [];
$dash_at_risk_total = 0;
$overview_subjects = 0;
$overview_class_records = 0;
$overview_tutorials = 0;
$overview_teachers_total = 0;
$overview_teachers_present_today = 0;
$overview_attendance_rate = 0;
$enrollment_status_breakdown = [];
$enrollment_status_total = 0;
$es_color_map = [];
$enrollment_trend_labels = [];
$enrollment_trend_values = [];

if (hasRole('Administrator') && $active_sy) {
    // Columns are listed explicitly instead of `e.*` so `grade_level` can
    // only ever come from the ENROLLMENT record (what the learner was
    // enrolled as) and never be shadowed by the students table.
    // Sorted on the real date value (newest first), with enrollment_id only
    // as a tie-breaker for several enrolments recorded on the same day.
    $recent_enrollments = getRecords("
        SELECT e.enrollment_id, e.enrollment_date, e.enrollment_status,
               e.grade_level, e.strand,
               s.first_name, s.last_name, s.lrn,
               sec.section_name
        FROM enrollments e
        JOIN students s ON e.student_id = s.student_id
        LEFT JOIN sections sec ON sec.section_id = e.section_id
        WHERE e.school_year_id = ?
        ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
        LIMIT 5
    ", [$active_sy['sy_id']]);

    $recent_activity = getRecords("
        SELECT al.*, u.first_name, u.last_name
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        ORDER BY al.created_at DESC
        LIMIT 10
    ");

    // ---- At-Risk preview -------------------------------------------------
    // One row per approved student with current encoded grades. The teacher
    // AI view treats a below-passing OR Incomplete subject as at-risk, so
    // this candidate list must include Incomplete records as well.
    $risk_candidates = getRecords("
        SELECT s.student_id, s.first_name, s.last_name, s.grade_level,
               sec.section_name,
               (
                   SELECT AVG(g_avg.final_grade)
                   FROM grades g_avg
                   WHERE g_avg.student_id = s.student_id
                     AND g_avg.school_year_id = ?
                     AND " . gh_validTermSql('g_avg') . "
               ) AS avg_grade,
               (
                   SELECT MIN(g_min.final_grade)
                   FROM grades g_min
                   WHERE g_min.student_id = s.student_id
                     AND g_min.school_year_id = ?
                     AND " . gh_validTermSql('g_min') . "
               ) AS min_grade
        FROM students s
        JOIN enrollments e
          ON e.enrollment_id = (
              SELECT e2.enrollment_id
              FROM enrollments e2
              WHERE e2.student_id = s.student_id
                AND e2.school_year_id = ?
                AND e2.enrollment_status = 'Approved'
              ORDER BY e2.enrollment_id DESC
              LIMIT 1
          )
        LEFT JOIN sections sec ON sec.section_id = e.section_id
        WHERE EXISTS (
            SELECT 1
            FROM grades g
            WHERE g.student_id = s.student_id
              AND g.school_year_id = ?
              AND " . gh_encodedTermSql('g') . "
        )
    ", [$active_sy['sy_id'], $active_sy['sy_id'], $active_sy['sy_id'], $active_sy['sy_id']]);

    foreach ($risk_candidates as $cand) {
        $risk = calculateStudentRisk($cand['student_id'], $active_sy['sy_id']);
        $at_risk_subjects = getAtRiskSubjectRecords($cand['student_id'], $active_sy['sy_id']);
        $teacher_at_risk = (float)$cand['avg_grade'] < 80 || (float)$cand['min_grade'] < 75;
        if (!$teacher_at_risk && empty($at_risk_subjects)) {
            continue;
        }
        if (($risk['status'] ?? '') !== 'ok') {
            $risk = [
                'risk_level' => 'Medium',
                'average' => round((float)$cand['avg_grade'], 2),
                'min_grade' => round((float)$cand['min_grade'], 2),
                'min_subject' => null,
                'failed_subjects' => [],
            ];
        }
        if ($risk['risk_level'] === 'Low' && ($teacher_at_risk || !empty($at_risk_subjects))) {
            $risk['risk_level'] = 'Medium';
        }

        // Weak subjects = the ones actually below the passing mark, lowest
        // first. A Medium-risk learner can be below the average threshold
        // without failing anything; in that case the single weakest subject
        // is shown so the column is never empty and never invented.
        $weak = $risk['failed_subjects'];
        if (empty($weak) && $risk['min_subject'] !== null) {
            $weak = [['subject_name' => $risk['min_subject'], 'grade' => $risk['min_grade']]];
        }

        $dash_at_risk[] = [
            'student_id'   => $cand['student_id'],
            'student_name' => trim($cand['last_name'] . ', ' . $cand['first_name']),
            'section_name' => $cand['section_name'],
            'grade_level'  => $cand['grade_level'],
            'risk_level'   => $risk['risk_level'],
            'average'      => $risk['average'],
            'min_grade'    => $risk['min_grade'],
            'weak'         => $weak,
        ];
    }

    // High before Medium; inside a level, the lowest average first, with the
    // lowest single subject grade as the tie-breaker.
    usort($dash_at_risk, function ($a, $b) {
        $rank = ['High' => 0, 'Medium' => 1];
        if ($rank[$a['risk_level']] !== $rank[$b['risk_level']]) {
            return $rank[$a['risk_level']] <=> $rank[$b['risk_level']];
        }
        if ($a['average'] != $b['average']) {
            return $a['average'] <=> $b['average'];
        }
        return $a['min_grade'] <=> $b['min_grade'];
    });

    $dash_at_risk_total = count($dash_at_risk);

    // ---- System Overview (real counts from existing tables) --------------
    $overview_subjects = getCount('subjects', 'is_active = 1');
    $overview_class_records = getCount('grades', 'school_year_id = ?', [$active_sy['sy_id']]);
    $overview_tutorials = getCount('tutorial_plans', 'school_year_id = ?', [$active_sy['sy_id']]);
    // Teacher attendance — same-day snapshot straight from the real
    // teacher_attendance table (active teachers who have timed in today,
    // out of all active teachers). This intentionally does NOT replicate the
    // schedule-aware Late/Absent logic in modules/attendance/admin_view.php —
    // that page answers a different, more detailed question; this is just
    // today's simple presence rate for the dashboard summary card.
    $overview_teachers_total = (int)($stats['total_teachers'] ?? 0);
    $overview_teachers_present_today = getCount('teacher_attendance', "attendance_date = CURDATE() AND status IN ('Timed In','Completed')");
    $overview_attendance_rate = $overview_teachers_total > 0 ? round(($overview_teachers_present_today / $overview_teachers_total) * 100) : 0;

    // ---- Enrollment Status breakdown (donut chart) ------------------------
    $enrollment_status_breakdown = getRecords("
        SELECT enrollment_status, COUNT(*) as total
        FROM enrollments
        WHERE school_year_id = ?
        GROUP BY enrollment_status
        ORDER BY total DESC
    ", [$active_sy['sy_id']]);
    $enrollment_status_total = 0;
    foreach ($enrollment_status_breakdown as $es_row) { $enrollment_status_total += (int)$es_row['total']; }
    $es_color_map = [
        'Approved'    => '#238863',
        'Pending'     => '#356EA8',
        'Rejected'    => '#C94A4A',
        'Incomplete Requirements' => '#B45309',
        'Transferred' => '#9A7520',
        'Graduated'   => '#667085',
    ];

    // ---- Enrollment Trend (last 6 weeks, cumulative real enrollments) -----
    $enrollment_trend_labels = [];
    $enrollment_trend_values = [];
    for ($w = 5; $w >= 0; $w--) {
        $week_end = date('Y-m-d', strtotime("-{$w} weeks"));
        $enrollment_trend_labels[] = date('M j', strtotime($week_end));
        $enrollment_trend_values[] = getCount('enrollments', "school_year_id = ? AND enrollment_date <= ?", [$active_sy['sy_id'], $week_end]);
    }
}
// --- END: ADMIN Additional data ---

// --- Customizable Admin Dashboard ------------------------------------
// Per-account widget visibility/order layered on TOP of everything above
// — it never touches a query or a calculation, only which of the already-
// computed widgets render below and in what order. Only an Administrator
// can actually save/reset a layout (see save_dashboard_preferences.php);
// a Registrar viewing this same dashboard always gets the registry
// defaults back from getDashboardPreferences() since they never have
// rows of their own in dashboard_preferences.
require_once 'includes/dashboard_helpers.php';
$dw = null;
$is_dashboard_admin = hasRole('Administrator');
if (hasAnyRole(['Administrator', 'Registrar'])) {
    $dw = getDashboardPreferences($user_id);
}

/**
 * Echoes the opening <div> for one dashboard widget: its span class
 * (width), its saved order (as the CSS `order` the grid uses to place
 * it), and display:none when the admin has hidden it. Show/hide and
 * reordering both happen in the "Add / Remove Widgets" panel (a single
 * sortable checklist), not on the card itself — this only wraps the
 * widget's existing markup/content, which is completely unchanged.
 */
function dw_widget_open($dw, $key, $is_admin) {
    $w = $dw[$key];
    $order = (int)$w['order'];
    $style = 'order:' . $order . ';';
    if (!$w['visible']) {
        $style .= 'display:none;';
    }
    echo '<div class="dashboard-widget ' . htmlspecialchars($w['span']) . '" data-widget-key="' . htmlspecialchars($key) . '" data-dashboard-order="' . $order . '" style="' . $style . '">';
}
function dw_widget_close() {
    echo '</div>';
}
?>

<style>
    .dash-stat {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 14px;
        transition: border-color 0.2s, background 0.2s, transform 0.2s, box-shadow 0.2s;
    }
    .dash-stat:hover {
        border-color: var(--accent, #c5c9d6);
        background: var(--accent-soft, var(--surface));
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(20, 33, 61, 0.08);
    }
    /* The icon fills with the card's accent colour on hover, so the whole
       card reads as one target rather than a box with a tile inside it. */
    .dash-stat:hover .dash-stat-icon {
        background: var(--accent) !important;
        color: #fff !important;
    }
    .dash-stat:hover .dash-stat-value,
    .dash-stat:hover .dash-stat-label {
        color: var(--accent);
    }
    .dash-stat:focus-visible {
        outline: 2px solid var(--accent, #14213D);
        outline-offset: 2px;
    }
    .dash-stat-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        flex-shrink: 0;
        transition: background 0.2s, color 0.2s;
    }
    .dash-stat-label {
        font-size: 14.5px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-muted);
        margin-bottom: 4px;
        transition: color 0.2s;
    }
    .dash-stat-value {
        font-family: 'IBM Plex Mono', monospace;
        font-size: 32px;
        font-weight: 700;
        color: var(--text);
        line-height: 1;
        transition: color 0.2s;
    }
    .dash-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 8px;
        overflow: hidden;
    }
    .dash-card-header {
        padding: 16px 20px;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .dash-card-header h6 {
        margin: 0;
        font-size: 16px;
        font-weight: 600;
        color: var(--text);
    }
    .dash-card-body {
        padding: 0;
    }
    .dash-card-body.padded {
        padding: 16px 20px;
    }
    .overview-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 0;
        border-bottom: 1px solid var(--border-soft);
    }
    .overview-row:last-child { border-bottom: none; }
    .overview-icon {
        width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center; font-size: 14.5px;
    }
    .overview-label { flex: 1; font-size: 15px; color: var(--text); }
    .overview-value { font-size: 15px; font-weight: 700; color: var(--ink); }
    .dash-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 15px;
    }
    .dash-table th {
        padding: 12px 20px;
        text-align: left;
        font-size: 13.5px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-muted);
        background: var(--surface-hover);
        border-bottom: 1px solid var(--border);
    }
    .dash-table td {
        padding: 14px 20px;
        border-bottom: 1px solid var(--border);
        color: var(--text);
        vertical-align: middle;
    }
    .dash-table tr:last-child td {
        border-bottom: none;
    }
    .dash-table tr:hover td {
        background: var(--surface-hover);
    }
    .dash-badge {
        display: inline-block;
        padding: 5px 14px;
        border-radius: 20px;
        font-size: 14.5px;
        font-weight: 600;
        line-height: 1.4;
    }
    .dash-badge-success { background: #EAF7F1; color: #238863; }
    .dash-badge-danger { background: #FDEEEE; color: #C94A4A; }
    .dash-badge-warning { background: #FFF7E6; color: #9A7520; }
    .dash-badge-gray { background: #F0F1F4; color: #667085; }
    .dash-badge-pending { background: #EDF4FB; color: #356EA8; }
    /* Recent Activity: failed sign-in attempts (no matching user) stand out */
    .dash-table tr.dash-row-alert td { background: #FEF4F4; }
    .dash-table tr.dash-row-alert:hover td { background: #FCE9E9; }
    .dash-table tr.dash-row-alert td:first-child { box-shadow: inset 4px 0 0 #D64545; }
    .dash-unknown-user { display: inline-flex; align-items: center; gap: 7px; font-weight: 500; font-style: italic; color: var(--text-muted); }
    .dash-badge-alert { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 20px; background: #FDE3E3; color: #B42323; font-size: 14.5px; font-weight: 700; }
    html[data-theme="dark"] .dash-table tr.dash-row-alert td { background: rgba(226, 114, 111, 0.10); }
    html[data-theme="dark"] .dash-table tr.dash-row-alert:hover td { background: rgba(226, 114, 111, 0.17); }
    html[data-theme="dark"] .dash-table tr.dash-row-alert td:first-child { box-shadow: inset 4px 0 0 #EE8683; }
    html[data-theme="dark"] .dash-badge-alert { background: rgba(226, 114, 111, 0.20); color: #F6A9A6; }
    .risk-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 20px;
        border-bottom: 1px solid var(--border);
        font-size: 14.5px;
    }
    .risk-item:last-child {
        border-bottom: none;
    }
    .risk-item-name {
        font-weight: 500;
        color: var(--text);
    }
    .risk-item-meta {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .risk-item-avg {
        font-family: 'IBM Plex Mono', monospace;
        font-weight: 700;
        font-size: 16px;
    }
    .section-label {
        font-size: 14.5px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-muted);
        margin-bottom: 12px;
    }
    .meta-bar {
        display: flex;
        align-items: center;
        gap: 16px;
        font-size: 15px;
        color: var(--text-muted);
        flex-wrap: wrap;
    }
    .meta-bar span {
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .meta-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: var(--success);
        display: inline-block;
    }
    a.dash-stat {
        text-decoration: none;
        color: inherit;
    }
    /* Consistent, medium-sized View All button for every dashboard card.
       Keeps the existing outline style and border radius — only the hit
       area and label size change. */
    .dash-btn-viewall {
        padding: 7px 16px;
        font-size: 14.5px;
        line-height: 1.2;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .dash-card-header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
    }
    /* The At-Risk header holds a title, a count and a button, so it needs to
       be allowed to wrap on narrow screens instead of overflowing. */
    .dash-card-header.dash-card-header-wrap {
        flex-wrap: wrap;
        gap: 10px;
    }
    .risk-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
    }
    /* Tighter horizontal padding so a table still reads comfortably inside
       the narrow (col-lg-4) At-Risk card instead of overflowing it. */
    .dash-table-compact th,
    .dash-table-compact td {
        padding-left: 12px;
        padding-right: 12px;
    }
    .risk-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 2px 7px;
        border-radius: 4px;
        background: #F0F1F4;
        color: #667085;
        font-size: 14.5px;
        line-height: 1.6;
        max-width: 100%;
    }
    .risk-chip-name {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .risk-chip-grade {
        font-family: 'IBM Plex Mono', monospace;
        font-weight: 600;
        color: #C94A4A;
    }
    .empty-state {
        padding: 40px 20px;
        text-align: center;
        color: var(--text-muted);
        font-size: 15px;
    }
    .empty-state i {
        display: block;
        margin-bottom: 8px;
        opacity: 0.4;
    }

    /* ---- Google-Classroom-style "My Classes" cards --------------------
       Deliberately not an exact clone: keeps EGradePro's navy brand color
       and existing dark-mode tokens, but borrows Classroom's signature
       layout -- a colored banner with the class title, a circular avatar
       cut into the seam between banner and body, a kebab (⋮) menu for the
       legacy "Encode Grades" action, and a bottom icon row that jumps
       straight into Classwork/People. */
    .gc-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 14px;
        overflow: visible;
        height: 100%;
        display: flex;
        flex-direction: column;
        position: relative;
        transition: box-shadow .15s ease, transform .15s ease;
    }
    .gc-card:hover {
        box-shadow: 0 8px 20px rgba(20,33,61,0.16);
        transform: translateY(-2px);
    }
    .gc-card-head {
        position: relative;
    }
    .gc-card-banner {
        position: relative;
        display: block;
        background-color: #123B6D;
        background-image:
            linear-gradient(110deg, rgba(8,31,61,0.9), rgba(18,59,109,0.68)),
            url('static/images/school%20bldg%20bg.png');
        background-size: cover;
        background-position: center 42%;
        color: #fff;
        border-radius: 14px 14px 0 0;
        padding: 16px 46px 16px 16px;
        min-height: 88px;
        text-decoration: none;
        text-shadow: 0 1px 3px rgba(0,0,0,0.6);
    }
    .gc-card-banner:hover, .gc-card-banner:focus {
        color: #fff;
        text-decoration: none;
    }
    .gc-card-title {
        font-weight: 700;
        font-size: 15.5px;
        line-height: 1.3;
        color: #fff;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .gc-card-subtitle {
        font-size: 13px;
        color: rgba(255,255,255,0.95);
        opacity: 1;
        margin-top: 4px;
    }
    .gc-card-menu {
        position: absolute;
        top: 8px;
        right: 6px;
    }
    .gc-card-menu-btn {
        color: #fff;
        padding: 4px 8px;
        line-height: 1;
        background: transparent;
        border: none;
        border-radius: 6px;
        cursor: pointer;
    }
    .gc-card-menu-btn:hover,
    .gc-card-menu-btn:focus {
        background: rgba(255,255,255,0.18);
        outline: none;
    }
    /* Same open/close convention as .app-topbar-dropdown in style.css --
       a plain class toggle handled by the delegated click listener below,
       not Bootstrap's JS dropdown (unused elsewhere in this codebase). */
    .gc-card-menu-list {
        display: none;
        position: absolute;
        top: 100%;
        right: 0;
        margin-top: 4px;
        min-width: 170px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 8px;
        box-shadow: 0 6px 18px rgba(0,0,0,0.18);
        padding: 6px;
        z-index: 20;
    }
    .gc-card-menu.open .gc-card-menu-list {
        display: block;
    }
    .gc-card-menu-list a {
        display: block;
        padding: 8px 10px;
        border-radius: 6px;
        font-size: 14.5px;
        color: var(--text);
        text-decoration: none;
    }
    .gc-card-menu-list a:hover {
        background: var(--surface-hover);
    }
    /* Section grouping -- mirrors Grade Management's own Section -> Subjects
       hierarchy so it's visible at a glance that (for example) Grade 8's
       "2 sections" there is these same 2 groups here, whose card counts
       add up to the total subject-cards shown (3 + 1). */
    .gc-section-group {
        margin-bottom: 22px;
    }
    .gc-section-group:last-child {
        margin-bottom: 0;
    }
    .gc-section-heading {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .03em;
        color: var(--text-muted);
        padding-bottom: 8px;
        margin-bottom: 12px;
        border-bottom: 1px dashed var(--border);
    }
    .gc-section-heading i {
        color: #14213D;
    }
    .gc-section-count {
        margin-left: auto;
        background: var(--surface-hover);
        color: var(--text-muted);
        border-radius: 20px;
        padding: 2px 10px;
        font-size: 12.5px;
        text-transform: none;
        letter-spacing: normal;
        font-weight: 600;
    }
    .gc-card-avatar {
        position: absolute;
        right: 16px;
        bottom: -24px;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: var(--surface);
        border: 3px solid var(--surface);
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 6px rgba(0,0,0,0.18);
    }
    .gc-card-avatar img {
        width: 34px;
        height: 34px;
        object-fit: contain;
        border-radius: 50%;
    }
    .gc-card-body {
        padding: 32px 16px 12px;
        flex: 1;
        display: flex;
        flex-direction: column;
    }
    .gc-card-meta {
        font-size: 13.5px;
        color: var(--text-muted);
        margin-bottom: 14px;
    }
    .gc-card-footer {
        margin-top: auto;
        display: flex;
        border-top: 1px solid var(--border);
        padding-top: 6px;
        gap: 4px;
    }
    .gc-card-footer a {
        flex: 1;
        text-align: center;
        padding: 6px 4px;
        border-radius: 8px;
        color: var(--text-muted);
        font-size: 13px;
        text-decoration: none;
    }
    .gc-card-footer a:hover {
        background: var(--surface-hover);
        color: var(--text);
    }
    .gc-card-footer a i {
        display: block;
        font-size: 15px;
        margin-bottom: 2px;
    }
</style>

<?php if (hasAnyRole(['Administrator', 'Registrar'])): ?>
<!-- ============================================================ -->
<!-- ADMIN / REGISTRAR DASHBOARD -->
<!-- ============================================================ -->

<?php $dash_terms_no_end = $active_sy ? gh_termsWithoutEndDate((int) $active_sy['sy_id']) : []; ?>
<?php if (!empty($dash_terms_no_end)): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2" style="border-radius:10px;font-size:15px;">
        <i class="fas fa-calendar-xmark mt-1"></i>
        <div>
            <strong><?php echo htmlspecialchars(implode(', ', $dash_terms_no_end)); ?> <?php echo count($dash_terms_no_end) === 1 ? 'has' : 'have'; ?> no End Date yet.</strong>
            A class moves to the next term only after its term's End Date, so without one the term never ends.
            Set it in <a href="<?php echo BASE_URL; ?>modules/teachers/index.php">Teacher Management &rsaquo; Term Dates</a>.
        </div>
    </div>
<?php endif; ?>

<!-- Meta bar + Customize Dashboard (Administrator only) -->
<div class="mb-3 dash-header-row">
    <div class="section-label mb-0">School Year: <?php echo $active_sy ? htmlspecialchars($active_sy['sy_name']) : 'N/A'; ?></div>

    <?php if ($is_dashboard_admin): ?>
    <button type="button" id="dashCustomizeBtn" class="dash-customize-btn">
        <i class="fas fa-sliders"></i> Customize Dashboard
    </button>

    <!-- Customize Dashboard opens this ONE sortable checklist immediately —
         drag a row to reorder, check/uncheck to show/hide, both previewed
         live on the dashboard behind this modal — and Reset Default / Save
         Layout live right here in the footer too, so the whole feature is
         one place instead of a separate toolbar hidden behind the modal
         backdrop. Rows are built by static/js/dashboard-customize.js from
         the same widget registry PHP uses, so a new widget added to
         includes/dashboard_helpers.php's registry shows up here
         automatically. -->
    <div class="modal fade" id="dwWidgetModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Customize Dashboard</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="padding:20px 22px;">
                    <div class="section-label" style="margin-bottom:4px;">Dashboard Widgets</div>
                    <div style="font-size:14px;color:var(--text-muted);margin-bottom:12px;">Drag to reorder. Check a widget to show it, uncheck to hide it.</div>
                    <div class="dw-select-all-row">
                        <input type="checkbox" id="dwSelectAllChk">
                        <label for="dwSelectAllChk">Select All</label>
                    </div>
                    <div id="dwWidgetChecklist" class="dw-widget-checklist"></div>
                </div>
                <div class="modal-footer dw-modal-footer">
                    <button type="button" id="dwResetBtn" class="dw-tb-btn"><i class="fas fa-rotate-left"></i> Reset Default</button>
                    <button type="button" id="dwCancelBtn" class="btn-app-cancel">Cancel</button>
                    <button type="button" id="dwSaveBtn" class="dw-tb-btn dw-tb-btn-primary"><i class="fas fa-check"></i> Save Layout</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    window.__dashboardCustomize = {
        csrfToken: <?php echo json_encode(generateCSRFToken()); ?>,
        saveUrl: <?php echo json_encode($base_url . 'save_dashboard_preferences.php'); ?>,
        registry: <?php
            $__dw_list = [];
            foreach (getDashboardWidgetRegistry() as $__dw_key => $__dw_def) {
                $__dw_list[] = ['key' => $__dw_key, 'label' => $__dw_def['label']];
            }
            echo json_encode($__dw_list);
        ?>
    };
    </script>
    <?php
        $__dash_customize_js_v = @filemtime(__DIR__ . '/static/js/dashboard-customize.js') ?: time();
    ?>
    <script src="<?php echo BASE_URL; ?>static/js/dashboard-customize.js?v=<?php echo $__dash_customize_js_v; ?>"></script>
    <?php endif; ?>
</div>

<!-- Every Administrator/Registrar widget below lives in ONE grid so any
     widget can be reordered next to any other; dw_widget_open()/close()
     only add the wrapper (order/visibility) — each widget's own markup,
     queries and calculations are completely unchanged. -->
<div class="dashboard-widget-grid" id="dashboardWidgetGrid" data-customizable="<?php echo $is_dashboard_admin ? '1' : '0'; ?>">

<!-- Top Stats -->
<!-- Each card carries its own --accent / --accent-soft pair, which the
     .dash-stat:hover rules above use for the border, tint and icon fill.
     The four are deliberately distinct so the cards are told apart at a
     glance; Active Enrollments used to repeat the Students blue. -->
<?php dw_widget_open($dw, 'stat_total_students', $is_dashboard_admin); ?>
        <a href="modules/students/" class="dash-stat dash-anim-card" style="--accent:#356EA8; --accent-soft:#F4F8FD;">
            <div class="dash-stat-icon" style="background:#EDF4FB;color:#356EA8;"><i class="fas fa-user-graduate"></i></div>
            <div>
                <div class="dash-stat-label">Total Students</div>
                <div class="dash-stat-value" id="dashTotalStudents" data-counter data-target="<?php echo (float)($stats['total_students'] ?? 0); ?>"><?php echo number_format($stats['total_students'] ?? 0); ?></div>
            </div>
        </a>
<?php dw_widget_close(); ?>
<?php dw_widget_open($dw, 'stat_total_teachers', $is_dashboard_admin); ?>
        <a href="modules/teachers/" class="dash-stat dash-anim-card" style="--accent:#238863; --accent-soft:#F2FAF6;">
            <div class="dash-stat-icon" style="background:#EAF7F1;color:#238863;"><i class="fas fa-chalkboard-teacher"></i></div>
            <div>
                <div class="dash-stat-label">Total Teachers</div>
                <div class="dash-stat-value" id="dashTotalTeachers" data-counter data-target="<?php echo (float)($stats['total_teachers'] ?? 0); ?>"><?php echo number_format($stats['total_teachers'] ?? 0); ?></div>
            </div>
        </a>
<?php dw_widget_close(); ?>
<?php dw_widget_open($dw, 'stat_active_enrollments', $is_dashboard_admin); ?>
        <a href="modules/enrollment/" class="dash-stat dash-anim-card" style="--accent:#3730A3; --accent-soft:#F5F5FD;">
            <div class="dash-stat-icon" style="background:#E7E9FB;color:#3730A3;"><i class="fas fa-users"></i></div>
            <div>
                <div class="dash-stat-label">Active Enrollments</div>
                <div class="dash-stat-value" id="dashActiveEnrollments" data-counter data-target="<?php echo (float)($stats['active_enrollments'] ?? 0); ?>"><?php echo number_format($stats['active_enrollments'] ?? 0); ?></div>
            </div>
        </a>
<?php dw_widget_close(); ?>
<?php dw_widget_open($dw, 'stat_passing_rate', $is_dashboard_admin); ?>
        <a href="modules/grading/" class="dash-stat dash-anim-card" style="--accent:#9A7520; --accent-soft:#FDFAF2;">
            <div class="dash-stat-icon" style="background:#FFF7E6;color:#9A7520;"><i class="fas fa-chart-line"></i></div>
            <div>
                <div class="dash-stat-label">Passing Rate</div>
                <div class="dash-stat-value" id="dashPassingRate" data-counter data-target="<?php echo (float)($stats['passing_rate'] ?? 0); ?>" data-suffix="%"><?php echo number_format($stats['passing_rate'] ?? 0); ?>%</div>
            </div>
        </a>
<?php dw_widget_close(); ?>

<!-- Enrollment Status -->
<?php dw_widget_open($dw, 'card_enrollment_status', $is_dashboard_admin); ?>
        <div class="dash-card" style="height:100%;">
            <div class="dash-card-header">
                <h6><i class="fas fa-chart-pie me-2" style="color:#14213D;font-size:15px;"></i>Enrollment Status</h6>
            </div>
            <div class="dash-card-body padded">
                <?php if ($enrollment_status_total > 0): ?>
                <div style="position:relative;width:170px;height:170px;margin:0 auto 16px;">
                    <canvas id="enrollmentStatusChart" width="170" height="170"></canvas>
                    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;pointer-events:none;">
                        <div style="font-size:22px;font-weight:700;color:var(--ink);line-height:1;"><?php echo number_format($enrollment_status_total); ?></div>
                        <div style="font-size:12.5px;color:var(--text-muted);">Total</div>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <?php foreach ($enrollment_status_breakdown as $es_row):
                        $es_pct = $enrollment_status_total > 0 ? round(($es_row['total'] / $enrollment_status_total) * 100, 1) : 0;
                        $es_color = $es_color_map[$es_row['enrollment_status']] ?? '#98A2B3';
                    ?>
                    <div style="display:flex;align-items:center;gap:8px;font-size:14px;">
                        <span style="width:9px;height:9px;border-radius:50%;flex-shrink:0;background:<?php echo $es_color; ?>;"></span>
                        <span style="flex:1;color:var(--text);"><?php echo htmlspecialchars($es_row['enrollment_status']); ?></span>
                        <span style="color:var(--text-muted);white-space:nowrap;"><?php echo (int)$es_row['total']; ?> (<?php echo $es_pct; ?>%)</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-chart-pie fa-2x"></i>No enrollment data yet.</div>
                <?php endif; ?>
            </div>
        </div>
<?php dw_widget_close(); ?>

<!-- Enrollment Trend -->
<?php dw_widget_open($dw, 'card_enrollment_trend', $is_dashboard_admin); ?>
        <div class="dash-card" style="height:100%;">
            <div class="dash-card-header">
                <h6><i class="fas fa-chart-line me-2" style="color:#14213D;font-size:15px;"></i>Enrollment Trend</h6>
                <span style="font-size:13.5px;color:var(--text-muted);">Last 6 weeks</span>
            </div>
            <div class="dash-card-body padded">
                <?php if (!empty($enrollment_trend_values) && array_sum($enrollment_trend_values) > 0): ?>
                <div style="position:relative;height:220px;">
                    <canvas id="enrollmentTrendChart"></canvas>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-chart-line fa-2x"></i>No enrollment history yet.</div>
                <?php endif; ?>
            </div>
        </div>
<?php dw_widget_close(); ?>

<!-- System Overview -->
<?php dw_widget_open($dw, 'card_system_overview', $is_dashboard_admin); ?>
        <div class="dash-card" data-dashboard-substagger="50" style="height:100%;">
            <div class="dash-card-header">
                <h6><i class="fas fa-layer-group me-2" style="color:#14213D;font-size:15px;"></i>System Overview</h6>
            </div>
            <div class="dash-card-body padded">
                <div class="overview-row" data-dashboard-subitem>
                    <div class="overview-icon" style="background:#EDF4FB;color:#356EA8;"><i class="fas fa-book"></i></div>
                    <span class="overview-label">Subjects</span>
                    <span class="overview-value"><?php echo number_format($overview_subjects); ?></span>
                </div>
                <div class="overview-row" data-dashboard-subitem>
                    <div class="overview-icon" style="background:#EAF7F1;color:#238863;"><i class="fas fa-clipboard-list"></i></div>
                    <span class="overview-label">Class Records</span>
                    <span class="overview-value"><?php echo number_format($overview_class_records); ?></span>
                </div>
                <div class="overview-row" data-dashboard-subitem>
                    <div class="overview-icon" style="background:#FFF7E6;color:#9A7520;"><i class="fas fa-calendar-check"></i></div>
                    <span class="overview-label">Teacher Attendance Today</span>
                    <span class="overview-value"><?php echo (int)$overview_attendance_rate; ?>%</span>
                </div>
                <div class="overview-row" data-dashboard-subitem>
                    <div class="overview-icon" style="background:#F5F5FD;color:#3730A3;"><i class="fas fa-graduation-cap"></i></div>
                    <span class="overview-label">Tutorials</span>
                    <span class="overview-value"><?php echo number_format($overview_tutorials); ?></span>
                </div>
            </div>
        </div>
<?php dw_widget_close(); ?>

<!-- Recent Enrollments -->
<?php dw_widget_open($dw, 'card_recent_enrollments', $is_dashboard_admin); ?>
        <div class="dash-card" data-dashboard-substagger="40">
            <div class="dash-card-header">
                <h6><i class="fas fa-user-plus me-2" style="color:#14213D;font-size:15px;"></i>Recent Enrollments</h6>
                <a href="modules/enrollment/" class="btn btn-sm btn-outline-secondary dash-btn-viewall">View All</a>
            </div>
            <div class="dash-card-body">
                <?php if (!empty($recent_enrollments)): ?>
                <div class="table-responsive">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>LRN</th>
                            <th>Grade / Section</th>
                            <th>Status</th>
                            <th>Enrolled</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_enrollments as $enroll): ?>
                        <tr data-dashboard-subitem>
                            <td style="font-weight:500;"><?php echo htmlspecialchars($enroll['last_name'] . ', ' . $enroll['first_name']); ?></td>
                            <td style="font-family:'IBM Plex Mono',monospace;font-size:15px;"><?php echo attQrLrnLink($enroll['lrn'] ?? ''); ?></td>
                            <td>
                                <?php
                                    // Grade and Section in one column. When the learner has no
                                    // section assigned yet, ONLY the grade level is printed —
                                    // no dangling separator, no NULL, no "N/A".
                                    $e_section = trim((string)($enroll['section_name'] ?? ''));
                                    echo htmlspecialchars($enroll['grade_level']);
                                    if ($e_section !== '') {
                                        echo ' <span style="color:var(--text-muted);">&middot;</span> ' . htmlspecialchars($e_section);
                                    }
                                ?>
                            </td>
                            <td>
                                <?php
                                    $e_status = $enroll['enrollment_status'];
                                    $e_class = 'gray';
                                    if ($e_status === 'Approved') $e_class = 'success';
                                    elseif ($e_status === 'Pending') $e_class = 'pending';
                                    elseif (in_array($e_status, ['Incomplete Requirements', 'Rejected'], true)) { $e_class = 'warning'; $e_status = 'Incomplete Requirements'; }
                                    elseif ($e_status === 'Transferred') $e_class = 'warning';
                                ?>
                                <span class="dash-badge dash-badge-<?php echo $e_class; ?>"><?php echo htmlspecialchars($e_status); ?></span>
                            </td>
                            <td style="color:var(--text-muted);font-size:15px;white-space:nowrap;">
                                <?php
                                    // Real enrollment date from the record, always with the year.
                                    $e_date = $enroll['enrollment_date'] ?? '';
                                    $e_ts = $e_date !== '' ? strtotime($e_date) : false;
                                    echo $e_ts ? date('M d, Y', $e_ts) : '&mdash;';
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox fa-2x"></i>
                    No recent enrollments.
                </div>
                <?php endif; ?>
            </div>
        </div>
<?php dw_widget_close(); ?>

<!-- At-Risk Students -->
<?php dw_widget_open($dw, 'card_at_risk_students', $is_dashboard_admin); ?>
        <div class="dash-card" style="height:100%;">
            <div class="dash-card-header dash-card-header-wrap">
                <h6><i class="fas fa-exclamation-triangle me-2" style="color:#C94A4A;font-size:15px;"></i>At-Risk Students</h6>
                <div class="dash-card-header-actions">
                    <span class="dash-badge dash-badge-danger"><?php echo (int)$dash_at_risk_total; ?></span>
                    <a href="modules/reports/at_risk.php" class="btn btn-sm btn-outline-secondary dash-btn-viewall">View All</a>
                </div>
            </div>
            <div class="dash-card-body">
                <?php if (!empty($dash_at_risk)): ?>
                <div class="table-responsive">
                <table class="dash-table dash-table-compact">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Grade / Section</th>
                            <th class="text-center">Grade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($dash_at_risk, 0, 5) as $ar): ?>
                        <?php
                            $ar_section = trim((string)($ar['section_name'] ?? ''));
                        ?>
                        <tr>
                            <td style="font-weight:500;white-space:nowrap;"><?php echo htmlspecialchars($ar['student_name']); ?></td>
                            <td style="color:var(--text-muted);font-size:15px;white-space:nowrap;">
                                <?php
                                    // Grade level from the learner's record, section from
                                    // their approved enrolment — never derived from the
                                    // name, never hardcoded. When no section is assigned
                                    // yet, only the grade level is printed: no dangling
                                    // separator and no "N/A".
                                    echo htmlspecialchars($ar['grade_level']);
                                    if ($ar_section !== '') {
                                        echo ' <span style="color:var(--text-muted);">&middot;</span> ' . htmlspecialchars($ar_section);
                                    }
                                ?>
                            </td>
                            <td class="text-center">
                                <span class="risk-item-avg" style="color:#C94A4A;"><?php echo number_format((float)$ar['average'], 1); ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-check-circle fa-2x"></i>No at-risk students right now.</div>
                <?php endif; ?>
            </div>
        </div>
<?php dw_widget_close(); ?>

<!-- Recent Activity -->
<?php dw_widget_open($dw, 'card_recent_activity', $is_dashboard_admin); ?>
        <div class="dash-card">
            <div class="dash-card-header">
                <h6><i class="fas fa-clock me-2" style="color:#14213D;font-size:15px;"></i>Recent Activity</h6>
                <a href="modules/logs/index.php" class="btn btn-sm btn-outline-secondary dash-btn-viewall">View All</a>
            </div>
            <div class="dash-card-body">
                <?php if (!empty($recent_activity)): ?>
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Module</th>
                            <th>Action</th>
                            <th>Description</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_activity as $log): ?>
                        <?php
                            // Failed sign-in attempts are logged with no user_id, so the User cell used to be blank.
                            $log_user = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''));
                            $log_failed = stripos((string) $log['action'], 'failed') !== false;
                        ?>
                        <tr<?php echo $log_failed ? ' class="dash-row-alert"' : ''; ?>>
                            <?php if ($log_user !== ''): ?>
                            <td style="font-weight:500;"><?php echo htmlspecialchars($log_user); ?></td>
                            <?php else: ?>
                            <td><span class="dash-unknown-user"><i class="fas <?php echo $log_failed ? 'fa-user-slash' : 'fa-user'; ?>" aria-hidden="true"></i> <?php echo $log_failed ? 'Unknown user' : 'Guest'; ?></span></td>
                            <?php endif; ?>
                            <td><span class="dash-badge dash-badge-gray"><?php echo htmlspecialchars($log['module']); ?></span></td>
                            <?php if ($log_failed): ?>
                            <td style="font-size:15px;"><span class="dash-badge-alert"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> <?php echo htmlspecialchars($log['action']); ?></span></td>
                            <?php else: ?>
                            <td style="font-size:15px;"><?php echo htmlspecialchars($log['action']); ?></td>
                            <?php endif; ?>
                            <td style="font-size:15px;color:var(--text-muted);max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($log['description']); ?></td>
                            <td style="font-size:15px;color:var(--text-muted);white-space:nowrap;"><?php echo date('M d, g:i A', strtotime($log['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-history fa-2x"></i>No activity recorded yet.</div>
                <?php endif; ?>
            </div>
        </div>
<?php dw_widget_close(); ?>

</div><!-- /.dashboard-widget-grid -->

<?php endif; ?>

<?php if (hasRole('Teacher')): ?>
<!-- ============================================================ -->
<!-- TEACHER DASHBOARD -->
<!-- ============================================================ -->
<?php $total_at_risk = count($at_risk_list ?? []); ?>

<?php
// Compact Term Calendar hint -- same info shown in Manage Assessment Items
// and Encode Grades (both read/write the same term_windows table), so a
// teacher can see at a glance which term is current and its date range
// without having to open a class first.
$__dash_sy_id = $active_sy['sy_id'] ?? 0;
$__dash_current_term = gh_getCurrentTerm($__dash_sy_id);
$__dash_term_display = $__dash_current_term ?? getDefaultTerm();
$__dash_window = $__dash_sy_id ? gh_getTermWindow($__dash_sy_id, $__dash_term_display) : null;
$__dash_status = gh_termWindowStatus($__dash_window);
$__dash_badge_colors = [
    'secondary' => ['#F2F4F7', '#475467'],
    'info'      => ['#EAF2FE', '#1D4ED8'],
    'success'   => ['#E9F9F1', '#0F7B4E'],
    'warning'   => ['#FEF3E6', '#B54708'],
];
list($__dash_bg, $__dash_fg) = $__dash_badge_colors[$__dash_status['badge']] ?? $__dash_badge_colors['secondary'];
$__dash_range = ($__dash_window && !empty($__dash_window['start_date']) && !empty($__dash_window['end_date']))
    ? formatDate($__dash_window['start_date']) . ' – ' . formatDate($__dash_window['end_date'])
    : 'Dates not set';
?>
<div class="mb-3">
    <span class="btn btn-sm" title="<?php echo htmlspecialchars($__dash_status['label']); ?>"
          style="background:#fff;border:1px solid var(--border, #E4E7EC);color:<?php echo $__dash_fg; ?>;font-size:14.5px;cursor:default;">
        <i class="fas fa-calendar-alt me-1"></i><?php echo htmlspecialchars($__dash_term_display . ': ' . $__dash_range); ?>
    </span>
</div>

<!-- Top Row: 4 Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <a href="modules/teachers/my_sections.php" class="dash-stat dash-anim-card" data-dashboard-order="1">
            <div class="dash-stat-icon" style="background:#EDF4FB;color:#356EA8;"><i class="fas fa-layer-group"></i></div>
            <div>
                <div class="dash-stat-label">My Sections</div>
                <div class="dash-stat-value" id="dashMySections" data-counter data-target="<?php echo (float)($stats['my_sections'] ?? 0); ?>"><?php echo number_format($stats['my_sections'] ?? 0); ?></div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6">
        <a href="modules/students/teacher_students.php" class="dash-stat dash-anim-card" data-dashboard-order="2">
            <div class="dash-stat-icon" style="background:#EAF7F1;color:#238863;"><i class="fas fa-users"></i></div>
            <div>
                <div class="dash-stat-label">My Students</div>
                <div class="dash-stat-value" id="dashMyStudents" data-counter data-target="<?php echo (float)($stats['my_students'] ?? 0); ?>"><?php echo number_format($stats['my_students'] ?? 0); ?></div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6">
        <a href="modules/grading/index.php" class="dash-stat dash-anim-card" data-dashboard-order="3">
            <div class="dash-stat-icon" style="background:#F0F1F4;color:#667085;"><i class="fas fa-book"></i></div>
            <div>
                <div class="dash-stat-label">My Subjects</div>
                <div class="dash-stat-value" id="dashMySubjects" data-counter data-target="<?php echo (float)($stats['my_subjects'] ?? 0); ?>"><?php echo number_format($stats['my_subjects'] ?? 0); ?></div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="dash-stat dash-anim-card" data-dashboard-order="4">
            <div class="dash-stat-icon" style="background:#FDEEEE;color:#C94A4A;"><i class="fas fa-exclamation-triangle"></i></div>
            <div>
                <div class="dash-stat-label">Need Attention</div>
                <div class="dash-stat-value" id="dashNeedAttention" style="color:#C94A4A;" data-counter data-target="<?php echo (float)$total_at_risk; ?>"><?php echo number_format($total_at_risk); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- My Classes Table -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="dash-card" data-dashboard-order="5" data-dashboard-substagger="40">
            <div class="dash-card-header">
                <h6><i class="fas fa-chalkboard me-2" style="color:#14213D;font-size:13.5px;"></i>My Classes</h6>
                <div class="d-flex align-items-center gap-2">
                    <a href="modules/teachers/my_sections.php" class="btn btn-sm btn-outline-secondary" style="font-size:12.5px;padding:2px 10px;">View All</a>
                </div>
            </div>
            <div class="dash-card-body" id="my-classes">
                <?php if (!empty($my_classes)): ?>
                <?php foreach ($my_classes_by_section as $__section): ?>
                <div class="gc-section-group">
                    <div class="gc-section-heading">
                        <i class="fas fa-layer-group"></i>
                        <?php echo htmlspecialchars($__section['grade_level']); ?><?php if (!empty($__section['strand'])) echo ' &middot; ' . htmlspecialchars($__section['strand'] === 'ASSH' ? 'ASSH Cluster' : $__section['strand']); ?>
                        &middot; Section <?php echo htmlspecialchars($__section['section_name']); ?>
                        <span class="gc-section-count"><?php echo count($__section['classes']); ?> class<?php echo count($__section['classes']) === 1 ? '' : 'es'; ?></span>
                    </div>
                    <div class="row g-3">
                        <?php foreach ($__section['classes'] as $cls): ?>
                        <?php $cls_term = $cls['subject_term'] ?? $dash_selected_term; ?>
                        <?php
                            $gc_classroom_url = 'modules/teachers/classroom.php?section_id=' . $cls['section_id']
                                . '&subject_code=' . urlencode($cls['subject_code']) . '&term=' . urlencode($cls_term);
                        ?>
                        <div class="col-md-6 col-lg-4" data-dashboard-subitem>
                            <div class="gc-card">
                                <div class="gc-card-head">
                                    <a class="gc-card-banner" href="<?php echo $gc_classroom_url; ?>">
                                        <div class="gc-card-title"><?php echo htmlspecialchars($cls['subject_name']); ?></div>
                                        <div class="gc-card-subtitle">
                                            <?php echo htmlspecialchars($cls['grade_level']); ?><?php if (!empty($cls['strand'])) echo ' &middot; ' . htmlspecialchars($cls['strand'] === 'ASSH' ? 'ASSH Cluster' : $cls['strand']); ?>
                                            &middot; <?php echo htmlspecialchars($cls['section_name']); ?>
                                        </div>
                                    </a>
                                    <div class="gc-card-menu">
                                        <button type="button" class="gc-card-menu-btn" aria-haspopup="true" aria-expanded="false" title="More options">
                                            <i class="fas fa-ellipsis-vertical"></i>
                                        </button>
                                        <div class="gc-card-menu-list">
                                            <a href="modules/teachers/my_sections.php?grade=<?php echo urlencode($cls['grade_level']); ?>&section_id=<?php echo $cls['section_id']; ?>&subject_code=<?php echo urlencode($cls['subject_code']); ?>">
                                                <i class="fas fa-pen me-2"></i>Encode Grades
                                            </a>
                                        </div>
                                    </div>
                                    <div class="gc-card-avatar">
                                        <img src="<?php echo BASE_URL; ?>static/images/logo.png" alt="Imelda Integrated Secondary School logo">
                                    </div>
                                </div>
                                <div class="gc-card-body">
                                    <div class="gc-card-meta" title="View-only master schedule">
                                        <i class="fas fa-clock me-1"></i><?php echo htmlspecialchars($cls['schedule_text'] ?: 'Schedule not set'); ?>
                                        &middot; <i class="fas fa-users me-1"></i><?php echo intval($cls['student_count']); ?> student<?php echo intval($cls['student_count']) === 1 ? '' : 's'; ?>
                                    </div>
                                    <div class="gc-card-footer">
                                        <a href="<?php echo $gc_classroom_url; ?>&tab=classwork"><i class="fas fa-clipboard-list"></i>Classwork</a>
                                        <a href="<?php echo $gc_classroom_url; ?>&tab=people"><i class="fas fa-users"></i>People</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-chalkboard fa-2x"></i>No classes or subjects have been assigned to you for <?php echo htmlspecialchars($dash_selected_term); ?>.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($my_classes)): ?>
<script>
// Kebab (⋮) menu on each "My Classes" card -- same open/close convention
// as the profile menu's toggleProfileMenu() in footer.php (a plain class
// toggle), not Bootstrap's JS dropdown, which nothing else in this app uses.
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.gc-card-menu-btn');
    document.querySelectorAll('.gc-card-menu.open').forEach(function (menu) {
        if (!btn || menu !== btn.closest('.gc-card-menu')) {
            menu.classList.remove('open');
            var openBtn = menu.querySelector('.gc-card-menu-btn');
            if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
        }
    });
    if (btn) {
        e.preventDefault();
        e.stopPropagation();
        var menu = btn.closest('.gc-card-menu');
        var isOpen = menu.classList.toggle('open');
        btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
});
</script>
<?php endif; ?>

<!-- At-Risk Students -->
<?php if ($total_at_risk > 0): ?>
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="dash-card" data-dashboard-order="6">
            <div class="dash-card-header">
                <h6><i class="fas fa-exclamation-triangle me-2" style="color:#C94A4A;font-size:13.5px;"></i>Students Needing Attention</h6>
                <a href="modules/ai/index.php" class="btn btn-sm btn-outline-secondary" style="font-size:12.5px;padding:2px 10px;">View All</a>
            </div>
            <div class="dash-card-body">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Section</th>
                            <th class="text-center">Average</th>
                            <th class="text-center">Risk Level</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($at_risk_list as $student):
                            $avg = round($student['avg_grade'], 1);
                            if ($avg < 75 || $student['min_grade'] < 70) {
                                $risk_label = 'High';
                                $risk_class = 'dash-badge-danger';
                            } elseif ($avg < 80 || $student['min_grade'] < 75) {
                                $risk_label = 'Medium';
                                $risk_class = 'dash-badge-warning';
                            } else {
                                $risk_label = 'Low';
                                $risk_class = 'dash-badge-pending';
                            }
                        ?>
                        <tr>
                            <td style="font-weight:500;"><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></td>
                            <td style="color:var(--text-muted);font-size:13.5px;"><?php echo htmlspecialchars($student['section_name']); ?></td>
                            <td class="text-center"><span class="risk-item-avg" style="color:#C94A4A;"><?php echo $avg; ?>%</span></td>
                            <td class="text-center"><span class="dash-badge <?php echo $risk_class; ?>"><?php echo $risk_label; ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php elseif (($stats['risk_low'] ?? 0) + ($stats['risk_medium'] ?? 0) + ($stats['risk_high'] ?? 0) === 0): ?>
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="dash-card" data-dashboard-order="6">
            <div class="dash-card-body padded" style="text-align:center;color:var(--text-muted);font-size:14.5px;">
                <i class="fas fa-check-circle me-1" style="color:#238863;"></i> No grade data yet &mdash; risk analysis will appear once grades are encoded.
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (hasRole('Enrollment Officer')): ?>
<!-- ============================================================ -->
<!-- ENROLLMENT OFFICER DASHBOARD -->
<!-- Everything here reads existing online_applications / enrollments data
     through the SAME queries the enrollment modules already use — this
     page never computes a statistic of its own. "New Walk-in Enrollment"
     and "Review Online Applications" open the existing
     modules/enrollment/new.php and modules/enrollment/applications.php
     pages; nothing on this dashboard writes to the database. -->
<!-- ============================================================ -->

<div class="mb-3 dash-header-row">
    <div class="section-label mb-0">School Year: <?php echo $active_sy ? htmlspecialchars($active_sy['sy_name']) : 'N/A'; ?>
        · <?php echo eoGrade() ? 'Assigned: <strong>' . htmlspecialchars(eoGrade()) . '</strong>' : '<span style="color:#B91C1C">No grade level assigned yet — ask the Administrator</span>'; ?></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="modules/enrollment/applications.php" class="dash-customize-btn" style="text-decoration:none;">
            <i class="fas fa-inbox"></i> Review Online Applications
        </a>
        <a href="modules/enrollment/new.php" class="dash-customize-btn" style="text-decoration:none;">
            <i class="fas fa-user-plus"></i> New Walk-in Enrollment
        </a>
    </div>
</div>

<!-- Top Row: 4 Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <a href="modules/enrollment/applications.php" class="dash-stat dash-anim-card" data-dashboard-order="1" style="--accent:#C94A4A; --accent-soft:#FCF3F3;">
            <div class="dash-stat-icon" style="background:#FBEAEA;color:#C94A4A;"><i class="fas fa-inbox"></i></div>
            <div>
                <div class="dash-stat-label">Pending Online Applications</div>
                <div class="dash-stat-value" data-counter data-target="<?php echo (float)($stats['pending_applications'] ?? 0); ?>"><?php echo number_format($stats['pending_applications'] ?? 0); ?></div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6">
        <a href="modules/enrollment/index.php" class="dash-stat dash-anim-card" data-dashboard-order="2" style="--accent:#3730A3; --accent-soft:#F5F5FD;">
            <div class="dash-stat-icon" style="background:#E7E9FB;color:#3730A3;"><i class="fas fa-users"></i></div>
            <div>
                <div class="dash-stat-label">Total Enrolled (This SY)</div>
                <div class="dash-stat-value" data-counter data-target="<?php echo (float)($stats['total_enrolled'] ?? 0); ?>"><?php echo number_format($stats['total_enrolled'] ?? 0); ?></div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6">
        <a href="modules/enrollment/applications.php" class="dash-stat dash-anim-card" data-dashboard-order="3" style="--accent:#238863; --accent-soft:#F2FAF6;">
            <div class="dash-stat-icon" style="background:#EAF7F1;color:#238863;"><i class="fas fa-globe"></i></div>
            <div>
                <div class="dash-stat-label">Enrolled via Online</div>
                <div class="dash-stat-value" data-counter data-target="<?php echo (float)($stats['online_enrolled'] ?? 0); ?>"><?php echo number_format($stats['online_enrolled'] ?? 0); ?></div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6">
        <a href="modules/enrollment/index.php" class="dash-stat dash-anim-card" data-dashboard-order="4" style="--accent:#9A7520; --accent-soft:#FDFAF2;">
            <div class="dash-stat-icon" style="background:#FFF7E6;color:#9A7520;"><i class="fas fa-door-open"></i></div>
            <div>
                <div class="dash-stat-label">Enrolled via Walk-in</div>
                <div class="dash-stat-value" data-counter data-target="<?php echo (float)($stats['walkin_enrolled'] ?? 0); ?>"><?php echo number_format($stats['walkin_enrolled'] ?? 0); ?></div>
            </div>
        </a>
    </div>
</div>

<!-- Recent Online Applications + Recent Walk-in Enrollments -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="dash-card" data-dashboard-order="5" data-dashboard-substagger="40" style="height:100%;">
            <div class="dash-card-header">
                <h6><i class="fas fa-inbox me-2" style="color:#14213D;font-size:15px;"></i>Recent Online Applications</h6>
                <a href="modules/enrollment/applications.php" class="btn btn-sm btn-outline-secondary dash-btn-viewall">View All</a>
            </div>
            <div class="dash-card-body">
                <?php if (!empty($eo_recent_applications)): ?>
                <div class="table-responsive">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Applicant</th>
                            <th>Grade</th>
                            <th>Status</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eo_recent_applications as $app): ?>
                        <tr data-dashboard-subitem>
                            <td style="font-weight:500;">
                                <a href="modules/enrollment/view_application.php?id=<?php echo (int)$app['application_id']; ?>" style="color:inherit;text-decoration:none;">
                                    <?php echo htmlspecialchars($app['last_name'] . ', ' . $app['first_name']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($app['grade_level']); ?></td>
                            <td>
                                <?php
                                    $a_status = $app['status'];
                                    $a_class = 'gray';
                                    if ($a_status === 'Approved') $a_class = 'success';
                                    elseif ($a_status === 'Pending') $a_class = 'pending';
                                    elseif ($a_status === 'Under Review') $a_class = 'warning';
                                    elseif (in_array($a_status, ['Incomplete Requirements', 'Rejected', 'For Correction'], true)) { $a_class = 'warning'; $a_status = 'Incomplete Requirements'; }
                                ?>
                                <span class="dash-badge dash-badge-<?php echo $a_class; ?>"><?php echo htmlspecialchars($a_status); ?></span>
                            </td>
                            <td style="color:var(--text-muted);font-size:15px;white-space:nowrap;">
                                <?php $a_ts = strtotime($app['created_at']); echo $a_ts ? date('M d, Y', $a_ts) : '&mdash;'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox fa-2x"></i>
                    No online applications yet.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="dash-card" data-dashboard-order="6" data-dashboard-substagger="40" style="height:100%;">
            <div class="dash-card-header">
                <h6><i class="fas fa-door-open me-2" style="color:#14213D;font-size:15px;"></i>Recent Walk-in Enrollments</h6>
                <a href="modules/enrollment/index.php" class="btn btn-sm btn-outline-secondary dash-btn-viewall">View All</a>
            </div>
            <div class="dash-card-body">
                <?php if (!empty($eo_recent_walkins)): ?>
                <div class="table-responsive">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Grade / Section</th>
                            <th>Status</th>
                            <th>Enrolled</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eo_recent_walkins as $enroll): ?>
                        <tr data-dashboard-subitem>
                            <td style="font-weight:500;">
                                <a href="modules/enrollment/view.php?id=<?php echo (int)$enroll['enrollment_id']; ?>" style="color:inherit;text-decoration:none;">
                                    <?php echo htmlspecialchars($enroll['last_name'] . ', ' . $enroll['first_name']); ?>
                                </a>
                            </td>
                            <td>
                                <?php
                                    $w_section = trim((string)($enroll['section_name'] ?? ''));
                                    echo htmlspecialchars($enroll['grade_level']);
                                    if ($w_section !== '') {
                                        echo ' <span style="color:var(--text-muted);">&middot;</span> ' . htmlspecialchars($w_section);
                                    }
                                ?>
                            </td>
                            <td>
                                <?php
                                    $w_status = $enroll['enrollment_status'];
                                    $w_class = 'gray';
                                    if ($w_status === 'Approved') $w_class = 'success';
                                    elseif ($w_status === 'Pending') $w_class = 'pending';
                                    elseif (in_array($w_status, ['Incomplete Requirements', 'Rejected'], true)) { $w_class = 'warning'; $w_status = 'Incomplete Requirements'; }
                                    elseif ($w_status === 'Transferred') $w_class = 'warning';
                                ?>
                                <span class="dash-badge dash-badge-<?php echo $w_class; ?>"><?php echo htmlspecialchars($w_status); ?></span>
                            </td>
                            <td style="color:var(--text-muted);font-size:15px;white-space:nowrap;">
                                <?php $w_ts = $enroll['enrollment_date'] ? strtotime($enroll['enrollment_date']) : false; echo $w_ts ? date('M d, Y', $w_ts) : '&mdash;'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-door-open fa-2x"></i>
                    No walk-in enrollments yet.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Wires this dashboard's loader + count-up stats — see
// static/js/dashboard-animations.js for the shared, reusable helpers
// (animateCounter, showPageLoader, hidePageLoader) this calls into.
document.addEventListener('DOMContentLoaded', function () {
    if (typeof initDashboardLoad === 'function') {
        initDashboardLoad({
            cardSelector: '.dash-anim-card',
            onRevealed: function () {
                // Set by includes/header.php from the one-time
                // $_SESSION['login_success_message'] flash, only right
                // after an actual login — never on a plain refresh.
                if (typeof __loginSuccessMessage !== 'undefined' && typeof notifyLoginSuccess === 'function') {
                    notifyLoginSuccess(__loginSuccessMessage);
                }
            }
        });
    }
});
</script>

<?php if (hasRole('Administrator') && $active_sy): ?>
<!-- Chart.js (CDN) — only loaded for the Administrator dashboard, which is
     the only view with the Enrollment Status / Enrollment Trend charts. -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.5.1/chart.umd.min.js"></script>
<script>
// Charts are created right away on DOMContentLoaded, same as before —
// waiting for the sequential entrance to "reach" these cards turned out
// to be an unreliable trigger in practice (the chart could sit blank for
// several seconds). Their card still fades in on its own turn in the
// sequence; the chart itself is simply already drawn underneath by the
// time that happens, which reads as "the chart is there when its card
// appears" without depending on a second timing system. Data/labels are
// untouched — only how the existing chart animates once created.
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

    var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var esChart = null, etChart = null;

    function createEnrollmentStatusChart() {
        var esCanvas = document.getElementById('enrollmentStatusChart');
        if (!esCanvas) return;
        esChart = new Chart(esCanvas, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_column($enrollment_status_breakdown, 'enrollment_status')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_map('intval', array_column($enrollment_status_breakdown, 'total'))); ?>,
                    backgroundColor: <?php echo json_encode(array_map(function ($row) use ($es_color_map) {
                        return $es_color_map[$row['enrollment_status']] ?? '#98A2B3';
                    }, $enrollment_status_breakdown)); ?>,
                    borderWidth: 0
                }]
            },
            options: {
                cutout: '68%',
                responsive: true,
                maintainAspectRatio: true,
                animation: reduceMotion ? false : { duration: 600 },
                plugins: { legend: { display: false }, tooltip: { enabled: true } }
            }
        });
    }

    function createEnrollmentTrendChart() {
        var etCanvas = document.getElementById('enrollmentTrendChart');
        if (!etCanvas) return;
        etChart = new Chart(etCanvas, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($enrollment_trend_labels); ?>,
                datasets: [{
                    label: 'Total Enrollments',
                    data: <?php echo json_encode(array_map('intval', $enrollment_trend_values)); ?>,
                    borderColor: '#356EA8',
                    backgroundColor: 'rgba(53,110,168,0.08)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: '#356EA8',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                // Documented Chart.js "typewriter" animation recipe: each
                // point's x/y only starts interpolating once its own short
                // delay elapses, so the line visibly draws left-to-right
                // instead of every point growing in at once. Skipped
                // entirely under reduced motion, where the final chart
                // just renders straight away.
                animation: reduceMotion ? false : {
                    x: {
                        type: 'number', easing: 'linear', duration: 500,
                        from: NaN,
                        delay: function (ctx) {
                            if (ctx.type !== 'data' || ctx.xStarted) return 0;
                            ctx.xStarted = true;
                            return ctx.index * 45;
                        }
                    },
                    y: {
                        type: 'number', easing: 'linear', duration: 500,
                        from: function (ctx) { return ctx.chart.scales.y.getPixelForValue(0); },
                        delay: function (ctx) {
                            if (ctx.type !== 'data' || ctx.yStarted) return 0;
                            ctx.yStarted = true;
                            return ctx.index * 45;
                        }
                    }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(16,24,40,0.06)' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    createEnrollmentStatusChart();
    createEnrollmentTrendChart();

    // Dark Mode support — re-theme these charts in place when the shared
    // toggle (static/js/theme.js) fires, no page reload. Only text/gridline
    // colors change; each dataset's own brand colors are left alone since
    // they already read fine on both a light and a dark card surface.
    document.addEventListener('egradepro:themechange', function (evt) {
        var dark = evt.detail && evt.detail.theme === 'dark';
        Chart.defaults.color = dark ? '#AEB9CF' : '#667085';
        if (etChart) {
            etChart.options.scales.y.grid.color = dark ? 'rgba(255,255,255,0.08)' : 'rgba(16,24,40,0.06)';
            etChart.update('none');
        }
        if (esChart) esChart.update('none');
    });
});
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>