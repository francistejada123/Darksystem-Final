<?php
// parent/setup.php?c=CODE
// Parent Portal setup: setup code -> child's birthday -> the parent's mobile
// number (pre-filled from the enrollment when known) -> email
// -> a 6-digit PIN -> a 6-digit code emailed to that address (the email is a
// sign-in name and the Forgot PIN address, so it is checked before saving).
// If the number already has an account, the parent enters its current PIN
// and the child is added. A "reset" code (from the school, or by SMS to the
// parent's own number) lets a linked parent choose a new PIN.

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/parent_portal.php';
require_once __DIR__ . '/_layout.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
ppEnsureSchema();
ppEmailEnsureSchema();

$code = ppNormCode($_GET['c'] ?? $_POST['code'] ?? '');
$c = $code !== '' ? ppFindCode($code) : null;
$en = ppLang() === 'en';
$error = '';
$done = null;
$v = ['mobile' => $c['mobile_hint'] ?? '', 'full_name' => '', 'relationship' => '', 'email' => ''];
$info = '';

// A new account waiting for its email code (kept 30 minutes; the PIN only as a hash).
$pending = $_SESSION['pp_setup'] ?? null;
if ($pending && (!$c || $pending['code'] !== $code || $pending['until'] < time())) {
    unset($_SESSION['pp_setup']);
    $pending = null;
}
if ($pending && isset($_GET['edit'])) {   // "Change email": back to the form, filled in (except the PIN)
    foreach (['mobile', 'full_name', 'relationship', 'email'] as $k) $v[$k] = $pending[$k];
    unset($_SESSION['pp_setup']);
    $pending = null;
}
if ($pending && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['verify_email', 'resend_email'], true)) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = $en ? 'Your session expired. Please try again.' : 'Nag-expire ang session. Pakisubukan ulit.';
    } elseif (isStudentIpRateLimited()) {
        $error = $en ? 'Too many attempts. Please wait a few minutes.' : 'Maraming subok. Maghintay ng ilang minuto.';
    } elseif ($_POST['action'] === 'resend_email') {
        $r = ppCodeSend('setup', $pending['email'], $pending['email']);
        if ($r['ok']) $info = $en ? 'A new code was sent. The old one no longer works.' : 'Nagpadala ng bagong code. Hindi na gagana ang luma.';
        else $error = sprintf($en ? 'Please wait %d seconds before asking for another code.' : 'Maghintay ng %d segundo bago humingi ng bagong code.', $r['wait']);
    } else {
        $vr = ppCodeVerify('setup', $pending['email'], preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')));
        if ($vr['ok']) {
            $r = ppRegister([
                'code' => $pending['code'], 'birth_date' => $pending['birth_date'], 'mobile' => $pending['mobile'], 'pin_hash' => $pending['pin_hash'],
                'full_name' => $pending['full_name'], 'relationship' => $pending['relationship'], 'email' => $pending['email'], 'email_verified' => 1,
            ]);
            unset($_SESSION['pp_setup']);
            if ($r['success']) {
                ppStartSession($r['parent']);
                header('Location: index.php?welcome=' . ($r['existing'] ? 'added' : 'new'));
                exit;
            }
            $pending = null;
            $error = $r['error'];
        } elseif (!empty($vr['dead'])) {
            $error = $en ? 'This code no longer works (expired or too many wrong tries). Tap "Send a new code".' : 'Hindi na gumagana ang code (expired o maraming maling subok). Pindutin ang "Magpadala ng bagong code".';
        } else {
            $error = sprintf($en ? 'Wrong code. %d tries left.' : 'Mali ang code. %d subok na lang.', $vr['left']);
        }
    }
}

// Step 0 (no code yet): the parent types their number; when it is the guardian
// number on record, the setup code appears right away (Print Slip stays as backup).
$found = null;
$find_mobile = '';
$has_account = false;
$have_code = isset($_GET['have_code']) || $code !== '';
// Signed in ("Add a child"): their own number is used, and only children not linked yet are shown.
$me = (!empty($_SESSION['parent_id']) && ($_SESSION['role'] ?? '') === 'Parent')
    ? getRecord("SELECT parent_id, mobile FROM parents WHERE parent_id = ? AND is_active = 1", [(int) $_SESSION['parent_id']]) : null;
