<?php
// parent/login.php -- Parent Portal sign-in: mobile number + 6-digit PIN.

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/parent_portal.php';
require_once __DIR__ . '/_layout.php';

ppEnsureSchema();
if (!empty($_SESSION['parent_id']) && ($_SESSION['role'] ?? '') === 'Parent') {
    header('Location: index.php');
    exit;
}
$en = ppLang() === 'en';
$error = '';
$login_v = (string) ($_SESSION['pp_login_prefill'] ?? '');   // after a PIN change: the number/email they used
unset($_SESSION['pp_login_prefill']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Mobile number or email (has "@") + PIN.
    $login_v = mb_substr(trim((string) ($_POST['login'] ?? '')), 0, 120);
    $pin = (string) ($_POST['pin'] ?? '');
    $p = $login_v !== '' ? ppFindParentByLogin($login_v) : null;
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = $en ? 'Your session expired. Please try again.' : 'Nag-expire ang session. Pakisubukan ulit.';
    } elseif (isStudentIpRateLimited()) {
        $error = $en ? 'Too many attempts from this phone. Please wait a few minutes.' : 'Maraming subok mula sa phone na ito. Maghintay ng ilang minuto.';
    } elseif ($p && ($m = ppLockedMinutes($p)) > 0) {
        $error = sprintf(ppT('locked'), $m);
    } elseif (!$p || !ppCheckPin($p, $pin)) {
        logActivity(null, 'student', 'Failed login', 'Parent Portal: wrong number/email or PIN (' . (strpos($login_v, '@') !== false ? ppMaskEmail(ppNormEmail($login_v)) : smsMask($login_v)) . ')');
        $error = ppT('wrong_login');
    } else {
        ppStartSession($p);
        logActivity(null, 'parents', 'Parent login', 'Parent ' . smsMask($p['mobile']) . ' signed in');
        header('Location: index.php');
        exit;
    }
}
$csrf = generateCSRFToken();
ppHead(ppT('login'), true);
ppAuthStart('fa-user-lock', ppT('login_title'), ppT('login_sub'));
?>
                <?php if (!$error && isset($_GET['pin_changed'])): ?><div class="pp-alert t-ok"><i class="fas fa-circle-check me-1"></i><?php echo $en ? 'Your PIN was changed. Sign in with your new PIN.' : 'Napalitan na ang PIN. Mag-login gamit ang bagong PIN.'; ?></div><?php endif; ?>
                <?php if ($error): ?><div class="pp-alert t-bad"><i class="fas fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                <form method="POST" autocomplete="on">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <div class="mb-3"><label class="pp-lbl" for="m"><?php echo ppT('login_id'); ?></label>
                        <div class="pp-field"><i class="fas fa-user" id="mIcon"></i>
                            <input type="text" id="m" class="form-control" name="login" maxlength="120" placeholder="09XXXXXXXXX / name@gmail.com" value="<?php echo htmlspecialchars($login_v); ?>" required autocomplete="username" autocapitalize="none" spellcheck="false"></div></div>
                    <div class="mb-4"><label class="pp-lbl" for="p"><?php echo ppT('pin'); ?></label>
                        <?php ppPinField('pin', '••••••', 'current-password', 'p'); ?></div>
                    <button class="btn btn-primary w-100 btn-go"><?php echo ppT('login'); ?> <i class="fas fa-arrow-right ms-1"></i></button>
                </form>
                <div class="links">
                    <a href="forgot.php"><?php echo ppT('forgot_pin'); ?></a>
                    <a href="setup.php"><?php echo ppT('setup'); ?></a>
                </div>
                <button type="button" class="btn btn-outline-primary pp-install mt-3"><i class="fas fa-mobile-screen me-1"></i> <?php echo ppT('add_home'); ?></button>
                <p class="pp-note pp-ios-tip mt-2"><?php echo $en ? 'iPhone: tap Share, then "Add to Home Screen".' : 'iPhone: pindutin ang Share, tapos "Add to Home Screen".'; ?></p>
                <div class="divider"></div>
                <p class="foot mb-0"><?php echo ppT('first_time'); ?> <a href="setup.php"><?php echo ppT('setup'); ?></a></p>
<?php ppAuthEnd('', '<script>(function () { var m = document.getElementById("m"), ic = document.getElementById("mIcon");
    function sync() { ic.className = "fas " + (m.value.indexOf("@") >= 0 ? "fa-envelope" : (/^[\d+]/.test(m.value) ? "fa-mobile-screen-button" : "fa-user")); }
    m.addEventListener("input", sync); sync(); })();</script>');
