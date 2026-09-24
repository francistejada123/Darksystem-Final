<?php
// ============================================================
// >>> THIS FILE GOES IN:  modules/attendance/history.php  <<<
// ============================================================
// modules/attendance/history.php
// Attendance History / Records — for ONE Grade Level + Section + Subject +
// Term, reached from the "View History" button on take.php. Two views in
// one page: a Class-Wide Log (every enrolled student x every date that has
// a recorded session, filterable by date range) and a Per-Student History
// (one student's day-by-day log plus their term summary).
//
// Read-only reporting. Never touches grades -- Written Works / Performance
// Tasks / Examinations (see includes/grading_helpers.php) remain the only
// official grading components. See includes/attendance_helpers.php.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php'; // getActiveTerms(), gh_getTermWindow()
require_once __DIR__ . '/../../includes/attendance_helpers.php';
require_once __DIR__ . '/../../includes/attendance_qr_helpers.php'; // adds the Time In column on first use (attQrEnsureSchema)

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Teacher'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

attQrEnsureSchema(); // the per-student log below reads attendance.time_in
$page_title = 'Attendance History';
$current_page = 'attendance_monitoring';
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

// Every subject/section THIS teacher actually teaches -- same source
// take.php uses, so the Class selector here matches exactly.
$my_assignments = getRecords("
    SELECT ts.subject_code, sub.subject_name, ts.section_id, sec.section_name
    FROM teacher_subjects ts
    JOIN subjects sub ON sub.subject_code = ts.subject_code
    JOIN sections sec ON sec.section_id = ts.section_id
    WHERE ts.teacher_id = ? AND ts.school_year_id = ?
    ORDER BY sec.section_name, sub.subject_name
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

$grade = isset($_GET['grade']) && $_GET['grade'] !== '' ? sanitize($_GET['grade']) : ($section_info['grade_level'] ?? '');

$today = date('Y-m-d');

// Date range -- defaults to the term's own Start Date (Manage Assessment
// Items) through today, same convention as take.php's "this term so far".
// The teacher can widen/narrow it with the date inputs below.
$term_window = gh_getTermWindow($active_sy['sy_id'], $term);
$default_start = $term_window['start_date'] ?? ($active_sy['start_date'] ?? date('Y-m-d', strtotime('-90 days')));
$start_date = isset($_GET['start_date']) ? sanitize($_GET['start_date']) : $default_start;
$end_date = isset($_GET['end_date']) ? sanitize($_GET['end_date']) : $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) { $start_date = $default_start; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) { $end_date = $today; }
if ($end_date > $today) { $end_date = $today; }
if ($start_date > $end_date) { $start_date = $end_date; }

$tab = (isset($_GET['tab']) && $_GET['tab'] === 'student') ? 'student' : 'class';

