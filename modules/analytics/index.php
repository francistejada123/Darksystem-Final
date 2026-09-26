<?php
// modules/analytics/index.php
// AI Student Risk & Intervention Analytics — Admin/Registrar view
// (also reachable by Teacher, scoped to their own students, exactly as
// before — Teachers normally use modules/ai/index.php instead).
//
// REDESIGNED: refocused from a general "Performance Analytics" dashboard
// (Students with Grades / Passing Students / Overall Average / Passing vs
// Failing chart / Subject Performance chart / Min-Max performance table)
// into a page that answers five questions only: who is at risk, why, what
// is their trend, what does the system recommend, and what is being done
// about it. The removed statistics are NOT deleted from the codebase —
// modules/reports and modules/students still compute their own copies —
// this file simply no longer displays them.
//
// NOTE (kept from the previous version): a copy of this dashboard was at
// some point saved over modules/attendance/index.php (its header still
// said "modules/analytics"). That path is supposed to serve Teacher
// Attendance. This file is the canonical Analytics page.

require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/risk_helpers.php';
require_once '../../includes/grading_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Registrar', 'Teacher'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'AI Analytics';
$current_page = 'analytics';
$base_url = BASE_URL;

// Get active school year
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
$sy_id = $active_sy ? $active_sy['sy_id'] : 0;

$user_id = $_SESSION['user_id'];
$is_teacher = hasRole('Teacher');
$is_admin = hasAnyRole(['Administrator', 'Registrar']);

// Configurable passing grade — every other page on the system reads it
// from here too, so Analytics never disagrees with Grading/Reports.
$pass_mark = getGradingPassMark();

// ------------------------------------------------------------------
// GRADING PERIOD
// ------------------------------------------------------------------
// The Term filter controls which term's grade rows populate the
// "Subject" / "Current Grade" columns (a genuine per-term snapshot).
// Risk Level / Risk Reason below are DELIBERATELY NOT re-scoped by this
// filter — calculateStudentRisk() is the one shared, centralized risk
// engine used by every page in the system (Tutorial System, Student
// Portal, Grading), and it always evaluates a student's complete record
// for the school year. Changing that here would make this page disagree
// with everywhere else a risk badge appears, so it is left untouched.
$available_terms = getActiveTerms();
$terms_with_data = array_column(getRecords(
    "SELECT DISTINCT term FROM grades WHERE school_year_id = ? AND " . gh_encodedTermSql(''),
    [$sy_id]
), 'term');

$default_term = $available_terms[0];
foreach ($available_terms as $t) {
    if (in_array($t, $terms_with_data, true)) { $default_term = $t; }
}

$selected_term = $_GET['term'] ?? $default_term;
if (!isValidTerm($selected_term)) { $selected_term = $default_term; }

// ============================================================
// 1. TEACHER SCOPING (unchanged) — a Teacher landing on this URL only
// ever sees their own advisory/subject students, never the full roster.
// ============================================================
$teacher_student_ids = [];
$teacher_section_ids = [];

