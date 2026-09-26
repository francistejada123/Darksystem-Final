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

// Older rows were saved with the module and the action in each other's column
// (see logActivity()). Read them the right way round: the module is the short
// lowercase key ("grading"), the action is the words ("Added assessment item").
$swapped = "(CAST(al.action AS BINARY) REGEXP '^[a-z][a-z_]*$' AND CAST(al.module AS BINARY) NOT REGEXP '^[a-z][a-z_]*$')";
$mod_sql = "LOWER(CASE WHEN $swapped THEN al.action ELSE al.module END)";
$act_sql = "(CASE WHEN $swapped THEN al.module ELSE al.action END)";

$where = ['1 = 1'];
$params = [];
if ($search !== '') {
    $where[] = '(u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR al.action LIKE ? OR al.module LIKE ? OR al.description LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
}
if ($module !== '') {
    $where[] = "$mod_sql = ?";
    $params[] = strtolower($module);
}
$where_sql = implode(' AND ', $where);
$total = (int)(getRecord("SELECT COUNT(*) AS total FROM activity_logs al LEFT JOIN users u ON u.user_id = al.user_id WHERE $where_sql", $params)['total'] ?? 0);
$total_pages = max(1, (int)ceil($total / $limit));
$page = min($page, $total_pages);
$offset = ($page - 1) * $limit;
$logs = getRecords(
    "SELECT al.log_id, $act_sql AS action, $mod_sql AS module, al.description, al.ip_address, al.created_at,
            u.username, u.first_name, u.last_name
     FROM activity_logs al
     LEFT JOIN users u ON u.user_id = al.user_id
     WHERE $where_sql
     ORDER BY al.created_at DESC, al.log_id DESC
     LIMIT $offset, $limit",
    $params
);
$modules = getRecords("SELECT DISTINCT $mod_sql AS module FROM activity_logs al WHERE al.module <> '' ORDER BY module");

function auditActorName(array $log): string
{
    $name = trim(implode(' ', array_filter([$log['first_name'] ?? '', $log['last_name'] ?? ''])));
    return $name !== '' ? $name : (($log['username'] ?? '') !== '' ? $log['username'] : 'System');
}
function auditModuleClass(string $module): string
{
    return 'audit-module-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($module));
}
function auditModuleLabel(string $module): string
{
    $label = ucwords(str_replace('_', ' ', $module));
    return str_ireplace(['Ai ', ' Ai'], ['AI ', ' AI'], $label);
}
/** Colour of the module badge: [text, background]. */
function auditModuleColor(string $module): array
{
    $m = strtolower($module);
    $map = [
        'auth' => ['#475467', '#EEF1F5'], 'student' => ['#0E7490', '#E0F4F8'], 'students' => ['#0E7490', '#E0F4F8'],
        'grading' => ['#15803D', '#E6F6EC'], 'enrollment' => ['#1D5FC2', '#E8F1FE'], 'online_enrollment' => ['#1D5FC2', '#E8F1FE'],
        'attendance' => ['#B45309', '#FFF4E5'], 'teachers' => ['#6D28D9', '#F1EBFE'], 'classroom' => ['#0F766E', '#E3F5F2'],
        'teacher ai' => ['#7C3AED', '#F3EDFF'], 'ai resource' => ['#7C3AED', '#F3EDFF'], 'google forms' => ['#7C3AED', '#F3EDFF'],
        'tutorial' => ['#A16207', '#FEF6DD'], 'settings' => ['#334155', '#EDF0F4'], 'reports' => ['#4338CA', '#ECEEFE'],
        'users' => ['#BE185D', '#FCE8F1'], 'dashboard' => ['#475467', '#EEF1F5'], 'logs' => ['#475467', '#EEF1F5'],
        'subjects' => ['#0369A1', '#E3F1FB'],
    ];
    return $map[$m] ?? ['#2868BD', '#EDF4FF'];
}
/** Icon and tone of an action, from its words. */
function auditActionStyle(string $action): array
{
    $a = strtolower($action);
    $rules = [
        ['/fail|error|denied/', 'fa-triangle-exclamation', 'danger'],
        ['/permanent delete|delet|remov|deactivat|unassign/', 'fa-trash-can', 'danger'],
        ['/login|logged in|sign in/', 'fa-right-to-bracket', ''],
        ['/logout/', 'fa-right-from-bracket', ''],
        ['/approv|activated|restor/', 'fa-circle-check', 'success'],
        ['/incomplete|reject/', 'fa-file-circle-exclamation', 'warning'],
        ['/email|sent|send|notif/', 'fa-paper-plane', ''],
        ['/export|download|backup/', 'fa-download', ''],
        ['/import|restore/', 'fa-upload', ''],
        ['/password/', 'fa-key', 'warning'],
        ['/add|creat|new|post|submit|generat|start/', 'fa-circle-plus', ''],
        ['/updat|edit|set |saved|save|assign|moved|reset|finaliz|customiz|ended|computed|complet/', 'fa-pen-to-square', ''],
    ];
    foreach ($rules as $r) {
        if (preg_match($r[0], $a)) return [$r[1], $r[2]];
    }
    return ['fa-circle-dot', ''];
}
/** Page numbers to show: 1 … 4 5 [6] 7 8 … 54 */
function auditPageList(int $page, int $total): array
{
    $pages = [];
    for ($p = 1; $p <= $total; $p++) {
        if ($p === 1 || $p === $total || abs($p - $page) <= 2) {
            if ($pages && end($pages) !== '…' && $p - (int) end($pages) > 1) $pages[] = '…';
            $pages[] = $p;
        }
    }
    return $pages;
}
function auditPageUrl(string $search, string $module, int $page): string
{
    return '?' . http_build_query(['search' => $search, 'module' => $module, 'page' => $page]);
}

