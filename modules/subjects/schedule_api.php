<?php
// Subject Management master scheduling API.
// Reuses teacher_subjects: admin schedules only assignments that already exist.
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

function sched_json($ok, $data = [], $status = 200) {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok], $data));
    exit;
}
function sched_time12($time) {
    $t = strtotime($time);
    return $t ? date('g:i A', $t) : $time;
}
function sched_day_name($n) {
    $days = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];
    return $days[(int)$n] ?? 'Day '.(int)$n;
}
function sched_shift_config() {
    $defaults = [
        'jhs_morning_start'=>'07:00', 'jhs_morning_end'=>'12:00',
        'jhs_afternoon_start'=>'12:30', 'jhs_afternoon_end'=>'17:30'
    ];
    $rows = getRecords("SELECT setting_key, setting_value FROM system_settings WHERE setting_group='attendance' AND setting_key IN ('jhs_morning_start','jhs_morning_end','jhs_afternoon_start','jhs_afternoon_end')");
    foreach ($rows as $r) if (!empty($r['setting_value'])) $defaults[$r['setting_key']] = substr($r['setting_value'],0,5);
    return $defaults;
}
function sched_default_shift($grade) {
    if (in_array($grade, ['Grade 7','Grade 8'], true)) return 'AM';
    if (in_array($grade, ['Grade 9','Grade 10'], true)) return 'PM';
    return '';
}
function sched_shift_type($shift, $grade) {
    if ($shift === 'AM') return 'JHS Morning';
    if ($shift === 'PM') return 'JHS Afternoon';
    if (in_array($grade, ['Grade 11','Grade 12'], true)) return 'SHS';
    return 'Custom';
}

if (!hasRole('Administrator')) sched_json(false, ['message'=>'Administrator access required.'], 403);
$active_sy = getRecord("SELECT sy_id FROM school_years WHERE is_active=1");
$sy = (int)($active_sy['sy_id'] ?? 0);
if (!$sy) sched_json(false, ['message'=>'No active school year found.'], 400);

// ---------------------------------------------------------------------------
// Teacher availability (read-only) -- feeds the time-slot picker in the
// Assign Teacher and Set Scheduling modals.
//   GET schedule_api.php?action=availability&teacher_id=&section_id=&ts_id=&days=1,2,3&shift=AM|PM|SHS
// Builds the class periods of the chosen shift (from the school's configured
// shift times, skipping the configured break) and marks each one:
//   free     -> selectable
//   occupied -> the teacher already has a class that overlaps it (any selected day)
//   section  -> the section already has a class from another teacher
//   break    -> the configured recess (never selectable)
// The same overlap rule the save endpoints use is applied here
// (slotStart < existingEnd AND slotEnd > existingStart), and the rows of the
// assignment being edited are ignored so it never blocks itself.
// ---------------------------------------------------------------------------
function sched_hm_to_min($hm) {
    $p = explode(':', substr((string)$hm, 0, 5));
    return ((int)$p[0]) * 60 + (int)($p[1] ?? 0);
}
function sched_min_to_hm($min) { return sprintf('%02d:%02d', intdiv($min, 60), $min % 60); }
function sched_slot_config() {
    // Same keys/defaults as Attendance Settings (includes/attendance_helpers.php).
    $cfg = [
        'jhs_morning_start'=>'07:00', 'jhs_morning_break_start'=>'09:30', 'jhs_morning_break_end'=>'09:45', 'jhs_morning_end'=>'12:00',
        'jhs_afternoon_start'=>'12:30', 'jhs_afternoon_break_start'=>'15:00', 'jhs_afternoon_break_end'=>'15:15', 'jhs_afternoon_end'=>'17:30',
        'shs_start'=>'08:00', 'shs_break_start'=>'10:30', 'shs_break_end'=>'10:45', 'shs_end'=>'14:00',
    ];
    $rows = getRecords("SELECT setting_key, setting_value FROM system_settings WHERE setting_group='attendance'");
    foreach ($rows as $r) {
        if (array_key_exists($r['setting_key'], $cfg) && $r['setting_value'] !== null && $r['setting_value'] !== '') $cfg[$r['setting_key']] = substr($r['setting_value'], 0, 5);
    }
    return $cfg;
}
// Length of one class period, in minutes. Grades 7-10 use 45-minute periods
// (see the note in the Assign Teacher modal); Senior High uses 60.
const SCHED_PERIOD_MIN_JHS = 45;
const SCHED_PERIOD_MIN_SHS = 60;