if ($me) $find_mobile = $me['mobile'];
if (!$c && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'find') {
    $find_mobile = $me ? $me['mobile'] : preg_replace('/\D/', '', (string) ($_POST['mobile'] ?? ''));
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = $en ? 'Your session expired. Please try again.' : 'Nag-expire ang session. Pakisubukan ulit.';
    } elseif (isStudentIpRateLimited()) {
        $error = $en ? 'Too many attempts. Please wait a few minutes.' : 'Maraming subok. Maghintay ng ilang minuto.';
    } elseif (ppStrictMobile($find_mobile) === '') {
        $error = ppMobileFormatError();
    } elseif (!$me && getRecord("SELECT 1 FROM parents WHERE mobile = ?", [$find_mobile])) {
        $has_account = true;
    } else {
        $r = ppSelfServeCodes($find_mobile, $me ? (int) $me['parent_id'] : 0);
        if ($r['ok']) {
            $found = $r['kids'];
        } elseif ($r['reason'] === 'limit') {
            $error = $en ? 'Too many codes for this number. Please try again after an hour.' : 'Maraming code na para sa number na ito. Subukan ulit pagkalipas ng isang oras.';
        } else {
            logActivity(null, 'student', 'Failed login', 'Parent setup: number not on any student record (' . smsMask($find_mobile) . ')');
            $error = $me
                ? ($en ? 'No other student has your number as the guardian number on record. Ask the school for a Parent Access Slip for your other child.'
                       : 'Walang ibang estudyanteng may number ninyo bilang guardian number sa record. Humingi ng Parent Access Slip sa school para sa isa pang anak.')
                : ($en ? 'No student has this number as the guardian number on record. Check the number, or ask the school for a Parent Access Slip.'
                       : 'Walang estudyanteng may ganitong guardian number sa record ng school. Tingnan kung tama ang number, o humingi ng Parent Access Slip sa school.');
        }
    }
}

