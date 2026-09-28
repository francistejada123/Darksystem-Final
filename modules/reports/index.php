<?php
// modules/reports/index.php
// Reports landing page — the Live Reports dashboard + the report
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
    'sf5' => ['DepEd Form', 'SF5 - Promotion & Level of Proficiency', 'End-of-year result per section: General Average, Promoted / Conditionally Promoted / Retained, with summary tables by sex. Submitted to the Division.', 'Section'],
    'sf9' => ['DepEd Form', "SF9 - Learner's Report Card", 'Given to the parent every term and at year end: grades per term, Final Grade, General Average, and attendance per month.', 'Learner LRN'],
    'grade_summary' => ['Monitoring', 'Grade Summary', 'Per-term check of a section: each learner\'s term averages and the subjects currently below passing, so the adviser/principal can act before the year ends.', 'Section (or Grade Level)'],
    'at_risk_student' => ['Intervention', 'At-Risk Student Report', 'One learner\'s weak subjects this school year with recommended actions (conference, remedial classes, guidance, parent coordination).', 'Learner LRN'],
];

// Get grade levels
$grade_levels = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];

// Pre-fill the Generate Report form when arriving with query params
// (e.g. a "Generate Report" link from the At-Risk Students page).
$report_type = isset($_GET['report_type']) ? sanitize($_GET['report_type']) : '';
$section_id = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;
$grade_level = isset($_GET['grade_level']) ? sanitize($_GET['grade_level']) : '';
// Digit string, not intval()'d — may be an LRN, which can start with 0.
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
   surface than the page background — matches Section 30's "reduce white
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
</style>

<div class="row mt-4 rep-page">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="rep-title">Reports</h4>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- LIVE REPORTS -->
<!-- ============================================================ -->
<div class="rep-page">
<div class="card border-0 shadow-sm" style="margin-bottom:16px;">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2" style="background: var(--surface);border-bottom: 1px solid var(--border);padding:16px 18px;">
        <h5 class="mb-0 rep-card-title">
            Live Reports
            <span class="badge rep-pill" style="background:#EAF7F1;color:#238863;margin-left:8px;" id="liveStatusBadge"></span>
        </h5>
        <div class="d-flex align-items-center gap-2">
            <span class="badge rep-pill d-none align-items-center gap-1" id="gradeFilterPill" style="cursor:pointer;background:#EDF4FB;color: var(--ink);" title="Click to clear the grade filter">
                <i class="fas fa-filter" style="font-size:13.5px;"></i> <span id="gradeFilterPillText"></span> <i class="fas fa-times ms-1" style="font-size:13.5px;"></i>
            </span>
            <span class="rep-updated" id="liveUpdatedAt">Loading...</span>
        </div>
    </div>
    <div class="card-body">
        <div class="accordion" id="liveReportsAccordion">

            <!-- 1. Enrollment Status (+ Section Occupancy shown per grade, on click) -->
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseEnrollment">
                        <i class="fas fa-user-check text-primary me-2"></i>Enrollment Status (per Grade Level)
                    </button>
                </h2>
                <div id="collapseEnrollment" class="accordion-collapse collapse show">
                    <div class="accordion-body">
                        <p class="text-muted mb-2" style="font-size:15px;">Click a grade level row to see its section occupancy.</p>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Grade Level</th>
                                        <th class="text-center">Pending</th>
                                        <th class="text-center">Approved</th>
                                        <th class="text-center">Incomplete Requirements</th>
                                        <th class="text-center">Total</th>
                                    </tr>
                                </thead>
                                <tbody id="liveEnrollmentStatusBody">
                                    <tr><td colspan="5" class="text-center text-muted py-3">Loading...</td></tr>
                                </tbody>
                                <tfoot id="liveEnrollmentStatusFoot"></tfoot>
                            </table>
                        </div>

                        <!-- Section Occupancy detail — filled in only when a grade row above is clicked -->
                        <div id="gradeSectionDetail" class="mt-3" style="display:none;">
                            <hr>
                            <h6 class="text-primary mb-2">
                                <i class="fas fa-chalkboard me-2"></i>Section Occupancy —
                                <span id="gradeSectionDetailTitle"></span>
                            </h6>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Section</th>
                                            <th class="text-center">Capacity</th>
                                            <th class="text-center">Approved</th>
                                            <th class="text-center">Pending</th>
                                            <th class="text-center">Remaining</th>
                                            <th style="min-width:120px;">Occupancy</th>
                                        </tr>
                                    </thead>
                                    <tbody id="liveSectionOccupancyBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Grade Submission Tracker -->
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseGradeTracker">
                        <i class="fas fa-tasks text-primary me-2"></i>Grade Submission Tracker
                        <span class="text-muted ms-2 grade-scope-label" style="display:none;font-size:15px;"></span>
                    </button>
                </h2>
                <div id="collapseGradeTracker" class="accordion-collapse collapse">
                    <div class="accordion-body">
                        <div class="d-flex justify-content-end mb-2">
                            <select id="liveTermSelect" class="form-select rep-input" style="width:auto;">
                                <option value="Term 1">Term 1</option>
                                <option value="Term 2">Term 2</option>
                                <option value="Term 3">Term 3</option>
                            </select>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Teacher</th>
                                        <th>Subject</th>
                                        <th>Section</th>
                                        <th class="text-center">Graded / Expected</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody id="liveGradeCompletionBody">
                                    <tr><td colspan="5" class="text-center text-muted py-3">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. At-Risk / Failing Students — summary only, full list moved to its own page -->
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAtRisk">
                        <i class="fas fa-exclamation-triangle text-primary me-2"></i>At-Risk &amp; Failing Students
                        <span class="badge bg-warning text-dark ms-2" id="atRiskCountBadge">0</span>
                        <span class="text-muted ms-2 grade-scope-label" style="display:none;font-size:15px;"></span>
                    </button>
                </h2>
                <div id="collapseAtRisk" class="accordion-collapse collapse">
                    <div class="accordion-body">
                        <p class="mb-2" id="atRiskSummaryText">Loading...</p>
                        <a href="at_risk.php" class="btn rep-btn" style="background:#B7791F;border-color:#B7791F;" id="viewAtRiskLink">
                            <i class="fas fa-list me-1"></i>View At-Risk Students
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

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
            <div class="col-md-3">
                <label class="rep-label">Student LRN / ID</label>
                <input type="text" inputmode="numeric" pattern="[0-9]*" name="student_id" id="reportStudentId" class="form-control rep-input" placeholder="e.g. 110100100052"
                       value="<?php echo htmlspecialchars($student_id); ?>">
                <small class="rep-hint">For SF9 and At-Risk Student Report</small>
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