if ($is_teacher) {
    $teacher_sections = getRecords("
        SELECT DISTINCT section_id
        FROM teacher_subjects
        WHERE teacher_id = ? AND school_year_id = ?
    ", [$user_id, $sy_id]);
    $teacher_section_ids = array_column($teacher_sections, 'section_id');

    if (!empty($teacher_section_ids)) {
        $placeholders = implode(',', array_fill(0, count($teacher_section_ids), '?'));
        $teacher_students = getRecords("
            SELECT DISTINCT s.student_id
            FROM students s
            JOIN enrollments e ON s.student_id = e.student_id
            WHERE e.section_id IN ($placeholders)
            AND e.school_year_id = ? AND e.enrollment_status = 'Approved'
        ", array_merge($teacher_section_ids, [$sy_id]));
        $teacher_student_ids = array_column($teacher_students, 'student_id');
    }
}

// ============================================================
// 2. ROSTER — every enrolled, active student with at least one valid
// grade this term, plus their section (needed for the "Grade & Section"
// column and for the Section filter).
// ============================================================
if ($is_admin) {
    $students_with_grades = getRecords("
        SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.grade_level, s.strand,
               sec.section_id, sec.section_name,
               AVG(g.final_grade) as average_grade,
               COUNT(g.grade_id) as subject_count
        FROM students s
        JOIN enrollments e ON s.student_id = e.student_id AND e.school_year_id = ?
        LEFT JOIN sections sec ON sec.section_id = e.section_id
        JOIN grades g ON s.student_id = g.student_id
                      AND g.school_year_id = ?
                      AND g.term = ?
                      AND " . gh_encodedTermSql('g') . "
        WHERE e.enrollment_status = 'Approved' AND s.is_active = 1
        GROUP BY s.student_id
        ORDER BY average_grade ASC
    ", [$sy_id, $sy_id, $selected_term]);
} else {
    $students_with_grades = [];
    if (!empty($teacher_student_ids)) {
        $placeholders = implode(',', array_fill(0, count($teacher_student_ids), '?'));
        $students_with_grades = getRecords("
            SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.grade_level, s.strand,
                   sec.section_id, sec.section_name,
                   AVG(g.final_grade) as average_grade,
                   COUNT(g.grade_id) as subject_count
            FROM students s
            JOIN enrollments e ON s.student_id = e.student_id AND e.school_year_id = ?
            LEFT JOIN sections sec ON sec.section_id = e.section_id
            JOIN grades g ON s.student_id = g.student_id
                          AND g.school_year_id = ?
                          AND g.term = ?
                          AND " . gh_encodedTermSql('g') . "
            WHERE s.student_id IN ($placeholders)
            AND e.enrollment_status = 'Approved' AND s.is_active = 1
            GROUP BY s.student_id
            ORDER BY average_grade ASC
        ", array_merge([$sy_id, $sy_id, $selected_term], $teacher_student_ids));
    }
}

$student_ids = array_column($students_with_grades, 'student_id');

// ============================================================
// 3. RISK — via the one shared helper (includes/risk_helpers.php).
// Same rule-based thresholds used everywhere else in the system.
// ============================================================
$student_risks = [];
$student_at_risk_subjects = [];
foreach ($students_with_grades as $student) {
    $sid = $student['student_id'];
    $student_risks[$sid] = calculateStudentRisk($sid, $sy_id);
    $student_at_risk_subjects[$sid] = getAtRiskSubjectRecords($sid, $sy_id);
}

// ============================================================
// 4. THIS TERM'S WEAKEST SUBJECT — the "Subject" + "Current Grade"
// columns are a per-term snapshot (unlike Risk Level/Reason above),
// computed the same way calculateStudentRisk() computes its own
// min-subject internally: lowest g.final_grade for the student, but
// scoped to $selected_term so the Term filter visibly changes it.
// ============================================================
$weak_subject_this_term = [];
if (!empty($student_ids)) {
    $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
    $weak_rows = getRecords("
        SELECT g.student_id, sub.subject_code, sub.subject_name, g.final_grade
        FROM grades g
        JOIN subjects sub ON g.subject_code = sub.subject_code
        WHERE g.student_id IN ($placeholders) AND g.school_year_id = ? AND g.term = ?
          AND " . gh_encodedTermSql('g') . "
        ORDER BY g.final_grade ASC
    ", array_merge($student_ids, [$sy_id, $selected_term]));
    foreach ($weak_rows as $wr) {
        if (!isset($weak_subject_this_term[$wr['student_id']])) {
            $weak_subject_this_term[$wr['student_id']] = $wr; // first hit = lowest grade (ORDER BY ASC)
        }
    }
}

// ============================================================
// 5. ACADEMIC TREND — per-student average per term, across every term
// that has data (not just the selected one), so a student graded in
// Term 1 and Term 2 shows a real Term-over-term comparison regardless
// of which Term is currently selected in the filter.
// ============================================================
$term_avgs = []; // $term_avgs[$student_id]['Term 1'] = 88.20
if (!empty($student_ids)) {
    $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
    $avg_rows = getRecords("
        SELECT g.student_id, g.term, AVG(g.final_grade) AS avg_grade
        FROM grades g
        WHERE g.student_id IN ($placeholders) AND g.school_year_id = ?
          AND " . gh_validTermSql('g') . "
        GROUP BY g.student_id, g.term
    ", array_merge($student_ids, [$sy_id]));
    foreach ($avg_rows as $ar) {
        $term_avgs[$ar['student_id']][$ar['term']] = round((float)$ar['avg_grade'], 2);
    }
}

/**
 * Compare the two most recent terms (in DepEd Term 1→2→3 order) that
 * actually have grades for this student. A ±0.5 band around zero is
 * treated as "Stable" rather than flagging normal rounding noise as a
 * trend. Never fabricates a term that has no data.
 */
function air_computeTrend($student_term_avgs, $available_terms) {
    $terms_with_data = [];
    foreach ($available_terms as $t) {
        if (isset($student_term_avgs[$t])) { $terms_with_data[] = $t; }
    }
    if (count($terms_with_data) < 2) {
        return [
            'status' => 'insufficient', 'label' => 'Insufficient Data', 'tone' => 'muted',
            'change' => null, 'from_term' => null, 'to_term' => null, 'terms' => $terms_with_data,
        ];
    }
    $to_term = end($terms_with_data);
    $from_term = $terms_with_data[count($terms_with_data) - 2];
    $change = round($student_term_avgs[$to_term] - $student_term_avgs[$from_term], 2);

    if ($change > 0.5) {
        $status = 'improving'; $label = 'Improving'; $tone = 'success';
    } elseif ($change < -0.5) {
        $status = 'declining'; $label = 'Declining'; $tone = 'danger';
    } else {
        $status = 'stable'; $label = 'Stable'; $tone = 'muted';
    }
    return [
        'status' => $status, 'label' => $label, 'tone' => $tone, 'change' => $change,
        'from_term' => $from_term, 'to_term' => $to_term, 'terms' => $terms_with_data,
    ];
}

// ============================================================
// 6. INTERVENTION STATUS — reuses the Tutorial System's own
// tutorial_plans.status (Draft/Active/Completed/Cancelled) rather than
// inventing a new column. Most recent plan per student wins.
//
// Mapping to the vocabulary requested for this page:
//   (no plan at all) -> "Not Reviewed"      — nobody has looked at this student yet
//   Draft             -> "Reviewed"          — a plan exists but hasn't started
//   Active             -> "Intervention Started" — tutoring is currently running
//   Completed          -> "Improving"        — the plan ran its course
//   Cancelled          -> "Not Reviewed"     — treated as if no active support exists
// ============================================================
function air_interventionInfo($plan_status) {
    switch ($plan_status) {
        case 'Draft':     return ['label' => 'Reviewed', 'tone' => 'info'];
        case 'Active':    return ['label' => 'Intervention Started', 'tone' => 'warning'];
        case 'Completed': return ['label' => 'Improving', 'tone' => 'success'];
        default:          return ['label' => 'Not Reviewed', 'tone' => 'muted']; // null or Cancelled
    }
}

$intervention_map = []; // $intervention_map[$student_id] = 'Draft'|'Active'|'Completed'|'Cancelled'|null
if (!empty($student_ids)) {
    $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
    $plan_rows = getRecords("
        SELECT tp.student_id, tp.status
        FROM tutorial_plans tp
        WHERE tp.school_year_id = ? AND tp.student_id IN ($placeholders)
        ORDER BY tp.created_at DESC
    ", array_merge([$sy_id], $student_ids));
    foreach ($plan_rows as $pr) {
        if (!isset($intervention_map[$pr['student_id']])) {
            $intervention_map[$pr['student_id']] = $pr['status']; // first = most recent
        }
    }
}

// ============================================================
// 7. TEACHER / SUBJECT / SECTION FILTER SOURCE DATA — admin sees every
// teacher assignment this school year; a Teacher viewing this page only
// ever sees their own (the Teacher filter itself is hidden for them,
// since there is nothing to choose).
// ============================================================
$ts_filter_sql = "
    SELECT ts.teacher_id, u.first_name, u.last_name,
           ts.subject_code, sub.subject_name, sub.grade_level AS subject_grade,
           ts.section_id, sec.section_name, sec.grade_level
    FROM teacher_subjects ts
    JOIN users u ON u.user_id = ts.teacher_id
    JOIN subjects sub ON sub.subject_code = ts.subject_code
    JOIN sections sec ON sec.section_id = ts.section_id
    WHERE ts.school_year_id = ?";
$ts_filter_params = [$sy_id];
if ($is_teacher) {
    $ts_filter_sql .= " AND ts.teacher_id = ?";
    $ts_filter_params[] = $user_id;
}
$ts_filter_sql .= " ORDER BY u.last_name, u.first_name, sub.subject_name, sec.section_name";
$teacher_subject_rows = getRecords($ts_filter_sql, $ts_filter_params);

// Quick lookup used to attach a teacher_id to each table row below:
// "which teacher teaches this student's weak-subject-this-term, in
// their own section?" — exactly the join the Teacher filter needs.
$ts_lookup = [];
foreach ($teacher_subject_rows as $tsr) {
    $key = $tsr['subject_code'] . '|' . $tsr['section_id'];
    if (!isset($ts_lookup[$key])) {
        $ts_lookup[$key] = (int)$tsr['teacher_id'];
    }
}

// Advisory class(es) of each teacher, shown next to the name in the Teacher filter.
$advisory_by_teacher = [];
foreach (getRecords("SELECT adviser_id, section_name FROM sections
                     WHERE is_active = 1 AND adviser_id IS NOT NULL
                     ORDER BY CAST(REPLACE(grade_level, 'Grade ', '') AS UNSIGNED), section_name") as $adv) {
    $advisory_by_teacher[(int) $adv['adviser_id']][] = $adv['section_name'];
}

$teachers_for_filter = [];
$seen_teacher_ids = [];
foreach ($teacher_subject_rows as $tsr) {
    if (!isset($seen_teacher_ids[$tsr['teacher_id']])) {
        $seen_teacher_ids[$tsr['teacher_id']] = true;
        $teachers_for_filter[] = [
            'id' => (int)$tsr['teacher_id'],
            'name' => trim($tsr['first_name'] . ' ' . $tsr['last_name']),
            'advisory' => $advisory_by_teacher[(int) $tsr['teacher_id']] ?? [],
        ];
    }
}

// ============================================================
// 8. BUILD THE ROW LIST — one row per student, sorted High -> Medium ->
// Low risk (worst average first within each tier), matching the
// "prioritize high/medium risk" requirement without hiding Low risk.
// ============================================================
$risk_rank = ['High' => 0, 'Medium' => 1, 'Low' => 2];
$rows = [];

foreach ($students_with_grades as $student) {
    $sid = $student['student_id'];
    $risk = $student_risks[$sid];
    $at_risk_subjects = $student_at_risk_subjects[$sid] ?? [];
    if ($risk['status'] !== 'ok') {
        if (empty($at_risk_subjects)) {
            continue;
        }
        $first_risk_subject = $at_risk_subjects[0];
        $risk = [
            'risk_level' => 'Medium',
            'reason' => $first_risk_subject['reason'],
            'average' => null,
            'failed_subjects' => [],
        ];
    } elseif (!empty($at_risk_subjects) && $risk['risk_level'] === 'Low') {
        $risk['risk_level'] = 'Medium';
        $risk['reason'] = $at_risk_subjects[0]['reason'];
    }

    $weak = $weak_subject_this_term[$sid] ?? null;
    $trend = air_computeTrend($term_avgs[$sid] ?? [], $available_terms);
    $intervention = air_interventionInfo($intervention_map[$sid] ?? null);

    $section_id = $student['section_id'] ? (int)$student['section_id'] : null;
    $subject_code = $weak['subject_code'] ?? null;
    $teacher_id = ($subject_code && $section_id && isset($ts_lookup[$subject_code . '|' . $section_id]))
        ? $ts_lookup[$subject_code . '|' . $section_id]
        : null;

    $rows[] = [
        'student_id'        => (int)$sid,
        'lrn'               => $student['lrn'],
        'name'              => trim($student['last_name'] . ', ' . $student['first_name']),
        'grade_level'       => $student['grade_level'],
        'section_id'        => $section_id,
        'section_name'      => $student['section_name'],
        'strand'            => $student['strand'],
        'risk_level'        => $risk['risk_level'],
        'risk_reason'       => $risk['reason'],
        'overall_average'   => $risk['average'],
        'subject_code'      => $subject_code ?: ($at_risk_subjects[0]['subject_code'] ?? null),
        'subject_name'      => $weak['subject_name'] ?? ($at_risk_subjects[0]['subject_name'] ?? null),
        'subject_grade'     => isset($weak['final_grade'])
            ? round((float)$weak['final_grade'], 2)
            : (isset($at_risk_subjects[0]['final_grade']) ? round((float)$at_risk_subjects[0]['final_grade'], 2) : null),
        'term'              => $selected_term,
        'teacher_id'        => $teacher_id,
        'trend'             => $trend,
        'intervention'      => $intervention,
        'recommendations'   => generateRiskRecommendations($risk),
        'failed_subjects'   => $risk['failed_subjects'],
    ];
}

usort($rows, function ($a, $b) use ($risk_rank) {
    $ra = $risk_rank[$a['risk_level']] ?? 3;
    $rb = $risk_rank[$b['risk_level']] ?? 3;
    if ($ra !== $rb) { return $ra <=> $rb; }
    return ($a['overall_average'] ?? 0) <=> ($b['overall_average'] ?? 0);
});

// ============================================================
// 9. SUMMARY CARDS — real counts from the exact population rendered
// below (never hard-coded).
// ============================================================
$count_high = 0; $count_medium = 0; $count_low = 0; $count_needs_intervention = 0;
foreach ($rows as $r) {
    if ($r['risk_level'] === 'High') { $count_high++; }
    elseif ($r['risk_level'] === 'Medium') { $count_medium++; }
    elseif ($r['risk_level'] === 'Low') { $count_low++; }
    if (in_array($r['risk_level'], ['High', 'Medium'], true) && $r['intervention']['label'] === 'Not Reviewed') {
        $count_needs_intervention++;
    }
}

include '../../includes/header.php';
?>

<div class="air-page">
<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1 air-fade-up" style="animation-delay:0ms;">
            <div>
                <h4 class="air-title">AI Student Risk &amp; Intervention Analytics</h4>
                <p class="air-subtitle">Identify students who may need academic support and review AI-assisted recommendations based on available academic data.</p>
            </div>
            <span class="air-sy-pill"><i class="fas fa-calendar-alt me-1"></i> SY: <?php echo $active_sy ? htmlspecialchars($active_sy['sy_name']) : 'N/A'; ?></span>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- SUMMARY CARDS -->
<!-- ============================================================ -->
<div class="row g-3 mb-3 mt-1">
    <div class="col-6 col-md-3">
        <div class="air-summary-card air-fade-up" style="animation-delay:60ms;">
            <div class="air-summary-icon air-tone-danger"><i class="fas fa-triangle-exclamation"></i></div>
            <div class="air-summary-body">
                <div class="air-summary-count" id="airCountHigh"><?php echo number_format($count_high); ?></div>
                <div class="air-summary-label">High Risk</div>
                <div class="air-summary-sub">Students</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="air-summary-card air-fade-up" style="animation-delay:110ms;">
            <div class="air-summary-icon air-tone-warning"><i class="fas fa-circle-exclamation"></i></div>
            <div class="air-summary-body">
                <div class="air-summary-count" id="airCountMedium"><?php echo number_format($count_medium); ?></div>
                <div class="air-summary-label">Medium Risk</div>
                <div class="air-summary-sub">Students</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="air-summary-card air-fade-up" style="animation-delay:160ms;">
            <div class="air-summary-icon air-tone-success"><i class="fas fa-circle-check"></i></div>
            <div class="air-summary-body">
                <div class="air-summary-count" id="airCountLow"><?php echo number_format($count_low); ?></div>
                <div class="air-summary-label">Low Risk</div>
                <div class="air-summary-sub">Students</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="air-summary-card air-fade-up" style="animation-delay:210ms;">
            <div class="air-summary-icon air-tone-info"><i class="fas fa-life-ring"></i></div>
            <div class="air-summary-body">
                <div class="air-summary-count" id="airCountNeeds"><?php echo number_format($count_needs_intervention); ?></div>
                <div class="air-summary-label">Needs Intervention</div>
                <div class="air-summary-sub">Not yet reviewed</div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- FILTERS -->
<!-- ============================================================ -->
<div class="air-filter-bar mb-3 air-fade-up" style="animation-delay:250ms;">
    <div class="row g-3 align-items-end">
        <?php if ($is_admin): ?>
        <div class="col-12 col-sm-6 col-lg">
            <label class="air-filter-label">Teacher</label>
            <select class="form-select air-input" id="airFilterTeacher">
                <option value="">All Teachers</option>
                <?php foreach ($teachers_for_filter as $t): ?>
                    <option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['name'] . ($t['advisory'] ? ' — Adviser: ' . implode(', ', $t['advisory']) : ' — No advisory class')); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-12 col-sm-6 col-lg">
            <label class="air-filter-label">Subject</label>
            <select class="form-select air-input" id="airFilterSubject">
                <option value="">All Subjects</option>
            </select>
        </div>
        <div class="col-12 col-sm-6 col-lg">
            <label class="air-filter-label">Section</label>
            <select class="form-select air-input" id="airFilterSection">
                <option value="">All Sections</option>
            </select>
        </div>
        <div class="col-12 col-sm-6 col-lg">
            <label class="air-filter-label">Term</label>
            <form method="get" id="airTermForm">
                <select name="term" class="form-select air-input" onchange="this.form.submit()">
                    <?php foreach ($available_terms as $t): ?>
                        <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $t === $selected_term ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($t); ?><?php echo in_array($t, $terms_with_data, true) ? '' : ' (no grades)'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="col-12 col-sm-6 col-lg">
            <label class="air-filter-label">Grade Level</label>
            <select class="form-select air-input" id="airFilterGrade">
                <option value="">All Grade Levels</option>
                <?php foreach (['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'] as $gl): ?>
                    <option value="<?php echo $gl; ?>"><?php echo $gl; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-sm-6 col-lg">
            <label class="air-filter-label">Risk Level</label>
            <select class="form-select air-input" id="airFilterRisk">
                <option value="">All Risk Levels</option>
                <option value="High">High Risk</option>
                <option value="Medium">Medium Risk</option>
                <option value="Low">Low Risk</option>
            </select>
        </div>
        <div class="col-12 col-lg">
            <button type="button" class="air-clear-btn w-100" id="airClearFilters"><i class="fas fa-rotate-left me-1"></i> Clear Filters</button>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- AT-RISK STUDENT ANALYSIS -->
<!-- ============================================================ -->
<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="card border-0 shadow-sm air-fade-up" style="animation-delay:300ms;">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2" style="background: var(--surface);border-bottom: 1px solid var(--border);padding:14px 18px;">
                <h5 class="mb-0" style="font-weight:600;color: var(--ink);font-size:15px;">
                    At-Risk Student Analysis &mdash; <?php echo htmlspecialchars($selected_term); ?>
                </h5>
                <span class="air-count-pill" id="airVisibleCount"><?php echo count($rows); ?> student<?php echo count($rows) == 1 ? '' : 's'; ?></span>
            </div>
            <div class="card-body p-0">

                <!-- Desktop / tablet table -->
                <div class="table-responsive d-none d-md-block">
                    <table class="table table-hover mb-0 air-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Grade &amp; Section</th>
                                <th>Subject</th>
                                <th>Current Grade</th>
                                <th>Risk Level</th>
                                <th>Risk Reason</th>
                                <th>Trend</th>
                                <th>Intervention</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="airTableBody"></tbody>
                    </table>
                </div>

                <!-- Mobile cards -->
                <div class="d-md-none p-2" id="airMobileCards"></div>

                <div class="air-empty-state d-none" id="airEmptyState">
                    <i class="fas fa-filter-circle-xmark"></i>
                    <p class="air-empty-title">No students match the selected filters.</p>
                    <p class="air-empty-sub">Try adjusting Teacher, Subject, Section, Term, or Risk Level.</p>
                </div>

            </div>
        </div>
    </div>
</div>

</div><!-- /.air-page -->

<!-- ============================================================ -->
<!-- VIEW ANALYSIS MODAL -->
<!-- ============================================================ -->
<div class="modal fade air-modal" id="airViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header air-modal-header">
                <h5 class="modal-title"><i class="fas fa-user-graduate me-2"></i><span id="airModalStudentName">&nbsp;</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="airModalBody">
                <!-- populated by JS -->
            </div>
        </div>
    </div>
</div>

<script>
// ============================================================
// AI Student Risk & Intervention Analytics — client-side filter +
// render + View Analysis modal. Everything needed is embedded below
// (no extra AJAX endpoint), so switching Teacher/Subject/Section/Risk
// Level re-renders instantly with a short fade/slide transition and
// never reloads the page. Only the Term filter (a real GET param, since
// it changes which term's grades were queried) submits the form.
// ============================================================
(function () {
    var ROWS = <?php echo json_encode(array_values($rows)); ?>;
    var TEACHER_SUBJECTS = <?php echo json_encode(array_map(function ($r) {
        return [
            'teacher_id'   => (int)$r['teacher_id'],
            'subject_code' => $r['subject_code'],
            'subject_name' => $r['subject_name'],
            'subject_grade'=> $r['subject_grade'],
            'section_id'   => (int)$r['section_id'],
            'section_name' => $r['section_name'],
            'grade_level'  => $r['grade_level'],
        ];
    }, $teacher_subject_rows)); ?>;
    var PASS_MARK = <?php echo json_encode((float)$pass_mark); ?>;
    var REDUCED_MOTION = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var teacherSelect = document.getElementById('airFilterTeacher');
    var subjectSelect = document.getElementById('airFilterSubject');
    var sectionSelect = document.getElementById('airFilterSection');
    var riskSelect = document.getElementById('airFilterRisk');
    var gradeSelect = document.getElementById('airFilterGrade');
    function gradeNum(g) { var m = String(g || '').match(/\d+/); return m ? parseInt(m[0], 10) : 99; }
    // <optgroup> per grade level, Grade 7 -> Grade 12, items sorted by name within each grade.
    function groupedOptions(items, gradeOf, valueOf, labelOf) {
        var groups = {};
        items.forEach(function (it) { var g = gradeOf(it) || 'Other'; (groups[g] = groups[g] || []).push(it); });
        return Object.keys(groups).sort(function (a, b) { return gradeNum(a) - gradeNum(b); }).map(function (g) {
            var opts = groups[g].sort(function (a, b) { return String(labelOf(a)).localeCompare(String(labelOf(b)), undefined, { numeric: true }); })
                .map(function (it) { return '<option value="' + escapeHtml(valueOf(it)) + '">' + escapeHtml(labelOf(it)) + '</option>'; }).join('');
            return '<optgroup label="' + escapeHtml(g) + '">' + opts + '</optgroup>';
        }).join('');
    }
    var clearBtn = document.getElementById('airClearFilters');
    var tableBody = document.getElementById('airTableBody');
    var mobileCards = document.getElementById('airMobileCards');
    var emptyState = document.getElementById('airEmptyState');
    var visibleCountEl = document.getElementById('airVisibleCount');

    function escapeHtml(str) {
        if (str === null || str === undefined) { return ''; }
        return String(str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ------------------------------------------------------------
    // DEPENDENT DROPDOWNS: Teacher -> Subject -> Section
    // ------------------------------------------------------------
    function uniqueBy(list, keyFn) {
        var seen = {}; var out = [];
        list.forEach(function (item) {
            var k = keyFn(item);
            if (!seen[k]) { seen[k] = true; out.push(item); }
        });
        return out;
    }

    function rebuildSubjectOptions() {
        if (!subjectSelect) { return; }
        var teacherId = teacherSelect ? teacherSelect.value : '';
        var grade = gradeSelect ? gradeSelect.value : '';
        var pool = TEACHER_SUBJECTS.filter(function (ts) {
            return (!teacherId || String(ts.teacher_id) === teacherId)
                && (!grade || (ts.subject_grade || ts.grade_level) === grade);
        });
        var subjects = uniqueBy(pool, function (ts) { return ts.subject_code; });
        var currentVal = subjectSelect.value;
        subjectSelect.innerHTML = '<option value="">All Subjects</option>' + groupedOptions(subjects,
            function (s) { return s.subject_grade || s.grade_level; },
            function (s) { return s.subject_code; },
            function (s) { return s.subject_name; });
        if (subjects.some(function (s) { return s.subject_code === currentVal; })) {
            subjectSelect.value = currentVal;
        }
    }

    function rebuildSectionOptions() {
        if (!sectionSelect) { return; }
        var teacherId = teacherSelect ? teacherSelect.value : '';
        var subjectCode = subjectSelect ? subjectSelect.value : '';
        var grade = gradeSelect ? gradeSelect.value : '';
        var pool = TEACHER_SUBJECTS.filter(function (ts) {
            return (!teacherId || String(ts.teacher_id) === teacherId)
                && (!subjectCode || ts.subject_code === subjectCode)
                && (!grade || ts.grade_level === grade);
        });
        var sections = uniqueBy(pool, function (ts) { return ts.section_id; });
        var currentVal = sectionSelect.value;
        sectionSelect.innerHTML = '<option value="">All Sections</option>' + groupedOptions(sections,
            function (s) { return s.grade_level; },
            function (s) { return String(s.section_id); },
            function (s) { return s.section_name; });
        if (sections.some(function (s) { return String(s.section_id) === currentVal; })) {
            sectionSelect.value = currentVal;
        }
    }

    if (teacherSelect) {
        teacherSelect.addEventListener('change', function () {
            rebuildSubjectOptions();
            rebuildSectionOptions();
            renderRows();
        });
    }
    if (subjectSelect) {
        subjectSelect.addEventListener('change', function () {
            rebuildSectionOptions();
            renderRows();
        });
    }
    if (sectionSelect) { sectionSelect.addEventListener('change', renderRows); }
    if (riskSelect) { riskSelect.addEventListener('change', renderRows); }
    if (gradeSelect) {
        gradeSelect.addEventListener('change', function () {
            rebuildSubjectOptions();
            rebuildSectionOptions();
            renderRows();
        });
    }
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (teacherSelect) { teacherSelect.value = ''; }
            if (subjectSelect) { subjectSelect.value = ''; }
            if (sectionSelect) { sectionSelect.value = ''; }
            rebuildSubjectOptions();
            rebuildSectionOptions();
            if (riskSelect) { riskSelect.value = ''; }
            if (gradeSelect) { gradeSelect.value = ''; }
            rebuildSubjectOptions();
            rebuildSectionOptions();
            renderRows();
        });
    }

    // ------------------------------------------------------------
    // RISK / TREND / INTERVENTION BADGE MARKUP
    // ------------------------------------------------------------
    function riskBadge(level) {
        var tone = level === 'High' ? 'danger' : (level === 'Medium' ? 'warning' : 'success');
        return '<span class="air-badge air-tone-' + tone + '">' + escapeHtml(level) + ' Risk</span>';
    }

    function trendMarkup(trend) {
        if (trend.status === 'insufficient') {
            return '<span class="air-trend air-tone-muted"><i class="fas fa-minus"></i> Insufficient Data</span>';
        }
        var icon = trend.status === 'improving' ? 'fa-arrow-trend-up' : (trend.status === 'declining' ? 'fa-arrow-trend-down' : 'fa-arrow-right');
        return '<span class="air-trend air-tone-' + trend.tone + '"><i class="fas ' + icon + '"></i> ' + escapeHtml(trend.label) + '</span>';
    }

    function interventionBadge(intervention) {
        return '<span class="air-badge air-badge-soft air-tone-' + intervention.tone + '" title="' + escapeHtml(intervention.label) + '">' + escapeHtml(intervention.label) + '</span>';
    }

    function hoverText(value, className) {
        var text = value === null || value === undefined || value === '' ? 'N/A' : String(value);
        var muted = text === 'N/A' ? ' air-muted-italic' : '';
        return '<span class="air-hover-text ' + (className || '') + muted + '" title="' + escapeHtml(text) + '">' + escapeHtml(text) + '</span>';
    }

    function gradeAndSection(row) {
        var section = row.section_name ? escapeHtml(row.section_name) : '<span class="air-muted-italic">Unassigned</span>';
        var text = (row.grade_level || '') + (row.section_name ? ' — ' + row.section_name : ' — Unassigned');
        return '<span class="air-hover-text" title="' + escapeHtml(text) + '">' + escapeHtml(row.grade_level) + ' <span class="air-dim">&mdash;</span> ' + section + '</span>';
    }

    function subjectCell(row) {
        if (!row.subject_name) { return '<span class="air-muted-italic">N/A</span>'; }
        return hoverText(row.subject_name);
    }

    function gradeCell(row) {
        if (row.subject_grade === null || row.subject_grade === undefined) { return '<span class="air-muted-italic">N/A</span>'; }
        var tone = row.subject_grade >= PASS_MARK ? 'success' : 'danger';
        return '<strong class="air-tone-text-' + tone + '">' + Number(row.subject_grade).toFixed(2) + '</strong>';
    }

    function riskReasonCell(value) {
        var text = value === null || value === undefined || value === '' ? 'No risk reason recorded.' : String(value);
        var preview = text.length > 86 ? text.slice(0, 83).trimEnd() + '...' : text;
        return '<span class="air-reason-details" data-reason="' + escapeHtml(text) + '">' +
            '<span class="air-reason-preview">' + escapeHtml(preview) + '</span>' +
            '</span>';
    }

    // ------------------------------------------------------------
    // FILTERING + RENDER (table + mobile cards share one data pass)
    // ------------------------------------------------------------
    function currentlyVisibleRows() {
        var teacherId = teacherSelect ? teacherSelect.value : '';
        var subjectCode = subjectSelect ? subjectSelect.value : '';
        var sectionId = sectionSelect ? sectionSelect.value : '';
        var risk = riskSelect ? riskSelect.value : '';
        var grade = gradeSelect ? gradeSelect.value : '';

        return ROWS.filter(function (row) {
            if (grade && row.grade_level !== grade) { return false; }
            if (teacherId && String(row.teacher_id) !== teacherId) { return false; }
            if (subjectCode && row.subject_code !== subjectCode) { return false; }
            if (sectionId && String(row.section_id) !== sectionId) { return false; }
            if (risk && row.risk_level !== risk) { return false; }
            return true;
        });
    }

    function rowToTableHtml(row, idx) {
        return '<tr class="air-row-enter">' +
            '<td><div class="air-student-name air-hover-text" title="' + escapeHtml(row.name) + '">' + escapeHtml(row.name) + '</div><div class="air-student-lrn">' + escapeHtml(row.lrn) + '</div></td>' +
            '<td>' + gradeAndSection(row) + '</td>' +
            '<td>' + subjectCell(row) + '</td>' +
            '<td>' + gradeCell(row) + '</td>' +
            '<td>' + riskBadge(row.risk_level) + '</td>' +
            '<td class="air-reason-cell">' + riskReasonCell(row.risk_reason) + '</td>' +
            '<td title="' + escapeHtml(row.trend.label || '') + '">' + trendMarkup(row.trend) + '</td>' +
            '<td>' + interventionBadge(row.intervention) + '</td>' +
            '<td class="text-center"><button type="button" class="air-view-btn" data-idx="' + idx + '"><i class="fas fa-magnifying-glass-chart me-1"></i> View Analysis</button></td>' +
            '</tr>';
    }

    function rowToCardHtml(row, idx) {
        return '' +
            '<div class="air-mobile-card air-row-enter">' +
            '  <div class="air-mobile-card-head">' +
            '    <div><div class="air-student-name">' + escapeHtml(row.name) + '</div><div class="air-student-lrn">' + escapeHtml(row.lrn) + '</div></div>' +
            riskBadge(row.risk_level) +
            '  </div>' +
            '  <div class="air-mobile-grid">' +
            '    <div><span class="air-mobile-k">Grade &amp; Section</span><span class="air-mobile-v">' + gradeAndSection(row) + '</span></div>' +
            '    <div><span class="air-mobile-k">Subject</span><span class="air-mobile-v">' + subjectCell(row) + '</span></div>' +
            '    <div><span class="air-mobile-k">Current Grade</span><span class="air-mobile-v">' + gradeCell(row) + '</span></div>' +
            '    <div><span class="air-mobile-k">Trend</span><span class="air-mobile-v">' + trendMarkup(row.trend) + '</span></div>' +
            '    <div><span class="air-mobile-k">Intervention</span><span class="air-mobile-v">' + interventionBadge(row.intervention) + '</span></div>' +
            '  </div>' +
            '  <div class="air-mobile-reason"><span class="air-mobile-k">Reason</span>' + riskReasonCell(row.risk_reason) + '</div>' +
            '  <div class="air-mobile-reason" title="' + escapeHtml(row.term || '') + '"><span class="air-mobile-k">Term</span> ' + escapeHtml(row.term || 'N/A') + '</div>' +
            '  <button type="button" class="air-view-btn w-100 mt-2" data-idx="' + idx + '"><i class="fas fa-magnifying-glass-chart me-1"></i> View Analysis</button>' +
            '</div>';
    }

    // High / Medium / Low / Needs Intervention cards: for the chosen Grade Level (all grades when none).
    function updateSummaryCards() {
        var grade = gradeSelect ? gradeSelect.value : '';
        var c = { High: 0, Medium: 0, Low: 0, needs: 0 };
        ROWS.forEach(function (r) {
            if (grade && r.grade_level !== grade) { return; }
            if (c.hasOwnProperty(r.risk_level)) { c[r.risk_level]++; }
            if ((r.risk_level === 'High' || r.risk_level === 'Medium') && r.intervention && r.intervention.label === 'Not Reviewed') { c.needs++; }
        });
        [['airCountHigh', c.High], ['airCountMedium', c.Medium], ['airCountLow', c.Low], ['airCountNeeds', c.needs]].forEach(function (p) {
            var el = document.getElementById(p[0]);
            if (el) { el.textContent = p[1].toLocaleString(); }
        });
    }

    function renderRows() {
        var visible = currentlyVisibleRows();
        updateSummaryCards();

        function paint() {
            if (visible.length === 0) {
                tableBody.innerHTML = '';
                mobileCards.innerHTML = '';
                emptyState.classList.remove('d-none');
            } else {
                emptyState.classList.add('d-none');
                tableBody.innerHTML = visible.map(function (row) {
                    return rowToTableHtml(row, ROWS.indexOf(row));
                }).join('');
                mobileCards.innerHTML = visible.map(function (row) {
                    return rowToCardHtml(row, ROWS.indexOf(row));
                }).join('');
            }
            visibleCountEl.textContent = visible.length + ' student' + (visible.length === 1 ? '' : 's');
            wireViewButtons();
        }

        // Fade-out current rows, then paint the new set with a fade+slide
        // in — 220ms out / 260ms in, well inside the 200-350ms guidance.
        // Skipped entirely under prefers-reduced-motion.
        if (REDUCED_MOTION) {
            paint();
            return;
        }
        [tableBody, mobileCards].forEach(function (el) { el.classList.add('air-filtering-out'); });
        setTimeout(function () {
            paint();
            [tableBody, mobileCards].forEach(function (el) {
                el.classList.remove('air-filtering-out');
            });
        }, 180);
    }

    // ------------------------------------------------------------
    // VIEW ANALYSIS MODAL
    // ------------------------------------------------------------
    var modalEl = document.getElementById('airViewModal');
    var bsModal = null;

    function wireViewButtons() {
        document.querySelectorAll('.air-view-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var idx = parseInt(btn.getAttribute('data-idx'), 10);
                openModal(ROWS[idx]);
            });
        });
    }

    function trendDetailHtml(row) {
        var t = row.trend;
        if (t.status === 'insufficient') {
            return '<p class="air-modal-muted">Trend analysis requires additional Term data.</p>';
        }
        var rows = t.terms.map(function (term) {
            return '<div class="air-trend-term"><span>' + escapeHtml(term) + '</span><strong>' + Number(row.term_avgs ? row.term_avgs[term] : 0).toFixed(2) + '</strong></div>';
        }).join('');
        var changeSign = t.change > 0 ? '+' : '';
        return '' +
            '<div class="air-trend-terms">' + rows + '</div>' +
            '<div class="air-trend-summary">' +
            '  <span>Change (' + escapeHtml(t.from_term) + ' &rarr; ' + escapeHtml(t.to_term) + ')</span>' +
            '  <strong class="air-tone-text-' + t.tone + '">' + changeSign + t.change.toFixed(2) + '</strong>' +
            '</div>' +
            '<div class="mt-2">' + trendMarkup(t) + '</div>';
    }

    function openModal(row) {
        document.getElementById('airModalStudentName').textContent = row.name;

        var recsHtml = row.recommendations.map(function (r) { return '<li>' + escapeHtml(r) + '</li>'; }).join('');
        var failedHtml = (row.failed_subjects || []).map(function (fs) {
            return '<span class="air-chip-fail">' + escapeHtml(fs.subject_name) + ': ' + Number(fs.grade).toFixed(2) + '</span>';
        }).join('');

        var html = '' +
            '<div class="air-modal-section">' +
            '  <h6 class="air-modal-heading"><i class="fas fa-id-card me-2"></i>Student Information</h6>' +
            '  <div class="air-info-grid">' +
            '    <div><span class="air-mobile-k">Student Name</span><span class="air-mobile-v">' + escapeHtml(row.name) + '</span></div>' +
            '    <div><span class="air-mobile-k">LRN</span><span class="air-mobile-v">' + escapeHtml(row.lrn) + '</span></div>' +
            '    <div><span class="air-mobile-k">Grade Level</span><span class="air-mobile-v">' + escapeHtml(row.grade_level) + '</span></div>' +
            '    <div><span class="air-mobile-k">Section</span><span class="air-mobile-v">' + (row.section_name ? escapeHtml(row.section_name) : '<span class="air-muted-italic">Unassigned</span>') + '</span></div>' +
            '    <div><span class="air-mobile-k">Subject</span><span class="air-mobile-v">' + subjectCell(row) + '</span></div>' +
            '    <div><span class="air-mobile-k">Current Term</span><span class="air-mobile-v">' + escapeHtml(row.term) + '</span></div>' +
            '    <div><span class="air-mobile-k">Current Grade</span><span class="air-mobile-v">' + gradeCell(row) + '</span></div>' +
            '  </div>' +
            '</div>' +
            '<div class="air-modal-section">' +
            '  <h6 class="air-modal-heading"><i class="fas fa-triangle-exclamation me-2"></i>Risk Analysis</h6>' +
            '  <div class="mb-2">' + riskBadge(row.risk_level) + '</div>' +
            '  <p class="air-modal-reason">' + escapeHtml(row.risk_reason) + '</p>' +
            (failedHtml ? '<div class="mt-1">' + failedHtml + '</div>' : '') +
            '</div>' +
            '<div class="air-modal-section">' +
            '  <h6 class="air-modal-heading"><i class="fas fa-chart-line me-2"></i>Academic Trend</h6>' +
            trendDetailHtml(row) +
            '</div>' +
            '<div class="air-modal-section air-recommendation-card">' +
            '  <h6 class="air-modal-heading"><i class="fas fa-wand-magic-sparkles me-2"></i>AI-Assisted Recommendation</h6>' +
            '  <ul class="air-rec-list">' + recsHtml + '</ul>' +
            '</div>' +
            '<div class="air-modal-section">' +
            '  <h6 class="air-modal-heading"><i class="fas fa-life-ring me-2"></i>Intervention Status</h6>' +
            '  ' + interventionBadge(row.intervention) +
            '</div>';

        document.getElementById('airModalBody').innerHTML = html;

        if (!bsModal) { bsModal = new bootstrap.Modal(modalEl); }
        bsModal.show();
    }

    // Attach the per-student term averages onto each row (kept out of the
    // main ROWS payload above for readability; merged once here).
    var TERM_AVGS = <?php echo json_encode(array_reduce($rows, function ($carry, $r) use ($term_avgs) {
        $carry[(string)$r['student_id']] = $term_avgs[$r['student_id']] ?? new stdClass();
        return $carry;
    }, []), JSON_FORCE_OBJECT); ?>;
    ROWS.forEach(function (row) { row.term_avgs = TERM_AVGS[row.student_id] || {}; });

    rebuildSubjectOptions();
    rebuildSectionOptions();
    renderRows();
})();
</script>

<style>
/* ============================================================
   AI STUDENT RISK & INTERVENTION ANALYTICS — page-scoped styles.
   Uses ONLY the shared theme.css variables (--ink, --surface,
   --border, --text-muted, --success/--warning/--danger/--info + tints,
   --primary/--primary-soft) so Dark Mode needs zero extra rules here —
   toggling the shared theme re-themes this page automatically.
   ============================================================ */
.air-title { color: var(--ink); font-weight: 700; font-size: 24px; margin: 0; }
.air-subtitle { color: var(--text-muted); font-size: 14px; margin: 4px 0 0; max-width: 640px; }
.air-sy-pill { background: var(--surface-hover); color: var(--text-muted); padding: 6px 14px; border-radius: 20px; font-size: 12.5px; font-weight: 600; white-space: nowrap; }

/* ---- Summary cards ---- */
.air-summary-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    box-shadow: var(--shadow-sm, 0 1px 3px rgba(16,24,40,0.06));
    padding: 18px;
    height: 100%;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}
.air-summary-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md, 0 6px 18px rgba(16,24,40,0.10));
}
.air-summary-icon {
    width: 48px; height: 48px; border-radius: 12px; flex: 0 0 48px;
    display: flex; align-items: center; justify-content: center; font-size: 19px;
}
.air-summary-count { font-size: 30px; font-weight: 700; line-height: 1.1; color: var(--ink); }
.air-summary-label { font-size: 13.5px; font-weight: 700; color: var(--ink); margin-top: 2px; }
.air-summary-sub { font-size: 12px; color: var(--text-muted); margin-top: 1px; }

