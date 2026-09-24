<?php
// modules/students/deleted.php
// "Recycle Bin" — lists soft-deleted (is_active = 0) students, since
// index.php only ever shows active ones and restore.php had no UI
// entry point before this. Restore or permanently delete from here.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Registrar'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Deleted Students';
$current_page = 'students';
$base_url = BASE_URL;

$search = isset($_GET['q']) ? trim(sanitize($_GET['q'])) : '';

$sql = "SELECT student_id, lrn, first_name, last_name, grade_level, strand, updated_at
        FROM students WHERE is_active = 0";
$params = [];
if ($search !== '') {
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR lrn LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like, $like];
}
$sql .= " ORDER BY updated_at DESC";
$deleted_students = getRecords($sql, $params);

$csrf_token = generateCSRFToken();

include '../../includes/header.php';
?>

<style>
    .del-page h4, .del-page h6 { font-family: var(--font-primary, 'Inter', sans-serif); }
    .del-row-name { font-weight: 600; color: var(--ink, #0F1A3C); }
    .del-row-lrn { font-family: 'IBM Plex Mono', monospace; font-size: 12px; color: var(--text-muted, #6b7280); }
    .del-action-btn {
        display: inline-flex; align-items: center; gap: 6px;
        font-size: 12.5px; font-weight: 600; padding: 6px 12px; border-radius: 8px;
        margin-bottom: 4px; text-decoration: none;
    }
    .del-action-restore { background: #EAF7F1; color: #1E8E5A; border: 1px solid #BEE3CE; }
    .del-action-restore:hover { background: #1E8E5A; color: #fff; }
    .del-action-forever { background: #FDEEEE; color: #B3261E; border: 1px solid #F5C6C6; }
    .del-action-forever:hover { background: #B3261E; color: #fff; }
</style>

<div class="container-fluid py-4 del-page">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="fas fa-trash-can me-2" style="color:var(--ink);"></i>Deleted Students</h4>
            <p class="text-muted mb-0 small">Soft-deleted students. Restore them, or permanently remove if no longer needed.</p>
        </div>
        <a href="<?php echo BASE_URL; ?>modules/students/index.php" class="btn btn-outline-dark">
            <i class="fas fa-arrow-left me-2"></i>Back to Students
        </a>
    </div>

    <?php if (!empty($_SESSION['error_message'])): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['restored'])): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Student restored successfully.</div>
    <?php endif; ?>
    <?php if (isset($_GET['permanent_deleted'])): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Student permanently deleted.</div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-3 mb-3">
        <div class="card-body">
            <form method="GET" class="d-flex gap-2">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search by name or LRN..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-sm btn-dark" style="background:var(--ink); border-color:var(--ink);">Search</button>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-body">
            <?php if (empty($deleted_students)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-box-open mb-2" style="font-size:28px; opacity:0.3; display:block;"></i>
                    No deleted students<?php echo $search !== '' ? ' matching your search' : ''; ?>.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="small text-uppercase text-muted">
                            <tr>
                                <th>Student</th><th>LRN</th><th>Grade</th><th>Strand</th><th>Last Updated</th><th style="width:180px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deleted_students as $s): ?>
                            <tr>
                                <td class="del-row-name"><?php echo htmlspecialchars($s['last_name'] . ', ' . $s['first_name']); ?></td>
                                <td class="del-row-lrn"><?php echo htmlspecialchars($s['lrn']); ?></td>
                                <td><?php echo htmlspecialchars($s['grade_level']); ?></td>
                                <td><?php echo htmlspecialchars($s['strand'] ?: '—'); ?></td>
                                <td class="text-muted small"><?php echo date('M j, Y g:i A', strtotime($s['updated_at'])); ?></td>
                                <td>
                                    <a href="restore.php?id=<?php echo $s['student_id']; ?>&csrf_token=<?php echo urlencode($csrf_token); ?>"
                                       class="btn btn-sm del-action-btn del-action-restore d-flex justify-content-center"
                                       title="Restore this student"
                                       onclick="return appConfirm(this, <?php echo htmlspecialchars(json_encode('Restore ' . $s['last_name'] . ', ' . $s['first_name'] . '?'), ENT_QUOTES); ?>, {okText: 'Restore'})">
                                        <i class="fas fa-rotate-left"></i> Restore
                                    </a>
                                    <a href="delete_permanent.php?id=<?php echo $s['student_id']; ?>&confirm=yes&csrf_token=<?php echo urlencode($csrf_token); ?>"
                                       class="btn btn-sm del-action-btn del-action-forever d-flex justify-content-center"
                                       title="Permanently delete — cannot be undone"
                                       onclick="return appConfirm(this, <?php echo htmlspecialchars(json_encode('PERMANENTLY delete ' . $s['last_name'] . ', ' . $s['first_name'] . '? This cannot be undone.'), ENT_QUOTES); ?>, {danger: true, okText: 'Delete Forever'})">
                                        <i class="fas fa-trash-can"></i> Delete Forever
                                    </a>
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