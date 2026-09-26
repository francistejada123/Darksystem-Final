<?php
// modules/students/portal/tasks.php
// Student Portal - Tasks & Activities: all classwork and assessment tasks of
// every class in one list (To do / Done / Missing), each opening its class.

session_start();
require_once '../../../config/database.php';
require_once '../../../includes/assessment_material_helpers.php'; // aim_getStudentTasks()
require_once '../../../includes/student_portal_data.php';
require_once '../../../includes/student_portal_page.php';

list($student_id, $student, $profile_image) = spRequireStudent();
$ctx = spdContext($student_id);
$tasks = spdTasks($student_id, $ctx);

$groups = ['todo' => ['To do', 'Active', 'Upcoming'], 'done' => ['Turned in', 'Graded', 'Completed'], 'missing' => ['Missing']];
$count = function ($g) use ($tasks, $groups) { return count(array_filter($tasks, function ($t) use ($g, $groups) { return in_array($t['state'], $groups[$g], true); })); };
$stateClass = ['To do' => 'warn', 'Active' => 'warn', 'Upcoming' => 'info', 'Turned in' => 'ok', 'Graded' => 'ok', 'Completed' => 'ok', 'Missing' => 'bad'];

spPageStart('Tasks & Activities', $count('todo') . ' to do · ' . $count('missing') . ' missing', $student, $profile_image);
?>
            <div class="sp-tabs" role="tablist">
                <button type="button" class="active" data-filter="todo">To do <span><?php echo $count('todo'); ?></span></button>
                <button type="button" data-filter="done">Done <span><?php echo $count('done'); ?></span></button>
                <button type="button" data-filter="missing">Missing <span><?php echo $count('missing'); ?></span></button>
                <button type="button" data-filter="all">All <span><?php echo count($tasks); ?></span></button>
            </div>
            <?php if (!$tasks): ?>
                <div class="card-portal"><div class="empty-state"><i class="fas fa-clipboard-check"></i><h5>No tasks yet</h5><p class="mb-0">Activities your teachers post show up here.</p></div></div>
            <?php else: ?>
                <div class="sp-list" id="spTaskList">
                    <?php foreach ($tasks as $t):
                        $g = 'all';
                        foreach ($groups as $k => $states) if (in_array($t['state'], $states, true)) $g = $k; ?>
                        <a class="sp-item" href="<?php echo htmlspecialchars($t['link']); ?>" data-group="<?php echo $g; ?>">
                            <span class="sp-item-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="sp-item-main">
                                <strong><?php echo htmlspecialchars($t['title']); ?></strong>
                                <small><?php echo htmlspecialchars($t['subject']); ?> · <?php echo htmlspecialchars($t['kind']); ?></small>
                                <small><i class="far fa-clock me-1"></i><?php echo $t['due'] ? 'Due ' . htmlspecialchars(date('M j, g:i A', strtotime($t['due']))) : 'No due date'; ?></small>
                            </span>
                            <span class="sp-tag <?php echo $stateClass[$t['state']] ?? ''; ?>"><?php echo htmlspecialchars($t['state'] === 'Graded' && $t['score'] ? 'Graded ' . $t['score'] : $t['state']); ?></span>
                        </a>
                    <?php endforeach; ?>
                    <div class="card-portal sp-none" hidden><div class="empty-state"><i class="fas fa-check-double"></i><h5>Nothing here</h5></div></div>
                </div>
            <?php endif; ?>
            <script>
            (function () {
                var tabs = document.querySelectorAll('.sp-tabs button');
                function show(f) {
                    var any = false;
                    document.querySelectorAll('#spTaskList .sp-item').forEach(function (el) {
                        var on = f === 'all' || el.getAttribute('data-group') === f;
                        el.hidden = !on; if (on) any = true;
                    });
                    var none = document.querySelector('#spTaskList .sp-none'); if (none) none.hidden = any;
                    tabs.forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-filter') === f); });
                }
                tabs.forEach(function (b) { b.addEventListener('click', function () { show(b.getAttribute('data-filter')); }); });
                show('todo');
            })();
            </script>
<?php spPageEnd();
