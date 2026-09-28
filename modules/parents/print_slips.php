<?php
// modules/parents/print_slips.php?student_id=.. | ?section_id=..
// Printable Parent Access Slips (4 per A4 page): QR code, short link, 8-character
// setup code and simple Tagalog steps. Every print makes NEW one-time codes
// (only their hashes are stored), valid for 7 days.

session_start();
require_once __DIR__ . '/common.php';

$sid = (int) ($_GET['student_id'] ?? 0);
$secId = (int) ($_GET['section_id'] ?? 0);
$reset = !empty($_GET['reset']);
$ids = [];
if ($sid && ppStaffCanStudent($sid)) $ids = [$sid];
elseif ($secId && ppStaffCanSection($secId)) $ids = array_map('intval', array_column(ppSectionStudents($secId), 'student_id'));
if (!$ids) {
    http_response_code(403);
    exit('Not allowed.');
}
ppEnsureSchema();
$uid = (int) $_SESSION['user_id'];
$pre = $_SESSION['pp_slip_codes'] ?? [];
unset($_SESSION['pp_slip_codes']);
$slips = [];
foreach ($ids as $id) {
    $st = ppStudentCard($id);
    if (!$st) continue;
    $code = $pre[$id] ?? ppIssueCode($id, 'slip', $st['guardian_contact'], $uid);
    $slips[] = ['st' => $st, 'code' => $code, 'link' => ppSetupLink($code)];
}
logActivity($uid, 'parents', 'Printed Parent Access slips', count($slips) . ' slip(s)' . ($secId ? " for section #$secId" : ''));
$portal = appUrl('parent_login.php');
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Parent Access Slips</title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 0; }
        .bar { padding: 12px; background: #14213D; color: #fff; display: flex; gap: 10px; align-items: center; }
        .bar button { padding: 8px 16px; font-weight: 700; border: 0; border-radius: 6px; cursor: pointer; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; padding: 6mm; }
        .slip { border: 1.5px dashed #555; border-radius: 6px; padding: 5mm; page-break-inside: avoid; min-height: 128mm; }
        .slip h3 { margin: 0 0 2mm; font-size: 15pt; }
        .slip .sub { font-size: 9.5pt; color: #444; margin-bottom: 3mm; }
        .who { font-size: 11pt; margin-bottom: 3mm; line-height: 1.35; }
        .qr { width: 36mm; height: 36mm; float: right; margin-left: 3mm; }
        .qr svg { width: 100%; height: 100%; }
        .code { font-size: 20pt; font-weight: 800; letter-spacing: 2px; border: 2px solid #111; border-radius: 6px; display: inline-block; padding: 1mm 3mm; margin: 1mm 0 2mm; }
        .link { font-size: 9pt; word-break: break-all; }
        ol { padding-left: 5mm; margin: 2mm 0; font-size: 10.5pt; line-height: 1.45; }
        .ways { clear: both; font-size: 10pt; margin-top: 2mm; }
        .foot { font-size: 8.5pt; color: #444; margin-top: 2mm; border-top: 1px solid #ccc; padding-top: 2mm; }
        @media print { .bar { display: none; } .grid { padding: 0; } }
    </style>
</head>
<body>
<div class="bar"><button onclick="window.print()">🖨 Print</button><span><?php echo count($slips); ?> slip(s). Each code works once and expires in <?php echo PP_CODE_DAYS; ?> days. Printing again makes new codes.</span></div>
<div class="grid">
<?php foreach ($slips as $s): $st = $s['st']; ?>
    <div class="slip">
        <h3><?php echo $reset ? 'RESET PIN — EGradePro' : 'PARENT ACCESS — EGradePro'; ?></h3>
        <div class="sub">Parent Portal ng Imelda Integrated Secondary School</div>
        <div class="qr" data-link="<?php echo htmlspecialchars($s['link']); ?>"></div>
        <div class="who"><b>Anak:</b> <?php echo htmlspecialchars($st['first_name'] . ' ' . $st['last_name']); ?><br>
            <b>Grade/Section:</b> <?php echo htmlspecialchars(trim($st['grade_level'] . ' - ' . ($st['section_name'] ?? ''))); ?><br>
            <b>Adviser:</b> <?php echo htmlspecialchars($st['adviser_name'] ?: '—'); ?></div>
        <div>Code:</div>
        <div class="code"><?php echo htmlspecialchars(ppFormatCode($s['code'])); ?></div>
        <div class="ways">
            <b>Paraan 1:</b> I-scan ang QR gamit ang Camera, GCash o Messenger.<br>
            <b>Paraan 2:</b> Buksan sa Chrome: <span class="link"><?php echo htmlspecialchars($s['link']); ?></span><br>
            <b>Paraan 3:</b> Magpatulong sa adviser o registrar.
        </div>
        <ol>
            <li>Buksan ang link (o i-type ang code sa Parent Portal).</li>
            <li>Ilagay ang <b>birthday ng anak</b>.</li>
            <li>Ilagay ang <b>inyong cellphone number</b>.</li>
            <li><?php echo $reset ? 'Gumawa ng <b>bagong 6-digit PIN</b>.' : 'Gumawa ng <b>6-digit PIN</b>. Kayo lang ang nakakaalam nito.'; ?></li>
        </ol>
        <div class="foot">Susunod na login: <?php echo htmlspecialchars($portal); ?> — cellphone number + PIN.<br>
            Isang beses lang magagamit · Mag-e-expire: <?php echo date('M j, Y', strtotime('+' . PP_CODE_DAYS . ' days')); ?> · Hindi humihingi ng pera o OTP ang school.</div>
    </div>
<?php endforeach; ?>
</div>
<script src="<?php echo BASE_URL; ?>static/js/vendor/qrcode.min.js"></script>
<script>
document.querySelectorAll('.qr').forEach(function (el) {
    var qr = qrcode(0, 'M'); qr.addData(el.getAttribute('data-link')); qr.make();
    el.innerHTML = qr.createSvgTag({ cellSize: 3, margin: 2 });
});
</script>
</body>
</html>
