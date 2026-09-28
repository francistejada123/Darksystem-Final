<?php
// ============================================================
// >>> THIS FILE GOES IN:  modules/attendance/take.php  <<<
// ============================================================
// modules/attendance/take.php
// Attendance (Teacher Portal sidebar) — QR scanner for the teacher's own class.
//
// The teacher picks a class, starts IN scanning, and each student scans their
// personal QR code (see student_qr.php). Every scan is saved by
// modules/attendance/scan.php with the exact time-in and compared with the
// class's scheduled start: on/before start = Present, after start = Late.
// A student can only be scanned IN once per class per day, and ending the
// session marks everyone who never scanned as Absent.
//
// This is a SEPARATE feature from modules/attendance/index.php and
// admin_view.php, which are the Teacher's own personal Time In/Time Out
// clock (backed by `teacher_attendance`). This page records each STUDENT's
// attendance (backed by the existing `attendance` table), so Attendance
// History, AI Analytics and the at-risk attendance monitoring keep working.
//
// Never touches grades. Written Works / Performance Tasks / Examinations
// (see includes/grading_helpers.php) remain the only official grading
// components — attendance only ever feeds AI Analytics as an ADDITIONAL
// risk indicator (see includes/attendance_helpers.php).

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php'; // getActiveTerms() -- Term picker
require_once __DIR__ . '/../../includes/attendance_helpers.php';
require_once __DIR__ . '/../../includes/attendance_qr_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Teacher'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Attendance';
$current_page = 'student_attendance';
$base_url = BASE_URL;

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
if (!$active_sy) {
    include '../../includes/header.php';
    echo '<div class="alert alert-warning mt-4">⚠️ No active school year found.</div>';
    include '../../includes/footer.php';
    exit;
}

$teacher_id = $_SESSION['user_id'];
$section_id = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;
$subject_code = isset($_GET['subject_code']) ? sanitize($_GET['subject_code']) : '';
$term = isset($_GET['term']) ? sanitize($_GET['term']) : getDefaultTerm();
if (!isValidTerm($term)) {
    $term = getDefaultTerm();
}
$terms = getActiveTerms();

$today = date('Y-m-d');
$att_date = isset($_GET['att_date']) ? sanitize($_GET['att_date']) : $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $att_date) || $att_date > $today) {
    $att_date = $today;
}
$is_today = ($att_date === $today);

