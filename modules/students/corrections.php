<?php
// modules/students/corrections.php
// Registrar / Administrator: review correction requests for personal and
// guardian information (filed from Edit Student or the student's My Profile).
// Only an approved request changes the student record (includes/student_records_helpers.php).

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student_records_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
if (!hasAnyRole(['Registrar', 'Administrator'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}
srEnsureSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token.';
    } else {
        $approve = ($_POST['decision'] ?? '') === 'approve';
        $r = srReviewCorrection((int) ($_POST['request_id'] ?? 0), $approve, (int) $_SESSION['user_id'], (string) ($_POST['review_note'] ?? ''));
        if ($r['success']) {
            $_SESSION['success_message'] = $approve ? 'Approved — the student record was updated.' : 'Request rejected.';
        } else {
            $_SESSION['error_message'] = $r['error'];
        }
    }
    header('Location: corrections.php' . (isset($_GET['all']) ? '?all=1' : ''));
    exit;
}

$page_title = 'Correction Requests';
$current_page = 'students';
$base_url = BASE_URL;
$show_all = isset($_GET['all']);
$fields = srCorrectionFields();
$requests = getRecords(
    "SELECT c.*, s.lrn, s.first_name, s.last_name, s.grade_level,
            u.first_name AS rv_first, u.last_name AS rv_last,
            ru.first_name AS rq_first, ru.last_name AS rq_last
     FROM student_info_corrections c
     JOIN students s ON s.student_id = c.student_id
     LEFT JOIN users u ON u.user_id = c.reviewed_by
     LEFT JOIN users ru ON ru.user_id = c.requested_by_id AND c.requested_by_role <> 'Student'
     " . ($show_all ? '' : "WHERE c.status = 'Pending'") . "
     ORDER BY (c.status = 'Pending') DESC, c.request_id DESC
     LIMIT 200"
);
$csrf_token = generateCSRFToken();
include '../../includes/header.php';
?>
<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Correction Requests</h1>
        <p class="page-subtitle">Personal and guardian information changes only after it is approved here.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="?<?php echo $show_all ? '' : 'all=1'; ?>" class="btn btn-outline-secondary btn-sm"><?php echo $show_all ? 'Pending only' : 'Show all (history)'; ?></a>
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Students</a>
    </div>
</div>

<div class="card app-card">
    <div class="card-body p-0">
        <?php if (empty($requests)): ?>
            <div class="text-center text-muted py-5"><i class="fas fa-check-circle fa-2x text-success d-block mb-2"></i>No <?php echo $show_all ? '' : 'pending '; ?>correction requests.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="font-size:15px;">
                    <thead><tr><th>Student</th><th>Information</th><th>Current</th><th>Requested</th><th>Reason</th><th>Requested by</th><th style="min-width:260px;">Decision</th></tr></thead>
                    <tbody>
                    <?php foreach ($requests as $rq): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($rq['last_name'] . ', ' . $rq['first_name']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($rq['lrn'] . ' · ' . $rq['grade_level']); ?></small></td>
                            <td><?php echo htmlspecialchars($fields[$rq['field_name']] ?? $rq['field_name']); ?></td>
                            <td><?php echo htmlspecialchars((string) $rq['old_value']); ?></td>
                            <td><strong><?php echo htmlspecialchars($rq['new_value']); ?></strong></td>
                            <td><?php echo htmlspecialchars($rq['reason']); ?></td>
                            <td><?php echo $rq['requested_by_role'] === 'Student' ? 'Student' : htmlspecialchars(trim(($rq['rq_first'] ?? '') . ' ' . ($rq['rq_last'] ?? '')) . ' (' . $rq['requested_by_role'] . ')'); ?><br>
                                <small class="text-muted"><?php echo date('M j, Y g:i A', strtotime($rq['created_at'])); ?></small></td>
                            <td>
                                <?php if ($rq['status'] === 'Pending'): ?>
                                    <form method="POST" class="d-flex flex-column gap-1">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="request_id" value="<?php echo (int) $rq['request_id']; ?>">
                                        <input type="text" name="review_note" class="form-control form-control-sm" maxlength="500" placeholder="Note (optional)">
                                        <div class="d-flex gap-1">
                                            <button type="submit" name="decision" value="approve" class="btn btn-sm btn-success flex-fill"
                                                    onclick="return confirm('Approve and change the student record?')"><i class="fas fa-check me-1"></i>Approve</button>
                                            <button type="submit" name="decision" value="reject" class="btn btn-sm btn-outline-danger flex-fill"><i class="fas fa-xmark me-1"></i>Reject</button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <span class="badge <?php echo $rq['status'] === 'Approved' ? 'bg-success' : 'bg-danger'; ?>"><?php echo $rq['status']; ?></span>
                                    <small class="text-muted d-block">by <?php echo htmlspecialchars(trim(($rq['rv_first'] ?? '') . ' ' . ($rq['rv_last'] ?? ''))); ?><?php echo $rq['reviewed_at'] ? ' · ' . date('M j, Y', strtotime($rq['reviewed_at'])) : ''; ?></small>
                                    <?php if (!empty($rq['review_note'])): ?><small class="d-block"><?php echo htmlspecialchars($rq['review_note']); ?></small><?php endif; ?>
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
<?php include '../../includes/footer.php'; ?>
