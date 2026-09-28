<?php
// parent/forgot.php -- Forgot PIN, in three steps on this page:
//   1. the parent's mobile number  -> a 6-digit code is texted to it (no link:
//      the networks block texts with links)
//   2. the code                    -> 10 minutes, 5 tries, 60 s between codes
//   3. a new 6-digit PIN           -> saved, signed in
// The screens are the same whether or not the number has an account.
// Without an SMS sender: ask the adviser/registrar for a Reset PIN slip.

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/parent_portal.php';
require_once __DIR__ . '/_layout.php';

header('X-Robots-Tag: noindex, nofollow');
ppEnsureSchema();
ppOtpEnsureSchema();
$en = ppLang() === 'en';
$error = '';
$info = '';
$fp = $_SESSION['pp_fp'] ?? [];   // ['mobile' => ..., 'parent_id' => ..., 'until' => ...]

if (isset($_GET['restart'])) {
    unset($_SESSION['pp_fp']);
    header('Location: forgot.php');
    exit;
}
if (!empty($fp['parent_id']) && (int) ($fp['until'] ?? 0) < time()) {   // took too long after the code
    $fp = ['mobile' => $fp['mobile']];
    $_SESSION['pp_fp'] = $fp;
    $error = $en ? 'That took too long. Please request a new code.' : 'Masyadong natagalan. Humingi ng bagong code.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && smsReady()) {
    $action = $_POST['action'] ?? '';
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = $en ? 'Your session expired. Please try again.' : 'Nag-expire ang session. Pakisubukan ulit.';
    } elseif (isStudentIpRateLimited() && $action !== 'pin') {
        $error = $en ? 'Too many attempts. Please wait a few minutes.' : 'Maraming subok. Maghintay ng ilang minuto.';
    } elseif ($action === 'send' || $action === 'resend') {
        $mobile = $action === 'resend' ? (string) ($fp['mobile'] ?? '') : smsNormalizeNumber($_POST['mobile'] ?? '');
        if ($mobile === '') {
            $error = $en ? 'Enter a valid mobile number (09XXXXXXXXX).' : 'Ilagay ang tamang cellphone number (09XXXXXXXXX).';
        } else {
            $r = ppOtpRequest($mobile);
            $fp = ['mobile' => $mobile];
            $_SESSION['pp_fp'] = $fp;
            if (!$r['ok']) {
                $error = $r['wait'] > PP_OTP_GAP
                    ? sprintf($en ? 'Too many codes for this number. Try again in %d minute(s).' : 'Maraming code na para sa number na ito. Subukan ulit pagkalipas ng %d minuto.', (int) ceil($r['wait'] / 60))
                    : sprintf($en ? 'Please wait %d seconds before asking for another code.' : 'Maghintay ng %d segundo bago humingi ng bagong code.', $r['wait']);
            } else {
                $info = $action === 'resend' ? ($en ? 'A new code was sent. The old one no longer works.' : 'Nagpadala ng bagong code. Hindi na gagana ang luma.') : '';
            }
        }
    } elseif ($action === 'verify' && !empty($fp['mobile'])) {
        $v = ppOtpVerify($fp['mobile'], preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')));
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
            logActivity(null, 'parents', 'Parent PIN reset', 'New PIN set with a texted code (' . smsMask($p['mobile']) . ')');
            ppStartSession($p);   // also clears pp_fp
            header('Location: index.php?welcome=reset');
            exit;
        }
    }
}

$step = !smsReady() ? 'none' : (!empty($fp['parent_id']) ? 'pin' : (!empty($fp['mobile']) ? 'code' : 'number'));
$wait = $step === 'code' ? ppOtpWait($fp['mobile']) : 0;
$csrf = generateCSRFToken();
$masked = !empty($fp['mobile']) ? substr($fp['mobile'], 0, 4) . '•••' . substr($fp['mobile'], -4) : '';

