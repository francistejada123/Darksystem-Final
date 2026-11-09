<?php
// includes/attendance_qr_helpers.php
//
// QR-code attendance for STUDENTS (Teacher Portal -> Attendance).
//
//   * every approved student gets ONE stable QR code, generated from the LRN
//     and signed so a QR cannot be forged just by knowing somebody's LRN;
//   * the teacher opens an attendance SESSION for one class (subject +
//     section) and scans students IN as they arrive; every scan stores the
//     exact time-in and is compared with the class's scheduled start time:
//       scan <= start  -> Present        scan > start -> Late
//   * a student can only be scanned IN once per class per day;
//   * ending the session marks the students who never scanned as Absent.
//
//   * a scan is NOT limited to whichever class the teacher currently has on
//     screen: attQrRecordScan() looks up the scanned student's own Approved
//     section for the active school year, then finds one of THIS teacher's
//     classes for that section that already has an Open session today (the
//     class on screen is preferred when it qualifies). This never opens a
//     new session on its own -- the teacher must have already pressed
//     "Start IN Scanning" for one of the student's classes -- it only saves
//     the teacher from having to flip the Class picker to match every
//     student before scanning them.
//
// Everything is written into the EXISTING `attendance` table, so Attendance
// History, AI Analytics and the at-risk attendance monitoring
// (includes/attendance_helpers.php) keep working unchanged. Only two
// additive, nullable columns are added to it (time_in, marked_via), plus two
// small new tables (attendance_sessions, attendance_qr_keys). Nothing is
// dropped or rewritten, and existing rows are never touched.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/attendance_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

/** Payload prefix of every student QR code. */
const ATT_QR_PREFIX = 'EGP1';

// ============================================================
// SCHEMA (additive, idempotent -- same convention as the other
// *EnsureSchema() helpers in this project)
// ============================================================

/**
 * Makes sure the attendance table has time_in / marked_via and that the
 * session + key tables exist. Safe to call on every request.
 * @return bool true when everything QR attendance needs is in place
 */
