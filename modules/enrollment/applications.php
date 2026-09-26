<?php
// modules/enrollment/applications.php
// ADMIN — inbox of ONLINE enrollment applications submitted through the public form.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/enrollment_helpers_online.php';
oeEnsureCorrectionStatus(); // Rejected / For Correction -> Incomplete Requirements (once)
require_once __DIR__ . '/../../includes/attendance_qr_helpers.php'; // attQrLrnLink()

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Registrar', 'Enrollment Officer'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Online Applications';
$current_page = 'applications';
$base_url = BASE_URL;

$search = sanitize($_GET['search'] ?? '');
$status = sanitize($_GET['status'] ?? '');
$grade_level = sanitize($_GET['grade_level'] ?? '');

$where = '1=1';
$params = [];
if ($search) {
    $where .= " AND (a.reference_no LIKE ? OR a.lrn LIKE ? OR a.first_name LIKE ? OR a.last_name LIKE ?)";
    $p = "%$search%";
    $params = array_merge($params, [$p, $p, $p, $p]);
}
if ($status) { $where .= " AND a.status = ?"; $params[] = $status; }
if ($grade_level) { $where .= " AND a.grade_level = ?"; $params[] = $grade_level; }

$applications = getRecords("
    SELECT a.*, st.lrn AS student_lrn,
           (SELECT COUNT(*) FROM application_documents d WHERE d.application_id = a.application_id) AS doc_count
    FROM online_applications a
    LEFT JOIN students st ON st.student_id = a.student_id
    WHERE $where AND a.deleted_at IS NULL
    ORDER BY FIELD(a.status,'Pending','Under Review','Incomplete Requirements','Approved'), a.created_at DESC
", $params);

$counts = getRecord("
    SELECT
      SUM(status = 'Pending') AS pending,
      SUM(status = 'Under Review') AS reviewing,
      SUM(status IN ('Incomplete Requirements','For Correction','Rejected')) AS incomplete,
      SUM(status = 'Approved') AS approved,
      0 AS rejected
    FROM online_applications
    WHERE deleted_at IS NULL
");
$can_delete = hasAnyRole(['Administrator', 'Registrar']);
$can_delete_forever = hasRole('Administrator');
$trash_count = $can_delete ? (int) (getRecord("SELECT COUNT(*) AS c FROM online_applications WHERE deleted_at IS NOT NULL")['c'] ?? 0) : 0;

include '../../includes/header.php';
?>

<style>
/* This page's stat-cards/filter-card/table-card/data-table/status-badge/
   btn-view/empty-state now come from the shared .enroll-page, .app-inbox
   rules in static/css/style.css — see modules/enrollment/index.php, which
   used to keep an almost-identical copy of this same block. */
</style>

<div class="app-inbox" style="padding: 24px;">

    <div class="page-header">
        <div>
            <h4>Online Enrollment Applications</h4>
            <p>Applications submitted by students through the public online enrollment form</p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php if ($can_delete): ?>
            <a href="applications_deleted.php" class="btn-secondary-link" style="color:#B3261E;border-color:#F5C6C6;">
                <i class="fas fa-trash-can"></i> Recycle Bin<?php echo $trash_count ? ' (' . $trash_count . ')' : ''; ?>
            </a>
            <?php endif; ?>
            <a href="index.php" class="btn-secondary-link">
                <i class="fas fa-user-plus"></i> Walk-in Enrollment
            </a>
        </div>
    </div>

    <!-- Status Cards -->
    <div class="stat-cards">
        <?php
        $cards = [
            ['Pending', intval($counts['pending'] ?? 0), '#B7791F', '#fef9e7', 'fa-hourglass-half'],
            ['Under Review', intval($counts['reviewing'] ?? 0), '#667085', '#f3f4f6', 'fa-magnifying-glass'],
            ['Incomplete Requirements', intval($counts['incomplete'] ?? 0), '#B45309', '#FFF4E5', 'fa-file-circle-exclamation'],
            ['Approved', intval($counts['approved'] ?? 0), '#238863', '#e8f5e9', 'fa-circle-check'],
        ];
        foreach ($cards as $c): ?>
            <div class="stat-card">
                <div class="stat-icon" style="background: <?php echo $c[3]; ?>; color: <?php echo $c[2]; ?>;">
                    <i class="fas <?php echo $c[4]; ?>"></i>
                </div>
                <div>
                    <div class="stat-value"><?php echo $c[1]; ?></div>
                    <div class="stat-label"><?php echo $c[0]; ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="filter-card">
        <form method="GET">
            <input type="text" name="search" placeholder="Search reference no., LRN or name" value="<?php echo htmlspecialchars($search); ?>" style="flex: 1; min-width: 200px;">
            <select name="status">
                <option value="">All Status</option>
                <?php foreach (['Pending', 'Under Review', 'Incomplete Requirements', 'Approved'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $status === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                <?php endforeach; ?>
            </select>
            <select name="grade_level">
                <option value="">All Grades</option>
                <?php foreach (getOnlineGradeLevels() as $g): ?>
                    <option value="<?php echo $g; ?>" <?php echo $grade_level === $g ? 'selected' : ''; ?>><?php echo $g; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-view" style="padding: 9px 18px; height: 42px;">
                <i class="fas fa-filter"></i> Filter
            </button>
        </form>
    </div>

    <!-- Table -->
    <div class="table-card">
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Reference No.</th>
                        <th>Applicant</th>
                        <th>Grade / Strand</th>
                        <th>Type</th>
                        <th>Docs</th>
                        <th>Ave.</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($applications)): ?>
                        <tr>
                            <td colspan="9">
                                <div class="empty-state">
                                    <i class="fas fa-inbox"></i>
                                    <p>No online applications yet.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: foreach ($applications as $a):
                        $badge = ['Pending' => 'pending', 'Under Review' => 'review', 'For Correction' => 'incomplete', 'Incomplete Requirements' => 'incomplete', 'Approved' => 'approved', 'Rejected' => 'incomplete'][$a['status']] ?? 'pending'; ?>
                        <tr>
                            <td><span class="ref-code"><?php echo htmlspecialchars($a['reference_no']); ?></span></td>
                            <td>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($a['last_name'] . ', ' . $a['first_name']); ?></div>
                                <div style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">LRN: <?php echo attQrLrnLink($a['lrn'] ?: ($a['student_lrn'] ?: 'N/A')); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($a['grade_level']); ?><?php echo $a['strand'] ? ' <span class="strand-badge">' . htmlspecialchars($a['strand']) . '</span>' : ''; ?></td>
                            <td style="font-size: 14px; color: var(--text-muted);"><?php echo htmlspecialchars($a['application_type']); ?></td>
                            <td><span class="doc-count"><?php echo intval($a['doc_count']); ?></span></td>
                            <td style="font-weight: 600;"><?php echo (float) $a['average_grade'] > 0 ? number_format((float) $a['average_grade'], 2) : '&mdash;'; ?></td>
                            <td style="font-size: 14px; color: var(--text-muted); white-space: nowrap;"><?php echo formatDate($a['created_at'], 'M d, Y'); ?></td>
                            <td><span class="status-badge status-<?php echo $badge; ?>"><?php echo htmlspecialchars($a['status']); ?></span></td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="view_application.php?id=<?php echo $a['application_id']; ?>" class="btn-view" aria-label="Review application <?php echo htmlspecialchars($a['reference_no']); ?>">
                                    <i class="fas fa-eye"></i> Review
                                </a>
                                <?php if ($can_delete): ?>
                                <button type="button" class="btn-app-delete" title="Delete application" aria-label="Delete application <?php echo htmlspecialchars($a['reference_no']); ?>"
                                        onclick="showDeleteOptions(<?php echo (int) $a['application_id']; ?>, <?php echo htmlspecialchars(json_encode($a['last_name'] . ', ' . $a['first_name'] . ' (' . $a['reference_no'] . ')'), ENT_QUOTES); ?>)">
                                    <i class="fas fa-trash-can"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<style>
    #deleteModal .modal-content, #permanentDeleteModal .modal-content, #chooseDeleteModal .modal-content {
        border: 1px solid #e5eaf2; border-radius: 18px; overflow: hidden;
        box-shadow: 0 24px 70px rgba(15,26,60,0.22);
    }
    #deleteModal .modal-dialog, #permanentDeleteModal .modal-dialog, #chooseDeleteModal .modal-dialog {
        width: min(590px, calc(100vw - 32px)); max-width: 590px;
    }
    #deleteModal .modal-header, #permanentDeleteModal .modal-header, #chooseDeleteModal .modal-header {
        background: #fff; color: #14213d; border: none; padding: 24px 28px 0;
    }
    #deleteModal .modal-title, #permanentDeleteModal .modal-title, #chooseDeleteModal .modal-title {
        display: none;
    }
    #deleteModal .btn-close, #permanentDeleteModal .btn-close, #chooseDeleteModal .btn-close {
        opacity: .55; margin: -8px -8px 0 auto;
    }
    .del-icon-circle {
        width: 64px; height: 64px; border-radius: 16px; display: flex; align-items: center;
        justify-content: center; margin: 0 0 16px; font-size: 24px;
    }
    #deleteModal .del-icon-circle { background: #FFF3E0; color: #B3261E; }
    #permanentDeleteModal .del-icon-circle { background: #FDEEEE; color: #B3261E; }
    #chooseDeleteModal .del-icon-circle { background: #edf4ff; color: #416b9f; }
    .del-modal-title { font-family: var(--font-primary, 'Inter', sans-serif); font-weight: 700; font-size: 25px; line-height: 1.15; color: var(--ink, #0F1A3C); margin: 0; }
    .del-modal-sub { color: #71829d; font-size: 15px; margin: 7px 0 0; }
    #deleteModal .modal-body, #permanentDeleteModal .modal-body, #chooseDeleteModal .modal-body {
        padding: 0 28px 20px !important;
    }
    #deleteModal .modal-footer, #permanentDeleteModal .modal-footer, #chooseDeleteModal .modal-footer {
        border-top: 1px solid #edf1f6; padding: 18px 28px 24px; gap: 10px;
    }
    .del-btn-cancel {
        background: #eef2f8; color: #71829d; border: 0;
        border-radius: 11px; padding: 11px 22px; font-weight: 700; font-size: 13.5px;
    }
    .del-btn-cancel:hover { background: #e5ebf4; color: #526783; }
    .del-btn-soft {
        background: #263650; border-color: #263650; color: #fff;
        border-radius: 11px; padding: 11px 22px; font-weight: 700; font-size: 13.5px;
    }
    .del-btn-soft:hover { background: #1b2840; color: #fff; }
    .del-btn-danger {
        background: #B3261E; border-color: #B3261E; color: #fff;
        border-radius: 11px; padding: 11px 22px; font-weight: 700; font-size: 13.5px;
    }
    .del-btn-danger:hover { background: #93201A; color: #fff; }
    .del-warning-box {
        background: #FDEEEE; border: 1px solid #F5C6C6; border-radius: 10px; padding: 12px 14px; margin-top: 14px;
    }
    .del-warning-box ul { list-style: none; padding-left: 0; margin: 8px 0 0 0; }
    .del-warning-box li { font-size: 13px; color: var(--ink, #0F1A3C); padding: 2px 0; }
    .delete-choice-list { display:grid; gap:12px; margin-top:24px; }
    .delete-choice {
        width:100%; display:flex; align-items:center; gap:14px; text-align:left;
        padding:15px 16px; border:1px solid #dfe7f3; border-radius:13px; background:#fff;
        color:#253754; transition:background .18s ease, border-color .18s ease, box-shadow .18s ease;
    }
    .delete-choice:hover, .delete-choice:focus-visible {
        border-color:#b9d1f7; background:#f7faff; box-shadow:0 5px 16px rgba(58,104,166,.08); outline:0;
    }
    .delete-choice-icon { width:44px; height:44px; flex:0 0 44px; display:grid; place-items:center; border-radius:13px; background:#edf4ff; color:#416b9f; font-size:19px; }
    .delete-choice.danger .delete-choice-icon { background:#fff0f0; color:#e3343f; }
    .delete-choice-copy { flex:1; min-width:0; }
    .delete-choice-title { display:block; font-size:15px; font-weight:700; margin-bottom:3px; }
    .delete-choice-description { display:block; color:#71829d; font-size:12.5px; line-height:1.45; max-width:390px; }
    .delete-choice-arrow { color:#8495ae; font-size:17px; }
    .delete-choice-radio { width:20px; height:20px; flex:0 0 20px; accent-color:#1976ff; }
    @media (max-width:600px) {
        #deleteModal .modal-dialog, #permanentDeleteModal .modal-dialog, #chooseDeleteModal .modal-dialog { width:calc(100vw - 20px); }
        #deleteModal .modal-header, #permanentDeleteModal .modal-header, #chooseDeleteModal .modal-header { padding-left:20px; padding-right:20px; }
        #deleteModal .modal-body, #permanentDeleteModal .modal-body, #chooseDeleteModal .modal-body { padding-left:20px !important; padding-right:20px !important; }
        #deleteModal .modal-footer, #permanentDeleteModal .modal-footer, #chooseDeleteModal .modal-footer { padding-left:20px; padding-right:20px; }
        .del-modal-title { font-size:22px; }
    }
</style>
<style>
    .btn-app-delete { display:inline-flex; align-items:center; justify-content:center; width:42px; height:42px; margin-left:6px; vertical-align:middle; border-radius:10px; border:1px solid #F5C6C6; background:#FDEEEE; color:#B3261E; font-size:15px; cursor:pointer; transition:background .15s,color .15s; }
    .btn-app-delete:hover { background:#B3261E; color:#fff; }
</style>

<?php if ($can_delete): ?>
<!-- CHOOSE DELETE TYPE (same as Students) -->
<div class="modal fade" id="chooseDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-circle-xmark me-2"></i>Delete Application</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="del-icon-circle"><i class="fas fa-trash-can"></i></div>
                <p class="del-modal-title">Choose delete type</p>
                <p class="del-modal-sub">for <span id="chooseDeleteName">this application</span></p>
                <div class="delete-choice-list">
                    <button type="button" class="delete-choice" id="chooseSoftDeleteBtn">
                        <input class="delete-choice-radio" type="radio" name="deleteType" checked aria-label="Soft Delete">
                        <span class="delete-choice-icon"><i class="fas fa-trash-can"></i></span>
                        <span class="delete-choice-copy">
                            <span class="delete-choice-title">Soft Delete</span>
                            <span class="delete-choice-description">Can be restored later. The application will be moved to the Recycle Bin.</span>
                        </span>
                        <i class="fas fa-chevron-right delete-choice-arrow" aria-hidden="true"></i>
                    </button>
                    <?php if ($can_delete_forever): ?>
                    <button type="button" class="delete-choice danger" id="choosePermanentDeleteBtn">
                        <input class="delete-choice-radio" type="radio" name="deleteType" aria-label="Permanent Delete">
                        <span class="delete-choice-icon"><i class="fas fa-skull-crossbones"></i></span>
                        <span class="delete-choice-copy">
                            <span class="delete-choice-title">Permanent Delete</span>
                            <span class="delete-choice-description">This action cannot be undone. The application and its uploaded documents will be permanently removed.</span>
                        </span>
                        <i class="fas fa-chevron-right delete-choice-arrow" aria-hidden="true"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="del-btn-cancel flex-fill" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="del-btn-soft flex-fill" id="continueDeleteBtn">Continue</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash-can me-2"></i>Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 28px 24px 10px 24px;">
                <div class="del-icon-circle"><i class="fas fa-trash-can"></i></div>
                <p class="del-modal-title" id="deleteMessage">Are you sure you want to delete this application?</p>
                <p class="del-modal-sub" id="deleteSubMessage">This can be restored later.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="del-btn-cancel" data-bs-dismiss="modal" style="border:none;">Cancel</button>
                <a href="#" id="confirmDeleteBtn" class="del-btn-soft"><i class="fas fa-trash-can me-1"></i> Delete</a>
            </div>
        </div>
    </div>
</div>

<?php if ($can_delete_forever): ?>
<div class="modal fade" id="permanentDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-triangle-exclamation me-2"></i>Permanent Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 28px 24px 10px 24px;">
                <div class="del-icon-circle"><i class="fas fa-triangle-exclamation"></i></div>
                <p class="del-modal-title" id="permanentDeleteMessage">This action cannot be undone!</p>
                <div class="del-warning-box">
                    <span class="small fw-semibold" style="color:var(--ink);"><i class="fas fa-circle-info me-1"></i>This will permanently delete:</span>
                    <ul>
                        <li><i class="fas fa-xmark text-danger me-2"></i>Application record</li>
                        <li><i class="fas fa-xmark text-danger me-2"></i>Uploaded documents and their files</li>
                        <li><i class="fas fa-xmark text-danger me-2"></i>Enrollment tracking for this reference number</li>
                    </ul>
                    <span class="small" style="color:var(--text-muted);"><i class="fas fa-circle-info me-1"></i>A student account created when it was approved is kept (delete it from Students if needed).</span>
                </div>
                <p class="del-modal-sub mt-2 mb-0"><i class="fas fa-circle-info me-1"></i>This action is logged for security.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="del-btn-cancel" data-bs-dismiss="modal" style="border:none;">Cancel</button>
                <a href="#" id="confirmPermanentDeleteBtn" class="del-btn-danger"><i class="fas fa-trash-can me-1"></i> Delete Permanently</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
var csrfToken = <?php echo json_encode(generateCSRFToken()); ?>;
var pendingDeleteId = null, pendingDeleteName = null, pendingDeleteType = 'soft';
function escHtml(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
function showDeleteOptions(id, name) {
    pendingDeleteId = id;
    pendingDeleteName = name;
    pendingDeleteType = 'soft';
    document.getElementById('chooseDeleteName').textContent = name;
    document.querySelector('#chooseSoftDeleteBtn .delete-choice-radio').checked = true;
    var perm = document.querySelector('#choosePermanentDeleteBtn .delete-choice-radio');
    if (perm) perm.checked = false;
    new bootstrap.Modal(document.getElementById('chooseDeleteModal')).show();
}
document.getElementById('chooseSoftDeleteBtn').addEventListener('click', function (event) {
    if (event.target.tagName !== 'INPUT') this.querySelector('.delete-choice-radio').checked = true;
    pendingDeleteType = 'soft';
});
var permChoice = document.getElementById('choosePermanentDeleteBtn');
if (permChoice) permChoice.addEventListener('click', function (event) {
    if (event.target.tagName !== 'INPUT') this.querySelector('.delete-choice-radio').checked = true;
    pendingDeleteType = 'permanent';
});
document.getElementById('continueDeleteBtn').addEventListener('click', function () {
    bootstrap.Modal.getInstance(document.getElementById('chooseDeleteModal')).hide();
    var base = 'application_delete.php?id=' + pendingDeleteId + '&from=list&csrf_token=' + encodeURIComponent(csrfToken);
    if (pendingDeleteType === 'permanent') {
        document.getElementById('permanentDeleteMessage').innerHTML = 'Are you sure you want to <strong>PERMANENTLY DELETE</strong> the application of <strong>' + escHtml(pendingDeleteName) + '</strong>?<br><small class="text-muted">This action cannot be undone.</small>';
        document.getElementById('confirmPermanentDeleteBtn').href = base + '&action=permanent&confirm=yes';
    } else {
        document.getElementById('deleteMessage').innerHTML = 'Are you sure you want to delete the application of <strong>' + escHtml(pendingDeleteName) + '</strong>?';
        document.getElementById('deleteSubMessage').innerHTML = 'It moves to the Recycle Bin and can be restored later.';
        document.getElementById('confirmDeleteBtn').href = base + '&action=delete';
    }
    setTimeout(function () {
        new bootstrap.Modal(document.getElementById(pendingDeleteType === 'permanent' ? 'permanentDeleteModal' : 'deleteModal')).show();
    }, 250);
});
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>