$titles = [
    'none' => [ppT('forgot_pin'), $en ? 'We will help you choose a new PIN.' : 'Tutulungan namin kayong gumawa ng bagong PIN.'],
    'number' => [ppT('forgot_pin'), $en ? 'Enter your mobile number. We will text you a 6-digit code.' : 'Ilagay ang inyong cellphone number. Magte-text kami ng 6-digit na code.'],
    'code' => [$en ? 'Enter the code' : 'Ilagay ang code', sprintf($en ? 'If %s has a Parent Portal account, we texted a 6-digit code to it. It works for %d minutes.' : 'Kung may Parent Portal account ang %s, nag-text kami ng 6-digit na code. Gagana ito sa loob ng %d minuto.', '<strong style="color:var(--pp-ink)">' . $masked . '</strong>', PP_OTP_MINUTES)],
    'pin' => [$en ? 'Choose a new PIN' : 'Gumawa ng bagong PIN', $en ? 'The code is correct. Create your new 6-digit PIN.' : 'Tama ang code. Gumawa ng bagong 6-digit na PIN.'],
];
ppHead(ppT('forgot_pin'), true);
ppAuthStart($step === 'code' ? 'fa-comment-sms' : ($step === 'pin' ? 'fa-lock' : 'fa-key'), $titles[$step][0], $titles[$step][1]);
?>
<style>
    .otp { display: flex; gap: 8px; justify-content: center; margin-bottom: 8px; }
    .otp input { width: 50px; height: 58px; text-align: center; font-size: 24px; font-weight: 800; padding: 0; }
    @media (max-width: 400px) { .otp { gap: 6px; } .otp input { width: 42px; height: 52px; font-size: 21px; } }
    .fp-steps { display: flex; justify-content: center; gap: 6px; margin: -8px 0 18px; }
    .fp-steps span { width: 34px; height: 5px; border-radius: 3px; background: var(--pp-line); }
    .fp-steps span.on { background: linear-gradient(90deg, #1D4ED8, #3B82F6); }
    .linkbtn { background: none; border: 0; padding: 0; color: var(--pp-blue); font-weight: 600; font-size: 15px; }
    .linkbtn:disabled { color: #94A3B8; }
</style>
<?php if ($step !== 'none'): $n = ['number' => 1, 'code' => 2, 'pin' => 3][$step]; ?>
    <div class="fp-steps" aria-hidden="true"><?php for ($i = 1; $i <= 3; $i++) echo '<span class="' . ($i <= $n ? 'on' : '') . '"></span>'; ?></div>
<?php endif; ?>
<?php if ($error): ?><div class="pp-alert t-bad"><i class="fas fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($info): ?><div class="pp-alert t-ok"><i class="fas fa-circle-check me-1"></i><?php echo htmlspecialchars($info); ?></div><?php endif; ?>

<?php if ($step === 'none'): ?>
    <div class="pp-alert t-info"><i class="fas fa-circle-info me-1"></i><?php echo $en ? 'Please ask your child\'s adviser or the registrar for a new Parent Access Slip ("Reset PIN"). Bring a valid ID. You will choose a new PIN yourself.' : 'Humingi po ng bagong Parent Access Slip ("Reset PIN") sa adviser ng inyong anak o sa registrar. Magdala ng valid ID. Kayo mismo ang gagawa ng bagong PIN.'; ?></div>

<?php elseif ($step === 'number'): ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="send">
        <label class="pp-lbl" for="m"><?php echo ppT('mobile'); ?></label>
        <div class="pp-field mb-4"><i class="fas fa-mobile-screen-button"></i>
            <input type="tel" id="m" class="form-control" name="mobile" inputmode="numeric" maxlength="13" placeholder="09XXXXXXXXX" required autocomplete="tel"></div>
        <button class="btn btn-primary w-100 btn-go"><i class="fas fa-paper-plane me-1"></i> <?php echo $en ? 'Send code' : 'Ipadala ang code'; ?></button>
    </form>

<?php elseif ($step === 'code'): ?>
    <form method="POST" id="codeForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="verify">
        <input type="hidden" name="code" id="codeVal">
        <div class="otp" aria-label="<?php echo $en ? '6-digit code' : '6-digit na code'; ?>">
            <?php for ($i = 0; $i < 6; $i++): ?><input class="form-control" type="text" inputmode="numeric" <?php echo $i === 0 ? 'autocomplete="one-time-code" autofocus' : ''; ?> aria-label="<?php echo $i + 1; ?>"><?php endfor; ?>
        </div>
        <button class="btn btn-primary w-100 btn-go mt-3" id="codeBtn"><?php echo $en ? 'Verify code' : 'I-verify ang code'; ?> <i class="fas fa-arrow-right ms-1"></i></button>
    </form>
    <form method="POST" class="links">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="resend">
        <a href="forgot.php?restart=1"><?php echo $en ? 'Change number' : 'Ibang number'; ?></a>
        <button class="linkbtn" id="resendBtn" data-wait="<?php echo (int) $wait; ?>" <?php echo $wait > 0 ? 'disabled' : ''; ?>><?php echo $en ? 'Send a new code' : 'Magpadala ng bagong code'; ?><span id="resendT"></span></button>
    </form>

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
<?php ob_start(); ?>
<script>
(function () {
    // 6 code boxes: type/paste moves along; the 6th digit submits.
    var boxes = [].slice.call(document.querySelectorAll('.otp input'));
    var form = document.getElementById('codeForm');
    if (boxes.length) {
        function fill(start, digits) {
            for (var i = 0; i < digits.length && start + i < boxes.length; i++) boxes[start + i].value = digits[i];
            var next = Math.min(start + digits.length, boxes.length - 1);
            boxes[next].focus();
            var code = boxes.map(function (b) { return b.value; }).join('');
            if (code.length === 6) { document.getElementById('codeVal').value = code; form.submit(); }
        }
        boxes.forEach(function (b, i) {
            b.addEventListener('input', function () { var d = this.value.replace(/\D/g, ''); this.value = ''; if (d) fill(i, d.split('')); });
            b.addEventListener('keydown', function (e) { if (e.key === 'Backspace' && !this.value && i > 0) { boxes[i - 1].value = ''; boxes[i - 1].focus(); } });
            b.addEventListener('paste', function (e) { var d = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6); if (d) { e.preventDefault(); fill(0, d.split('')); } });
        });
        form.addEventListener('submit', function (e) {
            var code = boxes.map(function (b) { return b.value; }).join('');
            if (code.length !== 6) { e.preventDefault(); boxes[code.length].focus(); return; }
            document.getElementById('codeVal').value = code;
        });
    }
    // "Send a new code" unlocks after the wait.
    var rb = document.getElementById('resendBtn'), rt = document.getElementById('resendT');
    if (rb) {
        var left = parseInt(rb.getAttribute('data-wait'), 10) || 0;
        (function tick() {
            if (left <= 0) { rb.disabled = false; rt.textContent = ''; return; }
            rt.textContent = ' (' + left + 's)'; left--; setTimeout(tick, 1000);
        })();
    }
})();
</script>
<?php ppAuthEnd('<i class="fas fa-shield-halved me-1"></i>' . ($en ? 'The school will never ask for this code. Do not give it to anyone.' : 'Hindi hihingin ng school ang code na ito. Huwag itong ibigay kaninuman.'), ob_get_clean());
