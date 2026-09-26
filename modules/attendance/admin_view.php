<?php
// modules/attendance/admin_view.php
// Admin/Registrar — Teacher Attendance monitoring.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/attendance_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Registrar'])) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'Teacher Attendance';
$current_page = 'attendance';
$base_url = '../../';

$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_grade = $_GET['grade'] ?? '';
$selected_schedule_type = $_GET['schedule_type'] ?? '';
$active_tab = $_GET['tab'] ?? 'today';
$history_from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$history_to = $_GET['to'] ?? date('Y-m-d');
$selected_teacher = intval($_GET['teacher'] ?? 0);

$SCHEDULE_TYPES = ['JHS Morning', 'JHS Afternoon', 'JHS Morning + Afternoon', 'SHS', 'Mixed JHS + SHS', 'Custom Schedule'];

// Handle admin edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_edit') {
    if (hasAnyRole(['Administrator'])) {
        $edit_id = intval($_POST['attendance_id'] ?? 0);
        $edit_time_in = $_POST['edit_time_in'] ?? null;
        $edit_time_out = $_POST['edit_time_out'] ?? null;

        if ($edit_id) {
            $edit_status = 'No Time In';
            if (!empty($edit_time_in) && !empty($edit_time_out)) {
                $edit_status = 'Completed';
            } elseif (!empty($edit_time_in)) {
                $edit_status = 'Timed In';
            }

            executeQuery(
                "UPDATE teacher_attendance SET time_in = ?, time_out = ?, status = ? WHERE attendance_id = ?",
                [$edit_time_in ?: null, $edit_time_out ?: null, $edit_status, $edit_id]
            );
            $_SESSION['success_message'] = 'Attendance record updated.';
        }
    }
    header('Location: admin_view.php?tab=' . urlencode($active_tab) . '&date=' . urlencode($selected_date) . '&grade=' . urlencode($selected_grade));
    exit;
}

// ============================================================
// MANAGE TEACHING SCHEDULE (teacher_class_schedule)
// This is the DAY/TIME data the automatic JHS/SHS/Mixed detection reads
// — see includes/attendance_helpers.php. It is purely additive on top
// of teacher_subjects (which still says WHAT a teacher teaches); this
// only records WHEN.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_schedule') {
    if (hasRole('Administrator') && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $sch_ts_id = intval($_POST['ts_id'] ?? 0);
        $sch_days = $_POST['day_of_week'] ?? [];
        $sch_start = $_POST['start_time'] ?? '';
        $sch_end = $_POST['end_time'] ?? '';
        $sch_shift = $_POST['shift_type'] ?? '';

        $valid_shift_types = ['JHS Morning', 'JHS Afternoon', 'SHS', 'Custom'];
        $ts_row = getRecord("SELECT * FROM teacher_subjects WHERE ts_id = ?", [$sch_ts_id]);

        if (!$ts_row) {
            $_SESSION['error_message'] = 'Select a valid teacher assignment.';
        } elseif (empty($sch_days) || !is_array($sch_days)) {
            $_SESSION['error_message'] = 'Select at least one day.';
        } elseif (empty($sch_start) || empty($sch_end) || strtotime($sch_end) <= strtotime($sch_start)) {
            $_SESSION['error_message'] = 'End time must be after start time.';
        } elseif (!in_array($sch_shift, $valid_shift_types, true)) {
            $_SESSION['error_message'] = 'Select a valid shift type.';
        } else {
            $added = 0;
            foreach ($sch_days as $d) {
                $d = intval($d);
                if ($d < 1 || $d > 7) continue;
                $dup = getRecord(
                    "SELECT schedule_id FROM teacher_class_schedule WHERE teacher_id=? AND subject_code=? AND section_id=? AND school_year_id=? AND day_of_week=? AND start_time=?",
                    [$ts_row['teacher_id'], $ts_row['subject_code'], $ts_row['section_id'], $ts_row['school_year_id'], $d, $sch_start]
                );
                if ($dup) continue;
                executeQuery(
                    "INSERT INTO teacher_class_schedule (teacher_id, subject_code, section_id, school_year_id, day_of_week, start_time, end_time, shift_type, is_active) VALUES (?,?,?,?,?,?,?,?,1)",
                    [$ts_row['teacher_id'], $ts_row['subject_code'], $ts_row['section_id'], $ts_row['school_year_id'], $d, $sch_start, $sch_end, $sch_shift]
                );
                $added++;
            }
            logActivity($_SESSION['user_id'], 'attendance', 'Added teaching schedule', "Teacher #{$ts_row['teacher_id']}, {$ts_row['subject_code']}, $added day(s), $sch_shift $sch_start-$sch_end");
            $_SESSION['success_message'] = $added > 0 ? "Schedule added for $added day(s)." : 'Those schedule entries already exist.';
        }
    } else {
        $_SESSION['error_message'] = 'Not authorized or invalid security token.';
    }
    header('Location: admin_view.php?tab=schedule');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_schedule') {
    if (hasRole('Administrator') && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $sid = intval($_POST['schedule_id'] ?? 0);
        $row = getRecord("SELECT is_active FROM teacher_class_schedule WHERE schedule_id = ?", [$sid]);
        if ($row) {
            $new_state = $row['is_active'] ? 0 : 1;
            executeQuery("UPDATE teacher_class_schedule SET is_active = ? WHERE schedule_id = ?", [$new_state, $sid]);
            logActivity($_SESSION['user_id'], 'attendance', $new_state ? 'Reactivated teaching schedule' : 'Deactivated teaching schedule', "schedule_id=$sid");
            $_SESSION['success_message'] = $new_state ? 'Schedule entry reactivated.' : 'Schedule entry deactivated.';
        }
    }
    header('Location: admin_view.php?tab=schedule');
    exit;
}

$csrf_token = generateCSRFToken();

// Available grade levels
$grade_levels = getRecords("SELECT DISTINCT grade_level FROM sections ORDER BY FIELD(grade_level, 'Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12')");

// Build teacher list with their assignments
$teacher_filter_grade = '';
$teacher_params = [];
if ($selected_grade) {
    $teacher_filter_grade = "AND EXISTS (SELECT 1 FROM teacher_subjects ts2 JOIN sections sec2 ON ts2.section_id = sec2.section_id WHERE ts2.teacher_id = u.user_id AND sec2.grade_level = ?)";
    $teacher_params[] = $selected_grade;
}

