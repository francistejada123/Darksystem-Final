<?php
// modules/notifications/index.php
// Full notification list for the logged-in user (any role). Previously a
// standalone page with its own generic header — now uses the shared
// header.php/footer.php so it matches the rest of the system (sidebar,
// fonts, appToast, etc).

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$page_title = 'Notifications';
$current_page = 'notifications';
$base_url = BASE_URL;

$user_id = $_SESSION['user_id'];

// Simple pagination — 20 per page instead of one hardcoded LIMIT 50 dump.
$per_page = 20;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$total_row = getRecord("SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ?", [$user_id]);
$total = intval($total_row['cnt'] ?? 0);
$total_pages = max(1, (int) ceil($total / $per_page));

$notifications = getRecords("
    SELECT * FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT $per_page OFFSET $offset
", [$user_id]);

// Mark all as read (only affects rows that were unread going in).
executeQuery("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0", [$user_id]);

/**
 * Map a notification's `type` column (info | success | warning | error —
 * the values createNotification() actually writes) to an icon + style.
 * The old version compared $notif['type'] against the words "Approved" /
 * "Rejected", but those words only ever appear in the title/message, never
 * in `type` (every row so far is written as type='info'), so that branch
 * was dead code and always fell through to the info icon.
 */
function notifIconMeta(string $type): array
{
    switch ($type) {
        case 'success':
            return ['class' => 'icon-success', 'icon' => 'fa-circle-check'];
        case 'error':
            return ['class' => 'icon-error', 'icon' => 'fa-circle-xmark'];
        case 'warning':
            return ['class' => 'icon-warning', 'icon' => 'fa-triangle-exclamation'];
        case 'info':
        default:
            return ['class' => 'icon-info', 'icon' => 'fa-circle-info'];
    }
}

/**
 * Display-only category (filter tabs + pill). The notifications table has no
 * category column, so it is derived from the wording of the title first, then
 * the message / link: Tutorial, Enrollment, Academic, otherwise System.
 */
function notifCategory(array $n): string
{
    $title = strtolower((string) ($n['title'] ?? ''));
    $rest  = strtolower((string) ($n['message'] ?? '') . ' ' . (string) ($n['link'] ?? ''));

    $rules = [
        'Tutorial'   => '/tutorial/',
        'Enrollment' => '/enroll|applica|subject assigned|section|assigned|admission/',
        'Academic'   => '/quiz|grade|grading|score|attendance|assessment|risk|report|class record|activity|exam/',
    ];
    foreach ([$title, $rest] as $haystack) {
        foreach ($rules as $cat => $pattern) {
            if (preg_match($pattern, $haystack)) {
                return $cat;
            }
        }
    }
    return 'System';
}

$nt_categories = ['Academic', 'Enrollment', 'System', 'Tutorial'];
$nt_counts = array_fill_keys($nt_categories, 0);
foreach ($notifications as $i => $n) {
    $notifications[$i]['_cat'] = notifCategory($n);
    $nt_counts[$notifications[$i]['_cat']]++;
}

$nt_from = $total > 0 ? $offset + 1 : 0;
$nt_to   = $offset + count($notifications);

include '../../includes/header.php';
?>

<style>
    .notif-page {
        --nt-fill: #1668E3;          /* solid brand-blue fills (white label on top) */
        --nt-blue: #1668E3;          /* blue used as text / outline on the page surface */
        --nt-blue-soft: #EAF2FE;
    }
    html[data-theme="dark"] .notif-page {
        --nt-fill: #2A6BD8;
        --nt-blue: #7FB0FF;
        --nt-blue-soft: rgba(111, 163, 255, 0.12);
    }

    /* ---- page header ---- */
    .nt-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 16px; }
    .nt-head-l { display: flex; align-items: center; gap: 14px; min-width: 0; }
    .nt-head-icon {
        width: 48px; height: 48px; border-radius: 12px; flex-shrink: 0;
        background: var(--nt-fill); color: #fff; font-size: 20px;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 6px 14px rgba(22, 104, 227, 0.20);
    }
    .nt-title { margin: 0 0 1px; font-size: 22px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; color: var(--ink); }
    .nt-sub { margin: 0; font-size: 13px; color: var(--text-muted); }
    .nt-head-r { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
    .nt-date { font-size: 12px; color: var(--text-muted); white-space: nowrap; }
    .nt-date .nt-sep { margin: 0 6px; }
    .nt-markall {
        display: inline-flex; align-items: center; gap: 9px;
        padding: 7px 14px; border-radius: 8px;
        border: 1.5px solid var(--nt-blue); background: var(--surface); color: var(--nt-blue);
        font-size: 12.5px; font-weight: 600; cursor: pointer;
        transition: background-color .15s ease, color .15s ease;
    }
    .nt-markall:hover { background: var(--nt-blue-soft); }
    .nt-markall:focus-visible, .nt-tab:focus-visible, .nt-kebab:focus-visible { outline: 2px solid var(--nt-blue); outline-offset: 2px; }

    /* ---- filter tabs + sort ---- */
    .nt-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
    .nt-tabs { display: flex; flex-wrap: wrap; gap: 4px; }
    .nt-tab {
        display: inline-flex; align-items: center; gap: 8px;
        height: 34px; padding: 0 14px; border: 0; border-radius: 8px;
        background: transparent; color: var(--ink); font-size: 13px; font-weight: 500; cursor: pointer;
        transition: background-color .15s ease, color .15s ease;
    }
    .nt-tab:hover { background: var(--surface-hover); }
    .nt-tab.active { background: var(--nt-fill); color: #fff; font-weight: 600; }
    .nt-count {
        min-width: 22px; height: 22px; padding: 0 6px; border-radius: 999px;
        display: inline-flex; align-items: center; justify-content: center;
        background: rgba(20, 33, 61, 0.08); color: var(--ink); font-size: 11.5px; font-weight: 600;
    }
    .nt-tab.active .nt-count { background: #fff; color: #1349A8; }
    html[data-theme="dark"] .nt-count { background: rgba(255, 255, 255, 0.10); }
    html[data-theme="dark"] .nt-tab.active .nt-count { background: #fff; }

    .nt-sort {
        position: relative; display: inline-flex; align-items: center;
        height: 36px; min-width: 170px; border: 1px solid var(--border); border-radius: 8px;
        background: var(--surface); color: var(--ink);
    }
    .nt-sort .nt-sort-lead { position: absolute; left: 12px; font-size: 12.5px; color: var(--ink); pointer-events: none; }
    .nt-sort .nt-sort-caret { position: absolute; right: 12px; font-size: 10px; color: var(--ink); pointer-events: none; }
    .nt-sort select {
        appearance: none; -webkit-appearance: none; width: 100%; height: 100%;
        padding: 0 30px 0 34px; border: 0; border-radius: 8px; background: transparent; color: var(--ink);
        font-size: 13px; font-weight: 500; cursor: pointer;
    }
    .nt-sort select:focus-visible { outline: 2px solid var(--nt-blue); outline-offset: 2px; }

    /* ---- list ---- */
    .nt-list { display: flex; flex-direction: column; gap: 8px; }
    .nt-item {
        display: flex; align-items: center; gap: 14px;
        padding: 11px 16px; border-radius: 12px;
        background: var(--surface); border: 1px solid var(--border-soft);
        box-shadow: 0 2px 8px rgba(20, 33, 61, 0.05);
        transition: border-color .15s ease, box-shadow .15s ease;
    }
    .nt-item:hover { border-color: var(--border); box-shadow: 0 4px 14px rgba(20, 33, 61, 0.08); }
    .nt-item.unread { background: var(--nt-blue-soft); border-color: rgba(22, 104, 227, 0.28); }
    .nt-item.unread .nt-item-title::before {
        content: ""; display: inline-block; width: 7px; height: 7px; margin-right: 7px;
        border-radius: 50%; background: var(--nt-blue); vertical-align: middle; position: relative; top: -1px;
    }
    .nt-item[hidden] { display: none; }

    .nt-icon {
        width: 42px; height: 42px; border-radius: 50%; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center; font-size: 19px;
    }
    .icon-success { background: #DFF4E9; color: #22A06B; }
    .icon-error   { background: #FDE7E7; color: #D64545; }
    .icon-warning { background: #FFEBD3; color: #F08A1C; }
    .icon-info    { background: #E3EEFD; color: #1668E3; }
    html[data-theme="dark"] .icon-success { background: rgba(52, 196, 141, 0.16); color: #6FDDAF; }
    html[data-theme="dark"] .icon-error   { background: rgba(226, 114, 111, 0.16); color: #F09A97; }
    html[data-theme="dark"] .icon-warning { background: rgba(224, 168, 61, 0.16);  color: #F0C673; }
    html[data-theme="dark"] .icon-info    { background: rgba(111, 163, 255, 0.16); color: #9CC3FF; }

    .nt-body { flex: 1 1 auto; min-width: 0; }
    .nt-main { display: block; color: inherit; text-decoration: none; }
    a.nt-main:hover .nt-item-title { text-decoration: underline; }
    .nt-titlerow { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 2px; }
    .nt-item-title { font-size: 14px; font-weight: 700; color: var(--ink); line-height: 1.3; }
    .nt-msg { font-size: 12.5px; color: var(--text-muted); line-height: 1.45; overflow-wrap: anywhere; }

    .nt-pill { display: inline-block; padding: 1px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; line-height: 1.6; }
    .nt-pill-academic   { background: #DFF4E9; color: #1C8A5B; }
    .nt-pill-tutorial   { background: #FFEBD3; color: #C86A0A; }
    .nt-pill-enrollment { background: #E3EEFD; color: #1668E3; }
    .nt-pill-system     { background: #ECEEF6; color: #566079; }
    html[data-theme="dark"] .nt-pill-academic   { background: rgba(52, 196, 141, 0.16);  color: #6FDDAF; }
    html[data-theme="dark"] .nt-pill-tutorial   { background: rgba(224, 168, 61, 0.16);  color: #F0C673; }
    html[data-theme="dark"] .nt-pill-enrollment { background: rgba(111, 163, 255, 0.16); color: #9CC3FF; }
    html[data-theme="dark"] .nt-pill-system     { background: rgba(147, 160, 184, 0.16); color: #C4CEE0; }

    .nt-when { flex: 0 0 auto; min-width: 96px; font-size: 12px; line-height: 1.5; color: var(--text-muted); white-space: nowrap; }
    .nt-more { flex: 0 0 auto; }
    .nt-kebab {
        width: 28px; height: 28px; border: 0; border-radius: 6px; background: transparent;
        color: var(--ink); font-size: 14px; cursor: pointer;
    }
    .nt-kebab:hover { background: var(--surface-hover); }
    .nt-more .dropdown-menu { font-size: 13.5px; }

    .nt-empty { text-align: center; padding: 36px 16px; color: var(--text-muted); font-size: 13px; background: var(--surface); border: 1px solid var(--border-soft); border-radius: 14px; }
    .nt-empty i { display: block; font-size: 24px; margin-bottom: 10px; color: var(--text-muted-2); }

    /* ---- footer: count + pagination ---- */
    .nt-foot { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
    .nt-showing { font-size: 12.5px; color: var(--text-muted); }
    .nt-pager { display: flex; gap: 6px; }
    .nt-pager a, .nt-pager span {
        min-width: 32px; height: 32px; padding: 0 8px; border-radius: 6px;
        display: inline-flex; align-items: center; justify-content: center;
        border: 1px solid var(--border); background: var(--surface); color: var(--ink);
        font-size: 12.5px; font-weight: 500; text-decoration: none;
    }
    .nt-pager a:hover { background: var(--surface-hover); }
    .nt-pager .active { background: var(--nt-fill); border-color: var(--nt-fill); color: #fff; font-weight: 600; }
    .nt-pager .disabled { opacity: 0.45; color: var(--text-muted); }

    @media (max-width: 767.98px) {
        .nt-title { font-size: 20px; }
        .nt-head-icon { width: 44px; height: 44px; font-size: 18px; border-radius: 12px; }
        .nt-head-r { width: 100%; justify-content: space-between; gap: 12px; }
        .nt-sort { width: 100%; }
        .nt-item { flex-wrap: wrap; gap: 12px 14px; padding: 14px; }
        .nt-icon { width: 40px; height: 40px; font-size: 18px; }
        .nt-body { flex-basis: calc(100% - 110px); }
        .nt-when { order: 3; flex-basis: 100%; min-width: 0; padding-left: 54px; white-space: normal; }
        .nt-when div { display: inline; }
        .nt-when div + div::before { content: " · "; }
        .nt-more { margin-left: auto; }
    }
</style>

<div class="container-fluid py-4 notif-page">
    <div class="nt-head">
        <div class="nt-head-l">
            <div class="nt-head-icon"><i class="fas fa-bell" aria-hidden="true"></i></div>
            <div>
                <h4 class="nt-title">Notifications</h4>
                <p class="nt-sub">Updates and alerts for your account.</p>
            </div>
        </div>
        <div class="nt-head-r">
            <span class="nt-date"><?php echo date('D, M j, Y'); ?><span class="nt-sep">&middot;</span><?php echo date('g:i A'); ?></span>
            <button type="button" class="nt-markall" id="ntMarkAll"><i class="fas fa-check" aria-hidden="true"></i> Mark All as Read</button>
        </div>
    </div>

    <?php if (empty($notifications)): ?>
        <div class="nt-empty"><i class="far fa-bell-slash" aria-hidden="true"></i>No notifications yet.</div>
    <?php else: ?>
        <div class="nt-toolbar">
            <div class="nt-tabs" role="tablist" aria-label="Filter notifications">
                <button type="button" class="nt-tab active" data-cat="all" role="tab" aria-selected="true">All <span class="nt-count"><?php echo count($notifications); ?></span></button>
                <?php foreach ($nt_categories as $cat): ?>
                    <button type="button" class="nt-tab" data-cat="<?php echo $cat; ?>" role="tab" aria-selected="false"><?php echo $cat; ?> <span class="nt-count"><?php echo $nt_counts[$cat]; ?></span></button>
                <?php endforeach; ?>
            </div>
            <label class="nt-sort" for="ntSort">
                <i class="fas fa-sliders nt-sort-lead" aria-hidden="true"></i>
                <select id="ntSort" aria-label="Sort notifications">
                    <option value="desc">Latest First</option>
                    <option value="asc">Oldest First</option>
                </select>
                <i class="fas fa-chevron-down nt-sort-caret" aria-hidden="true"></i>
            </label>
        </div>

        <div class="nt-list" id="ntList">
            <?php foreach ($notifications as $notif): ?>
                <?php
                    $meta = notifIconMeta($notif['type'] ?? 'info');
                    $notif_link = !empty($notif['link']) ? BASE_URL . ltrim($notif['link'], '/') : null;
                    $ts = strtotime($notif['created_at']);
                    $cat = $notif['_cat'];
                ?>
                <div class="nt-item <?php echo $notif['is_read'] == 0 ? 'unread' : ''; ?>" data-cat="<?php echo $cat; ?>" data-ts="<?php echo (int) $ts; ?>">
                    <div class="nt-icon <?php echo $meta['class']; ?>"><i class="fas <?php echo $meta['icon']; ?>" aria-hidden="true"></i></div>
                    <div class="nt-body">
                        <<?php echo $notif_link ? 'a href="' . htmlspecialchars($notif_link) . '"' : 'div'; ?> class="nt-main">
                            <div class="nt-titlerow">
                                <span class="nt-item-title"><?php echo htmlspecialchars($notif['title']); ?></span>
                                <span class="nt-pill nt-pill-<?php echo strtolower($cat); ?>"><?php echo $cat; ?></span>
                            </div>
                            <div class="nt-msg"><?php echo htmlspecialchars($notif['message']); ?></div>
                        </<?php echo $notif_link ? 'a' : 'div'; ?>>
                    </div>
                    <div class="nt-when">
                        <div><?php echo date('M j, Y', $ts); ?></div>
                        <div><?php echo date('g:i A', $ts); ?></div>
                    </div>
                    <div class="dropdown nt-more">
                        <button type="button" class="nt-kebab" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions"><i class="fas fa-ellipsis-vertical" aria-hidden="true"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php if ($notif_link): ?>
                                <li><a class="dropdown-item" href="<?php echo htmlspecialchars($notif_link); ?>"><i class="fas fa-arrow-up-right-from-square me-2" aria-hidden="true"></i>Open</a></li>
                            <?php else: ?>
                                <li><span class="dropdown-item disabled">No further actions</span></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="nt-empty" id="ntNone" hidden><i class="far fa-bell-slash" aria-hidden="true"></i>No notifications in this category.</div>

        <div class="nt-foot">
            <div class="nt-showing">
                <?php if ($total_pages > 1): ?>
                    Showing <?php echo $nt_from; ?>&ndash;<?php echo $nt_to; ?> of <?php echo $total; ?> notifications
                <?php else: ?>
                    Showing <?php echo $total; ?> of <?php echo $total; ?> notification<?php echo $total === 1 ? '' : 's'; ?>
                <?php endif; ?>
            </div>
            <nav class="nt-pager" aria-label="Notification pages">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>" aria-label="Previous page"><i class="fas fa-chevron-left" aria-hidden="true"></i></a>
                <?php else: ?>
                    <span class="disabled" aria-hidden="true"><i class="fas fa-chevron-left"></i></span>
                <?php endif; ?>
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                    <?php if ($p == $page): ?>
                        <span class="active" aria-current="page"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $p; ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>" aria-label="Next page"><i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                <?php else: ?>
                    <span class="disabled" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>
                <?php endif; ?>
            </nav>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var list = document.getElementById('ntList');
    if (!list) return;
    var tabs = document.querySelectorAll('.nt-tab');
    var none = document.getElementById('ntNone');
    var sortSel = document.getElementById('ntSort');
    var current = 'all';

    function apply() {
        var shown = 0;
        list.querySelectorAll('.nt-item').forEach(function (it) {
            var ok = current === 'all' || it.getAttribute('data-cat') === current;
            it.hidden = !ok;
            if (ok) shown++;
        });
        if (none) none.hidden = shown !== 0;
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(function (x) { x.classList.remove('active'); x.setAttribute('aria-selected', 'false'); });
            t.classList.add('active'); t.setAttribute('aria-selected', 'true');
            current = t.getAttribute('data-cat');
            apply();
        });
    });
    if (sortSel) {
        sortSel.addEventListener('change', function () {
            var dir = sortSel.value === 'asc' ? 1 : -1;
            var items = Array.prototype.slice.call(list.querySelectorAll('.nt-item'));
            items.sort(function (a, b) { return dir * (a.getAttribute('data-ts') - b.getAttribute('data-ts')); });
            items.forEach(function (it) { list.appendChild(it); });
        });
    }
    // Everything on this page was already marked read server-side when it loaded;
    // the button just clears the "new" highlight.
    var mark = document.getElementById('ntMarkAll');
    if (mark) mark.addEventListener('click', function () {
        list.querySelectorAll('.nt-item.unread').forEach(function (it) { it.classList.remove('unread'); });
        var badge = document.getElementById('appTopbarBellBadge');
        if (badge) badge.style.display = 'none';
    });
})();
</script>

<?php include '../../includes/footer.php'; ?>
