<?php
// modules/enrollment/index.php
// Enrollment management

require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/attendance_qr_helpers.php'; // attQrLrnLink() -- approved students' LRN opens their QR code
require_once '../../includes/grading_helpers.php'; // year status (Completed / Promoted) -- see Section 17c
require_once __DIR__ . '/../../includes/enrollment_helpers_online.php';
enEnsureIncompleteStatus(); // walk-in: Rejected -> Incomplete Requirements (once)

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Registrar', 'Enrollment Officer'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Enrollment Management';
$current_page = 'enrollment';
$base_url = BASE_URL;

$search = sanitize($_GET['search'] ?? '');
$status = sanitize($_GET['status'] ?? '');
$grade_level = sanitize($_GET['grade_level'] ?? '');

// Enrollment date window. Whitelisted before it reaches SQL — an
// unrecognised value simply falls back to "no filter".
$date_ranges = ['today' => 'Today', '7d' => 'Last 7 Days', '30d' => 'Last 30 Days'];
$date_range = sanitize($_GET['date_range'] ?? '');
if (!array_key_exists($date_range, $date_ranges)) {
    $date_range = '';
}

// School year filter: only school years that exist in Settings > School Years can be
// chosen; anything else falls back to "All School Years".
$school_years_list = getRecords("SELECT sy_id, sy_name FROM school_years ORDER BY start_date DESC, sy_id DESC") ?: [];
$school_year_filter = intval($_GET['school_year'] ?? 0);
if (!in_array($school_year_filter, array_map('intval', array_column($school_years_list, 'sy_id')), true)) {
    $school_year_filter = 0;
}

$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// End-of-year status (Completed / Promoted), saved per learner + school year + grade
// level in student_year_status when all three terms are finalized and every subject
// grade is complete. It is an overlay: the enrollment record itself is unchanged.
gh_ensureStudentYearStatusTable();
$active_sy_row = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
gh_sweepYearStatuses($active_sy_row['sy_id'] ?? 0);   // catch up sections that finished before this existed
$year_promoted_label = gh_yearStatusPromotedLabel();
$year_join = " LEFT JOIN student_year_status ys ON ys.student_id = e.student_id AND ys.school_year_id = e.school_year_id
                    AND ys.grade_level = e.grade_level AND ys.status = " . $db->quote($year_promoted_label) . " ";

// Build WHERE clause
$where = "e.enrollment_status != 'Graduated'";
$params = [];

if ($search) {
    $where .= " AND (s.lrn LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.last_name, ', ', s.first_name) LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if ($status === $year_promoted_label) {
    $where .= " AND e.enrollment_status = 'Approved' AND ys.status_id IS NOT NULL";
} elseif ($status) {
    $where .= " AND e.enrollment_status = ?";
    $params[] = $status;
}

if ($grade_level) {
    $where .= " AND e.grade_level = ?";
    $params[] = $grade_level;
}

if ($school_year_filter) {
    $where .= " AND e.school_year_id = ?";
    $params[] = $school_year_filter;
}

// enrollment_date is a DATE column, so these are whole-day windows and the
// current day is always included: "Last 7 Days" = today plus the 6 days
// before it. No user input is concatenated here — only the whitelisted key.
if ($date_range === 'today') {
    $where .= " AND e.enrollment_date = CURDATE()";
} elseif ($date_range === '7d') {
    $where .= " AND e.enrollment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
} elseif ($date_range === '30d') {
    $where .= " AND e.enrollment_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)";
}

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM enrollments e 
              JOIN students s ON e.student_id = s.student_id 
              $year_join
              WHERE $where";
$count_result = getRecord($count_sql, $params);
$total_records = $count_result ? $count_result['total'] : 0;
$total_pages = ceil($total_records / $limit);

// If the last row of the last page was just deleted, fall back to the new
// last page instead of showing an empty one.
if ($total_pages > 0 && $page > $total_pages) {
    $page = (int)$total_pages;
    $offset = ($page - 1) * $limit;
}

// Get enrollments
$sql = "SELECT e.*, s.lrn, s.first_name, s.last_name, s.middle_name, 
               s.grade_level as student_grade, 
               sec.section_name, 
               sec.strand as section_strand,
               sy.sy_name,
               ys.status AS year_status, ys.general_average AS year_average,
               u.first_name as processed_first, u.last_name as processed_last
        FROM enrollments e 
        JOIN students s ON e.student_id = s.student_id 
        LEFT JOIN sections sec ON e.section_id = sec.section_id 
        LEFT JOIN school_years sy ON e.school_year_id = sy.sy_id 
        LEFT JOIN users u ON e.processed_by = u.user_id 
        $year_join
        WHERE $where 
        ORDER BY e.enrollment_date DESC, e.enrollment_status ASC
        LIMIT $offset, $limit";

