<?php
// modules/ai/resources.php
// Teacher-facing review page for student answers to AI-generated resources
// (see includes/ai_resource_submission_helpers.php and
// database/migrations/2026_ai_resource_submissions.sql). This is the other
// half of the native in-app "Answer Now" / "Submit Your Answer" feature on
// modules/students/portal/messages.php:
//   - Multiple-choice/true-false answers are already auto-graded the
//     instant the student submits them -- this page just lets the teacher
//     see the score and, if they want, the per-question breakdown.
//   - Free-response answers (worksheets, essays, discussion activities)
//     have no single correct answer, so they only ever show up here as
//     "Waiting" until the teacher actually reads them and clicks
//     Mark Reviewed (optionally with feedback) -- nothing in this app ever
//     invents a score for that kind of work.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ai_resource_submission_helpers.php';

if (!hasRole('Teacher')) {
    header('Location: ../../index.php');
    exit;
}

$teacher_id = (int) $_SESSION['user_id'];
aiResourceEnsureSchema(); // adds the retake_allowed column on a database that does not have it yet

// ---- Allow ONE more attempt on an auto-graded quiz. A graded quiz is one
// attempt only; this is the teacher's explicit permission for a retake. ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['allow_retake'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $post_error = 'Invalid security token. Please try again.';
    } else {
        $retake = aiResourceAllowRetake((int) ($_POST['submission_id'] ?? 0), $teacher_id);
        header('Location: resources.php?filter=all&retake=' . ($retake['success'] ? '1' : '0'));
        exit;
    }
}

// ---- Confirm & Record Grade (final score + optional feedback) ----
// Score/max_score are optional -- a teacher can leave both blank to record
// feedback-only, no-numeric-score review (e.g. a discussion activity). When
// both are given AND the resource is linked to a real gradebook item, this
// also syncs the grade into Grade Management and clears it from Missing
// Assessments (see aiResourceMarkReviewed() for the full contract).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_reviewed'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $post_error = 'Invalid security token. Please try again.';
    } else {
        $submission_id = (int) ($_POST['submission_id'] ?? 0);
        $feedback = (string) ($_POST['teacher_feedback'] ?? '');
        $score_raw = trim((string) ($_POST['score'] ?? ''));
        $max_score_raw = trim((string) ($_POST['max_score'] ?? ''));
        $score = ($score_raw !== '' && is_numeric($score_raw)) ? (float) $score_raw : null;
        $max_score = ($max_score_raw !== '' && is_numeric($max_score_raw)) ? (float) $max_score_raw : null;

        $result = aiResourceMarkReviewed($submission_id, $teacher_id, $feedback, $score, $max_score);
        if (!$result['success']) {
            header('Location: resources.php?review_error=1');
        } else {
            $synced = ($result['grade_sync_status'] ?? null) === 'synced' ? '&synced=1' : '';
            header('Location: resources.php?reviewed=1' . $synced);
        }
        exit;
    }
}

$page_title = 'Student Submissions';
$current_page = 'ai_resources';
$csrf_token = generateCSRFToken();

$filter = ($_GET['filter'] ?? 'pending') === 'all' ? 'all' : 'pending';
$submissions = aiResourceGetTeacherSubmissions($teacher_id, $filter === 'pending');

$attach_type_labels = [
    'quiz' => 'Quiz', 'practice_quiz' => 'Practice Quiz', 'reviewer' => 'Reviewer',
    'remedial_activity' => 'Remedial Activity', 'worksheet' => 'Worksheet', 'practice_exercise' => 'Practice Exercise',
    'performance_task' => 'Performance Task', 'written_work' => 'Written Work', 'question_bank' => 'Question Bank',
    'study_guide' => 'Study Guide', 'study_plan' => 'Study Plan', 'flashcards' => 'Flashcards',
    'short_assessment' => 'Short Assessment', 'multiple_choice' => 'Multiple Choice Questions',
    'true_false' => 'True/False Questions', 'identification' => 'Identification Questions', 'essay' => 'Essay Questions',
    'reflection_activity' => 'Reflection Activity', 'reading_activity' => 'Reading Activity',
    'intervention_activity' => 'Intervention Activity', 'learning_activity' => 'Learning Activity', 'rubric' => 'Rubric',
    'assignment' => 'Assignment', 'practice_test' => 'Practice Test', 'other' => 'Learning Resource',
];