<!-- Quick Reports -->
<div class="row g-3">
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
// ============================================================
// LIVE REPORTS DASHBOARD — fetches modules/reports/live_data.php,
// renders it, then polls on an interval so the numbers stay connected
// to the database without a full page reload.
// ============================================================
(function () {
    var REFRESH_INTERVAL_MS = 20000; // 20s
    var termSelect = document.getElementById('liveTermSelect');
    var updatedAtEl = document.getElementById('liveUpdatedAt');
    var statusBadge = document.getElementById('liveStatusBadge');
    var gradeFilterPill = document.getElementById('gradeFilterPill');
    var gradeFilterPillText = document.getElementById('gradeFilterPillText');
    var pollTimer = null;
    var latestSectionOccupancy = []; // kept in memory so clicking a grade row doesn't need another request
    // Which grade is currently selected. Doubles as: (a) which grade's
    // Section Occupancy detail is expanded, and (b) the ?grade_level=
    // filter sent to live_data.php, which narrows Grade Submission
    // Tracker, At-Risk & Failing Students, and Teacher Load down to
    // just that grade. Clicking the same grade row again clears it.
    var gradeFilter = null;

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = (str === null || str === undefined) ? '' : String(str);
        return div.innerHTML;
    }

    // Reflects the current gradeFilter in the UI: the header pill, the
    // "(Grade X)" hints on the three scoped accordion sections, the
    // highlighted row in Enrollment Status, and the At-Risk page link.
    function updateGradeFilterUI() {
        if (gradeFilter) {
            gradeFilterPill.classList.remove('d-none');
            gradeFilterPill.classList.add('d-inline-flex');
            gradeFilterPillText.textContent = gradeFilter;
        } else {
            gradeFilterPill.classList.add('d-none');
            gradeFilterPill.classList.remove('d-inline-flex');
        }

        Array.prototype.forEach.call(document.querySelectorAll('.grade-scope-label'), function (el) {
            if (gradeFilter) {
                el.textContent = '(' + gradeFilter + ')';
                el.style.display = '';
            } else {
                el.style.display = 'none';
            }
        });

        Array.prototype.forEach.call(document.querySelectorAll('#liveEnrollmentStatusBody .grade-row'), function (r) {
            r.classList.toggle('table-primary', !!gradeFilter && r.dataset.grade === gradeFilter);
        });

        var viewAtRiskLink = document.getElementById('viewAtRiskLink');
        if (viewAtRiskLink) {
            viewAtRiskLink.href = gradeFilter ? ('at_risk.php?grade_level=' + encodeURIComponent(gradeFilter)) : 'at_risk.php';
        }
    }

    function setGradeFilter(grade) {
        if (gradeFilter === grade) {
            // Clicking the same grade again clears the filter.
            gradeFilter = null;
            document.getElementById('gradeSectionDetail').style.display = 'none';
        } else {
            gradeFilter = grade;
            showGradeSections(grade);
        }
        updateGradeFilterUI();
        loadLiveData();
    }

    if (gradeFilterPill) {
        gradeFilterPill.addEventListener('click', function () {
            setGradeFilter(gradeFilter); // same value in => toggles off
        });
    }

    function renderEnrollmentStatus(rows, totals) {
        var body = document.getElementById('liveEnrollmentStatusBody');
        var foot = document.getElementById('liveEnrollmentStatusFoot');
        if (!rows || rows.length === 0) {
            body.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No enrollment data yet.</td></tr>';
            foot.innerHTML = '';
            return;
        }
        body.innerHTML = rows.map(function (r) {
            return '<tr class="grade-row" data-grade="' + escapeHtml(r.grade_level) + '" style="cursor:pointer;" title="Click to view section occupancy">' +
                '<td>' + escapeHtml(r.grade_level) + ' <i class="fas fa-chevron-right text-muted"></i></td>' +
                '<td class="text-center"><span class="badge bg-warning text-dark">' + r.pending + '</span></td>' +
                '<td class="text-center"><span class="badge bg-success">' + r.approved + '</span></td>' +
                '<td class="text-center"><span class="badge bg-warning text-dark">' + r.rejected + '</span></td>' +
                '<td class="text-center"><strong>' + r.total + '</strong></td>' +
                '</tr>';
        }).join('');
        if (totals) {
            foot.innerHTML = '<tr class="table-light">' +
                '<td><strong>Overall</strong></td>' +
                '<td class="text-center"><strong>' + (totals.pending || 0) + '</strong></td>' +
                '<td class="text-center"><strong>' + (totals.approved || 0) + '</strong></td>' +
                '<td class="text-center"><strong>' + (totals.rejected || 0) + '</strong></td>' +
                '<td class="text-center"><strong>' + (totals.total || 0) + '</strong></td>' +
                '</tr>';
        }

        // Wire up click-to-expand/filter on each grade row
        Array.prototype.forEach.call(body.querySelectorAll('.grade-row'), function (row) {
            row.addEventListener('click', function () {
                setGradeFilter(row.dataset.grade);
            });
        });

        // Keep the section-occupancy detail open (with fresh data) if a
        // grade was already selected before this refresh, and re-apply
        // highlighting/pill state since the rows were just rebuilt.
        if (gradeFilter) {
            showGradeSections(gradeFilter);
        }
        updateGradeFilterUI();
    }

    function showGradeSections(grade) {
        var detail = document.getElementById('gradeSectionDetail');
        var title = document.getElementById('gradeSectionDetailTitle');
        var body = document.getElementById('liveSectionOccupancyBody');

        var rows = latestSectionOccupancy.filter(function (s) { return s.grade_level === grade; });

        title.textContent = grade;
        detail.style.display = '';

        if (rows.length === 0) {
            body.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">No active sections for ' + escapeHtml(grade) + '.</td></tr>';
            return;
        }
        body.innerHTML = rows.map(function (s) {
            var barColor = s.percent_full >= 100 ? 'bg-danger' : (s.percent_full >= 80 ? 'bg-warning' : 'bg-success');
            var label = escapeHtml(s.section_name) + (s.strand ? ' (' + escapeHtml(s.strand === 'ASSH' ? 'ASSH Cluster' : s.strand) + ')' : '');
            return '<tr>' +
                '<td>' + label + '</td>' +
                '<td class="text-center">' + s.capacity + '</td>' +
                '<td class="text-center"><span class="badge bg-success">' + s.approved + '</span></td>' +
                '<td class="text-center"><span class="badge bg-warning text-dark">' + s.pending + '</span></td>' +
                '<td class="text-center">' + (s.remaining <= 0 ? '<span class="badge bg-danger">Full</span>' : s.remaining) + '</td>' +
                '<td>' +
                    '<div class="progress" style="height:8px;">' +
                        '<div class="progress-bar ' + barColor + '" style="width:' + s.percent_full + '%"></div>' +
                    '</div>' +
                    '<span class="text-muted" style="font-size:15px;">' + s.percent_full + '%</span>' +
                '</td>' +
                '</tr>';
        }).join('');

        detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function renderGradeCompletion(rows) {
        var body = document.getElementById('liveGradeCompletionBody');
        if (!rows || rows.length === 0) {
            body.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No subject assignments for this school year.</td></tr>';
            return;
        }
        body.innerHTML = rows.map(function (r) {
            var statusBadgeHtml = r.complete
                ? '<span class="badge bg-success">Complete</span>'
                : '<span class="badge bg-warning text-dark">Incomplete</span>';
            return '<tr>' +
                '<td>' + escapeHtml(r.teacher_name) + '</td>' +
                '<td>' + escapeHtml(r.subject_name) + '</td>' +
                '<td>' + escapeHtml(r.section_name) + ' <small class="text-muted">(' + escapeHtml(r.grade_level) + ')</small></td>' +
                '<td class="text-center">' + r.graded_students + ' / ' + r.expected_students + '</td>' +
                '<td class="text-center">' + statusBadgeHtml + '</td>' +
                '</tr>';
        }).join('');
    }

    function renderAtRiskSummary(atRisk, failing) {
        var countBadge = document.getElementById('atRiskCountBadge');
        var summaryText = document.getElementById('atRiskSummaryText');
        var atRiskCount = (atRisk || []).length;
        var failingCount = (failing || []).length;
        var total = atRiskCount + failingCount;

        countBadge.textContent = total;
        countBadge.className = 'badge ms-2 ' + (total > 0 ? 'bg-warning text-dark' : 'bg-secondary');

        if (total === 0) {
            summaryText.textContent = 'No at-risk or failing students right now.';
        } else {
            summaryText.textContent = failingCount + ' student(s) currently failing, ' + atRiskCount + ' student(s) at risk.';
        }
    }

    function loadLiveData() {
    var term = termSelect ? termSelect.value : 'Term 1';
    statusBadge.classList.remove('bg-danger');
        statusBadge.classList.add('bg-success');

        var url = 'live_data.php?term=' + encodeURIComponent(term);
        if (gradeFilter) {
            url += '&grade_level=' + encodeURIComponent(gradeFilter);
        }

        fetch(url, { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('Request failed: ' + res.status);
                return res.json();
            })
            .then(function (data) {
                latestSectionOccupancy = data.section_occupancy || [];
                renderEnrollmentStatus(data.enrollment_status, data.enrollment_totals);
                renderGradeCompletion(data.grade_completion);
                renderAtRiskSummary(data.at_risk, data.failing);
                updatedAtEl.textContent = 'Updated ' + data.generated_at;
            })
            .catch(function (err) {
                statusBadge.classList.remove('bg-success');
                statusBadge.classList.add('bg-danger');
                updatedAtEl.textContent = 'Failed to refresh — will retry';
                console.error('Live report refresh failed:', err);
            });
    }

    // No in-page refresh control: the browser's own reload is the refresh.
    if (termSelect) termSelect.addEventListener('change', loadLiveData);

    loadLiveData();
    pollTimer = setInterval(loadLiveData, REFRESH_INTERVAL_MS);

    // Stop polling if the tab is hidden, resume when visible again — avoids
    // hammering the server with requests for tabs nobody's looking at.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            clearInterval(pollTimer);
        } else {
            loadLiveData();
            pollTimer = setInterval(loadLiveData, REFRESH_INTERVAL_MS);
        }
    });
})();
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

<?php include '../../includes/footer.php'; ?>