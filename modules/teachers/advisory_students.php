<?php
// modules/teachers/advisory_students.php
// Teacher Portal -> ADVISORY -> My Advisory Students
//
// The students of the ONE section this teacher advises, resolved from the
// existing relationship (nothing is stored separately):
//   users (logged-in teacher)
//     -> sections.adviser_id            (adviser assignment)
//     -> enrollments (active SY, Approved, section_id)
//     -> students
// Subject-teacher classes (teacher_subjects) are NOT used to pick students,
// so a teacher who teaches Math in many sections still sees only the
// advisory section here. "My Teaching Load" stays in Grading.
//
// READ-ONLY monitoring page: nothing here writes a grade, score or record.
// Grades come from the official `grades` rows (same as SF9 via rpYearResult),
// so an adviser can view but never edit another teacher's grades.
//
//   ?                -> list of advisory students (with search)
//   ?student_id=N    -> one advisory student's details. The server checks
//                       that N is enrolled in THIS teacher's advisory
//                       section; any other id is refused.

require_once __DIR__ . '/../../config/database.php';
if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';
require_once __DIR__ . '/../../includes/risk_helpers.php';        // getAtRiskSubjectRecords()
require_once __DIR__ . '/../../includes/attendance_helpers.php';  // gh_getStudentAttendanceSummaryAll()
require_once __DIR__ . '/../reports/report_functions.php';        // rpYearResult() -- the same rows SF9 shows

