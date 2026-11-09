<?php
// includes/attendance_helpers.php
//
// Single source of truth for Teacher Attendance schedule logic: JHS
// Morning/Afternoon, SHS, and Mixed JHS+SHS teachers, driven by each
// teacher's ACTUAL current class assignments (teacher_subjects +
// teacher_class_schedule) rather than can_handle_shs or grade level
// alone. Used by both modules/attendance/index.php (the teacher's own
// "My Attendance") and modules/attendance/admin_view.php (monitoring).
//
// Nothing here invents or hard-codes a nationwide DepEd start/end time —
// every clock value comes from system_settings (setting_group =
// 'attendance'), editable at Settings -> Attendance Schedule. If those
// rows are ever missing, the constants below are only a last-resort
// fallback so the page never fatals; the real values live in the DB.

require_once __DIR__ . '/../config/database.php';

// Fallback defaults — only used if a setting row is somehow missing
// from system_settings (normally seeded by
// database/attendance_schedule_upgrade.sql). Not a DepEd standard,
// just a safe placeholder.
const ATTENDANCE_SETTING_DEFAULTS = [
    'jhs_morning_start'         => '07:00',
    'jhs_morning_break_start'   => '09:30',
    'jhs_morning_break_end'     => '09:45',
    'jhs_morning_end'           => '12:00',
    'jhs_afternoon_start'       => '12:30',
    'jhs_afternoon_break_start' => '15:00',
    'jhs_afternoon_break_end'   => '15:15',
    'jhs_afternoon_end'         => '17:30',
    'shs_start'                 => '08:00',
    'shs_break_start'           => '10:30',
    'shs_break_end'             => '10:45',
    'shs_end'                   => '14:00',
];

/** How long after Time In the badge still reads "On Time"/"Late" before settling into "Working". UX choice, not a policy rule. */
const ATTENDANCE_JUST_TIMED_IN_WINDOW_MIN = 15;
/** How long after End Break the badge still reads "Back From Break" before settling into "Working". */
const ATTENDANCE_JUST_BACK_FROM_BREAK_WINDOW_MIN = 5;

/**
 * The school's configurable JHS/Morning/Afternoon and SHS schedule
 * times, read from system_settings. One place, reused everywhere —
 * change a class program time in Settings and every page picks it up.
 * @return array<string,string> H:i values keyed like ATTENDANCE_SETTING_DEFAULTS
 */
