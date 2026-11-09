<?php
// modules/reports/index.php
// Reports landing page â€” the Live Reports dashboard + the report
// generation form. Actual report output happens in generate.php
// (opened in a new tab), so this page never has to render a full
// report inline; it just stays the dashboard.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Registrar', 'Teacher'])) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'Reports';
$current_page = 'reports';
$base_url = '../../';

// Get active school year
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");

// Get sections for filtering (a Teacher: only the sections they advise)
$rep_all = hasAnyRole(['Administrator', 'Registrar']);
$sections = $rep_all
    ? getRecords("SELECT section_id, section_name, grade_level FROM sections WHERE is_active = 1
                  ORDER BY CAST(REPLACE(grade_level, 'Grade ', '') AS UNSIGNED), section_name")
    : getRecords("SELECT section_id, section_name, grade_level FROM sections WHERE is_active = 1 AND adviser_id = ?
                  ORDER BY CAST(REPLACE(grade_level, 'Grade ', '') AS UNSIGNED), section_name", [(int)($_SESSION['user_id'] ?? 0)]);

// What each report is for -- shown under the Report Type box.
$report_help = [
    'sf1' => ['DepEd Form', 'SF1 - School Register', 'Official list of the learners enrolled in a section this school year (males first, then females). Used at the start of the year and submitted to DepEd (LIS).', 'Section (or Grade Level)'],
    'sf5' => ['DepEd Form', 'SF5 - Promotion & Level of Proficiency', 'End-of-year result per section: General Average, descriptor, Completed / Promoted or Not Promoted status, with summary tables by sex. Submitted to the Division.', 'Section'],
    'sf9' => ['DepEd Form', "SF9 - Learner's Report Card", 'Given to the parent every term and at year end: grades per term, Final Grade, General Average, and attendance per month.', 'Learner (Name of My Advisory Students)'],
    'grade_summary' => ['Monitoring', 'Grade Summary', 'Per-term check of a section: each learner\'s term averages and the subjects currently below passing, so the adviser/principal can act before the year ends.', 'Section (or Grade Level)'],
    'at_risk_student' => ['Intervention', 'At-Risk Student Report', 'One learner\'s weak subjects this school year with recommended actions (conference, remedial classes, guidance, parent coordination).', 'Learner (Name of My Advisory Students)'],
];

// Learners for the individual reports (SF9 / At-Risk Student).
//   Teacher -> ONLY the students of the section they ADVISE:
//              users -> sections.adviser_id -> enrollments (active SY, Approved) -> students.
//              Subject-teacher classes (teacher_subjects) are never used here.
//   Administrator / Registrar -> every enrolled learner this school year.
// generate.php re-checks the same adviser rule (rpCanStudent) on the server.
$rep_adv_section = null;
$rep_students = [];
if (!$rep_all) {
    $rep_adv_section = getRecord(
        "SELECT section_id, section_name, grade_level FROM sections WHERE adviser_id = ? AND is_active = 1 ORDER BY section_id LIMIT 1",
        [(int)($_SESSION['user_id'] ?? 0)]) ?: null;
}
if (!empty($active_sy['sy_id']) && ($rep_all || $rep_adv_section)) {
    $rep_students = getRecords(
        "SELECT s.lrn, s.last_name, s.first_name, s.middle_name, e.grade_level, sec.section_name
           FROM enrollments e
           JOIN students s ON s.student_id = e.student_id AND s.is_active = 1
           LEFT JOIN sections sec ON sec.section_id = e.section_id
          WHERE e.school_year_id = ? AND e.enrollment_status = 'Approved'"
          . ($rep_all ? '' : ' AND e.section_id = ?') . "
          ORDER BY s.last_name, s.first_name",
        $rep_all ? [(int)$active_sy['sy_id']] : [(int)$active_sy['sy_id'], (int)$rep_adv_section['section_id']]
    ) ?: [];
}

// Get grade levels
$grade_levels = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];

// Pre-fill the Generate Report form when arriving with query params
// (e.g. a "Generate Report" link from the At-Risk Students page).
$report_type = isset($_GET['report_type']) ? sanitize($_GET['report_type']) : '';
$section_id = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;
$grade_level = isset($_GET['grade_level']) ? sanitize($_GET['grade_level']) : '';
// Digit string, not intval()'d â€” may be an LRN, which can start with 0.
$student_id = isset($_GET['student_id']) ? preg_replace('/[^0-9]/', '', trim($_GET['student_id'])) : '';

include '../../includes/header.php';
?>

<style>
/* Scoped to this page. Sizes match the Enrollment, Students, Teachers,
   Subjects and Attendance pages so the system reads at one size. */
.rep-page .rep-title { font-size: 24px; font-weight: 700; color: var(--ink); margin: 0; }
.rep-page .card-header h5 { font-size: 19px; font-weight: 600; color: var(--ink); }
.rep-page .rep-card-title { font-size: 19px; font-weight: 600; color: var(--ink); }
.rep-page .rep-updated { color: var(--ink); font-size: 15px; font-weight: 700; }
.rep-page .rep-pill { font-size: 14.5px; font-weight: 600; padding: 5px 12px; border-radius: 20px; }

.rep-page .rep-label { font-size: 15px; font-weight: 600; color: var(--text-muted); display: block; margin-bottom: 6px; }
.rep-page .rep-input { border-radius: 8px; border: 1px solid var(--border); font-size: 15px; padding: 9px 12px; height: 40px; }
.rep-page select.rep-input { padding-right: 38px; }
.rep-page .rep-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(91,95,239,0.12); }
.rep-page .rep-hint { color: var(--text-muted); font-size: 14.5px; }
/* Generate/report actions are the page's primary buttons, so they use the
   shared primary indigo like every other "Generate/Save/Apply" button in
   the system instead of navy. */
