<?php
// parent/index.php -- Parent Portal home: the chosen child's summary.

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/parent_portal.php';
require_once __DIR__ . '/../includes/student_portal_data.php';
require_once __DIR__ . '/../includes/assessment_material_helpers.php';   // aim_getStudentTasks()
require_once __DIR__ . '/_layout.php';

[$parent, $kids, $sel] = ppRequireParent();
$en = ppLang() === 'en';
ppHead(ppT('home'));
ppTop($parent, $kids, $sel);

if (($_GET['welcome'] ?? '') !== ''): ?>
    <div class="pp-alert t-ok"><i class="fas fa-circle-check me-1"></i><?php
        echo $_GET['welcome'] === 'reset' ? ($en ? 'Your new PIN is set.' : 'Nai-set na ang bagong PIN.')
           : ($_GET['welcome'] === 'added' ? ($en ? 'Your child was added to your account.' : 'Naidagdag na ang anak sa inyong account.')
           : ($en ? 'Welcome! Your Parent Portal is ready. Next time, sign in with your mobile number and PIN.' : 'Maligayang pagdating! Handa na ang inyong Parent Portal. Sa susunod, mag-login gamit ang cellphone number at PIN.')); ?></div>
    <button type="button" class="btn btn-primary pp-install mb-3" style="height:50px;font-weight:800"><i class="fas fa-mobile-screen me-1"></i> <?php echo ppT('add_home'); ?></button>
    <p class="pp-note pp-ios-tip mb-3"><?php echo $en ? 'iPhone: tap Share, then "Add to Home Screen".' : 'iPhone: pindutin ang Share, tapos "Add to Home Screen".'; ?></p>
<?php endif;

if (!$sel): ?>
    <div class="pp-card pp-empty"><?php echo $en ? 'No child is linked to this account yet.' : 'Wala pang anak na naka-link sa account na ito.'; ?><br><a href="setup.php" class="btn btn-primary mt-3"><?php echo ppT('add_child'); ?></a></div>
<?php ppBottom('home'); exit; endif;

$ctx = spdContext((int) $sel['student_id']);
$g = ppGrades((int) $sel['student_id']);
$att = spdAttendance((int) $sel['student_id'], $ctx['enrollment']['section_id'] ?? null);
$tasks = spdTasks((int) $sel['student_id'], $ctx);
$pending = count(array_filter($tasks, function ($t) { return in_array($t['state'], ['To do', 'Active'], true); }));
$missing = count(array_filter($tasks, function ($t) { return $t['state'] === 'Missing'; }));
$recent = array_slice(getRecords("SELECT date, status, subject_code FROM attendance WHERE student_id = ? ORDER BY date DESC, attendance_id DESC LIMIT 3", [(int) $sel['student_id']]), 0, 3);
?>
<?php if ($g['low_subjects'] > 0): ?>
    <div class="pp-alert t-bad"><i class="fas fa-triangle-exclamation me-1"></i><?php echo sprintf(ppT('at_risk'), $g['low_subjects']); ?></div>
<?php endif; ?>
<?php if ($missing > 0): ?>
    <div class="pp-alert t-warn"><i class="fas fa-clipboard-list me-1"></i><?php echo $en ? "$missing missing task(s). Please remind your child." : "May $missing gawain na hindi naipasa. Paalalahanan po ang anak."; ?></div>
<?php endif; ?>
<div class="pp-stats">
    <a class="pp-stat" href="grades.php"><span class="pp-ic"><i class="fas fa-chart-line"></i></span><b><?php echo $g['average'] > 0 ? number_format($g['average'], 1) : '—'; ?></b><span><?php echo ppT('gen_ave'); ?></span></a>
    <a class="pp-stat" href="attendance.php"><span class="pp-ic"><i class="fas fa-calendar-check"></i></span><b><?php echo $att['rate'] !== null ? $att['rate'] . '%' : '—'; ?></b><span><?php echo ppT('att_rate'); ?></span></a>
    <a class="pp-stat" href="tasks.php"><span class="pp-ic"><i class="fas fa-clipboard-list"></i></span><b><?php echo $pending; ?></b><span><?php echo ppT('pending'); ?></span></a>
</div>
<div class="pp-card">
    <h2><i class="fas fa-calendar-check me-2"></i><?php echo $en ? 'Latest attendance' : 'Pinakabagong attendance'; ?></h2>
    <?php if (!$recent): ?><div class="pp-empty"><?php echo ppT('no_att'); ?></div><?php endif; ?>
    <?php foreach ($recent as $r):
        $cls = ['Present' => 't-ok', 'Late' => 't-warn', 'Absent' => 't-bad', 'Excused' => 't-mute'][$r['status']] ?? 't-mute'; ?>
        <div class="pp-row"><div><?php echo date('D, M j', strtotime($r['date'])); ?><small><?php echo htmlspecialchars($r['subject_code']); ?></small></div><span class="pp-tag <?php echo $cls; ?>"><?php echo ppT(strtolower($r['status'])); ?></span></div>
    <?php endforeach; ?>
</div>
<div class="pp-card">
    <h2><i class="fas fa-clipboard-list me-2"></i><?php echo $en ? 'Coming up' : 'Mga susunod na gawain'; ?></h2>
    <?php $up = spdUpcoming($tasks, 4); if (!$up): ?><div class="pp-empty"><?php echo ppT('no_tasks'); ?></div><?php endif; ?>
    <?php foreach ($up as $t): ?>
        <div class="pp-row"><div><?php echo htmlspecialchars($t['title']); ?><small><?php echo htmlspecialchars($t['subject']); ?> · <?php echo $t['due'] ? ppT('due') . ' ' . date('M j, g:i A', strtotime($t['due'])) : '—'; ?></small></div><span class="pp-tag t-info"><?php echo htmlspecialchars($t['state']); ?></span></div>
    <?php endforeach; ?>
</div>
<div class="pp-card">
    <a href="setup.php" class="btn btn-outline-primary w-100"><i class="fas fa-user-plus me-1"></i> <?php echo ppT('add_child'); ?></a>
</div>
<?php ppBottom('home');
