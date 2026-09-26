<?php
// modules/enrollment/applications_deleted.php
// Recycle Bin for Online Applications (like modules/students/deleted.php):
// soft-deleted applications. Restore them, or permanently delete (Administrator).

require_once __DIR__ . '/../../config/database.php';
if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/enrollment_helpers_online.php';

if (!hasAnyRole(['Administrator', 'Registrar'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Deleted Applications';
$current_page = 'applications';
$base_url = BASE_URL;

$search = trim(sanitize($_GET['q'] ?? ''));
$sql = "SELECT a.application_id, a.reference_no, a.lrn, a.first_name, a.last_name, a.grade_level, a.strand, a.application_type, a.status, a.deleted_at,
               CONCAT(u.first_name, ' ', u.last_name) AS deleted_by_name,
               (SELECT COUNT(*) FROM application_documents d WHERE d.application_id = a.application_id) AS doc_count
        FROM online_applications a
        LEFT JOIN users u ON u.user_id = a.deleted_by
        WHERE a.deleted_at IS NOT NULL";
$params = [];
if ($search !== '') {
    $sql .= " AND (a.reference_no LIKE ? OR a.lrn LIKE ? OR a.first_name LIKE ? OR a.last_name LIKE ? OR CONCAT(a.first_name, ' ', a.last_name) LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like, $like, $like];
}
$sql .= " ORDER BY a.deleted_at DESC";
$rows = getRecords($sql, $params);
$is_admin = hasRole('Administrator');
$csrf_token = generateCSRFToken();

include '../../includes/header.php';
?>

<style>
    .del-page h4 { font-size: 24px; }
    .del-page p.small { font-size: 14.5px !important; }
    .del-page table td { font-size: 15px; vertical-align: middle; }
    .del-page table th { font-size: 12.5px; }
    .del-row-name { font-weight: 600; color: var(--ink, #0F1A3C); }
    .del-row-ref { font-family: 'IBM Plex Mono', monospace; font-size: 13px; color: var(--text-muted, #6b7280); }
    .del-action-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 13.5px; font-weight: 600; padding: 7px 14px; border-radius: 8px; margin-bottom: 4px; text-decoration: none; width: 100%; }
    .del-action-restore { background: #EAF7F1; color: #1E8E5A; border: 1px solid #BEE3CE; }
    .del-action-restore:hover { background: #1E8E5A; color: #fff; }
    .del-action-forever { background: #FDEEEE; color: #B3261E; border: 1px solid #F5C6C6; }
    .del-action-forever:hover { background: #B3261E; color: #fff; }
</style>

<div class="container-fluid py-4 del-page">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="fas fa-trash-can me-2" style="color:var(--ink);"></i>Deleted Applications</h4>
            <p class="text-muted mb-0 small">Soft-deleted online applications. Restore them, or permanently remove them if no longer needed.</p>
        </div>
        <a href="applications.php" class="btn btn-outline-dark"><i class="fas fa-arrow-left me-2"></i>Back to Online Applications</a>
    </div>

    <div class="card border-0 shadow-sm rounded-3 mb-3">
        <div class="card-body">
            <form method="GET" class="d-flex gap-2">
                <input type="text" name="q" class="form-control" placeholder="Search by reference no., name or LRN..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-dark" style="background:var(--ink); border-color:var(--ink);">Search</button>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-body">
            <?php if (!$rows): ?>
                <div class="text-center text-muted py-5" style="font-size:15px;">
                    <i class="fas fa-box-open mb-2" style="font-size:28px; opacity:0.3; display:block;"></i>
                    No deleted applications<?php echo $search !== '' ? ' matching your search' : ''; ?>.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead class="text-uppercase text-muted">
                            <tr><th>Reference No.</th><th>Applicant</th><th>Grade</th><th>Type</th><th>Status</th><th>Docs</th><th>Deleted</th><th style="width:190px;">Actions</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $a):
                            $nm = $a['last_name'] . ', ' . $a['first_name']; ?>
                            <tr>
                                <td class="del-row-ref"><?php echo htmlspecialchars($a['reference_no']); ?></td>
                                <td><span class="del-row-name"><?php echo htmlspecialchars($nm); ?></span><br><small class="text-muted">LRN: <?php echo htmlspecialchars($a['lrn'] ?: 'N/A'); ?></small></td>
                                <td><?php echo htmlspecialchars($a['grade_level']); ?><?php echo $a['strand'] ? ' · ' . htmlspecialchars($a['strand']) : ''; ?></td>
                                <td><?php echo htmlspecialchars($a['application_type']); ?></td>
                                <td><?php echo htmlspecialchars($a['status']); ?></td>
                                <td><?php echo (int) $a['doc_count']; ?></td>
                                <td class="text-muted"><?php echo date('M j, Y g:i A', strtotime($a['deleted_at'])); ?><?php if (!empty(trim((string) $a['deleted_by_name']))): ?><br><small>by <?php echo htmlspecialchars($a['deleted_by_name']); ?></small><?php endif; ?></td>
                                <td>
                                    <a href="application_delete.php?action=restore&id=<?php echo (int) $a['application_id']; ?>&csrf_token=<?php echo urlencode($csrf_token); ?>"
                                       class="del-action-btn del-action-restore" title="Restore this application"
                                       onclick="return appConfirm(this, <?php echo htmlspecialchars(json_encode('Restore the application of ' . $nm . '?'), ENT_QUOTES); ?>, {okText: 'Restore'})">
                                        <i class="fas fa-rotate-left"></i> Restore
                                    </a>
                                    <?php if ($is_admin): ?>
                                    <a href="application_delete.php?action=permanent&confirm=yes&from=bin&id=<?php echo (int) $a['application_id']; ?>&csrf_token=<?php echo urlencode($csrf_token); ?>"
                                       class="del-action-btn del-action-forever" title="Permanently delete — cannot be undone"
                                       onclick="return appConfirm(this, <?php echo htmlspecialchars(json_encode('PERMANENTLY delete the application of ' . $nm . ' and its uploaded documents? This cannot be undone.'), ENT_QUOTES); ?>, {danger: true, okText: 'Delete Forever'})">
                                        <i class="fas fa-trash-can"></i> Delete Forever
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
