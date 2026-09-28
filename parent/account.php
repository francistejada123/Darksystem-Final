<?php
// parent/account.php -- "Aking Account": the signed-in parent's mobile number
// and email. Adding or changing the email needs the current PIN and a
// 6-digit code sent to the new address; the old address is told about it.

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/parent_portal.php';
require_once __DIR__ . '/_layout.php';

[$parent, $kids, $sel] = ppRequireParent();
ppEmailEnsureSchema();
$parent = getRecord("SELECT * FROM parents WHERE parent_id = ?", [(int) $parent['parent_id']]);
$en = ppLang() === 'en';
$error = '';
$info = '';
$pend = $_SESSION['pp_acc'] ?? null;   // ['email' => new address, 'until' => ...]
if ($pend && ($pend['until'] < time() || isset($_GET['cancel']))) {
    unset($_SESSION['pp_acc']);
    $pend = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = $en ? 'Your session expired. Please try again.' : 'Nag-expire ang session. Pakisubukan ulit.';
    } elseif ($action === 'email') {
        $email = ppNormEmail($_POST['email'] ?? '');
        if (!ppMailReady()) {
            $error = $en ? 'Email cannot be sent right now. Please try again later.' : 'Hindi makapagpadala ng email sa ngayon. Subukan ulit mamaya.';
        } elseif ($email === '') {
            $error = $en ? 'Enter a valid email (for example name@gmail.com).' : 'Ilagay ang tamang email (halimbawa: pangalan@gmail.com).';
        } elseif ($email === (string) $parent['email']) {
            $error = $en ? 'That is already your email.' : 'Iyan na ang email ninyo.';
        } elseif (ppEmailTaken($email, (int) $parent['parent_id'])) {
            $error = $en ? 'This email is already used by another Parent Portal account.' : 'Ginagamit na ng ibang Parent Portal account ang email na ito.';
        } elseif (($m = ppLockedMinutes($parent)) > 0) {
            $error = sprintf(ppT('locked'), $m);
        } elseif (!ppCheckPin($parent, $_POST['pin'] ?? '')) {
            $error = $en ? 'Wrong PIN.' : 'Mali ang PIN.';
        } else {
            $r = ppCodeSend('change', $email, $email, (int) $parent['parent_id']);
            $pend = ['email' => $email, 'until' => time() + 30 * 60];
            $_SESSION['pp_acc'] = $pend;
            if (!$r['ok']) $info = sprintf($en ? 'A code was sent a moment ago. You can ask for a new one in %d seconds.' : 'May code nang naipadala kanina. Puwedeng humingi ng bago pagkalipas ng %d segundo.', $r['wait']);
        }
    } elseif ($pend && $action === 'resend') {
        $r = ppCodeSend('change', $pend['email'], $pend['email'], (int) $parent['parent_id']);
        if ($r['ok']) $info = $en ? 'A new code was sent. The old one no longer works.' : 'Nagpadala ng bagong code. Hindi na gagana ang luma.';
        else $error = sprintf($en ? 'Please wait %d seconds before asking for another code.' : 'Maghintay ng %d segundo bago humingi ng bagong code.', $r['wait']);
    } elseif ($pend && $action === 'verify') {
        $v = ppCodeVerify('change', $pend['email'], preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')));
        if ($v['ok'] && (int) $v['parent_id'] === (int) $parent['parent_id']) {
            unset($_SESSION['pp_acc']);
            if (!ppEmailTaken($pend['email'], (int) $parent['parent_id']) && ppSetEmail((int) $parent['parent_id'], $pend['email'])) {
                header('Location: account.php?saved=1');
                exit;
            }
            $pend = null;
            $error = $en ? 'This email is already used by another Parent Portal account.' : 'Ginagamit na ng ibang Parent Portal account ang email na ito.';
        } elseif (!empty($v['dead'])) {
            $error = $en ? 'This code no longer works (expired or too many wrong tries). Tap "Send a new code".' : 'Hindi na gumagana ang code (expired o maraming maling subok). Pindutin ang "Magpadala ng bagong code".';
        } else {
            $error = sprintf($en ? 'Wrong code. %d tries left.' : 'Mali ang code. %d subok na lang.', $v['left'] ?? 0);
        }
    }
}
$csrf = generateCSRFToken();

