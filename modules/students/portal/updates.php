<?php
// modules/students/portal/updates.php
// Student Portal - Updates: the class announcements of all the student's
// classes and the messages teachers sent (each opens its class page).

session_start();
require_once '../../../config/database.php';
require_once '../../../includes/classroom_helpers.php'; // classroomMimeIcon()
require_once '../../../includes/student_portal_data.php';
require_once '../../../includes/student_portal_page.php';

list($student_id, $student, $profile_image) = spRequireStudent();
$ctx = spdContext($student_id);
$u = spdUpdates($student_id, $ctx);
$tab = ($_GET['tab'] ?? '') === 'messages' ? 'messages' : 'announcements';

spPageStart('Updates', count($u['announcements']) . ' announcement(s) · ' . $u['unread'] . ' unread message(s)', $student, $profile_image);
?>
            <div class="sp-tabs" role="tablist">
                <a href="updates.php" class="<?php echo $tab === 'announcements' ? 'active' : ''; ?>">Announcements <span><?php echo count($u['announcements']); ?></span></a>
                <a href="updates.php?tab=messages" class="<?php echo $tab === 'messages' ? 'active' : ''; ?>">Messages <span><?php echo count($u['messages']); ?></span></a>
            </div>

            <?php if ($tab === 'announcements'): ?>
                <?php if (!$u['announcements']): ?>
                    <div class="card-portal"><div class="empty-state"><i class="fas fa-bullhorn"></i><h5>No announcements yet</h5></div></div>
                <?php endif; ?>
                <div class="sp-list">
                    <?php foreach ($u['announcements'] as $p): ?>
                        <div class="sp-card">
                            <div class="sp-card-head">
                                <span class="sp-item-icon"><i class="fas fa-bullhorn"></i></span>
                                <span><strong><?php echo htmlspecialchars($p['subject']); ?></strong>
                                    <small><?php echo htmlspecialchars(trim($p['first_name'] . ' ' . $p['last_name'])); ?> · <?php echo date('M j, g:i A', strtotime($p['created_at'])); ?></small></span>
                            </div>
                            <?php if (trim((string) $p['body']) !== ''): ?><div class="sp-card-body"><?php echo nl2br(htmlspecialchars($p['body'])); ?></div><?php endif; ?>
                            <?php foreach ($p['attachments'] as $a): ?>
                                <a class="sp-file" href="classwork_file.php?type=stream&id=<?php echo (int) $a['attachment_id']; ?>" target="_blank" rel="noopener">
                                    <i class="fas <?php echo $a['attachment_type'] === 'link' ? 'fa-link' : classroomMimeIcon($a['mime_type'], $a['original_filename']); ?>"></i>
                                    <?php echo htmlspecialchars($a['attachment_type'] === 'link' ? mb_strimwidth((string) $a['external_url'], 0, 48, '…') : $a['original_filename']); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?php if (!$u['messages']): ?>
                    <div class="card-portal"><div class="empty-state"><i class="fas fa-envelope-open"></i><h5>No messages yet</h5></div></div>
                <?php endif; ?>
                <div class="sp-list">
                    <?php foreach ($u['messages'] as $m): ?>
                        <a class="sp-item<?php echo !(int) $m['is_seen'] ? ' unread' : ''; ?>" href="subject.php?subject=<?php echo urlencode($m['subject_code']); ?>">
                            <span class="sp-item-icon"><i class="fas fa-envelope"></i></span>
                            <span class="sp-item-main">
                                <strong><?php echo htmlspecialchars($m['subject']); ?><?php if (!(int) $m['is_seen']): ?> <span class="sp-dot"></span><?php endif; ?></strong>
                                <small><?php echo htmlspecialchars(mb_strimwidth(preg_replace('/\s+/', ' ', (string) $m['final_message']), 0, 120, '…')); ?></small>
                                <small><?php echo htmlspecialchars(trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''))); ?> · <?php echo $m['sent_at'] ? date('M j, g:i A', strtotime($m['sent_at'])) : ''; ?></small>
                            </span>
                            <i class="fas fa-chevron-right sp-go"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
<?php spPageEnd();