$pending_count_all = count(aiResourceGetTeacherSubmissions($teacher_id, true));

include __DIR__ . '/../../includes/header.php';
?>

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-inbox text-primary me-2"></i>Student Submissions</h2>
        </div>
    </div>
</div>

<?php if (isset($_GET['retake'])): ?>
    <div class="alert alert-<?php echo $_GET['retake'] === '1' ? 'success' : 'danger'; ?>" style="border-radius:8px;font-size:13px;">
        <?php echo $_GET['retake'] === '1'
            ? '<i class="fas fa-check-circle me-1"></i>Retake allowed. The student can answer this quiz once more; the new score will replace the current one.'
            : 'Could not allow a retake for that submission.'; ?>
    </div>
<?php endif; ?>
<?php if (isset($_GET['reviewed'])): ?>
    <div class="alert alert-success" style="border-radius:8px;font-size:13px;">
        <i class="fas fa-check-circle me-1"></i>Marked as reviewed.
        <?php if (isset($_GET['synced'])): ?>The grade was also recorded in Grade Management and this activity is no longer missing.<?php endif; ?>
    </div>
<?php elseif (isset($_GET['review_error']) || !empty($post_error)): ?>
    <div class="alert alert-danger" style="border-radius:8px;font-size:13px;"><?php echo htmlspecialchars($post_error ?? 'Could not save your review.'); ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-body">
        <div class="btn-group mb-3" role="group" aria-label="Filter">
            <a href="resources.php?filter=pending" class="btn btn-sm <?php echo $filter === 'pending' ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                Needs Checking<?php echo $pending_count_all ? ' (' . $pending_count_all . ')' : ''; ?>
            </a>
            <a href="resources.php?filter=all" class="btn btn-sm <?php echo $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                All Submissions
            </a>
        </div>

        <?php if (empty($submissions)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-inbox fa-3x d-block mb-3" style="opacity:0.3;"></i>
                <?php if ($filter === 'pending'): ?>
                    <h5>Nothing waiting on you</h5>
                    <p class="mb-0">Free-response activities students have answered will show up here for you to check.</p>
                <?php else: ?>
                    <h5>No submissions yet</h5>
                    <p class="mb-0">Once a student answers a sent activity, it will show up here.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Activity</th>
                            <th>Subject</th>
                            <th>Type</th>
                            <th>Result</th>
                            <th>Submitted</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $s): ?>
                            <?php
                                $type_label = $attach_type_labels[$s['resource_type']] ?? 'Learning Resource';
                                $is_auto = (int) $s['is_auto_graded'] === 1;
                                $reviewed = $s['status'] === 'Reviewed';
                                // Locked/final only once Reviewed WITH an actual score/feedback
                                // decision made -- a submission a teacher already clicked
                                // "Mark Reviewed" on under the old checkmark-only flow (no
                                // score recorded) stays editable so it can still be AI-checked
                                // and confirmed with a real grade now.
                                $is_locked = $reviewed && $s['score'] !== null; // feedback-only reviews (the old checkmark-only flow) stay editable so they can still be scored now
                                $score_label = ($s['score'] !== null && $s['max_score'] !== null)
                                    ? htmlspecialchars(rtrim(rtrim(number_format((float) $s['score'], 1), '0'), '.')) . '/' . htmlspecialchars(rtrim(rtrim(number_format((float) $s['max_score'], 1), '0'), '.'))
                                    : null;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($s['last_name'] . ', ' . $s['first_name']); ?></td>
                                <td><?php echo htmlspecialchars($s['resource_title']); ?></td>
                                <td><?php echo htmlspecialchars($s['subject_code']); ?></td>
                                <td><?php echo htmlspecialchars($type_label); ?></td>
                                <td>
                                    <?php if ($is_auto): ?>
                                        <?php $pct = ((float) $s['max_score'] > 0) ? round(((float) $s['score'] / (float) $s['max_score']) * 100, 1) : null; ?>
                                        <strong class="text-success"><?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $s['score'], 1), '0'), '.')); ?>/<?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $s['max_score'], 1), '0'), '.')); ?></strong>
                                        <?php echo $pct !== null ? ' (' . htmlspecialchars(rtrim(rtrim(number_format($pct, 1), '0'), '.')) . '%)' : ''; ?>
                                        <?php if ((int) ($s['retake_allowed'] ?? 0) === 1): ?><br><span class="badge bg-warning text-dark">Retake allowed</span><?php endif; ?>
                                    <?php elseif ($is_locked): ?>
                                        <?php if ($score_label): ?><strong class="text-success"><?php echo $score_label; ?></strong> <?php endif; ?>
                                        <span class="badge bg-success">Reviewed</span>
                                    <?php elseif ($s['status'] === 'AI-Checked'): ?>
                                        <span class="badge bg-info text-dark">AI Suggested<?php echo $score_label ? ': ' . $score_label : ''; ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Waiting</span>
                                    <?php endif; ?>
                                </td>
                                <td><small class="text-muted"><?php echo htmlspecialchars(date('M d, Y g:i A', strtotime($s['submitted_at']))); ?></small></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#subModal<?php echo (int) $s['submission_id']; ?>">
                                        <?php echo ($is_auto || $is_locked) ? 'View' : 'Review'; ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Review/breakdown modals -- rendered OUTSIDE the table (a <div>
                 is not valid inside <tbody>/<tr>; browsers would otherwise
                 silently hoist it out anyway, which just makes the markup
                 harder to follow for no benefit). -->
            <?php foreach ($submissions as $s): ?>
                            <?php
                                $type_label = $attach_type_labels[$s['resource_type']] ?? 'Learning Resource';
                                $is_auto = (int) $s['is_auto_graded'] === 1;
                                $reviewed = $s['status'] === 'Reviewed';
                                $is_locked = $reviewed && $s['score'] !== null; // feedback-only reviews (the old checkmark-only flow) stay editable so they can still be scored now
                                $sid = (int) $s['submission_id'];
                                $answer_ext = strtolower(pathinfo((string) ($s['answer_stored_filename'] ?? ''), PATHINFO_EXTENSION));
                                $ai_checkable = $answer_ext !== '' && $answer_ext !== 'doc';
                                $is_linked = !empty($s['link_item_id']);
                                $cat_labels_local = ['WW' => 'Written Works', 'PT' => 'Performance Tasks', 'QA' => 'Examinations'];
                            ?>
                            <div class="modal fade" id="subModal<?php echo (int) $s['submission_id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title"><?php echo htmlspecialchars($s['resource_title']); ?> — <?php echo htmlspecialchars($s['last_name'] . ', ' . $s['first_name']); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <?php if ($is_auto): ?>
                                                <?php
                                                    $questions = json_decode((string) ($s['quiz_questions_json'] ?? ''), true) ?: [];
                                                    $answers = json_decode((string) ($s['answers_json'] ?? ''), true) ?: [];
                                                ?>
                                                <p><strong>Score:</strong> <?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $s['score'], 1), '0'), '.')); ?>/<?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $s['max_score'], 1), '0'), '.')); ?></p>
                                                <?php if ($is_linked): ?>
                                                    <div style="font-size:12px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:8px 12px;margin-bottom:12px;color:#166534;">
                                                        <i class="fas fa-link me-1"></i>Recorded in Manage Assessment Items (<?php echo htmlspecialchars($cat_labels_local[$s['link_category']] ?? $s['link_category']); ?>, <?php echo htmlspecialchars($s['link_term']); ?>). This is the student's one attempt.
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($is_linked || !empty($s['quiz_one_attempt'])): ?>
                                                    <?php if ((int) ($s['retake_allowed'] ?? 0) === 1): ?>
                                                        <p class="text-muted" style="font-size:12.5px;"><i class="fas fa-rotate-right me-1"></i>Retake allowed &mdash; waiting for the student to answer again.</p>
                                                    <?php else: ?>
                                                        <form method="POST" action="resources.php" class="mb-3" onsubmit="return confirm('Let this student answer the quiz once more? Their new score will replace the current one in Grade Management.');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                            <input type="hidden" name="submission_id" value="<?php echo $sid; ?>">
                                                            <button type="submit" name="allow_retake" value="1" class="btn btn-sm btn-outline-warning"><i class="fas fa-rotate-right me-1"></i>Allow retake</button>
                                                            <span class="text-muted ms-2" style="font-size:12px;">One attempt only unless you allow this.</span>
                                                        </form>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php foreach (array_values($questions) as $qi => $q): ?>
                                                    <?php
                                                        $chosen = $answers[$qi] ?? null;
                                                        $correct = isset($q['correct_index']) ? (int) $q['correct_index'] : null;
                                                        $is_correct = $chosen !== null && $correct !== null && (int) $chosen === $correct;
                                                    ?>
                                                    <div class="quiz-review-block" style="border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin-bottom:8px;">
                                                        <div style="font-weight:600;font-size:13.5px;"><?php echo $qi + 1; ?>. <?php echo htmlspecialchars($q['question'] ?? ''); ?></div>
                                                        <?php foreach ((array) ($q['choices'] ?? []) as $ci => $choice): ?>
                                                            <div style="font-size:13px;padding:2px 0;<?php echo $ci === $correct ? 'color:#238863;font-weight:600;' : ($ci == $chosen ? 'color:#C94A4A;' : ''); ?>">
                                                                <?php echo $ci === $correct ? '<i class="fas fa-check me-1"></i>' : ($ci == $chosen ? '<i class="fas fa-times me-1"></i>' : ''); ?>
                                                                <?php echo htmlspecialchars($choice); ?>
                                                                <?php echo $ci == $chosen ? ' (student\'s answer)' : ''; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <?php $manual = json_decode((string) ($s['answers_json'] ?? ''), true) ?: []; ?>
                                                <p class="text-muted" style="font-size:12.5px;">Student's attached file:</p>
                                                <?php if (!empty($s['answer_stored_filename'])): ?>
                                                    <div style="display:flex;align-items:center;gap:10px;background:#F8FAFC;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;margin-bottom:12px;font-size:13.5px;">
                                                        <i class="fas fa-paperclip"></i>
                                                        <span style="flex:1;word-break:break-word;"><?php echo htmlspecialchars($s['answer_original_filename'] ?: 'Answered file'); ?></span>
                                                        <a href="view_submission_answer.php?id=<?php echo (int) $s['submission_id']; ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">View</a>
                                                        <a href="download_submission_answer.php?id=<?php echo (int) $s['submission_id']; ?>" class="btn btn-sm btn-outline-secondary">Download</a>
                                                    </div>
                                                <?php else: ?>
                                                    <div style="background:#F8FAFC;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;margin-bottom:12px;font-size:13.5px;color:#9CA3AF;">No file attached.</div>
                                                <?php endif; ?>
                                                <?php if (!empty($manual['note'])): ?>
                                                    <p class="text-muted mb-1" style="font-size:12.5px;">Student's note:</p>
                                                    <div style="white-space:pre-wrap;font-size:13.5px;margin-bottom:16px;"><?php echo nl2br(htmlspecialchars($manual['note'])); ?></div>
                                                <?php endif; ?>

                                                <!-- ---- Gradebook link: required for a confirmed score to sync
                                                     into Grade Management and clear this activity from Missing
                                                     Assessments. Shown whenever unlinked and still editable --
                                                     covers both a brand-new activity the teacher skipped linking
                                                     at creation time, and an older one made before this feature
                                                     existed. ---- -->
                                                <?php if (!$is_locked): ?>
                                                    <?php if ($is_linked): ?>
                                                        <div id="linkStatus<?php echo $sid; ?>" style="font-size:12px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:8px 12px;margin-bottom:14px;color:#166534;">
                                                            <i class="fas fa-link me-1"></i>Linked to gradebook item: <strong><?php echo htmlspecialchars($cat_labels_local[$s['link_category']] ?? $s['link_category']); ?>, <?php echo htmlspecialchars($s['link_term']); ?></strong> — a confirmed score below will be recorded automatically and this will clear from Missing Assessments.
                                                        </div>
                                                    <?php else: ?>
                                                        <div id="linkStatus<?php echo $sid; ?>" class="ai-link-picker" style="font-size:12px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:10px 12px;margin-bottom:14px;">
                                                            <div style="font-weight:600;color:#92400E;margin-bottom:6px;"><i class="fas fa-triangle-exclamation me-1"></i>Not linked to a gradebook item yet</div>
                                                            <div style="color:#92400E;margin-bottom:8px;">Link this to the existing assessment it recovers so a confirmed score below is recorded automatically and clears from Missing Assessments. Otherwise you'll need to re-type the score in Grade Management yourself.</div>
                                                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                                                <select id="linkTerm<?php echo $sid; ?>" class="form-select form-select-sm" style="max-width:130px;">
                                                                    <option value="">Term…</option>
                                                                    <option value="Term 1">Term 1</option>
                                                                    <option value="Term 2">Term 2</option>
                                                                    <option value="Term 3">Term 3</option>
                                                                </select>
                                                                <select id="linkItem<?php echo $sid; ?>" class="form-select form-select-sm" style="max-width:260px;" disabled>
                                                                    <option value="">Select a term first…</option>
                                                                </select>
                                                                <button type="button" id="linkBtn<?php echo $sid; ?>" class="btn btn-sm btn-outline-primary" disabled>Link</button>
                                                            </div>
                                                        </div>
                                                        <script>
                                                        (function () {
                                                            var sid = <?php echo $sid; ?>;
                                                            var studentId = <?php echo (int) $s['student_id']; ?>;
                                                            var subjectCode = <?php echo json_encode($s['subject_code']); ?>;
                                                            var csrfToken = <?php echo json_encode($csrf_token); ?>;
                                                            var termSel = document.getElementById('linkTerm' + sid);
                                                            var itemSel = document.getElementById('linkItem' + sid);
                                                            var linkBtn = document.getElementById('linkBtn' + sid);

                                                            termSel.addEventListener('change', function () {
                                                                var term = termSel.value;
                                                                itemSel.innerHTML = '';
                                                                linkBtn.disabled = true;
                                                                if (!term) {
                                                                    var ph = document.createElement('option');
                                                                    ph.textContent = 'Select a term first…';
                                                                    itemSel.appendChild(ph);
                                                                    itemSel.disabled = true;
                                                                    return;
                                                                }
                                                                var loading = document.createElement('option');
                                                                loading.textContent = 'Loading…';
                                                                itemSel.appendChild(loading);
                                                                itemSel.disabled = true;

                                                                var fd = new FormData();
                                                                fd.append('csrf_token', csrfToken);
                                                                fd.append('student_id', studentId);
                                                                fd.append('subject_code', subjectCode);
                                                                fd.append('term', term);
                                                                fetch('list_assessment_items.php', { method: 'POST', body: fd })
                                                                    .then(function (r) { return r.json(); })
                                                                    .then(function (data) {
                                                                        if (termSel.value !== term) return;
                                                                        itemSel.innerHTML = '';
                                                                        if (!data.success || !data.items || !data.items.length) {
                                                                            var opt0 = document.createElement('option');
                                                                            opt0.value = '';
                                                                            opt0.textContent = data.success ? 'No items yet for this term' : 'Could not load items';
                                                                            itemSel.appendChild(opt0);
                                                                            itemSel.disabled = true;
                                                                            return;
                                                                        }
                                                                        var opt0 = document.createElement('option');
                                                                        opt0.value = '';
                                                                        opt0.textContent = 'Select which assessment this recovers…';
                                                                        itemSel.appendChild(opt0);
                                                                        var groups = {};
                                                                        data.items.forEach(function (it) {
                                                                            if (!groups[it.category_label]) {
                                                                                groups[it.category_label] = document.createElement('optgroup');
                                                                                groups[it.category_label].label = it.category_label;
                                                                                itemSel.appendChild(groups[it.category_label]);
                                                                            }
                                                                            var opt = document.createElement('option');
                                                                            opt.value = String(it.item_id);
                                                                            var hps = (it.highest_score % 1 === 0) ? it.highest_score.toFixed(0) : it.highest_score;
                                                                            opt.textContent = it.item_name + ' — ' + hps + ' pts — ' + (it.has_score ? ('Current: ' + it.current_score + '/' + hps) : 'Missing score');
                                                                            groups[it.category_label].appendChild(opt);
                                                                        });
                                                                        itemSel.disabled = false;
                                                                    })
                                                                    .catch(function () {
                                                                        itemSel.innerHTML = '<option value="">Could not load items</option>';
                                                                        itemSel.disabled = true;
                                                                    });
                                                            });
                                                            itemSel.addEventListener('change', function () {
                                                                linkBtn.disabled = !itemSel.value;
                                                            });
                                                            linkBtn.addEventListener('click', function () {
                                                                linkBtn.disabled = true;
                                                                linkBtn.textContent = 'Linking…';
                                                                var fd = new FormData();
                                                                fd.append('csrf_token', csrfToken);
                                                                fd.append('resource_id', <?php echo (int) $s['resource_id']; ?>);
                                                                fd.append('assessment_item_id', itemSel.value);
                                                                fd.append('assessment_term', termSel.value);
                                                                fetch('link_resource_item.php', { method: 'POST', body: fd })
                                                                    .then(function (r) { return r.json(); })
                                                                    .then(function (data) {
                                                                        if (data.success) {
                                                                            location.reload();
                                                                        } else {
                                                                            alert(data.error || 'Could not link this activity.');
                                                                            linkBtn.disabled = false;
                                                                            linkBtn.textContent = 'Link';
                                                                        }
                                                                    })
                                                                    .catch(function () {
                                                                        alert('Could not link this activity.');
                                                                        linkBtn.disabled = false;
                                                                        linkBtn.textContent = 'Link';
                                                                    });
                                                            });
                                                        })();
                                                        </script>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <?php if ($is_locked): ?>
                                                    <p class="text-muted mb-1" style="font-size:12.5px;">Score:</p>
                                                    <p style="font-size:14px;font-weight:600;"><?php echo $s['score'] !== null ? htmlspecialchars(rtrim(rtrim(number_format((float) $s['score'], 1), '0'), '.')) . '/' . htmlspecialchars(rtrim(rtrim(number_format((float) $s['max_score'], 1), '0'), '.')) : '(no numeric score)'; ?></p>
                                                    <p class="text-muted mb-1" style="font-size:12.5px;">Your feedback:</p>
                                                    <div style="white-space:pre-wrap;font-size:13.5px;"><?php echo htmlspecialchars($s['teacher_feedback'] ?: '(no feedback left)'); ?></div>
                                                <?php else: ?>
                                                    <?php if ($s['status'] === 'AI-Checked'): ?>
                                                        <div style="font-size:12px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:8px 12px;margin-bottom:10px;color:#1E40AF;">
                                                            <i class="fas fa-wand-magic-sparkles me-1"></i><strong>AI-suggested — review before confirming.</strong>
                                                            <?php if ($s['answer_source_type'] === 'image'): ?> The AI read this from a photo/scan, so double-check it carefully against the actual file.<?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div id="aiCheckError<?php echo $sid; ?>" class="text-danger" style="font-size:12.5px;display:none;margin-bottom:8px;"></div>
                                                    <form method="POST" action="resources.php" id="reviewForm<?php echo $sid; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                        <input type="hidden" name="submission_id" value="<?php echo $sid; ?>">
                                                        <div class="row g-2 mb-2">
                                                            <div class="col-auto">
                                                                <label class="form-label" style="font-size:12.5px;font-weight:600;">Score</label>
                                                                <input type="number" step="0.01" min="0" name="score" id="scoreInput<?php echo $sid; ?>" class="form-control form-control-sm" style="width:100px;" value="<?php echo $s['score'] !== null ? htmlspecialchars((string) $s['score']) : ''; ?>" placeholder="—">
                                                            </div>
                                                            <div class="col-auto">
                                                                <label class="form-label" style="font-size:12.5px;font-weight:600;">Out of</label>
                                                                <input type="number" step="0.01" min="0" name="max_score" id="maxScoreInput<?php echo $sid; ?>" class="form-control form-control-sm" style="width:100px;" value="<?php echo $s['max_score'] !== null ? htmlspecialchars((string) $s['max_score']) : ''; ?>" placeholder="—">
                                                            </div>
                                                        </div>
                                                        <label class="form-label" style="font-size:12.5px;font-weight:600;">Feedback (optional)</label>
                                                        <textarea name="teacher_feedback" id="feedbackInput<?php echo $sid; ?>" class="form-control mb-3" rows="3" placeholder="Good job! / Review pages 10-12 again..."><?php echo htmlspecialchars($s['teacher_feedback'] ?? ''); ?></textarea>
                                                        <div class="d-flex gap-2">
                                                            <?php if ($ai_checkable): ?>
                                                                <button type="button" id="aiCheckBtn<?php echo $sid; ?>" class="btn btn-sm btn-outline-primary">
                                                                    <i class="fas fa-wand-magic-sparkles me-1"></i>Check with AI
                                                                </button>
                                                            <?php endif; ?>
                                                            <button type="submit" name="mark_reviewed" value="1" class="btn btn-sm" style="background:#14213D;color:#fff;">
                                                                <i class="fas fa-check me-1"></i><?php echo $is_linked ? 'Confirm & Record Grade' : 'Confirm Review'; ?>
                                                            </button>
                                                        </div>
                                                    </form>
                                                    <?php if ($ai_checkable): ?>
                                                    <script>
                                                    (function () {
                                                        var sid = <?php echo $sid; ?>;
                                                        var csrfToken = <?php echo json_encode($csrf_token); ?>;
                                                        var btn = document.getElementById('aiCheckBtn' + sid);
                                                        var errEl = document.getElementById('aiCheckError' + sid);
                                                        btn.addEventListener('click', function () {
                                                            btn.disabled = true;
                                                            errEl.style.display = 'none';
                                                            var original = btn.innerHTML;
                                                            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Checking…';
                                                            var fd = new FormData();
                                                            fd.append('csrf_token', csrfToken);
                                                            fd.append('submission_id', sid);
                                                            fetch('check_submission.php', { method: 'POST', body: fd })
                                                                .then(function (r) { return r.json(); })
                                                                .then(function (data) {
                                                                    btn.disabled = false;
                                                                    btn.innerHTML = original;
                                                                    if (!data.success) {
                                                                        errEl.textContent = data.error || 'Could not check this submission.';
                                                                        errEl.style.display = 'block';
                                                                        return;
                                                                    }
                                                                    document.getElementById('scoreInput' + sid).value = data.score;
                                                                    document.getElementById('maxScoreInput' + sid).value = data.max_score;
                                                                    var fb = data.feedback || '';
                                                                    if (data.source_type === 'image' || !data.is_confident) {
                                                                        fb += (fb ? '\n\n' : '') + '(AI note: please double-check this score against the actual attached file before confirming.)';
                                                                    }
                                                                    document.getElementById('feedbackInput' + sid).value = fb;
                                                                })
                                                                .catch(function () {
                                                                    btn.disabled = false;
                                                                    btn.innerHTML = original;
                                                                    errEl.textContent = 'Could not check this submission. Please try again.';
                                                                    errEl.style.display = 'block';
                                                                });
                                                        });
                                                    })();
                                                    </script>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
