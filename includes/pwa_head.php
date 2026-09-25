<?php
// includes/pwa_head.php
// Makes the Student Portal / Online Enrollment installable as a phone app
// ("Install app" / Add to Home Screen). Include it inside <head> of student
// pages only. Staff pages never include it.
$pwa_base = defined('BASE_URL') ? BASE_URL : '/watdahel/';
?>
    <link rel="manifest" href="<?php echo $pwa_base; ?>manifest.webmanifest">
    <meta name="theme-color" content="#0d47a1">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="EGradePro">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="apple-touch-icon" href="<?php echo $pwa_base; ?>static/images/app/apple-touch-icon.png">
    <style>
        .pwa-install { position: fixed; left: 50%; bottom: 16px; transform: translateX(-50%); z-index: 3000; display: none; align-items: center; gap: 10px;
            background: #0d47a1; color: #fff; border-radius: 999px; padding: 10px 14px 10px 16px; box-shadow: 0 8px 24px rgba(13,71,161,.35); font: 600 14px/1.2 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: calc(100% - 32px); }
        .pwa-install button { border: 0; border-radius: 999px; padding: 7px 14px; font-weight: 700; cursor: pointer; }
        .pwa-install .pwa-go { background: #fff; color: #0d47a1; }
        .pwa-install .pwa-x { background: transparent; color: #fff; padding: 4px 6px; font-size: 18px; line-height: 1; }
        .pwa-install.show { display: flex; }
    </style>
    <script>
    (function () {
        var BASE = <?php echo json_encode($pwa_base); ?>;
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register(BASE + 'sw.js', { scope: BASE }).catch(function () {});
            });
        }
        // "Install app" bar on phones (not when already opened as the app, not after "x").
        var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
        var dismissed = false;
        try { dismissed = localStorage.getItem('egp-install-dismissed') === '1'; } catch (e) {}
        if (standalone || dismissed) return;
        var deferred = null;
        function bar(text, onGo) {
            var b = document.createElement('div');
            b.className = 'pwa-install';
            b.innerHTML = '<span>' + text + '</span>' + (onGo ? '<button type="button" class="pwa-go">Install</button>' : '') + '<button type="button" class="pwa-x" aria-label="Close">&times;</button>';
            document.body.appendChild(b);
            if (onGo) b.querySelector('.pwa-go').onclick = onGo;
            b.querySelector('.pwa-x').onclick = function () { b.remove(); try { localStorage.setItem('egp-install-dismissed', '1'); } catch (e) {} };
            requestAnimationFrame(function () { b.classList.add('show'); });
            return b;
        }
        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferred = e;
            var b = bar('&#128241; Install the EGradePro app', function () {
                b.remove();
                deferred.prompt();
                deferred = null;
            });
        });
        // iPhone / iPad Safari has no install prompt: show how.
        var ios = /iPhone|iPad|iPod/.test(navigator.userAgent) && !window.MSStream;
        if (ios) {
            window.addEventListener('load', function () { bar('Install: tap Share <b>&#x2191;</b> then <b>Add to Home Screen</b>', null); });
        }
    })();
    </script>
