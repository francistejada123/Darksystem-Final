<?php
// parent/setup.php?c=CODE
// Parent Portal setup: setup code -> child's birthday -> the parent's mobile
// number (pre-filled from the enrollment when known) -> a 6-digit PIN.
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

$code = ppNormCode($_GET['c'] ?? $_POST['code'] ?? '');
$c = $code !== '' ? ppFindCode($code) : null;
$en = ppLang() === 'en';
$error = '';
$done = null;
$v = ['mobile' => $c['mobile_hint'] ?? '', 'full_name' => '', 'relationship' => '', 'email' => ''];

if ($c && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['enter_code'])) {
    foreach ($v as $k => $_) if (isset($_POST[$k])) $v[$k] = trim((string) $_POST[$k]);
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = $en ? 'Your session expired. Please try again.' : 'Nag-expire ang session. Pakisubukan ulit.';
    } elseif (isStudentIpRateLimited()) {
        $error = $en ? 'Too many attempts. Please wait a few minutes.' : 'Maraming subok. Maghintay ng ilang minuto.';
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
            ppStartSession($p);
            header('Location: index.php?welcome=reset');
            exit;
        }
    } else {
        $r = ppRegister([
            'code' => $code, 'birth_date' => $_POST['birth_date'] ?? '', 'mobile' => $v['mobile'], 'pin' => $_POST['pin'] ?? '',
            'confirm_pin' => $_POST['confirm_pin'] ?? '', 'full_name' => $v['full_name'], 'relationship' => $v['relationship'], 'email' => $v['email'],
        ]);
        if ($r['success']) {
            ppStartSession($r['parent']);
            header('Location: index.php?welcome=' . ($r['existing'] ? 'added' : 'new'));
            exit;
        }
        $error = $r['error'];
        if (!empty($r['existing'])) $v['existing'] = 1;
    }
}
$existing = $c && ($c['channel'] !== 'reset') && smsNormalizeNumber($v['mobile']) !== ''
    && getRecord("SELECT 1 FROM parents WHERE mobile = ?", [smsNormalizeNumber($v['mobile'])]);
$csrf = generateCSRFToken();

ppHead($en ? 'Set up' : 'Setup');
ppTop();
?>
<style>
    .su label { font-weight: 700; font-size: 15px; margin-bottom: 5px; }
    .su .form-control, .su .form-select { height: 52px; font-size: 17px; }
    .su .pin { letter-spacing: 8px; font-size: 22px; text-align: center; font-weight: 700; }
    .su .step { display: flex; gap: 10px; align-items: center; font-weight: 800; font-size: 15px; color: var(--pp-blue); margin: 18px 0 8px; }
    .su .step span { width: 26px; height: 26px; border-radius: 50%; background: var(--pp-blue); color: #fff; display: grid; place-items: center; font-size: 13px; }
    .btn-go { height: 54px; font-size: 17px; font-weight: 800; }
</style>
<div class="pp-card su">
<?php if (!$c): ?>
    <h2><i class="fas fa-key me-2"></i><?php echo $en ? 'Enter your setup code' : 'Ilagay ang setup code'; ?></h2>
    <?php if ($code !== ''): ?><div class="pp-alert t-bad"><?php echo $en ? 'This code is invalid, already used, or expired. Ask the adviser or registrar for a new one.' : 'Hindi valid, nagamit na, o expired ang code na ito. Humingi ng bago sa adviser o registrar.'; ?></div><?php endif; ?>
    <p style="color:var(--pp-muted)"><?php echo $en ? 'The 8-character code is on the text message or the Parent Access Slip from the school.' : 'Ang 8-character na code ay nasa text message o sa Parent Access Slip mula sa school.'; ?></p>
    <form method="GET">
        <input class="form-control mb-3" name="c" maxlength="9" autocapitalize="characters" autocomplete="off" placeholder="K7P4-Q2XZ" style="text-transform:uppercase;letter-spacing:3px;text-align:center;font-weight:700;" required>
        <button class="btn btn-primary w-100 btn-go"><?php echo $en ? 'Continue' : 'Tuloy'; ?> <i class="fas fa-arrow-right ms-1"></i></button>
    </form>
    <p class="pp-note mt-3"><a href="login.php"><?php echo $en ? 'I already have an account' : 'May account na ako'; ?></a></p>
<?php else: ?>
    <h2><i class="fas fa-user-shield me-2"></i><?php echo $c['channel'] === 'reset' ? ($en ? 'Choose a new PIN' : 'Gumawa ng bagong PIN') : ($en ? 'Parent Portal setup' : 'Setup ng Parent Portal'); ?></h2>
    <p style="color:var(--pp-muted);margin:0"><?php echo $en ? 'For the child:' : 'Para sa anak:'; ?> <strong style="color:var(--pp-ink)"><?php echo htmlspecialchars($c['first_name'] . ' ' . mb_substr($c['last_name'], 0, 1) . '.'); ?></strong> · <?php echo htmlspecialchars($c['grade_level']); ?></p>
    <?php if ($error): ?><div class="pp-alert t-bad mt-3"><i class="fas fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="code" value="<?php echo htmlspecialchars($code); ?>">
        <div class="step"><span>1</span><?php echo $en ? "Your child's birthday" : 'Birthday ng inyong anak'; ?></div>
        <input type="date" class="form-control" name="birth_date" required max="<?php echo date('Y-m-d'); ?>">
        <div class="step"><span>2</span><?php echo $en ? 'Your mobile number' : 'Inyong cellphone number'; ?></div>
        <input type="tel" class="form-control" name="mobile" inputmode="numeric" maxlength="13" placeholder="09XXXXXXXXX" value="<?php echo htmlspecialchars($v['mobile']); ?>" required>
        <?php if ($c['channel'] !== 'reset' && !$existing && empty($v['existing'])): ?>
            <div class="row g-2 mt-1">
                <div class="col-7"><input class="form-control" name="full_name" maxlength="150" placeholder="<?php echo $en ? 'Your name (optional)' : 'Inyong pangalan (optional)'; ?>" value="<?php echo htmlspecialchars($v['full_name']); ?>" style="height:46px;font-size:15px"></div>
                <div class="col-5"><select class="form-select" name="relationship" style="height:46px;font-size:15px">
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
        <input type="password" class="form-control pin" name="pin" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="new-password" placeholder="••••••">
        <?php if ($c['channel'] === 'reset' || (!$existing && empty($v['existing']))): ?>
            <input type="password" class="form-control pin mt-2" name="confirm_pin" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="new-password" placeholder="<?php echo $en ? 'Repeat PIN' : 'Ulitin ang PIN'; ?>" style="letter-spacing:normal;font-size:16px;">
            <p class="pp-note" style="text-align:left"><?php echo $en ? 'Only you know this PIN. The school cannot see it. Avoid 123456 or 111111.' : 'Kayo lang ang nakakaalam ng PIN. Hindi ito nakikita ng school. Iwasan ang 123456 o 111111.'; ?></p>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary w-100 btn-go mt-3"><i class="fas fa-check me-1"></i> <?php echo $en ? 'Finish' : 'Tapusin'; ?></button>
    </form>
<?php endif; ?>
</div>
<p class="pp-note"><i class="fas fa-shield-halved me-1"></i><?php echo $en ? 'The school never asks for money or an OTP.' : 'Hindi humihingi ng pera o OTP ang school.'; ?></p>
</main>
<script>
document.querySelectorAll('.pin, input[name=mobile]').forEach(function (i) { i.addEventListener('input', function () { this.value = this.value.replace(/[^\d+]/g, ''); }); });
</script>
<?php ppInstallScript(); ?>
</body>
</html>
