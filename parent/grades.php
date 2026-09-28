<?php
// parent/grades.php -- the child's grades per term (same source as the Student Portal).

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/parent_portal.php';
require_once __DIR__ . '/_layout.php';

[$parent, $kids, $sel] = ppRequireParent();
ppHead(ppT('grades'));
ppTop($parent, $kids, $sel);
if (!$sel) { echo '<div class="pp-card pp-empty">' . ppT('no_grades') . '</div>'; ppBottom('grades'); exit; }
$g = ppGrades((int) $sel['student_id']);
?>
<div class="pp-stats" style="grid-template-columns:1fr">
    <div class="pp-stat"><b><?php echo $g['average'] > 0 ? number_format($g['average'], 2) : '—'; ?></b><span><?php echo ppT('gen_ave'); ?></span></div>
</div>
<?php if (!$g['by_term']): ?>
    <div class="pp-card pp-empty"><?php echo ppT('no_grades'); ?></div>
<?php endif; ?>
<?php foreach ($g['by_term'] as $term => $rows): ?>
    <div class="pp-card">
        <h2><?php echo htmlspecialchars($term); ?></h2>
        <?php foreach ($rows as $r):
            $fg = (float) $r['final_grade'];
            $cls = $fg <= 0 ? 't-mute' : ($fg >= 75 ? 't-ok' : 't-bad'); ?>
            <div class="pp-row"><div><?php echo htmlspecialchars($r['subject_name']); ?><?php if (!empty($r['remarks'])): ?><small><?php echo htmlspecialchars($r['remarks']); ?></small><?php endif; ?></div>
                <span class="pp-tag <?php echo $cls; ?>" style="font-size:15px"><?php echo $fg > 0 ? number_format($fg, 0) : '—'; ?></span></div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php ppBottom('grades');
