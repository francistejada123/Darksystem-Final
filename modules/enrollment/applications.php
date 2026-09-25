<?php
// modules/enrollment/applications.php
// ADMIN — inbox of ONLINE enrollment applications submitted through the public form.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/enrollment_helpers_online.php';
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
    WHERE $where
    ORDER BY FIELD(a.status,'Pending','Under Review','For Correction','Approved','Rejected'), a.created_at DESC
", $params);

$counts = getRecord("
    SELECT
      SUM(status = 'Pending') AS pending,
      SUM(status = 'Under Review') AS reviewing,
      SUM(status = 'For Correction') AS correction,
      SUM(status = 'Approved') AS approved,
      SUM(status = 'Rejected') AS rejected
    FROM online_applications
");

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
        <a href="index.php" class="btn-secondary-link">
            <i class="fas fa-user-plus"></i> Walk-in Enrollment
        </a>
    </div>

    <!-- Status Cards -->
    <div class="stat-cards">
        <?php
        $cards = [
            ['Pending', intval($counts['pending'] ?? 0), '#B7791F', '#fef9e7', 'fa-hourglass-half'],
            ['Under Review', intval($counts['reviewing'] ?? 0), '#667085', '#f3f4f6', 'fa-magnifying-glass'],
            ['For Correction', intval($counts['correction'] ?? 0), '#B45309', '#FFF4E5', 'fa-pen-to-square'],
            ['Approved', intval($counts['approved'] ?? 0), '#238863', '#e8f5e9', 'fa-circle-check'],
            ['Rejected', intval($counts['rejected'] ?? 0), '#C94A4A', '#fdecea', 'fa-circle-xmark'],
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
                <?php foreach (['Pending', 'Under Review', 'For Correction', 'Approved', 'Rejected'] as $s): ?>
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
                        $badge = ['Pending' => 'pending', 'Under Review' => 'review', 'For Correction' => 'correction', 'Approved' => 'approved', 'Rejected' => 'rejected'][$a['status']] ?? 'pending'; ?>
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
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php include '../../includes/footer.php'; ?>