$enrollments = getRecords($sql, $params);

// Get filter options
$statuses = ['Pending', 'Approved', 'Incomplete Requirements', 'Transferred', $year_promoted_label];
$grade_levels = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];

// "Delete Student" (permanent delete of an enrolled student + related records).
// Administrator only; keep this list in sync with delete_student.php.
$can_delete_student = hasRole('Administrator');
$deletable_statuses = ['Approved'];

// Status counts for dashboard cards
$counts = getRecord("
    SELECT
        SUM(enrollment_status = 'Pending') AS pending,
        SUM(enrollment_status = 'Approved') AS approved,
        SUM(enrollment_status IN ('Incomplete Requirements', 'Rejected')) AS rejected,
        SUM(enrollment_status = 'Transferred') AS transferred
    FROM enrollments
    WHERE enrollment_status != 'Graduated'
");

include '../../includes/header.php';
?>

<style>
/* This page's stat-cards/filter-card/table-card/data-table/status-badge/
   btn-view/btn-action/pagination/empty-state now come from the shared
   .enroll-page, .app-inbox rules in static/css/style.css — Enrollment and
   Online Applications used to each keep an almost-identical copy of this
   block, which is exactly the duplication Section 39 flags. Nothing here
   changes; it's the same classes, just defined once. */
</style>

<div class="enroll-page" style="padding: 24px;">

    <div class="page-header">
        <div>
            <h4>Enrollment Management</h4>
            <p>Manage student enrollment records and track status</p>
        </div>
        <a href="new.php" class="btn-view" style="padding: 11px 22px; font-size: 15px;">
            <i class="fas fa-plus"></i> New Enrollment
        </a>
    </div>

    <!-- Status Cards -->
    <div class="stat-cards">
        <?php
        $cards = [
            ['Pending', intval($counts['pending'] ?? 0), '#B7791F', '#fef9e7', 'fa-hourglass-half'],
            ['Approved', intval($counts['approved'] ?? 0), '#238863', '#e8f5e9', 'fa-circle-check'],
            ['Incomplete Requirements', intval($counts['rejected'] ?? 0), '#B45309', '#FFF4E5', 'fa-file-circle-exclamation'],
            ['Transferred', intval($counts['transferred'] ?? 0), '#667085', '#f3f4f6', 'fa-right-left'],
        ];
        foreach ($cards as $c): ?>
            <a href="index.php?status=<?php echo urlencode($c[0]); ?>" class="stat-card">
                <div class="stat-icon" style="background: <?php echo $c[3]; ?>; color: <?php echo $c[2]; ?>;">
                    <i class="fas <?php echo $c[4]; ?>"></i>
                </div>
                <div>
                    <div class="stat-value"><?php echo $c[1]; ?></div>
                    <div class="stat-label"><?php echo $c[0]; ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="filter-card">
        <form method="GET">
            <input type="text" name="search" placeholder="Search by LRN or name..." value="<?php echo htmlspecialchars($search); ?>" style="flex: 1; min-width: 200px;">
            <select name="status" onchange="this.form.submit();">
                <option value="">All Status</option>
                <?php foreach ($statuses as $st): ?>
                    <option value="<?php echo $st; ?>" <?php echo $status == $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                <?php endforeach; ?>
            </select>
            <select name="grade_level" onchange="this.form.submit();">
                <option value="">All Grade Levels</option>
                <?php foreach ($grade_levels as $g): ?>
                    <option value="<?php echo $g; ?>" <?php echo $grade_level == $g ? 'selected' : ''; ?>><?php echo $g; ?></option>
                <?php endforeach; ?>
            </select>
            <select name="date_range" onchange="this.form.submit();">
                <option value="">Enrollment Date: All</option>
                <?php foreach ($date_ranges as $dr_key => $dr_label): ?>
                    <option value="<?php echo $dr_key; ?>" <?php echo $date_range === $dr_key ? 'selected' : ''; ?>><?php echo $dr_label; ?></option>
                <?php endforeach; ?>
            </select>
            <select name="school_year" onchange="this.form.submit();">
                <option value="">All School Years</option>
                <?php foreach ($school_years_list as $sy_opt): ?>
                    <option value="<?php echo (int)$sy_opt['sy_id']; ?>" <?php echo $school_year_filter === (int)$sy_opt['sy_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($sy_opt['sy_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <a href="index.php" class="btn-view" style="padding: 9px 18px; height: 42px; color: var(--text-muted);">
                <i class="fas fa-undo"></i> Reset
            </a>
        </form>
    </div>

    <!-- Table -->
    <div class="table-card">
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>LRN</th>
                        <th>Student Name</th>
                        <th>Grade</th>
                        <th>Strand</th>
                        <th>School Year</th>
                        <th>Section</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($enrollments && count($enrollments) > 0): ?>
                        <?php foreach ($enrollments as $enrollment): ?>
                            <tr>
                                <td style="font-weight: 600;"><?php echo attQrLrnLink($enrollment['lrn']); ?></td>
                                <td><?php echo htmlspecialchars($enrollment['last_name'] . ', ' . $enrollment['first_name']); ?></td>
                                <td><?php echo htmlspecialchars($enrollment['grade_level']); ?></td>
                                <td>
                                    <?php 
                                    $strand = $enrollment['strand'] ?? '';
                                    if (empty($strand) || $strand === 'Not Assigned') {
                                        $strand = $enrollment['section_strand'] ?? '';
                                    }
                                    if (!empty($strand) && $strand !== 'Not Assigned') {
                                        echo '<span class="strand-badge">' . htmlspecialchars($strand === 'ASSH' ? 'ASSH Cluster' : $strand) . '</span>';
                                    } else {
                                        echo '<span style="color: var(--text-muted);">N/A</span>';
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($enrollment['sy_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($enrollment['section_name'] ?? 'Not Assigned'); ?></td>
                                <td>
                                    <?php
                                    $status_class = [
                                        'Pending' => 'pending',
                                        'Approved' => 'approved',
                                        'Rejected' => 'incomplete',
                                        'Incomplete Requirements' => 'incomplete',
                                        'Transferred' => 'transferred'
                                    ];
                                    $st = $enrollment['enrollment_status'];
                                    $cls = $status_class[$st] ?? 'pending';
                                    ?>
                                    <?php if ($st === 'Approved' && ($enrollment['year_status'] ?? '') === $year_promoted_label): ?>
                                        <span class="status-badge status-approved" title="Approved enrollment. All three terms finalized and every subject passed<?php echo $enrollment['year_average'] !== null ? ' -- General Average ' . number_format((float)$enrollment['year_average'], 2) : ''; ?>"><i class="fas fa-graduation-cap me-1"></i><?php echo htmlspecialchars($year_promoted_label); ?></span>
                                    <?php else: ?>
                                        <span class="status-badge status-<?php echo $cls; ?>"><?php echo $st; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space: nowrap;"><?php echo date('M d, Y', strtotime($enrollment['enrollment_date'])); ?></td>
                                <td style="white-space: nowrap;">
                                    <div class="actions-cell">
                                        <a href="view.php?id=<?php echo $enrollment['enrollment_id']; ?>" class="btn-view" title="View" aria-label="View enrollment for <?php echo htmlspecialchars($enrollment['last_name'] . ', ' . $enrollment['first_name']); ?>">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <?php if (in_array($enrollment['enrollment_status'], ['Pending', 'Incomplete Requirements'], true)): ?>
                                            <a href="approve.php?id=<?php echo $enrollment['enrollment_id']; ?>" class="btn-action approve" title="Approve" aria-label="Approve enrollment for <?php echo htmlspecialchars($enrollment['last_name'] . ', ' . $enrollment['first_name']); ?>" onclick="return appConfirm(this, 'Approve this enrollment?')">
                                                <i class="fas fa-check"></i>
                                            </a>
                                            <?php if ($enrollment['enrollment_status'] === 'Pending'): ?>
                                            <a href="reject.php?id=<?php echo $enrollment['enrollment_id']; ?>" class="btn-action incomplete" title="Mark as Incomplete Requirements" aria-label="Mark enrollment of <?php echo htmlspecialchars($enrollment['last_name'] . ', ' . $enrollment['first_name']); ?> as Incomplete Requirements" onclick="return appConfirm(this, 'Mark this enrollment as Incomplete Requirements? The student will be emailed what is missing.', {okText: 'Incomplete Requirements'})">
                                                <i class="fas fa-file-circle-exclamation"></i>
                                            </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($can_delete_student && in_array($enrollment['enrollment_status'], $deletable_statuses, true)): ?>
                                            <a href="#" class="btn-action reject js-delete-student" role="button" title="Delete Student"
                                               aria-label="Delete student <?php echo htmlspecialchars($enrollment['last_name'] . ', ' . $enrollment['first_name']); ?>"
                                               data-enrollment-id="<?php echo (int)$enrollment['enrollment_id']; ?>"
                                               data-student-id="<?php echo (int)$enrollment['student_id']; ?>"
                                               data-name="<?php echo htmlspecialchars($enrollment['last_name'] . ', ' . $enrollment['first_name'], ENT_QUOTES); ?>"
                                               data-lrn="<?php echo htmlspecialchars($enrollment['lrn'], ENT_QUOTES); ?>"
                                               data-grade="<?php echo htmlspecialchars($enrollment['grade_level'], ENT_QUOTES); ?>"
                                               data-section="<?php echo htmlspecialchars($enrollment['section_name'] ?? 'Not Assigned', ENT_QUOTES); ?>">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9">
                                <div class="empty-state">
                                    <i class="fas fa-user-plus"></i>
                                    <p><?php echo ($search || $status || $grade_level || $date_range || $school_year_filter)
                                        ? 'No enrollment records match the selected filters.'
                                        : 'No enrollment records found.'; ?></p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="pagination-wrap">
                <ul class="pagination">
                    <?php if ($page > 1): ?>
                        <li><a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&grade_level=<?php echo urlencode($grade_level); ?>&date_range=<?php echo urlencode($date_range); ?>&school_year=<?php echo (int)$school_year_filter; ?>">Prev</a></li>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="<?php echo $i == $page ? 'active' : ''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&grade_level=<?php echo urlencode($grade_level); ?>&date_range=<?php echo urlencode($date_range); ?>&school_year=<?php echo (int)$school_year_filter; ?>"><?php echo $i; ?></a></li>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <li><a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&grade_level=<?php echo urlencode($grade_level); ?>&date_range=<?php echo urlencode($date_range); ?>&school_year=<?php echo (int)$school_year_filter; ?>">Next</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php if ($can_delete_student): ?>
<!-- Delete Student confirmation (Administrator only) -->
<div class="modal fade" id="deleteStudentModal" tabindex="-1" aria-labelledby="deleteStudentModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="delete_student.php" id="deleteStudentForm">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteStudentModalLabel" style="color:#C94A4A;">
                    <i class="fas fa-triangle-exclamation"></i> Delete Student
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <table class="table table-sm mb-3" style="font-size:14px;">
                    <tbody>
                        <tr><th style="width:110px;">Student</th><td id="dsName"></td></tr>
                        <tr><th>LRN</th><td id="dsLrn"></td></tr>
                        <tr><th>Grade</th><td id="dsGrade"></td></tr>
                        <tr><th>Section</th><td id="dsSection"></td></tr>
                    </tbody>
                </table>
                <div class="alert alert-danger mb-0" role="alert">
                    <strong>This action will permanently delete this student and related records.</strong>
                    <div style="font-size:13px; margin-top:4px;">
                        Enrollment, requirements, grades, attendance, assignments and other data linked to this student are removed. This cannot be undone.
                    </div>
                </div>

                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                <input type="hidden" name="enrollment_id" id="dsEnrollmentId" value="">
                <input type="hidden" name="student_id" id="dsStudentId" value="">
                <input type="hidden" name="confirm" value="yes">
                <!-- keep the admin's filters / page after deleting -->
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
                <input type="hidden" name="grade_level" value="<?php echo htmlspecialchars($grade_level); ?>">
                <input type="hidden" name="date_range" value="<?php echo htmlspecialchars($date_range); ?>">
                <input type="hidden" name="school_year" value="<?php echo (int)$school_year_filter ?: ''; ?>">
                <input type="hidden" name="page" value="<?php echo (int)$page; ?>">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger" id="dsConfirmBtn">
                    <i class="fas fa-trash-alt"></i> Delete Permanently
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($can_delete_student): ?>
<script>
// Bootstrap's JS is loaded by the footer, so wire the modal up once the page is ready.
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('deleteStudentModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;
    // Move the modal to <body> so no page container can trap it under the backdrop.
    document.body.appendChild(modalEl);
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    var form = document.getElementById('deleteStudentForm');
    var btn = document.getElementById('dsConfirmBtn');

    document.querySelectorAll('.js-delete-student').forEach(function (a) {
        a.addEventListener('click', function (ev) {
            ev.preventDefault();
            document.getElementById('dsName').textContent = a.dataset.name || '';
            document.getElementById('dsLrn').textContent = a.dataset.lrn || '';
            document.getElementById('dsGrade').textContent = a.dataset.grade || '';
            document.getElementById('dsSection').textContent = a.dataset.section || '';
            document.getElementById('dsEnrollmentId').value = a.dataset.enrollmentId || '';
            document.getElementById('dsStudentId').value = a.dataset.studentId || '';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete Permanently';
            modal.show();
        });
    });

    // Prevent a double-click from submitting twice.
    form.addEventListener('submit', function () {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Deleting...';
    });
});
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>