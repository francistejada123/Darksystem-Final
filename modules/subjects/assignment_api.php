<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';
require_once __DIR__ . '/../../includes/teacher_department.php';

// ASSH Cluster = the school's Arts, Social Sciences and Humanities cluster. Older
// records (sections created before the cluster was renamed) still carry the
// retired label 'HUMSS'. Treat both as the same cluster so those sections and
// their subjects stay visible -- nothing in the database is rewritten.
if (!function_exists('normalizeStrandKey')) {
    function normalizeStrandKey($strand) {
        $clean = trim((string)$strand);
        if (in_array(strtoupper($clean), ['ASSH', 'HUMSS', 'ASSH CLUSTER'], true)) {
            return 'ASSH';
        }
        return $clean;
    }
}

header('Content-Type: application/json; charset=utf-8');

function assignment_json($ok, $data = [], $status = 200) {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok], $data));
    exit;
}
function assignment_time_label($time) {
    $stamp = strtotime($time);
    return $stamp ? date('g:i A', $stamp) : $time;
}
function assignment_day_label($day) {
    return [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday'][$day] ?? 'Day ' . $day;
}
function assignment_shift($grade) {
    if (in_array($grade, ['Grade 7', 'Grade 8'], true)) return 'AM';
    if (in_array($grade, ['Grade 9', 'Grade 10'], true)) return 'PM';
    return '';
}
function assignment_shift_window($shift) {
    $defaults = $shift === 'AM' ? ['06:00', '12:30'] : ['12:30', '19:00'];
    $keys = $shift === 'AM' ? ['jhs_morning_start', 'jhs_morning_end'] : ['jhs_afternoon_start', 'jhs_afternoon_end'];
    $rows = getRecords("SELECT setting_key, setting_value FROM system_settings WHERE setting_group='attendance' AND setting_key IN (?, ?)", $keys);
    foreach ($rows as $row) {
        if ($row['setting_key'] === $keys[0] && $row['setting_value'] !== '') $defaults[0] = substr($row['setting_value'], 0, 5);
        if ($row['setting_key'] === $keys[1] && $row['setting_value'] !== '') $defaults[1] = substr($row['setting_value'], 0, 5);
    }
    return $defaults;
}

if (!hasRole('Administrator')) assignment_json(false, ['message' => 'Administrator access required.'], 403);
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
if (!verifyCSRFToken($input['csrf_token'] ?? '')) assignment_json(false, ['message' => 'Invalid security token. Refresh the page.'], 403);

$activeSy = getRecord("SELECT sy_id FROM school_years WHERE is_active=1");
$schoolYearId = (int)($activeSy['sy_id'] ?? 0);
$action = ($input['action'] ?? 'assign') === 'edit' ? 'edit' : 'assign';
$tsId = (int)($input['ts_id'] ?? 0);
$teacherId = (int)($input['teacher_id'] ?? 0);
$sectionId = (int)($input['section_id'] ?? 0);
$subjectCode = sanitize($input['subject_code'] ?? '');
$days = $input['days'] ?? [];
$days = is_array($days) ? $days : [$days];
$days = array_values(array_unique(array_filter(array_map('intval', $days), fn($day) => $day >= 1 && $day <= 5)));
$start = trim((string)($input['start_time'] ?? ''));
$end = trim((string)($input['end_time'] ?? ''));

if (!$schoolYearId || !$teacherId || !$sectionId || $subjectCode === '') assignment_json(false, ['message' => 'Complete the teacher, subject, and section fields.'], 422);
if (!$days) assignment_json(false, ['message' => 'Select at least one weekday.'], 422);
if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $end <= $start) assignment_json(false, ['message' => 'End time must be later than start time.'], 422);