function sched_build_periods($startHm, $endHm, $breakStartHm, $breakEndHm, $len) {
    $start = sched_hm_to_min($startHm); $end = sched_hm_to_min($endHm);
    $bs = sched_hm_to_min($breakStartHm); $be = sched_hm_to_min($breakEndHm);
    $out = [];
    $t = $start;
    if ($be > $bs && $bs > $start && $be < $end) {
        while ($t + $len <= $bs) { $out[] = ['start'=>$t, 'end'=>$t + $len, 'break'=>false]; $t += $len; }
        $out[] = ['start'=>$bs, 'end'=>$be, 'break'=>true];
        $t = $be;
    }
    while ($t + $len <= $end) { $out[] = ['start'=>$t, 'end'=>$t + $len, 'break'=>false]; $t += $len; }
    return $out;
}
function sched_time_range_label($a, $b) {
    $fa = date('g:i A', strtotime(sched_min_to_hm($a))); $fb = date('g:i A', strtotime(sched_min_to_hm($b)));
    if (substr($fa, -2) === substr($fb, -2)) $fa = substr($fa, 0, -3);
    return $fa . ' – ' . $fb;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'availability') {
    $teacher_id = (int)($_GET['teacher_id'] ?? 0);
    $section_id = (int)($_GET['section_id'] ?? 0);
    $ts_id = (int)($_GET['ts_id'] ?? 0);
    $days = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['days'] ?? ''))), fn($d) => $d >= 1 && $d <= 7)));

    // The assignment being edited (if any) must not block itself.
    $exTeacher = 0; $exSubject = ''; $exSection = 0;
    if ($ts_id > 0) {
        $own = getRecord("SELECT teacher_id, subject_code, section_id FROM teacher_subjects WHERE ts_id=? AND school_year_id=?", [$ts_id, $sy]);
        if ($own) {
            $exTeacher = (int)$own['teacher_id']; $exSubject = $own['subject_code']; $exSection = (int)$own['section_id'];
            if (!$section_id) $section_id = $exSection;
        }
    }
    $section = $section_id ? getRecord("SELECT section_id, section_name, grade_level FROM sections WHERE section_id=?", [$section_id]) : null;
    if (!$section) sched_json(false, ['message'=>'Section not found.'], 404);

    $grade = $section['grade_level'];
    $defaultShift = sched_default_shift($grade);
    $shift = $defaultShift;
    if (!$shift) {
        $shift = strtoupper(trim((string)($_GET['shift'] ?? 'SHS')));
        if (!in_array($shift, ['AM', 'PM', 'SHS'], true)) $shift = 'SHS';
    }
    $cfg = sched_slot_config();
    if ($shift === 'AM')      $w = [$cfg['jhs_morning_start'], $cfg['jhs_morning_end'], $cfg['jhs_morning_break_start'], $cfg['jhs_morning_break_end']];
    elseif ($shift === 'PM')  $w = [$cfg['jhs_afternoon_start'], $cfg['jhs_afternoon_end'], $cfg['jhs_afternoon_break_start'], $cfg['jhs_afternoon_break_end']];
    else                      $w = [$cfg['shs_start'], $cfg['shs_end'], $cfg['shs_break_start'], $cfg['shs_break_end']];
    $period = $defaultShift ? SCHED_PERIOD_MIN_JHS : SCHED_PERIOD_MIN_SHS;
    $periods = sched_build_periods($w[0], $w[1], $w[2], $w[3], $period);

    $teacherRows = []; $sectionRows = [];
    if ($days) {
        $in = implode(',', array_fill(0, count($days), '?'));
        if ($teacher_id) {
            $teacherRows = getRecords("SELECT tcs.day_of_week, TIME_FORMAT(tcs.start_time,'%H:%i') s, TIME_FORMAT(tcs.end_time,'%H:%i') e,
                                              sub.subject_name, sec.section_name, sec.grade_level
                                       FROM teacher_class_schedule tcs
                                       JOIN subjects sub ON sub.subject_code=tcs.subject_code
                                       JOIN sections sec ON sec.section_id=tcs.section_id
                                       WHERE tcs.school_year_id=? AND tcs.is_active=1 AND tcs.teacher_id=? AND tcs.day_of_week IN ($in)
                                         AND NOT (tcs.teacher_id=? AND tcs.subject_code=? AND tcs.section_id=?)",
                                      array_merge([$sy, $teacher_id], $days, [$exTeacher, $exSubject, $exSection]));
        }
        $sectionRows = getRecords("SELECT tcs.day_of_week, TIME_FORMAT(tcs.start_time,'%H:%i') s, TIME_FORMAT(tcs.end_time,'%H:%i') e,
                                          sub.subject_name, u.first_name, u.last_name
                                   FROM teacher_class_schedule tcs
                                   JOIN subjects sub ON sub.subject_code=tcs.subject_code
                                   JOIN users u ON u.user_id=tcs.teacher_id
                                   WHERE tcs.school_year_id=? AND tcs.is_active=1 AND tcs.section_id=? AND tcs.day_of_week IN ($in)
                                     AND NOT (tcs.teacher_id=? AND tcs.subject_code=? AND tcs.section_id=?)",
                                  array_merge([$sy, $section_id], $days, [$exTeacher, $exSubject, $exSection]));
    }
    $dn = [1=>'Mon', 2=>'Tue', 3=>'Wed', 4=>'Thu', 5=>'Fri', 6=>'Sat', 7=>'Sun'];

    $slots = [];
    foreach ($periods as $p) {
        $slot = ['start'=>sched_min_to_hm($p['start']), 'end'=>sched_min_to_hm($p['end']), 'label'=>sched_time_range_label($p['start'], $p['end']), 'status'=>'free', 'detail'=>''];
        if ($p['break']) {
            $slot['status'] = 'break'; $slot['detail'] = 'Break time';
            $slots[] = $slot; continue;
        }
        $tLines = []; $sLines = [];
        foreach ($teacherRows as $r) {
            if ($p['start'] < sched_hm_to_min($r['e']) && $p['end'] > sched_hm_to_min($r['s'])) {
                $tLines[] = $dn[(int)$r['day_of_week']] . ': ' . $r['grade_level'] . ' ' . $r['section_name'] . ' – ' . $r['subject_name'] . ' (' . sched_time_range_label(sched_hm_to_min($r['s']), sched_hm_to_min($r['e'])) . ')';
            }
        }
        foreach ($sectionRows as $r) {
            if ($p['start'] < sched_hm_to_min($r['e']) && $p['end'] > sched_hm_to_min($r['s'])) {
                $sLines[] = $dn[(int)$r['day_of_week']] . ': ' . $r['subject_name'] . ' with ' . trim($r['first_name'] . ' ' . $r['last_name']) . ' (' . sched_time_range_label(sched_hm_to_min($r['s']), sched_hm_to_min($r['e'])) . ')';
            }
        }
        if ($tLines) {
            $slot['status'] = 'occupied';
            $slot['detail'] = 'Teacher already has a class — ' . implode('; ', array_unique($tLines));
        } elseif ($sLines) {
            $slot['status'] = 'section';
            $slot['detail'] = 'Section ' . $section['section_name'] . ' already has a class — ' . implode('; ', array_unique($sLines));
        }
        $slots[] = $slot;
    }

    sched_json(true, [
        'ready'   => (bool)($teacher_id && $days),
        'shift'   => $shift,
        'shifts'  => $defaultShift ? [$defaultShift] : ['AM', 'PM', 'SHS'],
        'period'  => $period,
        'window'  => ['start'=>$w[0], 'end'=>$w[1]],
        'slots'   => $slots,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $teacher_id = (int)($_GET['teacher_id'] ?? 0);
    if (!$teacher_id) sched_json(true, ['assignments'=>[]]);
    $assignments = getRecords("SELECT ts.ts_id, ts.teacher_id, ts.subject_code, ts.section_id,
                                      sub.subject_name, sec.section_name, sec.grade_level, sec.strand
                               FROM teacher_subjects ts
                               JOIN subjects sub ON sub.subject_code=ts.subject_code
                               JOIN sections sec ON sec.section_id=ts.section_id
                               WHERE ts.teacher_id=? AND ts.school_year_id=?
                               ORDER BY FIELD(sec.grade_level,'Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12'), sec.section_name, sub.subject_name",
                              [$teacher_id,$sy]);
    foreach ($assignments as &$a) {
        $a['default_shift'] = sched_default_shift($a['grade_level']);
        $a['schedules'] = getRecords("SELECT schedule_id, day_of_week, TIME_FORMAT(start_time,'%H:%i') start_time, TIME_FORMAT(end_time,'%H:%i') end_time,
                                             COALESCE(NULLIF(shift,''), CASE WHEN shift_type='JHS Morning' THEN 'AM' WHEN shift_type='JHS Afternoon' THEN 'PM' ELSE '' END) shift
                                      FROM teacher_class_schedule
                                      WHERE teacher_id=? AND subject_code=? AND section_id=? AND school_year_id=? AND is_active=1
                                      ORDER BY day_of_week,start_time",
                                     [$teacher_id,$a['subject_code'],$a['section_id'],$sy]);
    }
    unset($a);
    sched_json(true, ['assignments'=>$assignments, 'shift_config'=>sched_shift_config()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sched_json(false, ['message'=>'Unsupported request.'], 405);
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
if (!verifyCSRFToken($input['csrf_token'] ?? '')) sched_json(false, ['message'=>'Invalid security token. Refresh the page and try again.'], 403);

$ts_id = (int)($input['ts_id'] ?? 0);
$days = $input['days'] ?? [];
$start = trim((string)($input['start_time'] ?? ''));
$end = trim((string)($input['end_time'] ?? ''));
$shift = strtoupper(trim((string)($input['shift'] ?? '')));
if (!is_array($days)) $days = [$days];
$days = array_values(array_unique(array_filter(array_map('intval',$days), fn($d)=>$d>=1 && $d<=7)));

$assignment = getRecord("SELECT ts.*, sub.subject_name, sec.section_name, sec.grade_level
                         FROM teacher_subjects ts
                         JOIN subjects sub ON sub.subject_code=ts.subject_code
                         JOIN sections sec ON sec.section_id=ts.section_id
                         WHERE ts.ts_id=? AND ts.school_year_id=?", [$ts_id,$sy]);
if (!$assignment) sched_json(false, ['message'=>'This teacher assignment no longer exists.'], 404);
if (!$days) sched_json(false, ['message'=>'Select at least one day.'], 422);
if (!preg_match('/^\d{2}:\d{2}$/',$start) || !preg_match('/^\d{2}:\d{2}$/',$end) || strtotime($end) <= strtotime($start))
    sched_json(false, ['message'=>'End time must be later than start time.'], 422);

$grade = $assignment['grade_level'];
$defaultShift = sched_default_shift($grade);
if ($defaultShift && $shift !== $defaultShift) {
    sched_json(false, ['message'=>"Schedule Conflict: {$grade} uses the {$defaultShift} Shift based on the school's shift configuration."], 422);
}
if (!$defaultShift && !in_array($shift,['AM','PM','SHS','CUSTOM',''],true)) sched_json(false,['message'=>'Select a valid shift.'],422);

$config = sched_shift_config();
if ($defaultShift === 'AM' && ($start < $config['jhs_morning_start'] || $end > $config['jhs_morning_end'])) {
    sched_json(false, ['message'=>'Schedule Conflict: Grade 7–8 classes must be within the configured AM shift ('.sched_time12($config['jhs_morning_start']).'–'.sched_time12($config['jhs_morning_end']).').'], 422);
}
if ($defaultShift === 'PM' && ($start < $config['jhs_afternoon_start'] || $end > $config['jhs_afternoon_end'])) {
    sched_json(false, ['message'=>'Schedule Conflict: Grade 9–10 classes must be within the configured PM shift ('.sched_time12($config['jhs_afternoon_start']).'–'.sched_time12($config['jhs_afternoon_end']).').'], 422);
}

// Validate every requested day before changing anything. Proper overlap:
// newStart < existingEnd AND newEnd > existingStart.
$validateOnly = !empty($input['validate_only']);
foreach ($days as $day) {
    $teacherConflict = getRecord("SELECT tcs.*, sub.subject_name, sec.section_name, sec.grade_level
                                  FROM teacher_class_schedule tcs
                                  JOIN subjects sub ON sub.subject_code=tcs.subject_code
                                  JOIN sections sec ON sec.section_id=tcs.section_id
                                  WHERE tcs.school_year_id=? AND tcs.day_of_week=? AND tcs.is_active=1
                                    AND tcs.teacher_id=?
                                    AND NOT (tcs.teacher_id=? AND tcs.subject_code=? AND tcs.section_id=?)
                                    AND ? < TIME_FORMAT(tcs.end_time,'%H:%i') AND ? > TIME_FORMAT(tcs.start_time,'%H:%i')
                                  LIMIT 1",
                                 [$sy,$day,$assignment['teacher_id'],$assignment['teacher_id'],$assignment['subject_code'],$assignment['section_id'],$start,$end]);
    if ($teacherConflict) {
        sched_json(false, ['message'=>'Schedule Conflict: Teacher already has '.$teacherConflict['grade_level'].' '.$teacherConflict['section_name'].' – '.$teacherConflict['subject_name'].' on '.sched_day_name($day).' from '.sched_time12($teacherConflict['start_time']).'–'.sched_time12($teacherConflict['end_time']).'.'], 409);
    }
    $sectionConflict = getRecord("SELECT tcs.*, sub.subject_name, u.first_name, u.last_name
                                  FROM teacher_class_schedule tcs
                                  JOIN subjects sub ON sub.subject_code=tcs.subject_code
                                  JOIN users u ON u.user_id=tcs.teacher_id
                                  WHERE tcs.school_year_id=? AND tcs.day_of_week=? AND tcs.is_active=1
                                    AND tcs.section_id=?
                                    AND NOT (tcs.teacher_id=? AND tcs.subject_code=? AND tcs.section_id=?)
                                    AND ? < TIME_FORMAT(tcs.end_time,'%H:%i') AND ? > TIME_FORMAT(tcs.start_time,'%H:%i')
                                  LIMIT 1",
                                 [$sy,$day,$assignment['section_id'],$assignment['teacher_id'],$assignment['subject_code'],$assignment['section_id'],$start,$end]);
    if ($sectionConflict) {
        $teacherName = trim($sectionConflict['first_name'].' '.$sectionConflict['last_name']);
        sched_json(false, ['message'=>'Schedule Conflict: Section '.$assignment['section_name'].' already has '.$sectionConflict['subject_name'].' with '.$teacherName.' on '.sched_day_name($day).' from '.sched_time12($sectionConflict['start_time']).'–'.sched_time12($sectionConflict['end_time']).'.'], 409);
    }
}

if ($validateOnly) {
    sched_json(true, ['message'=>'No schedule conflict detected.']);
}

try {
    global $db;
    $db->beginTransaction();
    executeQuery("DELETE FROM teacher_class_schedule WHERE teacher_id=? AND subject_code=? AND section_id=? AND school_year_id=?",
                 [$assignment['teacher_id'],$assignment['subject_code'],$assignment['section_id'],$sy]);
    $shiftType = sched_shift_type($shift,$grade);
    foreach ($days as $day) {
        executeQuery("INSERT INTO teacher_class_schedule
            (teacher_id,subject_code,section_id,school_year_id,day_of_week,start_time,end_time,shift_type,shift,is_active)
            VALUES (?,?,?,?,?,?,?,?,?,1)",
            [$assignment['teacher_id'],$assignment['subject_code'],$assignment['section_id'],$sy,$day,$start,$end,$shiftType,$shift]);
    }
    $db->commit();
    if (function_exists('logActivity')) logActivity($_SESSION['user_id'], 'subjects', 'Set master class schedule', $assignment['subject_code'].' / section '.$assignment['section_id'].' / '.implode(',',$days).' '.$start.'-'.$end);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('Set schedule failed: '.$e->getMessage());
    sched_json(false, ['message'=>'Could not save the schedule. Please try again.'], 500);
}

sched_json(true, ['message'=>'Schedule saved successfully.']);