.air-tone-danger { background: var(--danger-tint); color: var(--danger); }
.air-tone-warning { background: var(--warning-tint); color: var(--warning); }
.air-tone-success { background: var(--success-tint); color: var(--success); }
.air-tone-info { background: var(--primary-soft, var(--info-tint)); color: var(--primary, var(--info)); }

.air-tone-text-success { color: var(--success); }
.air-tone-text-danger { color: var(--danger); }
.air-tone-text-warning { color: var(--warning); }
.air-tone-text-muted { color: var(--text-muted); }

/* ---- Filters ---- */
.air-filter-bar { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; }
.air-filter-label { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em; display: block; margin-bottom: 6px; }
.air-input.form-select { border: 1px solid var(--border); border-radius: 8px; height: 42px; font-size: 13.5px; color: var(--ink); }
.air-input.form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(91,95,239,0.12); }
.air-clear-btn {
    height: 42px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface);
    color: var(--text-muted); font-weight: 600; font-size: 13px; transition: background 0.15s, transform 0.1s;
}
.air-clear-btn:hover { background: var(--surface-hover); }
.air-clear-btn:active { transform: scale(0.97); }

/* ---- Table ---- */
.air-table thead th {
    background: var(--surface-hover); border-bottom: 1px solid var(--border);
    font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700;
    color: var(--text-muted); padding: 12px 16px; vertical-align: middle;
}
.air-table tbody td { padding: 13px 16px; font-size: 13.5px; vertical-align: middle; border-bottom: 1px solid var(--border-soft); color: var(--ink); }
.air-table tbody tr:hover { background: var(--surface-hover); }
.air-table tbody tr:last-child td { border-bottom: none; }
.air-reason-cell { width: 220px; max-width: 220px; color: var(--text-muted); font-size: 12.5px; line-height: 1.4; }
.air-reason-details { display:block; position:relative; width:220px; max-width:220px; cursor:help; color:var(--text-muted); font-size:12px; line-height:1.35; }
.air-reason-details[data-reason]::after {
    content:attr(data-reason); position:absolute; left:0; bottom:calc(100% + 8px); z-index:100;
    width:min(340px, 30vw); padding:10px 12px; border:1px solid var(--border);
    border-radius:8px; background:var(--surface); color:var(--ink); box-shadow:0 8px 22px rgba(16,24,40,.16);
    font-size:12px; font-weight:400; line-height:1.5; white-space:normal; text-align:left;
    opacity:0; visibility:hidden; transform:translateY(5px); pointer-events:none;
    transition:opacity .14s ease, transform .14s ease, visibility .14s ease;
}
.air-reason-details[data-reason]:hover::after,
.air-reason-details[data-reason]:focus-visible::after {
    opacity:1; visibility:visible; transform:none;
}
.air-reason-preview { display:block; width:100%; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.air-reason-more { flex:0 0 auto; color:var(--primary); font-size:10.5px; font-weight:700; white-space:nowrap; }
.air-reason-full {
    margin:4px 6px 2px 26px; padding:8px 10px; border-left:3px solid var(--primary);
    border-radius:0 7px 7px 0; background:var(--surface-hover); color:var(--ink);
    font-size:12px; line-height:1.5; animation:airReasonReveal .16s ease-out;
}
@keyframes airReasonReveal { from { opacity:0; transform:translateY(-3px); } to { opacity:1; transform:translateY(0); } }
.air-hover-text {
    display: inline-block;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: bottom;
    cursor: help;
}
.air-reason-cell .air-hover-text { max-width: 260px; }
.air-table td:nth-child(1) .air-hover-text { max-width: 170px; }
.air-table td:nth-child(2) .air-hover-text { max-width: 145px; }
.air-table td:nth-child(3) .air-hover-text { max-width: 150px; }
.air-table td:nth-child(7) .air-hover-text,
.air-table td:nth-child(8) .air-hover-text { max-width: 140px; }
.air-student-name { font-weight: 600; color: var(--ink); font-size: 14px; }
.air-student-lrn { font-size: 12px; color: var(--text-muted); margin-top: 1px; }
.air-dim { color: var(--text-muted-2, var(--text-muted)); }
.air-muted-italic { color: var(--text-muted); font-style: italic; }

.air-badge {
    display: inline-block; padding: 5px 12px; border-radius: 999px; font-size: 12px; font-weight: 700;
}
.air-badge-soft { font-weight: 600; }
.air-trend { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; }
.air-trend.air-tone-muted { color: var(--text-muted); }
.air-trend.air-tone-success { color: var(--success); }
.air-trend.air-tone-danger { color: var(--danger); }
.air-view-btn {
    background: var(--primary); color: #fff; border: 1px solid var(--primary);
    padding: 8px 16px; border-radius: 8px; font-size: 12.5px; font-weight: 600;
    display: inline-flex; align-items: center; transition: background 0.15s, transform 0.1s, box-shadow 0.15s;
}
.air-view-btn:hover { background: #4A4EDB; border-color: #4A4EDB; box-shadow: 0 2px 8px rgba(91,95,239,0.28); }
.air-view-btn:active { transform: scale(0.97); }

.air-count-pill { background: var(--surface-hover); color: var(--text-muted); padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }

/* ---- Empty state ---- */
.air-empty-state { text-align: center; padding: 56px 20px; color: var(--text-muted); }
.air-empty-state i { font-size: 30px; color: var(--text-muted-2, var(--text-muted)); margin-bottom: 12px; display: block; }
.air-empty-title { font-weight: 700; color: var(--ink); font-size: 15px; margin: 0 0 4px; }
.air-empty-sub { font-size: 13px; margin: 0; }

/* ---- Mobile cards ---- */
.air-mobile-card {
    background: var(--surface); border: 1px solid var(--border); border-radius: 12px;
    padding: 14px; margin-bottom: 12px; transition: box-shadow 0.15s;
}
.air-mobile-card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 10px; }
.air-mobile-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 14px; margin-bottom: 8px; }
.air-mobile-k { display: block; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.03em; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; }
.air-mobile-v { display: block; font-size: 13.5px; color: var(--ink); font-weight: 500; }
.air-mobile-reason { font-size: 12.5px; color: var(--text-muted); border-top: 1px solid var(--border-soft); padding-top: 8px; margin-top: 4px; }
.air-mobile-reason .air-reason-details { max-width:none; margin-top:4px; }