// Every subject/section THIS teacher actually teaches — same source the
// Term/Subject selector on Manage Assessment Items and Encode Grades uses.
// Ordered by grade level (Grade 7 first, up through Grade 12/SHS) rather
// than alphabetically by section name, so e.g. "10-C" doesn't sort ahead of
// "7-B" and become the page's default class.
$my_assignments = getRecords("
    SELECT ts.subject_code, sub.subject_name, ts.section_id, sec.section_name, sec.grade_level
    FROM teacher_subjects ts
    JOIN subjects sub ON sub.subject_code = ts.subject_code
    JOIN sections sec ON sec.section_id = ts.section_id
    WHERE ts.teacher_id = ? AND ts.school_year_id = ?
    ORDER BY CAST(SUBSTRING_INDEX(sec.grade_level, ' ', -1) AS UNSIGNED), sec.section_name, sub.subject_name
", [$teacher_id, $active_sy['sy_id']]);

if (($section_id === 0 || $subject_code === '') && !empty($my_assignments)) {
    $section_id = (int) $my_assignments[0]['section_id'];
    $subject_code = $my_assignments[0]['subject_code'];
}

$section_info = getRecord("SELECT * FROM sections WHERE section_id = ?", [$section_id]);
$subject_info = getRecord("
    SELECT sub.*, ts.teacher_id FROM subjects sub
    INNER JOIN teacher_subjects ts ON ts.subject_code = sub.subject_code
    WHERE sub.subject_code = ? AND ts.section_id = ? AND ts.teacher_id = ? AND ts.school_year_id = ?
", [$subject_code, $section_id, $teacher_id, $active_sy['sy_id']]);

if (!$section_info || !$subject_info) {
    include '../../includes/header.php';
    echo '<div class="alert alert-warning mt-4">⚠️ You have no subject/section assigned yet. Ask an Administrator to assign your classes first.</div>';
    include '../../includes/footer.php';
    exit;
}

$grade = $section_info['grade_level'] ?? '';
$csrf_token = generateCSRFToken();

$schema_ok = attQrEnsureSchema();
$can_scan = $schema_ok && $is_today && hasRole('Teacher');

$session = $schema_ok ? attQrGetSession($subject_code, $section_id, $today) : null;

// What "on time" means for this class today. Once a session is open the start
// time is frozen on the session; before that, show what it WILL be.
$class_start = $session
    ? ['start' => $session['schedule_start'], 'source' => $session['start_source']]
    : attQrResolveClassStart($teacher_id, $subject_code, $section_id, $active_sy['sy_id']);
$class_start_label = attQrFormatTime($class_start['start']);
$class_start_source = ($class_start['source'] === 'schedule')
    ? 'from the class schedule'
    : 'school program start — no class schedule is set for today';

$summary_start = attQrSummaryStart($active_sy['sy_id'], $term);
$roster = $schema_ok
    ? attQrRoster($subject_code, $section_id, $active_sy['sy_id'], $att_date, $summary_start)
    : ['students' => [], 'counts' => ['Total' => 0, 'Present' => 0, 'Late' => 0, 'Absent' => 0, 'Excused' => 0, 'Waiting' => 0]];

$init = [
    'endpoint'    => BASE_URL . 'modules/attendance/scan.php',
    'csrf'        => $csrf_token,
    'subject'     => $subject_code,
    'section'     => $section_id,
    'term'        => $term,
    'isToday'     => $is_today,
    'canScan'     => $can_scan,
    'libUrl'      => BASE_URL . 'static/js/vendor/jsQR.min.js',
    'session'     => $session ? ['status' => $session['status'], 'class_start' => $class_start_label, 'start_source' => $session['start_source']] : null,
    'classStart'  => $class_start_label,
    'students'    => $roster['students'],
    'counts'      => $roster['counts'],
];

include '../../includes/header.php';
?>

<style>
    .attendance-page { --att-navy:#14213d; --att-blue:#1f73e8; --att-soft:#eef5ff; --att-border:#dce6f2; --att-muted:#64748b; max-width:1480px; margin:0 auto; padding-bottom:40px; }
    .att-hero { position:relative; overflow:hidden; display:flex; align-items:center; justify-content:space-between; gap:24px; min-height:120px; margin:18px 0 16px; padding:22px 30px; border:1px solid #dce8f6; border-radius:18px; background:linear-gradient(110deg,#f4f9ff 0%,#fff 56%,#edf5ff 100%); box-shadow:0 8px 24px rgba(35,75,125,.07); }
    .att-hero:after { content:""; position:absolute; width:310px; height:310px; right:-80px; top:-160px; border-radius:50%; border:28px solid rgba(56,130,246,.08); box-shadow:0 0 0 28px rgba(56,130,246,.05); }
    .att-hero-copy { position:relative; z-index:1; display:flex; align-items:center; gap:18px; }
    .att-hero-icon { display:grid; place-items:center; width:64px; height:64px; border-radius:17px; background:#e4f0ff; color:var(--att-blue); font-size:30px; box-shadow:inset 0 0 0 1px #d4e6ff; }
    .att-hero h1 { margin:0 0 5px; color:var(--att-navy); font-size:27px; font-weight:750; letter-spacing:-.03em; }
    .att-hero p { margin:0; max-width:680px; color:#6a7d99; font-size:14.5px; line-height:1.55; }
    .att-toolbar-card, .att-roster-card, .att-scan-card { border:1px solid var(--att-border); border-radius:15px; background:#fff; box-shadow:0 7px 22px rgba(35,75,125,.06); }
    .att-toolbar-card { display:flex; align-items:end; gap:18px; flex-wrap:wrap; padding:16px 18px; margin-bottom:16px; }
    .att-field { min-width:170px; flex:1; }
    .att-field label { display:block; margin:0 0 6px 2px; color:#526783; font-size:12.5px; font-weight:700; }
    .att-field .form-select, .att-field .form-control { min-height:43px; border:1px solid #d9e4f1; border-radius:9px; color:#294263; font-size:14.5px; box-shadow:none; }
    .att-field .form-select:focus, .att-field .form-control:focus { border-color:#6ea9ee; box-shadow:0 0 0 3px rgba(31,115,232,.11); }
    .att-toolbar-actions { display:flex; gap:9px; margin-left:auto; }
    .att-toolbar-actions .btn { min-height:43px; border-radius:9px; font-size:13.5px; font-weight:650; white-space:nowrap; }
    .att-btn-history { border:1px solid #d6e2ef; color:#47617f; background:#fff; }

    .att-layout { display:grid; grid-template-columns:400px minmax(0,1fr); gap:16px; align-items:start; }
    .att-layout.no-scanner { grid-template-columns:minmax(0,1fr); }

    /* ---- scanner card ---- */
    .att-scan-card { padding:18px; position:sticky; top:12px; }
    .att-scan-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:6px; }
    .att-scan-head h2 { margin:0; color:var(--att-navy); font-size:17px; font-weight:750; }
    .att-pill { display:inline-flex; align-items:center; gap:6px; padding:4px 11px; border-radius:20px; font-size:12.5px; font-weight:700; background:#eef2f7; color:#5d6f86; }
    .att-pill i { font-size:8px; }
    .att-pill.live { background:#e7f8ef; color:#138955; }.att-pill.live i { animation:attPulse 1.2s infinite; }
    .att-pill.closed { background:#fff0f1; color:#d44352; }
    @keyframes attPulse { 0%,100% { opacity:1; } 50% { opacity:.25; } }
    .att-start-note { margin:0 0 14px; padding:9px 12px; border-radius:10px; background:#f4f8fd; color:#5a6f8a; font-size:13.5px; line-height:1.5; }
    .att-start-note strong { color:var(--att-navy); }
    .att-ctl { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
    .att-ctl .btn { flex:1; min-height:42px; border-radius:10px; font-size:14.5px; font-weight:700; white-space:nowrap; }
    .att-btn-start { background:#1f73e8; border:0; color:#fff; box-shadow:0 4px 10px rgba(31,115,232,.22); }
    .att-btn-start:hover { background:#1a63c9; color:#fff; }
    .att-btn-end { background:#fff; border:1px solid #f1b9bf; color:#d44352; }
    .att-btn-end:hover { background:#fff3f4; color:#c0303f; }
    .att-video { position:relative; overflow:hidden; aspect-ratio:1/1; border-radius:14px; background:#0f172a; }
    .att-video video { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
    .att-frame { position:absolute; inset:16%; border:3px solid rgba(255,255,255,.85); border-radius:16px; box-shadow:0 0 0 999px rgba(15,23,42,.35); pointer-events:none; opacity:0; transition:opacity .2s; }
    .att-video.on .att-frame { opacity:1; }
    .att-cam-msg { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:10px; padding:24px; text-align:center; color:#c7d3e6; font-size:14.5px; line-height:1.5; }
    .att-cam-msg i { font-size:34px; color:#5f7ea8; }
    .att-video.on .att-cam-msg { display:none; }
    .att-manual { display:flex; gap:8px; margin-top:12px; }
    .att-manual input { flex:1; min-width:0; min-height:40px; border:1px solid #d9e4f1; border-radius:9px; padding:0 12px; font-size:14.5px; color:#294263; }
    .att-manual input:focus { outline:none; border-color:#6ea9ee; box-shadow:0 0 0 3px rgba(31,115,232,.11); }
    .att-manual .btn { min-height:40px; border-radius:9px; font-size:13.5px; font-weight:700; border:1px solid #d6e2ef; background:#fff; color:#47617f; white-space:nowrap; }
    .att-manual-hint { margin:5px 2px 0; color:#8394aa; font-size:12.5px; }
    .att-result { margin:0 0 14px; padding:16px 17px; border-radius:12px; border:1px solid transparent; }
    .att-result.show { animation:attFadeUp .25s ease both; }
    .att-result .r-title { font-size:20px; font-weight:800; letter-spacing:-.01em; line-height:1.25; }
    .att-result .r-name { margin:5px 0 3px; font-size:20px; font-weight:750; color:#14213d; }
    .att-result .r-meta { font-size:14px; color:#526783; }
    .att-result.waiting { background:#f4f6f9; border-color:#e3e8ef; }.att-result.waiting .r-title { color:#5b6b82; }
    .att-result.ok { background:#ecfbf3; border-color:#bdebd4; }.att-result.ok .r-title { color:#138955; }
    .att-result.late { background:#fff8e9; border-color:#f5dfaa; }.att-result.late .r-title { color:#b97a06; }
    .att-result.dup { background:#eef3f9; border-color:#cddbea; }.att-result.dup .r-title { color:#496b94; }
    .att-result.err { background:#fff0f1; border-color:#ffd0d4; }.att-result.err .r-title { color:#d44352; }

    /* ---- roster card ---- */
    .att-session-card { display:flex; align-items:center; gap:18px; padding:18px 22px; border-bottom:1px solid #e5edf6; flex-wrap:wrap; }
    .att-session-icon { display:grid; place-items:center; width:54px; height:54px; border-radius:14px; background:#e4f0ff; color:#2778e9; font-size:24px; }
    .att-session-copy { flex:1; min-width:200px; }
    .att-session-copy h2 { margin:0 0 5px; color:var(--att-navy); font-size:24px; font-weight:750; }
    .att-session-copy p { margin:0; color:#71839a; font-size:15px; }
    .att-stats { display:flex; gap:9px; flex-wrap:wrap; }
    .att-stat { min-width:92px; padding:9px 13px; border-radius:12px; background:#f6f9fd; transition:transform .25s; }
    .att-stat small { display:block; color:#71839a; font-size:13.5px; font-weight:650; }
    .att-stat strong { display:block; margin-top:3px; color:#3177df; font-size:26px; line-height:1; }
    .att-stat.bump { animation:attBump .35s ease; }
    @keyframes attBump { 50% { transform:scale(1.08); } }
    .att-stat.present { background:#ecfbf3; }.att-stat.present strong { color:#15945b; }
    .att-stat.late { background:#fff8e9; }.att-stat.late strong { color:#d28a0c; }
    .att-stat.absent { background:#fff0f1; }.att-stat.absent strong { color:#e14858; }
    .att-stat.excused { background:#f0f5fb; }.att-stat.excused strong { color:#536b8a; }
    .att-stat.waiting { background:#f3f4f7; }.att-stat.waiting strong { color:#6b7a90; }
    .att-roster-tools { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 22px; border-bottom:1px solid #e5edf6; }
    .att-search { position:relative; width:min(410px,100%); }
    .att-search i { position:absolute; left:13px; top:13px; color:#7c91ad; font-size:15px; }
    .att-search input { width:100%; min-height:39px; padding:8px 12px 8px 36px; border:1px solid #d9e4f1; border-radius:8px; color:#294263; font-size:13.5px; outline:none; }
    .att-search input:focus { border-color:#6ea9ee; box-shadow:0 0 0 3px rgba(31,115,232,.1); }
    .att-live { color:#8394aa; font-size:12.5px; }
    .att-live i { color:#15945b; }
    .att-table-head { display:grid; grid-template-columns:minmax(200px,1.3fr) 110px 150px 110px; gap:16px; padding:10px 22px; color:#7386a0; background:#f7faff; font-size:12px; font-weight:750; text-transform:uppercase; letter-spacing:.04em; }
    .att-row { display:grid; grid-template-columns:minmax(200px,1.3fr) 110px 150px 110px; align-items:center; gap:16px; padding:12px 22px; border-bottom:1px solid #edf2f7; transition:background .18s; }
    .att-row:last-child { border-bottom:0; }.att-row:hover { background:#fbfdff; }
    .att-row.flash { animation:attRowFlash 1.6s ease; }
    @keyframes attRowFlash { 0% { background:#d9f5e6; } 100% { background:transparent; } }
    .att-student { display:flex; align-items:center; gap:11px; min-width:0; }
    .att-avatar { display:grid; place-items:center; flex:0 0 34px; width:34px; height:34px; border-radius:50%; color:#fff; font-size:12.5px; font-weight:750; background:linear-gradient(135deg,#4d7df4,#8f4df1); }
    .att-name { overflow:hidden; color:#294263; font-size:15.5px; font-weight:700; text-overflow:ellipsis; white-space:nowrap; }
    .att-lrn { margin-top:3px; color:#8394aa; font-family:monospace; font-size:14.5px; font-weight:600; }
    .att-time { color:#294263; font-size:14.5px; font-weight:650; font-variant-numeric:tabular-nums; }
    .att-time.none { color:#a5b1c2; font-weight:500; }
    .att-badge { display:inline-flex; align-items:center; gap:6px; padding:5px 11px; border-radius:20px; font-size:13.5px; font-weight:700; }
    .att-badge.present { background:#e9faf1; color:#138955; }
    .att-badge.late { background:#fff7e6; color:#bd7b07; }
    .att-badge.absent { background:#ffedef; color:#d44352; }
    .att-badge.excused { background:#edf4fc; color:#496b94; }
    .att-badge.waiting { background:#f1f3f7; color:#7b889b; }
    .att-pct { color:#71839a; font-size:12.5px; text-align:right; }.att-pct strong { color:#15945b; }.att-pct strong.risk { color:#d44352; }
    .att-empty { padding:44px 20px; color:#71839a; text-align:center; }
    .att-past-note { margin:0 0 16px; padding:11px 16px; border-radius:12px; background:#eef5ff; border:1px solid #d7e6fb; color:#3a5f92; font-size:14px; }
    .att-hero, .att-toolbar-card, .att-roster-card, .att-scan-card { animation:attFadeUp .42s ease both; }
    @keyframes attFadeUp { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
    @media (prefers-reduced-motion: reduce) { .att-hero, .att-toolbar-card, .att-roster-card, .att-scan-card, .att-row.flash, .att-stat.bump, .att-pill.live i { animation:none; } }
    @media (max-width:1100px) { .att-layout { grid-template-columns:minmax(0,1fr); }.att-scan-card { position:static; } }
    @media (max-width:950px) { .att-table-head { display:none; }.att-row { grid-template-columns:1fr auto; gap:8px 12px; }.att-row .att-time, .att-row .att-pct { text-align:left; }.att-toolbar-actions { width:100%; margin-left:0; }.att-toolbar-actions .btn { flex:1; } }
    @media (max-width:600px) { .att-hero { padding:18px; }.att-hero-icon { width:50px; height:50px; font-size:24px; }.att-hero h1 { font-size:22px; }.att-toolbar-card { padding:13px; }.att-field { min-width:100%; }.att-roster-tools { padding:13px; }.att-search { width:100%; }.att-session-card { padding:15px; }.att-row { padding:12px 15px; } }
</style>

<div class="attendance-page">
<div class="att-hero">
    <div class="att-hero-copy">
        <div class="att-hero-icon"><i class="fas fa-qrcode"></i></div>
        <div>
            <h1>Attendance</h1>
            <p>Pick your class, start IN scanning, and let students scan their QR code. Time-in is recorded automatically: on or before the class start is <strong>Present</strong>, after it is <strong>Late</strong>.</p>
        </div>
    </div>
</div>

<?php if (!$schema_ok): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>The attendance database update could not be applied, so QR attendance is unavailable. Please contact the administrator.</div>
<?php endif; ?>

<div class="att-toolbar-card">
        <form method="GET" class="d-flex align-items-end flex-wrap gap-2">
            <div class="att-field">
                <label>Class</label>
                <select name="class_pick" class="form-select" onchange="var v=this.value.split('|'); location.href='?section_id='+v[0]+'&subject_code='+encodeURIComponent(v[1])+'&term=<?php echo urlencode($term); ?>&att_date=<?php echo urlencode($att_date); ?>';">
                    <?php $current_grade = null; foreach ($my_assignments as $a): ?>
                        <?php if ($a['grade_level'] !== $current_grade): ?>
                            <?php if ($current_grade !== null): ?></optgroup><?php endif; ?>
                            <optgroup label="<?php echo htmlspecialchars($a['grade_level']); ?>">
                            <?php $current_grade = $a['grade_level']; ?>
                        <?php endif; ?>
                        <option value="<?php echo $a['section_id']; ?>|<?php echo htmlspecialchars($a['subject_code']); ?>"
                            <?php echo ((int) $a['section_id'] === $section_id && $a['subject_code'] === $subject_code) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($a['section_name'] . ' — ' . $a['subject_name']); ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if ($current_grade !== null): ?></optgroup><?php endif; ?>
                </select>
            </div>
            <div class="att-field">
                <label>Term</label>
                <select name="term" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($terms as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo $term == $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="att-field">
                <label>Date</label>
                <input type="date" name="att_date" value="<?php echo htmlspecialchars($att_date); ?>" max="<?php echo $today; ?>" class="form-control" onchange="this.form.submit()">
            </div>
            <input type="hidden" name="section_id" value="<?php echo $section_id; ?>">
            <input type="hidden" name="subject_code" value="<?php echo htmlspecialchars($subject_code); ?>">
        </form>
    <div class="att-toolbar-actions">
        <a href="history.php?section_id=<?php echo $section_id; ?>&subject_code=<?php echo urlencode($subject_code); ?>&term=<?php echo urlencode($term); ?>&grade=<?php echo urlencode($grade); ?>" class="btn att-btn-history"><i class="fas fa-clock-rotate-left me-1"></i> View History</a>
    </div>
</div>

<?php if (!$is_today): ?>
    <div class="att-past-note"><i class="fas fa-circle-info me-1"></i> Showing the saved attendance for <strong><?php echo date('M j, Y', strtotime($att_date)); ?></strong>. Scanning is only available for today — pick today's date to scan.</div>
<?php endif; ?>

<div class="att-layout <?php echo $can_scan ? '' : 'no-scanner'; ?>">

<?php if ($can_scan): ?>
    <!-- ================= QR SCANNER ================= -->
    <section class="att-scan-card" id="scanCard" aria-label="QR scanner">
        <div class="att-scan-head">
            <h2><i class="fas fa-camera me-1" style="color:#2778e9;"></i> IN Scanner</h2>
            <span class="att-pill" id="sessionPill"><i class="fas fa-circle"></i> <span>Not started</span></span>
        </div>
        <p class="att-start-note" id="startNote">
            Class start time: <strong><?php echo htmlspecialchars($class_start_label); ?></strong> (<?php echo htmlspecialchars($class_start_source); ?>).
            Scans after that time are marked <strong>Late</strong>.
        </p>

        <div class="att-result waiting" id="scanResult" role="status" aria-live="polite">
            <div class="r-title">Waiting for Next Scan</div>
            <div class="r-name"></div>
            <div class="r-meta">Scan a student's QR code, or type their LRN below.</div>
        </div>

        <div class="att-ctl">
            <button type="button" class="btn att-btn-start" id="btnStart"><i class="fas fa-play me-1"></i> <span>Start IN Scanning</span></button>
            <button type="button" class="btn att-btn-end" id="btnEnd" hidden><i class="fas fa-stop me-1"></i> End Session</button>
        </div>

        <div class="att-video" id="videoBox">
            <video id="scanVideo" playsinline muted></video>
            <div class="att-frame"></div>
            <div class="att-cam-msg" id="camMsg"><i class="fas fa-qrcode"></i><span id="camMsgText">Press <strong>Start IN Scanning</strong> to open the camera. Students then hold their QR code up to it.</span></div>
        </div>

        <form class="att-manual" id="manualForm" autocomplete="off">
            <input type="text" id="manualLrn" inputmode="numeric" placeholder="No QR? Type or scan LRN" aria-label="Student LRN" maxlength="20">
            <button type="submit" class="btn" id="manualBtn"><i class="fas fa-arrow-right-to-bracket me-1"></i> Record IN</button>
        </form>
        <p class="att-manual-hint">Also works with a USB barcode/QR scanner — click the box and scan.</p>
    </section>
<?php endif; ?>

    <!-- ================= LIVE ROSTER ================= -->
    <section class="att-roster-card" aria-label="Attendance list">
        <div class="att-session-card">
            <div class="att-session-icon"><i class="fas fa-book-open"></i></div>
            <div class="att-session-copy">
                <h2><?php echo htmlspecialchars($section_info['section_name']); ?> — <?php echo htmlspecialchars($subject_info['subject_name']); ?></h2>
                <p><?php echo htmlspecialchars($term); ?> &nbsp;•&nbsp; <?php echo date('M j, Y', strtotime($att_date)); ?> &nbsp;•&nbsp; Attendance session</p>
            </div>
            <div class="att-stats" aria-label="Attendance summary">
                <div class="att-stat"><small>Total Students</small><strong data-att-count="Total">0</strong></div>
                <div class="att-stat present"><small>Present</small><strong data-att-count="Present">0</strong></div>
                <div class="att-stat late"><small>Late</small><strong data-att-count="Late">0</strong></div>
                <div class="att-stat absent"><small>Absent</small><strong data-att-count="Absent">0</strong></div>
                <div class="att-stat excused"><small>Excused</small><strong data-att-count="Excused">0</strong></div>
                <div class="att-stat waiting"><small>Not Yet Scanned</small><strong data-att-count="Waiting">0</strong></div>
            </div>
        </div>
        <div class="att-roster-tools">
            <div class="att-search"><i class="fas fa-search"></i><input type="search" id="attendanceSearch" placeholder="Search student name or LRN..." aria-label="Search students"></div>
            <?php if ($can_scan): ?><span class="att-live" id="liveNote"><i class="fas fa-circle" style="font-size:7px;"></i> Updates live</span><?php endif; ?>
        </div>
        <div class="att-table-head"><span>Student</span><span>Time In</span><span>Status</span><span style="text-align:right;">This Term</span></div>
        <div id="attendanceRoster"></div>
    </section>
</div>
</div>

<!-- End-session confirmation -->
<div class="modal fade" id="endModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-stop-circle text-danger me-2"></i>End attendance session?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="endModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep Scanning</button>
                <button type="button" class="btn btn-danger" id="endConfirm">End Session</button>
            </div>
        </div>
    </div>
</div>

<script>
window.ATT_INIT = <?php echo json_encode($init, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
</script>
<script>
(function () {
    var cfg = window.ATT_INIT;
    var roster = document.getElementById('attendanceRoster');
    var students = cfg.students || [];
    var session = cfg.session;              // {status, class_start, start_source} | null
    var prevStatus = {};                    // student_id -> status, to flash rows that changed
    var query = '';

    function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; }

    // ------------------------------------------------ rendering
    var statusText = { Present: 'Present', Late: 'Late', Absent: 'Absent', Excused: 'Excused', Waiting: 'Not yet scanned' };

    function renderCounts(counts) {
        Object.keys(counts).forEach(function (k) {
            var el = document.querySelector('[data-att-count="' + k + '"]');
            if (el && el.textContent !== String(counts[k])) {
                el.textContent = counts[k];
                var tile = el.closest('.att-stat');
                if (tile) { tile.classList.remove('bump'); void tile.offsetWidth; tile.classList.add('bump'); }
            }
        });
    }

    function renderRoster(flashIds) {
        if (!students.length) {
            roster.innerHTML = '<div class="att-empty"><i class="fas fa-user-graduate mb-2" style="font-size:25px;"></i><br>No approved, active students enrolled in this section yet.</div>';
            return;
        }
        var html = '';
        students.forEach(function (s) {
            var hay = (s.name + ' ' + s.lrn).toLowerCase();
            var hidden = query && hay.indexOf(query) === -1 ? ' hidden' : '';
            var flash = flashIds && flashIds.indexOf(s.student_id) !== -1 ? ' flash' : '';
            var time = s.time_label ? '<span class="att-time">' + esc(s.time_label) + '</span>'
                : '<span class="att-time none">—</span>';
            var pct = s.pct === null || s.pct === undefined
                ? '<span class="text-muted">No records yet</span>'
                : '<strong class="' + (s.at_risk ? 'risk' : '') + '">' + esc(s.pct) + '%</strong>';
            var via = s.via === 'lrn' ? ' title="Recorded by typed LRN"' : (s.via === 'session_close' ? ' title="Marked Absent when the session ended"' : '');
            html += '<div class="att-row' + flash + '" data-id="' + s.student_id + '"' + hidden + '>'
                + '<div class="att-student"><div class="att-avatar">' + esc(s.initials) + '</div><div><div class="att-name">' + esc(s.name) + '</div><div class="att-lrn">' + esc(s.lrn) + '</div></div></div>'
                + '<div>' + time + '</div>'
                + '<div><span class="att-badge ' + s.status.toLowerCase() + '"' + via + '>' + esc(s.status === 'Waiting' && !cfg.isToday ? 'No record' : (statusText[s.status] || s.status)) + '</span></div>'
                + '<div class="att-pct">' + pct + '</div>'
                + '</div>';
        });
        roster.innerHTML = html;
    }

    // When a scan lands on a different one of the teacher's classes than the
    // one on screen (see attQrRecordScan()), switch the whole panel -- title,
    // Class picker, roster, counts -- to that class, so what's live on screen
    // always matches where the attendance actually went.
    function switchDisplayedClass(data) {
        cfg.subject = data.subject_code;
        cfg.section = data.section_id;
        var titleEl = document.querySelector('.att-session-copy h2');
        if (titleEl) titleEl.textContent = (data.section_name || '') + ' — ' + (data.subject_name || '');
        var picker = document.querySelector('select[name="class_pick"]');
        if (picker) picker.value = data.section_id + '|' + data.subject_code;
    }

    // Merge a snapshot from scan.php; flash every row whose status changed.
    // flashId: when the snapshot just switched to a different class, the
    // usual before/after status diff doesn't apply (it's a fresh roster) --
    // flash just the student who was scanned instead.
    function applySnapshot(data, flashId) {
        if (!data || !data.students) return;
        var switched = data.subject_code && (data.subject_code !== cfg.subject || String(data.section_id) !== String(cfg.section));
        var changed = [];
        if (switched) {
            switchDisplayedClass(data);
            if (flashId) changed = [flashId];
        } else {
            data.students.forEach(function (s) {
                if (prevStatus[s.student_id] !== undefined && prevStatus[s.student_id] !== s.status) changed.push(s.student_id);
            });
        }
        data.students.forEach(function (s) { prevStatus[s.student_id] = s.status; });
        students = data.students;
        session = data.session;
        renderCounts(data.counts);
        renderRoster(changed);
        renderSession();
    }

    if (!cfg.isToday) {
        var waitTile = document.querySelector('[data-att-count="Waiting"]');
        if (waitTile) waitTile.previousElementSibling.textContent = 'No Record';
    }
    students.forEach(function (s) { prevStatus[s.student_id] = s.status; });
    renderCounts(cfg.counts);
    renderRoster([]);

    var search = document.getElementById('attendanceSearch');
    if (search) search.addEventListener('input', function () {
        query = this.value.trim().toLowerCase();
        roster.querySelectorAll('.att-row').forEach(function (row) {
            var s = students.filter(function (x) { return String(x.student_id) === row.dataset.id; })[0];
            row.hidden = !!query && (!s || (s.name + ' ' + s.lrn).toLowerCase().indexOf(query) === -1);
        });
    });

    if (!cfg.canScan) return;   // past date / read-only view: nothing else to wire up

    // ------------------------------------------------ scanner
    var btnStart = document.getElementById('btnStart');
    var btnEnd = document.getElementById('btnEnd');
    var pill = document.getElementById('sessionPill');
    var videoBox = document.getElementById('videoBox');
    var video = document.getElementById('scanVideo');
    var camMsgText = document.getElementById('camMsgText');
    var resultBox = document.getElementById('scanResult');
    var startNote = document.getElementById('startNote');
    var manualForm = document.getElementById('manualForm');
    var manualLrn = document.getElementById('manualLrn');

    var stream = null, jsQRReady = false, jsQRLoading = null;
    var canvas = document.createElement('canvas');
    var ctx = canvas.getContext('2d', { willReadFrequently: true });
    var scanning = false, inFlight = false, lastCode = '', lastAt = 0, audioCtx = null, resultTimer = null;

    function isOpen() { return session && session.status === 'Open'; }
    function cameraOn() { return !!stream; }

    function renderSession() {
        var label = 'Not started', cls = 'att-pill';
        if (session && session.status === 'Open') { label = cameraOn() ? 'Scanning IN' : 'Session open'; cls += ' live'; }
        else if (session && session.status === 'Closed') { label = 'Session ended'; cls += ' closed'; }
        pill.className = cls;
        pill.querySelector('span').textContent = label;

        var startLabel = 'Start IN Scanning', icon = 'fa-play';
        if (session && session.status === 'Open') { startLabel = cameraOn() ? 'Camera on' : 'Turn On Camera'; icon = 'fa-video'; }
        else if (session && session.status === 'Closed') { startLabel = 'Resume Scanning'; }
        btnStart.querySelector('span').textContent = startLabel;
        btnStart.querySelector('i').className = 'fas ' + icon + ' me-1';
        btnStart.disabled = !!(session && session.status === 'Open' && cameraOn());
        btnEnd.hidden = !(session && session.status === 'Open');

        var start = session ? session.class_start : cfg.classStart;
        var src = session ? session.start_source : null;
        if (session && src) {
            startNote.innerHTML = 'Class start time: <strong>' + esc(start) + '</strong> (' + (src === 'schedule' ? 'from the class schedule' : 'school program start — no class schedule set for today')
                + '). Scans after that time are marked <strong>Late</strong>.';
        }
    }

    function setCamMsg(html) { camMsgText.innerHTML = html; videoBox.classList.remove('on'); }

    function post(action, extra) {
        var fd = new FormData();
        fd.append('csrf_token', cfg.csrf);
        fd.append('action', action);
        fd.append('subject_code', cfg.subject);
        fd.append('section_id', cfg.section);
        fd.append('term', cfg.term);
        Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
        return fetch(cfg.endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { success: false, error: 'Unexpected server response.' }; }); })
            .catch(function () { return { success: false, error: 'Could not reach the server. Check your connection.' }; });
    }

    // --- feedback (sound + vibration + result card)
    function beep(kind) {
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            var o = audioCtx.createOscillator(), g = audioCtx.createGain();
            o.type = 'sine';
            o.frequency.value = kind === 'ok' ? 880 : (kind === 'late' ? 660 : 220);
            g.gain.setValueAtTime(0.0001, audioCtx.currentTime);
            g.gain.exponentialRampToValueAtTime(0.25, audioCtx.currentTime + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + (kind === 'err' ? 0.32 : 0.18));
            o.connect(g); g.connect(audioCtx.destination);
            o.start(); o.stop(audioCtx.currentTime + 0.35);
        } catch (e) {}
        try { if (navigator.vibrate) navigator.vibrate(kind === 'err' ? [80, 40, 80] : 60); } catch (e) {}
    }

    // "Math 7 – Grade 7 – Section 7-B · " prefix for the result meta line,
    // so it's always clear which class a scan actually landed in -- it can
    // differ from the class currently on screen (see attQrRecordScan()).
    function classLine(r) {
        var bits = [];
        if (r.subject_name) bits.push(esc(r.subject_name));
        if (r.grade_level) bits.push(esc(r.grade_level));
        if (r.section_name) bits.push('Section ' + esc(r.section_name));
        return bits.length ? bits.join(' – ') + ' · ' : '';
    }

    // Large, plain-language titles for the scanner's pass/fail states.
    var ERROR_TITLES = {
        invalid_qr:           'Invalid QR Code',
        unknown_student:      'Student Not Found',
        not_in_class:         'Student Is Not Currently Enrolled',
        not_open_for_student: 'Attendance Not Started For This Class'
    };

    function showResult(res) {
        var cls, title, name = '', meta = '';
        if (!res.success) {
            cls = 'err';
            title = ERROR_TITLES[res.code] || (res.error || 'Scan Failed');
            if (res.name) {
                // Known student, just not valid right now (not enrolled, or
                // none of their classes has scanning running yet).
                name = res.name;
                meta = 'LRN ' + esc(res.lrn) + (res.error ? ' · ' + esc(res.error) : '');
                if (res.available && res.available.length) {
                    meta += ' · Start IN Scanning for: ' + res.available.map(esc).join(', ');
                }
            } else {
                meta = res.error || 'Please try again.';
            }
        } else if (res.duplicate) {
            cls = 'dup'; title = 'Attendance Already Recorded';
            name = res.name;
            meta = classLine(res) + 'LRN ' + esc(res.lrn) + ' · already ' + esc(res.status) + ' at ' + esc(res.time_label);
        } else if (res.status === 'Late') {
            cls = 'late'; title = 'Attendance Recorded Successfully'; name = res.name;
            meta = classLine(res) + 'LRN ' + esc(res.lrn) + ' · Late · IN at ' + esc(res.time_label) + ' (class started ' + esc(res.class_start) + ')';
        } else {
            cls = 'ok'; title = 'Attendance Recorded Successfully'; name = res.name;
            meta = classLine(res) + 'LRN ' + esc(res.lrn) + ' · Present · IN at ' + esc(res.time_label);
        }
        resultBox.className = 'att-result show ' + cls;
        resultBox.querySelector('.r-title').textContent = title;
        resultBox.querySelector('.r-name').textContent = name;
        resultBox.querySelector('.r-meta').innerHTML = meta;
        beep(cls === 'ok' ? 'ok' : (cls === 'late' ? 'late' : (cls === 'dup' ? 'late' : 'err')));
        clearTimeout(resultTimer);
    }

    // --- sending a scan
    function sendScan(code, method) {
        if (inFlight) return Promise.resolve();
        inFlight = true;
        return post('scan', { code: code, method: method }).then(function (res) {
            inFlight = false;
            showResult(res);
            if (res.students) applySnapshot(res, res.success ? res.student_id : null);
            return res;
        });
    }

    // --- camera + decode loop
    function loadJsQR() {
        if (window.jsQR) return Promise.resolve();
        if (jsQRLoading) return jsQRLoading;
        jsQRLoading = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = cfg.libUrl; s.onload = resolve; s.onerror = function () { reject(new Error('scanner library')); };
            document.head.appendChild(s);
        });
        return jsQRLoading;
    }

    function startCamera() {
        if (cameraOn()) return Promise.resolve();
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setCamMsg('<i class="fas fa-lock d-block mb-2"></i>The camera needs a secure page (<strong>https</strong> or <strong>localhost</strong>). You can still record students by typing or scanning their LRN below.');
            return Promise.resolve();
        }
        setCamMsg('<i class="fas fa-spinner fa-spin d-block mb-2"></i>Opening camera…');
        return Promise.all([
            loadJsQR(),
            navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false })
        ]).then(function (r) {
            stream = r[1];
            video.srcObject = stream;
            return video.play();
        }).then(function () {
            videoBox.classList.add('on');
            scanning = true;
            renderSession();
            tick();
        }).catch(function (err) {
            stopCamera();
            var msg = err && err.name === 'NotAllowedError'
                ? 'Camera permission was blocked. Allow camera access for this site, then press <strong>Turn On Camera</strong>.'
                : (err && err.name === 'NotFoundError' ? 'No camera was found on this device.' : 'The camera could not be started.');
            setCamMsg('<i class="fas fa-video-slash d-block mb-2"></i>' + msg + '<br>You can still record students by typing or scanning their LRN below.');
            renderSession();
        });
    }

    function stopCamera() {
        scanning = false;
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        video.srcObject = null;
        videoBox.classList.remove('on');
    }

    function tick() {
        if (!scanning) return;
        if (isOpen() && !inFlight && video.readyState >= 2 && video.videoWidth > 0 && window.jsQR) {
            var scale = Math.min(1, 640 / video.videoWidth);
            var w = Math.round(video.videoWidth * scale), h = Math.round(video.videoHeight * scale);
            canvas.width = w; canvas.height = h;
            ctx.drawImage(video, 0, 0, w, h);
            var code = window.jsQR(ctx.getImageData(0, 0, w, h).data, w, h, { inversionAttempts: 'attemptBoth' });
            if (code && code.data) {
                var now = Date.now();
                if (code.data !== lastCode || now - lastAt > 4000) {   // same QR held in front of the camera = one scan
                    lastCode = code.data; lastAt = now;
                    sendScan(code.data, 'qr');
                }
            }
        }
        setTimeout(tick, 110);
    }

    // --- buttons
    btnStart.addEventListener('click', function () {
        // Sound needs a user gesture on some browsers -- unlock it here.
        try { audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)(); if (audioCtx.resume) audioCtx.resume(); } catch (e) {}
        btnStart.disabled = true;
        var go = isOpen() ? Promise.resolve({ success: true }) : post('start');
        go.then(function (res) {
            if (!res.success) {
                btnStart.disabled = false;
                showResult({ success: false, error: res.error || 'Could not start the session.' });
                return;
            }
            if (res.students) applySnapshot(res);
            return startCamera();
        }).then(renderSession);
    });

    btnEnd.addEventListener('click', function () {
        var waiting = students.filter(function (s) { return s.status === 'Waiting'; }).length;
        document.getElementById('endModalBody').innerHTML = waiting > 0
            ? '<p class="mb-2"><strong>' + waiting + '</strong> student' + (waiting === 1 ? ' has' : 's have') + ' not scanned in yet. Ending the session marks them as <strong>Absent</strong>.</p><p class="mb-0 text-muted small">Students who already scanned keep their Present/Late record. You can resume scanning later today — a late scan will replace the Absent mark with Late.</p>'
            : '<p class="mb-0">Everyone has scanned in. End the session now?</p>';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('endModal')).show();
    });

    document.getElementById('endConfirm').addEventListener('click', function () {
        var btn = this; btn.disabled = true;
        post('end').then(function (res) {
            btn.disabled = false;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('endModal')).hide();
            if (!res.success) { showResult({ success: false, error: res.error || 'Could not end the session.' }); return; }
            stopCamera();
            applySnapshot(res);
            setCamMsg('<i class="fas fa-circle-check d-block mb-2" style="color:#4cc38a;"></i>Session ended.' + (res.absent_marked ? ' <strong>' + res.absent_marked + '</strong> student' + (res.absent_marked === 1 ? '' : 's') + ' marked Absent.' : '') + '<br>Press <strong>Resume Scanning</strong> to reopen it.');
        });
    });

    manualForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var lrn = manualLrn.value.trim();
        if (!lrn) return;
        sendScan(lrn, 'lrn').then(function (res) {
            if (res && res.success) manualLrn.value = '';
            manualLrn.focus();
            manualLrn.select();
        });
    });

    // --- live updates: refresh the list every few seconds while the session is open
    setInterval(function () {
        if (document.hidden || inFlight || !isOpen()) return;
        post('state').then(function (res) { if (res.success && !inFlight) applySnapshot(res); });
    }, 4000);

    window.addEventListener('pagehide', stopCamera);
    renderSession();
    if (session && session.status === 'Closed') {
        setCamMsg('<i class="fas fa-circle-check d-block mb-2" style="color:#4cc38a;"></i>Today\'s session has ended.<br>Press <strong>Resume Scanning</strong> to reopen it.');
    } else if (isOpen()) {
        setCamMsg('Session is open. Press <strong>Turn On Camera</strong> to continue scanning.');
    }
})();
</script>

<?php include '../../includes/footer.php'; ?>