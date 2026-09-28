<?php
// parent/forgot.php -- Forgot PIN, in three steps on this page:
//   1. mobile number or email      -> a 6-digit code is emailed to the account's email
//   2. the code                    -> 10 minutes, 5 tries, 60 s between codes, 3 per hour
//   3. a new 6-digit PIN           -> saved, signed in, "PIN changed" email
// The screens are the same whether or not the account exists. An account
// without an email (or no email sender on the server): ask the adviser or
// registrar for a Reset PIN slip.

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/parent_portal.php';
require_once __DIR__ . '/_layout.php';

header('X-Robots-Tag: noindex, nofollow');
ppEmailEnsureSchema();
$en = ppLang() === 'en';
$error = '';
$info = '';
$fp = $_SESSION['pp_fp'] ?? [];   // ['login' => typed, 'target' => ..., 'parent_id' => ..., 'until' => ...]

if (isset($_GET['restart'])) {
    unset($_SESSION['pp_fp']);
    header('Location: forgot.php');
    exit;
}
if (!empty($fp['parent_id']) && (int) ($fp['until'] ?? 0) < time()) {   // took too long after the code
    $fp = ['login' => $fp['login'], 'target' => $fp['target']];
    $_SESSION['pp_fp'] = $fp;
    $error = $en ? 'That took too long. Please ask for a new code.' : 'Masyadong natagalan. Humingi ng bagong code.';
}

/** Email a code for what was typed (only when that account has an email). */
function fpSend($login)
{
    $p = ppFindParentByLogin($login);
    if ($p && !empty($p['email'])) {
        $target = $p['email'];
        $r = ppCodeSend('reset', $target, $p['email'], (int) $p['parent_id']);
    } else {
        $target = strpos($login, '@') !== false ? ppNormEmail($login) : smsNormalizeNumber($login);
        $r = ppCodeSend('reset', $target, null);
        logActivity(null, 'student', 'Failed login', 'Parent Forgot PIN: ' . ($p ? 'account has no email' : 'no account') . ' (' . (strpos($login, '@') !== false ? ppMaskEmail($target) : smsMask($target)) . ')');
    }
    return [$target, $r];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ppMailReady()) {
    $action = $_POST['action'] ?? '';
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = $en ? 'Your session expired. Please try again.' : 'Nag-expire ang session. Pakisubukan ulit.';
    } elseif (isStudentIpRateLimited() && $action !== 'pin') {
        $error = $en ? 'Too many attempts. Please wait a few minutes.' : 'Maraming subok. Maghintay ng ilang minuto.';
    } elseif ($action === 'send' || $action === 'resend') {
        $login = $action === 'resend' ? (string) ($fp['login'] ?? '') : mb_substr(trim((string) ($_POST['login'] ?? '')), 0, 120);
        $valid = strpos($login, '@') !== false ? ppNormEmail($login) !== '' : smsNormalizeNumber($login) !== '';
        if (!$valid) {
            $error = $en ? 'Enter your mobile number (09XXXXXXXXX) or your email.' : 'Ilagay ang cellphone number (09XXXXXXXXX) o ang email ninyo.';
        } else {
            [$target, $r] = fpSend($login);
            $fp = ['login' => $login, 'target' => $target];
            $_SESSION['pp_fp'] = $fp;
            if (!$r['ok']) {
                $error = $r['wait'] > PP_OTP_GAP
                    ? sprintf($en ? 'Too many codes for this account. Try again in %d minute(s).' : 'Maraming code na para sa account na ito. Subukan ulit pagkalipas ng %d minuto.', (int) ceil($r['wait'] / 60))
                    : sprintf($en ? 'Please wait %d seconds before asking for another code.' : 'Maghintay ng %d segundo bago humingi ng bagong code.', $r['wait']);
            } elseif ($action === 'resend') {
                $info = $en ? 'A new code was sent. The old one no longer works.' : 'Nagpadala ng bagong code. Hindi na gagana ang luma.';
            }
        }
    } elseif ($action === 'verify' && !empty($fp['target'])) {
        $v = ppCodeVerify('reset', $fp['target'], preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')));
        if ($v['ok']) {
            $fp['parent_id'] = $v['parent_id'];
            $fp['until'] = time() + 10 * 60;
            $_SESSION['pp_fp'] = $fp;
        } elseif (!empty($v['dead'])) {
            $error = $en ? 'This code no longer works (expired or too many wrong tries). Tap "Send a new code".' : 'Hindi na gumagana ang code (expired o maraming maling subok). Pindutin ang "Magpadala ng bagong code".';
        } else {
            $error = sprintf($en ? 'Wrong code. %d tries left.' : 'Mali ang code. %d subok na lang.', $v['left']);
        }
    } elseif ($action === 'pin' && !empty($fp['parent_id'])) {
        $p = getRecord("SELECT * FROM parents WHERE parent_id = ? AND is_active = 1", [(int) $fp['parent_id']]);
        if (!$p) {
            unset($_SESSION['pp_fp']);
            $fp = [];
            $error = $en ? 'This account is no longer active. Please ask the school.' : 'Hindi na aktibo ang account na ito. Magtanong sa school.';
        } elseif (($pe = ppPinProblem($_POST['pin'] ?? '', $_POST['confirm_pin'] ?? '')) !== '') {
            $error = $pe;
        } else {
            executeQuery("UPDATE parents SET pin_hash = ?, failed_logins = 0, locked_until = NULL WHERE parent_id = ?", [password_hash($_POST['pin'], PASSWORD_DEFAULT), (int) $p['parent_id']]);
            logActivity(null, 'parents', 'Parent PIN reset', 'New PIN set with an emailed code (' . smsMask($p['mobile']) . ')');
            ppMailPinChanged($p['email']);
            // Sign in again with the new PIN (no automatic sign-in).
            $prefill = (string) ($fp['login'] ?? '');
            unset($_SESSION['pp_fp'], $_SESSION['parent_id'], $_SESSION['pp_child']);
            session_regenerate_id(true);
            $_SESSION['pp_login_prefill'] = $prefill;
            header('Location: login.php?pin_changed=1');
            exit;
        }
    }
}