/* ---- View Analysis modal ---- */
.air-modal-header { background: var(--surface); border-bottom: 1px solid var(--border); }
.air-modal-header .modal-title { color: var(--ink); font-weight: 700; font-size: 16px; }
.air-modal-section { border-bottom: 1px solid var(--border-soft); padding: 16px 0; }
.air-modal-section:first-child { padding-top: 4px; }
.air-modal-section:last-child { border-bottom: none; padding-bottom: 4px; }
.air-modal-heading { font-size: 13.5px; font-weight: 700; color: var(--ink); margin-bottom: 10px; }
.air-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; }
.air-modal-reason { font-size: 13.5px; color: var(--text-muted); margin: 0; }
.air-modal-muted { font-size: 13px; color: var(--text-muted); font-style: italic; margin: 0; }
.air-chip-fail { background: var(--danger-tint); color: var(--danger); padding: 3px 9px; border-radius: 6px; font-size: 11.5px; font-weight: 600; margin-right: 6px; display: inline-block; margin-top: 4px; }
.air-trend-terms { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 8px; }
.air-trend-term { background: var(--surface-hover); border-radius: 8px; padding: 6px 12px; font-size: 12.5px; color: var(--text-muted); display: flex; gap: 8px; align-items: center; }
.air-trend-term strong { color: var(--ink); }
.air-trend-summary { display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: var(--text-muted); }