include '../../includes/header.php';
?>

<style>
    .audit-page { max-width: 1500px; margin: 0 auto; }
    .audit-hero { display:flex; align-items:center; justify-content:space-between; gap:20px; margin:20px 0 16px; padding:24px 28px; border:1px solid #dce8f6; border-radius:16px; background:linear-gradient(110deg,#f4f9ff,#fff); box-shadow:0 7px 22px rgba(35,75,125,.06); }
    .audit-hero h1 { margin:0 0 6px; color:#14213d; font-size:28px; font-weight:750; }
    .audit-hero p { margin:0; color:#5d7190; font-size:15px; }
    .audit-hero-icon { display:grid; place-items:center; width:60px; height:60px; margin-right:16px; border-radius:15px; color:#276fdb; background:#e7f1ff; font-size:26px; flex:0 0 auto; }
    .audit-hero-copy { display:flex; align-items:center; }
    .audit-count { color:#276fdb; font-size:30px; font-weight:750; text-align:right; }
    .audit-count small { display:block; color:#6a7e99; font-size:13px; font-weight:600; }

    .audit-filter { display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; margin-bottom:16px; padding:16px 18px; border:1px solid #dce6f2; border-radius:13px; background:#fff; box-shadow:0 6px 18px rgba(35,75,125,.05); }
    .audit-filter label { display:block; margin:0 0 6px 2px; color:#3f5573; font-size:13px; font-weight:700; }
    .audit-filter input, .audit-filter select { height:44px; min-width:260px; padding:0 12px; border:1px solid #cfdcec; border-radius:9px; color:#1f3552; font-size:14.5px; background:#fff; }
    .audit-filter input:focus, .audit-filter select:focus { outline:none; border-color:#1f73e8; box-shadow:0 0 0 3px rgba(31,115,232,.15); }
    .audit-filter button, .audit-filter .audit-clear { display:inline-flex; align-items:center; gap:7px; height:44px; padding:0 20px; border-radius:9px; font-size:14.5px; font-weight:700; text-decoration:none; }
    .audit-filter button { border:0; background:#1f73e8; color:#fff; }
    .audit-filter button:hover { background:#1861c7; }
    .audit-filter .audit-clear { border:1px solid #cfdcec; color:#3f5573; background:#fff; }
    .audit-filter .audit-clear:hover { background:#f3f7fc; }

    .audit-card { border:1px solid #dce6f2; border-radius:14px; background:#fff; box-shadow:0 7px 22px rgba(35,75,125,.06); overflow:hidden; }
    .audit-scroll { overflow-x:auto; }
    .audit-table { width:100%; margin:0; border-collapse:collapse; }
    .audit-table th { padding:14px 18px; color:#51667f; background:#f4f8fd; font-size:12.5px; font-weight:700; text-align:left; text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; border-bottom:1px solid #e3ebf5; }
    .audit-table td { padding:16px 18px; border-top:1px solid #edf2f7; color:#2d4262; font-size:14.5px; vertical-align:middle; }
    .audit-table tbody tr:nth-child(even) { background:#fbfcfe; }
    .audit-table tbody tr { transition:background .15s; }
    .audit-table tbody tr:hover { background:#f1f7ff; }
    .audit-time { white-space:nowrap; color:#1f3552; font-weight:600; }
    .audit-time small { display:block; margin-top:3px; color:#6b7f99; font-size:13px; font-weight:500; }
    .audit-actor { color:#14213d; font-size:15px; font-weight:700; white-space:nowrap; }
    .audit-actor small { display:block; margin-top:3px; color:#6b7f99; font-size:13px; font-weight:500; }
    .audit-module { display:inline-block; padding:6px 12px; border-radius:999px; font-size:13px; font-weight:700; white-space:nowrap; }
    .audit-action { display:flex; align-items:center; gap:10px; color:#14213d; font-weight:700; min-width:190px; }
    .audit-action i { display:grid; place-items:center; width:32px; height:32px; border-radius:9px; background:#eef3fa; color:#3d5a80; font-size:14px; flex:0 0 auto; }
    .audit-action.danger i { background:#fdecec; color:#c23a3a; }
    .audit-action.danger span { color:#b42318; }
    .audit-action.success i { background:#e6f6ec; color:#15803d; }
    .audit-action.warning i { background:#fff4e5; color:#b45309; }
    .audit-description { min-width:280px; max-width:520px; color:#4a5f7c; line-height:1.55; word-break:break-word; }
    .audit-ip { color:#6b7f99; font-family:ui-monospace, Consolas, monospace; font-size:13.5px; white-space:nowrap; }
    .audit-empty { padding:56px 20px; color:#5f7390; font-size:15px; text-align:center; }

    .audit-pagination { display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; padding:16px 18px; border-top:1px solid #e3ebf5; background:#f8fbff; }
    .audit-page-info { color:#3f5573; font-size:14.5px; }
    .audit-page-info strong { color:#14213d; }
    .audit-pages { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
    .audit-pages a, .audit-pages span { display:inline-flex; align-items:center; justify-content:center; gap:7px; min-width:42px; height:42px; padding:0 14px; border:1px solid #cfdcec; border-radius:10px; background:#fff; color:#1f3552; font-size:14.5px; font-weight:700; text-decoration:none; transition:background .15s, border-color .15s, color .15s; }
    .audit-pages a:hover { border-color:#1f73e8; background:#eef5ff; color:#1f73e8; }
    .audit-pages .current { border-color:#1f73e8; background:#1f73e8; color:#fff; }
    .audit-pages .disabled { color:#a6b3c4; background:#f3f6fa; border-color:#e3e9f1; cursor:not-allowed; }
    .audit-pages .gap { min-width:auto; padding:0 4px; border:0; background:transparent; color:#6b7f99; }
    .audit-pages .nav { padding:0 16px; }

    html[data-theme="dark"] .audit-hero, html[data-theme="dark"] .audit-filter, html[data-theme="dark"] .audit-card { background:var(--surface, #111827); border-color:var(--border, #243244); }
    html[data-theme="dark"] .audit-hero h1, html[data-theme="dark"] .audit-actor, html[data-theme="dark"] .audit-action, html[data-theme="dark"] .audit-time, html[data-theme="dark"] .audit-page-info strong { color:var(--ink, #e5e7eb); }
    html[data-theme="dark"] .audit-hero p, html[data-theme="dark"] .audit-table td, html[data-theme="dark"] .audit-description, html[data-theme="dark"] .audit-page-info, html[data-theme="dark"] .audit-filter label { color:var(--text-muted, #a3b1c6); }
    html[data-theme="dark"] .audit-table th, html[data-theme="dark"] .audit-pagination { background:rgba(255,255,255,.03); border-color:var(--border, #243244); color:var(--text-muted, #a3b1c6); }
    html[data-theme="dark"] .audit-table td { border-color:var(--border, #243244); }
    html[data-theme="dark"] .audit-table tbody tr:nth-child(even) { background:rgba(255,255,255,.02); }
    html[data-theme="dark"] .audit-table tbody tr:hover { background:rgba(59,130,246,.08); }
    html[data-theme="dark"] .audit-filter input, html[data-theme="dark"] .audit-filter select, html[data-theme="dark"] .audit-pages a, html[data-theme="dark"] .audit-filter .audit-clear { background:var(--surface-hover, #1a2333); border-color:var(--border, #2b3a50); color:var(--ink, #e5e7eb); }
    html[data-theme="dark"] .audit-pages .current { background:#1f73e8; border-color:#1f73e8; color:#fff; }
    html[data-theme="dark"] .audit-pages .disabled { background:transparent; border-color:var(--border, #243244); color:#5b6b82; }

    @media (max-width:800px) {
        .audit-hero { padding:20px; }
        .audit-count { display:none; }
        .audit-table { min-width:980px; }
        .audit-filter input, .audit-filter select { min-width:100%; }
        .audit-filter > div { flex:1 1 100%; }
    }
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
        <div><label for="audit-module">Module</label><select id="audit-module" name="module"><option value="">All modules</option><?php foreach ($modules as $item): ?><option value="<?php echo htmlspecialchars($item['module']); ?>" <?php echo strtolower($module) === $item['module'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(auditModuleLabel($item['module'])); ?></option><?php endforeach; ?></select></div>
        <button type="submit"><i class="fas fa-filter"></i> Filter</button>
        <?php if ($search !== '' || $module !== ''): ?><a class="audit-clear" href="index.php"><i class="fas fa-xmark"></i> Clear</a><?php endif; ?>
    </form>

    <div class="audit-card">
        <?php if (!$logs): ?>
            <div class="audit-empty"><i class="fas fa-clipboard-list mb-2" style="font-size:28px;"></i><br>No activity records matched your filter.</div>
        <?php else: ?>
            <div class="audit-scroll">
            <table class="audit-table">
                <thead><tr><th>Time</th><th>User</th><th>Module</th><th>Action</th><th>Details</th><th>IP address</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log):
                    [$mfg, $mbg] = auditModuleColor($log['module']);
                    [$aicon, $atone] = auditActionStyle($log['action']);
                ?>
                    <tr>
                        <td class="audit-time"><?php echo htmlspecialchars(date('M j, Y', strtotime($log['created_at']))); ?><small><?php echo htmlspecialchars(date('g:i A', strtotime($log['created_at']))); ?></small></td>
                        <td class="audit-actor"><?php echo htmlspecialchars(auditActorName($log)); ?><small><?php echo htmlspecialchars($log['username'] ?? ''); ?></small></td>
                        <td><span class="audit-module <?php echo htmlspecialchars(auditModuleClass($log['module'])); ?>" style="color:<?php echo $mfg; ?>;background:<?php echo $mbg; ?>;"><?php echo htmlspecialchars(auditModuleLabel($log['module'])); ?></span></td>
                        <td><div class="audit-action <?php echo $atone; ?>"><i class="fas <?php echo $aicon; ?>" aria-hidden="true"></i><span><?php echo htmlspecialchars($log['action']); ?></span></div></td>
                        <td class="audit-description"><?php echo htmlspecialchars(($log['description'] ?? '') !== '' ? $log['description'] : '—'); ?></td>
                        <td class="audit-ip"><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div class="audit-pagination">
                <span class="audit-page-info">Showing <strong><?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $limit, $total)); ?></strong> of <strong><?php echo number_format($total); ?></strong> records · Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                <nav class="audit-pages" aria-label="Audit Trail pages">
                    <?php if ($page > 1): ?>
                        <a class="nav" href="<?php echo auditPageUrl($search, $module, $page - 1); ?>"><i class="fas fa-chevron-left"></i> Previous</a>
                    <?php else: ?>
                        <span class="nav disabled" aria-disabled="true"><i class="fas fa-chevron-left"></i> Previous</span>
                    <?php endif; ?>
                    <?php foreach (auditPageList($page, $total_pages) as $p): ?>
                        <?php if ($p === '…'): ?>
                            <span class="gap">…</span>
                        <?php elseif ($p === $page): ?>
                            <span class="current" aria-current="page"><?php echo $p; ?></span>
                        <?php else: ?>
                            <a href="<?php echo auditPageUrl($search, $module, $p); ?>"><?php echo $p; ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($page < $total_pages): ?>
                        <a class="nav" href="<?php echo auditPageUrl($search, $module, $page + 1); ?>">Next <i class="fas fa-chevron-right"></i></a>
                    <?php else: ?>
                        <span class="nav disabled" aria-disabled="true">Next <i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
