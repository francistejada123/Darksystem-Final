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
$mobile_v = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mobile_v = smsNormalizeNumber($_POST['mobile'] ?? '') ?: preg_replace('/[^\d+]/', '', (string) ($_POST['mobile'] ?? ''));
    $pin = (string) ($_POST['pin'] ?? '');
    $p = $mobile_v !== '' ? getRecord("SELECT * FROM parents WHERE mobile = ? AND is_active = 1", [$mobile_v]) : null;
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = $en ? 'Your session expired. Please try again.' : 'Nag-expire ang session. Pakisubukan ulit.';
    } elseif (isStudentIpRateLimited()) {
        $error = $en ? 'Too many attempts from this phone. Please wait a few minutes.' : 'Maraming subok mula sa phone na ito. Maghintay ng ilang minuto.';
    } elseif ($p && ($m = ppLockedMinutes($p)) > 0) {
        $error = sprintf(ppT('locked'), $m);
    } elseif (!$p || !ppCheckPin($p, $pin)) {
        logActivity(null, 'student', 'Failed login', 'Parent Portal: wrong number or PIN (' . smsMask($mobile_v) . ')');
        $error = ppT('wrong_login');
    } else {
        ppStartSession($p);
        logActivity(null, 'parents', 'Parent login', 'Parent ' . smsMask($p['mobile']) . ' signed in');
        header('Location: index.php');
        exit;
    }
}
$csrf = generateCSRFToken();
ppHead(ppT('login'));
ppTop();
?>
<style>
    .lg label { font-weight: 700; font-size: 15px; margin-bottom: 5px; }
    .lg .form-control { height: 54px; font-size: 18px; }
    .lg .pin { letter-spacing: 8px; font-size: 22px; text-align: center; font-weight: 700; }
</style>
<div class="pp-card lg" style="margin-top:8px">
    <h2 style="font-size:20px"><i class="fas fa-user-shield me-2"></i><?php echo ppT('login_title'); ?></h2>
    <?php if ($error): ?><div class="pp-alert t-bad"><i class="fas fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="POST" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <div class="mb-3"><label for="m"><?php echo ppT('mobile'); ?></label>
            <input type="tel" id="m" class="form-control" name="mobile" inputmode="numeric" maxlength="13" placeholder="09XXXXXXXXX" value="<?php echo htmlspecialchars($mobile_v); ?>" required autocomplete="username"></div>
        <div class="mb-3"><label for="p"><?php echo ppT('pin'); ?></label>
            <input type="password" id="p" class="form-control pin" name="pin" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="••••••" required autocomplete="current-password"></div>
        <button class="btn btn-primary w-100" style="height:54px;font-size:17px;font-weight:800"><i class="fas fa-right-to-bracket me-1"></i> <?php echo ppT('login'); ?></button>
    </form>
    <div class="d-flex justify-content-between mt-3" style="font-size:15px">
        <a href="forgot.php"><?php echo ppT('forgot_pin'); ?></a>
        <a href="setup.php"><?php echo ppT('setup'); ?></a>
    </div>
    <button type="button" class="btn btn-outline-primary pp-install mt-3"><i class="fas fa-mobile-screen me-1"></i> <?php echo ppT('add_home'); ?></button>
    <p class="pp-note pp-ios-tip mt-2"><?php echo $en ? 'iPhone: tap Share, then "Add to Home Screen".' : 'iPhone: pindutin ang Share, tapos "Add to Home Screen".'; ?></p>
</div>
<p class="pp-note"><?php echo ppT('first_time'); ?> <a href="setup.php"><?php echo ppT('setup'); ?></a></p>
</main>
<script>document.querySelectorAll('#m, #p').forEach(function (i) { i.addEventListener('input', function () { this.value = this.value.replace(/[^\d+]/g, ''); }); });</script>
<?php ppInstallScript(); ?>
</body>
</html>