$assignment = getRecord("SELECT ts.ts_id, ts.teacher_id AS old_teacher_id, ts.subject_code AS old_subject_code, ts.section_id AS old_section_id,
        sub.subject_name, sub.grade_level, sub.strand, sec.section_name, sec.grade_level AS section_grade
    FROM teacher_subjects ts
    JOIN subjects sub ON sub.subject_code=ts.subject_code
    JOIN sections sec ON sec.section_id=ts.section_id
    WHERE ts.ts_id=? AND ts.school_year_id=?", [$tsId, $schoolYearId]);
if ($action === 'edit' && !$assignment) assignment_json(false, ['message' => 'Assignment not found.'], 404);

$subject = getRecord("SELECT subject_code, subject_name, grade_level, strand, term FROM subjects WHERE subject_code=? AND is_active=1", [$subjectCode]);
$section = getRecord("SELECT section_id, section_name, grade_level, strand FROM sections WHERE section_id=? AND is_active=1", [$sectionId]);
$teacher = getRecord("SELECT user_id, first_name, last_name, department_level FROM users WHERE user_id=? AND role_id=3 AND is_active=1", [$teacherId]);
if (!$subject || !$section || !$teacher || $subject['grade_level'] !== $section['grade_level'] || (!empty($section['strand']) && normalizeStrandKey($subject['strand']) !== normalizeStrandKey($section['strand']))) {
    assignment_json(false, ['message' => 'The selected subject, section, or teacher is invalid.'], 422);
}
// Department / Level saved at teacher registration is the source of truth:
// a JHS teacher may only be given Grade 7-10, an SHS teacher only Grade 11-12.
// Re-checked here so a hand-crafted API call cannot bypass the dropdowns.
$deptCheck = tdCheckAssignment($teacher['department_level'] ?? '', $section['grade_level']);
if (!$deptCheck['ok']) assignment_json(false, ['message' => $deptCheck['message']], 422);
$shift = assignment_shift($section['grade_level']);
if ($shift) {
    [$windowStart, $windowEnd] = assignment_shift_window($shift);
    if ($start < $windowStart || $end > $windowEnd) assignment_json(false, ['message' => 'Schedule Conflict: ' . $section['grade_level'] . ' classes must stay within the ' . $shift . ' Shift (' . assignment_time_label($windowStart) . '–' . assignment_time_label($windowEnd) . ').'], 422);
}

$duplicate = getRecord("SELECT ts_id FROM teacher_subjects WHERE subject_code=? AND section_id=? AND school_year_id=? AND (? = 'assign' OR ts_id <> ?)", [$subjectCode, $sectionId, $schoolYearId, $action, $tsId]);
if ($duplicate) assignment_json(false, ['message' => 'This subject is already assigned to this section.'], 409);

$excludeTeacher = $action === 'edit' ? (int)$assignment['old_teacher_id'] : 0;
$excludeSubject = $action === 'edit' ? $assignment['old_subject_code'] : '';
$excludeSection = $action === 'edit' ? (int)$assignment['old_section_id'] : 0;
foreach ($days as $day) {
    $teacherConflict = getRecord("SELECT tcs.start_time, tcs.end_time, sub.subject_name, sec.section_name, sec.grade_level
        FROM teacher_class_schedule tcs JOIN subjects sub ON sub.subject_code=tcs.subject_code JOIN sections sec ON sec.section_id=tcs.section_id
        WHERE tcs.school_year_id=? AND tcs.day_of_week=? AND tcs.is_active=1 AND tcs.teacher_id=?
          AND NOT (tcs.teacher_id=? AND tcs.subject_code=? AND tcs.section_id=?)
          AND ? < tcs.end_time AND ? > tcs.start_time LIMIT 1",
        [$schoolYearId, $day, $teacherId, $excludeTeacher, $excludeSubject, $excludeSection, $start, $end]);
    if ($teacherConflict) assignment_json(false, ['message' => 'Schedule Conflict: This teacher already has ' . $teacherConflict['subject_name'] . ' – Section ' . $teacherConflict['section_name'] . ' from ' . assignment_time_label($teacherConflict['start_time']) . ' to ' . assignment_time_label($teacherConflict['end_time']) . '.'], 409);

    $sectionConflict = getRecord("SELECT tcs.start_time, tcs.end_time, sub.subject_name, u.first_name, u.last_name
        FROM teacher_class_schedule tcs JOIN subjects sub ON sub.subject_code=tcs.subject_code JOIN users u ON u.user_id=tcs.teacher_id
        WHERE tcs.school_year_id=? AND tcs.day_of_week=? AND tcs.section_id=? AND tcs.is_active=1
          AND NOT (tcs.teacher_id=? AND tcs.subject_code=? AND tcs.section_id=?)
          AND ? < tcs.end_time AND ? > tcs.start_time LIMIT 1",
        [$schoolYearId, $day, $sectionId, $excludeTeacher, $excludeSubject, $excludeSection, $start, $end]);
    if ($sectionConflict) assignment_json(false, ['message' => 'Schedule Conflict: This section already has ' . $sectionConflict['subject_name'] . ' with ' . trim($sectionConflict['first_name'] . ' ' . $sectionConflict['last_name']) . ' from ' . assignment_time_label($sectionConflict['start_time']) . ' to ' . assignment_time_label($sectionConflict['end_time']) . '.'], 409);
}

if (!empty($input['validate_only'])) assignment_json(true, ['message' => 'No schedule conflict detected.']);

try {
    global $db;
    $db->beginTransaction();
    if ($action === 'edit') {
        // Editing may not move an assignment into a grade level outside the
        // teacher's department either.
        $newDeptCheck = tdCheckAssignment($teacher['department_level'] ?? '', $section['grade_level']);
        if (!$newDeptCheck['ok']) assignment_json(false, ['message' => $newDeptCheck['message']], 422);
        executeQuery("UPDATE teacher_subjects SET teacher_id=?, subject_code=?, section_id=? WHERE ts_id=? AND school_year_id=?", [$teacherId, $subjectCode, $sectionId, $tsId, $schoolYearId]);
        executeQuery("DELETE FROM teacher_class_schedule WHERE teacher_id=? AND subject_code=? AND section_id=? AND school_year_id=?", [$excludeTeacher, $excludeSubject, $excludeSection, $schoolYearId]);
    } else {
        executeQuery("INSERT INTO teacher_subjects (teacher_id, subject_code, section_id, school_year_id) VALUES (?, ?, ?, ?)", [$teacherId, $subjectCode, $sectionId, $schoolYearId]);
    }
    foreach ($days as $day) {
        executeQuery("INSERT INTO teacher_class_schedule (teacher_id, subject_code, section_id, school_year_id, day_of_week, start_time, end_time, shift_type, shift, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)", [$teacherId, $subjectCode, $sectionId, $schoolYearId, $day, $start, $end, $shift ? ($shift === 'AM' ? 'JHS Morning' : 'JHS Afternoon') : 'SHS', $shift ?: 'SHS']);
    }
    $db->commit();
    assignment_json(true, ['message' => 'Teacher assignment and schedule saved successfully.']);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('Subject assignment schedule failed: ' . $error->getMessage());
    assignment_json(false, ['message' => 'Could not save the assignment and schedule.'], 500);
}
