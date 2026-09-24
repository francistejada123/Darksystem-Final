<?php
// System Audit Trail — administrator-only activity history.
require_once '../../config/database.php';
require_once '../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Audit Trail';
$current_page = 'logs';
$search = trim(sanitize($_GET['search'] ?? ''));
$module = trim(sanitize($_GET['module'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

$where = ['1 = 1'];
$params = [];
if ($search !== '') {
    $where[] = '(u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR al.action LIKE ? OR al.module LIKE ? OR al.description LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
}
if ($module !== '') {
    $where[] = 'al.module = ?';
    $params[] = $module;
}
$where_sql = implode(' AND ', $where);
$total = (int)(getRecord("SELECT COUNT(*) AS total FROM activity_logs al LEFT JOIN users u ON u.user_id = al.user_id WHERE $where_sql", $params)['total'] ?? 0);
$total_pages = max(1, (int)ceil($total / $limit));
$page = min($page, $total_pages);
$offset = ($page - 1) * $limit;
$logs = getRecords(
    "SELECT al.log_id, al.action, al.module, al.description, al.ip_address, al.created_at,
            u.username, u.first_name, u.last_name
     FROM activity_logs al
     LEFT JOIN users u ON u.user_id = al.user_id
     WHERE $where_sql
     ORDER BY al.created_at DESC, al.log_id DESC
     LIMIT $offset, $limit",
    $params
);
$modules = getRecords("SELECT DISTINCT module FROM activity_logs WHERE module <> '' ORDER BY module");

function auditActorName(array $log): string
{
    $name = trim(implode(' ', array_filter([$log['first_name'] ?? '', $log['last_name'] ?? ''])));
    return $name !== '' ? $name : (($log['username'] ?? '') !== '' ? $log['username'] : 'System');
}
function auditModuleClass(string $module): string
{
    return 'audit-module-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($module));
}

include '../../includes/header.php';
?>

<style>
    .audit-page { max-width: 1500px; margin: 0 auto; }
    .audit-hero { display:flex; align-items:center; justify-content:space-between; gap:20px; margin:20px 0 16px; padding:24px 28px; border:1px solid #dce8f6; border-radius:16px; background:linear-gradient(110deg,#f4f9ff,#fff); box-shadow:0 7px 22px rgba(35,75,125,.06); }
    .audit-hero h1 { margin:0 0 5px; color:#14213d; font-size:27px; font-weight:750; }
    .audit-hero p { margin:0; color:#6a7d99; font-size:13px; }
    .audit-hero-icon { display:grid; place-items:center; width:58px; height:58px; margin-right:16px; border-radius:15px; color:#276fdb; background:#e7f1ff; font-size:25px; }
    .audit-hero-copy { display:flex; align-items:center; }
    .audit-count { color:#276fdb; font-size:28px; font-weight:750; text-align:right; }
    .audit-count small { display:block; color:#7486a0; font-size:11px; font-weight:600; }
    .audit-filter { display:flex; align-items:end; gap:10px; flex-wrap:wrap; margin-bottom:16px; padding:15px; border:1px solid #dce6f2; border-radius:13px; background:#fff; box-shadow:0 6px 18px rgba(35,75,125,.05); }
    .audit-filter label { display:block; margin:0 0 5px 2px; color:#526783; font-size:11px; font-weight:700; }
    .audit-filter input, .audit-filter select { min-height:39px; min-width:210px; border:1px solid #d9e4f1; border-radius:8px; color:#294263; font-size:12px; }
    .audit-filter button { min-height:39px; border:0; border-radius:8px; background:#1f73e8; color:#fff; font-size:12px; font-weight:700; padding:0 17px; }
    .audit-card { overflow:hidden; border:1px solid #dce6f2; border-radius:14px; background:#fff; box-shadow:0 7px 22px rgba(35,75,125,.06); }
    .audit-table { width:100%; margin:0; border-collapse:collapse; }
    .audit-table th { padding:12px 15px; color:#71839a; background:#f7faff; font-size:10px; text-align:left; text-transform:uppercase; letter-spacing:.04em; }
    .audit-table td { padding:13px 15px; border-top:1px solid #edf2f7; color:#344b69; font-size:12px; vertical-align:top; }
    .audit-table tr { transition:background .16s; }.audit-table tbody tr:hover { background:#fbfdff; }
    .audit-actor { color:#14213d; font-weight:700; }.audit-actor small { display:block; margin-top:3px; color:#8999ad; font-weight:400; }
    .audit-action { color:#14213d; font-weight:700; }.audit-description { max-width:430px; color:#70839d; line-height:1.45; }
    .audit-module { display:inline-block; padding:5px 9px; border-radius:15px; background:#edf4ff; color:#2868bd; font-size:10px; font-weight:750; }
    .audit-time { white-space:nowrap; color:#607795; }.audit-ip { color:#8798ac; font-family:monospace; font-size:11px; white-space:nowrap; }
    .audit-empty { padding:48px 20px; color:#72849b; text-align:center; }.audit-pagination { display:flex; justify-content:space-between; align-items:center; padding:13px 15px; border-top:1px solid #edf2f7; color:#71839a; font-size:11px; }
    .audit-pagination a { display:inline-block; margin-left:5px; padding:6px 10px; border:1px solid #d9e4f1; border-radius:7px; color:#426382; text-decoration:none; }.audit-pagination a:hover { border-color:#79aeea; background:#eef6ff; }
    @media (max-width:800px) { .audit-hero { padding:20px; }.audit-count { display:none; }.audit-card { overflow-x:auto; }.audit-table { min-width:850px; } }
</style>

<div class="audit-page">
    <div class="audit-hero">
        <div class="audit-hero-copy">
            <div class="audit-hero-icon"><i class="fas fa-shield-halved"></i></div>
            <div><h1>Audit Trail</h1><p>Review system activity, account changes, enrollment, grading, and other administrative actions.</p></div>
        </div>
        <div class="audit-count"><?php echo number_format($total); ?><small>activity records</small></div>
    </div>

    <form class="audit-filter" method="get">
        <div><label for="audit-search">Search activity</label><input id="audit-search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="User, action, module, or details"></div>
        <div><label for="audit-module">Module</label><select id="audit-module" name="module"><option value="">All modules</option><?php foreach ($modules as $item): ?><option value="<?php echo htmlspecialchars($item['module']); ?>" <?php echo $module === $item['module'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $item['module']))); ?></option><?php endforeach; ?></select></div>
        <button type="submit"><i class="fas fa-filter me-1"></i> Filter</button>
        <?php if ($search !== '' || $module !== ''): ?><a class="btn btn-sm btn-outline-secondary" href="index.php">Clear</a><?php endif; ?>
    </form>

    <div class="audit-card">
        <?php if (!$logs): ?>
            <div class="audit-empty"><i class="fas fa-clipboard-list mb-2" style="font-size:28px;"></i><br>No activity records matched your filter.</div>
        <?php else: ?>
            <table class="audit-table">
                <thead><tr><th>Time</th><th>User</th><th>Module</th><th>Action</th><th>Details</th><th>IP address</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="audit-time"><?php echo htmlspecialchars(date('M j, Y', strtotime($log['created_at']))); ?><br><small><?php echo htmlspecialchars(date('g:i A', strtotime($log['created_at']))); ?></small></td>
                        <td class="audit-actor"><?php echo htmlspecialchars(auditActorName($log)); ?><small><?php echo htmlspecialchars($log['username'] ?? ''); ?></small></td>
                        <td><span class="audit-module <?php echo htmlspecialchars(auditModuleClass($log['module'])); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $log['module']))); ?></span></td>
                        <td class="audit-action"><?php echo htmlspecialchars($log['action']); ?></td>
                        <td class="audit-description"><?php echo htmlspecialchars($log['description'] ?? '—'); ?></td>
                        <td class="audit-ip"><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="audit-pagination"><span>Showing <?php echo $total ? ($offset + 1) : 0; ?>–<?php echo min($offset + $limit, $total); ?> of <?php echo number_format($total); ?></span><span><?php if ($page > 1): ?><a href="?<?php echo http_build_query(['search' => $search, 'module' => $module, 'page' => $page - 1]); ?>">Previous</a><?php endif; ?><?php if ($page < $total_pages): ?><a href="?<?php echo http_build_query(['search' => $search, 'module' => $module, 'page' => $page + 1]); ?>">Next</a><?php endif; ?></span></div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
