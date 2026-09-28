<?php
// modules/parents/progress_report.php?student_id=.. | ?section_id=..
// Printed Progress Report for parents without a smartphone: grades, attendance
// and missing / pending tasks -- the same data the Parent Portal shows.

session_start();
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/student_portal_data.php';
require_once __DIR__ . '/../../includes/assessment_material_helpers.php';

$sid = (int) ($_GET['student_id'] ?? 0);
$secId = (int) ($_GET['section_id'] ?? 0);
$ids = [];
if ($sid && ppStaffCanStudent($sid)) $ids = [$sid];
elseif ($secId && ppStaffCanSection($secId)) $ids = array_map('intval', array_column(ppSectionStudents($secId), 'student_id'));
if (!$ids) {
    http_response_code(403);
    exit('Not allowed.');
}
$sy = getRecord("SELECT sy_name FROM school_years WHERE is_active = 1");
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Progress Report</title>
    <style>
        @page { size: A4; margin: 14mm; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 11pt; margin: 0; }
        .bar { padding: 12px; background: #14213D; color: #fff; }
        .bar button { padding: 8px 16px; font-weight: 700; border: 0; border-radius: 6px; cursor: pointer; }
        .page { padding: 8mm; page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        h1 { font-size: 16pt; margin: 0; } .sub { color: #444; margin-bottom: 4mm; }
        table { width: 100%; border-collapse: collapse; margin: 2mm 0 5mm; }
        th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
        th { background: #eee; }
        .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 3mm; margin: 2mm 0 5mm; }
        .box { border: 1px solid #999; border-radius: 4px; padding: 3mm; text-align: center; } .box b { display: block; font-size: 16pt; }
        .sign { margin-top: 12mm; display: grid; grid-template-columns: 1fr 1fr; gap: 16mm; }
        .sign div { border-top: 1px solid #111; text-align: center; padding-top: 2px; font-size: 10pt; }
        @media print { .bar { display: none; } .page { padding: 0; } }
    </style>
</head>
<body>
<div class="bar"><button onclick="window.print()">🖨 Print</button> Progress Report — <?php echo count($ids); ?> learner(s)</div>
<?php foreach ($ids as $id):
    $st = ppStudentCard($id);
    if (!$st) continue;
    $ctx = spdContext($id);
    $g = ppGrades($id);
    $att = spdAttendance($id, $ctx['enrollment']['section_id'] ?? null);
    $tasks = spdTasks($id, $ctx);
    $open = array_values(array_filter($tasks, function ($t) { return in_array($t['state'], ['Missing', 'To do', 'Active'], true); }));
?>
    <div class="page">
        <h1>Progress Report — EGradePro</h1>
        <div class="sub">Imelda Integrated Secondary School · SY <?php echo htmlspecialchars($sy['sy_name'] ?? ''); ?> · <?php echo date('F j, Y'); ?></div>
        <table>
            <tr><th style="width:25%">Learner</th><td><?php echo htmlspecialchars($st['last_name'] . ', ' . $st['first_name']); ?> (LRN <?php echo htmlspecialchars($st['lrn']); ?>)</td></tr>
            <tr><th>Grade / Section</th><td><?php echo htmlspecialchars(trim($st['grade_level'] . ' - ' . ($st['section_name'] ?? ''))); ?></td></tr>
            <tr><th>Adviser</th><td><?php echo htmlspecialchars($st['adviser_name'] ?: '—'); ?></td></tr>
            <tr><th>Parent / Guardian</th><td><?php echo htmlspecialchars($st['guardian_name'] ?: '—'); ?></td></tr>
        </table>
        <div class="grid">
            <div class="box"><b><?php echo $g['average'] > 0 ? number_format($g['average'], 2) : '—'; ?></b>General Average</div>
            <div class="box"><b><?php echo $att['rate'] !== null ? $att['rate'] . '%' : '—'; ?></b>Attendance</div>
            <div class="box"><b><?php echo $att['Absent']; ?></b>Absences</div>
            <div class="box"><b><?php echo count(array_filter($tasks, function ($t) { return $t['state'] === 'Missing'; })); ?></b>Missing tasks</div>
        </div>
        <strong>Grades</strong>
        <?php if (!$g['by_term']): ?><p>No grades released yet.</p><?php endif; ?>
        <?php foreach ($g['by_term'] as $term => $rows): ?>
            <table><tr><th><?php echo htmlspecialchars($term); ?></th><th style="width:20%">Grade</th><th style="width:25%">Remarks</th></tr>
                <?php foreach ($rows as $r): ?><tr><td><?php echo htmlspecialchars($r['subject_name']); ?></td><td><?php echo (float) $r['final_grade'] > 0 ? number_format((float) $r['final_grade'], 0) : '—'; ?></td><td><?php echo htmlspecialchars($r['remarks'] ?? ''); ?></td></tr><?php endforeach; ?>
            </table>
        <?php endforeach; ?>
        <strong>Tasks to finish</strong>
        <?php if (!$open): ?><p>None right now.</p><?php else: ?>
            <table><tr><th>Task</th><th>Subject</th><th>Due</th><th>Status</th></tr>
                <?php foreach (array_slice($open, 0, 15) as $t): ?><tr><td><?php echo htmlspecialchars($t['title']); ?></td><td><?php echo htmlspecialchars($t['subject']); ?></td><td><?php echo $t['due'] ? date('M j', strtotime($t['due'])) : '—'; ?></td><td><?php echo htmlspecialchars($t['state']); ?></td></tr><?php endforeach; ?>
            </table>
        <?php endif; ?>
        <div class="sign"><div>Adviser</div><div>Parent / Guardian signature &amp; date</div></div>
    </div>
<?php endforeach; ?>
</body>
</html>