.air-recommendation-card {
    background: var(--primary-soft, var(--surface-hover));
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px !important;
    margin: 4px 0;
}
.air-recommendation-card .air-modal-heading { color: var(--primary, var(--ink)); }
.air-rec-list { margin: 0; padding-left: 18px; font-size: 13.5px; color: var(--ink); }
.air-rec-list li { margin-bottom: 4px; }
.air-rec-list li:last-child { margin-bottom: 0; }

/* ---- Entrance animation ---- */
.air-fade-up { animation: airFadeUp 320ms ease both; }
@keyframes airFadeUp {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ---- Filter re-render transition ---- */
.air-filtering-out { opacity: 0; transform: translateY(4px); transition: opacity 180ms ease, transform 180ms ease; }
.air-row-enter { animation: airRowIn 260ms ease both; }
@keyframes airRowIn {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
    .air-fade-up, .air-summary-card, .air-row-enter, .air-view-btn, .air-clear-btn { animation: none !important; transition: none !important; }
}

/* ---- Responsive ---- */
@media (max-width: 991.98px) {
    .air-info-grid { grid-template-columns: 1fr; }
}
@media (max-width: 767.98px) {
    .air-summary-card { padding: 14px; gap: 10px; }
    .air-summary-icon { width: 40px; height: 40px; flex: 0 0 40px; font-size: 16px; }
    .air-summary-count { font-size: 24px; }
    .air-mobile-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 420px) {
    .air-mobile-grid { grid-template-columns: 1fr; }
}
</style>

<!-- Filter dropdowns always open below their field (the browser's own list opened upward near the top of the page). -->
<script src="../../static/js/dropdown-below.js?v=<?php echo (int) @filemtime(__DIR__ . '/../../static/js/dropdown-below.js'); ?>"></script>
<script>
['airFilterTeacher', 'airFilterSubject', 'airFilterSection', 'airFilterGrade', 'airFilterRisk'].forEach(function (id) {
    DropdownBelow.enhance(document.getElementById(id));
});
DropdownBelow.enhance(document.querySelector('#airTermForm select[name="term"]'));
</script>

<?php include '../../includes/footer.php'; ?>