if (!hasRole('Teacher')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'My Advisory Students';
$current_page = 'advisory_students';
$base_url = '../../';

$teacher_id = (int)($_SESSION['user_id'] ?? 0);
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1") ?: null;
$sy_id = (int)($active_sy['sy_id'] ?? 0);

// Adviser assignment (one advisory class per teacher -- sections.adviser_id).
$adv = $teacher_id ? (getRecord(
    "SELECT section_id, section_name, grade_level, strand FROM sections WHERE adviser_id = ? AND is_active = 1 ORDER BY section_id LIMIT 1",
    [$teacher_id]) ?: null) : null;

// Not a class adviser -> this page does not exist for them (the sidebar hides
// the link too). Server-side, so typing the URL does not open it either.
if (!$adv) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

/** Current learners of the advisory section (Approved enrollment, active SY). */
function asStudents($section_id, $sy_id, $student_id = 0) {
    return getRecords(
        "SELECT s.student_id, s.lrn, s.first_name, s.middle_name, s.last_name, s.gender, s.enrollment_status AS student_status,
                e.enrollment_id, e.enrollment_status, e.grade_level
           FROM enrollments e
           JOIN students s ON s.student_id = e.student_id AND s.is_active = 1
          WHERE e.section_id = ? AND e.school_year_id = ? AND e.enrollment_status = 'Approved'"
          . ($student_id ? " AND s.student_id = ?" : "") . "
          ORDER BY s.last_name, s.first_name",
        $student_id ? [(int)$section_id, (int)$sy_id, (int)$student_id] : [(int)$section_id, (int)$sy_id]
    ) ?: [];
}
function asName(array $s) {
    $mid = trim((string)($s['middle_name'] ?? ''));
    return trim($s['first_name'] . ' ' . ($mid !== '' ? mb_substr($mid, 0, 1) . '. ' : '') . $s['last_name']);
}
function asMaskLrn($lrn) {
    $lrn = (string)$lrn;
    return strlen($lrn) > 4 ? str_repeat('*', 8) . substr($lrn, -4) : $lrn;
}
/** Student's own status word, as the Student pages show it (Enrolled, ...). */
function asEnrollmentLabel(array $s) {
    $v = trim((string)($s['student_status'] ?? ''));
    return $v !== '' ? $v : 'Enrolled';
}

// ---------- the section's current term and its subjects (read-only) ----------
$term = '';
$subjects = [];
$finalized = [];
$grade_of = [];   // [student_id][subject_code] => official term grade
if ($adv && $sy_id) {
    $term = 'Term 3';
    foreach (gh_threeTerms() as $t) {
        if (!gh_isSectionTermCompleted((int)$adv['section_id'], $sy_id, $t)) { $term = $t; break; }
    }
    // Subjects taught to the section this term (JHS: no term; SHS: this term only).
    $subjects = getRecords(
        "SELECT DISTINCT sub.subject_code, sub.subject_name
           FROM teacher_subjects ts JOIN subjects sub ON sub.subject_code = ts.subject_code
          WHERE ts.section_id = ? AND ts.school_year_id = ? AND sub.is_active = 1
            AND (sub.term IS NULL OR sub.term = '' OR sub.term = ?)
          ORDER BY sub.subject_name", [(int)$adv['section_id'], $sy_id, $term]) ?: [];
    gh_ensureTermFinalizationsTable();
    foreach (getRecords(
        "SELECT DISTINCT subject_code FROM term_finalizations WHERE section_id = ? AND school_year_id = ? AND term = ?",
        [(int)$adv['section_id'], $sy_id, $term]) ?: [] as $f) {
        $finalized[$f['subject_code']] = true;
    }
    foreach (getRecords(
        "SELECT student_id, subject_code, term, transmuted_grade, remarks FROM grades
          WHERE section_id = ? AND school_year_id = ? AND term = ?", [(int)$adv['section_id'], $sy_id, $term]) ?: [] as $g) {
        if (gh_isValidGradeRow($g)) $grade_of[(int)$g['student_id']][$g['subject_code']] = (int)round((float)$g['transmuted_grade']);
    }
}
/** Subjects of this term the learner still has no official grade in. */
function asPending($student_id, array $subjects, array $grade_of) {
    $p = [];
    foreach ($subjects as $sub) if (!isset($grade_of[(int)$student_id][$sub['subject_code']])) $p[] = $sub['subject_name'];
    return $p;
}

// ---------- single student view (server-side adviser check) ----------
$view_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$view = null;
$view_error = '';
if ($view_id > 0) {
    $rows = ($adv && $sy_id) ? asStudents((int)$adv['section_id'], $sy_id, $view_id) : [];
    if (!$rows) {
        http_response_code(403);
        $view_error = 'You can only view students of your own advisory class.';
    } else {
        $view = $rows[0];
    }
}

include '../../includes/header.php';
?>
<style>
.adv-page { padding: 4px 0 24px; }
.adv-page .adv-title { font-size: 22px; font-weight: 700; color: var(--ink); margin: 0 0 4px; display: flex; align-items: center; gap: 10px; }
.adv-page .adv-sub { color: var(--text-muted); font-size: 15px; margin: 0 0 16px; }
.adv-page .adv-meta { display: flex; flex-wrap: wrap; gap: 10px 28px; background: var(--surface); border: 1px solid var(--border); border-left: 5px solid #14213D; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px; font-size: 15.5px; color: var(--ink); }
.adv-page .adv-meta b { font-weight: 700; }
.adv-page .adv-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; }
.adv-page .adv-card h6 { font-size: 13.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); margin: 0 0 10px; }
.adv-page .adv-search { width: 100%; max-width: 380px; height: 44px; font-size: 15px; border-radius: 8px; margin-bottom: 12px; }
.adv-page table { width: 100%; border-collapse: collapse; font-size: 15px; }
.adv-page th { text-align: left; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); font-weight: 600; padding: 10px; border-bottom: 1px solid var(--border); white-space: nowrap; }
.adv-page td { padding: 11px 10px; border-bottom: 1px solid var(--border-soft, #eef0f3); vertical-align: middle; }
.adv-page .adv-name { font-weight: 600; color: var(--ink); }
.adv-page .adv-lrn { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 14px; color: var(--text-muted); }
.adv-page .pill { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 13.5px; font-weight: 600; white-space: nowrap; }
.adv-page .pill.ok { background: #E7F6EE; color: #0F7B4E; } .adv-page .pill.wait { background: #FFF4E0; color: #9A5B00; }
.adv-page .pill.bad { background: #FDECEA; color: #B42318; } .adv-page .pill.none { background: var(--surface-hover); color: var(--text-muted); }
.adv-page .btn-view, .adv-page .adv-actions a { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 8px; font-size: 14.5px; font-weight: 500; text-decoration: none; border: 1px solid var(--border); color: var(--ink); background: var(--surface); white-space: nowrap; }
.adv-page .btn-view.primary, .adv-page .adv-actions a.primary { background: #14213D; color: #fff; border-color: #14213D; }
.adv-page .adv-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.adv-page .adv-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
.adv-page .kv { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-bottom: 1px solid var(--border-soft, #eef0f3); font-size: 15px; }
.adv-page .kv span:first-child { color: var(--text-muted); }
.adv-page .adv-empty { background: var(--surface); border: 1px dashed var(--border); border-radius: 12px; padding: 22px; text-align: center; color: var(--text-muted); font-size: 15.5px; }
.adv-page .table-wrap { overflow-x: auto; }
@media (max-width: 700px) {
    .adv-page .adv-list thead { display: none; }
    .adv-page .adv-list tr { display: block; padding: 10px 0; border-bottom: 1px solid var(--border-soft, #eef0f3); }
    .adv-page .adv-list td { display: flex; justify-content: space-between; gap: 10px; border: none; padding: 4px 2px; }
    .adv-page .adv-list td::before { content: attr(data-label); color: var(--text-muted); font-size: 13.5px; }
}
</style>

<div class="adv-page">
<?php if (!$adv): ?>
    <h2 class="adv-title"><i class="fas fa-users"></i> My Advisory Students</h2>
    <div class="adv-empty"><i class="fas fa-info-circle me-1"></i> <b>No advisory class assigned.</b><br>
        Only the class adviser of a section sees its students here. Your subject classes are in Grading.</div>

<?php elseif ($view_error !== ''): ?>
    <h2 class="adv-title"><i class="fas fa-users"></i> My Advisory Students</h2>
    <div class="alert alert-danger"><?php echo htmlspecialchars($view_error); ?></div>
    <a class="btn-view" href="advisory_students.php"><i class="fas fa-arrow-left"></i> Back to My Advisory Students</a>

<?php elseif ($view): ?>
    <?php
    // ---------- one advisory student: read-only monitoring ----------
    $res = rpYearResult((int)$view['student_id'], (int)$adv['section_id'], $adv['grade_level']);   // same data SF9 uses
    $terms = gh_threeTerms();
    $pending = asPending((int)$view['student_id'], $subjects, $grade_of);
    $att_start = !empty($active_sy['start_date']) ? $active_sy['start_date'] : date('Y-m-d', strtotime('-180 days'));
    $att = gh_getStudentAttendanceSummaryAll((int)$view['student_id'], $att_start, date('Y-m-d'));
    $risk = getAtRiskSubjectRecords((int)$view['student_id'], $sy_id) ?: [];
    $rep = BASE_URL . 'modules/reports/generate.php?student_id=' . urlencode($view['lrn']) . '&report_type=';
    ?>
    <a class="btn-view mb-3" href="advisory_students.php"><i class="fas fa-arrow-left"></i> Back to My Advisory Students</a>
    <h2 class="adv-title mt-3"><i class="fas fa-user"></i> <?php echo htmlspecialchars(asName($view)); ?></h2>
    <p class="adv-sub">Advisory student &middot; view only &mdash; grades are encoded and finalized by each subject teacher.</p>

    <div class="adv-actions">
        <a class="primary" target="_blank" href="<?php echo htmlspecialchars($rep . 'sf9'); ?>"><i class="fas fa-id-card"></i> Generate SF9</a>
        <a target="_blank" href="<?php echo htmlspecialchars($rep . 'at_risk_student'); ?>"><i class="fas fa-exclamation-triangle"></i> At-Risk Student Report</a>
        <a href="#adv-grades"><i class="fas fa-list-ol"></i> View Grades</a>
        <a href="#adv-att"><i class="fas fa-calendar-check"></i> View Attendance</a>
    </div>

    <div class="adv-grid">
        <div class="adv-card">
            <h6>Student</h6>
            <div class="kv"><span>LRN</span><span class="adv-lrn"><?php echo htmlspecialchars($view['lrn']); ?></span></div>
            <div class="kv"><span>Grade &amp; Section</span><span><?php echo htmlspecialchars($adv['grade_level'] . ' - ' . $adv['section_name']); ?></span></div>
            <div class="kv"><span>School Year</span><span><?php echo htmlspecialchars($active_sy['sy_name'] ?? ''); ?></span></div>
            <div class="kv"><span>Enrollment Status</span><span><span class="pill ok"><?php echo htmlspecialchars(asEnrollmentLabel($view)); ?></span></span></div>
        </div>
        <div class="adv-card">
            <h6>Grades &mdash; <?php echo htmlspecialchars($term); ?></h6>
            <div class="kv"><span>Grade status</span><span>
                <?php if (!$subjects): ?><span class="pill none">No subjects yet</span>
                <?php elseif (!$pending): ?><span class="pill ok">Complete</span>
                <?php else: ?><span class="pill wait"><?php echo count($pending) . ' Subject' . (count($pending) === 1 ? '' : 's') . ' Pending'; ?></span><?php endif; ?>
            </span></div>
            <div class="kv"><span>Subjects finalized by teachers</span><span><?php echo count(array_intersect_key($finalized, array_flip(array_column($subjects, 'subject_code')))); ?> of <?php echo count($subjects); ?></span></div>
            <div class="kv"><span>Academic At-Risk</span><span><?php echo $risk ? '<span class="pill bad">Yes (' . count($risk) . ')</span>' : '<span class="pill ok">No</span>'; ?></span></div>
            <div class="kv"><span>Year status</span><span><?php echo htmlspecialchars($res['action'] === 'Incomplete' ? 'In progress' : $res['action']); ?></span></div>
        </div>
        <div class="adv-card" id="adv-att">
            <h6>Attendance (since <?php echo htmlspecialchars(date('M d, Y', strtotime($att_start))); ?>)</h6>
            <div class="kv"><span>Present / Late / Absent</span><span><?php echo (int)$att['present'] . ' / ' . (int)$att['late'] . ' / ' . (int)$att['absent']; ?></span></div>
            <div class="kv"><span>Attendance rate</span><span><?php echo $att['percentage'] !== null ? htmlspecialchars((string)$att['percentage']) . '%' : '&mdash;'; ?></span></div>
            <div class="kv"><span>Attendance At-Risk</span><span><?php echo !empty($att['at_risk']) ? '<span class="pill bad">Yes</span>' : '<span class="pill ok">No</span>'; ?></span></div>
            <?php if (!empty($att['reason'])): ?><div class="kv"><span>Reason</span><span style="text-align:right"><?php echo htmlspecialchars($att['reason']); ?></span></div><?php endif; ?>
        </div>
    </div>

    <?php if ($risk): ?>
        <div class="adv-card">
            <h6>Academic At-Risk subjects</h6>
            <div class="table-wrap"><table>
                <thead><tr><th>Subject</th><th>Term</th><th>Grade</th><th>Reason</th></tr></thead>
                <tbody>
                <?php foreach ($risk as $r): ?>
                    <tr><td><?php echo htmlspecialchars($r['subject_name']); ?></td><td><?php echo htmlspecialchars($r['term']); ?></td>
                        <td><?php echo ((float)$r['final_grade'] > 0) ? (int)round((float)$r['final_grade']) : 'INC'; ?></td>
                        <td><?php echo htmlspecialchars((string)($r['reason'] ?? '')); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    <?php endif; ?>

    <div class="adv-card" id="adv-grades">
        <h6>Grades this school year (official, finalized by subject teachers)</h6>
        <div class="table-wrap"><table>
            <thead><tr><th>Learning Area</th><?php foreach ($terms as $t): ?><th style="text-align:center"><?php echo htmlspecialchars($t); ?></th><?php endforeach; ?><th style="text-align:center">Final Grade</th><th style="text-align:center">Remarks</th></tr></thead>
            <tbody>
            <?php foreach ($res['subjects'] as $sub): ?>
                <tr>
                    <td><?php echo htmlspecialchars($sub['name']); ?></td>
                    <?php foreach ($terms as $t): $g = $sub['terms'][$t] ?? null; ?>
                        <td style="text-align:center"><?php echo $g !== null ? (int)round($g) : (in_array($t, $sub['valid_terms'], true) ? '' : '<span style="color:var(--text-muted)">n/a</span>'); ?></td>
                    <?php endforeach; ?>
                    <td style="text-align:center;font-weight:700"><?php echo $sub['final'] !== null ? (int)round($sub['final']) : ''; ?></td>
                    <td style="text-align:center"><?php echo $sub['complete'] ? ($sub['passed'] ? '<span class="pill ok">Passed</span>' : '<span class="pill bad">Failed</span>') : ''; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$res['subjects']): ?><tr><td colspan="<?php echo count($terms) + 3; ?>" style="text-align:center;color:var(--text-muted)">No learning areas or grades yet.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <p class="adv-sub" style="margin:10px 0 0;font-size:14px;">Blank = not finalized yet by the subject teacher. Final Grade appears after all terms are graded. This page cannot change grades.</p>
    </div>

<?php else: ?>
    <?php $students = $sy_id ? asStudents((int)$adv['section_id'], $sy_id) : []; ?>
    <h2 class="adv-title"><i class="fas fa-users"></i> My Advisory Students</h2>
    <p class="adv-sub">Students of the section you advise. Updated automatically from enrollment.</p>
    <div class="adv-meta">
        <span><b>Advisory Class:</b> <?php echo htmlspecialchars($adv['grade_level'] . ' - ' . $adv['section_name']); ?></span>
        <span><b>School Year:</b> <?php echo htmlspecialchars($active_sy['sy_name'] ?? '—'); ?></span>
        <span><b>Total Students:</b> <?php echo count($students); ?></span>
        <span><b>Grade status for:</b> <?php echo htmlspecialchars($term); ?></span>
    </div>

    <?php if (!$students): ?>
        <div class="adv-empty">No students are enrolled in your advisory class yet.</div>
    <?php else: ?>
        <div class="adv-card">
            <input type="search" id="advSearch" class="form-control adv-search" placeholder="Search advisory student... (name or LRN)" aria-label="Search advisory student">
            <div class="table-wrap">
                <table class="adv-list">
                    <thead><tr><th>Student</th><th>LRN</th><th>Grade &amp; Section</th><th>Enrollment Status</th><th>Grade Status (<?php echo htmlspecialchars($term); ?>)</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($students as $st):
                        $pend = asPending((int)$st['student_id'], $subjects, $grade_of); ?>
                        <tr data-search="<?php echo htmlspecialchars(strtolower(asName($st) . ' ' . $st['last_name'] . ', ' . $st['first_name'] . ' ' . $st['lrn'])); ?>">
                            <td data-label="Student" class="adv-name"><?php echo htmlspecialchars(asName($st)); ?></td>
                            <td data-label="LRN" class="adv-lrn"><?php echo htmlspecialchars(asMaskLrn($st['lrn'])); ?></td>
                            <td data-label="Grade &amp; Section"><?php echo htmlspecialchars($adv['grade_level'] . ' - ' . $adv['section_name']); ?></td>
                            <td data-label="Enrollment Status"><span class="pill ok"><?php echo htmlspecialchars(asEnrollmentLabel($st)); ?></span></td>
                            <td data-label="Grade Status">
                                <?php if (!$subjects): ?><span class="pill none">No subjects yet</span>
                                <?php elseif (!$pend): ?><span class="pill ok">Complete</span>
                                <?php else: ?><span class="pill wait" title="<?php echo htmlspecialchars(implode(', ', $pend)); ?>"><?php echo count($pend) . ' Subject' . (count($pend) === 1 ? '' : 's') . ' Pending'; ?></span><?php endif; ?>
                            </td>
                            <td data-label="" style="text-align:right"><a class="btn-view primary" href="advisory_students.php?student_id=<?php echo (int)$st['student_id']; ?>"><i class="fas fa-eye"></i> View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="advNoMatch" style="display:none"><td colspan="6" style="text-align:center;color:var(--text-muted)">No advisory student matches your search.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <script>
        // Filters only the rows already on this page (your advisory students).
        (function () {
            var input = document.getElementById('advSearch');
            if (!input) return;
            var rows = Array.prototype.slice.call(document.querySelectorAll('.adv-list tbody tr[data-search]'));
            var none = document.getElementById('advNoMatch');
            input.addEventListener('input', function () {
                var q = input.value.trim().toLowerCase(), shown = 0;
                rows.forEach(function (r) { var ok = !q || r.getAttribute('data-search').indexOf(q) !== -1; r.style.display = ok ? '' : 'none'; if (ok) shown++; });
                none.style.display = shown ? 'none' : '';
            });
        })();
        </script>
    <?php endif; ?>
<?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