function getAttendanceScheduleSettings() {
    $rows = getRecords("SELECT setting_key, setting_value FROM system_settings WHERE setting_group = 'attendance'");
    $settings = ATTENDANCE_SETTING_DEFAULTS;
    foreach ($rows as $row) {
        if (array_key_exists($row['setting_key'], $settings) && $row['setting_value'] !== null && $row['setting_value'] !== '') {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $settings;
}

/**
 * The teacher's current-school-year subject/section assignments —
 * WHAT they teach. Does not say when; see getTeacherScheduleForDate().
 * @return array<int,array>
 */
function getTeacherAssignments($teacherId, $schoolYearId) {
    return getRecords("
        SELECT ts.ts_id, ts.subject_code, sub.subject_name, ts.section_id,
               sec.section_name, sec.grade_level, sec.is_senior_high, sec.strand
        FROM teacher_subjects ts
        JOIN sections sec ON ts.section_id = sec.section_id
        JOIN subjects sub ON ts.subject_code = sub.subject_code
        WHERE ts.teacher_id = ? AND ts.school_year_id = ?
        ORDER BY sec.grade_level, sec.section_name, sub.subject_name
    ", [$teacherId, $schoolYearId]);
}

/**
 * The teacher's actual scheduled classes/duty for one specific date —
 * WHEN they teach — resolved against the currently active school year.
 * Empty array means "no teacher_class_schedule entries for that day",
 * which the caller must treat as "not configured", never as absence.
 * @return array<int,array>
 */
function getTeacherScheduleForDate($teacherId, $date) {
    $active_sy = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
    if (!$active_sy) {
        return [];
    }
    $day_of_week = (int) date('N', strtotime($date)); // 1=Monday .. 7=Sunday
    return getRecords("
        SELECT tcs.*, sub.subject_name, sec.section_name, sec.grade_level, sec.is_senior_high
        FROM teacher_class_schedule tcs
        JOIN subjects sub ON tcs.subject_code = sub.subject_code
        JOIN sections sec ON tcs.section_id = sec.section_id
        WHERE tcs.teacher_id = ?
          AND tcs.school_year_id = ?
          AND tcs.day_of_week = ?
          AND tcs.is_active = 1
        ORDER BY tcs.start_time
    ", [$teacherId, $active_sy['sy_id'], $day_of_week]);
}

/**
 * Classifies a day's schedule rows (from getTeacherScheduleForDate) into
 * one of: 'JHS Morning', 'JHS Afternoon', 'JHS Morning + Afternoon',
 * 'SHS', 'Mixed JHS + SHS', 'Custom Schedule', or null (no rows).
 * Trusts each row's own shift_type (set explicitly when the schedule
 * entry was created) rather than re-guessing from clock times.
 * @param array<int,array> $scheduleRows
 * @return string|null
 */
function classifyScheduleType(array $scheduleRows) {
    if (empty($scheduleRows)) {
        return null;
    }
    $hasJhsMorning = false;
    $hasJhsAfternoon = false;
    $hasShs = false;
    $hasCustom = false;
    foreach ($scheduleRows as $row) {
        switch ($row['shift_type']) {
            case 'JHS Morning':   $hasJhsMorning = true; break;
            case 'JHS Afternoon': $hasJhsAfternoon = true; break;
            case 'SHS':           $hasShs = true; break;
            default:              $hasCustom = true; break;
        }
    }
    if ($hasShs && ($hasJhsMorning || $hasJhsAfternoon)) {
        return 'Mixed JHS + SHS';
    }
    if ($hasShs) {
        return 'SHS';
    }
    if ($hasJhsMorning && $hasJhsAfternoon) {
        return 'JHS Morning + Afternoon';
    }
    if ($hasJhsMorning) {
        return 'JHS Morning';
    }
    if ($hasJhsAfternoon) {
        return 'JHS Afternoon';
    }
    if ($hasCustom) {
        return 'Custom Schedule';
    }
    return null;
}

/**
 * Convenience wrapper: schedule type for a teacher on a given date.
 * @return string|null
 */
function getTeacherScheduleType($teacherId, $date) {
    return classifyScheduleType(getTeacherScheduleForDate($teacherId, $date));
}

/**
 * The expected attendance window for a teacher on a given date, computed
 * ONLY from their actual teacher_class_schedule rows for that day —
 * never guessed from grade level alone. Returns null when there is no
 * schedule configured for that day (caller must show "not configured",
 * not calculate Late/Absent from nothing).
 *
 * Expected Time In  = earliest scheduled start that day
 * Expected Time Out = latest scheduled end that day
 * Expected Break    = the configured break window for whichever shift
 *                      is present (SHS takes priority when mixed, since
 *                      SHS is the fixed 8-2 block; otherwise JHS
 *                      Afternoon's break when both JHS halves are
 *                      worked, else JHS Morning's break) — a single
 *                      attendance record only has one break_start/end
 *                      pair, so one representative window is chosen.
 *
 * @return array{time_in:string,time_out:string,break_start:string,break_end:string,schedule_type:string}|null
 */
function getExpectedAttendanceWindow($teacherId, $date) {
    $rows = getTeacherScheduleForDate($teacherId, $date);
    if (empty($rows)) {
        return null;
    }
    $settings = getAttendanceScheduleSettings();
    $schedule_type = classifyScheduleType($rows);

    $starts = array_map(fn($r) => $r['start_time'], $rows);
    $ends = array_map(fn($r) => $r['end_time'], $rows);
    sort($starts);
    sort($ends);
    $expected_time_in = $starts[0];
    $expected_time_out = end($ends);

    $has_shs = false;
    $has_jhs_afternoon = false;
    foreach ($rows as $row) {
        if ($row['shift_type'] === 'SHS') $has_shs = true;
        if ($row['shift_type'] === 'JHS Afternoon') $has_jhs_afternoon = true;
    }
    if ($has_shs) {
        $break_start = $settings['shs_break_start'];
        $break_end = $settings['shs_break_end'];
    } elseif ($has_jhs_afternoon) {
        $break_start = $settings['jhs_afternoon_break_start'];
        $break_end = $settings['jhs_afternoon_break_end'];
    } else {
        $break_start = $settings['jhs_morning_break_start'];
        $break_end = $settings['jhs_morning_break_end'];
    }

    return [
        'time_in'       => $expected_time_in,
        'time_out'      => $expected_time_out,
        'break_start'   => $break_start,
        'break_end'     => $break_end,
        'schedule_type' => $schedule_type ?? 'Custom Schedule',
    ];
}

/**
 * @param string $actualTimeIn H:i:s
 * @param string $expectedTimeIn H:i:s / H:i
 * @return bool
 */
function isTeacherLate($actualTimeIn, $expectedTimeIn) {
    if (empty($actualTimeIn) || empty($expectedTimeIn)) {
        return false;
    }
    return strtotime($actualTimeIn) > strtotime($expectedTimeIn);
}

/**
 * Classifies ONE teacher_attendance row (or null) into a single display
 * status. Late/On Time/Back From Break are transient confirmations that
 * settle into "Working" after a short window — see the two
 * ATTENDANCE_*_WINDOW_MIN constants above.
 *
 * Does NOT return 'Absent' or 'No Duty Today' — those depend on whether
 * a schedule existed that day at all, which the caller already knows
 * from getTeacherScheduleForDate()/getExpectedAttendanceWindow(), so
 * they are decided at the page level before this function is even
 * called for a missing record.
 *
 * @param array|null $attendance a teacher_attendance row
 * @param bool $isToday false for a past date being reviewed in history —
 *        an unfinished record from a past day reads as "Incomplete", not
 *        an still-in-progress "Working"/"Late".
 * @return string
 */
function getTeacherAttendanceState($attendance, $isToday = true) {
    if (!$attendance || empty($attendance['time_in'])) {
        return 'Not Yet Timed In';
    }
    if (!empty($attendance['time_out'])) {
        return 'Completed';
    }
    if (!$isToday) {
        // Day is over and there's still no Time Out — nothing left to
        // transition into, so this record is simply incomplete.
        return 'Incomplete';
    }
    if (!empty($attendance['break_start']) && empty($attendance['break_end'])) {
        return 'On Break';
    }
    if (!empty($attendance['break_end'])) {
        $minutes_since = (time() - strtotime($attendance['break_end'])) / 60;
        return $minutes_since < ATTENDANCE_JUST_BACK_FROM_BREAK_WINDOW_MIN ? 'Back From Break' : 'Working';
    }
    // Timed in, no break taken (yet) today.
    $minutes_since = (time() - strtotime($attendance['time_in'])) / 60;
    if ($minutes_since < ATTENDANCE_JUST_TIMED_IN_WINDOW_MIN) {
        // Only claim On Time/Late when there was an actual expected time to
        // compare against — with no configured schedule that day, saying
        // "On Time" would be a false calculation from nothing (see the
        // fallback rule in getExpectedAttendanceWindow()'s doc comment).
        if (!empty($attendance['expected_time_in'])) {
            return isTeacherLate($attendance['time_in'], $attendance['expected_time_in']) ? 'Late' : 'On Time';
        }
        return 'Working';
    }
    return 'Working';
}

/**
 * Makes sure `teacher_attendance` has the columns the Teacher Attendance
 * code (modules/attendance/index.php + admin_view.php) reads and writes:
 * the schedule SNAPSHOT captured at Time In (expected_time_in/out,
 * expected_break_start/end, schedule_type) and break_start/break_end.
 *
 * These columns were never part of the shipped schema (egradepro.sql), so
 * on a database built from it the Time In INSERT fails with "Unknown
 * column" and the admin monitoring query returns nothing. This is the same
 * self-healing pattern as attQrEnsureSchema(): purely additive, idempotent,
 * NULL-able columns — no table dropped, no existing row changed. The same
 * change is available as database/migrations/2026_teacher_attendance_schedule_snapshot.sql.
 *
 * @return bool true when every required column exists
 */
function teacherAttendanceEnsureSchema() {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (schemaIsReady('teacher_attendance_snapshot_v1')) {
        return $ready = true;
    }

    $required = [
        'break_start'          => 'time DEFAULT NULL',
        'break_end'            => 'time DEFAULT NULL',
        'expected_time_in'     => 'time DEFAULT NULL',
        'expected_time_out'    => 'time DEFAULT NULL',
        'expected_break_start' => 'time DEFAULT NULL',
        'expected_break_end'   => 'time DEFAULT NULL',
        'schedule_type'        => 'varchar(50) DEFAULT NULL',
    ];

    $loadColumns = function () {
        return array_column(getRecords(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_attendance'"
        ), 'COLUMN_NAME');
    };

    $columns = $loadColumns();
    if (empty($columns)) {
        error_log('teacherAttendanceEnsureSchema: table teacher_attendance not found or not readable.');
        return $ready = false;
    }
    foreach ($required as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            executeQuery("ALTER TABLE `teacher_attendance` ADD COLUMN `$column` $definition");
        }
    }

    $columns = $loadColumns();
    $ready = count(array_diff(array_keys($required), $columns)) === 0;
    if ($ready) {
        schemaMarkReady('teacher_attendance_snapshot_v1');
    } else {
        error_log('teacherAttendanceEnsureSchema: could not add missing teacher_attendance columns: '
            . implode(', ', array_diff(array_keys($required), $columns)));
    }
    return $ready;
}

/**
 * Server-side guard for the teacher's Time In / Time Out actions.
 *
 * @param array|null $attendance today's existing teacher_attendance row, or null
 * @param string $action one of 'time_in'|'time_out'
 * @return array{valid:bool,message:string}
 */
function validateAttendanceAction($attendance, $action) {
    switch ($action) {
        case 'time_in':
            if ($attendance) {
                return ['valid' => false, 'message' => 'You have already timed in today.'];
            }
            return ['valid' => true, 'message' => ''];

        case 'time_out':
            if (!$attendance || empty($attendance['time_in'])) {
                return ['valid' => false, 'message' => 'Please Time In first.'];
            }
            if (!empty($attendance['time_out'])) {
                return ['valid' => false, 'message' => 'Attendance for today is already completed.'];
            }
            return ['valid' => true, 'message' => ''];

        default:
            return ['valid' => false, 'message' => 'Unknown attendance action.'];
    }
}

/**
 * Whether $date falls on a Saturday or Sunday.
 * @param string $date Y-m-d
 * @return bool
 */
function isWeekend($date) {
    $n = (int) date('N', strtotime($date));
    return $n >= 6;
}

/**
 * Shared [background, text] hex color pair for a display status label —
 * one definition reused by both the teacher's My Attendance page and
 * the admin monitoring page, so a status never renders differently in
 * one place than the other.
 * @return array{0:string,1:string}
 */
function getAttendanceStatusColors($status) {
    // Matches the shared status-pill tints in static/css/style.css
    // (Success/Warning/Danger/Info/Muted) so a status reads the same
    // color everywhere it appears in the system.
    return match ($status) {
        'Completed', 'On Time' => ['#EAF7F1', '#238863'],           // success
        'On Break', 'Late' => ['#FFF7E6', '#B7791F'],               // warning
        'Incomplete' => ['#FDEEEE', '#C94A4A'],                     // danger (orange/red)
        'Back From Break', 'Working', 'Timed In' => ['#EDF4FB', '#356EA8'], // info
        'Absent' => ['#FDEEEE', '#C94A4A'],                         // danger
        default => ['#F2F4F7', '#667085'], // Not Yet Timed In / No Duty Today
    };
}

// ============================================================
// STUDENT ATTENDANCE — separate domain from the Teacher clock-in system
// above. Backed by the existing `attendance` table (student_id,
// subject_code, section_id, date, status enum Present/Absent/Late/Excused).
// Recorded per class session via modules/attendance/take.php.
//
// This NEVER touches grades. Written Works / Performance Tasks /
// Examinations (includes/grading_helpers.php) remain the only official
// grading components — attendance is purely an ADDITIONAL risk signal
// surfaced in AI Analytics, exactly as DepEd treats it: a factor a teacher
// considers, not a grade input, unless a school's own policy says
// otherwise (which this system does not assume).
// ============================================================

/** Fallback defaults — only used when the Administrator hasn't saved
 *  Settings -> Attendance Schedule -> Student Attendance Risk Thresholds
 *  yet. The real, school-configured values live in system_settings
 *  (setting_group = 'attendance'), same pattern as the class-hour
 *  settings above. */
const ATTENDANCE_RISK_PCT_THRESHOLD = 90;
const ATTENDANCE_RISK_ABSENCE_THRESHOLD = 3;
const ATTENDANCE_RISK_CONSECUTIVE_ABSENCES = 3;
const ATTENDANCE_RISK_LATE_THRESHOLD = 5;

/**
 * The school-configured Student Attendance Risk thresholds, read from
 * Settings -> Attendance Schedule (Administrator). Falls back to the
 * constants above if the Administrator hasn't saved this section yet.
 * @return array{pct:int,absences:int,consecutive:int,late:int}
 */
function gh_getAttendanceRiskThresholds() {
    $rows = getRecords("SELECT setting_key, setting_value FROM system_settings WHERE setting_group = 'attendance'");
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return [
        'pct' => isset($settings['attendance_risk_pct_threshold']) && $settings['attendance_risk_pct_threshold'] !== ''
            ? (float) $settings['attendance_risk_pct_threshold'] : ATTENDANCE_RISK_PCT_THRESHOLD,
        'absences' => isset($settings['attendance_risk_absence_threshold']) && $settings['attendance_risk_absence_threshold'] !== ''
            ? (int) $settings['attendance_risk_absence_threshold'] : ATTENDANCE_RISK_ABSENCE_THRESHOLD,
        'consecutive' => isset($settings['attendance_risk_consecutive_absences']) && $settings['attendance_risk_consecutive_absences'] !== ''
            ? (int) $settings['attendance_risk_consecutive_absences'] : ATTENDANCE_RISK_CONSECUTIVE_ABSENCES,
        'late' => isset($settings['attendance_risk_late_threshold']) && $settings['attendance_risk_late_threshold'] !== ''
            ? (int) $settings['attendance_risk_late_threshold'] : ATTENDANCE_RISK_LATE_THRESHOLD,
    ];
}

/**
 * Shared aggregation: raw per-DAY attendance rows (ordered by date) -> the
 * summary shape every attendance-summary function below returns. Needs the
 * actual day-by-day sequence (not just totals) to detect CONSECUTIVE
 * absences, one of the four school-configured risk conditions.
 * Excused days are set aside from BOTH sides of the percentage — they never
 * count against a student, but they never inflate it either.
 * A Late day counts as half a day attended (Present 1, Late 0.5, Absent 0).
 * @param array<int,array{status:string}> $rows ordered by date ascending
 * @return array{present:int,late:int,absent:int,excused:int,total:int,percentage:float|null,consecutive_absent:int,at_risk:bool,reason:string|null}
 */
function gh_summarizeAttendanceRows(array $rows) {
    $present = 0; $late = 0; $absent = 0; $excused = 0;
    $consecutive_absent = 0;
    $running_streak = 0;
    foreach ($rows as $row) {
        switch ($row['status']) {
            case 'Present': $present++; $running_streak = 0; break;
            case 'Late':    $late++;    $running_streak = 0; break;
            case 'Excused': $excused++; $running_streak = 0; break;
            case 'Absent':
                $absent++;
                $running_streak++;
                $consecutive_absent = max($consecutive_absent, $running_streak);
                break;
        }
    }

    // Half credit for a Late: Present = 1, Late = 0.5, Absent = 0. (A student who
    // only ever arrived late must not read as 100%.) Excused stays out of both sides.
    $countable = $present + $late + $absent;
    $percentage = $countable > 0 ? round((($present + 0.5 * $late) / $countable) * 100, 1) : null;

    $t = gh_getAttendanceRiskThresholds();
    $reasons = [];
    if ($percentage !== null && $percentage < $t['pct']) {
        $reasons[] = 'Attendance is ' . $percentage . '%, below the ' . rtrim(rtrim(number_format($t['pct'], 1), '0'), '.') . '% threshold';
    }
    if ($absent >= $t['absences']) {
        $reasons[] = $absent . ' absence' . ($absent === 1 ? '' : 's') . ' recorded this term (threshold: ' . $t['absences'] . ')';
    }
    if ($consecutive_absent >= $t['consecutive']) {
        $reasons[] = $consecutive_absent . ' consecutive absences';
    }
    if ($late >= $t['late']) {
        $reasons[] = $late . ' late record' . ($late === 1 ? '' : 's') . ' this term (threshold: ' . $t['late'] . ')';
    }

    return [
        'present' => $present,
        'late' => $late,
        'absent' => $absent,
        'excused' => $excused,
        'total' => $countable + $excused,
        'percentage' => $percentage,
        'consecutive_absent' => $consecutive_absent,
        'at_risk' => !empty($reasons),
        'reason' => !empty($reasons) ? implode('; ', $reasons) . '.' : null,
    ];
}

/**
 * Collapses possibly-multiple attendance rows PER DAY (one student can have
 * a separate row per subject/section) down to ONE status per date, so a
 * student's overall daily attendance profile -- and especially a
 * CONSECUTIVE-absence streak -- isn't skewed by how many subjects recorded
 * that day. A day counts as Absent for the student if ANY subject marked
 * them Absent that day (missing school is missing school); otherwise Late
 * beats Excused beats Present, in that priority order.
 * @param array<int,array{date:string,status:string}> $rows
 * @return array<int,array{status:string}> one entry per distinct date, ordered by date ascending
 */
function gh_collapseAttendanceRowsByDate(array $rows) {
    $priority = ['Absent' => 3, 'Late' => 2, 'Excused' => 1, 'Present' => 0];
    $by_date = [];
    foreach ($rows as $row) {
        $d = $row['date'];
        if (!isset($by_date[$d]) || ($priority[$row['status']] ?? 0) > ($priority[$by_date[$d]] ?? 0)) {
            $by_date[$d] = $row['status'];
        }
    }
    ksort($by_date);
    $collapsed = [];
    foreach ($by_date as $status) {
        $collapsed[] = ['status' => $status];
    }
    return $collapsed;
}

/**
 * One student's attendance summary for ONE subject/section across a date
 * range (inclusive). Used by the Take Attendance roster and by any
 * per-subject attendance view.
 * @param string|null $end_date defaults to today when null.
 */
function gh_getStudentAttendanceSummary($student_id, $subject_code, $section_id, $start_date, $end_date = null) {
    $end_date = $end_date ?: date('Y-m-d');
    $rows = getRecords(
        "SELECT status FROM attendance
         WHERE student_id = ? AND subject_code = ? AND section_id = ?
           AND date >= ? AND date <= ?
         ORDER BY date ASC",
        [$student_id, $subject_code, $section_id, $start_date, $end_date]
    );
    return gh_summarizeAttendanceRows($rows);
}

/**
 * One student's attendance summary combined across EVERY subject/section
 * this ONE teacher has recorded attendance for them in, over a date range.
 * Used by AI Analytics, which shows one attendance figure per student
 * rather than per subject.
 * @param string|null $end_date defaults to today when null.
 */
function gh_getStudentAttendanceSummaryForTeacher($teacher_id, $student_id, $start_date, $end_date = null) {
    $end_date = $end_date ?: date('Y-m-d');
    $rows = getRecords(
        "SELECT a.date, a.status
         FROM attendance a
         JOIN teacher_subjects ts ON ts.subject_code = a.subject_code AND ts.section_id = a.section_id
         WHERE a.student_id = ? AND ts.teacher_id = ?
           AND a.date >= ? AND a.date <= ?
         ORDER BY a.date ASC",
        [$student_id, $teacher_id, $start_date, $end_date]
    );
    return gh_summarizeAttendanceRows(gh_collapseAttendanceRowsByDate($rows));
}

/**
 * Same summary as gh_getStudentAttendanceSummaryForTeacher() but across
 * ALL of the student's subjects/sections regardless of which teacher
 * recorded them — used for the Administrator view of AI Analytics, where
 * there is no single owning teacher to scope by.
 */
function gh_getStudentAttendanceSummaryAll($student_id, $start_date, $end_date = null) {
    $end_date = $end_date ?: date('Y-m-d');
    $rows = getRecords(
        "SELECT date, status FROM attendance
         WHERE student_id = ? AND date >= ? AND date <= ?
         ORDER BY date ASC",
        [$student_id, $start_date, $end_date]
    );
    return gh_summarizeAttendanceRows(gh_collapseAttendanceRowsByDate($rows));
}

/**
 * Every recorded attendance status for ONE class (subject/section) on ONE
 * date, keyed by student_id — the Take Attendance roster's prefill.
 * @return array<int,array{attendance_id:int,status:string,remarks:string|null}>
 */
function gh_getClassAttendanceForDate($teacher_id, $subject_code, $section_id, $date) {
    $rows = getRecords(
        "SELECT attendance_id, student_id, status, remarks FROM attendance
         WHERE subject_code = ? AND section_id = ? AND date = ?",
        [$subject_code, $section_id, $date]
    );
    $by_student = [];
    foreach ($rows as $row) {
        $by_student[(int) $row['student_id']] = [
            'attendance_id' => (int) $row['attendance_id'],
            'status' => $row['status'],
            'remarks' => $row['remarks'],
        ];
    }
    return $by_student;
}

/**
 * Record (or update) one student's attendance for one class session.
 * App-level upsert (select-then-insert/update) — matches this codebase's
 * existing convention (see assessment_scores handling in
 * modules/grading/index.php) rather than relying on a DB-level unique
 * constraint that doesn't exist on this table.
 */
function gh_setStudentAttendance($student_id, $subject_code, $section_id, $date, $status, $remarks = null) {
    $valid_statuses = ['Present', 'Absent', 'Late', 'Excused'];
    if (!in_array($status, $valid_statuses, true)) {
        return false;
    }
    $existing = getRecord(
        "SELECT attendance_id FROM attendance WHERE student_id = ? AND subject_code = ? AND section_id = ? AND date = ?",
        [$student_id, $subject_code, $section_id, $date]
    );
    if ($status === 'Absent' && $date === date('Y-m-d')) {
        require_once __DIR__ . '/parent_portal.php';
        ppAbsentAlert((int) $student_id, $date);   // text to linked parents, once a day
    }
    if ($existing) {
        return executeQuery(
            "UPDATE attendance SET status = ?, remarks = ?, updated_at = NOW() WHERE attendance_id = ?",
            [$status, $remarks, $existing['attendance_id']]
        );
    }
    return executeQuery(
        "INSERT INTO attendance (student_id, subject_code, section_id, date, status, remarks) VALUES (?, ?, ?, ?, ?, ?)",
        [$student_id, $subject_code, $section_id, $date, $status, $remarks]
    );
}