if ($c && !$pending && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['enter_code']) && !in_array($_POST['action'] ?? '', ['verify_email', 'resend_email', 'find'], true)) {
    foreach ($v as $k => $_) if (isset($_POST[$k])) $v[$k] = trim((string) $_POST[$k]);
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = $en ? 'Your session expired. Please try again.' : 'Nag-expire ang session. Pakisubukan ulit.';
    } elseif (isStudentIpRateLimited()) {
        $error = $en ? 'Too many attempts. Please wait a few minutes.' : 'Maraming subok. Maghintay ng ilang minuto.';
    } elseif (ppStrictMobile($v['mobile']) === '') {
        $error = ppMobileFormatError();
    } elseif ($c['channel'] === 'reset') {
        // New PIN for a parent already linked to this child (code sent to their own number / given by the school).
        $mobile = smsNormalizeNumber($v['mobile']);
        $p = $mobile ? getRecord("SELECT p.* FROM parents p JOIN parent_students ps ON ps.parent_id = p.parent_id AND ps.student_id = ? WHERE p.mobile = ?", [(int) $c['student_id'], $mobile]) : null;
        $bd = trim((string) ($_POST['birth_date'] ?? ''));
        if (!$p || $bd === '' || date('Y-m-d', strtotime($bd)) !== date('Y-m-d', strtotime($c['birth_date']))) {
            logActivity(null, 'student', 'Failed login', 'Parent PIN reset did not match (student #' . $c['student_id'] . ')');
            $error = $en ? 'The number or the child\'s birthday does not match.' : 'Hindi tugma ang number o ang birthday ng anak.';
        } elseif (($pe = ppPinProblem($_POST['pin'] ?? '', $_POST['confirm_pin'] ?? '')) !== '') {
            $error = $pe;
        } else {
            executeQuery("UPDATE parents SET pin_hash = ?, failed_logins = 0, locked_until = NULL WHERE parent_id = ?", [password_hash($_POST['pin'], PASSWORD_DEFAULT), (int) $p['parent_id']]);
            executeQuery("UPDATE parent_setup_codes SET used_at = NOW(), used_by_parent = ? WHERE code_id = ?", [(int) $p['parent_id'], (int) $c['code_id']]);
            logActivity(null, 'parents', 'Parent PIN reset', 'Parent ' . smsMask($mobile));
            // Sign in again with the new PIN (no automatic sign-in).
            unset($_SESSION['parent_id'], $_SESSION['pp_child']);
            session_regenerate_id(true);
            $_SESSION['pp_login_prefill'] = $mobile;
            header('Location: login.php?pin_changed=1');
            exit;
        }
    } else {
        // New account: the email is required and checked with a code first.
        $mobileN = smsNormalizeNumber($v['mobile']);
        $isNew = $mobileN !== '' && !getRecord("SELECT 1 FROM parents WHERE mobile = ?", [$mobileN]);
        $email = ppNormEmail($v['email']);
        $bd = trim((string) ($_POST['birth_date'] ?? ''));
        $r = null;
        if ($isNew && $email === '') {
            $error = $en ? 'Enter a valid email (for example name@gmail.com).' : 'Ilagay ang tamang email (halimbawa: pangalan@gmail.com).';
        } elseif ($isNew && ppEmailTaken($email)) {
            $error = $en ? 'This email is already used by another Parent Portal account.' : 'Ginagamit na ng ibang Parent Portal account ang email na ito.';
        } elseif ($isNew && ppMailReady()) {
            if ($bd === '' || strtotime($bd) === false || date('Y-m-d', strtotime($bd)) !== date('Y-m-d', strtotime($c['birth_date']))) {
                logActivity(null, 'student', 'Failed login', 'Parent setup: wrong birthday for the setup code of student #' . $c['student_id']);
                $error = $en ? "The child's birthday does not match our record." : 'Hindi tugma ang birthday ng anak sa record ng school.';
            } elseif (($pe = ppPinProblem($_POST['pin'] ?? '', $_POST['confirm_pin'] ?? '')) !== '') {
                $error = $pe;
            } else {
                $pending = ['code' => $code, 'birth_date' => $bd, 'mobile' => $mobileN, 'full_name' => $v['full_name'], 'relationship' => $v['relationship'],
                            'email' => $email, 'pin_hash' => password_hash($_POST['pin'], PASSWORD_DEFAULT), 'until' => time() + 30 * 60];
                $_SESSION['pp_setup'] = $pending;
                $sr = ppCodeSend('setup', $email, $email);
                if (!$sr['ok']) $info = sprintf($en ? 'A code was sent a moment ago. You can ask for a new one in %d seconds.' : 'May code nang naipadala kanina. Puwedeng humingi ng bago pagkalipas ng %d segundo.', $sr['wait']);
            }
        } else {
            $r = ppRegister([
                'code' => $code, 'birth_date' => $_POST['birth_date'] ?? '', 'mobile' => $v['mobile'], 'pin' => $_POST['pin'] ?? '',
                'confirm_pin' => $_POST['confirm_pin'] ?? '', 'full_name' => $v['full_name'], 'relationship' => $v['relationship'], 'email' => $isNew ? $email : '',
            ]);
        }
        if ($r && $r['success']) {
            ppStartSession($r['parent']);
            header('Location: index.php?welcome=' . ($r['existing'] ? 'added' : 'new'));
            exit;
        }
        if ($r) {
            $error = $r['error'];
            if (!empty($r['existing'])) $v['existing'] = 1;
        }
    }
}
$existing = $c && ($c['channel'] !== 'reset') && smsNormalizeNumber($v['mobile']) !== ''
    && getRecord("SELECT 1 FROM parents WHERE mobile = ?", [smsNormalizeNumber($v['mobile'])]);
$csrf = generateCSRFToken();

