<?php
// parent/updates.php -- class announcements of the child's classes (not the child's private messages).

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/parent_portal.php';
require_once __DIR__ . '/../includes/student_portal_data.php';
require_once __DIR__ . '/_layout.php';

[$parent, $kids, $sel] = ppRequireParent();
ppHead(ppT('updates'));
ppTop($parent, $kids, $sel);
if (!$sel) { echo '<div class="pp-card pp-empty">' . ppT('no_updates') . '</div>'; ppBottom('updates'); exit; }
$u = spdUpdates((int) $sel['student_id'], spdContext((int) $sel['student_id']), 30);
?>
<?php if (!$u['announcements']): ?><div class="pp-card pp-empty"><?php echo ppT('no_updates'); ?></div><?php endif; ?>
<?php foreach ($u['announcements'] as $p): ?>
    <div class="pp-card">
        <div style="font-size:14.5px;color:var(--pp-muted)"><i class="fas fa-bullhorn me-1"></i><?php echo htmlspecialchars($p['subject']); ?> · <?php echo htmlspecialchars(trim($p['first_name'] . ' ' . $p['last_name'])); ?> · <?php echo date('M j, g:i A', strtotime($p['created_at'])); ?></div>
        <div style="margin-top:8px;font-size:15.5px;line-height:1.55;white-space:pre-line"><?php echo htmlspecialchars(html_entity_decode(strip_tags((string) $p['body']), ENT_QUOTES, 'UTF-8')); ?></div>
        <?php if ($p['attachments']): ?><div style="margin-top:8px;font-size:14.5px;color:var(--pp-muted)"><i class="fas fa-paperclip me-1"></i><?php echo count($p['attachments']); ?> attachment(s) — <?php echo ppLang() === 'en' ? 'open them in the Student Portal' : 'makikita sa Student Portal ng anak'; ?></div><?php endif; ?>
    </div>
<?php endforeach; ?>
<?php ppBottom('updates');