function attQrEnsureSchema()
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (schemaIsReady('attendance_qr_v1')) {
        return $ready = true;
    }

    $hasColumn = function ($table, $column) {
        $r = getRecord(
            "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
        return $r && (int) $r['n'] > 0;
    };

    if (!$hasColumn('attendance', 'time_in')) {
        executeQuery("ALTER TABLE `attendance` ADD COLUMN `time_in` time DEFAULT NULL COMMENT 'Exact scan time when recorded by QR/LRN scan'");
    }
    if (!$hasColumn('attendance', 'marked_via')) {
        executeQuery("ALTER TABLE `attendance` ADD COLUMN `marked_via` varchar(20) DEFAULT NULL COMMENT 'qr | lrn | session_close (NULL = recorded manually before QR attendance)'");
    }

    executeQuery("CREATE TABLE IF NOT EXISTS `attendance_sessions` (
        `session_id` int(11) NOT NULL AUTO_INCREMENT,
        `teacher_id` int(11) NOT NULL,
        `subject_code` varchar(20) NOT NULL,
        `section_id` int(11) NOT NULL,
        `school_year_id` int(11) NOT NULL,
        `session_date` date NOT NULL,
        `schedule_start` time NOT NULL,
        `start_source` varchar(20) NOT NULL DEFAULT 'schedule',
        `status` enum('Open','Closed') NOT NULL DEFAULT 'Open',
        `started_at` datetime NOT NULL,
        `ended_at` datetime DEFAULT NULL,
        PRIMARY KEY (`session_id`),
        UNIQUE KEY `uniq_att_session` (`subject_code`,`section_id`,`session_date`),
        KEY `idx_att_session_teacher` (`teacher_id`,`session_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    executeQuery("CREATE TABLE IF NOT EXISTS `attendance_qr_keys` (
        `key_id` tinyint(4) NOT NULL,
        `secret` char(64) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`key_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $ready = $hasColumn('attendance', 'time_in') && $hasColumn('attendance', 'marked_via');
    $__schema_ok = ($ready);
    if ($__schema_ok) {
        schemaMarkReady('attendance_qr_v1');
    }
    return $__schema_ok;
}

// ============================================================
// QR TOKENS
// ============================================================

/**
 * The signing secret. Created once, then stored so every QR code stays valid.
 * (Changing/deleting the row invalidates every QR that was already printed.)
 */
function attQrSecret()
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    attQrEnsureSchema();
    $row = getRecord("SELECT secret FROM attendance_qr_keys WHERE key_id = 1");
    if (!$row) {
        executeQuery("INSERT IGNORE INTO attendance_qr_keys (key_id, secret) VALUES (1, ?)", [bin2hex(random_bytes(32))]);
        $row = getRecord("SELECT secret FROM attendance_qr_keys WHERE key_id = 1");
    }
    // Only if the database itself is unwritable: a fixed per-install value so pages still render.
    $secret = $row ? $row['secret'] : hash('sha256', 'egradepro-qr|' . __DIR__);
    return $secret;
}

/** The text encoded inside a student's QR code. */
function attQrPayload($lrn)
{
    $lrn = trim((string) $lrn);
    return ATT_QR_PREFIX . ':' . $lrn . ':' . substr(hash_hmac('sha256', ATT_QR_PREFIX . '|' . $lrn, attQrSecret()), 0, 12);
}

/**
 * Reads a scanned QR text. Returns the LRN it carries, or null when the text
 * is not one of ours or its signature does not match.
 */
function attQrParsePayload($text)
{
    $text = trim((string) $text);
    if (!preg_match('/^' . ATT_QR_PREFIX . ':([A-Za-z0-9\-]{1,20}):([0-9a-f]{12})$/', $text, $m)) {
        return null;
    }
    $expected = substr(hash_hmac('sha256', ATT_QR_PREFIX . '|' . $m[1], attQrSecret()), 0, 12);
    return hash_equals($expected, $m[2]) ? $m[1] : null;
}

// ============================================================
// "IS THIS STUDENT APPROVED?" + the clickable LRN
// ============================================================

/**
 * lrn => student_id for every active student whose enrollment in the ACTIVE
 * school year has been approved. One query, cached for the request.
 * @return array<string,int>
 */
function attQrApprovedByLrn()
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $rows = getRecords("
        SELECT s.student_id, s.lrn
        FROM students s
        JOIN enrollments e ON e.student_id = s.student_id
        WHERE e.school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1 LIMIT 1)
          AND e.enrollment_status = 'Approved'
          AND s.is_active = 1
    ");
    foreach ($rows as $r) {
        $map[(string) $r['lrn']] = (int) $r['student_id'];
    }
    return $map;
}

/** Is this student's enrollment approved (so their QR code may be shown)? */
function attQrIsApproved($student_id)
{
    return in_array((int) $student_id, attQrApprovedByLrn(), true);
}

/**
 * The LRN as HTML. Once the student's enrollment is approved it becomes a
 * link to their QR code page; before that it stays plain text.
 * @param string   $lrn
 * @param int|null $student_id  optional, speeds nothing up -- the LRN alone is enough
 */
function attQrLrnLink($lrn, $student_id = null)
{
    static $styled = false;
    $lrn = (string) $lrn;
    $safe = htmlspecialchars($lrn);
    if ($lrn === '' || $lrn === 'N/A') {
        return $safe;
    }
    $map = attQrApprovedByLrn();
    if (!isset($map[$lrn])) {
        return $safe;
    }
    $style = '';
    if (!$styled) {
        $styled = true;
        $style = '<style>.lrn-qr-link{color:inherit;text-decoration:none;border-bottom:1px dashed currentColor;cursor:pointer;white-space:nowrap}'
            . '.lrn-qr-link i{font-size:.85em;margin-right:5px;opacity:.65}.lrn-qr-link:hover{color:#1f73e8}</style>';
    }
    return $style . '<a class="lrn-qr-link" href="' . BASE_URL . 'modules/attendance/student_qr.php?student_id=' . (int) $map[$lrn]
        . '" title="Click to show this student\'s QR code"><i class="fas fa-qrcode"></i>' . $safe . '</a>';
}

// ============================================================
// CLASS START TIME  (the value a scan is compared with)
// ============================================================

/** 'H:i' / 'H:i:s' -> 'H:i:s' */
function attQrNormalizeTime($t)
{
    $t = trim((string) $t);
    if (preg_match('/^\d{1,2}:\d{2}$/', $t)) {
        $t .= ':00';
    }
    if (!preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $t, $m)) {
        return '00:00:00';
    }
    return sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3]);
}

