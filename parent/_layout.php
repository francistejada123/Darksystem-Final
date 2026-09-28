<?php
// parent/_layout.php
// Shared page frame of the Parent Portal: phone-first app bar with the child
// picker and Tagalog/English switch, bottom navigation, and the install
// ("Idagdag sa Home Screen") support. Read-only pages only.

if (!defined('BASE_URL')) define('BASE_URL', '/watdahel/');

function ppHead($title, $auth = false)
{
    $b = BASE_URL;
    $GLOBALS['pp_title'] = $title;
    ?><!DOCTYPE html>
<html lang="<?php echo ppLang() === 'en' ? 'en' : 'fil'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title><?php echo htmlspecialchars($title); ?> - EGradePro Parent Portal</title>
    <link rel="manifest" href="<?php echo $b; ?>parent/manifest.webmanifest">
    <meta name="theme-color" content="#0B1A3A">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="EGradePro Magulang">
    <link rel="apple-touch-icon" href="<?php echo $b; ?>static/images/app/apple-touch-icon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --pp-ink: #14213D; --pp-navy: #0B1A3A; --pp-blue: #1D4ED8; --pp-blue2: #2563EB; --pp-soft: #E0ECFF; --pp-bg: #F3F6FB; --pp-muted: #52607A; --pp-line: #E3E9F2;
                --pp-shadow: 0 10px 30px rgba(20, 33, 61, .08); --pp-bgimg: url('<?php echo $b; ?>static/images/school%20bldg%20bg.png'); }
        * { box-sizing: border-box; }
        body { background: var(--pp-bg); color: var(--pp-ink); font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 16px; margin: 0; padding-bottom: 96px; }
        a { color: var(--pp-blue); }

        /* app bar (all pages) */
        .pp-bar { position: sticky; top: 0; z-index: 50; background: linear-gradient(90deg, #081530 0%, #14213D 55%, #1E3A8A 100%); color: #fff; padding: 12px clamp(16px, 3vw, 36px); display: flex; align-items: center; gap: 12px; box-shadow: 0 4px 18px rgba(8, 21, 48, .25); }
        .pp-bar img { width: 42px; height: 42px; border-radius: 50%; background: #fff; object-fit: contain; padding: 2px; }
        .pp-bar .t { font-weight: 800; font-size: 19px; line-height: 1.1; letter-spacing: .2px; }
        .pp-bar .t small { display: block; font-weight: 500; font-size: 14px; opacity: .8; letter-spacing: 0; }
        .pp-bar .r { margin-left: auto; display: flex; gap: 8px; align-items: center; }
        .pp-bar a.lang { color: #fff; font-size: 14.5px; font-weight: 700; text-decoration: none; background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.4); border-radius: 999px; padding: 7px 14px; display: inline-flex; align-items: center; gap: 7px; }
        .pp-bar a.lang:hover { background: rgba(255,255,255,.18); }

        /* buttons + inputs */
        .btn-primary { background: linear-gradient(90deg, #1D4ED8, #2563EB 60%, #3B82F6); border: 0; box-shadow: 0 8px 20px rgba(29, 78, 216, .28); }
        .btn-primary:hover, .btn-primary:focus { background: linear-gradient(90deg, #1E40AF, #1D4ED8 60%, #2563EB); }
        .btn-outline-primary { border-color: #BFD3FB; color: var(--pp-blue); background: #fff; }
        .btn-outline-primary:hover { background: var(--pp-soft); color: var(--pp-blue); border-color: #BFD3FB; }
        .btn { border-radius: 12px; }
        .form-control, .form-select { border-radius: 12px; border-color: #D5DDE8; }
        .form-control:focus, .form-select:focus { border-color: #93B4F5; box-shadow: 0 0 0 4px rgba(37, 99, 235, .12); }
        .pp-field { position: relative; }
        .pp-field > i { position: absolute; left: 17px; top: 50%; transform: translateY(-50%); color: #64748B; font-size: 17px; pointer-events: none; }
        .pp-field .form-control { padding-left: 48px; height: 54px; font-size: 17px; }
        .pp-field .pp-eye { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); width: 42px; height: 42px; border: 0; background: none; color: #64748B; border-radius: 10px; }
        .pp-field .pp-eye:hover { background: #F1F5F9; }
        .pp-field .pin { letter-spacing: 6px; font-weight: 700; padding-right: 50px; }
        .pp-field .pin::placeholder { letter-spacing: 6px; }
        .pp-field .pin.ph-text::placeholder { letter-spacing: normal; font-weight: 500; }
        .pp-lbl { font-weight: 700; font-size: 15px; margin-bottom: 6px; display: block; }

        /* inner pages */
        .pp-wrap { max-width: 880px; margin: 0 auto; padding: 18px 16px; }
        .pp-child { position: relative; overflow: hidden; display: flex; align-items: center; gap: 14px; color: #fff; border-radius: 20px; padding: 20px 20px; margin-bottom: 16px; box-shadow: 0 14px 34px rgba(11, 26, 58, .22);
                    background: linear-gradient(100deg, rgba(8, 21, 48, .96) 0%, rgba(20, 33, 61, .9) 45%, rgba(30, 58, 138, .6) 100%), var(--pp-bgimg) right 30% / cover no-repeat; }
        .pp-child .av { width: 56px; height: 56px; border-radius: 50%; background: #fff; color: var(--pp-blue); display: grid; place-items: center; font-weight: 800; font-size: 20px; flex: 0 0 auto; box-shadow: 0 0 0 4px rgba(255,255,255,.2); }
        .pp-child .k { font-size: 13.5px; text-transform: uppercase; letter-spacing: .08em; opacity: .75; font-weight: 700; }
        .pp-child .n { font-weight: 800; font-size: 20px; line-height: 1.2; }
        .pp-child .m { opacity: .85; font-size: 15px; }
        .pp-child select { margin-left: auto; max-width: 42%; font-size: 15px; }
        .pp-pagehd { display: flex; align-items: center; gap: 12px; margin: 4px 2px 14px; }
        .pp-pagehd h1 { font-size: 22px; font-weight: 800; margin: 0; }
        .pp-ic { width: 42px; height: 42px; border-radius: 50%; background: var(--pp-soft); color: var(--pp-blue); display: inline-grid; place-items: center; font-size: 17px; flex: 0 0 auto; }
        .pp-card { background: #fff; border: 1px solid var(--pp-line); border-radius: 18px; padding: 18px 18px; margin-bottom: 16px; box-shadow: var(--pp-shadow); }
        .pp-card h2 { font-size: 18px; font-weight: 800; margin: 0 0 12px; display: flex; align-items: center; gap: 10px; }
        .pp-card h2 > i:first-child { width: 36px; height: 36px; border-radius: 50%; background: var(--pp-soft); color: var(--pp-blue); display: inline-grid; place-items: center; font-size: 15px; margin: 0 !important; flex: 0 0 auto; }
        .pp-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; }
        .pp-stat { background: #fff; border: 1px solid var(--pp-line); border-radius: 18px; padding: 16px 10px; text-align: center; text-decoration: none; color: inherit; box-shadow: var(--pp-shadow); transition: transform .15s, box-shadow .15s; }
        a.pp-stat:hover { transform: translateY(-2px); box-shadow: 0 14px 30px rgba(20, 33, 61, .12); color: inherit; }
        .pp-stat .pp-ic { margin-bottom: 8px; }
        .pp-stat b { display: block; font-size: 24px; font-weight: 800; }
        .pp-stat > span:not(.pp-ic) { display: block; font-size: 14.5px; color: var(--pp-muted); }
        .pp-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 12px 0; border-top: 1px solid #EEF2F6; font-size: 15.5px; }
        .pp-row:first-of-type { border-top: 0; }
        .pp-row small { color: var(--pp-muted); display: block; font-size: 14.5px; }
        .pp-tag { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 14.5px; font-weight: 700; white-space: nowrap; }
        .t-ok { background: #ECFDF5; color: #047857; } .t-warn { background: #FFF7ED; color: #B45309; } .t-bad { background: #FEF2F2; color: #B91C1C; } .t-info { background: #EFF6FF; color: #1D4ED8; } .t-mute { background: #F1F5F9; color: #475569; }
        .pp-alert { border-radius: 14px; padding: 13px 15px; margin-bottom: 16px; font-size: 15px; }
        .pp-empty { text-align: center; color: var(--pp-muted); padding: 22px 8px; font-size: 15px; }
        .pp-note { font-size: 14.5px; color: var(--pp-muted); text-align: center; margin-top: 6px; }
        .pp-nav { position: fixed; left: 50%; transform: translateX(-50%); bottom: 0; width: 100%; max-width: 880px; z-index: 60; background: #fff; border-top: 1px solid var(--pp-line); display: flex; gap: 4px; padding: 7px 6px calc(7px + env(safe-area-inset-bottom)); box-shadow: 0 -8px 24px rgba(20, 33, 61, .08); }
        .pp-nav a { flex: 1; text-align: center; text-decoration: none; color: #64748B; font-size: 13.5px; font-weight: 700; padding: 6px 0; border-radius: 12px; }
        .pp-nav a i { display: block; font-size: 20px; margin-bottom: 3px; }
        .pp-nav a.on { color: var(--pp-blue); background: var(--pp-soft); }
        .pp-install { display: none; width: 100%; }
        .pp-ios-tip { display: none; }
        @media (min-width: 760px) {
            .pp-wrap { padding: 26px 24px; }
            .pp-nav { bottom: 14px; border: 1px solid var(--pp-line); border-radius: 18px; width: calc(100% - 48px); padding: 7px 6px; }
        }

        /* sign-in / setup pages: welcome column + card over the school photo */
        body.pp-authbody { padding-bottom: 0; background: #F5F8FC; }
        .pp-auth { position: relative; min-height: calc(100vh - 66px); overflow: hidden; }
        .pp-auth::before { content: ''; position: absolute; inset: 0; background: var(--pp-bgimg) 72% center / cover no-repeat; }
        .pp-auth::after { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, #F5F8FC 0%, rgba(245,248,252,.97) 30%, rgba(245,248,252,.72) 52%, rgba(245,248,252,.12) 78%, rgba(245,248,252,0) 100%); }
        .pp-auth-in { position: relative; z-index: 3; max-width: 1280px; margin: 0 auto; padding: 56px clamp(16px, 4vw, 48px) 150px; display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 470px) minmax(0, .75fr); gap: clamp(24px, 4vw, 56px); align-items: center; }
        .pp-welcome .bar { width: 44px; height: 5px; border-radius: 3px; background: linear-gradient(90deg, #1D4ED8, #3B82F6); margin-bottom: 18px; }
        .pp-welcome h1 { font-size: clamp(30px, 3.2vw, 42px); font-weight: 800; line-height: 1.15; margin: 0 0 12px; color: var(--pp-ink); }
        .pp-welcome h1 span { color: var(--pp-blue); }
        .pp-welcome .lead2 { font-size: 18px; color: var(--pp-muted); margin-bottom: 30px; }
        .pp-feat { display: flex; align-items: center; gap: 16px; margin-bottom: 20px; }
        .pp-feat .pp-ic { width: 54px; height: 54px; font-size: 20px; background: #fff; box-shadow: 0 6px 18px rgba(29, 78, 216, .14); }
        .pp-feat b { display: block; font-size: 17px; }
        .pp-feat span { color: var(--pp-muted); font-size: 15px; }
        .pp-auth-card { background: #fff; border-radius: 24px; box-shadow: 0 30px 70px rgba(20, 33, 61, .2); padding: 34px 32px 26px; border: 1px solid rgba(227, 233, 242, .8); }
        .pp-auth-card .hd-ic { width: 70px; height: 70px; border-radius: 50%; background: linear-gradient(135deg, #1D4ED8, #3B82F6); color: #fff; display: grid; place-items: center; font-size: 27px; margin: 0 auto 16px; box-shadow: 0 0 0 9px var(--pp-soft); }
        .pp-auth-card .hd-t { font-size: 24px; font-weight: 800; text-align: center; margin: 0 0 8px; }
        .pp-auth-card .hd-s { text-align: center; color: var(--pp-muted); font-size: 15.5px; margin: 0 auto 22px; max-width: 360px; }
        .pp-auth-card .btn-go { height: 56px; font-size: 17px; font-weight: 800; }
        .pp-auth-card .links { display: flex; justify-content: space-between; gap: 10px; margin-top: 16px; font-size: 15px; font-weight: 600; }
        .pp-auth-card .links a { text-decoration: none; }
        .pp-auth-card .divider { border-top: 1px solid var(--pp-line); margin: 18px 0 14px; }
        .pp-auth-card .foot { text-align: center; color: var(--pp-muted); font-size: 15px; }
        .pp-auth-card .foot a { font-weight: 700; text-decoration: none; }
        .pp-auth-card .pp-alert { text-align: left; }
        .pp-auth .pp-under { text-align: center; color: var(--pp-muted); font-size: 14.5px; margin-top: 14px; }
        .pp-wave { position: absolute; left: 0; right: 0; bottom: 0; z-index: 2; line-height: 0; pointer-events: none; }
        .pp-wave svg { width: 100%; height: 120px; display: block; }
        .pp-doodle { position: absolute; z-index: 2; color: #1D4ED8; opacity: .07; pointer-events: none; }
        @media (max-width: 1100px) { .pp-auth-in { grid-template-columns: minmax(0, 1fr) minmax(0, 470px); } }
        @media (max-width: 860px) {
            .pp-auth::after { background: linear-gradient(180deg, rgba(245,248,252,.9) 0%, rgba(245,248,252,.95) 100%); }
            .pp-auth-in { grid-template-columns: 1fr; padding-top: 26px; padding-bottom: 120px; max-width: 520px; }
            .pp-welcome { order: 2; }
            .pp-welcome .bar, .pp-welcome h1, .pp-welcome .lead2 { display: none; }
            .pp-feat { background: rgba(255,255,255,.85); border-radius: 16px; padding: 12px 14px; margin-bottom: 10px; }
            .pp-feat .pp-ic { width: 46px; height: 46px; font-size: 17px; }
            .pp-doodle { display: none; }
        }
        @media (max-width: 480px) {
            .pp-bar .t { font-size: 17px; } .pp-bar img { width: 36px; height: 36px; }
            .pp-auth-card { padding: 26px 18px 20px; border-radius: 20px; }
            .pp-auth-card .hd-t { font-size: 21px; }
            .pp-wave svg { height: 80px; }
        }
    </style>
</head>
<body<?php echo $auth ? ' class="pp-authbody"' : ''; ?>>
<?php
}

/** The dark app bar: logo, "EGradePro / Parent Portal", language pill, sign out. */
function ppBar(array $parent = null)
{
    $b = BASE_URL;
    $other = ppLang() === 'en' ? 'tl' : 'en';
    $q = $_GET; $q['lang'] = $other;
    ?>
<header class="pp-bar">
    <img src="<?php echo $b; ?>static/images/logo.png" alt="">
    <div class="t">EGradePro<small><?php echo ppT('portal'); ?></small></div>
    <div class="r">
        <a class="lang" href="?<?php echo htmlspecialchars(http_build_query($q)); ?>" title="Tagalog / English"><i class="fas fa-globe"></i><?php echo $other === 'en' ? 'English' : 'Tagalog'; ?></a>
        <?php if ($parent): ?><a class="lang" href="logout.php" title="<?php echo ppT('logout'); ?>" aria-label="<?php echo ppT('logout'); ?>"><i class="fas fa-right-from-bracket"></i></a><?php endif; ?>
    </div>
</header>
<?php
}

function ppTop(array $parent = null, array $kids = [], array $sel = null)
{
    ppBar($parent);
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
    $icons = ['grades' => 'fa-chart-line', 'attendance' => 'fa-calendar-check', 'tasks' => 'fa-clipboard-list', 'updates' => 'fa-bullhorn'];
    ?>
<main class="pp-wrap">
<?php if ($sel): ?>
    <div class="pp-child">
        <div class="av"><?php echo htmlspecialchars(mb_substr($sel['first_name'], 0, 1) . mb_substr($sel['last_name'], 0, 1)); ?></div>
        <div>
            <div class="k"><?php echo ppT('child'); ?></div>
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
<?php if ($parent && isset($icons[$page])): ?>
    <div class="pp-pagehd"><span class="pp-ic"><i class="fas <?php echo $icons[$page]; ?>"></i></span><h1><?php echo htmlspecialchars(ppT($page)); ?></h1></div>
<?php endif; ?>
<?php
}

/**
 * Sign-in style pages (login, setup, forgot PIN, share): app bar, the welcome
 * column on the left, then opens the white card. Close with ppAuthEnd().
 */
function ppAuthStart($icon, $title, $sub = '')
{
    ppBar();
    $feats = [['fa-chart-line', 'f_grades', 'f_grades_d'], ['fa-calendar-check', 'f_att', 'f_att_d'], ['fa-bullhorn', 'f_conn', 'f_conn_d']];
    ?>
<main class="pp-auth">
    <i class="fas fa-graduation-cap pp-doodle" style="font-size:90px;left:3%;bottom:150px;transform:rotate(-12deg)"></i>
    <i class="fas fa-book-open pp-doodle" style="font-size:64px;left:24%;bottom:130px;transform:rotate(8deg)"></i>
    <i class="fas fa-pencil pp-doodle" style="font-size:50px;left:38%;top:40px;transform:rotate(30deg)"></i>
    <div class="pp-auth-in">
        <section class="pp-welcome">
            <div class="bar"></div>
            <h1><?php echo ppT('welcome_to'); ?> <span>EGradePro</span></h1>
            <p class="lead2"><?php echo ppT('welcome_sub'); ?></p>
            <?php foreach ($feats as $f): ?>
                <div class="pp-feat"><span class="pp-ic"><i class="fas <?php echo $f[0]; ?>"></i></span><div><b><?php echo ppT($f[1]); ?></b><span><?php echo ppT($f[2]); ?></span></div></div>
            <?php endforeach; ?>
        </section>
        <div>
            <div class="pp-auth-card">
                <div class="hd-ic"><i class="fas <?php echo htmlspecialchars($icon); ?>"></i></div>
                <h2 class="hd-t"><?php echo $title; ?></h2>
                <?php if ($sub !== ''): ?><p class="hd-s"><?php echo $sub; ?></p><?php endif; ?>
<?php
}

/** Closes the card opened by ppAuthStart(); $under is shown below the card. */
function ppAuthEnd($under = '', $script = '')
{
    ?>
            </div>
            <?php if ($under !== ''): ?><p class="pp-under"><?php echo $under; ?></p><?php endif; ?>
        </div>
    </div>
    <div class="pp-wave" aria-hidden="true"><svg viewBox="0 0 1440 120" preserveAspectRatio="none">
        <path d="M0,70 C320,130 620,10 960,55 C1180,85 1320,70 1440,40 L1440,120 L0,120 Z" fill="#14213D"/>
        <path d="M0,70 C320,130 620,10 960,55 C1180,85 1320,70 1440,40" fill="none" stroke="#F5B301" stroke-width="4"/>
        <path d="M0,95 C360,140 700,50 1040,85 C1240,105 1360,95 1440,80 L1440,120 L0,120 Z" fill="#0B1A3A"/>
    </svg></div>
</main>
<script>document.querySelectorAll('.pin, input[type=tel]').forEach(function (i) { i.addEventListener('input', function () { this.value = this.value.replace(/[^\d+]/g, ''); }); });</script>
<?php echo $script; ?>
<?php ppInstallScript(); ?>
</body>
</html>
<?php
}

/** A PIN box with the lock icon and the show/hide eye. */
function ppPinField($name, $placeholder = '••••••', $autocomplete = 'current-password', $id = '')
{
    ?>
    <div class="pp-field"><i class="fas fa-lock"></i>
        <input type="password" class="form-control pin<?php echo $placeholder !== '••••••' ? ' ph-text' : ''; ?>" <?php echo $id ? 'id="' . $id . '"' : ''; ?> name="<?php echo $name; ?>" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="<?php echo htmlspecialchars($placeholder); ?>" required autocomplete="<?php echo $autocomplete; ?>">
        <button type="button" class="pp-eye" aria-label="Show PIN"><i class="fas fa-eye"></i></button>
    </div>
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
    document.addEventListener('click', function (e) {   // show / hide PIN
        var eye = e.target.closest && e.target.closest('.pp-eye');
        if (!eye) return;
        var inp = eye.parentNode.querySelector('input');
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        eye.querySelector('i').className = 'fas ' + (show ? 'fa-eye-slash' : 'fa-eye');
    });
    var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.navigator.standalone;
    if (ios) document.querySelectorAll('.pp-ios-tip').forEach(function (t) { t.style.display = 'block'; });
})();
</script>
<?php
}