.rep-page .rep-btn { background: var(--primary); color: #fff; border: 1px solid var(--primary); padding: 0 26px; height: 40px; border-radius: 8px; font-weight: 600; font-size: 14.5px; display: inline-flex; align-items: center; }
.rep-page .rep-btn:hover { background: #4A4EDB; border-color: #4A4EDB; color: #fff; }
.rep-page .rep-btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.rep-page .rep-btn-green { background: #238863; border-color: #238863; }

.rep-page .rep-help { background: var(--surface-muted, #F3F6F9); border: 1px solid var(--border-soft, #E3E9F2); border-radius: 8px; padding: 10px 14px; font-size: 15px; color: var(--ink); }
.rep-page .rep-help-tag { background: var(--primary-soft); color: var(--primary); border-radius: 6px; padding: 2px 8px; font-size: 13.5px; font-weight: 700; }
.rep-page .rep-help-need { display: block; color: var(--text-muted); font-size: 14.5px; margin-top: 3px; }
.rep-page .list-group-item { font-size: 15px; border-color: var(--border-soft); padding: 14px 16px; }
.rep-page .rep-tag { background: var(--primary-soft); color: var(--primary); padding: 5px 12px; border-radius: 6px; font-size: 14.5px; font-weight: 600; }

/* Accordion headers get the same "soft muted surface" treatment as the
   filter panels, and the expanded body gets a slightly more elevated
   surface than the page background â€” matches Section 30's "reduce white
   appearance" direction without touching any report-generation logic. */
.rep-page .accordion-item { border: 1px solid var(--border-soft); border-radius: var(--radius); overflow: hidden; }
.rep-page .accordion-button { font-size: 16px; font-weight: 600; background: var(--surface-muted); color: var(--ink); }
.rep-page .accordion-button:not(.collapsed) { background: var(--surface-muted); color: var(--ink); box-shadow: none; }
.rep-page .accordion-button:focus { box-shadow: 0 0 0 2px rgba(91,95,239,0.12); }
.rep-page .accordion-body { background: var(--surface-elevated, var(--surface)); }
.rep-page .table > :not(caption) > * > * { padding: 14px 16px; }
.rep-page .table thead th { font-size: 13px; text-transform: uppercase; letter-spacing: 0.03em; font-weight: 600; color: var(--text-muted); vertical-align: middle; }
.rep-page .table tbody td { font-size: 15px; vertical-align: middle; }
.rep-page .table tbody td strong,
.rep-page .table tfoot td { font-weight: 700; }
/* ---- Bigger, easier-to-read text ---- */
.rep-page .rep-title { font-size: 28px !important; }
.rep-page .rep-card-title, .rep-page .card-header h5 { font-size: 20px !important; }
.rep-page h5.mb-3, .rep-page h5.mb-0 { font-size: 18px !important; }
.rep-page .rep-label { font-size: 15px !important; color: var(--ink) !important; }
.rep-page .rep-input { font-size: 15.5px !important; height: 46px !important; }
.rep-page .rep-hint { font-size: 15px !important; }
.rep-page .rep-btn { font-size: 15.5px !important; height: 46px !important; }
.rep-page .accordion-button { font-size: 17.5px !important; }
.rep-page .table thead th { font-size: 14px !important; }
.rep-page .table tbody td, .rep-page .table tfoot td { font-size: 15.5px !important; }
.rep-page .table .badge { font-size: 15px !important; padding: 5px 10px; min-width: 30px; }
.rep-page .list-group-item { font-size: 16px !important; }
.rep-page .rep-tag, .rep-page .rep-pill { font-size: 15px !important; }
.rep-page .rep-updated { font-size: 16px !important; }
.rep-page .accordion-body p, .rep-page .accordion-body .text-muted { font-size: 15px; }
.rep-page #reportSection optgroup { font-weight: 700; font-style: normal; color: #14213D; }
/* Section picker: always opens BELOW the field (the browser's own list opened upward when there was little room below) */
.rep-page .sec-pick { position: relative; }
.rep-page .sec-pick select { display: none; }
.rep-page .sec-pick-btn { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 8px; background: var(--surface); color: var(--ink); text-align: left; cursor: pointer; }
.rep-page .sec-pick-btn i { font-size: 13.5px; color: var(--text-muted); transition: transform .15s; }
.rep-page .sec-pick.open .sec-pick-btn { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(91,95,239,0.12); }
.rep-page .sec-pick.open .sec-pick-btn i { transform: rotate(180deg); }
.rep-page .sec-pick-list { display: none; position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 1060; max-height: 320px; overflow-y: auto; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; box-shadow: 0 12px 28px rgba(16,24,40,.16); padding: 6px 0; }
.rep-page .sec-pick.open .sec-pick-list { display: block; }
.rep-page .sec-pick-group { padding: 8px 14px 4px; font-size: 14.5px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; color: var(--text-muted); position: sticky; top: -6px; background: var(--surface); }
.rep-page .sec-pick-opt { display: block; width: 100%; border: 0; background: transparent; text-align: left; padding: 9px 14px 9px 26px; font-size: 15.5px; color: var(--ink); cursor: pointer; }
.rep-page .sec-pick-opt.all { padding-left: 14px; font-weight: 600; }
.rep-page .sec-pick-opt:hover, .rep-page .sec-pick-opt.focus { background: var(--primary-soft, #EEF1FE); }
.rep-page .sec-pick-opt.selected { background: var(--primary); color: #fff; }
.rep-page #reportSection option { font-weight: 400; color: #1f2937; }
/* Student picker: same field look as Section (.sec-pick), plus a search box. */
.rep-page .stu-pick .sec-pick-btn span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rep-page .stu-pick-search { padding: 4px 10px 8px; border-bottom: 1px solid var(--border-soft, #E3E9F2); position: sticky; top: -6px; background: var(--surface); z-index: 1; }
.rep-page .stu-pick-search input { width: 100%; height: 40px; font-size: 15px; border-radius: 8px; }
.rep-page .stu-opt { display: flex; flex-direction: column; gap: 2px; }
.rep-page .stu-opt .stu-name { font-weight: 600; }
.rep-page .stu-opt .stu-sub { font-size: 13.5px; color: var(--text-muted); font-weight: 400; }
.rep-page .stu-opt.selected .stu-sub { color: rgba(255,255,255,.85); }
.rep-page .stu-pick-empty { padding: 12px 14px; color: var(--text-muted); font-size: 15px; }
</style>

<div class="row mt-4 rep-page">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="rep-title">Reports</h4>
        </div>
    </div>
</div>

<?php
// Generate Report + Quick Reports (SF1, SF5, SF9, Grade Summary, At-Risk Student)
// are for the CLASS ADVISER of a section. A teacher who advises no section does
// not see them; generate.php refuses them on the server as well.
$rep_can_generate = $rep_all || $rep_adv_section;
?>
<div class="rep-page">
<?php
if (!$rep_can_generate): ?>
<div class="card border-0 shadow-sm" style="margin-bottom:16px;">
    <div class="card-body" style="padding:18px;font-size:15px;color:var(--text-muted);">
        <i class="fas fa-info-circle me-1"></i> Generate Report (SF1, SF5, SF9, Grade Summary and At-Risk Student Report) is available once you are assigned as a class adviser.
    </div>
</div>
<?php else: ?>
<!-- Report Generation Form -->
<div class="card border-0 shadow-sm" style="margin-bottom:16px;">
    <div class="card-body" style="padding:18px;">
        <h5 class="mb-3" style="font-weight:600;color: var(--ink);font-size:15px;">Generate Report</h5>
        <form method="GET" action="generate.php" class="row g-3" target="_blank">
            <div class="col-md-3">
                <label class="rep-label">Report Type</label>
                <select name="report_type" class="form-select rep-input" required>
                    <option value="">Select Report</option>
                    <?php $rep_grp = null; foreach ($report_help as $rk => $rh):
                        if ($rh[0] !== $rep_grp): if ($rep_grp !== null) echo '</optgroup>'; $rep_grp = $rh[0]; ?>
                        <optgroup label="<?php echo htmlspecialchars($rh[0] === 'DepEd Form' ? 'DepEd Forms' : $rh[0]); ?>">
                    <?php endif; ?>
                        <option value="<?php echo $rk; ?>" <?php echo $report_type === $rk ? 'selected' : ''; ?>><?php echo htmlspecialchars($rh[1]); ?></option>
                    <?php endforeach; echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="rep-label">Grade Level</label>
                <select name="grade_level" id="reportGradeLevel" class="form-select rep-input">
                    <option value="">All Grade Levels</option>
                    <?php foreach ($grade_levels as $g): ?>
                        <option value="<?php echo $g; ?>" <?php echo ($grade_level === $g) ? 'selected' : ''; ?>><?php echo $g; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="rep-label">Section</label>
                <select name="section_id" id="reportSection" class="form-select rep-input">
                    <option value=""><?php echo $rep_all ? 'All Sections' : 'My advisory section'; ?></option>
                    <?php $sec_group = null; foreach ($sections as $sec):
                        if ($sec['grade_level'] !== $sec_group):
                            if ($sec_group !== null) echo '</optgroup>';
                            $sec_group = $sec['grade_level']; ?>
                        <optgroup label="<?php echo htmlspecialchars($sec_group); ?>" data-grade="<?php echo htmlspecialchars($sec_group); ?>">
                        <?php endif; ?>
                        <option value="<?php echo $sec['section_id']; ?>"
                                data-grade="<?php echo htmlspecialchars($sec['grade_level']); ?>"
                                <?php echo ($section_id === (int)$sec['section_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sec['section_name']); ?>
                        </option>
                    <?php endforeach; if ($sec_group !== null) echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="col-md-3" id="repStudentWrap">
                <label class="rep-label" id="reportStudentLabel"><?php echo $rep_all ? 'Student' : 'Name of My Advisory Students'; ?></label>
                <?php if (!$rep_all && !$rep_adv_section): ?>
                    <select class="form-select rep-input" id="reportStudent" disabled>
                        <option>No advisory class assigned.</option>
                    </select>
                    <small class="rep-hint">SF9 and the At-Risk Student Report are for advisers only.</small>
                <?php else: ?>
                    <!-- The value sent is the learner's LRN, which generate.php already accepts. -->
                    <select name="student_id" id="reportStudent" class="form-select rep-input">
                        <option value=""><?php echo $rep_all ? 'Select Student' : 'Select Advisory Student'; ?></option>
                        <?php foreach ($rep_students as $rs): ?>
                            <option value="<?php echo htmlspecialchars($rs['lrn']); ?>"
                                    data-lrn="<?php echo htmlspecialchars($rs['lrn']); ?>"
                                    data-sub="<?php echo htmlspecialchars(trim($rs['grade_level'] . ' - ' . ($rs['section_name'] ?? ''))); ?>"
                                    <?php echo ($student_id !== '' && $student_id === (string)$rs['lrn']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(trim($rs['first_name'] . ' ' . (trim((string)$rs['middle_name']) !== '' ? mb_substr(trim($rs['middle_name']), 0, 1) . '. ' : '') . $rs['last_name'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="rep-hint" id="reportStudentHint"><?php echo $rep_all
                        ? 'Search by name or LRN'
                        : htmlspecialchars($rep_adv_section['grade_level'] . ' - ' . $rep_adv_section['section_name']) . ' &middot; ' . count($rep_students) . ' student' . (count($rep_students) === 1 ? '' : 's'); ?></small>
                <?php endif; ?>
            </div>
            <div class="col-12">
                <div id="repHelp" class="rep-help" style="display:none"></div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn rep-btn">
                    Generate Report
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Under Report Type: what the chosen report is for, who uses it, and what it needs.
(function () {
    var help = <?php echo json_encode($report_help); ?>;
    var sel = document.querySelector('select[name=report_type]'), box = document.getElementById('repHelp');
    if (!sel || !box) return;
    function show() {
        var h = help[sel.value];
        if (!h) { box.style.display = 'none'; return; }
        box.innerHTML = '<span class="rep-help-tag">' + h[0] + '</span> <b>' + h[1] + '</b> &mdash; ' + h[2] + ' <span class="rep-help-need">Needs: ' + h[3] + '</span>';
        box.style.display = '';
    }
    sel.addEventListener('change', show);
    show();
})();
</script>
<?php endif; ?>

<!-- Quick Reports -->
<div class="row g-3">
    <?php if ($rep_can_generate): ?>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header" style="background: var(--surface);border-bottom: 1px solid var(--border);padding:14px 18px;">
                <h5 class="mb-0" style="font-weight:600;color: var(--ink);font-size:15px;">Quick Reports</h5>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="list-group list-group-flush">
                    <a href="generate.php?report_type=sf1" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" target="_blank">
                        <span>SF1 - School Register</span>
                        <span class="rep-tag">All</span>
                    </a>
                    <a href="index.php?report_type=sf5" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span>SF5 - Promotion Report</span>
                        <span class="rep-tag">Choose a section</span>
                    </a>
                    <a href="generate.php?report_type=grade_summary" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" target="_blank">
                        <span>Grade Summary</span>
                        <span class="rep-tag">All</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header" style="background: var(--surface);border-bottom: 1px solid var(--border);padding:14px 18px;">
                <h5 class="mb-0" style="font-weight:600;color: var(--ink);font-size:15px;">Export Options</h5>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="list-group list-group-flush">
                    <div class="list-group-item" style="border-color: var(--border-soft);">
                        <div class="d-flex justify-content-between align-items-center mb-2" style="font-size:15px;">
                            <span>Export to PDF</span>
                            <span class="rep-tag" style="background:#FDEEEE;color:#C94A4A;">PDF</span>
                        </div>
                        <select id="exportTypeSelect" class="form-select rep-input">
                            <option value="all">All Live Report Sections</option>
                            <option value="enrollment_status">Enrollment Status</option>
                            <option value="section_occupancy">Section Occupancy</option>
                            <option value="grade_completion">Grade Submission Tracker</option>
                            <option value="at_risk">At-Risk & Failing Students</option>
                        </select>
                        <button type="button" class="btn rep-btn rep-btn-green w-100 mt-2" style="justify-content:center;" onclick="exportTable()">
                            Download
                        </button>
                    </div>
                    <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" onclick="window.print(); return false;">
                        <span>Print This Dashboard</span>
                        <span class="rep-tag">Print</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<script>
function exportTable() {
    var type = document.getElementById('exportTypeSelect').value;
    var termSelect = document.getElementById('liveTermSelect');
    var term = termSelect ? termSelect.value : 'Term 1';
    var params = new URLSearchParams({ type: type, term: term });
    var gradePill = document.getElementById('gradeFilterPillText');
    if (gradePill && gradePill.textContent && gradePill.textContent.trim() !== '') {
        params.set('grade_level', gradePill.textContent.trim());
    }
    window.location.href = 'export_pdf.php?' + params.toString();
}
</script>

<script>
// Realtime filter: Section dropdown only shows sections that belong to the
// currently selected Grade Level. Runs on page load too, so a report
// re-opened with ?grade_level=... in the URL filters correctly right away.
(function () {
    var gradeSelect = document.getElementById('reportGradeLevel');
    var sectionSelect = document.getElementById('reportSection');
    if (!gradeSelect || !sectionSelect) return;

    var sectionOptions = Array.prototype.slice.call(sectionSelect.options);

    function filterSections() {
        var selectedGrade = gradeSelect.value;
        var currentSectionValue = sectionSelect.value;
        var currentStillValid = false;

        sectionOptions.forEach(function (opt) {
            // Always keep the "All Sections" placeholder (no data-grade) visible
            if (!opt.dataset.grade) {
                opt.hidden = false;
                opt.disabled = false;
                return;
            }

            var matches = !selectedGrade || opt.dataset.grade === selectedGrade;
            opt.hidden = !matches;
            opt.disabled = !matches;

            if (matches && opt.value === currentSectionValue) {
                currentStillValid = true;
            }
        });

        // If the section that was selected no longer matches the chosen
        // grade level, reset back to "All Sections" instead of leaving a
        // stale, hidden option selected.
        if (currentSectionValue && !currentStillValid) {
            sectionSelect.value = '';
        }
        sectionSelect.querySelectorAll('optgroup').forEach(function (grp) {
            var show = !selectedGrade || grp.dataset.grade === selectedGrade;
            grp.hidden = !show;
            grp.disabled = !show;
        });
    }

    gradeSelect.addEventListener('change', filterSections);
    filterSections(); // initialize on page load
})();

// Section picker that always opens below the field. The real <select> stays in
// the form (hidden) and keeps the value; this only draws the list, grouped by
// Grade 7 ... Grade 12, and follows the Grade Level filter above.
(function () {
    var select = document.getElementById('reportSection');
    var gradeSelect = document.getElementById('reportGradeLevel');
    if (!select) return;
    var wrap = document.createElement('div');
    wrap.className = 'sec-pick';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'form-select rep-input sec-pick-btn';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    var list = document.createElement('div');
    list.className = 'sec-pick-list';
    list.setAttribute('role', 'listbox');
    wrap.appendChild(btn);
    wrap.appendChild(list);
    var label = document.querySelector('label[for="reportSection"]') || wrap.parentNode.querySelector('.rep-label');
    if (label) { label.id = label.id || 'reportSectionLabel'; btn.setAttribute('aria-labelledby', label.id); }

    function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
    function current() { var o = select.options[select.selectedIndex]; return o ? o.text.trim() : 'All Sections'; }
    function draw() {
        btn.innerHTML = '<span>' + esc(current()) + '</span><i class="fas fa-chevron-down" aria-hidden="true"></i>';
        var html = '';
        Array.prototype.forEach.call(select.children, function (el) {
            if (el.tagName === 'OPTION') {
                html += '<button type="button" role="option" class="sec-pick-opt all' + (el.selected ? ' selected' : '') + '" data-value="' + esc(el.value) + '">' + esc(el.text.trim()) + '</button>';
            } else if (el.tagName === 'OPTGROUP' && !el.hidden) {
                html += '<div class="sec-pick-group">' + esc(el.label) + '</div>';
                Array.prototype.forEach.call(el.children, function (o) {
                    if (o.hidden || o.disabled) return;
                    html += '<button type="button" role="option" class="sec-pick-opt' + (o.selected ? ' selected' : '') + '" data-value="' + esc(o.value) + '">' + esc(o.text.trim()) + '</button>';
                });
            }
        });
        list.innerHTML = html;
    }
    function open() {
        draw();
        wrap.classList.add('open');
        btn.setAttribute('aria-expanded', 'true');
        var sel = list.querySelector('.selected');
        if (sel) list.scrollTop = Math.max(0, sel.offsetTop - 40);
        // keep the whole list on screen: scroll the page a little if it would end below the window
        var r = list.getBoundingClientRect();
        if (r.bottom > window.innerHeight - 8) window.scrollBy({ top: r.bottom - window.innerHeight + 16, behavior: 'smooth' });
    }
    function close() { wrap.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
    function choose(v) {
        select.value = v;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        close();
        draw();
        btn.focus();
    }
    btn.addEventListener('click', function () { wrap.classList.contains('open') ? close() : open(); });
    list.addEventListener('click', function (e) {
        var o = e.target.closest('.sec-pick-opt');
        if (o) choose(o.getAttribute('data-value'));
    });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
    wrap.addEventListener('keydown', function (e) {
        var opts = Array.prototype.slice.call(list.querySelectorAll('.sec-pick-opt'));
        var i = opts.indexOf(list.querySelector('.sec-pick-opt.focus'));
        if (e.key === 'Escape') { close(); btn.focus(); return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!wrap.classList.contains('open')) { open(); return; }
            if (i < 0) i = opts.indexOf(list.querySelector('.sec-pick-opt.selected'));
            i = e.key === 'ArrowDown' ? Math.min(opts.length - 1, i + 1) : Math.max(0, i - 1);
            opts.forEach(function (o) { o.classList.remove('focus'); });
            if (opts[i]) { opts[i].classList.add('focus'); opts[i].scrollIntoView({ block: 'nearest' }); }
        } else if (e.key === 'Enter' && wrap.classList.contains('open') && i >= 0) {
            e.preventDefault();
            choose(opts[i].getAttribute('data-value'));
        }
    });
    // The Grade Level filter hides other grades and may reset the section.
    if (gradeSelect) gradeSelect.addEventListener('change', function () { setTimeout(draw, 0); });
    draw();
})();
</script>

<script>
// "Name of My Advisory Students" picker. Same look as the Section picker
// (.sec-pick); the real <select name="student_id"> stays in the form and
// sends the learner's LRN. Search box filters by name or LRN.
(function () {
    var select = document.getElementById('reportStudent');
    var typeSel = document.querySelector('select[name=report_type]');
    var colWrap = document.getElementById('repStudentWrap');
    if (!select || !typeSel || !colWrap) return;
    var studentTypes = { sf9: 1, at_risk_student: 1 };
    function layout() {
        var t = typeSel.value;
        // Only reports about ONE learner need a student; whole-class reports
        // (Grade Summary, SF1, SF5) use the section instead.
        colWrap.style.display = (t === '' || studentTypes[t]) ? '' : 'none';
        if (!select.disabled) select.required = !!studentTypes[t];
    }
    typeSel.addEventListener('change', layout);
    layout();
    if (select.disabled) {
        typeSel.form.addEventListener('submit', function (e) {
            if (studentTypes[typeSel.value]) { e.preventDefault(); alertBox('No advisory class assigned.'); }
        });
        return;
    }

    var wrap = document.createElement('div');
    wrap.className = 'sec-pick stu-pick';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'form-select rep-input sec-pick-btn';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    btn.setAttribute('aria-labelledby', 'reportStudentLabel');
    var list = document.createElement('div');
    list.className = 'sec-pick-list';
    list.innerHTML = '<div class="stu-pick-search"><input type="search" class="form-control" placeholder="Search name or LRN..." aria-label="Search student"></div><div class="stu-pick-opts" role="listbox"></div>';
    wrap.appendChild(btn);
    wrap.appendChild(list);
    var search = list.querySelector('input'), box = list.querySelector('.stu-pick-opts');
    var opts = Array.prototype.slice.call(select.options).filter(function (o) { return o.value !== ''; });

    function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
    function mask(l) { l = String(l || ''); return l.length > 4 ? '********' + l.slice(-4) : l; }
    function drawBtn() {
        var o = select.options[select.selectedIndex];
        var txt = o && o.value ? o.text.trim() : select.options[0].text.trim();
        btn.innerHTML = '<span' + (o && o.value ? '' : ' style="color:var(--text-muted)"') + '>' + esc(txt) + '</span><i class="fas fa-chevron-down" aria-hidden="true"></i>';
    }
    function drawList() {
        var q = search.value.trim().toLowerCase(), html = '';
        opts.forEach(function (o) {
            var name = o.text.trim();
            if (q && name.toLowerCase().indexOf(q) === -1 && String(o.dataset.lrn).indexOf(q) === -1) return;
            html += '<button type="button" role="option" class="sec-pick-opt all stu-opt' + (o.selected ? ' selected' : '') + '" data-value="' + esc(o.value) + '">'
                  + '<span class="stu-name">' + esc(name) + '</span><span class="stu-sub">LRN: ' + esc(mask(o.dataset.lrn)) + ' &middot; ' + esc(o.dataset.sub || '') + '</span></button>';
        });
        box.innerHTML = html || '<div class="stu-pick-empty">' + (opts.length ? 'No matching student.' : 'No enrolled students in this section yet.') + '</div>';
    }
    function open() {
        wrap.classList.add('open'); btn.setAttribute('aria-expanded', 'true');
        search.value = ''; drawList(); search.focus();
        var r = list.getBoundingClientRect();
        if (r.bottom > window.innerHeight - 8) window.scrollBy({ top: r.bottom - window.innerHeight + 16, behavior: 'smooth' });
    }
    function close() { wrap.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
    function choose(v) { select.value = v; select.dispatchEvent(new Event('change', { bubbles: true })); close(); drawBtn(); btn.focus(); }
    btn.addEventListener('click', function () { wrap.classList.contains('open') ? close() : open(); });
    search.addEventListener('input', drawList);
    box.addEventListener('click', function (e) { var o = e.target.closest('.sec-pick-opt'); if (o) choose(o.getAttribute('data-value')); });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
    wrap.addEventListener('keydown', function (e) {
        var items = Array.prototype.slice.call(box.querySelectorAll('.sec-pick-opt'));
        var i = items.indexOf(box.querySelector('.sec-pick-opt.focus'));
        if (e.key === 'Escape') { close(); btn.focus(); return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!wrap.classList.contains('open')) { open(); return; }
            i = e.key === 'ArrowDown' ? Math.min(items.length - 1, i + 1) : Math.max(0, i - 1);
            items.forEach(function (o) { o.classList.remove('focus'); });
            if (items[i]) { items[i].classList.add('focus'); items[i].scrollIntoView({ block: 'nearest' }); }
        } else if (e.key === 'Enter' && wrap.classList.contains('open')) {
            e.preventDefault();
            if (i < 0 && items.length === 1) i = 0;
            if (items[i]) choose(items[i].getAttribute('data-value'));
        }
    });
    // A hidden required <select> cannot show the browser's message, so check here.
    typeSel.form.addEventListener('submit', function (e) {
        if (studentTypes[typeSel.value] && !select.value) { e.preventDefault(); open(); alertBox('Please select a student.'); }
        if (!studentTypes[typeSel.value]) select.value = '';
    });
    drawBtn();
})();
function alertBox(msg) {
    var h = document.getElementById('reportStudentHint') || document.querySelector('#repStudentWrap .rep-hint');
    if (h) { h.textContent = msg; h.style.color = '#C94A4A'; }
}
</script>

<?php include '../../includes/footer.php'; ?>