ppHead(ppT('account'));
ppTop($parent, [], null);
?>
<?php if (isset($_GET['saved'])): ?><div class="pp-alert t-ok"><i class="fas fa-circle-check me-1"></i><?php echo $en ? 'Your email is saved. You can now sign in with it, and it will get the code if you forget your PIN.' : 'Naka-save na ang email. Puwede na itong gamitin sa pag-login, at dito ipapadala ang code kapag nakalimutan ang PIN.'; ?></div><?php endif; ?>
<?php if ($error): ?><div class="pp-alert t-bad"><i class="fas fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($info): ?><div class="pp-alert t-ok"><i class="fas fa-circle-check me-1"></i><?php echo htmlspecialchars($info); ?></div><?php endif; ?>

<div class="pp-card">
    <h2><i class="fas fa-id-card"></i><?php echo $en ? 'Sign-in details' : 'Pang-login'; ?></h2>
    <div class="pp-row"><div><?php echo ppT('mobile'); ?><small><?php echo $en ? 'Sign in with this or your email' : 'Pang-login, o ang email'; ?></small></div><b><?php echo htmlspecialchars($parent['mobile']); ?></b></div>
    <div class="pp-row"><div>Email<small><?php echo $en ? 'Sign-in and Forgot PIN' : 'Pang-login at Forgot PIN'; ?></small></div>
        <?php if (!empty($parent['email'])): ?><span style="text-align:right"><b style="word-break:break-all"><?php echo htmlspecialchars($parent['email']); ?></b><br><span class="pp-tag <?php echo $parent['email_verified_at'] ? 't-ok' : 't-warn'; ?>"><?php echo $parent['email_verified_at'] ? ($en ? 'Confirmed' : 'Kumpirmado') : ($en ? 'Not confirmed' : 'Hindi pa kumpirmado'); ?></span></span>
        <?php else: ?><span class="pp-tag t-warn"><?php echo $en ? 'None yet' : 'Wala pa'; ?></span><?php endif; ?></div>
</div>

<div class="pp-card">
<?php if ($pend): ?>
    <h2><i class="fas fa-envelope-open-text"></i><?php echo $en ? 'Confirm your email' : 'Kumpirmahin ang email'; ?></h2>
    <p style="color:var(--pp-muted);font-size:15px"><?php echo sprintf($en ? 'We sent a 6-digit code to %s. It works for %d minutes. Check Spam too.' : 'Nagpadala kami ng 6-digit na code sa %s. Gagana ito sa loob ng %d minuto. Tingnan din ang Spam.', '<strong style="color:var(--pp-ink);word-break:break-all">' . htmlspecialchars($pend['email']) . '</strong>', PP_OTP_MINUTES); ?></p>
    <?php ppCodeForm($csrf, 'verify', 'resend', ppCodeWait($pend['email']), 'account.php?cancel=1', $en ? 'Cancel' : 'Kanselahin', $en ? 'Confirm and save' : 'Kumpirmahin at i-save'); ?>
<?php else: ?>
    <h2><i class="fas fa-envelope"></i><?php echo empty($parent['email']) ? ($en ? 'Add an email' : 'Magdagdag ng email') : ($en ? 'Change email' : 'Palitan ang email'); ?></h2>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="email">
        <label class="pp-lbl" for="em"><?php echo $en ? 'New email' : 'Bagong email'; ?></label>
        <div class="pp-field mb-3"><i class="fas fa-envelope"></i><input type="email" id="em" class="form-control" name="email" maxlength="100" placeholder="<?php echo $en ? 'name@gmail.com' : 'pangalan@gmail.com'; ?>" required autocapitalize="none" spellcheck="false"></div>
        <label class="pp-lbl"><?php echo $en ? 'Your current PIN' : 'Kasalukuyang PIN'; ?></label>
        <?php ppPinField('pin', '••••••', 'current-password'); ?>
        <button class="btn btn-primary w-100 mt-3" style="height:52px;font-weight:800"><i class="fas fa-paper-plane me-1"></i> <?php echo $en ? 'Send a code to this email' : 'Magpadala ng code sa email na ito'; ?></button>
    </form>
<?php endif; ?>
</div>
<?php ppBottom('account');
