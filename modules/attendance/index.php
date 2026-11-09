<?php
// modules/attendance/index.php
// Teacher Attendance — Time In / Time Out for
// the logged-in teacher. The expected duty schedule is derived from the
// teacher's ACTUAL class assignments for today (teacher_subjects +
// teacher_class_schedule), not from can_handle_shs or grade level alone.
// See includes/attendance_helpers.php for the shared schedule logic.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/attendance_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

// Admin/Registrar goes to the monitoring page
if (hasAnyRole(['Administrator', 'Registrar'])) {
    header('Location: admin_view.php');
    exit;
}

if (!hasRole('Teacher')) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'My Attendance';
$current_page = 'attendance';
$base_url = '../../';

$teacher_id = (int) $_SESSION['user_id'];
$today = date('Y-m-d'); // Asia/Manila, set globally in config/database.php

// ============================================================
// HANDLE ATTENDANCE ACTIONS
// Server time only — the browser never supplies a timestamp.
// Every action is re-validated regardless of what the UI allowed.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $is_ajax_request = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    $respond_to_action = function ($success, $message, $status_code = 200, array $extra = []) use ($is_ajax_request) {
        if ($is_ajax_request) {
            // The page reloads after a successful action; carry the message
            // over so the teacher sees the confirmation on the refreshed page.
            if ($success) {
                $_SESSION['success_message'] = $message;
            }
            http_response_code($status_code);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
            exit;
        }

        $_SESSION[$success ? 'success_message' : 'error_message'] = $message;
        header('Location: index.php');
        exit;
    };

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $respond_to_action(false, 'Invalid security token. Please refresh the page and try again.', 403);
    }

    $action = $_POST['action'];
    $now_time = date('H:i:s');
    $now_label = date('g:i A');
    // Teacher-facing message for any unexpected failure. The technical
    // cause is only ever written to the server error log.
    $fail_message = $action === 'time_out'
        ? 'Unable to record Time Out. Please try again.'
        : 'Unable to record Time In. Please try again.';

    try {
        // Bring teacher_attendance up to the schema this module expects
        // (additive + idempotent; see teacherAttendanceEnsureSchema()).
        if (!teacherAttendanceEnsureSchema()) {
            error_log("Teacher attendance ($action) failed for teacher_id=$teacher_id: schema is missing required columns.");
            $respond_to_action(false, $fail_message, 500);
        }

        // Always scoped to the logged-in teacher (session) and today's
        // date — one row per teacher per day (UNIQUE uniq_teacher_date).
        $existing_stmt = executeQuery(
            "SELECT * FROM teacher_attendance WHERE teacher_id = ? AND attendance_date = ?",
            [$teacher_id, $today]
        );
        if (!$existing_stmt) {
            error_log("Teacher attendance ($action) failed for teacher_id=$teacher_id: could not read today's record.");
            $respond_to_action(false, $fail_message, 500);
        }
        $existing = $existing_stmt->fetch(PDO::FETCH_ASSOC);

        switch ($action) {
            case 'time_in':
                // Repeated click / page refresh / second tab: never insert a
                // second row. Return the Time In that is already saved.
                if ($existing && !empty($existing['time_in'])) {
                    $respond_to_action(
                        true,
                        'You already timed in today at ' . date('g:i A', strtotime($existing['time_in'])) . '.',
                        200,
                        ['already_recorded' => true, 'time_in' => $existing['time_in']]
                    );
                }

                $window = getExpectedAttendanceWindow($teacher_id, $today);
                $snapshot = [
                    $window['time_in'] ?? null,
                    $window['time_out'] ?? null,
                    $window['break_start'] ?? null,
                    $window['break_end'] ?? null,
                    $window['schedule_type'] ?? null,
                ];

                if ($existing) {
                    // A row for today exists but has no Time In (e.g. cleared by
                    // an admin edit). Fill in THAT row instead of inserting.
                    $result = executeQuery(
                        "UPDATE teacher_attendance
                            SET time_in = ?, status = 'Timed In',
                                expected_time_in = ?, expected_time_out = ?, expected_break_start = ?, expected_break_end = ?, schedule_type = ?
                          WHERE attendance_id = ? AND teacher_id = ? AND time_in IS NULL",
                        array_merge([$now_time], $snapshot, [$existing['attendance_id'], $teacher_id])
                    );
                } else {
                    // The no-op ON DUPLICATE KEY clause makes a concurrent
                    // double submit harmless: the UNIQUE (teacher_id,
                    // attendance_date) key turns the 2nd insert into a no-op.
                    $result = executeQuery(
                        "INSERT INTO teacher_attendance
                            (teacher_id, attendance_date, time_in, status, expected_time_in, expected_time_out, expected_break_start, expected_break_end, schedule_type)
                         VALUES (?, ?, ?, 'Timed In', ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE attendance_id = attendance_id",
                        array_merge([$teacher_id, $today, $now_time], $snapshot)
                    );
                }
                if (!$result) {
                    // executeQuery() already logged the SQL error.
                    error_log("Teacher Time In failed for teacher_id=$teacher_id date=$today: save query failed.");
                    $respond_to_action(false, $fail_message, 500);
                }

                // Confirm what is actually stored, and report that.
                $saved_stmt = executeQuery(
                    "SELECT time_in FROM teacher_attendance WHERE teacher_id = ? AND attendance_date = ?",
                    [$teacher_id, $today]
                );
                $saved = $saved_stmt ? $saved_stmt->fetch(PDO::FETCH_ASSOC) : false;
                if (!$saved || empty($saved['time_in'])) {
                    error_log("Teacher Time In failed for teacher_id=$teacher_id date=$today: record not found after save.");
                    $respond_to_action(false, $fail_message, 500);
                }
                $saved_label = date('g:i A', strtotime($saved['time_in']));

                if ($result->rowCount() < 1) {
                    // Lost a race with another request that saved first.
                    $respond_to_action(
                        true,
                        'You already timed in today at ' . $saved_label . '.',
                        200,
                        ['already_recorded' => true, 'time_in' => $saved['time_in']]
                    );
                }

                logActivity($teacher_id, 'Teacher Time In', 'attendance', 'Clocked in at ' . $now_label);
                $respond_to_action(true, 'Time In recorded successfully at ' . $saved_label . '.', 200, ['time_in' => $saved['time_in']]);

            case 'time_out':
                $check = validateAttendanceAction($existing ?: null, $action);
                if (!$check['valid']) {
                    $respond_to_action(false, $check['message'], 409);
                }
                // Updates the SAME row Time In created — never inserts.
                $result = executeQuery(
                    "UPDATE teacher_attendance SET time_out = ?, status = 'Completed' WHERE attendance_id = ? AND teacher_id = ? AND time_out IS NULL",
                    [$now_time, $existing['attendance_id'], $teacher_id]
                );
                if (!$result || $result->rowCount() !== 1) {
                    $respond_to_action(false, 'Time Out could not be saved. Refresh the page and try again.', 409);
                }
                logActivity($teacher_id, 'Teacher Time Out', 'attendance', 'Clocked out at ' . $now_label);
                $respond_to_action(true, 'Time Out recorded successfully.');

            default:
                $respond_to_action(false, 'Unknown attendance action.', 400);
        }
    } catch (Throwable $e) {
        error_log("Teacher attendance ($action) exception for teacher_id=$teacher_id: " . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $respond_to_action(false, $fail_message, 500);
    }
}

$csrf_token = generateCSRFToken();

// The page below reads the schedule-snapshot columns; make sure they exist.
teacherAttendanceEnsureSchema();

// ============================================================
// TODAY'S SCHEDULE + ATTENDANCE STATE
// ============================================================
$today_record = getRecord(
    "SELECT * FROM teacher_attendance WHERE teacher_id = ? AND attendance_date = ?",
    [$teacher_id, $today]
);

$today_schedule_rows = getTeacherScheduleForDate($teacher_id, $today);
$has_schedule_today = !empty($today_schedule_rows);
$is_weekend_today = isWeekend($today);

// Once the teacher has timed in, the schedule shown/used for Late
// calculation is the SNAPSHOT stored on that record (so it stays
// accurate to what was actually expected that day, even if the school's
// class program changes later) — not a live recomputation.
if ($today_record) {
    $display_window = [
        'time_in'       => $today_record['expected_time_in'],
        'time_out'      => $today_record['expected_time_out'],
        'break_start'   => $today_record['expected_break_start'],
        'break_end'     => $today_record['expected_break_end'],
        'schedule_type' => $today_record['schedule_type'],
    ];
} else {
    $display_window = getExpectedAttendanceWindow($teacher_id, $today);
}

$attendance_state_record = $today_record
    ? array_merge($today_record, ['break_start' => null, 'break_end' => null])
    : null;
$attendance_state = getTeacherAttendanceState($attendance_state_record, true);

// Fetch attendance history (last 30 days)
$history = getRecords(
    "SELECT * FROM teacher_attendance WHERE teacher_id = ? ORDER BY attendance_date DESC LIMIT 30",
    [$teacher_id]
);

// Teacher info
$teacher = getRecord("SELECT first_name, last_name FROM users WHERE user_id = ?", [$teacher_id]);

// Status badge colors — getAttendanceStatusColors() in
// includes/attendance_helpers.php, shared with the admin monitoring
// page so a status never renders differently in one place than the
// other.
function attStatusColors($status) {
    return getAttendanceStatusColors($status);
}

include '../../includes/header.php';
?>
<?php
// ---- Display values ----
$att_hour = (int) date('G');
$att_greet = $att_hour < 12 ? 'Good morning,' : ($att_hour < 18 ? 'Good afternoon,' : 'Good evening,');
$att_greet_icon = $att_hour < 18 ? 'fa-sun' : 'fa-moon';

// Month shown in Attendance History (?month=YYYY-MM, default this month).
$att_month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$att_months = array_column(getRecords(
    "SELECT DISTINCT DATE_FORMAT(attendance_date, '%Y-%m') AS m FROM teacher_attendance WHERE teacher_id = ? ORDER BY m DESC",
    [$teacher_id]
), 'm');
if (!in_array(date('Y-m'), $att_months, true)) array_unshift($att_months, date('Y-m'));
if (!in_array($att_month, $att_months, true)) $att_months[] = $att_month;
rsort($att_months);
$history = getRecords(
    "SELECT * FROM teacher_attendance WHERE teacher_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ? ORDER BY attendance_date DESC",
    [$teacher_id, $att_month]
);
// Summary for the month: worked time = Time Out - Time In (same as the table).
$att_present = 0; $att_secs = 0; $att_complete = 0; $att_incomplete = 0;
foreach ($history as $h) {
    if (!empty($h['time_in'])) $att_present++;
    if (!empty($h['time_in']) && !empty($h['time_out'])) {
        $att_secs += max(0, strtotime($h['time_out']) - strtotime($h['time_in']));
        $att_complete++;
    } elseif (!empty($h['time_in']) && $h['attendance_date'] !== $today) {
        $att_incomplete++; // timed in but never timed out (today is still in progress)
    }
}
 $att_active = !empty($today_record['time_in']) && empty($today_record['time_out']);
$att_active_elapsed = $att_active
    ? max(0, time() - strtotime($today . ' ' . $today_record['time_in']))
    : 0;
$att_active_in_month = $att_active && $att_month === date('Y-m');
if ($att_active_in_month) $att_secs += $att_active_elapsed;
$att_fmt_hours = function ($secs) { $h = floor($secs / 3600); $m = floor(($secs % 3600) / 60); return $h . 'h' . ($m ? ' ' . $m . 'm' : ''); };
$att_fmt_elapsed = function ($secs) { return sprintf('%02d:%02d:%02d', floor($secs / 3600), floor(($secs % 3600) / 60), $secs % 60); };
$att_total = $att_secs ? $att_fmt_hours($att_secs) : '0';
$att_avg_days = $att_complete + ($att_active_in_month ? 1 : 0);
$att_avg = $att_avg_days ? $att_fmt_hours((int) round($att_secs / $att_avg_days)) : '0';

// Progress steps
$s_in = !empty($today_record['time_in']);
$s_out = !empty($today_record['time_out']);
$att_t = function ($v) { return $v ? date('g:i A', strtotime($v)) : ''; };
?>

<style>
.att2 { --a-line: var(--border, #e3e8ef); --a-card: var(--surface, #fff); --a-soft: var(--surface-hover, #f6f8fb); --a-ink: var(--ink, #14213d); --a-muted: var(--text-muted, #667085); --a-green: #0f7b5f; }
.att2 .hero { display: grid; grid-template-columns: minmax(260px, 0.9fr) 2fr; background: var(--a-card); border: 1px solid var(--a-line); border-radius: 16px; overflow: hidden; margin-bottom: 22px; box-shadow: 0 1px 3px rgba(16,24,40,.04); }
.att2 .hero-art { position: relative; background: linear-gradient(180deg, #eef5fd 0%, #e6f0fb 60%, #dcebf9 100%); padding: 34px 30px 0; min-height: 300px; display: flex; flex-direction: column; }
.att2 .hero-art .greet { font-size: 18px; color: #4b5b73; }
.att2 .hero-art .who { font-size: 34px; font-weight: 800; color: var(--a-ink); line-height: 1.15; display: flex; align-items: center; gap: 12px; margin: 4px 0 8px; }
.att2 .hero-art .who i { color: #f5b301; font-size: 30px; }
.att2 .hero-art .who i.fa-moon { color: #7c8db5; }
.att2 .hero-art .note { font-size: 16px; color: #4b5b73; }
.att2 .hero-art svg { margin-top: auto; width: 100%; height: auto; display: block; }
.att2 .hero-main { padding: 26px 26px 24px; }
.att2 .today-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
.att2 .today-lbl { font-size: 14.5px; font-weight: 700; letter-spacing: .06em; color: var(--a-muted); }
.att2 .today-date { font-size: 30px; font-weight: 800; color: var(--a-ink); letter-spacing: -.01em; }
.att2 .date-chip { display: inline-flex; align-items: center; gap: 10px; border: 1px solid var(--a-line); border-radius: 10px; padding: 9px 14px; font-size: 15px; color: var(--a-ink); background: var(--a-soft); }
.att2 .panels { display: grid; grid-template-columns: 1fr 1.25fr; gap: 16px; }
.att2 .panel { border: 1px solid var(--a-line); border-radius: 12px; padding: 16px 18px; background: var(--a-soft); }
.att2 .panel-title { font-size: 14px; font-weight: 700; letter-spacing: .06em; color: var(--a-muted); margin-bottom: 14px; text-transform: uppercase; }
.att2 .duty { display: flex; gap: 14px; }
.att2 .duty-ic { flex-shrink: 0; width: 58px; height: 58px; border-radius: 50%; background: #dbe8fb; color: #2f7fe0; display: flex; align-items: center; justify-content: center; font-size: 24px; }
.att2 .duty-type { font-size: 19px; font-weight: 800; color: var(--a-ink); }
.att2 .duty-exp { font-size: 15.5px; color: #4b5b73; margin-top: 4px; }
.att2 .duty-exp strong { color: var(--a-ink); }
.att2 .duty-assigned { border-top: 1px solid var(--a-line); margin-top: 12px; padding-top: 12px; font-size: 15.5px; color: #4b5b73; }
.att2 .duty-assigned .cls { color: var(--a-ink); font-weight: 700; }
.att2 .duty-assigned .tm { color: var(--a-muted); }
.att2 .duty-empty { font-size: 15px; color: var(--a-muted); }
.att2 .steps { display: flex; align-items: flex-start; justify-content: space-between; position: relative; margin: 2px 6px 16px; }
.att2 .step { display: flex; flex-direction: column; align-items: center; gap: 6px; width: 50%; position: relative; z-index: 1; }
.att2 .step-ic { width: 48px; height: 48px; border-radius: 50%; background: #e9edf3; color: #6b7a90; display: flex; align-items: center; justify-content: center; font-size: 19px; border: 2px solid var(--a-card); }
.att2 .step.done .step-ic { background: #1f9d6b; color: #fff; }
.att2 .step.now .step-ic { background: #2f7fe0; color: #fff; box-shadow: 0 0 0 4px rgba(47,127,224,.18); }
.att2 .step-name { font-size: 15px; font-weight: 700; color: var(--a-ink); }
.att2 .step-state { font-size: 14px; border-radius: 6px; padding: 3px 10px; background: #eef1f5; color: var(--a-muted); white-space: nowrap; }
.att2 .step.done .step-state { background: #e3f5ec; color: #16704b; font-weight: 600; }
.att2 .step.now .step-state { background: #e7f0fc; color: #1d5fbf; font-weight: 600; }
.att2 .step.next .step-state { background: #e3f5ec; color: #16704b; }
.att2 .steps::before { content: ""; position: absolute; top: 24px; left: 25%; right: 25%; height: 2px; background: #dfe4ec; z-index: 0; }
.att2 .actions { display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; }
.att2 .actions form { margin: 0; }
.att2 .btn-att { display: inline-flex; align-items: center; justify-content: center; gap: 10px; min-width: 200px; height: 48px; padding: 0 26px; border-radius: 8px; border: 0; font-size: 17px; font-weight: 700; color: #fff; background: var(--a-green); }
.att2 .btn-att:hover { filter: brightness(1.07); color: #fff; }
.att2 .btn-att.out { background: #c2412d; }
.att2 .btn-att.out-ghost { background: var(--a-card); color: #c2412d; border: 1px solid #e6b3aa; min-width: 150px; }
.att2 .help { text-align: center; font-size: 15px; color: var(--a-muted); margin-top: 10px; }
.att2 .status-row { text-align: center; margin-top: 8px; font-size: 14.5px; color: var(--a-muted); }
.att2 .done-msg { text-align: center; color: #16704b; font-weight: 700; font-size: 15px; }
.att2 .action-feedback { margin-top: 12px; padding: 10px 12px; border-radius: 8px; background: #fdecec; color: #b42318; text-align: center; }

.att2 .hist { background: var(--a-card); border: 1px solid var(--a-line); border-radius: 16px; padding: 22px 26px; box-shadow: 0 1px 3px rgba(16,24,40,.04); }
.att2 .hist-head { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
.att2 .hist-head .ic { font-size: 30px; color: var(--a-ink); }
.att2 .hist-head h2 { font-size: 21px; font-weight: 800; color: var(--a-ink); margin: 0; }
.att2 .hist-head p { margin: 2px 0 0; font-size: 15.5px; color: var(--a-muted); }
.att2 .month-pick { margin-left: auto; position: relative; }
.att2 .month-pick i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--a-ink); pointer-events: none; }
.att2 .month-pick select { height: 44px; border: 1px solid var(--a-line); border-radius: 10px; padding: 0 36px 0 40px; font-size: 15.5px; color: var(--a-ink); background: var(--a-card); min-width: 210px; }
.att2 .tiles { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 16px; }
.att2 .tile { display: flex; align-items: center; gap: 16px; border-radius: 12px; padding: 16px 20px; }
.att2 .tile i { font-size: 30px; }
.att2 .tile b { display: block; font-size: 24px; color: var(--a-ink); line-height: 1.1; }
.att2 .tile span { font-size: 15.5px; color: #4b5b73; }
.att2 .t-green { background: #e8f6ef; } .att2 .t-green i { color: #1f9d6b; }
.att2 .t-orange { background: #fdf3e6; } .att2 .t-orange i { color: #e08a00; }
.att2 .t-blue { background: #eaf2fc; } .att2 .t-blue i { color: #2f7fe0; }
.att2 .t-red { background: #fdecec; } .att2 .t-red i { color: #d9363e; }
.att2 .hist-empty { border: 1px solid var(--a-line); border-radius: 12px; text-align: center; padding: 26px 16px 30px; }
.att2 .hist-empty .art { width: 110px; height: 110px; border-radius: 50%; margin: 0 auto 10px; display: flex; align-items: center; justify-content: center; background: radial-gradient(circle, #eef3f9 55%, transparent 56%); font-size: 46px; color: #8fa3bd; }
.att2 .hist-empty strong { display: block; font-size: 17px; color: var(--a-ink); }
.att2 .hist-empty span { font-size: 15.5px; color: var(--a-muted); }
.att2 .hist .table-responsive { border: 1px solid var(--a-line); border-radius: 12px; }

html[data-theme="dark"] .att2 .hero-art { background: linear-gradient(180deg, #16223A 0%, #182a47 100%); }
html[data-theme="dark"] .att2 .hero-art .greet, html[data-theme="dark"] .att2 .hero-art .note, html[data-theme="dark"] .att2 .duty-exp, html[data-theme="dark"] .att2 .duty-assigned, html[data-theme="dark"] .att2 .tile span { color: #c7d2e6; }
html[data-theme="dark"] .att2 .step-ic { background: #22314f; color: #93a0b8; }
html[data-theme="dark"] .att2 .step-state { background: #22314f; }
html[data-theme="dark"] .att2 .steps::before { background: #263352; }
html[data-theme="dark"] .att2 .duty-ic { background: rgba(47,127,224,.18); color: #8ab4f8; }
html[data-theme="dark"] .att2 .t-green { background: rgba(31,157,107,.14); }
html[data-theme="dark"] .att2 .t-orange { background: rgba(224,138,0,.14); }
html[data-theme="dark"] .att2 .t-blue { background: rgba(47,127,224,.14); }
html[data-theme="dark"] .att2 .t-red { background: rgba(217,54,62,.14); }
html[data-theme="dark"] .att2 .hist-empty .art { background: radial-gradient(circle, #1b2a44 55%, transparent 56%); }
@media (max-width: 1100px) { .att2 .hero { grid-template-columns: 1fr; } .att2 .hero-art { min-height: 0; padding-bottom: 0; } .att2 .hero-art svg { max-height: 150px; } .att2 .panels { grid-template-columns: 1fr; } .att2 .tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .att2 .tiles { grid-template-columns: 1fr; } .att2 .month-pick { margin-left: 0; width: 100%; } .att2 .month-pick select { width: 100%; } }
</style>

<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">My Attendance</h1>
        <p class="page-subtitle">Record your daily Time In and Time Out.</p>
    </div>
</div>

<div class="att2">
    <!-- Today -->
    <section class="hero">
        <div class="hero-art">
            <div class="greet"><?php echo $att_greet; ?></div>
            <div class="who">Teacher <?php echo htmlspecialchars($teacher['first_name'] ?? ''); ?> <i class="fas <?php echo $att_greet_icon; ?>"></i></div>
            <div class="note">Here's your attendance for today.</div>
            <svg viewBox="0 0 460 200" aria-hidden="true">
                <ellipse cx="120" cy="120" rx="120" ry="70" fill="#d6e6f7"/><ellipse cx="330" cy="110" rx="130" ry="80" fill="#d6e6f7"/>
                <rect x="360" y="70" width="40" height="120" fill="#c9dcf2"/><rect x="330" y="95" width="26" height="95" fill="#c9dcf2"/>
                <rect x="100" y="98" width="200" height="92" fill="#f4f7fb" stroke="#b9cbe3"/>
                <rect x="160" y="62" width="80" height="128" fill="#fff" stroke="#b9cbe3"/>
                <polygon points="150,64 200,40 250,64" fill="#6f95d8"/><rect x="92" y="92" width="72" height="8" fill="#6f95d8"/><rect x="236" y="92" width="72" height="8" fill="#6f95d8"/>
                <circle cx="200" cy="84" r="13" fill="#fff" stroke="#6f95d8" stroke-width="3"/><path d="M200 77v8l5 3" stroke="#6f95d8" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                <g fill="#5b8fe0"><rect x="112" y="110" width="16" height="18"/><rect x="136" y="110" width="16" height="18"/><rect x="248" y="110" width="16" height="18"/><rect x="272" y="110" width="16" height="18"/>
                <rect x="112" y="140" width="16" height="18"/><rect x="136" y="140" width="16" height="18"/><rect x="248" y="140" width="16" height="18"/><rect x="272" y="140" width="16" height="18"/>
                <rect x="176" y="112" width="16" height="18"/><rect x="208" y="112" width="16" height="18"/></g>
                <rect x="186" y="150" width="28" height="40" fill="#5b8fe0"/>
                <path d="M0 190 Q120 160 230 188 T460 180 V200 H0Z" fill="#8cc79a"/>
                <g fill="#4f9d6a"><circle cx="40" cy="150" r="24"/><circle cx="68" cy="160" r="20"/><circle cx="330" cy="152" r="22"/><circle cx="400" cy="146" r="28"/><circle cx="436" cy="160" r="20"/></g>
                <g stroke="#6b5a45" stroke-width="3"><line x1="40" y1="170" x2="40" y2="192"/><line x1="400" y1="170" x2="400" y2="192"/></g>
            </svg>
        </div>

        <div class="hero-main">
            <div class="today-row">
                <div>
                    <div class="today-lbl">TODAY</div>
                    <div class="today-date"><?php echo date('l, F j, Y'); ?></div>
                </div>
            </div>

            <div class="panels">
                <!-- TODAY'S DUTY SCHEDULE -->
                <div class="panel">
                    <div class="panel-title">Today's Duty Schedule</div>
                    <?php if ($is_weekend_today && !$has_schedule_today): ?>
                        <div class="duty-empty">No scheduled teaching duty today.</div>
                    <?php elseif (!$has_schedule_today): ?>
                        <div class="duty-empty">Today's teaching schedule has not been configured.<br><small>You may still record Time In/Out; the school may still require it even without a set class schedule.</small></div>
                    <?php else: ?>
                        <div class="duty">
                            <div class="duty-ic"><i class="fas fa-book-open"></i></div>
                            <div>
                                <div class="duty-type"><?php echo htmlspecialchars($display_window['schedule_type'] ?? 'Custom Schedule'); ?></div>
                                <?php if (!empty($display_window['time_in']) && !empty($display_window['time_out'])): ?>
                                    <div class="duty-exp">Expected Duty: <strong><?php echo date('g:i A', strtotime($display_window['time_in'])); ?> – <?php echo date('g:i A', strtotime($display_window['time_out'])); ?></strong></div>
                                <?php endif; ?>
                                <div class="duty-assigned">Assigned Today:
                                    <?php foreach ($today_schedule_rows as $row): ?>
                                        <div><span class="cls"><?php echo htmlspecialchars($row['grade_level'] . ' · ' . $row['subject_name']); ?></span><br><span class="tm">(<?php echo date('g:i A', strtotime($row['start_time'])); ?> – <?php echo date('g:i A', strtotime($row['end_time'])); ?>)</span></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ATTENDANCE PROGRESS -->
                <div class="panel">
                    <div class="panel-title">Attendance Progress</div>
                    <div class="steps">
                        <div class="step <?php echo $s_in ? 'done' : 'next'; ?>">
                            <div class="step-ic"><i class="far fa-clock"></i></div>
                            <div class="step-name">Time In</div>
                            <div class="step-state"><?php echo $s_in ? $att_t($today_record['time_in']) : 'Not started'; ?></div>
                        </div>
                        <div class="step <?php echo $s_out ? 'done' : ''; ?>">
                            <div class="step-ic"><i class="fas fa-arrow-right-from-bracket"></i></div>
                            <div class="step-name">Time Out</div>
                            <div class="step-state"><?php echo $s_out ? $att_t($today_record['time_out']) : 'Not started'; ?></div>
                        </div>
                    </div>

                    <div id="attendance-action-feedback" class="action-feedback" role="alert" hidden></div>

                    <?php if ($s_in): ?>
                        <?php
                            $today_worked_seconds = $att_active
                                ? $att_active_elapsed
                                : max(0, strtotime($today_record['time_out']) - strtotime($today_record['time_in']));
                        ?>
                        <div class="status-row">
                            Teaching time:
                            <strong class="<?php echo $att_active ? 'js-attendance-elapsed' : ''; ?>"<?php echo $att_active ? ' data-elapsed-seconds="' . $today_worked_seconds . '"' : ''; ?>>
                                <?php echo $att_fmt_elapsed($today_worked_seconds); ?>
                            </strong>
                        </div>
                    <?php endif; ?>

                    <?php if (!$today_record): ?>
                        <div class="actions">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="time_in">
                                <button type="submit" class="btn-att"><i class="fas fa-circle-play"></i> Time In</button>
                            </form>
                        </div>
                        <div class="help">Start your day by clicking the Time In button.</div>
                    <?php elseif (empty($today_record['time_out'])): ?>
                        <div class="actions">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="time_out">
                                <button type="submit" class="btn-att out"><i class="fas fa-arrow-right-from-bracket"></i> Time Out</button>
                            </form>
                        </div>
                        <?php list($sbg, $scl) = attStatusColors($attendance_state); ?>
                        <div class="status-row">Status: <span class="status-pill" style="background:<?php echo $sbg; ?>;color:<?php echo $scl; ?>;"><?php echo htmlspecialchars($attendance_state); ?></span></div>
                    <?php else: ?>
                        <?php list($sbg, $scl) = attStatusColors($attendance_state); ?>
                        <div class="done-msg"><i class="fas fa-circle-check me-1"></i>Attendance completed for today.</div>
                        <div class="status-row">Status: <span class="status-pill" style="background:<?php echo $sbg; ?>;color:<?php echo $scl; ?>;"><?php echo htmlspecialchars($attendance_state); ?></span></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Attendance History -->
    <section class="hist">
        <div class="hist-head">
            <i class="far fa-calendar-days ic"></i>
            <div>
                <h2>Attendance History</h2>
                <p>View your daily Time In and Time Out records.</p>
            </div>
            <form method="GET" class="month-pick">
                <i class="far fa-calendar"></i>
                <select name="month" onchange="this.form.submit()" aria-label="Month">
                    <?php foreach ($att_months as $m): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m === $att_month ? 'selected' : ''; ?>><?php echo date('F Y', strtotime($m . '-01')); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="tiles">
            <div class="tile t-green"><i class="far fa-calendar-check"></i><div><b><?php echo $att_present; ?></b><span>Days Present</span></div></div>
            <div class="tile t-orange"><i class="far fa-clock"></i><div><b<?php echo $att_active_in_month ? ' class="js-attendance-total" data-total-seconds="' . $att_secs . '"' : ''; ?>><?php echo $att_total; ?></b><span>Total Hours</span></div></div>
            <div class="tile t-blue"><i class="fas fa-clock-rotate-left"></i><div><b<?php echo $att_active_in_month ? ' class="js-attendance-average" data-average-days="' . $att_avg_days . '" data-total-seconds="' . $att_secs . '"' : ''; ?>><?php echo $att_avg; ?></b><span>Avg. Hours/Day</span></div></div>
            <div class="tile t-red"><i class="far fa-calendar-xmark"></i><div><b><?php echo $att_incomplete; ?></b><span>Days with Incomplete Log</span></div></div>
        </div>

        <?php if (empty($history)): ?>
            <div class="hist-empty">
                <div class="art"><i class="far fa-calendar-xmark"></i></div>
                <strong>No attendance records yet.</strong>
                <span>Your entries will appear here after you time in.</span>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Schedule</th>
                            <th>Attendance</th>
                            <th>Worked</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                            <?php
                                $h_is_today = ($h['attendance_date'] === $today);
                                $h_state_record = array_merge($h, ['break_start' => null, 'break_end' => null]);
                                $h_state = getTeacherAttendanceState($h_state_record, $h_is_today);
                                list($hbg, $hcl) = attStatusColors($h_state);
                                $h_is_active = $h_is_today && !empty($h['time_in']) && empty($h['time_out']);
                                $h_has_hours = !empty($h['time_in']) && (!empty($h['time_out']) || $h_is_active);
                                if ($h_has_hours) {
                                    $h_diff = $h_is_active
                                        ? $att_active_elapsed
                                        : strtotime($h['time_out']) - strtotime($h['time_in']);
                                    $h_worked = $h_is_active
                                        ? $att_fmt_elapsed($h_diff)
                                        : floor($h_diff / 3600) . 'h ' . floor(($h_diff % 3600) / 60) . 'm';
                                }
                            ?>
                            <tr>
                                <td class="cell-strong no-wrap"><?php echo date('M j, Y', strtotime($h['attendance_date'])); ?></td>
                                <td style="font-size:14px;">
                                    <div><?php echo !empty($h['schedule_type']) ? htmlspecialchars($h['schedule_type']) : '<span class="text-muted-2">—</span>'; ?></div>
                                    <?php if (!empty($h['expected_time_in']) && !empty($h['expected_time_out'])): ?>
                                        <div class="text-muted-2"><?php echo date('g:i A', strtotime($h['expected_time_in'])) . ' – ' . date('g:i A', strtotime($h['expected_time_out'])); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="attendance-time-block">
                                        <div class="time-row"><span class="time-label">In</span><span class="time-value <?php echo $h['time_in'] ? '' : 'text-muted-2'; ?>"><?php echo $h['time_in'] ? date('g:i A', strtotime($h['time_in'])) : '—'; ?></span></div>
                                        <div class="time-row"><span class="time-label">Out</span><span class="time-value <?php echo $h['time_out'] ? '' : 'text-muted-2'; ?>"><?php echo $h['time_out'] ? date('g:i A', strtotime($h['time_out'])) : '—'; ?></span></div>
                                    </div>
                                </td>
                                <td class="<?php echo $h_has_hours ? 'cell-strong' : 'text-muted-2'; ?>"><?php if ($h_has_hours): ?><span<?php echo $h_is_active ? ' class="js-attendance-elapsed" data-elapsed-seconds="' . $h_diff . '"' : ''; ?>><?php echo $h_worked; ?></span><?php else: ?>—<?php endif; ?></td>
                                <td><span class="status-pill" style="background:<?php echo $hbg; ?>;color:<?php echo $hcl; ?>;"><?php echo htmlspecialchars($h_state); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
    (function () {
        var attendanceForms = document.querySelectorAll('.att2 .actions form');
        Array.prototype.forEach.call(attendanceForms, function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.fetch || !window.FormData) return;
                event.preventDefault();

                // Ignore repeated clicks / Enter presses while a request is running.
                if (form.dataset.submitting === '1') return;
                form.dataset.submitting = '1';

                var button = form.querySelector('button[type="submit"]');
                var originalButton = button.innerHTML;
                var actionInput = form.querySelector('input[name="action"]');
                var fallbackMessage = actionInput && actionInput.value === 'time_out'
                    ? 'Unable to record Time Out. Please try again.'
                    : 'Unable to record Time In. Please try again.';
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

                fetch(form.action || window.location.href, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                }).then(function (response) {
                    // A non-JSON reply (PHP/HTML error page) must never reach the teacher.
                    return response.json().then(function (result) {
                        return { ok: response.ok, result: result };
                    }, function () {
                        return { ok: false, result: null };
                    });
                }).then(function (reply) {
                    if (!reply.ok || !reply.result || !reply.result.success) {
                        // Only messages written by the server for the teacher are shown.
                        var failure = new Error((reply.result && reply.result.message) || fallbackMessage);
                        failure.teacherSafe = true;
                        throw failure;
                    }
                    // Reload so Attendance Progress is rebuilt from the saved record.
                    window.location.reload();
                }).catch(function (error) {
                    form.dataset.submitting = '0';
                    button.disabled = false;
                    button.innerHTML = originalButton;
                    var feedback = document.getElementById('attendance-action-feedback');
                    feedback.hidden = false;
                    feedback.textContent = (error && error.teacherSafe) ? error.message : fallbackMessage;
                });
            });
        });

        var timers = document.querySelectorAll('.js-attendance-elapsed');
        var total = document.querySelector('.js-attendance-total');
        var average = document.querySelector('.js-attendance-average');
        if (!timers.length && !total) return;

        var startedAt = performance.now();
        Array.prototype.forEach.call(timers, function (timer) {
            timer.dataset.initialElapsed = timer.dataset.elapsedSeconds;
        });
        var initialTotal = total ? Number(total.dataset.totalSeconds) : 0;
        var averageDays = average ? Number(average.dataset.averageDays) : 0;

        function updateElapsedTimes() {
            var additionalSeconds = Math.floor((performance.now() - startedAt) / 1000);
            Array.prototype.forEach.call(timers, function (timer) {
                var elapsed = Number(timer.dataset.initialElapsed) + additionalSeconds;
                var hours = Math.floor(elapsed / 3600);
                var minutes = Math.floor((elapsed % 3600) / 60);
                var seconds = elapsed % 60;
                timer.textContent = String(hours).padStart(2, '0') + ':' +
                    String(minutes).padStart(2, '0') + ':' +
                    String(seconds).padStart(2, '0');
            });
            if (total) {
                var totalSeconds = initialTotal + additionalSeconds;
                var totalHours = Math.floor(totalSeconds / 3600);
                var totalMinutes = Math.floor((totalSeconds % 3600) / 60);
                total.textContent = totalHours + 'h' + (totalMinutes ? ' ' + totalMinutes + 'm' : '');
                if (average && averageDays) {
                    var averageSeconds = Math.round(totalSeconds / averageDays);
                    var averageHours = Math.floor(averageSeconds / 3600);
                    var averageMinutes = Math.floor((averageSeconds % 3600) / 60);
                    average.textContent = averageHours + 'h' + (averageMinutes ? ' ' + averageMinutes + 'm' : '');
                }
            }
        }

        updateElapsedTimes();
        window.setInterval(updateElapsedTimes, 1000);
    })();
</script>

<?php include '../../includes/footer.php'; ?>
