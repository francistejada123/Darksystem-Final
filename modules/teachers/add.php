<?php
// modules/teachers/add.php
// Invite New Teacher: the admin enters only the teacher's official email.
// The teacher fills in their own information and creates their own password
// on teacher_register.php (see includes/teacher_invitations.php).

require_once __DIR__ . '/../../config/database.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/teacher_invitations.php';

if (!hasRole('Administrator')) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'Invite New Teacher';
$current_page = 'teachers';
$base_url = '../../';

tiEnsureSchema();
$errors = [];
$email_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? 'invite';
        if ($action === 'invite' || $action === 'resend') {
            if ($action === 'resend') {
                $row = getRecord("SELECT email FROM teacher_invitations WHERE invite_id = ?", [(int) ($_POST['invite_id'] ?? 0)]);
                $email = $row['email'] ?? '';
            } else {
                $email = trim((string) ($_POST['email'] ?? ''));
                $email_value = $email;
            }
            $r = tiInvite($email, (int) $_SESSION['user_id']);
            if (!$r['success']) {
                $errors[] = $r['error'];
            } else {
                if (!empty($r['email_error'])) {
                    $_SESSION['error_message'] = 'The invitation was saved, but the email to ' . $email . ' could not be sent: ' . $r['email_error'] . ' Check Settings → Email, then click Resend.';
                } else {
                    $_SESSION['success_message'] = 'Registration link sent to ' . $email . '.';
                }
                header('Location: add.php');
                exit;
            }
        } elseif ($action === 'cancel') {
            $id = (int) ($_POST['invite_id'] ?? 0);
            $row = getRecord("SELECT email FROM teacher_invitations WHERE invite_id = ? AND used_at IS NULL AND revoked_at IS NULL", [$id]);
            if ($row) {
                executeQuery("UPDATE teacher_invitations SET revoked_at = NOW() WHERE invite_id = ?", [$id]);
                logActivity($_SESSION['user_id'], 'teachers', 'Cancelled teacher invitation', 'Invitation for ' . $row['email'] . ' cancelled');
                $_SESSION['success_message'] = 'Invitation for ' . $row['email'] . ' cancelled. Its link no longer works.';
            }
            header('Location: add.php');
            exit;
        }
    }
}

$invitations = getRecords(
    "SELECT ti.invite_id, ti.email, ti.expires_at, ti.used_at, ti.revoked_at, ti.email_sent, ti.email_error, ti.created_at, ti.user_id,
            u.first_name, u.last_name, u.is_active
     FROM teacher_invitations ti
     LEFT JOIN users u ON u.user_id = ti.user_id
     ORDER BY ti.created_at DESC, ti.invite_id DESC
     LIMIT 30"
);

$csrf_token = generateCSRFToken();
include '../../includes/header.php';
?>

