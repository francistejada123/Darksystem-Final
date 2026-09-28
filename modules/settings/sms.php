<?php
// modules/settings/sms.php -- Settings > SMS (Administrator): which sender is
// set up (.env), Send Test SMS, which texts are on, and the SMS log.

require_once __DIR__ . '/../../config/database.php';
if (!defined('BASE_URL')) define('BASE_URL', '/watdahel/');
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sms.php';

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}
smsEnsureSchema();
$page_title = 'SMS Settings';
$current_page = 'settings';
$base_url = BASE_URL;
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $flash = ['danger', 'Invalid security token. Please try again.'];
    } elseif (($_POST['action'] ?? '') === 'test') {
        $r = smsSend($_POST['to'] ?? '', 'EGradePro: This is a sample message from the school system. ' . date('M j, g:i A'), ['kind' => 'test', 'created_by' => (int) $_SESSION['user_id']]);
        $flash = $r['success'] ? ['success', 'Sent! It should arrive in a few seconds to a few minutes.'] : ['danger', 'Not sent: ' . ($r['error'] ?: $r['status'])];
        logActivity($_SESSION['user_id'], 'settings', 'Sent test SMS', $r['status']);
    } elseif (($_POST['action'] ?? '') === 'kinds') {
        foreach (['welcome', 'absent'] as $k) smsSetKind($k, !empty($_POST['on_' . $k]));
        $flash = ['success', 'Saved.'];
        logActivity($_SESSION['user_id'], 'settings', 'Updated SMS settings', 'welcome=' . (int) !empty($_POST['on_welcome']) . ', absent=' . (int) !empty($_POST['on_absent']));
    }
}
$p = smsProvider();
$log = getRecords("SELECT m.*, s.first_name, s.last_name FROM sms_messages m LEFT JOIN students s ON s.student_id = m.student_id ORDER BY m.sms_id DESC LIMIT 50");
$month = getRecord("SELECT SUM(status = 'Sent') AS sent, SUM(status = 'Sent') * 1 AS x, SUM(CASE WHEN status = 'Sent' THEN parts ELSE 0 END) AS parts FROM sms_messages WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
$csrf = generateCSRFToken();
include __DIR__ . '/../../includes/header.php';
?>
<style>
    .smsp h4 { font-size: 26px; font-weight: 700; }
    .smsp .card-x { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; margin-bottom: 18px; }
    .smsp .card-x h5 { font-size: 18px; font-weight: 700; margin-bottom: 12px; }
    .smsp table { width: 100%; border-collapse: collapse; }
    .smsp th { background: var(--surface-hover); padding: 10px 12px; font-size: 12.5px; text-transform: uppercase; color: var(--text-muted); text-align: left; }
    .smsp td { padding: 10px 12px; border-top: 1px solid var(--border-soft); font-size: 14.5px; vertical-align: top; }
    .smsp code { font-size: 13px; }
</style>
<div class="smsp" style="padding:24px;max-width:1200px">
    <h4><i class="fas fa-comment-sms me-2"></i>SMS Settings</h4>
    <p class="text-muted" style="font-size:15px">Texts to parents/guardians: the Parent Portal welcome link when an enrollment is approved, and absence notices to linked parents.</p>
    <?php if ($flash): ?><div class="alert alert-<?php echo $flash[0]; ?>"><?php echo htmlspecialchars($flash[1]); ?></div><?php endif; ?>

    <div class="card-x">
        <h5>Sender</h5>
        <p style="font-size:15px;margin-bottom:6px"><b><?php echo htmlspecialchars($p['label']); ?></b>
            <?php if ($p['provider'] !== '' && $p['problem'] === ''): ?><span class="badge bg-success ms-2">Ready</span>
            <?php else: ?><span class="badge bg-warning text-dark ms-2">Not ready</span><?php endif; ?></p>
        <?php if ($p['problem']): ?><p class="text-muted mb-2" style="font-size:14px"><?php echo htmlspecialchars($p['problem']); ?> Until then, texts are recorded as "Not sent" and staff can send them from a phone.</p><?php endif; ?>
        <details style="font-size:14px"><summary>How to set up (in the <code>.env</code> file of the server)</summary>
            <div class="mt-2"><b>For the defense (Android phone):</b> install "SMS Gateway for Android" on a phone with load / unli-text, start it, and copy the address, username and password it shows:
<pre style="background:var(--surface-hover);padding:10px;border-radius:8px">SMS_PROVIDER=android_gateway
SMS_GATEWAY_URL=http://192.168.x.x:8080   (Local mode, same Wi-Fi)  or  https://api.sms-gate.app/3rdparty/v1  (Cloud mode)
SMS_GATEWAY_USER=...
SMS_GATEWAY_PASSWORD=...</pre>
            <b>For deployment (Semaphore):</b>
<pre style="background:var(--surface-hover);padding:10px;border-radius:8px">SMS_PROVIDER=semaphore
SEMAPHORE_API_KEY=...
SEMAPHORE_SENDER=EGradePro   (only after Semaphore approves the sender name)</pre>
            Never paste these values in chat or commit them.</div></details>
    </div>

    <div class="row g-3">
        <div class="col-lg-6"><div class="card-x h-100">
            <h5>Send Test SMS</h5>
            <form method="POST" class="d-flex gap-2">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="test">
                <input class="form-control" name="to" placeholder="09XXXXXXXXX" inputmode="numeric" maxlength="13" required>
                <button class="btn btn-primary" style="white-space:nowrap"><i class="fas fa-paper-plane me-1"></i> Send</button>
            </form>
            <p class="text-muted mt-2 mb-0" style="font-size:13px">This month: <?php echo (int) ($month['sent'] ?? 0); ?> text(s) sent (<?php echo (int) ($month['parts'] ?? 0); ?> SMS part(s); Semaphore ≈ ₱0.56 each).</p>
        </div></div>
        <div class="col-lg-6"><div class="card-x h-100">
            <h5>Texts that are sent</h5>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="kinds">
                <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="on_welcome" id="kw" <?php echo smsKindOn('welcome') ? 'checked' : ''; ?>><label class="form-check-label" for="kw" style="font-size:15px">Welcome + Parent Portal link (when an enrollment is approved)</label></div>
                <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="on_absent" id="ka" <?php echo smsKindOn('absent') ? 'checked' : ''; ?>><label class="form-check-label" for="ka" style="font-size:15px">Absent today (to linked parents, once a day)</label></div>
                <button class="btn btn-outline-primary">Save</button>
            </form>
        </div></div>
    </div>

    <div class="card-x mt-3" style="padding:0;overflow:hidden">
        <div style="padding:16px 20px"><h5 class="mb-0">SMS Log (latest 50)</h5></div>
        <div style="overflow-x:auto"><table>
            <tr><th>When</th><th>To</th><th>Kind</th><th>Student</th><th>Status</th><th>Message</th></tr>
            <?php if (!$log): ?><tr><td colspan="6" class="text-center text-muted py-4">No texts yet.</td></tr><?php endif; ?>
            <?php foreach ($log as $m): ?>
                <tr>
                    <td style="white-space:nowrap"><?php echo date('M j, g:i A', strtotime($m['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars(smsMask($m['to_number'])); ?></td>
                    <td><?php echo htmlspecialchars($m['kind']); ?></td>
                    <td><?php echo $m['first_name'] ? htmlspecialchars($m['last_name'] . ', ' . $m['first_name']) : '—'; ?></td>
                    <td><span class="badge bg-<?php echo $m['status'] === 'Sent' ? 'success' : ($m['status'] === 'Failed' ? 'danger' : 'secondary'); ?>"><?php echo $m['status']; ?></span><?php if ($m['error']): ?><small class="d-block text-muted" style="max-width:260px"><?php echo htmlspecialchars($m['error']); ?></small><?php endif; ?></td>
                    <td style="max-width:360px;font-size:13.5px"><?php echo htmlspecialchars(preg_replace('/Code: [A-Z0-9]{8}/', 'Code: ••••••••', preg_replace('/c=[A-Z0-9]{8}/', 'c=••••••••', $m['message']))); ?></td>
                </tr>
            <?php endforeach; ?>
        </table></div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