$step = !ppMailReady() ? 'none' : (!empty($fp['parent_id']) ? 'pin' : (!empty($fp['target']) ? 'code' : 'id'));
$wait = $step === 'code' ? ppCodeWait($fp['target']) : 0;
$csrf = generateCSRFToken();

$titles = [
    'none' => [ppT('forgot_pin'), $en ? 'We will help you choose a new PIN.' : 'Tutulungan namin kayong gumawa ng bagong PIN.'],
    'id' => [ppT('forgot_pin'), $en ? 'Enter your mobile number or email. We will email you a 6-digit code.' : 'Ilagay ang cellphone number o email ninyo. Magpapadala kami ng 6-digit na code sa email ninyo.'],
    'code' => [$en ? 'Check your email' : 'Tingnan ang email ninyo', sprintf($en ? 'If this account has an email, we sent a 6-digit code to it. It works for %d minutes. Check Spam too.' : 'Kung may email ang account na ito, nagpadala kami ng 6-digit na code doon. Gagana ito sa loob ng %d minuto. Tingnan din ang Spam.', PP_OTP_MINUTES)],
    'pin' => [$en ? 'Choose a new PIN' : 'Gumawa ng bagong PIN', $en ? 'The code is correct. Create your new 6-digit PIN.' : 'Tama ang code. Gumawa ng bagong 6-digit na PIN.'],
];
ppHead(ppT('forgot_pin'), true);
ppAuthStart($step === 'code' ? 'fa-envelope-open-text' : ($step === 'pin' ? 'fa-lock' : 'fa-key'), $titles[$step][0], $titles[$step][1]);
if ($step !== 'none') ppSteps(['id' => 1, 'code' => 2, 'pin' => 3][$step]);
?>
<?php if ($error): ?><div class="pp-alert t-bad"><i class="fas fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($info): ?><div class="pp-alert t-ok"><i class="fas fa-circle-check me-1"></i><?php echo htmlspecialchars($info); ?></div><?php endif; ?>

<?php if ($step === 'none'): ?>
    <div class="pp-alert t-info"><i class="fas fa-circle-info me-1"></i><?php echo $en ? 'Please ask your child\'s adviser or the registrar for a new Parent Access Slip ("Reset PIN"). Bring a valid ID. You will choose a new PIN yourself.' : 'Humingi po ng bagong Parent Access Slip ("Reset PIN") sa adviser ng inyong anak o sa registrar. Magdala ng valid ID. Kayo mismo ang gagawa ng bagong PIN.'; ?></div>

<?php elseif ($step === 'id'): ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="send">
        <label class="pp-lbl" for="m"><?php echo ppT('login_id'); ?></label>
        <div class="pp-field mb-4"><i class="fas fa-user"></i>
            <input type="text" id="m" class="form-control" name="login" maxlength="120" placeholder="09XXXXXXXXX / name@gmail.com" required autocomplete="username" autocapitalize="none" spellcheck="false"></div>
        <button class="btn btn-primary w-100 btn-go"><i class="fas fa-paper-plane me-1"></i> <?php echo $en ? 'Email me a code' : 'Ipadala ang code sa email'; ?></button>
    </form>

<?php elseif ($step === 'code'): ?>
    <?php ppCodeForm($csrf, 'verify', 'resend', $wait, 'forgot.php?restart=1', $en ? 'Change' : 'Palitan'); ?>
    <p class="pp-note" style="text-align:left"><i class="fas fa-circle-info me-1"></i><?php echo $en ? 'No email on your account, or no code after a few minutes? Ask the adviser or registrar for a Reset PIN slip.' : 'Walang email ang account, o walang dumating na code? Humingi ng Reset PIN slip sa adviser o registrar.'; ?></p>

<?php else: ?>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="pin">
        <label class="pp-lbl"><?php echo $en ? 'New 6-digit PIN' : 'Bagong 6-digit PIN'; ?></label>
        <?php ppPinField('pin', '••••••', 'new-password'); ?>
        <div class="mt-2"><?php ppPinField('confirm_pin', $en ? 'Repeat PIN' : 'Ulitin ang PIN', 'new-password'); ?></div>
        <p class="pp-note" style="text-align:left"><?php echo $en ? 'Only you know this PIN. The school cannot see it. Avoid 123456 or 111111.' : 'Kayo lang ang nakakaalam ng PIN. Hindi ito nakikita ng school. Iwasan ang 123456 o 111111.'; ?></p>
        <button class="btn btn-primary w-100 btn-go mt-2"><i class="fas fa-check me-1"></i> <?php echo $en ? 'Save new PIN' : 'I-save ang bagong PIN'; ?></button>
    </form>
<?php endif; ?>
    <div class="divider"></div>
    <p class="foot mb-0"><a href="login.php"><i class="fas fa-arrow-left me-1"></i><?php echo $en ? 'Back to Sign in' : 'Bumalik sa Mag-login'; ?></a></p>
<?php ppAuthEnd('<i class="fas fa-shield-halved me-1"></i>' . ($en ? 'The school will never ask for this code. Do not give it to anyone.' : 'Hindi hihingin ng school ang code na ito. Huwag itong ibigay kaninuman.'));