<style>
.tinv-page { padding: 24px; }
.tinv-page .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 12px; }
.tinv-page .page-header h4 { font-size: 26px; font-weight: 700; color: var(--ink); margin: 0; }
.tinv-page .page-header p { margin: 4px 0 0; color: var(--text-muted); font-size: 15px; }
.tinv-page .btn-back { display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; border-radius: 8px; font-size: 14.5px; font-weight: 500; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); text-decoration: none; }
.tinv-page .btn-back:hover { background: var(--surface-hover); color: var(--ink); }
.tinv-page .card-x { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; margin-bottom: 20px; overflow: hidden; }
.tinv-page .card-x-head { padding: 16px 22px; border-bottom: 1px solid var(--border-soft); display: flex; align-items: center; gap: 10px; }
.tinv-page .card-x-head h5 { margin: 0; font-size: 18px; font-weight: 700; color: var(--ink); }
.tinv-page .card-x-body { padding: 22px; }
.tinv-page .invite-form { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
.tinv-page .invite-form label { display: block; font-size: 14.5px; font-weight: 600; color: var(--ink); margin-bottom: 6px; }
.tinv-page .invite-form input { width: 420px; max-width: 100%; height: 46px; padding: 0 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 15.5px; background: var(--surface); color: var(--ink); }
.tinv-page .invite-form input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.15); }
.tinv-page .btn-send { display: inline-flex; align-items: center; gap: 8px; height: 46px; padding: 0 22px; border: 0; border-radius: 8px; background: #14213D; color: #fff; font-size: 15.5px; font-weight: 600; cursor: pointer; }
.tinv-page .btn-send:hover { filter: brightness(1.15); }
.tinv-page .steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-top: 20px; }
.tinv-page .step { padding: 14px; border: 1px solid var(--border-soft); border-radius: 10px; background: var(--surface-hover); font-size: 14px; color: var(--text-muted); line-height: 1.45; }
.tinv-page .step b { display: block; color: var(--ink); font-size: 14.5px; margin-bottom: 3px; }
.tinv-page .error-alert { background: #fdecea; border: 1px solid #f5c6cb; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; color: #C94A4A; font-size: 14.5px; }
.tinv-page table { width: 100%; border-collapse: collapse; }
.tinv-page th { padding: 12px 18px; background: var(--surface-hover); color: var(--text-muted); font-size: 12.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; text-align: left; border-bottom: 1px solid var(--border); }
.tinv-page td { padding: 14px 18px; border-bottom: 1px solid var(--border-soft); font-size: 15px; color: var(--ink); vertical-align: middle; }
.tinv-page td small { display: block; color: var(--text-muted); font-size: 13px; margin-top: 2px; }
.tinv-page .pill { display: inline-block; padding: 5px 12px; border-radius: 999px; font-size: 13px; font-weight: 600; white-space: nowrap; }
.tinv-page .pill-pending { background: #FFF4E5; color: #B45309; }
.tinv-page .pill-active { background: #EAF7F1; color: #238863; }
.tinv-page .pill-inactive, .tinv-page .pill-cancelled, .tinv-page .pill-expired { background: #F2F4F7; color: #475467; }
.tinv-page .row-actions { display: flex; gap: 8px; justify-content: flex-end; }
.tinv-page .row-actions button, .tinv-page .row-actions a { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface); color: var(--ink); font-size: 14px; font-weight: 500; text-decoration: none; cursor: pointer; }
.tinv-page .row-actions .danger { color: #C23A3A; border-color: #F1C4C4; }
.tinv-page .row-actions button:hover, .tinv-page .row-actions a:hover { background: var(--surface-hover); }
.tinv-page .empty { padding: 28px; text-align: center; color: var(--text-muted); font-size: 15px; }
@media (max-width: 900px) { .tinv-page .steps { grid-template-columns: 1fr 1fr; } }
</style>

<div class="tinv-page">
    <div class="page-header">
        <div>
            <h4><i class="fas fa-envelope-open-text me-2"></i>Invite New Teacher</h4>
            <p>Enter the new teacher's official email. They fill in their own information and create their own password.</p>
        </div>
        <a href="index.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Teachers</a>
    </div>

    <?php if ($errors): ?>
        <div class="error-alert"><?php foreach ($errors as $er): ?><div><i class="fas fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($er); ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="card-x">
        <div class="card-x-head"><i class="fas fa-paper-plane"></i><h5>Send a registration link</h5></div>
        <div class="card-x-body">
            <form method="POST" class="invite-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="invite">
                <div>
                    <label for="inviteEmail">Teacher's official email <span style="color:#C94A4A">*</span></label>
                    <input type="email" id="inviteEmail" name="email" required maxlength="100" placeholder="teacher@school.edu.ph" value="<?php echo htmlspecialchars($email_value); ?>">
                </div>
                <button type="submit" class="btn-send"><i class="fas fa-paper-plane"></i> Send Teacher Registration Link</button>
            </form>
            <div class="steps">
                <div class="step"><b>1. Invitation sent</b>A secure link that works once and expires in <?php echo TI_VALID_DAYS; ?> days goes to this email only.</div>
                <div class="step"><b>2. Teacher registers</b>The teacher fills in Basic and Professional Information and creates a password.</div>
                <div class="step"><b>3. Account ready</b>The account is created and the teacher gets a "Go to Teacher Portal" email.</div>
                <div class="step"><b>4. Teacher list</b>The teacher appears in your Teacher List. You never see their password.</div>
            </div>
        </div>
    </div>

    <div class="card-x">
        <div class="card-x-head"><i class="fas fa-list-check"></i><h5>Invitations</h5></div>
        <?php if (!$invitations): ?>
            <div class="empty">No invitations yet.</div>
        <?php else: ?>
            <div style="overflow-x:auto;">
            <table>
                <thead><tr><th>Email</th><th>Status</th><th>Sent</th><th>Link expires</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($invitations as $inv):
                    $st = tiStatus($inv);
                    if ($st === 'Active' && isset($inv['is_active']) && (int) $inv['is_active'] !== 1) $st = 'Inactive';
                    $cls = ['Pending Account Setup' => 'pending', 'Active' => 'active', 'Inactive' => 'inactive', 'Cancelled' => 'cancelled', 'Expired' => 'expired'][$st];
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($inv['email']); ?>
                            <?php if ($inv['user_id'] && $inv['first_name']): ?><small><?php echo htmlspecialchars($inv['last_name'] . ', ' . $inv['first_name']); ?></small><?php endif; ?>
                            <?php if (!$inv['email_sent'] && $st === 'Pending Account Setup'): ?><small style="color:#C23A3A;"><i class="fas fa-triangle-exclamation"></i> Email not sent<?php echo $inv['email_error'] ? ': ' . htmlspecialchars($inv['email_error']) : ''; ?></small><?php endif; ?>
                        </td>
                        <td><span class="pill pill-<?php echo $cls; ?>"><?php echo $st; ?></span></td>
                        <td><?php echo date('M j, Y', strtotime($inv['created_at'])); ?><small><?php echo date('g:i A', strtotime($inv['created_at'])); ?></small></td>
                        <td><?php echo $st === 'Pending Account Setup' ? date('M j, Y g:i A', strtotime($inv['expires_at'])) : '—'; ?></td>
                        <td>
                            <div class="row-actions">
                                <?php if ($st === 'Active' || $st === 'Inactive'): ?>
                                    <a href="view.php?id=<?php echo (int) $inv['user_id']; ?>"><i class="fas fa-eye"></i> View Profile</a>
                                <?php else: ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="resend">
                                        <input type="hidden" name="invite_id" value="<?php echo (int) $inv['invite_id']; ?>">
                                        <button type="submit"><i class="fas fa-rotate-right"></i> Resend</button>
                                    </form>
                                    <?php if ($st === 'Pending Account Setup'): ?>
                                        <form method="POST" onsubmit="return confirm('Cancel this invitation? Its link will stop working.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="invite_id" value="<?php echo (int) $inv['invite_id']; ?>">
                                            <button type="submit" class="danger"><i class="fas fa-ban"></i> Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
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
