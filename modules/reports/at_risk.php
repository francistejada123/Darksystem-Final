<?php
// modules/reports/at_risk.php
// Dedicated At-Risk & Failing Students report.
// Pulled out of the main Reports page so it doesn't clutter the
// Live Reports dashboard — this page shows ONLY at-risk/failing
// students, and clicking a student goes straight to their account.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Registrar', 'Teacher'])) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'At-Risk Students';
$current_page = 'reports';
$base_url = '../../';

// Optional grade-level filter carried over from the Reports dashboard —
// e.g. clicking "Grade 7" there and following "View At-Risk Students"
// lands here already scoped to Grade 7 instead of showing every grade.
$valid_grades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
$grade_filter = isset($_GET['grade_level']) ? sanitize($_GET['grade_level']) : '';
if (!in_array($grade_filter, $valid_grades, true)) {
    $grade_filter = '';
}

include '../../includes/header.php';
?>

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h2 class="mb-0">
                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>At-Risk &amp; Failing Students
                    <?php if ($grade_filter): ?>
                        <small class="text-muted">(<?php echo htmlspecialchars($grade_filter); ?>)</small>
                    <?php endif; ?>
                </h2>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if ($grade_filter): ?>
                    <a href="at_risk.php" class="badge bg-primary text-decoration-none" title="Clear grade filter">
                        <i class="fas fa-filter"></i> <?php echo htmlspecialchars($grade_filter); ?> <i class="fas fa-times ms-1"></i>
                    </a>
                <?php endif; ?>
                <small class="text-muted" id="updatedAt">Loading...</small>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="refreshBtn" title="Refresh now">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm rounded-3">
    <div class="card-body">
        <p class="text-muted small mb-3">
            Click a student's row to go directly to their account.
        </p>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>LRN</th>
                        <th>Student Name</th>
                        <th>Grade Level</th>
                        <th>Section</th>
                        <th class="text-center">Average</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="atRiskBody">
                    <tr><td colspan="7" class="text-center text-muted py-4">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    var refreshBtn = document.getElementById('refreshBtn');
    var updatedAtEl = document.getElementById('updatedAt');
    var body = document.getElementById('atRiskBody');

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = (str === null || str === undefined) ? '' : String(str);
        return div.innerHTML;
    }

    function renderRows(atRisk, failing) {
        var rows = [];
        (failing || []).forEach(function (s) { rows.push({ s: s, status: 'Failing', cls: 'bg-danger' }); });
        (atRisk || []).forEach(function (s) { rows.push({ s: s, status: 'At Risk', cls: 'bg-warning text-dark' }); });

        if (rows.length === 0) {
            body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No at-risk or failing students right now.</td></tr>';
            return;
        }

        body.innerHTML = rows.map(function (item) {
            var s = item.s;
            return '<tr class="student-row" data-student-id="' + s.student_id + '" style="cursor:pointer;" title="Open student account">' +
                '<td>' + escapeHtml(s.lrn) + '</td>' +
                '<td>' + escapeHtml(s.student_name) + '</td>' +
                '<td>' + escapeHtml(s.grade_level) + '</td>' +
                '<td>' + escapeHtml(s.section_name || 'Unassigned') + '</td>' +
                '<td class="text-center">' + s.average.toFixed(2) + '</td>' +
                '<td class="text-center"><span class="badge ' + item.cls + '">' + item.status + '</span></td>' +
                '<td class="text-center">' +
                    '<a href="generate.php?report_type=sf5&student_id=' + s.student_id + '" target="_blank" ' +
                        'class="btn btn-sm btn-outline-primary" title="Generate this student\'s report" onclick="event.stopPropagation();">' +
                        '<i class="fas fa-file-pdf"></i>' +
                    '</a>' +
                '</td>' +
                '</tr>';
        }).join('');

        Array.prototype.forEach.call(body.querySelectorAll('.student-row'), function (row) {
            row.addEventListener('click', function () {
                window.location.href = '../students/view.php?id=' + row.dataset.studentId;
            });
        });
    }

    function loadData() {
        var url = 'live_data.php';
        <?php if ($grade_filter): ?>
        url += '?grade_level=' + encodeURIComponent(<?php echo json_encode($grade_filter); ?>);
        <?php endif; ?>
        fetch(url, { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('Request failed: ' + res.status);
                return res.json();
            })
            .then(function (data) {
                renderRows(data.at_risk, data.failing);
                updatedAtEl.textContent = 'Updated ' + data.generated_at;
            })
            .catch(function (err) {
                updatedAtEl.textContent = 'Failed to load — try refreshing';
                console.error('At-risk report load failed:', err);
            });
    }

    if (refreshBtn) refreshBtn.addEventListener('click', loadData);
    loadData();
})();
</script>

<?php include '../../includes/footer.php'; ?>