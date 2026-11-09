<?php
// modules/teachers/incomplete_requirements.php
// ADVISER — students of MY advisory section who still have pending enrollment requirements.
// Reminder only (parent meetings, card distribution ...): shows the requirement STATUS and the NAME of
// what is missing. No uploaded file, no download link, no approve / reject / update action.
// Only the section's own adviser (sections.adviser_id = me) gets rows; other roles are sent away.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/enrollment_helpers_online.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Teacher')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Incomplete Requirements';
$current_page = 'teachers';
$base_url = '../../';

$rows = oeAdviserIncompleteRequirements((int) $_SESSION['user_id']);

include '../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <h4 class="mb-1"><i class="fas fa-file-circle-exclamation me-2" style="color:#B45309;"></i>Students With Incomplete Requirements</h4>
        <div class="text-muted" style="font-size:14px;">Your advisory section only &middot; for reminders to parents / guardians. The Admin / Enrollment Officer verifies the documents.</div>
    </div>
    <a href="../../index.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Dashboard</a>
</div>

<div class="card shadow-sm rounded-3">
    <div class="card-body p-0">
        <?php if ($rows): ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>LRN</th>
                        <th>Grade &amp; Section</th>
                        <th>Missing Requirement</th>
                        <th>Requirement Status</th>
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): foreach ($r['pending'] as $i => $p): ?>
                    <tr>
                        <?php if ($i === 0): $span = count($r['pending']); ?>
                        <td rowspan="<?php echo $span; ?>"><strong><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></strong></td>
                        <td rowspan="<?php echo $span; ?>"><?php echo htmlspecialchars((string) $r['lrn']); ?></td>
                        <td rowspan="<?php echo $span; ?>"><?php echo htmlspecialchars($r['grade_level'] . ' – ' . $r['section_name']); ?></td>
                        <?php endif; ?>
                        <td><?php echo htmlspecialchars($p['label']); ?></td>
                        <td><span class="badge" style="background:#FFF4E5;color:#B45309;border:1px solid #F5C58A;">&#9888; <?php echo htmlspecialchars($p['state']); ?></span></td>
                        <?php if ($i === 0): ?>
                        <td rowspan="<?php echo $span; ?>"><?php echo $r['updated'] ? htmlspecialchars(date('M d, Y', strtotime($r['updated']))) : '-'; ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-circle-check fa-2x d-block mb-2" style="color:#0F7B4E;"></i>
            No students in your section have pending requirements.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