/**
 * When does this class start on the given day ($ts, default now)?
 * ONLY from the class's own meeting in teacher_class_schedule -- the schedule
 * the Administrator sets in Teacher Management (Schedule) -- matched by
 * teacher + subject + section + school year + weekday; the current or next
 * meeting that day. Admin schedule edits show up immediately (read live).
 *
 * No schedule for that weekday -> source 'none' and start NULL. There is no
 * silent fallback to the school program start (e.g. 12:30 PM) any more, so a
 * wrong start time can never decide Present/Late.
 * @return array{start:?string,end:?string,source:string,label:string}
 */
function attQrResolveClassStart($teacherId, $subjectCode, $sectionId, $schoolYearId, $ts = null)
{
    $ts = $ts ?: time();
    $dow = (int) date('N', $ts);
    $now = date('H:i:s', $ts);

    $rows = getRecords("
        SELECT start_time, end_time FROM teacher_class_schedule
        WHERE teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ?
          AND day_of_week = ? AND is_active = 1
        ORDER BY start_time
    ", [$teacherId, $subjectCode, $sectionId, $schoolYearId, $dow]);

    if (!empty($rows)) {
        $pick = null;
        foreach ($rows as $r) {
            if (attQrNormalizeTime($r['end_time']) > $now) { // still running, or not started yet
                $pick = $r;
                break;
            }
        }
        $pick = $pick ?: end($rows); // every meeting already ended -> the last one
        return [
            'start'  => attQrNormalizeTime($pick['start_time']),
            'end'    => attQrNormalizeTime($pick['end_time']),
            'source' => 'schedule',
            'label'  => 'Class schedule',
        ];
    }

    return [
        'start'  => null,
        'end'    => null,
        'source' => 'none',
        'label'  => 'No class schedule set for this day',
    ];
}

/**
 * Every weekly meeting of this class (same match as above), for showing
 * "This class meets: Tue 3:15 PM - 4:00 PM" when the chosen day has none.
 * @return string[]
 */
function attQrClassWeekSchedule($teacherId, $subjectCode, $sectionId, $schoolYearId)
{
    $days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $out = [];
    foreach (getRecords("
        SELECT day_of_week, start_time, end_time FROM teacher_class_schedule
        WHERE teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND is_active = 1
        ORDER BY day_of_week, start_time
    ", [$teacherId, $subjectCode, $sectionId, $schoolYearId]) ?: [] as $r) {
        $out[] = ($days[(int) $r['day_of_week']] ?? '') . ' ' . attQrFormatTime($r['start_time']) . ' - ' . attQrFormatTime($r['end_time']);
    }
    return $out;
}

/** Present when the scan is on/before the start time, Late after it. */
function attQrStatusFor($scanTime, $classStart)
{
    return attQrNormalizeTime($scanTime) <= attQrNormalizeTime($classStart) ? 'Present' : 'Late';
}

// ============================================================
// CLASS / ROSTER
// ============================================================

/** Does this teacher teach this subject in this section this school year? */
function attQrTeacherOwnsClass($teacherId, $subjectCode, $sectionId, $schoolYearId)
{
    return (bool) getRecord(
        "SELECT ts_id FROM teacher_subjects WHERE teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ?",
        [$teacherId, $subjectCode, $sectionId, $schoolYearId]
    );
}

/** Approved, active students of one section. */
function attQrSectionStudents($sectionId, $schoolYearId)
{
    return getRecords("
        SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.middle_name
        FROM students s
        JOIN enrollments e ON e.student_id = s.student_id
        WHERE e.section_id = ? AND e.school_year_id = ? AND e.enrollment_status = 'Approved' AND s.is_active = 1
        ORDER BY s.last_name, s.first_name
    ", [$sectionId, $schoolYearId]);
}

/** First character of a name (multibyte-safe when mbstring is available). */
function attQrInitial($text)
{
    $text = (string) $text;
    return function_exists('mb_substr') ? mb_substr($text, 0, 1) : substr($text, 0, 1);
}

/** '7:58 AM' from a TIME value, or '' */
function attQrFormatTime($t)
{
    return ($t === null || $t === '') ? '' : date('g:i A', strtotime('1970-01-01 ' . $t));
}

/**
 * Every student's attendance summary for ONE class from $start through $end
 * (the "This term" column), computed with the same
 * gh_summarizeAttendanceRows() rules the at-risk monitoring uses -- but from a
 * single query, so the live poll stays cheap.
 * @return array<int,array> student_id => summary
 */
function attQrTermSummaries($subjectCode, $sectionId, $start, $end)
{
    $rows = getRecords(
        "SELECT student_id, status FROM attendance
         WHERE subject_code = ? AND section_id = ? AND date >= ? AND date <= ?
         ORDER BY date ASC",
        [$subjectCode, $sectionId, $start, $end]
    );
    $by = [];
    foreach ($rows as $r) {
        $by[(int) $r['student_id']][] = ['status' => $r['status']];
    }
    $out = [];
    foreach ($by as $sid => $list) {
        $out[$sid] = gh_summarizeAttendanceRows($list);
    }
    return $out;
}

/**
 * First day of the "This term so far" range: the term's own Start Date when
 * one is set in Manage Assessment Items, otherwise the school year's start.
 */
function attQrSummaryStart($schoolYearId, $term)
{
    require_once __DIR__ . '/grading_helpers.php';
    $window = gh_getTermWindow($schoolYearId, $term);
    if (!empty($window['start_date'])) {
        return $window['start_date'];
    }
    $sy = getRecord("SELECT start_date FROM school_years WHERE sy_id = ?", [$schoolYearId]);
    return $sy['start_date'] ?? date('Y-01-01');
}

/**
 * Roster + counts for one class on one date -- what the page renders and what
 * the live poll returns.
 * @return array{students:array<int,array>,counts:array<string,int>}
 */
function attQrRoster($subjectCode, $sectionId, $schoolYearId, $date, $summaryStart = null)
{
    $students = attQrSectionStudents($sectionId, $schoolYearId);
    $summaries = $summaryStart ? attQrTermSummaries($subjectCode, $sectionId, $summaryStart, date('Y-m-d')) : [];
    $rows = getRecords(
        "SELECT student_id, status, time_in, marked_via FROM attendance WHERE subject_code = ? AND section_id = ? AND date = ?",
        [$subjectCode, $sectionId, $date]
    );
    $by = [];
    foreach ($rows as $r) {
        $by[(int) $r['student_id']] = $r;
    }

    $counts = ['Total' => count($students), 'Present' => 0, 'Late' => 0, 'Absent' => 0, 'Excused' => 0, 'Waiting' => 0];
    $out = [];
    foreach ($students as $s) {
        $sid = (int) $s['student_id'];
        $rec = $by[$sid] ?? null;
        $status = $rec ? $rec['status'] : 'Waiting';
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
        $out[] = [
            'student_id' => $sid,
            'lrn'        => $s['lrn'],
            'name'       => $s['last_name'] . ', ' . $s['first_name'] . ($s['middle_name'] ? ' ' . attQrInitial($s['middle_name']) . '.' : ''),
            'initials'   => strtoupper(attQrInitial($s['first_name']) . attQrInitial($s['last_name'])),
            'status'     => $status,
            'time_in'    => $rec && $rec['time_in'] !== null ? $rec['time_in'] : null,
            'time_label' => $rec ? attQrFormatTime($rec['time_in']) : '',
            'via'        => $rec ? $rec['marked_via'] : null,
            'pct'        => isset($summaries[$sid]) ? $summaries[$sid]['percentage'] : null,
            'at_risk'    => isset($summaries[$sid]) ? (bool) $summaries[$sid]['at_risk'] : false,
        ];
    }
    return ['students' => $out, 'counts' => $counts];
}

// ============================================================
// SESSIONS
// ============================================================

/** Today's (or any date's) session row for a class, or null. */
function attQrGetSession($subjectCode, $sectionId, $date)
{
    return getRecord(
        "SELECT * FROM attendance_sessions WHERE subject_code = ? AND section_id = ? AND session_date = ?",
        [$subjectCode, $sectionId, $date]
    );
}

/**
 * Starts (or resumes) IN scanning for a class today. The class start time is
 * fixed when the session opens so every student in it is judged by the same
 * clock.
 * @return array{success:bool,session?:array,error?:string}
 */
function attQrStartSession($teacherId, $subjectCode, $sectionId, $schoolYearId)
{
    if (!attQrEnsureSchema()) {
        return ['success' => false, 'error' => 'Attendance database update could not be applied. Please contact the administrator.'];
    }
    $today = date('Y-m-d');
    $session = attQrGetSession($subjectCode, $sectionId, $today);
    if ($session) {
        if ($session['status'] !== 'Open') {
            executeQuery("UPDATE attendance_sessions SET status = 'Open', ended_at = NULL, teacher_id = ? WHERE session_id = ?", [$teacherId, $session['session_id']]);
        }
    } else {
        $start = attQrResolveClassStart($teacherId, $subjectCode, $sectionId, $schoolYearId);
        if ($start['source'] !== 'schedule' || $start['start'] === null) {
            // No meeting for this class today in the Admin schedule -> do not
            // open a session with a made-up start time.
            return ['success' => false, 'error' => 'No class schedule set for this day. Ask the administrator to set this class\'s schedule in Teacher Management.'];
        }
        executeQuery(
            "INSERT IGNORE INTO attendance_sessions (teacher_id, subject_code, section_id, school_year_id, session_date, schedule_start, start_source, status, started_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Open', ?)",
            [$teacherId, $subjectCode, $sectionId, $schoolYearId, $today, $start['start'], $start['source'], date('Y-m-d H:i:s')]
        );
    }
    $session = attQrGetSession($subjectCode, $sectionId, $today);
    if (!$session) {
        return ['success' => false, 'error' => 'Could not start the attendance session.'];
    }
    return ['success' => true, 'session' => $session];
}

/**
 * Ends today's session: every student who never scanned IN is marked Absent.
 * Students who scanned are left exactly as they are. Idempotent.
 * @return array{success:bool,absent_marked?:int,error?:string}
 */
function attQrEndSession($teacherId, $subjectCode, $sectionId, $schoolYearId)
{
    $today = date('Y-m-d');
    $session = attQrGetSession($subjectCode, $sectionId, $today);
    if (!$session) {
        return ['success' => false, 'error' => 'There is no attendance session to end.'];
    }
    $marked = 0;
    if ($session['status'] === 'Open') {
        $existing = getRecords("SELECT student_id FROM attendance WHERE subject_code = ? AND section_id = ? AND date = ?", [$subjectCode, $sectionId, $today]);
        $have = [];
        foreach ($existing as $r) {
            $have[(int) $r['student_id']] = true;
        }
        foreach (attQrSectionStudents($sectionId, $schoolYearId) as $s) {
            if (isset($have[(int) $s['student_id']])) {
                continue;
            }
            $r = executeQuery(
                "INSERT IGNORE INTO attendance (student_id, subject_code, section_id, date, status, marked_via) VALUES (?, ?, ?, ?, 'Absent', 'session_close')",
                [$s['student_id'], $subjectCode, $sectionId, $today]
            );
            if ($r && $r->rowCount() > 0) {
                $marked++;
                require_once __DIR__ . '/parent_portal.php';
                ppAbsentAlert((int) $s['student_id'], $today);   // text to linked parents, once a day
            }
        }
        executeQuery("UPDATE attendance_sessions SET status = 'Closed', ended_at = ? WHERE session_id = ?", [date('Y-m-d H:i:s'), $session['session_id']]);
    }
    return ['success' => true, 'absent_marked' => $marked];
}

// ============================================================
// THE SCAN
// ============================================================

/**
 * Among several candidate classes (each already an Open session today, for
 * the same teacher), pick the one whose teacher_class_schedule says it's
 * meeting right now. Returns one row from $candidates, or null when none of
 * them are scheduled for this exact moment (caller falls back to whichever
 * was started first).
 */
function attQrPickScheduledNow($teacherId, $schoolYearId, array $candidates)
{
    $dow = (int) date('N');
    $now = date('H:i:s');
    foreach ($candidates as $c) {
        $rows = getRecords("
            SELECT start_time, end_time FROM teacher_class_schedule
            WHERE teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ?
              AND day_of_week = ? AND is_active = 1
        ", [$teacherId, $c['subject_code'], $c['section_id'], $schoolYearId, $dow]);
        foreach ($rows as $r) {
            if (attQrNormalizeTime($r['start_time']) <= $now && $now <= attQrNormalizeTime($r['end_time'])) {
                return $c;
            }
        }
    }
    return null;
}

/**
 * Records one IN scan.
 *
 * The scan is NOT limited to the class the teacher currently has open on
 * screen ($subjectCode/$sectionId): the student's own Approved section for
 * the active school year is looked up, then matched against this teacher's
 * classes that already have an Open attendance session today for that
 * section. The class on screen is used when it's one of the matches;
 * otherwise, whichever is scheduled right now (teacher_class_schedule), and
 * failing that, whichever the teacher started first. Nothing is ever
 * auto-started -- if none of the student's classes have an Open session yet,
 * the scan is refused and says so.
 *
 * @param string $input   the raw QR text (method 'qr') or a typed LRN (method 'lrn')
 * @param string $method  'qr' | 'lrn'
 * @return array  success=false + error, or success=true with:
 *   duplicate (bool), status, time_in, time_label, name, lrn, grade_level,
 *   section_name, section_id, subject_code, subject_name, class_start --
 *   section_id/subject_code are the class actually recorded, which the
 *   caller (scan.php) uses to report on that class rather than whatever was
 *   on screen when the scan was sent.
 */
function attQrRecordScan($teacherId, $subjectCode, $sectionId, $schoolYearId, $input, $method = 'qr')
{
    if (!attQrEnsureSchema()) {
        return ['success' => false, 'error' => 'Attendance database update could not be applied.'];
    }
    $today = date('Y-m-d');

    // Who is this?
    $lrn = null;
    if ($method === 'qr') {
        $lrn = attQrParsePayload($input);
        if ($lrn === null) {
            return ['success' => false, 'error' => 'This is not a valid student QR code.', 'code' => 'invalid_qr'];
        }
    } else {
        $lrn = trim((string) $input);
        if ($lrn === '') {
            return ['success' => false, 'error' => 'Enter the student\'s LRN.', 'code' => 'invalid_lrn'];
        }
    }
    $student = getRecord("SELECT student_id, lrn, first_name, last_name, middle_name FROM students WHERE lrn = ? AND is_active = 1", [$lrn]);
    if (!$student) {
        return ['success' => false, 'error' => 'No active student found for this LRN.', 'code' => 'unknown_student'];
    }
    $name = $student['last_name'] . ', ' . $student['first_name'];

    // Every section this student is Approved-enrolled in for the active
    // school year -- almost always exactly one, but a scan should still work
    // correctly on the rare chance a student has more than one.
    $enrolledIn = getRecords("
        SELECT e.section_id, e.grade_level, sec.section_name
        FROM enrollments e
        JOIN sections sec ON sec.section_id = e.section_id
        WHERE e.student_id = ? AND e.school_year_id = ? AND e.enrollment_status = 'Approved'
    ", [$student['student_id'], $schoolYearId]);
    if (!$enrolledIn) {
        return ['success' => false, 'error' => $name . ' is not currently enrolled this school year.', 'code' => 'not_in_class', 'name' => $name, 'lrn' => $student['lrn']];
    }
    $sectionInfoById = [];
    foreach ($enrolledIn as $e) {
        $sectionInfoById[(int) $e['section_id']] = $e;
    }
    $sectionIds = array_keys($sectionInfoById);

    // This teacher's own classes, in any of those sections, that already
    // have IN scanning running today.
    $ph = implode(',', array_fill(0, count($sectionIds), '?'));
    $candidates = getRecords("
        SELECT ts.subject_code, sub.subject_name, ts.section_id, s.schedule_start, s.started_at
        FROM teacher_subjects ts
        JOIN subjects sub ON sub.subject_code = ts.subject_code
        JOIN attendance_sessions s ON s.subject_code = ts.subject_code AND s.section_id = ts.section_id AND s.session_date = ?
        WHERE ts.teacher_id = ? AND ts.school_year_id = ? AND ts.section_id IN ($ph) AND s.status = 'Open'
        ORDER BY s.started_at ASC
    ", array_merge([$today, $teacherId, $schoolYearId], $sectionIds));

    if (!$candidates) {
        // Tell the teacher which of this student's classes they could start,
        // even though none is running yet -- saves a trip to the roster.
        $available = getRecords("
            SELECT DISTINCT sub.subject_name, sec.section_name
            FROM teacher_subjects ts
            JOIN subjects sub ON sub.subject_code = ts.subject_code
            JOIN sections sec ON sec.section_id = ts.section_id
            WHERE ts.teacher_id = ? AND ts.school_year_id = ? AND ts.section_id IN ($ph)
            ORDER BY sub.subject_name
        ", array_merge([$teacherId, $schoolYearId], $sectionIds));
        return [
            'success'   => false,
            'error'     => 'You have not started attendance for any of ' . $name . "'s classes today.",
            'code'      => 'not_open_for_student',
            'name'      => $name,
            'lrn'       => $student['lrn'],
            'available' => array_map(function ($a) { return $a['subject_name'] . ' — ' . $a['section_name']; }, $available),
        ];
    }

    // Prefer the class on screen when it qualifies; otherwise the only
    // candidate; otherwise whichever is scheduled right now; otherwise
    // whichever the teacher started first today.
    $chosen = null;
    foreach ($candidates as $c) {
        if ($c['subject_code'] === $subjectCode && (int) $c['section_id'] === (int) $sectionId) {
            $chosen = $c;
            break;
        }
    }
    if (!$chosen) {
        $chosen = count($candidates) === 1
            ? $candidates[0]
            : (attQrPickScheduledNow($teacherId, $schoolYearId, $candidates) ?: $candidates[0]);
    }
    $subjectCode = $chosen['subject_code'];
    $sectionId = (int) $chosen['section_id'];
    $sectionInfo = $sectionInfoById[$sectionId];

    // Time-in vs the class start.
    $now = time();
    $timeIn = date('H:i:s', $now);
    $status = attQrStatusFor($timeIn, $chosen['schedule_start']);

    // Atomic: the first scan writes the row; any later scan of the same student
    // for this class + date finds time_in already set and changes nothing.
    $res = executeQuery(
        "INSERT INTO attendance (student_id, subject_code, section_id, date, status, time_in, marked_via)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            status     = IF(time_in IS NULL, VALUES(status), status),
            marked_via = IF(time_in IS NULL, VALUES(marked_via), marked_via),
            time_in    = IF(time_in IS NULL, VALUES(time_in), time_in)",
        [$student['student_id'], $subjectCode, $sectionId, $today, $status, $timeIn, $method]
    );
    if (!$res) {
        return ['success' => false, 'error' => 'Could not save the attendance record. Please try again.', 'code' => 'db_error'];
    }
    $duplicate = ($res->rowCount() === 0);

    $rec = getRecord(
        "SELECT status, time_in FROM attendance WHERE student_id = ? AND subject_code = ? AND section_id = ? AND date = ?",
        [$student['student_id'], $subjectCode, $sectionId, $today]
    );
    return [
        'success'      => true,
        'duplicate'    => $duplicate,
        'student_id'   => (int) $student['student_id'],
        'name'         => $name,
        'lrn'          => $student['lrn'],
        'grade_level'  => $sectionInfo['grade_level'],
        'section_name' => $sectionInfo['section_name'],
        'subject_code' => $subjectCode,
        'subject_name' => $chosen['subject_name'],
        'section_id'   => $sectionId,
        'status'       => $rec['status'],
        'time_in'      => $rec['time_in'],
        'time_label'   => attQrFormatTime($rec['time_in']),
        'class_start'  => attQrFormatTime($chosen['schedule_start']),
    ];
}

// ============================================================
// WHOLE-TERM ATTENDANCE REPORT  (Grade Management -> Attendance)
// ============================================================

/**
 * Every student's attendance for ONE class across a whole term: one status per
 * school day plus the same summary the at-risk monitoring uses
 * (gh_summarizeAttendanceRows: Present 1, Late 0.5, Absent 0, Excused set aside).
 *
 * The term runs from its Start Date to its End Date (Manage Assessment Items /
 * Term Dates). A term without dates falls back to school-year start .. today
 * (or the term's Start Date .. today) and says so in 'note'.
 *
 * @return array{term:string,start:string,end:string,dates_set:bool,note:string,dates:array,students:array,totals:array}
 */
function attQrTermReport($subjectCode, $sectionId, $schoolYearId, $term)
{
    require_once __DIR__ . '/grading_helpers.php';
    require_once __DIR__ . '/attendance_helpers.php';

    $window = gh_getTermWindow($schoolYearId, $term);
    $sy = getRecord("SELECT sy_name, start_date, end_date FROM school_years WHERE sy_id = ?", [$schoolYearId]);
    $today = date('Y-m-d');

    $hasStart = !empty($window['start_date']);
    $hasEnd = !empty($window['end_date']);
    $start = $hasStart ? $window['start_date'] : null;
    $end = $hasEnd ? $window['end_date'] : null;

    // A missing date is taken from the neighbouring term when that one is set:
    // start = the day after the previous term ended, end = the day before the next term starts.
    $terms = gh_threeTerms();
    $pos = array_search($term, $terms, true);
    if ($start === null && $pos > 0) {
        $prev = gh_getTermWindow($schoolYearId, $terms[$pos - 1]);
        if (!empty($prev['end_date'])) {
            $start = date('Y-m-d', strtotime($prev['end_date'] . ' +1 day'));
            $hasStart = true;
        }
    }
    if ($end === null && $pos !== false && $pos < count($terms) - 1) {
        $next = gh_getTermWindow($schoolYearId, $terms[$pos + 1]);
        if (!empty($next['start_date'])) {
            $end = date('Y-m-d', strtotime($next['start_date'] . ' -1 day'));
            $hasEnd = true;
        }
    }
    $start = $start ?? ($sy['start_date'] ?? date('Y-01-01'));
    $end = $end ?? $today;

    $note = '';
    if (!$hasStart) {
        $note = 'The Start Date of ' . $term . ' is not set yet, so records since the start of the school year are shown. '
            . 'Set the term dates in Manage Assessment Items to limit this to the term.';
    } elseif (!$hasEnd) {
        $note = 'The End Date of ' . $term . ' is not set yet, so records up to today are shown.';
    }

    if ($start > $end) {                    // e.g. Term 2 starts in the future, its End Date is not set
        $end = $start;
        $note = $term . ' has not started yet (it starts on ' . date('M j, Y', strtotime($start)) . ').';
    }

    $students = attQrSectionStudents($sectionId, $schoolYearId);
    $rows = getRecords(
        "SELECT student_id, date, status, time_in, marked_via FROM attendance
         WHERE subject_code = ? AND section_id = ? AND date >= ? AND date <= ?
         ORDER BY date ASC, student_id ASC",
        [$subjectCode, $sectionId, $start, $end]
    );

    $dates = [];
    $cells = [];
    foreach ($rows as $r) {
        $d = $r['date'];
        $dates[$d] = true;
        $cells[(int) $r['student_id']][$d] = [
            's' => $r['status'],
            't' => attQrFormatTime($r['time_in']),
            'v' => $r['marked_via'],
        ];
    }
    $dates = array_keys($dates);
    sort($dates);

    $letters = ['Present' => 'P', 'Late' => 'L', 'Absent' => 'A', 'Excused' => 'E'];
    $totals = ['Present' => 0, 'Late' => 0, 'Absent' => 0, 'Excused' => 0, 'AtRisk' => 0];
    $out = [];
    foreach ($students as $s) {
        $sid = (int) $s['student_id'];
        $mine = $cells[$sid] ?? [];
        $list = [];
        $row = [];
        foreach ($dates as $d) {
            if (isset($mine[$d])) {
                $list[] = ['status' => $mine[$d]['s']];
                $row[$d] = ['s' => $letters[$mine[$d]['s']] ?? '?', 'status' => $mine[$d]['s'], 't' => $mine[$d]['t'], 'v' => $mine[$d]['v']];
            } else {
                $row[$d] = null;
            }
        }
        $sum = gh_summarizeAttendanceRows($list);
        $totals['Present'] += $sum['present'];
        $totals['Late'] += $sum['late'];
        $totals['Absent'] += $sum['absent'];
        $totals['Excused'] += $sum['excused'];
        if ($sum['at_risk']) {
            $totals['AtRisk']++;
        }
        $out[] = [
            'student_id' => $sid,
            'lrn'        => $s['lrn'],
            'name'       => $s['last_name'] . ', ' . $s['first_name'] . ($s['middle_name'] ? ' ' . attQrInitial($s['middle_name']) . '.' : ''),
            'cells'      => $row,
            'present'    => $sum['present'],
            'late'       => $sum['late'],
            'absent'     => $sum['absent'],
            'excused'    => $sum['excused'],
            'pct'        => $sum['percentage'],
            'at_risk'    => (bool) $sum['at_risk'],
            'reason'     => $sum['reason'],
        ];
    }

    return [
        'term'      => $term,
        'sy_name'   => $sy['sy_name'] ?? '',
        'start'     => $start,
        'end'       => $end,
        'dates_set' => ($hasStart && $hasEnd),
        'note'      => $note,
        'dates'     => $dates,
        'students'  => $out,
        'totals'    => $totals,
    ];
}