$section_students = getRecords("
    SELECT s.student_id, s.lrn, s.first_name, s.last_name
    FROM students s
    INNER JOIN enrollments e ON s.student_id = e.student_id
    WHERE e.section_id = ?
    AND e.school_year_id = ?
    AND e.enrollment_status = 'Approved'
    AND s.is_active = 1
    ORDER BY s.last_name, s.first_name
", [$section_id, $active_sy['sy_id']]);

// ============================================================
// CLASS-WIDE LOG -- every enrolled student x every DATE that actually has
// at least one recorded session for this subject/section in range (not
// every calendar day -- a day nobody took attendance on isn't a column).
// ============================================================
$log_dates = [];
$log_grid = []; // [student_id][date] => status
if ($tab === 'class') {
    $date_rows = getRecords(
        "SELECT DISTINCT date FROM attendance
         WHERE subject_code = ? AND section_id = ? AND date >= ? AND date <= ?
         ORDER BY date ASC",
        [$subject_code, $section_id, $start_date, $end_date]
    );
    $log_dates = array_column($date_rows, 'date');

    if (!empty($log_dates)) {
        $all_rows = getRecords(
            "SELECT student_id, date, status FROM attendance
             WHERE subject_code = ? AND section_id = ? AND date >= ? AND date <= ?",
            [$subject_code, $section_id, $start_date, $end_date]
        );
        foreach ($all_rows as $row) {
            $log_grid[(int) $row['student_id']][$row['date']] = $row['status'];
        }
    }
}

// ============================================================
// PER-STUDENT HISTORY -- one student's day-by-day log + term summary.
// ============================================================
$selected_student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$student_log = [];
$student_summary = null;
$selected_student = null;
if ($tab === 'student') {
    if ($selected_student_id === 0 && !empty($section_students)) {
        $selected_student_id = (int) $section_students[0]['student_id'];
    }
    foreach ($section_students as $s) {
        if ((int) $s['student_id'] === $selected_student_id) {
            $selected_student = $s;
            break;
        }
    }
    if ($selected_student) {
        $student_log = getRecords(
            "SELECT date, status, remarks, time_in FROM attendance
             WHERE student_id = ? AND subject_code = ? AND section_id = ? AND date >= ? AND date <= ?
             ORDER BY date DESC",
            [$selected_student_id, $subject_code, $section_id, $start_date, $end_date]
        );
        $student_summary = gh_getStudentAttendanceSummary($selected_student_id, $subject_code, $section_id, $start_date, $end_date);
    }
}

$status_badge_class = [
    'Present' => 'bg-success',
    'Late' => 'bg-warning text-dark',
    'Absent' => 'bg-danger',
    'Excused' => 'bg-info text-dark',
];
$status_letter = ['Present' => 'P', 'Late' => 'L', 'Absent' => 'A', 'Excused' => 'E'];

include '../../includes/header.php';
?>

<style>
    .hist-grid-table th, .hist-grid-table td { white-space: nowrap; text-align: center; font-size: 14px; }
    .hist-grid-table th:first-child, .hist-grid-table td:first-child { text-align: left; white-space: normal; }
    .hist-status-dot { display:inline-flex; align-items:center; justify-content:center; width:26px; height:24px; border-radius:5px; font-weight:700; font-size:13px; line-height:1; color:#fff; text-align:center; }
    .hist-status-dot.st-present { background:#238863; }
    .hist-status-dot.st-late { background:#B7791F; }
    .hist-status-dot.st-absent { background:#C94A4A; }
    .hist-status-dot.st-excused { background:#356EA8; }
    .hist-legend span { display:inline-flex; align-items:center; gap:6px; margin-right:14px; font-size:13px; color: var(--text-muted); }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 mt-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-1" style="color: var(--ink);font-weight:600;">Attendance History</h4>
        <small style="color: var(--text-muted);">
            <?php echo htmlspecialchars($section_info['section_name']); ?> ·
            <?php echo htmlspecialchars($subject_info['subject_name']); ?> ·
            <?php echo htmlspecialchars($term); ?>
        </small>
    </div>
    <a href="take.php?section_id=<?php echo $section_id; ?>&subject_code=<?php echo urlencode($subject_code); ?>&term=<?php echo urlencode($term); ?>&grade=<?php echo urlencode($grade); ?>" class="btn btn-sm" style="border: 1px solid var(--border);color: var(--text-muted);background: var(--surface);">
        <i class="fas fa-arrow-left me-1"></i> Back to Take Attendance
    </a>
</div>

<div style="background:#EDF4FB;border: 1px solid var(--border);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color: var(--text-muted);">
    Read-only records of what was already saved in Take Attendance. This never adds to or subtracts from Written Works, Performance Tasks, or Examinations — it only feeds <a href="<?php echo BASE_URL; ?>modules/ai/index.php" style="color: var(--ink);">AI Analytics</a> as an additional risk indicator.
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'class' ? 'active' : ''; ?>" href="?section_id=<?php echo $section_id; ?>&subject_code=<?php echo urlencode($subject_code); ?>&term=<?php echo urlencode($term); ?>&grade=<?php echo urlencode($grade); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&tab=class">
            <i class="fas fa-table-list me-1"></i> Class-Wide Log
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'student' ? 'active' : ''; ?>" href="?section_id=<?php echo $section_id; ?>&subject_code=<?php echo urlencode($subject_code); ?>&term=<?php echo urlencode($term); ?>&grade=<?php echo urlencode($grade); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&tab=student&student_id=<?php echo $selected_student_id; ?>">
            <i class="fas fa-user me-1"></i> Per-Student History
        </a>
    </li>
</ul>

<div class="card shadow-sm rounded-3 mb-3">
    <div class="card-body py-3">
        <form method="GET" class="d-flex align-items-end flex-wrap gap-2">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
            <div>
                <label class="form-label mb-1" style="font-size:12px;color: var(--text-muted);">Class</label>
                <select name="class_pick" class="form-select form-select-sm" style="font-size:14px;border: 1px solid var(--border);border-radius:6px;" onchange="var v=this.value.split('|'); location.href='?section_id='+v[0]+'&subject_code='+encodeURIComponent(v[1])+'&term=<?php echo urlencode($term); ?>&tab=<?php echo urlencode($tab); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>';">
                    <?php foreach ($my_assignments as $a): ?>
                        <option value="<?php echo $a['section_id']; ?>|<?php echo htmlspecialchars($a['subject_code']); ?>"
                            <?php echo ((int) $a['section_id'] === $section_id && $a['subject_code'] === $subject_code) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($a['section_name'] . ' — ' . $a['subject_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label mb-1" style="font-size:12px;color: var(--text-muted);">Term</label>
                <select name="term" class="form-select form-select-sm" style="font-size:14px;border: 1px solid var(--border);border-radius:6px;" onchange="this.form.submit()">
                    <?php foreach ($terms as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo $term == $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label mb-1" style="font-size:12px;color: var(--text-muted);">From</label>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" max="<?php echo $today; ?>" class="form-control form-control-sm" style="font-size:14px;border: 1px solid var(--border);border-radius:6px;">
            </div>
            <div>
                <label class="form-label mb-1" style="font-size:12px;color: var(--text-muted);">To</label>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" max="<?php echo $today; ?>" class="form-control form-control-sm" style="font-size:14px;border: 1px solid var(--border);border-radius:6px;">
            </div>
            <?php if ($tab === 'student'): ?>
                <div>
                    <label class="form-label mb-1" style="font-size:12px;color: var(--text-muted);">Student</label>
                    <select name="student_id" class="form-select form-select-sm" style="font-size:14px;border: 1px solid var(--border);border-radius:6px;">
                        <?php foreach ($section_students as $s): ?>
                            <option value="<?php echo $s['student_id']; ?>" <?php echo ((int) $s['student_id'] === $selected_student_id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['last_name'] . ', ' . $s['first_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <input type="hidden" name="section_id" value="<?php echo $section_id; ?>">
            <input type="hidden" name="subject_code" value="<?php echo htmlspecialchars($subject_code); ?>">
            <input type="hidden" name="grade" value="<?php echo htmlspecialchars($grade); ?>">
            <button type="submit" class="btn btn-sm" style="background:#14213D;color:#fff;border:none;">
                <i class="fas fa-filter me-1"></i> Apply
            </button>
        </form>
    </div>
</div>

<div class="hist-legend mb-3">
    <span><span class="hist-status-dot st-present">P</span> Present</span>
    <span><span class="hist-status-dot st-late">L</span> Late</span>
    <span><span class="hist-status-dot st-absent">A</span> Absent</span>
    <span><span class="hist-status-dot st-excused">E</span> Excused</span>
</div>

<?php if ($tab === 'class'): ?>
    <!-- ==================== CLASS-WIDE LOG ==================== -->
    <div class="card shadow-sm rounded-3">
        <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0" style="font-weight:600;color: var(--ink);">
                <?php echo date('M j, Y', strtotime($start_date)); ?> – <?php echo date('M j, Y', strtotime($end_date)); ?>
            </h6>
            <span class="badge bg-secondary"><?php echo count($log_dates); ?> session date<?php echo count($log_dates) === 1 ? '' : 's'; ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($section_students)): ?>
                <div class="text-center py-4 text-muted">No approved, active students enrolled in this section yet.</div>
            <?php elseif (empty($log_dates)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-calendar-xmark fa-2x d-block mb-2"></i>
                    No attendance was recorded for this class/subject within this date range yet.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm hist-grid-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <?php foreach ($log_dates as $d): ?>
                                    <th><?php echo date('M j', strtotime($d)); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($section_students as $s): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($s['last_name'] . ', ' . $s['first_name']); ?></td>
                                    <?php foreach ($log_dates as $d): ?>
                                        <?php $st = $log_grid[(int) $s['student_id']][$d] ?? null; ?>
                                        <td>
                                            <?php if ($st): ?>
                                                <span class="hist-status-dot st-<?php echo strtolower($st); ?>" title="<?php echo htmlspecialchars($st); ?>"><?php echo $status_letter[$st]; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <!-- ==================== PER-STUDENT HISTORY ==================== -->
    <?php if (!$selected_student): ?>
        <div class="alert alert-warning">No approved, active students enrolled in this section yet.</div>
    <?php else: ?>
        <div class="row g-3 mb-3">
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm rounded-3 text-center py-3">
                    <div class="fw-bold" style="font-size:26px;color:#238863;"><?php echo (int) $student_summary['present']; ?></div>
                    <div class="text-muted" style="font-size:13px;">Present</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm rounded-3 text-center py-3">
                    <div class="fw-bold" style="font-size:26px;color:#B7791F;"><?php echo (int) $student_summary['late']; ?></div>
                    <div class="text-muted" style="font-size:13px;">Late</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm rounded-3 text-center py-3">
                    <div class="fw-bold" style="font-size:26px;color:#C94A4A;"><?php echo (int) $student_summary['absent']; ?></div>
                    <div class="text-muted" style="font-size:13px;">Absent</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm rounded-3 text-center py-3">
                    <div class="fw-bold" style="font-size:26px;<?php echo $student_summary['at_risk'] ? 'color:#C94A4A;' : 'color:#238863;'; ?>">
                        <?php echo $student_summary['percentage'] !== null ? number_format($student_summary['percentage'], 1) . '%' : '—'; ?>
                    </div>
                    <div class="text-muted" style="font-size:13px;">Attendance %</div>
                </div>
            </div>
        </div>

        <?php if (!empty($student_summary['at_risk'])): ?>
            <div class="alert alert-danger d-flex align-items-start gap-2">
                <i class="fas fa-triangle-exclamation mt-1"></i>
                <div><?php echo htmlspecialchars($student_summary['reason']); ?> (shown in AI Analytics as an Attendance Risk indicator — never affects the Term Grade.)</div>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm rounded-3">
            <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0" style="font-weight:600;color: var(--ink);">
                    <?php echo htmlspecialchars($selected_student['last_name'] . ', ' . $selected_student['first_name']); ?>
                    <span class="text-muted small ms-1">(<?php echo htmlspecialchars($selected_student['lrn']); ?>)</span>
                </h6>
                <span class="badge bg-secondary"><?php echo count($student_log); ?> record<?php echo count($student_log) === 1 ? '' : 's'; ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($student_log)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-calendar-xmark fa-2x d-block mb-2"></i>
                        No attendance recorded for this student in this date range yet.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Time In</th>
                                    <th>Status</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($student_log as $row): ?>
                                    <tr>
                                        <td><?php echo date('l, M j, Y', strtotime($row['date'])); ?></td>
                                        <td><?php echo !empty($row['time_in']) ? htmlspecialchars(attQrFormatTime($row['time_in'])) : '<span class="text-muted">—</span>'; ?></td>
                                        <td><span class="badge <?php echo $status_badge_class[$row['status']] ?? 'bg-secondary'; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                                        <td><?php echo $row['remarks'] ? htmlspecialchars($row['remarks']) : '<span class="text-muted">—</span>'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>