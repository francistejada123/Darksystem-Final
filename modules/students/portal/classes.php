<?php
// modules/students/portal/classes.php
// Student Portal - My Classes: every class of the student as a full-width card
// (subject, teacher, work to do, new updates) that opens the class page.

session_start();
require_once '../../../config/database.php';
require_once '../../../includes/student_portal_ui.php';   // spSubjectColor(), spInitial()
require_once '../../../includes/assessment_material_helpers.php'; // aim_getStudentTasks() for the to-do count
require_once '../../../includes/student_portal_data.php';
require_once '../../../includes/student_portal_page.php';

list($student_id, $student, $profile_image) = spRequireStudent();
$ctx = spdContext($student_id);

$unread = [];
foreach (getRecords("SELECT subject_code, COUNT(*) AS n FROM student_support_messages WHERE student_id = ? AND status = 'Sent' AND is_seen = 0 GROUP BY subject_code", [$student_id]) as $r) {
    $unread[$r['subject_code']] = (int) $r['n'];
}
$todo = [];
foreach (spdTasks($student_id, $ctx) as $t) {
    if ($t['state'] === 'To do' || $t['state'] === 'Active') $todo[$t['subject_code']] = ($todo[$t['subject_code']] ?? 0) + 1;
}

$sec = $ctx['enrollment'];
spPageStart('My Classes', count($ctx['classes']) . ' class(es)' . ($sec ? ' · ' . ($sec['grade_level'] ?? '') . ' – ' . ($sec['section_name'] ?? '') : ''), $student, $profile_image);
?>
            <?php if (!$ctx['classes']): ?>
                <div class="card-portal"><div class="empty-state"><i class="fas fa-chalkboard"></i><h5>No classes yet</h5><p class="mb-0">Your subjects show up here once you are enrolled in a section.</p></div></div>
            <?php else: ?>
                <div class="sp-list">
                    <?php foreach ($ctx['classes'] as $c): $color = spSubjectColor($c['subject_code']); $n = $unread[$c['subject_code']] ?? 0; $w = $todo[$c['subject_code']] ?? 0; ?>
                        <a class="sp-class" href="subject.php?subject=<?php echo urlencode($c['subject_code']); ?>" style="--card-color: <?php echo htmlspecialchars($color); ?>;">
                            <span class="sp-class-stripe"></span>
                            <span class="sp-class-avatar"><?php echo htmlspecialchars(spInitial($c['subject_name'])); ?></span>
                            <span class="sp-class-main">
                                <strong><?php echo htmlspecialchars($c['subject_name']); ?></strong>
                                <small><i class="fas fa-user-tie me-1"></i>Teacher: <?php echo htmlspecialchars(spdTeacherName($c)); ?></small>
                                <span class="sp-class-tags">
                                    <?php if ($w): ?><span class="sp-tag warn"><i class="fas fa-clipboard-list"></i> <?php echo $w; ?> to do</span><?php endif; ?>
                                    <?php if ($n): ?><span class="sp-tag info"><i class="fas fa-bell"></i> <?php echo $n; ?> new</span><?php endif; ?>
                                    <?php if (!$w && !$n): ?><span class="sp-tag">No new updates</span><?php endif; ?>
                                </span>
                            </span>
                            <i class="fas fa-chevron-right sp-go"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
<?php spPageEnd();
