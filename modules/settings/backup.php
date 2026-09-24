<?php
// modules/settings/backup.php
// Backup & Restore — download a full database dump, or restore from one.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Backup & Restore';
$current_page = 'settings';
$base_url = BASE_URL;

global $db, $dbname;
$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$table_count = count($tables);

$total_rows = 0;
foreach ($tables as $t) {
    $total_rows += (int) $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}

$csrf_token = generateCSRFToken();

include '../../includes/header.php';
?>

<style>
    .backup-page h4, .backup-page h6 { font-family: var(--font-primary, 'Inter', sans-serif); }
    .backup-icon-hero {
        width: 56px; height: 56px; border-radius: 14px; display: flex; align-items: center;
        justify-content: center; font-size: 22px; margin-bottom: 14px;
    }
</style>

<div class="container-fluid py-4 backup-page">
    <h4 class="fw-bold mb-1"><i class="fas fa-database me-2" style="color:var(--ink);"></i>Backup &amp; Restore</h4>
    <p class="text-muted mb-4 small">Download a full copy of the database, or restore it from a previously downloaded backup.</p>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card stats-card border-0 rounded-3">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div><h6 class="mb-1">Tables</h6><h2 class="mb-0"><?php echo $table_count; ?></h2></div>
                    <div class="stats-icon bg-primary bg-opacity-10"><i class="fas fa-table text-primary"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card stats-card border-0 rounded-3">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div><h6 class="mb-1">Total Records</h6><h2 class="mb-0"><?php echo number_format($total_rows); ?></h2></div>
                    <div class="stats-icon bg-info bg-opacity-10"><i class="fas fa-layer-group text-info"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- BACKUP -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body">
                    <div class="backup-icon-hero bg-success bg-opacity-10 text-success"><i class="fas fa-download"></i></div>
                    <h5 class="fw-bold">Create Backup</h5>
                    <p class="text-muted small">
                        Downloads a complete <code>.sql</code> file with every table's structure and data —
                        the same format phpMyAdmin and mysqldump produce. Save it somewhere safe (Google Drive,
                        an external drive) outside the server.
                    </p>
                    <a href="backup_download.php" class="btn btn-dark" style="background:var(--ink); border-color:var(--ink);">
                        <i class="fas fa-download me-2"></i>Download Backup Now
                    </a>
                    <p class="text-muted small mt-3 mb-0">
                        <i class="fas fa-circle-info me-1"></i>
                        For <?php echo number_format($total_rows); ?> records across <?php echo $table_count; ?> tables,
                        this may take a moment — please don't close the tab while it downloads.
                    </p>
                </div>
            </div>
        </div>

        <!-- RESTORE -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body">
                    <div class="backup-icon-hero bg-danger bg-opacity-10 text-danger"><i class="fas fa-triangle-exclamation"></i></div>
                    <h5 class="fw-bold">Restore from Backup</h5>
                    <div class="alert alert-danger small">
                        <strong>This overwrites existing data.</strong> Restoring replaces tables in this database
                        with whatever is in the uploaded file. This cannot be undone. Download a fresh backup
                        first (left) if you want to be able to go back.
                    </div>
                    <form method="POST" action="backup_restore.php" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Select .sql backup file</label>
                            <input type="file" name="sql_file" accept=".sql" class="form-control" required>
                        </div>
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="confirmRestore" name="confirm_restore" value="1" required>
                            <label class="form-check-label small" for="confirmRestore">
                                I understand this will overwrite existing data and cannot be undone.
                            </label>
                        </div>
                        <button type="submit" class="btn btn-outline-danger" onclick="return appConfirm(this, 'This will overwrite your current data. Are you sure you want to continue?', {danger: true, okText: 'Restore', title: 'Restore Database'});">
                            <i class="fas fa-upload me-2"></i>Restore from File
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>