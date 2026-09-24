<?php
// modules/attendance/scan.php
// JSON endpoint behind Teacher Portal -> Attendance (modules/attendance/take.php).
// POST + CSRF, Teacher role only, and only for a class the teacher teaches.
//
//   action=state  today's session + roster + counts (the live poll)
//   action=start  start / resume IN scanning for the class
//   action=scan   record one student IN  (qr = scanned QR text, lrn = typed LRN)
//   action=end    end the session; students who never scanned become Absent
//
// All the rules (signature check, one IN per student per class per day,
// Present/Late against the scheduled start) live in
// includes/attendance_qr_helpers.php -- nothing the browser sends is trusted.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php'; // isValidTerm(), getDefaultTerm(), gh_getTermWindow()
require_once __DIR__ . '/../../includes/attendance_qr_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function attScanJson($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isLoggedIn() || !hasRole('Teacher')) {
    attScanJson(['success' => false, 'error' => 'Unauthorized.'], 403);
}
if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
    attScanJson(['success' => false, 'error' => 'Your session expired. Please refresh the page.'], 403);
}

$teacherId = (int) ($_SESSION['user_id'] ?? 0);
$activeSy = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
if (!$activeSy) {
    attScanJson(['success' => false, 'error' => 'No active school year.'], 422);
}
$syId = (int) $activeSy['sy_id'];

$subjectCode = trim((string) ($_POST['subject_code'] ?? ''));
$sectionId = (int) ($_POST['section_id'] ?? 0);
if ($subjectCode === '' || $sectionId <= 0 || !attQrTeacherOwnsClass($teacherId, $subjectCode, $sectionId, $syId)) {
    attScanJson(['success' => false, 'error' => 'You do not teach this class.'], 403);
}
if (!attQrEnsureSchema()) {
    attScanJson(['success' => false, 'error' => 'Attendance database update could not be applied. Please contact the administrator.'], 500);
}

$today = date('Y-m-d');

/**
 * Session + roster in the shape the page uses, for whichever class
 * $subjectCode/$sectionId names -- not necessarily the class that was on
 * screen when the request was sent (see the 'scan' case below, where a
 * scan can land on a different one of the teacher's classes).
 */
function attScanSnapshot($subjectCode, $sectionId, $syId, $today)
{
    $session = attQrGetSession($subjectCode, $sectionId, $today);
    $term = (string) ($_POST['term'] ?? '');
    if (!isValidTerm($term)) {
        $term = getDefaultTerm();
    }
    $roster = attQrRoster($subjectCode, $sectionId, $syId, $today, attQrSummaryStart($syId, $term));
    $subjectRow = getRecord("SELECT subject_name FROM subjects WHERE subject_code = ?", [$subjectCode]);
    $sectionRow = getRecord("SELECT section_name, grade_level FROM sections WHERE section_id = ?", [$sectionId]);
    return [
        'subject_code' => $subjectCode,
        'section_id'   => $sectionId,
        'subject_name' => $subjectRow['subject_name'] ?? '',
        'section_name' => $sectionRow['section_name'] ?? '',
        'grade_level'  => $sectionRow['grade_level'] ?? '',
        'session' => $session ? [
            'status'       => $session['status'],
            'class_start'  => attQrFormatTime($session['schedule_start']),
            'start_source' => $session['start_source'],
        ] : null,
        'students'    => $roster['students'],
        'counts'      => $roster['counts'],
        'server_time' => date('g:i:s A'),
    ];
}

switch ((string) ($_POST['action'] ?? '')) {
    case 'state':
        attScanJson(['success' => true] + attScanSnapshot($subjectCode, $sectionId, $syId, $today));
        break;

    case 'start':
        $r = attQrStartSession($teacherId, $subjectCode, $sectionId, $syId);
        if (empty($r['success'])) {
            attScanJson($r, 422);
        }
        logActivity($teacherId, 'attendance', 'Started QR attendance', "Started IN scanning for $subjectCode / section $sectionId on $today");
        attScanJson(['success' => true] + attScanSnapshot($subjectCode, $sectionId, $syId, $today));
        break;

    case 'scan':
        $method = ($_POST['method'] ?? 'qr') === 'lrn' ? 'lrn' : 'qr';
        $r = attQrRecordScan($teacherId, $subjectCode, $sectionId, $syId, (string) ($_POST['code'] ?? ''), $method);
        // A refused scan (bad QR, other class...) is a normal outcome, not a server error.
        // On success, the snapshot is for the class the scan actually landed
        // in (attQrRecordScan can route it to a different one of the
        // teacher's classes than what was on screen) so the page can switch
        // to showing that class's roster and counts.
        $snapSubject = $r['success'] ? $r['subject_code'] : $subjectCode;
        $snapSection = $r['success'] ? $r['section_id'] : $sectionId;
        attScanJson($r + attScanSnapshot($snapSubject, $snapSection, $syId, $today));
        break;

    case 'end':
        $r = attQrEndSession($teacherId, $subjectCode, $sectionId, $syId);
        if (empty($r['success'])) {
            attScanJson($r, 422);
        }
        logActivity($teacherId, 'attendance', 'Ended QR attendance', "Ended attendance session for $subjectCode / section $sectionId on $today ({$r['absent_marked']} marked Absent)");
        attScanJson($r + attScanSnapshot($subjectCode, $sectionId, $syId, $today));
        break;

    default:
        attScanJson(['success' => false, 'error' => 'Unknown request.'], 400);
}