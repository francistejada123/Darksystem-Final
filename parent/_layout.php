<?php
// parent/_layout.php
// Shared page frame of the Parent Portal: phone-first app bar with the child
// picker and Tagalog/English switch, bottom navigation, and the install
// ("Idagdag sa Home Screen") support. Read-only pages only.

if (!defined('BASE_URL')) define('BASE_URL', '/watdahel/');

function ppHead($title)
{
    $b = BASE_URL;
    ?><!DOCTYPE html>
<html lang="<?php echo ppLang() === 'en' ? 'en' : 'fil'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title><?php echo htmlspecialchars($title); ?> - EGradePro Parent Portal</title>
    <link rel="manifest" href="<?php echo $b; ?>parent/manifest.webmanifest">
    <meta name="theme-color" content="#14213D">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="EGradePro Magulang">
    <link rel="apple-touch-icon" href="<?php echo $b; ?>static/images/app/apple-touch-icon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --pp-ink: #14213D; --pp-blue: #1D4ED8; --pp-bg: #F1F4F8; --pp-muted: #52607A; }
        * { box-sizing: border-box; }
        body { background: var(--pp-bg); color: var(--pp-ink); font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 16px; margin: 0; padding-bottom: 84px; }
        .pp-bar { position: sticky; top: 0; z-index: 50; background: var(--pp-ink); color: #fff; padding: 12px 16px; display: flex; align-items: center; gap: 10px; }
        .pp-bar img { width: 32px; height: 32px; border-radius: 8px; background: #fff; object-fit: contain; }
        .pp-bar .t { font-weight: 800; font-size: 17px; line-height: 1.1; }
        .pp-bar .t small { display: block; font-weight: 500; font-size: 13.5px; opacity: .75; }
        .pp-bar .r { margin-left: auto; display: flex; gap: 8px; align-items: center; }
        .pp-bar a.lang { color: #fff; font-size: 14.5px; font-weight: 700; text-decoration: none; border: 1px solid rgba(255,255,255,.35); border-radius: 999px; padding: 4px 10px; }
        .pp-wrap { max-width: 720px; margin: 0 auto; padding: 16px; }
        .pp-child { display: flex; align-items: center; gap: 12px; background: #fff; border-radius: 14px; padding: 14px 16px; margin-bottom: 14px; border: 1px solid #E2E8F0; }
        .pp-child .av { width: 46px; height: 46px; border-radius: 50%; background: #E0ECFF; color: var(--pp-blue); display: grid; place-items: center; font-weight: 800; font-size: 18px; flex: 0 0 auto; }
        .pp-child .n { font-weight: 800; font-size: 17px; }
        .pp-child .m { color: var(--pp-muted); font-size: 15px; }
        .pp-child select { margin-left: auto; max-width: 46%; font-size: 15px; }
        .pp-card { background: #fff; border: 1px solid #E2E8F0; border-radius: 14px; padding: 16px; margin-bottom: 14px; }
        .pp-card h2 { font-size: 17px; font-weight: 800; margin: 0 0 12px; }
        .pp-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px; }
        .pp-stat { background: #fff; border: 1px solid #E2E8F0; border-radius: 14px; padding: 12px 10px; text-align: center; text-decoration: none; color: inherit; }
        .pp-stat b { display: block; font-size: 22px; font-weight: 800; }
        .pp-stat span { font-size: 14.5px; color: var(--pp-muted); }
        .pp-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 11px 0; border-top: 1px solid #EEF2F6; font-size: 15.5px; }
        .pp-row:first-of-type { border-top: 0; }
        .pp-row small { color: var(--pp-muted); display: block; font-size: 14.5px; }
        .pp-tag { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 14.5px; font-weight: 700; white-space: nowrap; }
        .t-ok { background: #ECFDF5; color: #047857; } .t-warn { background: #FFF7ED; color: #B45309; } .t-bad { background: #FEF2F2; color: #B91C1C; } .t-info { background: #EFF6FF; color: #1D4ED8; } .t-mute { background: #F1F5F9; color: #475569; }
        .pp-alert { border-radius: 12px; padding: 12px 14px; margin-bottom: 14px; font-size: 15px; }
        .pp-empty { text-align: center; color: var(--pp-muted); padding: 22px 8px; font-size: 15px; }
        .pp-note { font-size: 14.5px; color: var(--pp-muted); text-align: center; margin-top: 6px; }
        .pp-nav { position: fixed; left: 0; right: 0; bottom: 0; z-index: 60; background: #fff; border-top: 1px solid #E2E8F0; display: flex; padding: 6px 4px calc(6px + env(safe-area-inset-bottom)); }
        .pp-nav a { flex: 1; text-align: center; text-decoration: none; color: #64748B; font-size: 13.5px; font-weight: 600; padding: 4px 0; }
        .pp-nav a i { display: block; font-size: 20px; margin-bottom: 3px; }
        .pp-nav a.on { color: var(--pp-blue); }
        .pp-install { display: none; width: 100%; }
        .pp-ios-tip { display: none; }
        @media (min-width: 760px) { .pp-wrap { padding: 24px; } }
    </style>
</head>
<body>
<?php
}

function ppTop(array $parent = null, array $kids = [], array $sel = null)
{
    $b = BASE_URL;
    $other = ppLang() === 'en' ? 'tl' : 'en';
    $q = $_GET; $q['lang'] = $other;
    ?>
<header class="pp-bar">
    <img src="<?php echo $b; ?>static/images/logo.png" alt="">
    <div class="t">EGradePro<small><?php echo ppT('portal'); ?></small></div>
    <div class="r">
        <a class="lang" href="?<?php echo htmlspecialchars(http_build_query($q)); ?>" title="Tagalog / English"><?php echo $other === 'en' ? 'English' : 'Tagalog'; ?></a>
        <?php if ($parent): ?><a class="lang" href="logout.php" title="<?php echo ppT('logout'); ?>" aria-label="<?php echo ppT('logout'); ?>"><i class="fas fa-right-from-bracket"></i></a><?php endif; ?>
    </div>
</header>
<main class="pp-wrap">
<?php if ($sel): ?>
    <div class="pp-child">
        <div class="av"><?php echo htmlspecialchars(mb_substr($sel['first_name'], 0, 1) . mb_substr($sel['last_name'], 0, 1)); ?></div>
        <div>
            <div class="n"><?php echo htmlspecialchars($sel['first_name'] . ' ' . $sel['last_name']); ?></div>
            <div class="m"><?php echo htmlspecialchars(trim($sel['grade_level'] . ' · ' . ($sel['section_name'] ?? '—'))); ?><?php echo !empty($sel['adviser_name']) ? ' · ' . ppT('adviser') . ': ' . htmlspecialchars($sel['adviser_name']) : ''; ?></div>
        </div>
        <?php if (count($kids) > 1): ?>
            <select class="form-select" aria-label="<?php echo ppT('choose_child'); ?>" onchange="location.href='?child='+this.value">
                <?php foreach ($kids as $k): ?><option value="<?php echo (int) $k['student_id']; ?>" <?php echo (int) $k['student_id'] === (int) $sel['student_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($k['first_name']); ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php
}

function ppBottom($active)
{
    $items = [['index.php', 'home', 'fa-house'], ['grades.php', 'grades', 'fa-chart-line'], ['attendance.php', 'attendance', 'fa-calendar-check'],
              ['tasks.php', 'tasks', 'fa-clipboard-list'], ['updates.php', 'updates', 'fa-bullhorn']];
    ?>
    <p class="pp-note"><i class="fas fa-eye me-1"></i><?php echo ppT('read_only'); ?></p>
</main>
<nav class="pp-nav" aria-label="Menu">
    <?php foreach ($items as $it): ?>
        <a href="<?php echo $it[0]; ?>" class="<?php echo $active === $it[1] ? 'on' : ''; ?>"><i class="fas <?php echo $it[2]; ?>"></i><?php echo ppT($it[1]); ?></a>
    <?php endforeach; ?>
</nav>
<?php ppInstallScript(); ?>
</body>
</html>
<?php
}

/** "Idagdag sa Home Screen": the install prompt on Android; instructions on iPhone. */
function ppInstallScript()
{
    ?>
<script>
(function () {
    if ('serviceWorker' in navigator) navigator.serviceWorker.register(<?php echo json_encode(BASE_URL . 'sw.js'); ?>, { scope: <?php echo json_encode(BASE_URL); ?> }).catch(function () {});
    var deferred = null;
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault(); deferred = e;
        document.querySelectorAll('.pp-install').forEach(function (b) { b.style.display = 'block'; });
    });
    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('.pp-install');
        if (!b) return;
        if (deferred) { deferred.prompt(); deferred = null; b.style.display = 'none'; }
    });
    var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.navigator.standalone;
    if (ios) document.querySelectorAll('.pp-ios-tip').forEach(function (t) { t.style.display = 'block'; });
})();
</script>
<?php
}
