<?php
// parent/forgot.php -- Forgot PIN.
// With an SMS sender set up: a reset link is texted to the parent's OWN number
// (proves it is theirs); the reset also asks the child's birthday. Without SMS:
// the adviser/registrar gives a new Parent Access slip ("Reset PIN").
// The answer is the same whether or not the number has an account.

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/parent_portal.php';
require_once __DIR__ . '/_layout.php';

ppEnsureSchema();
$en = ppLang() === 'en';
$sent = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && smsReady()) {
    $mobile = smsNormalizeNumber($_POST['mobile'] ?? '');
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = $en ? 'Your session expired. Please try again.' : 'Nag-expire ang session. Pakisubukan ulit.';
    } elseif ($mobile === '') {
        $error = $en ? 'Enter a valid mobile number.' : 'Ilagay ang tamang cellphone number.';
    } elseif (isStudentIpRateLimited()) {
        $error = $en ? 'Too many attempts. Please wait a few minutes.' : 'Maraming subok. Maghintay ng ilang minuto.';
    } else {
        $p = getRecord("SELECT p.parent_id, ps.student_id FROM parents p JOIN parent_students ps ON ps.parent_id = p.parent_id WHERE p.mobile = ? AND p.is_active = 1 LIMIT 1", [$mobile]);
        if ($p) {
            $code = ppIssueCode((int) $p['student_id'], 'reset', $mobile);
            smsSend($mobile, 'EGradePro: Bagong PIN para sa Parent Portal: ' . ppSetupLink($code) . ' Code: ' . $code . '. Hindi kami humihingi ng pera o OTP.',
                    ['kind' => 'manual', 'student_id' => (int) $p['student_id']]);
            logActivity(null, 'parents', 'Parent PIN reset requested', 'Reset link texted to ' . smsMask($mobile));
        } else {
            logActivity(null, 'student', 'Failed login', 'Parent PIN reset for unknown number ' . smsMask($mobile));
        }
        $sent = true;
    }
}
$csrf = generateCSRFToken();
ppHead(ppT('forgot_pin'), true);
ppAuthStart('fa-key', ppT('forgot_pin'), $sent ? '' : (smsReady()
    ? ($en ? 'Enter your mobile number. We will text you a link to choose a new PIN.' : 'Ilagay ang inyong cellphone number. Magte-text kami ng link para gumawa ng bagong PIN.')
    : ($en ? 'We will help you choose a new PIN.' : 'Tutulungan namin kayong gumawa ng bagong PIN.')));
?>
<?php if ($sent): ?>
                <div class="pp-alert t-ok"><i class="fas fa-circle-check me-1"></i><?php echo $en ? 'If this number has a Parent Portal account, we sent a text with a link to choose a new PIN. It may take a few minutes.' : 'Kung may Parent Portal account ang number na ito, nagpadala kami ng text na may link para gumawa ng bagong PIN. Maaaring umabot ng ilang minuto.'; ?></div>
                <a href="login.php" class="btn btn-primary w-100 btn-go d-flex align-items-center justify-content-center"><?php echo ppT('login'); ?></a>
<?php elseif (smsReady()): ?>
                <?php if ($error): ?><div class="pp-alert t-bad"><i class="fas fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <label class="pp-lbl" for="m"><?php echo ppT('mobile'); ?></label>
                    <div class="pp-field mb-4"><i class="fas fa-mobile-screen-button"></i>
                        <input type="tel" id="m" class="form-control" name="mobile" inputmode="numeric" maxlength="13" placeholder="09XXXXXXXXX" required></div>
                    <button class="btn btn-primary w-100 btn-go"><i class="fas fa-paper-plane me-1"></i> <?php echo $en ? 'Text me a link' : 'I-text sa akin ang link'; ?></button>
                </form>
<?php else: ?>
                <div class="pp-alert t-info"><i class="fas fa-circle-info me-1"></i><?php echo $en ? 'Please ask your child\'s adviser or the registrar for a new Parent Access Slip ("Reset PIN"). Bring a valid ID. You will choose a new PIN yourself.' : 'Humingi po ng bagong Parent Access Slip ("Reset PIN") sa adviser ng inyong anak o sa registrar. Magdala ng valid ID. Kayo mismo ang gagawa ng bagong PIN.'; ?></div>
<?php endif; ?>
                <div class="divider"></div>
                <p class="foot mb-0"><a href="login.php"><i class="fas fa-arrow-left me-1"></i><?php echo $en ? 'Back to Sign in' : 'Bumalik sa Mag-login'; ?></a></p>
<?php ppAuthEnd();
