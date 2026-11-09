<?php
// parent/create_password.php?ref=OE-...
//
// Step 2 of the Parent Portal activation flow (the step shown on the Enrollment
// Tracking page BELOW the approved enrollment / guardian information).
//
//   Pending / Under Review            -> this page is not reachable
//   Approved, guardian NOT registered -> not reachable (guardian registers first)
//   Approved, guardian registered     -> THIS PAGE: create the password
//   Password already created          -> "Parent Portal Activated" + Login
//
// There is no real email verification anywhere in this flow: once the Admin
// approves the enrollment the address counts as verified automatically, so no
// link, no OTP and no emailed token is needed. The existing account is reused --
// the password is written onto the SAME parents row the guardian registration
// created (parents.pin_hash), so no duplicate parent account can appear.

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/enrollment_helpers_online.php';   // oeSubmittedRef()
require_once __DIR__ . '/../includes/parent_portal.php';
require_once __DIR__ . '/_layout.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
ppEnsureSchema();
ppEmailEnsureSchema();

$ref = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($_GET['ref'] ?? $_POST['ref'] ?? ''));
$self = 'create_password.php' . ($ref !== '' ? '?ref=' . rawurlencode($ref) : '');
$en = ppLang() === 'en';
$error = '';
$info = '';

// The application this page belongs to. The reference number came from the URL,
// so it is only trusted when it is the application submitted on this browser.
$app = null;
if ($ref !== '' && oeSubmittedRef() === $ref) {
    $app = getRecord("SELECT * FROM online_applications WHERE reference_no = ? AND deleted_at IS NULL", [$ref]);
}
$state = $app ? ppActivationState($app) : null;
$parent = $state['parent'] ?? null;
$already = $parent && ppIsActivated($parent);

// Anything out of order goes back to the tracking page, which shows the right step.
$to_tracking = '../enrollment_status.php' . ($ref !== '' ? '?ref=' . urlencode($ref) : '');
if (!$app || !$state || $state['stage'] === PP_ACT_PENDING) {
    header('Location: ' . $to_tracking);
    exit;
}
if ($state['can_guardian']) {   // guardian has not registered yet -- that comes first
    header('Location: parent/start.php?ref=' . rawurlencode($ref));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already) {
    $pw = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $flash = static function ($err, $ok = '') {
        $_SESSION['pp_pw_flash'] = ['error' => $err, 'info' => $ok];
    };

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = $en ? 'Your session expired. Please try again.' : 'Nag-expire ang session. Pakisubukan ulit.';
    } elseif (isStudentIpRateLimited()) {
        $error = $en ? 'Too many attempts. Please wait a few minutes.' : 'Maraming subok. Maghintay ng ilang minuto.';
    } elseif (($pe = ppPinProblem($pw, $confirm)) !== '') {
        $error = $pe;   // the same password rules the PIN already uses
    } else {
        // Write onto the existing parents row: same account, same login (number
        // or email), no new parent row and no change to the student link.
        $set = ppSetPassword((int) $parent['parent_id'], $pw);
        if (!$set['success']) {
            $error = $set['error'];
        } else {
            logActivity(null, 'parents', 'Parent Portal activated', 'Parent #' . (int) $parent['parent_id'] . ' created their password');
            unset($_SESSION['pp_pw_flash']);
            header('Location: ' . $self . '&activated=1', true, 303);
            exit;
        }
    }
    // Back to a clean GET so refresh / Back never re-submits the password.
    $flash($error);
    header('Location: ' . $self, true, 303);
    exit;
}

