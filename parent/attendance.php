<?php
// parent/attendance.php -- the child's attendance (QR / teacher records), newest first.

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/parent_portal.php';
require_once __DIR__ . '/../includes/student_portal_data.php';
require_once __DIR__ . '/_layout.php';

[$parent, $kids, $sel] = ppRequireParent();
ppHead(ppT('attendance'));
ppTop($parent, $kids, $sel);
if (!$sel) { echo '<div class="pp-card pp-empty">' . ppT('no_att') . '</div>'; ppBottom('attendance'); exit; }
$ctx = spdContext((int) $sel['student_id']);
$att = spdAttendance((int) $sel['student_id'], $ctx['enrollment']['section_id'] ?? null);
$rows = getRecords(
    "SELECT a.date, a.status, a.time_in, sub.subject_name, a.subject_code
       FROM attendance a LEFT JOIN subjects sub ON sub.subject_code = a.subject_code
      WHERE a.student_id = ? ORDER BY a.date DESC, a.time_in DESC LIMIT 120",
    [(int) $sel['student_id']]
);
$by = [];
foreach ($rows as $r) $by[$r['date']][] = $r;
?>
<div class="pp-stats" style="grid-template-columns:repeat(4,1fr)">
    <div class="pp-stat"><b style="color:#047857"><?php echo $att['Present']; ?></b><span><?php echo ppT('present'); ?></span></div>
    <div class="pp-stat"><b style="color:#B45309"><?php echo $att['Late']; ?></b><span><?php echo ppT('late'); ?></span></div>
    <div class="pp-stat"><b style="color:#B91C1C"><?php echo $att['Absent']; ?></b><span><?php echo ppT('absent'); ?></span></div>
    <div class="pp-stat"><b><?php echo $att['rate'] !== null ? $att['rate'] . '%' : '—'; ?></b><span>Rate</span></div>
</div>
<?php if (!$by): ?><div class="pp-card pp-empty"><?php echo ppT('no_att'); ?></div><?php endif; ?>
<?php foreach ($by as $date => $list): ?>
    <div class="pp-card">
        <h2><?php echo date('l, M j, Y', strtotime($date)); ?></h2>
        <?php foreach ($list as $r):
            $cls = ['Present' => 't-ok', 'Late' => 't-warn', 'Absent' => 't-bad', 'Excused' => 't-mute'][$r['status']] ?? 't-mute'; ?>
            <div class="pp-row"><div><?php echo htmlspecialchars($r['subject_name'] ?: $r['subject_code']); ?><?php if ($r['time_in']): ?><small><?php echo date('g:i A', strtotime($r['time_in'])); ?></small><?php endif; ?></div><span class="pp-tag <?php echo $cls; ?>"><?php echo ppT(strtolower($r['status'])); ?></span></div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php ppBottom('attendance');
