<?php
// parent/start.php?ref=OE-...[&share=1]
// From the pop-up on the Enrollment Tracking page (approved application, same
// device that applied): makes a one-time setup code for the guardian number
// on the application, then either opens the setup page right away ("I-setup
// ngayon") or shows the code + link to send to the parent ("I-share").

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/enrollment_helpers_online.php';   // oeSubmittedRef()
require_once __DIR__ . '/../includes/parent_portal.php';
require_once __DIR__ . '/_layout.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
ppEnsureSchema();
$ref = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($_GET['ref'] ?? ''));
$app = ($ref !== '' && oeSubmittedRef() === $ref)
    ? getRecord("SELECT a.student_id, a.status, a.guardian_contact, a.guardian_name, a.guardian_relationship FROM online_applications a WHERE a.reference_no = ? AND a.deleted_at IS NULL", [$ref])
    : null;
$ok = $app && $app['status'] === 'Approved' && !empty($app['student_id'])
      && getRecord("SELECT 1 FROM students WHERE student_id = ? AND is_active = 1", [(int) $app['student_id']]);
if (!$ok) {
    header('Location: setup.php');
    exit;
}
// One code per device visit (kept in the session so a refresh does not make more).
$key = 'pp_start_' . $ref;
if (empty($_SESSION[$key]) || !ppFindCode($_SESSION[$key])) {
    $_SESSION[$key] = ppIssueCode((int) $app['student_id'], 'tracking', $app['guardian_contact']);
}
$code = $_SESSION[$key];
if (empty($_GET['share'])) {
    header('Location: setup.php?c=' . rawurlencode($code));
    exit;
}
$en = ppLang() === 'en';
$link = ppSetupLink($code);
$text = ($en ? 'Parent Portal setup for ' : 'Setup ng Parent Portal para kay ') . '' . "\n" . $link . "\nCode: " . $code;
ppHead('Share');
ppTop();
?>
<div class="pp-card">
    <h2><i class="fas fa-share-nodes me-2"></i><?php echo $en ? 'Send this to the parent' : 'Ipadala ito sa magulang'; ?></h2>
    <p style="color:var(--pp-muted)"><?php echo $en ? 'They open the link (or type the code at the Parent Portal), enter the child\'s birthday, and create a PIN.' : 'Bubuksan nila ang link (o ita-type ang code sa Parent Portal), ilalagay ang birthday ng anak, at gagawa ng PIN.'; ?></p>
    <div style="background:#F1F5F9;border-radius:12px;padding:14px;text-align:center">
        <div style="font-size:14.5px;color:var(--pp-muted)">Code</div>
        <div style="font-size:28px;font-weight:800;letter-spacing:4px"><?php echo htmlspecialchars(ppFormatCode($code)); ?></div>
        <div style="font-size:14.5px;word-break:break-all;margin-top:6px"><?php echo htmlspecialchars($link); ?></div>
    </div>
    <div class="d-grid gap-2 mt-3">
        <button type="button" class="btn btn-primary" style="height:50px;font-weight:800" id="shareBtn"><i class="fas fa-share me-1"></i> <?php echo $en ? 'Share (Messenger, SMS...)' : 'I-share (Messenger, SMS...)'; ?></button>
        <button type="button" class="btn btn-outline-primary" id="copyBtn"><i class="fas fa-copy me-1"></i> <?php echo $en ? 'Copy link and code' : 'Kopyahin ang link at code'; ?></button>
        <a href="../enrollment_status.php?ref=<?php echo urlencode($ref); ?>" class="btn btn-link"><?php echo $en ? 'Back to Enrollment Tracking' : 'Bumalik sa Enrollment Tracking'; ?></a>
    </div>
    <p class="pp-note"><?php echo $en ? 'Works once, expires in ' . PP_CODE_DAYS . ' days.' : 'Isang beses lang magagamit, mag-e-expire sa loob ng ' . PP_CODE_DAYS . ' araw.'; ?></p>
</div>
</main>
<script>
(function () {
    var msg = <?php echo json_encode('EGradePro Parent Portal: ' . $link . ' Code: ' . $code); ?>;
    document.getElementById('shareBtn').addEventListener('click', function () {
        if (navigator.share) navigator.share({ title: 'EGradePro Parent Portal', text: msg }).catch(function () {});
        else { navigator.clipboard && navigator.clipboard.writeText(msg); this.textContent = <?php echo json_encode($en ? 'Copied!' : 'Nakopya na!'); ?>; }
    });
    document.getElementById('copyBtn').addEventListener('click', function () {
        var b = this;
        (navigator.clipboard ? navigator.clipboard.writeText(msg) : Promise.reject()).then(function () { b.textContent = <?php echo json_encode($en ? 'Copied!' : 'Nakopya na!'); ?>; }).catch(function () { prompt('Copy:', msg); });
    });
})();
</script>
</body>
</html>