if (!empty($_SESSION['pp_pw_flash']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $error = $_SESSION['pp_pw_flash']['error'] ?? '';
    $info = $_SESSION['pp_pw_flash']['info'] ?? '';
    unset($_SESSION['pp_pw_flash']);
}
if (isset($_GET['activated'])) { $already = true; }
if ($already) {
    $parent = getRecord("SELECT * FROM parents WHERE parent_id = ?", [(int) $parent['parent_id']]) ?: $parent;
}

$csrf = generateCSRFToken();
$child = getRecord("SELECT first_name, last_name FROM students WHERE student_id = ?", [(int) ppStudentForApplication($app)]);
ppHead($en ? 'Create Password' : 'Gumawa ng Password', true);
if ($already) {
    ppAuthStart('fa-circle-check', $en ? 'Parent Portal Activated' : 'Nak activated ang Parent Portal',
        $en ? 'Your Parent Portal account is active. Sign in with your registered number or email and this password.'
            : 'Aktibo na ang inyong Parent Portal account. Mag-login gamit ang inyong nakarehistrong numero o email at ang password na ito.');
    ?>
    <div style="font-size:15px;line-height:1.6;text-align:left">
        <p class="mb-2"><i class="fas fa-user text-primary me-1"></i>Signed in as:
            <strong><?php echo htmlspecialchars($parent['mobile'] ?? ''); ?></strong>
            <?php if (!empty($parent['email'])): ?> &middot; <?php echo htmlspecialchars($parent['email']); ?><?php endif; ?>
        </p>
        <?php if ($child): ?>
            <p class="text-muted small mb-3"><?php echo $en ? 'You can now see the grades, attendance and activities of ' : 'Maaari mo nang makita ang grades, attendance at mga gawain ng '; ?><strong><?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?></strong>.</p>
        <?php endif; ?>
        <a href="../student_login.php?as=parent" class="btn btn-primary w-100 btn-go"><i class="fas fa-right-to-bracket me-1"></i> <?php echo $en ? 'Go to Parent Portal Login' : 'Mag-login sa Parent Portal'; ?></a>
    </div>
    <?php ob_start(); ?>
    <script>window.addEventListener('pageshow',function(e){if(e.persisted)location.reload();});</script>
    <?php ppAuthEnd('<i class="fas fa-shield-halved me-1"></i>' . ($en ? 'The school never asks for your password.' : 'Hindi humihingi ng inyong password ang school.'), ob_get_clean());
    exit;
}

ppAuthStart('fa-key', $en ? 'Create your password' : 'Gumawa ng password',
    $en ? 'Guardian registration is complete. Choose a password to activate your Parent Portal.'
        : 'Tapos na ang pagpaparehistro. Gumawa ng password para ma-activate ang Parent Portal.');
ppSteps(2, 2);
if ($error) echo '<div class="pp-alert t-bad"><i class="fas fa-circle-exclamation me-1"></i>' . htmlspecialchars($error) . '</div>';
if ($info) echo '<div class="pp-alert t-ok"><i class="fas fa-circle-check me-1"></i>' . htmlspecialchars($info) . '</div>';
if ($child) {
    echo '<div class="pp-note" style="text-align:left"><i class="fas fa-user-graduate me-1"></i>'
        . ($en ? 'Creating the password for ' : 'Ginagawa ang password para sa ') . '<strong>'
        . htmlspecialchars($child['first_name'] . ' ' . mb_substr($child['last_name'], 0, 1) . '.') . '</strong></div>';
}
?>
    <form method="POST" autocomplete="off" action="<?php echo htmlspecialchars($self); ?>" id="ppPwForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="ref" value="<?php echo htmlspecialchars($ref); ?>">
        <label class="pp-lbl" for="pw1"><?php echo $en ? 'New Password' : 'Bagong Password'; ?></label>
        <div class="pp-field"><i class="fas fa-lock"></i>
            <input type="password" id="pw1" class="form-control" name="password" minlength="8" required autocomplete="new-password" placeholder="<?php echo $en ? 'At least 8 characters' : '8 karaktero o higit pa'; ?>"></div>
        <label class="pp-lbl" for="pw2" style="margin-top:12px"><?php echo $en ? 'Confirm Password' : 'Kumpirmahin ang Password'; ?></label>
        <div class="pp-field"><i class="fas fa-lock"></i>
            <input type="password" id="pw2" class="form-control" name="confirm_password" minlength="8" required autocomplete="new-password" placeholder="<?php echo $en ? 'Repeat the password' : 'Ulitin ang password'; ?>"></div>
        <p class="pp-note" style="text-align:left"><i class="fas fa-circle-info me-1"></i><?php echo $en
            ? 'At least 8 characters, using letters and numbers. The school cannot see your password.'
            : '8 karaktero o higit pa, may titik at numero. Hindi ito nakikita ng school.'; ?></p>
        <button type="submit" class="btn btn-primary w-100 btn-go mt-2"><i class="fas fa-check me-1"></i> <?php echo $en ? 'Create Password & Activate' : 'Gumawa ng Password'; ?></button>
    </form>
<?php ob_start(); ?>
<script>
// Same password in both boxes before submitting: never a surprise after the fact.
(function () {
    window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
    var f = document.getElementById('ppPwForm');
    if (!f) return;
    f.addEventListener('submit', function (e) {
        var a = f.elements.password, b = f.elements.confirm_password;
        if (a.value !== b.value) {
            e.preventDefault();
            b.setCustomValidity(<?php echo json_encode($en ? 'The two passwords do not match.' : 'Hindi magkapareho ang dalawang password.'); ?>);
            b.reportValidity();
            b.setCustomValidity('');
        }
    });
})();
</script>
<?php ppAuthEnd(
    '<a href="' . htmlspecialchars($to_tracking) . '"><i class="fas fa-arrow-left me-1"></i>' . ($en ? 'Back to Enrollment Tracking' : 'Bumalik sa Enrollment Tracking') . '</a>',
    ob_get_clean()
);