$teachers = getRecords("
    SELECT DISTINCT u.user_id, u.first_name, u.last_name
    FROM users u
    WHERE u.role_id = 3 AND u.is_active = 1
    $teacher_filter_grade
    ORDER BY u.last_name, u.first_name
", $teacher_params);

$teacher_ids = array_column($teachers, 'user_id');

// Get attendance for selected date
$attendance = [];
if (!empty($teacher_ids)) {
    $placeholders = implode(',', array_fill(0, count($teacher_ids), '?'));
    $att_params = array_merge([$selected_date], $teacher_ids);
    $att_rows = getRecords("
        SELECT ta.teacher_id, ta.time_in, ta.break_start, ta.break_end, ta.time_out, ta.status,
               ta.expected_time_in, ta.expected_time_out, ta.expected_break_start, ta.expected_break_end, ta.schedule_type
        FROM teacher_attendance ta
        WHERE ta.attendance_date = ? AND ta.teacher_id IN ($placeholders)
    ", $att_params);
    foreach ($att_rows as $row) {
        $attendance[$row['teacher_id']] = $row;
    }
}

// Get teacher assignments (sections + subjects)
$assignments = [];
if (!empty($teacher_ids)) {
    $placeholders = implode(',', array_fill(0, count($teacher_ids), '?'));
    $assign_rows = getRecords("
        SELECT ts.teacher_id, sec.grade_level, sec.section_name, sub.subject_name
        FROM teacher_subjects ts
        JOIN sections sec ON ts.section_id = sec.section_id
        JOIN subjects sub ON ts.subject_code = sub.subject_code
        WHERE ts.teacher_id IN ($placeholders)
        ORDER BY sec.grade_level, sec.section_name, sub.subject_name
    ", $teacher_ids);
    foreach ($assign_rows as $row) {
        $assignments[$row['teacher_id']][] = $row;
    }
}

// Per-teacher expected schedule for the SELECTED date — never a single
// global expected start time. A teacher with no configured
// teacher_class_schedule entry for that day is "No Duty Today", not
// "Absent" (see includes/attendance_helpers.php).
$selected_is_today = ($selected_date === date('Y-m-d'));
$teacher_schedule = [];
foreach ($teachers as $t) {
    $teacher_schedule[$t['user_id']] = getExpectedAttendanceWindow($t['user_id'], $selected_date);
}

// Compute display status using the teacher's own actual schedule for
// this date, never a fixed school-wide expected start time.
function computeDisplayStatus($att, $window, $isToday) {
    if (!$window) {
        return ['No Duty Today', 'secondary'];
    }
    if (!$att || empty($att['time_in'])) {
        return ['Absent', 'danger'];
    }
    $state = getTeacherAttendanceState($att, $isToday);
    $class = match ($state) {
        'Completed', 'On Time' => 'success',
        'Late', 'On Break', 'Incomplete' => 'warning text-dark',
        'Back From Break', 'Working' => 'primary',
        default => 'secondary',
    };
    return [$state, $class];
}

// ============================================================
// Assignment chips (max 3 visible + "+N more"), shared by the
// desktop table and the mobile cards below so the badge-limiting
// markup is written once, not duplicated per view.
// $by_section: ['Section Name' => ['Subject', ...], ...]
// ============================================================
function render_assignment_chips($by_section, $prefix) {
    if (empty($by_section)) {
        return '<span class="text-muted-2">No assignments</span>';
    }
    $names = array_keys($by_section);
    $total = count($names);
    $html = '<div class="d-flex flex-wrap gap-1 assignment-chip-row" data-chip-group="' . htmlspecialchars($prefix) . '">';
    $idx = 0;
    foreach ($names as $sec_name) {
        $sec_id = $prefix . '-' . $idx;
        $extra = $idx >= 3 ? ' chip-extra d-none' : '';
        $html .= '<button type="button" class="chip section-toggle-btn' . $extra . '"'
            . ' id="btn-' . $sec_id . '" onclick="toggleSection(\'' . $sec_id . '\', this)"'
            . ' aria-expanded="false" aria-controls="panel-' . $sec_id . '"'
            . ' title="Show subjects in ' . htmlspecialchars($sec_name) . '"'
            . ' data-teacher="' . htmlspecialchars($prefix) . '" data-active="0">'
            . htmlspecialchars($sec_name) . '</button>';
        $idx++;
    }
    if ($total > 3) {
        $more_label = '+' . ($total - 3) . ' more';
        $html .= '<button type="button" class="chip chip-more" data-label="' . htmlspecialchars($more_label) . '" onclick="toggleMoreChips(this)">' . htmlspecialchars($more_label) . '</button>';
    }
    $html .= '</div>';
    $idx = 0;
    foreach ($by_section as $sec_name => $subjects) {
        $sec_id = $prefix . '-' . $idx;
        $html .= '<div id="panel-' . $sec_id . '" class="section-panel d-none">'
            . '<div class="section-panel-title">' . htmlspecialchars($sec_name) . ' Subjects</div>';
        foreach ($subjects as $subj) {
            $html .= '<div class="mb-0"><i class="fas fa-circle text-muted me-1" style="font-size:4px;vertical-align:middle;"></i>' . htmlspecialchars($subj) . '</div>';
        }
        $html .= '</div>';
        $idx++;
    }
    return $html;
}

// Schedule Type filter — applied here (not in SQL) since schedule type
// is computed per teacher per date, not a stored column. Must run
// BEFORE the summary counts below so the cards match the table.
if ($selected_schedule_type !== '') {
    $teachers = array_values(array_filter($teachers, function ($t) use ($teacher_schedule, $selected_schedule_type) {
        $w = $teacher_schedule[$t['user_id']];
        return $w && ($w['schedule_type'] ?? '') === $selected_schedule_type;
    }));
}

// Summary counts
$total = count($teachers);
$timed_in_count = 0;
$completed_count = 0;
$late_count = 0;
$absent_count = 0;
$no_duty_count = 0;
foreach ($teachers as $t) {
    $att = $attendance[$t['user_id']] ?? null;
    $window = $teacher_schedule[$t['user_id']];
    $label = computeDisplayStatus($att, $window, $selected_is_today);
    if ($label[0] === 'Completed') $completed_count++;
    elseif ($label[0] === 'Late') $late_count++;
    elseif ($label[0] === 'Absent') $absent_count++;
    elseif ($label[0] === 'No Duty Today') $no_duty_count++;
    elseif (in_array($label[0], ['On Time', 'Working', 'On Break', 'Back From Break'], true)) $timed_in_count++;
}

// History
$history_params = [$history_from, $history_to];
$history_filter = "WHERE ta.attendance_date >= ? AND ta.attendance_date <= ?";
if ($selected_teacher > 0) {
    $history_filter .= " AND ta.teacher_id = ?";
    $history_params[] = $selected_teacher;
}
if ($selected_grade) {
    $history_filter .= " AND ta.teacher_id IN (SELECT DISTINCT ts3.teacher_id FROM teacher_subjects ts3 JOIN sections sec3 ON ts3.section_id = sec3.section_id WHERE sec3.grade_level = ?)";
    $history_params[] = $selected_grade;
}
if ($selected_schedule_type !== '') {
    // schedule_type on each history row is the snapshot captured at that
    // day's Time In, so this filters by what actually applied that day.
    $history_filter .= " AND ta.schedule_type = ?";
    $history_params[] = $selected_schedule_type;
}
$history = getRecords("
    SELECT ta.*, u.first_name, u.last_name
    FROM teacher_attendance ta
    JOIN users u ON ta.teacher_id = u.user_id
    $history_filter
    ORDER BY ta.attendance_date DESC, u.last_name ASC
    LIMIT 100
", $history_params);

$grouped_history = [];
foreach ($history as $h) {
    $grouped_history[$h['attendance_date']][] = $h;
}

// Teacher list for filter
$teacher_list = getRecords("SELECT user_id, first_name, last_name FROM users WHERE role_id = 3 AND is_active = 1 ORDER BY last_name");

// Section + subject lookup for EVERY teacher, keyed by teacher_id then
// section name. This is deliberately not limited to $teacher_ids: the
// history table can show a teacher who falls outside the current grade
// filter, and they still need their sections and subjects displayed.
$teacher_sections = [];
$sec_rows = getRecords("
    SELECT ts.teacher_id, sec.grade_level, sec.section_name, sub.subject_name
    FROM teacher_subjects ts
    JOIN sections sec ON ts.section_id = sec.section_id
    JOIN subjects sub ON ts.subject_code = sub.subject_code
    ORDER BY sec.grade_level, sec.section_name, sub.subject_name
");
foreach ($sec_rows as $row) {
    // The key doubles as the chip label, so it carries the grade level too —
    // "G10 · 10-A" rather than a bare "10-A", since the history table lists
    // teachers from every grade at once.
    $sec_label = str_replace('Grade ', 'G', $row['grade_level']) . ' · ' . $row['section_name'];
    $teacher_sections[$row['teacher_id']][$sec_label][] = $row['subject_name'];
}

// ============================================================
// DATA FOR THE "MANAGE TEACHING SCHEDULE" TAB
// ============================================================
$DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

// Every current-school-year teacher_subjects assignment, for the "which
// class is this schedule entry for" dropdown when adding a new one.
$active_sy_row = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
$assignment_options = $active_sy_row ? getRecords("
    SELECT ts.ts_id, ts.teacher_id, u.first_name, u.last_name, ts.subject_code, sub.subject_name, sec.section_name, sec.grade_level
    FROM teacher_subjects ts
    JOIN users u ON ts.teacher_id = u.user_id
    JOIN subjects sub ON ts.subject_code = sub.subject_code
    JOIN sections sec ON ts.section_id = sec.section_id
    WHERE ts.school_year_id = ?
    ORDER BY u.last_name, u.first_name, sec.grade_level, sub.subject_name
", [$active_sy_row['sy_id']]) : [];

// Existing schedule entries, grouped by teacher, for the listing table.
$schedule_rows = getRecords("
    SELECT tcs.*, u.first_name, u.last_name, sub.subject_name, sec.section_name, sec.grade_level
    FROM teacher_class_schedule tcs
    JOIN users u ON tcs.teacher_id = u.user_id
    JOIN subjects sub ON tcs.subject_code = sub.subject_code
    JOIN sections sec ON tcs.section_id = sec.section_id
    ORDER BY u.last_name, u.first_name, tcs.day_of_week, tcs.start_time
");
$schedule_by_teacher = [];
foreach ($schedule_rows as $row) {
    $schedule_by_teacher[$row['teacher_id'] . '|' . $row['last_name'] . ', ' . $row['first_name']][] = $row;
}

include '../../includes/header.php';
?>

<style>
/* Page-scoped only where a rule genuinely doesn't belong in the
   shared design system (static/css/style.css). Everything else on
   this page — cards, badges, buttons, tables, forms, the summary
   grid, the filter bar, the grade-level chips, the mobile
   attendance cards — comes from the global stylesheet so this
   module matches the rest of the system.

   The rules below this point are ALSO page-scoped (everything is
   prefixed .att-page), added for the redesigned look. Where a class
   name is shared with other pages (e.g. .stats-card, .page-header,
   .btn.btn-primary), the override always goes through the .att-page
   ancestor so nothing here leaks onto any other module. */
.att-page .att-tab { font-size: 14px; font-weight: 500; padding: 12px 18px; text-decoration: none; display: inline-block; color: var(--text-muted); border-bottom: 3px solid transparent; position: relative; }
.att-page .att-tab.active { color: var(--ink); font-weight: 600; border-bottom-color: transparent; }
.att-page .att-tab.active::after { content: ''; position: absolute; left: 18px; right: 18px; bottom: -3px; height: 3px; border-radius: 3px; background: var(--primary); }
.att-page .att-tab:focus-visible { outline: 2px solid var(--ink); outline-offset: -2px; }
.att-page .att-muted-dash { color: var(--text-muted-2); }

/* ---- Page header: icon box + title/subtitle + illustration ---- */
.att-page .page-header {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    padding: var(--space-6) var(--space-6);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-5);
    margin-bottom: var(--space-5);
}
.att-page .page-header-main { display: flex; align-items: flex-start; gap: var(--space-4); min-width: 0; }
.att-page .page-header-icon {
    width: 52px; height: 52px; border-radius: 14px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 20px;
    background: var(--primary-soft);
    color: var(--primary);
}
.att-page .page-header .page-title { font-size: 26px; margin-bottom: 4px; }
.att-page .page-header .page-subtitle { max-width: 640px; }
.att-page .att-illustration { flex-shrink: 0; width: 150px; height: 110px; }
@media (max-width: 991.98px) {
    .att-page .att-illustration { display: none; }
}
@media (max-width: 575.98px) {
    .att-page .page-header { flex-direction: column; align-items: flex-start; }
}

/* ---- Filters card: icon + label header row above the existing form ---- */
.att-page .att-filter-card-header { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: var(--space-4); }
/* Solid primary indigo, matching Section 20's button system — was a
   blue/purple gradient, which Section 1's "avoid excessive gradients"
   direction rules out even for a single button. */
.att-page .att-btn-gradient {
    background: var(--primary);
    border: 1px solid var(--primary); color: #fff; font-weight: 600;
    box-shadow: 0 1px 2px rgba(16,24,40,.04);
}
.att-page .att-btn-gradient:hover { background: #4A4EDB; border-color: #4A4EDB; color: #fff; }
.att-page .att-btn-gradient:focus { color: #fff; }

/* ---- KPI cards: pastel accent + decorative wave, layered on the
   shared .stats-card look (border/radius/shadow unchanged). ---- */
.att-page .stats-card { position: relative; overflow: hidden; }
.att-page .stats-card-wave { position: absolute; left: 0; right: 0; bottom: 0; height: 16px; opacity: 0.55; pointer-events: none; }
.att-page .stats-card-wave svg { width: 100%; height: 100%; display: block; }
.att-page .stats-card.acc-navy    { background: linear-gradient(180deg, #F4F6FE 0%, var(--surface) 55%); }
.att-page .stats-card.acc-success { background: linear-gradient(180deg, var(--success-tint) 0%, var(--surface) 55%); }
.att-page .stats-card.acc-warning { background: linear-gradient(180deg, var(--warning-tint) 0%, var(--surface) 55%); }
.att-page .stats-card.acc-danger  { background: linear-gradient(180deg, var(--danger-tint) 0%, var(--surface) 55%); }
.att-page .stats-card.acc-muted   { background: linear-gradient(180deg, #F2F4F7 0%, var(--surface) 55%); }
.att-page .stats-card.acc-navy    .stats-card-wave { color: #DCE3FB; }
.att-page .stats-card.acc-success .stats-card-wave { color: var(--success-tint); }
.att-page .stats-card.acc-warning .stats-card-wave { color: var(--warning-tint); }
.att-page .stats-card.acc-danger  .stats-card-wave { color: var(--danger-tint); }
.att-page .stats-card.acc-muted   .stats-card-wave { color: #E7E9ED; }

/* ---- Table card header: calendar icon + Export button ---- */
.att-page .att-table-header-title { display: flex; align-items: center; gap: 8px; }
.att-page .att-export-btn { display: inline-flex; align-items: center; gap: 6px; }

/* ---- Bigger, easier-to-read text in the tables (Today's Overview,
   Attendance History, Manage Teaching Schedule). !important beats the
   small inline font sizes on some cells. ---- */
.att-page .table > :not(caption) > * > th { font-size: 12.5px !important; font-weight: 700; letter-spacing: .04em; padding-top: 13px; padding-bottom: 13px; }
.att-page .table > :not(caption) > * > td { font-size: 15px !important; padding-top: 14px; padding-bottom: 14px; }
.att-page .table .cell-strong { font-size: 15px; }
.att-page .table .text-muted-2, .att-page .att-muted-dash { font-size: 14.5px !important; }
.att-page .table .time-label { font-size: 13.5px; }
.att-page .table .time-value { font-size: 14.5px; }
.att-page .status-pill { font-size: 13px; padding: 5px 12px; }
.att-page .section-title { font-size: 17px !important; }
.att-page .card-header .badge, .att-page .card-header .status-pill { font-size: 13px; }
/* Assignment chips (10-B, 11-GAS B, +5 more) */
.att-page .assignment-chip-row { gap: 6px !important; }
.att-page .assignment-chip-row .chip { font-size: 14px; font-weight: 600; padding: 6px 12px; border-radius: 8px; line-height: 1.2; }
.att-page .assignment-chip-row .chip-more { font-size: 13.5px; }
.att-page .table td:has(> .assignment-chip-row) { max-width: 360px !important; }
/* Subjects under an opened section chip */
.att-page .section-panel { font-size: 14.5px; }
.att-page .section-panel-title { font-size: 14px !important; }
/* Manage Teaching Schedule form */
.att-page .card-body .form-label, .att-page .card-body label { font-size: 14.5px !important; }
.att-page .card-body .form-select, .att-page .card-body .form-control { font-size: 15px; min-height: 44px; }
.att-page .card-body .btn { font-size: 15px; }
.att-page .card-header h5, .att-page .card-header h6 { font-size: 17px !important; }
.att-page .table .btn-sm { font-size: 14px; padding: 6px 14px; }
</style>

<div class="att-page">
    <div class="page-header">
        <div class="page-header-main">
            <div class="page-header-icon"><i class="fas fa-calendar-check"></i></div>
            <div class="page-header-text">
                <h1 class="page-title">Teacher Attendance</h1>
                <p class="page-subtitle">Monitor teacher daily attendance. Expected duty hours are computed per teacher from their actual JHS/SHS class schedule.</p>
            </div>
        </div>
        <div class="att-illustration" aria-hidden="true">
            <svg viewBox="0 0 160 120" xmlns="http://www.w3.org/2000/svg">
                <circle cx="80" cy="60" r="56" fill="#EEF1FE"/>
                <rect x="30" y="34" width="72" height="58" rx="8" fill="#fff" stroke="#D8DCF7" stroke-width="2"/>
                <rect x="30" y="34" width="72" height="16" rx="8" fill="#5B5FEF"/>
                <rect x="42" y="26" width="6" height="16" rx="3" fill="#5B5FEF"/>
                <rect x="84" y="26" width="6" height="16" rx="3" fill="#5B5FEF"/>
                <g fill="#C9CEF6">
                    <rect x="40" y="58" width="12" height="10" rx="2"/>
                    <rect x="58" y="58" width="12" height="10" rx="2"/>
                    <rect x="76" y="58" width="12" height="10" rx="2"/>
                    <rect x="40" y="74" width="12" height="10" rx="2"/>
                </g>
                <g fill="#4CAF7D">
                    <rect x="58" y="74" width="12" height="10" rx="2"/>
                </g>
                <path d="M60 78 l3 3 l6 -6" stroke="#fff" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="118" cy="86" r="20" fill="#fff" stroke="#5B5FEF" stroke-width="3"/>
                <path d="M118 86 L118 74" stroke="#5B5FEF" stroke-width="3" stroke-linecap="round"/>
                <path d="M118 86 L127 90" stroke="#5B5FEF" stroke-width="3" stroke-linecap="round"/>
                <circle cx="118" cy="86" r="2.5" fill="#5B5FEF"/>
            </svg>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav" style="border-bottom:1px solid var(--border);margin-bottom:20px;flex-wrap:wrap;">
        <li class="nav-item">
            <a class="nav-link att-tab <?php echo $active_tab === 'today' ? 'active' : ''; ?>" <?php echo $active_tab === 'today' ? 'aria-current="page"' : ''; ?> href="?tab=today&date=<?php echo $selected_date; ?>&grade=<?php echo urlencode($selected_grade); ?>">
                Today's Overview
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link att-tab <?php echo $active_tab === 'history' ? 'active' : ''; ?>" <?php echo $active_tab === 'history' ? 'aria-current="page"' : ''; ?> href="?tab=history&grade=<?php echo urlencode($selected_grade); ?>">
                Attendance History
            </a>
        </li>
        <?php if (hasRole('Administrator')): ?>
        <li class="nav-item">
            <a class="nav-link att-tab <?php echo $active_tab === 'schedule' ? 'active' : ''; ?>" <?php echo $active_tab === 'schedule' ? 'aria-current="page"' : ''; ?> href="?tab=schedule">
                Manage Teaching Schedule
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <!-- Grade + Schedule Type Filter — only on Today's Overview. On the
         History tab, the same filters live inside the history filter
         card below, so the page does not have one filter floating above
         another. One card, single row on desktop, wraps on smaller
         screens (see .attendance-filter-bar in style.css). -->
    <?php if ($active_tab === 'today'): ?>
    <div class="card app-card mb-3">
        <div class="card-body">
            <div class="att-filter-card-header"><i class="fas fa-filter"></i> Filters</div>
            <form method="GET" class="attendance-filter-bar">
                <input type="hidden" name="tab" value="<?php echo $active_tab; ?>">
                <div>
                    <label for="attGradeSelect" class="form-label">Grade Level</label>
                    <select name="grade" id="attGradeSelect" class="form-select" onchange="this.form.submit()">
                        <option value="">All Grades</option>
                        <?php foreach ($grade_levels as $gl): ?>
                            <option value="<?php echo htmlspecialchars($gl['grade_level']); ?>" <?php echo $selected_grade === $gl['grade_level'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($gl['grade_level']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="attScheduleTypeSelect" class="form-label">Schedule Type</label>
                    <select name="schedule_type" id="attScheduleTypeSelect" class="form-select" onchange="this.form.submit()">
                        <option value="">All</option>
                        <?php foreach ($SCHEDULE_TYPES as $st): ?>
                            <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $selected_schedule_type === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars($st); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="attDateInput" class="form-label">Date</label>
                    <input type="date" name="date" id="attDateInput" class="form-control" max="<?php echo date('Y-m-d'); ?>" value="<?php echo $selected_date; ?>">
                </div>
                <div class="filter-apply-col">
                    <button type="submit" class="btn btn-primary att-btn-gradient w-100">Apply Filters</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($active_tab === 'today'): ?>
        <!-- SUMMARY CARDS: 5 metrics, small icon containers (not full-color cards).
             Each card additionally carries an acc-* modifier (page-scoped, see
             the <style> block above) for a soft pastel tint + decorative wave —
             purely visual, no change to the underlying $total/$completed_count/
             etc. values computed above. -->
        <?php
            $__att_wave = '<div class="stats-card-wave" aria-hidden="true"><svg viewBox="0 0 200 40" preserveAspectRatio="none"><path d="M0,22 C40,4 60,36 100,20 C140,4 160,36 200,20 L200,40 L0,40 Z" fill="currentColor"/></svg></div>';
        ?>
        <div class="attendance-summary-grid">
            <div class="stats-card acc-navy">
                <div class="stats-icon icon-navy"><i class="fas fa-users"></i></div>
                <div><h3 class="mb-0"><?php echo $total; ?></h3><small class="text-muted">Total Teachers</small></div>
                <?php echo $__att_wave; ?>
            </div>
            <div class="stats-card acc-success">
                <div class="stats-icon icon-success"><i class="fas fa-check-circle"></i></div>
                <div><h3 class="mb-0"><?php echo $completed_count; ?></h3><small class="text-muted">Completed</small></div>
                <?php echo $__att_wave; ?>
            </div>
            <div class="stats-card acc-warning">
                <div class="stats-icon icon-warning"><i class="fas fa-clock"></i></div>
                <div><h3 class="mb-0"><?php echo $late_count; ?></h3><small class="text-muted">Late</small></div>
                <?php echo $__att_wave; ?>
            </div>
            <div class="stats-card acc-danger">
                <div class="stats-icon icon-danger"><i class="fas fa-times-circle"></i></div>
                <div><h3 class="mb-0"><?php echo $absent_count; ?></h3><small class="text-muted">Absent</small></div>
                <?php echo $__att_wave; ?>
            </div>
            <div class="stats-card acc-muted">
                <div class="stats-icon icon-muted"><i class="fas fa-calendar-xmark"></i></div>
                <div><h3 class="mb-0"><?php echo $no_duty_count; ?></h3><small class="text-muted">No Duty Today</small></div>
                <?php echo $__att_wave; ?>
            </div>
        </div>

        <!-- TODAY'S OVERVIEW — grouped columns on desktop, one card per
             teacher on mobile (≤768px). Both views are rendered from the
             exact same PHP loop below: nothing is fetched or computed
             twice. -->
        <div class="card app-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 justify-content-between">
                <div class="att-table-header-title">
                    <i class="fas fa-calendar-day" style="color:#5B5FEF;"></i>
                    <h5 class="mb-0 section-title" style="font-size:15px;">
                        <?php echo date('l, F j, Y', strtotime($selected_date)); ?>
                    </h5>
                    <?php if ($selected_grade): ?>
                        <span class="status-pill status-info"><?php echo htmlspecialchars($selected_grade); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($teachers)): ?>
                <button type="button" class="btn btn-secondary btn-sm att-export-btn" onclick="exportAttendanceTableToCSV()">
                    <i class="fas fa-download"></i> Export
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($teachers)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><i class="fas fa-user-slash"></i></div>
                        <div class="empty-state-title">No teachers found</div>
                        <div class="empty-state-text">No teachers match the current grade level filter.</div>
                    </div>
                <?php else: ?>
                <!-- Desktop table (hidden ≤768px) -->
                <div class="table-responsive attendance-table-desktop">
                    <table class="table table-hover mb-0 align-middle" id="attTodayTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Teacher</th>
                                <th>Assignments</th>
                                <th>Schedule</th>
                                <th>Attendance</th>
                                <th>Worked</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($teachers as $t):
                                $att = $attendance[$t['user_id']] ?? null;
                                $window = $teacher_schedule[$t['user_id']];
                                $label = computeDisplayStatus($att, $window, $selected_is_today);
                                $t_assignments = $assignments[$t['user_id']] ?? [];
                                $by_section = [];
                                foreach ($t_assignments as $a) {
                                    $by_section[$a['section_name']][] = $a['subject_name'];
                                }
                                list($lbg, $lcl) = getAttendanceStatusColors($label[0]);
                                $has_hours = $att && !empty($att['time_in']) && !empty($att['time_out']);
                                if ($has_hours) {
                                    $diff = strtotime($att['time_out']) - strtotime($att['time_in']);
                                    $worked = floor($diff / 3600) . 'h ' . floor(($diff % 3600) / 60) . 'm';
                                }
                            ?>
                                <tr>
                                    <td class="text-muted-2"><?php echo $i++; ?></td>
                                    <td class="cell-strong no-wrap"><?php echo htmlspecialchars($t['last_name'] . ', ' . $t['first_name']); ?></td>
                                    <td style="max-width:280px;"><?php echo render_assignment_chips($by_section, 'sec-' . $t['user_id']); ?></td>
                                    <?php if (!$window): ?>
                                        <td colspan="3"><span class="att-muted-dash">No teaching duty configured for this date.</span></td>
                                    <?php else: ?>
                                        <td style="font-size:12.5px;">
                                            <div class="cell-strong"><?php echo htmlspecialchars($window['schedule_type']); ?></div>
                                            <div class="text-muted-2"><?php echo date('g:i A', strtotime($window['time_in'])) . ' – ' . date('g:i A', strtotime($window['time_out'])); ?></div>
                                        </td>
                                        <td>
                                            <div class="attendance-time-block">
                                                <div class="time-row"><span class="time-label">In</span><span class="time-value <?php echo (!$att || empty($att['time_in'])) ? 'text-muted-2' : ''; ?>"><?php echo ($att && !empty($att['time_in'])) ? date('g:i A', strtotime($att['time_in'])) : '—'; ?></span></div>
                                                <div class="time-row"><span class="time-label">Break</span><span class="time-value <?php echo (!$att || empty($att['break_start'])) ? 'text-muted-2' : ''; ?>"><?php echo ($att && !empty($att['break_start'])) ? (date('g:i A', strtotime($att['break_start'])) . '–' . (!empty($att['break_end']) ? date('g:i A', strtotime($att['break_end'])) : '…')) : '—'; ?></span></div>
                                                <div class="time-row"><span class="time-label">Out</span><span class="time-value <?php echo (!$att || empty($att['time_out'])) ? 'text-muted-2' : ''; ?>"><?php echo ($att && !empty($att['time_out'])) ? date('g:i A', strtotime($att['time_out'])) : '—'; ?></span></div>
                                            </div>
                                        </td>
                                        <td class="<?php echo $has_hours ? 'cell-strong' : 'text-muted-2'; ?>"><?php echo $has_hours ? $worked : '—'; ?></td>
                                    <?php endif; ?>
                                    <td><span class="status-pill" style="background:<?php echo $lbg; ?>;color:<?php echo $lcl; ?>;"><?php echo htmlspecialchars($label[0]); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile cards (shown only ≤768px) -->
                <div class="attendance-cards p-3">
                    <?php $i = 1; foreach ($teachers as $t):
                        $att = $attendance[$t['user_id']] ?? null;
                        $window = $teacher_schedule[$t['user_id']];
                        $label = computeDisplayStatus($att, $window, $selected_is_today);
                        $t_assignments = $assignments[$t['user_id']] ?? [];
                        $by_section = [];
                        foreach ($t_assignments as $a) {
                            $by_section[$a['section_name']][] = $a['subject_name'];
                        }
                        list($lbg, $lcl) = getAttendanceStatusColors($label[0]);
                        $has_hours = $att && !empty($att['time_in']) && !empty($att['time_out']);
                        if ($has_hours) {
                            $diff = strtotime($att['time_out']) - strtotime($att['time_in']);
                            $worked = floor($diff / 3600) . 'h ' . floor(($diff % 3600) / 60) . 'm';
                        }
                    ?>
                        <div class="attendance-card mb-3">
                            <div class="attendance-card-header">
                                <div class="attendance-card-name"><?php echo htmlspecialchars($t['last_name'] . ', ' . $t['first_name']); ?></div>
                                <span class="status-pill" style="background:<?php echo $lbg; ?>;color:<?php echo $lcl; ?>;"><?php echo htmlspecialchars($label[0]); ?></span>
                            </div>
                            <div class="attendance-card-badges"><?php echo render_assignment_chips($by_section, 'msec-' . $t['user_id']); ?></div>
                            <?php if (!$window): ?>
                                <div class="att-muted-dash" style="font-size:12.5px;">No teaching duty configured for this date.</div>
                            <?php else: ?>
                                <div class="attendance-card-section">
                                    <div class="attendance-card-section-label">Schedule</div>
                                    <div class="attendance-card-row"><span class="label"><?php echo htmlspecialchars($window['schedule_type']); ?></span><span class="value"><?php echo date('g:i A', strtotime($window['time_in'])) . ' – ' . date('g:i A', strtotime($window['time_out'])); ?></span></div>
                                </div>
                                <div class="attendance-card-section">
                                    <div class="attendance-card-section-label">Attendance</div>
                                    <div class="attendance-card-row"><span class="label">Time In</span><span class="value"><?php echo ($att && !empty($att['time_in'])) ? date('g:i A', strtotime($att['time_in'])) : '—'; ?></span></div>
                                    <div class="attendance-card-row"><span class="label">Break</span><span class="value"><?php echo ($att && !empty($att['break_start'])) ? (date('g:i A', strtotime($att['break_start'])) . '–' . (!empty($att['break_end']) ? date('g:i A', strtotime($att['break_end'])) : '…')) : '—'; ?></span></div>
                                    <div class="attendance-card-row"><span class="label">Time Out</span><span class="value"><?php echo ($att && !empty($att['time_out'])) ? date('g:i A', strtotime($att['time_out'])) : '—'; ?></span></div>
                                </div>
                                <div class="attendance-card-section">
                                    <div class="attendance-card-row"><span class="label">Worked</span><span class="value"><?php echo $has_hours ? $worked : '—'; ?></span></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($active_tab === 'history'): ?>
        <!-- ===== ATTENDANCE HISTORY ===== -->

        <!-- History Filters -->
        <div class="card app-card mb-3">
            <div class="card-body">
                <form method="GET" class="attendance-filter-bar">
                    <input type="hidden" name="tab" value="history">
                    <div>
                        <label for="attHistGrade" class="form-label">Grade Level</label>
                        <select name="grade" id="attHistGrade" class="form-select">
                            <option value="">All Grades</option>
                            <?php foreach ($grade_levels as $gl): ?>
                                <option value="<?php echo htmlspecialchars($gl['grade_level']); ?>" <?php echo $selected_grade === $gl['grade_level'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($gl['grade_level']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="attFrom" class="form-label">From</label>
                        <input type="date" name="from" id="attFrom" class="form-control" max="<?php echo date('Y-m-d'); ?>" value="<?php echo $history_from; ?>">
                    </div>
                    <div>
                        <label for="attTo" class="form-label">To</label>
                        <input type="date" name="to" id="attTo" class="form-control" max="<?php echo date('Y-m-d'); ?>" value="<?php echo $history_to; ?>">
                    </div>
                    <div>
                        <label for="attTeacher" class="form-label">Teacher</label>
                        <select name="teacher" id="attTeacher" class="form-select">
                            <option value="0">All Teachers</option>
                            <?php foreach ($teacher_list as $tl): ?>
                                <option value="<?php echo $tl['user_id']; ?>" <?php echo $selected_teacher == $tl['user_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tl['last_name'] . ', ' . $tl['first_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="attHistScheduleType" class="form-label">Schedule Type</label>
                        <select name="schedule_type" id="attHistScheduleType" class="form-select">
                            <option value="">All</option>
                            <?php foreach ($SCHEDULE_TYPES as $st): ?>
                                <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $selected_schedule_type === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars($st); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-apply-col">
                        <button type="submit" class="btn btn-primary att-btn-gradient">Apply Filters</button>
                        <a href="?tab=history" class="btn btn-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- History Grouped by Date -->
        <?php if (empty($grouped_history)): ?>
            <div class="card app-card">
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-calendar-xmark"></i></div>
                    <div class="empty-state-title">No attendance records</div>
                    <div class="empty-state-text">No attendance records found for the selected filters.</div>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($grouped_history as $date => $records): ?>
                <div class="card app-card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h6 class="mb-0 section-title" style="font-size:15px;">
                            <?php echo date('l, M j, Y', strtotime($date)); ?>
                        </h6>
                        <span class="status-pill status-muted"><?php echo count($records); ?> record<?php echo count($records) > 1 ? 's' : ''; ?></span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                        <table class="table mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Teacher</th>
                                    <th>Assignments</th>
                                    <th>Schedule</th>
                                    <th>Attendance</th>
                                    <th>Worked</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($records as $r): ?>
                                    <?php
                                        // Every history row already has a Time In (it exists because
                                        // the teacher recorded one), so this is always a real state,
                                        // never Absent/No Duty Today — those only apply to a MISSING
                                        // row, which the Today's Overview tab handles separately.
                                        $r_is_today = ($r['attendance_date'] === date('Y-m-d'));
                                        $r_state = getTeacherAttendanceState($r, $r_is_today);
                                        $r_by_section = $teacher_sections[$r['teacher_id']] ?? [];
                                        // The same teacher can appear under several dates, so the
                                        // chip-group key includes the attendance row id — otherwise
                                        // two rows would share element ids and expand together.
                                        $r_key = $r['teacher_id'] . '-' . $r['attendance_id'];
                                        list($lbg, $lcl) = getAttendanceStatusColors($r_state);
                                        $r_has_hours = $r['time_in'] && $r['time_out'];
                                        if ($r_has_hours) {
                                            $r_diff = strtotime($r['time_out']) - strtotime($r['time_in']);
                                            $r_worked = floor($r_diff / 3600) . 'h ' . floor(($r_diff % 3600) / 60) . 'm';
                                        }
                                    ?>
                                    <tr>
                                        <td class="cell-strong no-wrap"><?php echo htmlspecialchars($r['last_name'] . ', ' . $r['first_name']); ?></td>
                                        <td style="max-width:280px;"><?php echo render_assignment_chips($r_by_section, 'hsec-' . $r_key); ?></td>
                                        <td style="font-size:12.5px;">
                                            <div class="cell-strong"><?php echo !empty($r['schedule_type']) ? htmlspecialchars($r['schedule_type']) : '<span class="att-muted-dash">—</span>'; ?></div>
                                            <?php if (!empty($r['expected_time_in']) && !empty($r['expected_time_out'])): ?>
                                                <div class="text-muted-2"><?php echo date('g:i A', strtotime($r['expected_time_in'])) . ' – ' . date('g:i A', strtotime($r['expected_time_out'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="attendance-time-block">
                                                <div class="time-row"><span class="time-label">In</span><span class="time-value <?php echo $r['time_in'] ? '' : 'text-muted-2'; ?>"><?php echo $r['time_in'] ? date('g:i A', strtotime($r['time_in'])) : '—'; ?></span></div>
                                                <div class="time-row"><span class="time-label">Break</span><span class="time-value <?php echo !empty($r['break_start']) ? '' : 'text-muted-2'; ?>"><?php echo !empty($r['break_start']) ? (date('g:i A', strtotime($r['break_start'])) . '–' . (!empty($r['break_end']) ? date('g:i A', strtotime($r['break_end'])) : '…')) : '—'; ?></span></div>
                                                <div class="time-row"><span class="time-label">Out</span><span class="time-value <?php echo $r['time_out'] ? '' : 'text-muted-2'; ?>"><?php echo $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : '—'; ?></span></div>
                                            </div>
                                        </td>
                                        <td class="<?php echo $r_has_hours ? 'cell-strong' : 'text-muted-2'; ?>"><?php echo $r_has_hours ? $r_worked : '—'; ?></td>
                                        <td><span class="status-pill" style="background:<?php echo $lbg; ?>;color:<?php echo $lcl; ?>;"><?php echo htmlspecialchars($r_state); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php elseif ($active_tab === 'schedule' && hasRole('Administrator')): ?>
        <!-- ===== MANAGE TEACHING SCHEDULE ===== -->
        <div class="card border-0 shadow-sm" style="margin-bottom:16px;">
            <div class="card-header" style="background: var(--surface);border-bottom: 1px solid var(--border);padding:14px 18px;">
                <h5 class="mb-0" style="font-weight:600;color: var(--ink);font-size:15px;">Add a Class Schedule Entry</h5>
                <p class="text-muted small mb-0 mt-1">Pick one of the teacher's existing subject/section assignments, then set the day(s) and time it actually meets. This is what the automatic JHS/SHS/Mixed detection reads — teacher_subjects alone only says WHAT a teacher teaches, not WHEN.</p>
            </div>
            <div class="card-body">
                <?php if (empty($assignment_options)): ?>
                    <div class="text-muted" style="font-size:14px;">No teacher assignments found for the active school year. Assign teachers to subjects/sections first under Subjects.</div>
                <?php else: ?>
                <form method="POST" action="" class="row g-3 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add_schedule">
                    <div class="col-md-4">
                        <label class="form-label">Teacher — Subject — Section</label>
                        <select name="ts_id" class="form-select att-filter-input" required style="width:100%;">
                            <?php foreach ($assignment_options as $opt): ?>
                                <option value="<?php echo $opt['ts_id']; ?>">
                                    <?php echo htmlspecialchars($opt['last_name'] . ', ' . $opt['first_name'] . ' — ' . $opt['subject_name'] . ' (' . $opt['grade_level'] . ' ' . $opt['section_name'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Day(s)</label>
                        <div class="d-flex flex-wrap gap-2" style="padding-top:6px;">
                            <?php foreach ($DAY_NAMES as $d_num => $d_name): if ($d_num > 5) continue; ?>
                                <label style="font-size:13px;font-weight:500;color: var(--ink);background: var(--surface-hover);border: 1px solid var(--border);border-radius:6px;padding:6px 10px;cursor:pointer;">
                                    <input type="checkbox" name="day_of_week[]" value="<?php echo $d_num; ?>" style="margin-right:4px;"><?php echo substr($d_name, 0, 3); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Start Time</label>
                        <input type="time" name="start_time" class="form-control att-filter-input" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">End Time</label>
                        <input type="time" name="end_time" class="form-control att-filter-input" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Shift Type</label>
                        <select name="shift_type" class="form-select att-filter-input" required>
                            <option value="JHS Morning">JHS Morning</option>
                            <option value="JHS Afternoon">JHS Afternoon</option>
                            <option value="SHS">SHS</option>
                            <option value="Custom">Custom</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Add Schedule</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header" style="background: var(--surface);border-bottom: 1px solid var(--border);padding:14px 18px;">
                <h5 class="mb-0" style="font-weight:600;color: var(--ink);font-size:15px;">Current Schedule Entries</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($schedule_by_teacher)): ?>
                    <div class="text-center py-4" style="color: var(--text-muted);font-size:14px;">No teaching schedule entries have been added yet. Every teacher will show "Today's teaching schedule has not been configured" on My Attendance until at least one is added.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead style="background: var(--surface-hover);">
                                <tr>
                                    <th>Teacher</th>
                                    <th>Subject / Section</th>
                                    <th>Day</th>
                                    <th>Time</th>
                                    <th>Shift</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($schedule_by_teacher as $rows): ?>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td style="border-bottom: 1px solid var(--border-soft);"><strong><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></strong></td>
                                            <td style="border-bottom: 1px solid var(--border-soft);font-size:13px;"><?php echo htmlspecialchars($row['subject_name'] . ' — ' . $row['grade_level'] . ' ' . $row['section_name']); ?></td>
                                            <td style="border-bottom: 1px solid var(--border-soft);"><?php echo htmlspecialchars($DAY_NAMES[(int) $row['day_of_week']] ?? $row['day_of_week']); ?></td>
                                            <td style="border-bottom: 1px solid var(--border-soft);"><?php echo date('g:i A', strtotime($row['start_time'])) . ' – ' . date('g:i A', strtotime($row['end_time'])); ?></td>
                                            <td style="border-bottom: 1px solid var(--border-soft);"><?php echo htmlspecialchars($row['shift_type']); ?></td>
                                            <td style="border-bottom: 1px solid var(--border-soft);">
                                                <?php if ($row['is_active']): ?>
                                                    <span class="status-pill status-success">Active</span>
                                                <?php else: ?>
                                                    <span class="status-pill status-muted">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="border-bottom: 1px solid var(--border-soft);">
                                                <form method="POST" action="" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="action" value="toggle_schedule">
                                                    <input type="hidden" name="schedule_id" value="<?php echo $row['schedule_id']; ?>">
                                                    <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('<?php echo $row['is_active'] ? 'Deactivate' : 'Reactivate'; ?> this schedule entry?')">
                                                        <?php echo $row['is_active'] ? 'Deactivate' : 'Reactivate'; ?>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleSection(secId, btn) {
    var panel = document.getElementById('panel-' + secId);
    var isActive = btn.getAttribute('data-active') === '1';
    var teacherId = btn.getAttribute('data-teacher'); // full chip-group prefix, e.g. "sec-123"

    // Collapse all panels for this teacher/group first
    document.querySelectorAll('.section-toggle-btn[data-teacher="' + teacherId + '"]').forEach(function(b) {
        if (b.id !== 'btn-' + secId) {
            b.setAttribute('data-active', '0');
            b.setAttribute('aria-expanded', 'false');
            b.classList.remove('chip-open');
        }
    });
    document.querySelectorAll('.section-panel[id^="panel-' + teacherId + '-"]').forEach(function(p) {
        if (p.id !== 'panel-' + secId) {
            p.classList.add('d-none');
        }
    });

    if (isActive) {
        panel.classList.add('d-none');
        btn.setAttribute('data-active', '0');
        btn.setAttribute('aria-expanded', 'false');
        btn.classList.remove('chip-open');
    } else {
        panel.classList.remove('d-none');
        btn.setAttribute('data-active', '1');
        btn.setAttribute('aria-expanded', 'true');
        btn.classList.add('chip-open');
    }
}

// "+N more" reveals the remaining assignment chips for that teacher
// (max 3 shown by default \u2014 see render_assignment_chips() in PHP).
function toggleMoreChips(moreBtn) {
    var row = moreBtn.closest('.assignment-chip-row');
    if (!row) return;
    var hidden = row.querySelectorAll('.chip-extra');
    var willShow = hidden.length > 0 && hidden[0].classList.contains('d-none');
    hidden.forEach(function (chip) { chip.classList.toggle('d-none', !willShow); });
    moreBtn.textContent = willShow ? 'Show less' : moreBtn.getAttribute('data-label');
}

// Exports exactly what's currently rendered in the Today's Overview table —
// no separate server call, no re-fetch, so it can never drift from what the
// admin is actually looking at. Purely a client-side convenience; nothing
// here touches attendance data or logic.
function exportAttendanceTableToCSV() {
    var table = document.getElementById('attTodayTable');
    if (!table) return;
    var rows = table.querySelectorAll('tr');
    var csv = [];
    rows.forEach(function (row) {
        var cells = row.querySelectorAll('th, td');
        var line = [];
        cells.forEach(function (cell) {
            var text = cell.innerText.replace(/\s+/g, ' ').trim();
            line.push('"' + text.replace(/"/g, '""') + '"');
        });
        csv.push(line.join(','));
    });
    var blob = new Blob([csv.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'teacher_attendance_<?php echo $selected_date; ?>.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>

<?php include '../../includes/footer.php'; ?>