ppHead($en ? 'Set up' : 'Setup', true);
if ($pending) {
    // Step: the code emailed to the new address.
    ppAuthStart('fa-envelope-open-text', $en ? 'Confirm your email' : 'Kumpirmahin ang email',
        sprintf($en ? 'We sent a 6-digit code to %s. It works for %d minutes. Check Spam too.' : 'Nagpadala kami ng 6-digit na code sa %s. Gagana ito sa loob ng %d minuto. Tingnan din ang Spam.',
            '<strong style="color:var(--pp-ink)">' . htmlspecialchars($pending['email']) . '</strong>', PP_OTP_MINUTES));
    ppSteps(2, 2);
    if ($error) echo '<div class="pp-alert t-bad"><i class="fas fa-circle-exclamation me-1"></i>' . htmlspecialchars($error) . '</div>';
    if ($info) echo '<div class="pp-alert t-ok"><i class="fas fa-circle-check me-1"></i>' . htmlspecialchars($info) . '</div>';
    ppCodeForm($csrf, 'verify_email', 'resend_email', ppCodeWait($pending['email']), 'setup.php?c=' . rawurlencode($code) . '&edit=1', $en ? 'Change email' : 'Palitan ang email', $en ? 'Confirm and finish' : 'Kumpirmahin at tapusin');
    ppAuthEnd('<i class="fas fa-shield-halved me-1"></i>' . ($en ? 'The school never asks for money, a code or your PIN.' : 'Hindi humihingi ng pera, code o PIN ang school.'));
    exit;
}
if (!$c && $found) {
    ppAuthStart('fa-circle-check', $en ? 'We found your child' : 'Nakita namin ang inyong anak',
        $en ? 'Here is your setup code. Tap Continue to finish your account.' : 'Ito ang inyong setup code. Pindutin ang Tuloy para tapusin ang account.');
} elseif (!$c && $have_code) {
    ppAuthStart('fa-key', $en ? 'Enter your setup code' : 'Ilagay ang setup code',
        $en ? 'The 8-character code is on the Parent Access Slip or the text message from the school.' : 'Ang 8-character na code ay nasa Parent Access Slip o text message mula sa school.');
} elseif (!$c) {
    ppAuthStart('fa-user-plus', $en ? 'Create your account' : 'Gumawa ng account',
        $en ? 'Enter the mobile number you gave the school when your child enrolled.' : 'Ilagay ang cellphone number na ibinigay sa school noong nag-enroll ang inyong anak.');
} else {
    ppAuthStart($c['channel'] === 'reset' ? 'fa-lock' : 'fa-user-plus',
        $c['channel'] === 'reset' ? ($en ? 'Choose a new PIN' : 'Gumawa ng bagong PIN') : ($en ? 'Parent Portal setup' : 'Setup ng Parent Portal'),
        ($en ? 'For the child:' : 'Para sa anak:') . ' <strong style="color:var(--pp-ink)">' . htmlspecialchars($c['first_name'] . ' ' . mb_substr($c['last_name'], 0, 1) . '.') . '</strong> · ' . htmlspecialchars($c['grade_level']));
}
?>
<style>
    .su .form-control, .su .form-select { height: 52px; font-size: 17px; }
    .su .step { display: flex; gap: 10px; align-items: center; font-weight: 800; font-size: 15px; color: var(--pp-ink); margin: 18px 0 8px; }
    .su form > .step:first-of-type { margin-top: 0; }
    .su .step span { width: 28px; height: 28px; border-radius: 50%; background: linear-gradient(135deg, #1D4ED8, #3B82F6); color: #fff; display: grid; place-items: center; font-size: 14.5px; flex: 0 0 auto; }
    .su .code { text-transform: uppercase; letter-spacing: 4px; text-align: center; font-weight: 800; font-size: 20px; height: 56px; }
</style>
<div class="su">
<?php if (!$c && $found): ?>
    <?php foreach ($found as $i => $k): ?>
        <div style="border:1px solid var(--pp-line);border-radius:16px;padding:14px 16px;margin-bottom:12px;background:#F8FAFC">
            <div style="font-weight:800;font-size:16px"><i class="fas fa-user-graduate me-1" style="color:var(--pp-blue)"></i>
                <?php echo htmlspecialchars(mb_substr($k['first_name'], 0, 1) . '. ' . mb_substr($k['last_name'], 0, 1) . '.'); ?> &middot; <?php echo htmlspecialchars($k['grade_level']); ?></div>
            <div style="font-size:14.5px;color:var(--pp-muted);margin-top:6px"><?php echo $en ? 'Your setup code' : 'Ang inyong setup code'; ?></div>
            <div style="font-size:26px;font-weight:800;letter-spacing:4px;margin:2px 0 10px"><?php echo htmlspecialchars(ppFormatCode($k['code'])); ?></div>
            <a href="setup.php?c=<?php echo rawurlencode($k['code']); ?>" class="btn btn-primary w-100 btn-go d-flex align-items-center justify-content-center"><?php echo $en ? 'Continue' : 'Tuloy'; ?> <i class="fas fa-arrow-right ms-2"></i></a>
        </div>
    <?php endforeach; ?>
    <?php if (count($found) > 1): ?><p class="pp-note" style="text-align:left"><?php echo $en ? 'Set up with one child first. After signing in, add the others with "Add a child".' : 'Unahin ang isang anak. Pagka-login, idagdag ang iba gamit ang "Magdagdag ng anak".'; ?></p><?php endif; ?>
    <p class="pp-note" style="text-align:left"><?php echo sprintf($en ? 'The code works once and expires in %d days. Next you confirm your child\'s birthday and your email.' : 'Isang beses lang magagamit ang code at mag-e-expire sa loob ng %d araw. Susunod: kumpirmahin ang birthday ng anak at ang inyong email.', PP_CODE_DAYS); ?></p>
<?php elseif (!$c && !$have_code): ?>
    <?php if ($has_account): ?>
        <div class="pp-alert t-info"><i class="fas fa-circle-info me-1"></i><?php echo $en ? 'This number already has a Parent Portal account.' : 'May Parent Portal account na ang number na ito.'; ?> <a href="login.php"><b><?php echo ppT('login'); ?> →</b></a></div>
    <?php endif; ?>
    <?php if ($error): ?><div class="pp-alert t-bad"><i class="fas fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="find">
        <label class="pp-lbl" for="fm"><?php echo ppT('mobile'); ?></label>
        <div class="pp-field"><i class="fas fa-mobile-screen-button"></i>
            <input type="tel" id="fm" class="form-control" name="mobile" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" placeholder="09XXXXXXXXX" value="<?php echo htmlspecialchars($find_mobile); ?>" required <?php echo $me ? 'readonly' : ''; ?>
                   title="<?php echo htmlspecialchars(ppMobileFormatError()); ?>"></div>
        <p class="pp-note pp-mcount" style="text-align:left;margin:4px 0 16px"><?php echo $en ? '11 digits, starts with 09' : '11 digits, nagsisimula sa 09'; ?></p>
        <button class="btn btn-primary w-100 btn-go"><?php echo $en ? 'Continue' : 'Tuloy'; ?> <i class="fas fa-arrow-right ms-1"></i></button>
    </form>
    <p class="pp-note mt-3" style="text-align:left"><i class="fas fa-ticket me-1"></i><?php echo $en ? 'Have a setup code from the school (Parent Access Slip)?' : 'May setup code mula sa school (Parent Access Slip)?'; ?> <a href="setup.php?have_code=1"><b><?php echo $en ? 'Enter it here' : 'Ilagay dito'; ?></b></a></p>
<?php elseif (!$c): ?>
    <?php if ($code !== ''): ?><div class="pp-alert t-bad"><i class="fas fa-circle-exclamation me-1"></i><?php echo $en ? 'This code is invalid, already used, or expired. Ask the adviser or registrar for a new one.' : 'Hindi valid, nagamit na, o expired ang code na ito. Humingi ng bago sa adviser o registrar.'; ?></div><?php endif; ?>
    <form method="GET">
        <label class="pp-lbl" for="sc">Setup code</label>
        <input class="form-control code mb-4" id="sc" name="c" maxlength="9" autocapitalize="characters" autocomplete="off" placeholder="K7P4-Q2XZ" required>
        <button class="btn btn-primary w-100 btn-go"><?php echo $en ? 'Continue' : 'Tuloy'; ?> <i class="fas fa-arrow-right ms-1"></i></button>
    </form>
    <p class="pp-note mt-3" style="text-align:left"><a href="setup.php"><i class="fas fa-arrow-left me-1"></i><?php echo $en ? 'No code? Use your mobile number instead' : 'Walang code? Gamitin ang cellphone number'; ?></a></p>
<?php else: ?>
    <?php if ($error): ?><div class="pp-alert t-bad"><i class="fas fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="code" value="<?php echo htmlspecialchars($code); ?>">
        <div class="step"><span>1</span><?php echo $en ? "Your child's birthday" : 'Birthday ng inyong anak'; ?></div>
        <div class="pp-field"><i class="fas fa-cake-candles"></i><input type="date" class="form-control" name="birth_date" required max="<?php echo date('Y-m-d'); ?>"></div>
        <div class="step"><span>2</span><?php $new_acc = $c['channel'] !== 'reset' && !$existing && empty($v['existing']); echo $new_acc ? ($en ? 'Your mobile number and email' : 'Inyong cellphone number at email') : ($en ? 'Your mobile number' : 'Inyong cellphone number'); ?></div>
        <div class="pp-field"><i class="fas fa-mobile-screen-button"></i><input type="tel" class="form-control" name="mobile" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" title="<?php echo htmlspecialchars(ppMobileFormatError()); ?>" placeholder="09XXXXXXXXX" value="<?php echo htmlspecialchars($v['mobile']); ?>" required></div>
        <?php if ($c['channel'] !== 'reset' && !$existing && empty($v['existing'])): ?>
            <div class="pp-field mt-2"><i class="fas fa-envelope"></i><input type="email" class="form-control" name="email" maxlength="100" placeholder="<?php echo $en ? 'Email (e.g. name@gmail.com)' : 'Email (hal. pangalan@gmail.com)'; ?>" value="<?php echo htmlspecialchars($v['email']); ?>" required autocapitalize="none" spellcheck="false"></div>
            <p class="pp-note" style="text-align:left;margin:4px 0 0"><?php echo $en ? 'You can sign in with it, and a code is sent here if you forget your PIN. We will send a code to confirm it.' : 'Puwede itong gamitin sa pag-login, at dito ipapadala ang code kapag nakalimutan ang PIN. Magpapadala kami ng code para kumpirmahin ito.'; ?></p>
            <div class="row g-2 mt-1">
                <div class="col-7"><input class="form-control" name="full_name" maxlength="150" placeholder="<?php echo $en ? 'Your name (optional)' : 'Inyong pangalan (optional)'; ?>" value="<?php echo htmlspecialchars($v['full_name']); ?>" style="height:48px;font-size:15px"></div>
                <div class="col-5"><select class="form-select" name="relationship" style="height:48px;font-size:15px">
                    <?php foreach (['' => $en ? 'Relationship' : 'Relasyon', 'Mother' => $en ? 'Mother' : 'Nanay', 'Father' => $en ? 'Father' : 'Tatay', 'Guardian' => 'Guardian', 'Grandparent' => $en ? 'Grandparent' : 'Lolo/Lola', 'Other' => $en ? 'Other' : 'Iba pa'] as $k => $lbl): ?>
                        <option value="<?php echo $k; ?>" <?php echo $v['relationship'] === $k ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                    <?php endforeach; ?>
                </select></div>
            </div>
        <?php endif; ?>
        <div class="step"><span>3</span><?php
            if ($c['channel'] === 'reset') echo $en ? 'New 6-digit PIN' : 'Bagong 6-digit PIN';
            elseif ($existing || !empty($v['existing'])) echo $en ? 'This number already has an account: enter its PIN' : 'May account na ang number na ito: ilagay ang PIN';
            else echo $en ? 'Create a 6-digit PIN' : 'Gumawa ng 6-digit PIN';
        ?></div>
        <?php ppPinField('pin', '••••••', 'new-password'); ?>
        <?php if ($c['channel'] === 'reset' || (!$existing && empty($v['existing']))): ?>
            <div class="mt-2"><?php ppPinField('confirm_pin', $en ? 'Repeat PIN' : 'Ulitin ang PIN', 'new-password'); ?></div>
            <p class="pp-note" style="text-align:left"><?php echo $en ? 'Only you know this PIN. The school cannot see it. Avoid 123456 or 111111.' : 'Kayo lang ang nakakaalam ng PIN. Hindi ito nakikita ng school. Iwasan ang 123456 o 111111.'; ?></p>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary w-100 btn-go mt-3"><i class="fas fa-check me-1"></i> <?php echo $en ? 'Finish' : 'Tapusin'; ?></button>
    </form>
<?php endif; ?>
</div>
<div class="divider"></div>
<p class="foot mb-0"><?php echo $en ? 'Already have an account?' : 'May account na?'; ?> <a href="login.php"><?php echo ppT('login'); ?></a></p>
<?php ppAuthEnd('<i class="fas fa-shield-halved me-1"></i>' . ($en ? 'The school never asks for money or an OTP.' : 'Hindi humihingi ng pera o OTP ang school.'));
