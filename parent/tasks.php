<?php
// parent/tasks.php -- the child's activities and quizzes with their status (view only).

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/parent_portal.php';
require_once __DIR__ . '/../includes/student_portal_data.php';
require_once __DIR__ . '/../includes/assessment_material_helpers.php';   // aim_getStudentTasks()
require_once __DIR__ . '/_layout.php';

[$parent, $kids, $sel] = ppRequireParent();
$en = ppLang() === 'en';
ppHead(ppT('tasks'));
ppTop($parent, $kids, $sel);
if (!$sel) { echo '<div class="pp-card pp-empty">' . ppT('no_tasks') . '</div>'; ppBottom('tasks'); exit; }
$tasks = spdTasks((int) $sel['student_id'], spdContext((int) $sel['student_id']));
$label = function ($s) use ($en) {
    $m = ['To do' => ['Hindi pa tapos', 'To do'], 'Missing' => ['Hindi naipasa', 'Missing'], 'Turned in' => ['Naipasa', 'Turned in'],
          'Graded' => ['May grade na', 'Graded'], 'Upcoming' => ['Paparating', 'Upcoming'], 'Active' => ['Bukas ngayon', 'Open now'], 'Completed' => ['Tapos na', 'Completed']];
    return $m[$s][$en ? 1 : 0] ?? $s;
};
$tone = ['To do' => 't-info', 'Active' => 't-info', 'Upcoming' => 't-mute', 'Missing' => 't-bad', 'Turned in' => 't-ok', 'Graded' => 't-ok', 'Completed' => 't-ok'];
// Missing first, then what is still to do, then the rest.
$order = ['Missing' => 0, 'Active' => 1, 'To do' => 1, 'Upcoming' => 2];
usort($tasks, function ($a, $b) use ($order) { return ($order[$a['state']] ?? 3) <=> ($order[$b['state']] ?? 3); });
?>
<div class="pp-card">
    <h2><i class="fas fa-clipboard-list me-2"></i><?php echo ppT('tasks'); ?></h2>
    <?php if (!$tasks): ?><div class="pp-empty"><?php echo ppT('no_tasks'); ?></div><?php endif; ?>
    <?php foreach ($tasks as $t): ?>
        <div class="pp-row"><div><?php echo htmlspecialchars($t['title']); ?>
            <small><?php echo htmlspecialchars($t['subject']); ?> · <?php echo htmlspecialchars($t['kind']); ?><?php echo $t['due'] ? ' · ' . ppT('due') . ' ' . date('M j, g:i A', strtotime($t['due'])) : ''; ?><?php echo $t['score'] ? ' · ' . htmlspecialchars($t['score']) : ''; ?></small></div>
            <span class="pp-tag <?php echo $tone[$t['state']] ?? 't-mute'; ?>"><?php echo htmlspecialchars($label($t['state'])); ?></span></div>
    <?php endforeach; ?>
</div>
<?php ppBottom('tasks');
