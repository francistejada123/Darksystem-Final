<?php
// modules/students/portal/subject.php
// Student Portal — Subject/Class Page (Google-Classroom-style).
//
// One page per enrolled subject, reached by clicking that subject's class
// card on dashboard.php. Everything about that ONE subject lives here:
// activities/tasks the teacher generated and sent (quizzes, PDFs, Word
// docs, Google Forms — with Answer Now / Submit Your Answer / score /
// submitted-proof, exactly as before), plain support messages/instructions
// from the teacher, and this student's own submitted proof for each
// activity — one reverse-chronological stream, newest first.
//
// This replaces the old standalone messages.php as far as the student is
// concerned: there is no more top "Messages" bell showing every subject's
// updates in one place — a student only finds out about a new activity or
// message by opening that subject's own page (see dashboard.php's small
// "New" badge per class card, which says THAT something is waiting,
// never WHAT). Opening this page marks this subject's unseen messages as
// Seen, the same way messages.php used to for all subjects at once.
//
// The message-card markup below (attachment cards, quiz-answer modal,
// manual-answer modal) is intentionally the same proven markup that used
// to live in messages.php, just scoped to one subject_code and wrapped in
// this page's own banner/topbar instead of a generic "Messages" header.

session_start();
require_once '../../../config/database.php';
require_once '../../../includes/ai_quiz_gform_helpers.php'; // aiQuizSyncFormResponses(), aiQuizGetResponses()
require_once '../../../includes/ai_resource_submission_helpers.php'; // aiResourceGetSubmission() — for the native in-app answer/manual-check case below
require_once '../../../includes/student_portal_ui.php'; // spSubjectColor(), spInitial()
require_once '../../../includes/grading_helpers.php'; // gh_getCurrentTerm() for the automatic banner chip
require_once '../../../includes/message_comment_helpers.php'; // comments under each message (teacher is notified)
require_once '../../../includes/classwork_helpers.php'; // Classroom classwork -> "Your Work" (attach files, Turn In)
require_once '../../../includes/assessment_material_helpers.php'; // aim_getStudentTasks() -- assessments + lesson materials sent by the teacher

if (!isset($_SESSION['student_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../../../student_login.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$lrn = $_SESSION['student_lrn'];
$student_name = $_SESSION['student_name'];

$subject_code = trim((string) ($_GET['subject'] ?? ''));
if ($subject_code === '') {
    header('Location: dashboard.php');
    exit;
}

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");

// ---- This student must actually be enrolled in a section this subject is
// taught to, this school year — never trust the ?subject= query string on
// its own just because it looks like a real subject code. ----
$class = getRecord(
        "SELECT sub.subject_code, sub.subject_name, u.first_name AS teacher_first, u.last_name AS teacher_last,
            e.section_id, e.school_year_id, ts.teacher_id
     FROM enrollments e
     JOIN teacher_subjects ts ON ts.section_id = e.section_id AND ts.school_year_id = e.school_year_id
     JOIN subjects sub ON sub.subject_code = ts.subject_code
     JOIN users u ON u.user_id = ts.teacher_id
     WHERE e.student_id = ? AND e.school_year_id = ? AND sub.subject_code = ?
     LIMIT 1",
    [$student_id, $active_sy['sy_id'] ?? 0, $subject_code]
);
if (!$class) {
    header('Location: dashboard.php');
    exit;
}

$student = getRecord("SELECT * FROM students WHERE student_id = ?", [$student_id]);

// Assessments (quiz, activity, ...) the teacher sent to this class from
// "Send to <item> AI" (Manage Assessment Items -> Teacher AI), with their
// lesson materials and this student's own status: Upcoming / Active /
// Completed / Missing.
$lm_tasks = aim_getStudentTasks($student_id, (int) $class['section_id'], $class['subject_code'], (int) $class['school_year_id']);

// Classroom classwork given to this student (Your Work): teacher files, my
// attached files, status (Assigned / Turned In / Missing / Missing – Submitted
// Late / Graded).
classworkEnsureSchema();
$cw_items = getRecords(
    "SELECT ca.*, cs.status AS my_status, cs.submitted_at, cs.is_late, cs.score, cs.teacher_feedback, cs.score_source
     FROM classwork_assignments ca
     INNER JOIN classwork_assignment_students cs ON cs.assignment_id = ca.assignment_id AND cs.student_id = ?
     WHERE ca.section_id = ? AND ca.subject_code = ? AND ca.school_year_id = ? AND ca.status = 'Published'
     ORDER BY (cs.status = 'Assigned') DESC, COALESCE(ca.due_date, ca.created_at) DESC",
    [$student_id, (int) $class['section_id'], $class['subject_code'], (int) $class['school_year_id']]
);
$cw_attach = [];
$cw_files_mine = [];
if ($cw_items) {
    $cw_ids = array_map('intval', array_column($cw_items, 'assignment_id'));
    $cw_ph = implode(',', array_fill(0, count($cw_ids), '?'));
    foreach (getRecords("SELECT * FROM classwork_assignment_attachments WHERE assignment_id IN ($cw_ph) ORDER BY attachment_id", $cw_ids) as $att) {
        $cw_attach[(int) $att['assignment_id']][] = $att;
    }
    $cw_files_mine = classworkFilesFor($cw_ids, $student_id);
}
$cw_flash = isset($_GET['cw_msg']) ? (string) $_GET['cw_msg'] : '';
// ?cw=<id> opens one classwork (details + Your Work); otherwise a tidy list.
$cw_open_id = (int) ($_GET['cw'] ?? 0);
if ($cw_open_id && !in_array($cw_open_id, array_map('intval', array_column($cw_items, 'assignment_id')), true)) {
    $cw_open_id = 0;
}

$csrf_token = generateCSRFToken();
$submitted_flash = isset($_GET['submitted']);
$commented_flash = isset($_GET['commented']);
$submit_error = isset($_GET['submit_error']) ? (string) $_GET['submit_error'] : '';

// Same query messages.php used to run, just scoped to THIS subject_code —
// see that file's history for the full reasoning behind every join here.
$messages = getRecords(
    "SELECT m.*, u.first_name AS teacher_first, u.last_name AS teacher_last,
            r.resource_id AS attach_resource_id, r.title AS attach_title, r.resource_type AS attach_resource_type,
            r.output_format AS attach_format, r.original_filename AS attach_filename, r.file_size AS attach_size,
            r.audience AS attach_audience,
            r.google_form_view_url AS attach_gform_view_url, r.quiz_max_score AS attach_gform_max_score,
            r.quiz_questions_json AS attach_gform_questions_json, r.quiz_one_attempt AS attach_gform_one_attempt,
            ans.submission_id AS ans_submission_id, ans.is_auto_graded AS ans_is_auto_graded,
            ans.answers_json AS ans_answers_json, ans.score AS ans_score, ans.max_score AS ans_max_score,
            ans.status AS ans_status, ans.teacher_feedback AS ans_feedback, ans.submitted_at AS ans_submitted_at,
            ans.answer_original_filename AS ans_answer_filename
     FROM student_support_messages m
     JOIN users u ON m.teacher_id = u.user_id
     LEFT JOIN ai_generated_resources r ON r.resource_id = m.resource_id
     LEFT JOIN ai_resource_submissions ans ON ans.resource_id = r.resource_id AND ans.student_id = m.student_id
     WHERE m.student_id = ? AND m.subject_code = ? AND m.status = 'Sent'
     ORDER BY m.sent_at DESC",
    [$student_id, $subject_code]
);

// Same lazy Google-Forms-score sync messages.php used to do, scoped to
// this subject's attachments only.
// Per-activity flags, read separately from the query above: which activities came
// from Teacher AI (answered right here in the portal), which are one-attempt
// graded quizzes, and which the teacher has allowed a retake on.
$portal_flags = aiResourcePortalFlags($student_id, array_values(array_filter(array_map(function ($m) {
    return (int) ($m['attach_resource_id'] ?? 0);
}, $messages))));

// A Google Form that Teacher AI sent to a whole class is ONE shared Form, and
// Google cannot say which student submitted it -- so class quizzes are answered
// in the portal instead (same questions, graded by the system the moment the
// student submits, so the real score reaches Grade Management).
$is_portal_quiz = function ($m) use ($portal_flags) {
    $rid = (int) ($m['attach_resource_id'] ?? 0);
    if (($m['attach_format'] ?? null) !== 'gform' || $rid <= 0 || empty($portal_flags[$rid]['teacher_ai'])) {
        return false;
    }
    $q = json_decode((string) ($m['attach_gform_questions_json'] ?? ''), true);
    return is_array($q) && count($q) > 0;
};

$gform_scores = [];
foreach ($messages as $m) {
    if (($m['attach_format'] ?? null) === 'gform' && !empty($m['attach_resource_id']) && !$is_portal_quiz($m)) {
        aiQuizSyncFormResponses((int) $m['attach_resource_id']);
        $responses = aiQuizGetResponses((int) $m['attach_resource_id']);
        if (!empty($responses)) {
            $gform_scores[(int) $m['attach_resource_id']] = $responses[0];
        }
    }
}

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

/**
 * The "answer the quiz" modal: one form, one radio group per question. Only ever
 * printed while the student may still answer (never after a locked submission).
 */
function spQuizModal($rid, $title, array $questions, $csrf_token, array $prev_answers = [])
{
    ?>
    <div class="modal fade" id="quizModal<?php echo (int) $rid; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" action="submit_resource_answer.php" data-one-shot="1" data-quiz-form="1" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="resource_id" value="<?php echo (int) $rid; ?>">
                    <input type="hidden" name="quiz_form" value="1">
                    <div class="modal-header">
                        <h5 class="modal-title"><?php echo htmlspecialchars($title); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php foreach (array_values($questions) as $qi => $q): ?>
                            <div class="quiz-question-block">
                                <div class="quiz-question-text"><?php echo $qi + 1; ?>. <?php echo htmlspecialchars($q['question'] ?? ''); ?></div>
                                <?php foreach ((array) ($q['choices'] ?? []) as $ci => $choice): ?>
                                    <label class="quiz-choice-label">
                                        <input type="radio" name="quiz_answers[<?php echo $qi; ?>]" value="<?php echo $ci; ?>" <?php echo (isset($prev_answers[$qi]) && (int) $prev_answers[$qi] === $ci) ? 'checked' : ''; ?>>
                                        <span><?php echo htmlspecialchars($choice); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="modal-footer">
                        <div class="quiz-missing-msg me-auto" role="alert" hidden></div>
                        <button type="button" class="btn btn-sm" style="border:1px solid var(--portal-border);background:#fff;" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm" style="background:#14213D;color:#fff;">Submit Answers</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}

/** "5/10 (50%)" -- whole numbers stay whole, decimals lose trailing zeros. */
function spScoreText($score, $max)
{
    $fmt = function ($n) { return rtrim(rtrim(number_format((float) $n, 2), '0'), '.'); };
    $text = $fmt($score) . '/' . $fmt($max);
    if ((float) $max > 0) {
        $text .= ' (' . $fmt(((float) $score / (float) $max) * 100) . '%)';
    }
    return $text;
}

// Comments (the student's questions and the teacher's replies) under each message.
$message_comments = mc_getComments(array_column($messages, 'message_id'));

// Activities the teacher sent (quiz / PDF / Word from Ask AI or Teacher AI)
// are Classwork too: listed with the classwork and opened the same way
// (?act=<message_id>: details + Your work / Turn in). Plain messages stay in the feed.
$act_msgs = array_values(array_filter($messages, function ($m) {
    return !empty($m['attach_resource_id']) && ($m['attach_audience'] ?? '') === 'student';
}));
// A make-up activity is sent as a PDF and a Word copy (two messages): show it
// as ONE classwork with both files; the student turns in on the first one.
$act_merged = [];
$act_seen = [];
foreach (array_reverse($act_msgs) as $am) {   // oldest first, so the PDF (sent first) is the main one
    $k = (int) $am['teacher_id'] . '|' . mb_strtolower(trim((string) $am['attach_title']));
    $prev_i = $act_seen[$k] ?? null;
    if ($prev_i !== null && in_array($am['attach_format'], ['pdf', 'docx'], true)
        && $act_merged[$prev_i]['attach_format'] !== $am['attach_format'] && empty($act_merged[$prev_i]['extra_files'])
        && abs(strtotime($am['sent_at']) - strtotime($act_merged[$prev_i]['sent_at'])) <= 600) {
        if ($am['attach_format'] === 'pdf') {           // the PDF (and its message) is the main one
            $am['extra_files'] = [$act_merged[$prev_i]];
            $act_merged[$prev_i] = $am;
        } else {
            $act_merged[$prev_i]['extra_files'][] = $am;
        }
        continue;
    }
    $am['extra_files'] = [];
    $act_merged[] = $am;
    $act_seen[$k] = count($act_merged) - 1;
}
$act_msgs = array_reverse($act_merged);
$feed_msgs = array_values(array_filter($messages, function ($m) {
    return !(!empty($m['attach_resource_id']) && ($m['attach_audience'] ?? '') === 'student');
}));
$act_open_id = (int) ($_GET['act'] ?? 0);
if ($act_open_id && !in_array($act_open_id, array_map('intval', array_column($act_msgs, 'message_id')), true)) {
    $act_open_id = 0;
}
/** One activity's kind and Your-work state: key (assigned|turned_in|graded), label, score text. */
$act_state = function (array $m) use ($is_portal_quiz, $gform_scores) {
    $rid = (int) $m['attach_resource_id'];
    $q = json_decode((string) ($m['attach_gform_questions_json'] ?? ''), true);
    $has_q = is_array($q) && count($q) > 0;
    if (($m['attach_format'] ?? '') === 'pptx') {
        return ['kind' => 'material', 'key' => 'assigned', 'label' => 'Lesson', 'score' => null];
    }
    if (($m['attach_format'] ?? '') === 'gform' && !$is_portal_quiz($m)) {
        $latest = $gform_scores[$rid] ?? null;
        return $latest
            ? ['kind' => 'gform', 'key' => 'graded', 'label' => 'Graded', 'score' => spScoreText($latest['score'], $latest['max_score'])]
            : ['kind' => 'gform', 'key' => 'assigned', 'label' => 'Assigned', 'score' => null];
    }
    if ($has_q) {
        return !empty($m['ans_submission_id'])
            ? ['kind' => 'quiz', 'key' => 'graded', 'label' => 'Graded', 'score' => spScoreText($m['ans_score'], $m['ans_max_score'])]
            : ['kind' => 'quiz', 'key' => 'assigned', 'label' => 'Assigned', 'score' => null];
    }
    if (!empty($m['ans_submission_id'])) {
        if (($m['ans_status'] ?? '') === 'Reviewed') {
            return ['kind' => 'file', 'key' => 'graded', 'label' => 'Checked',
                    'score' => ($m['ans_score'] !== null && $m['ans_score'] !== '') ? spScoreText($m['ans_score'], $m['ans_max_score']) : null];
        }
        return ['kind' => 'file', 'key' => 'turned_in', 'label' => 'Turned in', 'score' => null];
    }
    return ['kind' => 'file', 'key' => 'assigned', 'label' => 'Assigned', 'score' => null];
};

// Mark THIS subject's unseen messages seen now that the student opened it —
// other subjects' unread counts (shown on their own class cards) are
// untouched.
$unseen_ids = array_column(array_filter($messages, fn($m) => (int) $m['is_seen'] === 0), 'message_id');
if (!empty($unseen_ids)) {
    $placeholders = implode(',', array_fill(0, count($unseen_ids), '?'));
    executeQuery("UPDATE student_support_messages SET is_seen = 1, seen_at = NOW() WHERE message_id IN ($placeholders)", $unseen_ids);
    foreach ($messages as &$m) {
        if (in_array($m['message_id'], $unseen_ids, true)) { $m['is_seen'] = 1; }
    }
    unset($m);
}

$profile_image = '';
if (!empty($student['profile_image']) && file_exists('../../../static/uploads/profiles/' . $student['profile_image'])) {
    $profile_image = '../../../static/uploads/profiles/' . $student['profile_image'];
}

$subject_color = spSubjectColor($class['subject_code']);
$teacher_name = trim($class['teacher_first'] . ' ' . $class['teacher_last']);
$current_term = gh_getCurrentTerm($active_sy['sy_id'] ?? 0);
$class_schedule = getRecords("SELECT day_of_week, TIME_FORMAT(start_time, '%H:%i') AS start_time, TIME_FORMAT(end_time, '%H:%i') AS end_time FROM teacher_class_schedule WHERE teacher_id=? AND subject_code=? AND section_id=? AND school_year_id=? AND is_active=1 ORDER BY day_of_week, start_time", [(int)$class['teacher_id'], $class['subject_code'], (int)$class['section_id'], (int)$class['school_year_id']]);
$page_title = $class['subject_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
    (function () {
        try {
            var t = localStorage.getItem('egradepro-theme');
            if (!t) { t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light'; }
            if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        } catch (e) {}
    })();
    </script>
    <title><?php echo htmlspecialchars($class['subject_name']); ?> - EGRADEPRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../../../static/css/style.css" rel="stylesheet">
    <link href="../../../static/css/portal-theme.css" rel="stylesheet">
    <link href="../../../static/css/student-portal-classroom-ui.css?v=<?php echo (int) @filemtime(__DIR__ . '/../../../static/css/student-portal-classroom-ui.css'); ?>" rel="stylesheet">
    <link href="../../../static/css/theme.css?v=<?php echo (int) @filemtime(__DIR__ . '/../../../static/css/theme.css'); ?>" rel="stylesheet">
    <script src="../../../static/js/student-portal-shell.js"></script>
    <script src="../../../static/js/theme.js"></script>
    <style>
        :root {
            --portal-bg: #F1F4F8;
            --portal-primary: #14213D;
            --portal-card: #ffffff;
            --portal-border: #e5e7eb;
            --portal-green: #238863;
            --portal-red: #C94A4A;
            --portal-yellow: #B7791F;
            --portal-gray: #667085;
        }
        * { box-sizing: border-box; }
        body { background: var(--portal-bg); font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; }

        .sidebar { background: var(--portal-primary) !important; min-height: 100vh; padding: 0; display: flex; flex-direction: column; }
        .sidebar .logo { padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; gap: 10px; }
        .sidebar .logo img { height: 36px; width: 36px; border-radius: 8px; object-fit: contain; }
        .sidebar .logo span { color: #fff; font-size: 15px; font-weight: 700; letter-spacing: 0.02em; line-height: 1.3; }
        .sidebar .logo small { display: block; font-size: 9px; font-weight: 500; letter-spacing: 0.1em; color: rgba(255,255,255,0.4); }
        .sidebar .nav { padding: 12px 0; flex: 1; }
        .sidebar .nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 20px; color: rgba(255,255,255,0.55); font-size: 13px; font-weight: 500; text-decoration: none; transition: all 0.15s ease; border-left: 3px solid transparent; }
        .sidebar .nav-link i { width: 18px; text-align: center; font-size: 14px; }
        .sidebar .nav-link:hover { color: #fff; background: rgba(255,255,255,0.06); }
        .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,0.08); border-left-color: #fff; }
        .sidebar .nav-link.text-danger { color: rgba(201,74,74,0.7); }
        .sidebar .nav-link.text-danger:hover { color: #C94A4A; background: rgba(201,74,74,0.08); }
        .sidebar-footer { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.08); }
        .sidebar-footer small { color: rgba(255,255,255,0.3); font-size: 11px; }

        .main-content { padding: 24px 32px; min-height: 100vh; }
        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px; }
        .topbar-left { display: flex; align-items: center; gap: 14px; }
        .topbar-right { display: flex; align-items: center; gap: 10px; }
        .btn-portal-logout { padding: 7px 16px; border-radius: 6px; font-size: 12px; font-weight: 500; border: 1px solid var(--portal-border); background: var(--portal-card); color: var(--portal-gray); text-decoration: none; transition: all 0.15s; }

        /* Subject banner — colored per-subject, matching that subject's
           class card on dashboard.php (see includes/student_portal_ui.php). */
        .subject-banner { background-color: #123B6D; background-image: linear-gradient(110deg, rgba(8,31,61,0.92), rgba(18,59,109,0.72)), url('../../../static/images/school%20bldg%20bg.png'); background-size: cover; background-position: center 42%; color: #fff; border-radius: 10px; padding: 22px 26px 26px; margin-bottom: 22px; position: relative; overflow: hidden; text-shadow: 0 1px 3px rgba(0,0,0,0.55); }
        .subject-banner h2 { margin: 0 0 6px; font-size: 24px; font-weight: 700; }
        .subject-banner-meta { font-size: 13px; opacity: 0.9; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .subject-avatar { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; }

        .msg-card { background: var(--portal-card); border: 1px solid var(--portal-border); border-radius: 8px; margin-bottom: 14px; padding: 18px 20px; }
        .msg-card.unread { border-left: 3px solid var(--portal-primary); }
        .msg-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 8px; }
        .msg-teacher { font-size: 12.5px; font-weight: 600; color: var(--portal-primary); }
        .msg-date { font-size: 11px; color: var(--portal-gray); white-space: nowrap; }
        .msg-type { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 4px; background: rgba(20,33,61,0.06); color: var(--portal-primary); margin-bottom: 8px; }
        .msg-text { font-size: 14px; color: #374151; line-height: 1.6; white-space: pre-line; }

        .msg-attachment { display: flex; align-items: center; gap: 12px; margin-top: 12px; padding: 12px 14px; background: #F8FAFC; border: 1px solid var(--portal-border); border-radius: 8px; flex-wrap: wrap; }
        .msg-attachment-icon { font-size: 22px; flex-shrink: 0; }
        .msg-attachment-icon.pdf { color: #C94A4A; }
        .msg-attachment-icon.docx { color: #2563eb; }
        .msg-attachment-icon.pptx { color: #D24726; }
        .msg-attachment-info { flex: 1; min-width: 160px; }
        .msg-attachment-name { font-size: 13px; font-weight: 600; color: var(--portal-primary); word-break: break-word; }
        .msg-attachment-meta { font-size: 11.5px; color: var(--portal-gray); margin-top: 2px; }
        .msg-attachment-actions { display: flex; gap: 6px; }
        .btn-attach-action { font-size: 12px; font-weight: 500; padding: 6px 12px; border-radius: 6px; border: 1px solid var(--portal-border); background: #fff; color: var(--portal-primary); text-decoration: none; transition: all 0.15s; white-space: nowrap; cursor: pointer; }
        .btn-attach-action:hover { background: #f1f5f9; color: var(--portal-primary); }

        .attach-status-pill { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 9px; border-radius: 99px; margin-top: 4px; }
        .attach-status-pill.waiting { background: rgba(183,121,31,0.12); color: var(--portal-yellow); }
        .attach-status-pill.reviewed { background: rgba(35,136,99,0.12); color: var(--portal-green); }
        .quiz-question-block { border: 1px solid var(--portal-border); border-radius: 8px; padding: 12px 14px; margin-bottom: 12px; }
        .quiz-question-block .quiz-question-text { font-size: 13.5px; font-weight: 600; color: var(--portal-primary); margin-bottom: 8px; }
        .quiz-choice-label { display: flex; align-items: center; gap: 8px; font-size: 13px; padding: 6px 4px; border-radius: 6px; cursor: pointer; }
        .quiz-choice-label:hover { background: #F8FAFC; }
        /* A <form> wraps header/body/footer in these modals, so it must pass the
           scrollable modal's height down -- otherwise the body is clipped and the
           questions cannot be scrolled. */
        .modal-dialog-scrollable .modal-content > form { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; max-height: 100%; overflow: hidden; }
        .modal-dialog-scrollable .modal-content > form > .modal-body { overflow-y: auto; min-height: 0; }
        .quiz-question-block.is-missing { border-color: #e5484d; background: #fff5f5; }
        .quiz-question-block.is-missing .quiz-question-text::after { content: " (not answered)"; color: #c62828; font-weight: 500; font-size: 12px; }
        .quiz-missing-msg { color: #c62828; font-size: 12.5px; font-weight: 600; }
        .manual-answer-box textarea { font-size: 13.5px; }
        .flash-banner { border-radius: 8px; padding: 10px 16px; font-size: 13px; margin-bottom: 16px; }
        .flash-banner.success { background: rgba(35,136,99,0.10); color: var(--portal-green); border: 1px solid rgba(35,136,99,0.25); }
        .flash-banner.error { background: rgba(201,74,74,0.10); color: var(--portal-red); border: 1px solid rgba(201,74,74,0.25); }

        .lm-section-title { font-size: 14px; font-weight: 700; color: var(--portal-primary); margin: 0 0 10px; display: flex; align-items: center; gap: 8px; }
        .lm-card { background: var(--portal-card); border: 1px solid var(--portal-border); border-radius: 8px; margin-bottom: 12px; padding: 14px 18px; }
        .lm-card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap; }
        .lm-card-title { font-size: 14px; font-weight: 700; color: var(--portal-primary); }
        .lm-card-meta { font-size: 12px; color: var(--portal-gray); margin-top: 2px; }
        .lm-status { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 99px; white-space: nowrap; }
        .lm-status.upcoming { background: rgba(29,78,216,0.10); color: #1D4ED8; }
        .lm-status.active { background: rgba(183,121,31,0.14); color: var(--portal-yellow); }
        .lm-status.completed { background: rgba(35,136,99,0.12); color: var(--portal-green); }
        .lm-status.missing { background: rgba(201,74,74,0.12); color: var(--portal-red); }
        .lm-files { margin-top: 10px; display: flex; flex-direction: column; gap: 6px; }
        .lm-file { display: flex; align-items: center; gap: 10px; font-size: 13px; padding: 8px 10px; border: 1px solid var(--portal-border); border-radius: 6px; background: #F8FAFC; text-decoration: none; color: var(--portal-primary); }
        .lm-file:hover { background: #f1f5f9; color: var(--portal-primary); }
        .lm-file small { color: var(--portal-gray); margin-left: auto; white-space: nowrap; }
        .lm-locked { font-size: 12px; color: var(--portal-gray); margin-top: 8px; }

        /* Your Work (Classroom classwork) */
        .yw-back { display: inline-block; font-size: 13.5px; font-weight: 700; color: #1a73e8; text-decoration: none; margin-bottom: 12px; }
        .yw-row { display: flex; align-items: center; gap: 12px; background: var(--portal-card); border: 1px solid var(--portal-border); border-radius: 10px; padding: 12px 16px; margin-bottom: 8px; text-decoration: none; color: inherit; transition: box-shadow .15s; }
        .yw-row:hover { box-shadow: 0 1px 3px rgba(60,64,67,0.3), 0 4px 8px 3px rgba(60,64,67,0.12); color: inherit; }
        .yw-row-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .yw-row-title { font-size: 14.5px; font-weight: 700; color: var(--portal-primary); }
        .yw-row-go { color: var(--portal-gray); font-size: 12px; }
        .yw-card { display: grid; grid-template-columns: minmax(0, 1fr) 280px; gap: 16px; background: var(--portal-card); border: 1px solid var(--portal-border); border-radius: 10px; padding: 16px 18px; margin-bottom: 14px; }
        @media (max-width: 860px) { .yw-card { grid-template-columns: 1fr; } }
        .yw-head { display: flex; gap: 12px; align-items: flex-start; }
        .yw-icon { flex-shrink: 0; width: 40px; height: 40px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: #fff; background: #1a73e8; }
        .yw-icon.yw-pt { background: #9334e6; } .yw-icon.yw-qa { background: #d93025; } .yw-icon.yw-none { background: #5f6368; }
        .yw-title { font-size: 16px; font-weight: 700; color: var(--portal-primary); }
        .yw-meta { font-size: 12.5px; color: var(--portal-gray); }
        .yw-instructions { font-size: 14px; line-height: 1.65; color: #374151; margin: 12px 0 8px; }
        .yw-file { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 8px 10px; border: 1px solid var(--portal-border); border-radius: 8px; background: #F8FAFC; margin-top: 6px; font-size: 13px; }
        .yw-file span { flex: 1; min-width: 120px; font-weight: 600; color: var(--portal-primary); word-break: break-word; }
        .yw-work { border: 1px solid var(--portal-border); border-radius: 10px; padding: 14px; align-self: start; box-shadow: 0 1px 3px rgba(60,64,67,0.12); }
        .yw-work-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 10px; }
        .yw-work-head strong { font-size: 15px; color: var(--portal-primary); }
        .yw-status { font-size: 11.5px; font-weight: 700; padding: 3px 9px; border-radius: 99px; background: #e8f0fe; color: #1967d2; text-align: right; }
        .yw-s-turned_in { background: #e6f4ea; color: #137333; }
        .yw-s-graded { background: #e6f4ea; color: #137333; }
        .yw-s-missing { background: #fce8e6; color: #c5221f; }
        .yw-s-late { background: #fef7e0; color: #b06000; }
        .yw-score { font-size: 26px; font-weight: 700; color: var(--portal-primary); } .yw-score small { font-size: 14px; color: var(--portal-gray); }
        .yw-note { font-size: 12px; color: var(--portal-gray); margin-bottom: 8px; }
        .yw-warn { color: #c5221f; }
        .yw-feedback { font-size: 13px; background: #F8FAFC; border-radius: 8px; padding: 8px; margin-bottom: 8px; }
        .yw-myfile { display: flex; align-items: center; gap: 6px; border: 1px solid var(--portal-border); border-radius: 8px; padding: 6px 8px; margin-bottom: 6px; font-size: 12.5px; }
        .yw-myfile a { flex: 1; color: var(--portal-primary); text-decoration: none; word-break: break-word; }
        .yw-myfile button { border: 0; background: transparent; color: #c5221f; font-size: 16px; line-height: 1; }
        .yw-btn { display: block; width: 100%; text-align: center; border-radius: 8px; padding: 8px 10px; font-size: 13.5px; font-weight: 700; margin-top: 6px; cursor: pointer; }
        .yw-btn-outline { border: 1px solid var(--portal-border); background: #fff; color: #1a73e8; }
        .yw-btn-primary { border: 0; background: #1a73e8; color: #fff; }
        .yw-btn-primary:disabled { opacity: .6; }

        .yw-msg { font-size: 14px; line-height: 1.65; color: #374151; margin: 12px 0 8px; white-space: pre-line; }
        .yw-work textarea, .yw-work input[type=file] { width: 100%; font-size: 12.5px; margin-top: 6px; }
        .yw-work textarea { border: 1px solid var(--portal-border); border-radius: 8px; padding: 6px 8px; }
        .yw-card .msg-comments { grid-column: 1 / -1; }
        .msg-anchor { display: block; position: relative; top: -80px; visibility: hidden; }
        .msg-comments { margin-top: 12px; border-top: 1px solid var(--portal-border); padding-top: 10px; }
        .msg-comment { font-size: 13px; padding: 7px 10px; border-radius: 8px; margin-bottom: 6px; color: #374151; }
        .msg-comment span { white-space: pre-line; }
        .msg-comment.student { background: #F5F6FF; margin-left: 24px; }
        .msg-comment.teacher { background: #E9F9F1; }
        .msg-comment b { display: block; font-size: 12px; color: var(--portal-primary); }
        .msg-comment small { font-weight: 400; color: var(--portal-gray); margin-left: 6px; }
        .msg-comment-form { display: flex; gap: 8px; align-items: flex-end; }
        .msg-comment-form textarea { flex: 1; font-size: 13px; border: 1px solid var(--portal-border); border-radius: 18px; padding: 7px 14px; resize: vertical; min-height: 36px; }
        .msg-comment-form button { border: none; background: var(--portal-primary); color: #fff; border-radius: 50%; width: 36px; height: 36px; flex-shrink: 0; }

        .empty-state { text-align: center; padding: 48px 20px; color: var(--portal-gray); }
        .empty-state i { font-size: 40px; margin-bottom: 12px; opacity: 0.3; }
        .empty-state h5 { font-size: 15px; font-weight: 600; margin-bottom: 4px; color: #9ca3af; }

        @media (max-width: 768px) {
            .sidebar { position: fixed !important; top: 0; left: -280px; width: 260px; height: 100vh; z-index: 1050; transition: left 0.25s ease; overflow-y: auto; box-shadow: 4px 0 24px rgba(0,0,0,0.15); }
            .sidebar.show { left: 0; }
            .mobile-menu-btn { display: inline-flex !important; }
            .main-content { padding: 16px !important; margin-left: 0 !important; }
            .topbar { flex-wrap: wrap; gap: 10px; }
            .subject-banner h2 { font-size: 19px; }
        }
        @media (min-width: 769px) {
            .mobile-menu-btn { display: none !important; }
            .sidebar-overlay { display: none !important; }
            .sidebar { display: flex !important; position: fixed !important; top: 0 !important; left: 0 !important; width: var(--sidebar-width, 240px) !important; height: 100vh !important; overflow-y: auto !important; }
            .main-content { margin-left: var(--sidebar-width, 240px) !important; width: calc(100% - var(--sidebar-width, 240px)) !important; max-width: calc(100% - var(--sidebar-width, 240px)) !important; flex: 0 0 auto !important; }
        }
    </style>
</head>
<body>
<script>document.body.classList.add('sidebar-collapsed');</script>
<script>document.body.classList.add('sidebar-collapsed');</script>
<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-2 sidebar" id="mainSidebar">
            <div class="logo">
                <img src="../../../static/images/logo.png" alt="Logo" onerror="this.style.display='none'">
                <span>EGRADEPRO<small>STUDENT PORTAL</small></span>
            </div>
            <nav class="nav flex-column">
                <a href="dashboard.php" class="nav-link"><i class="fas fa-th-large"></i> Dashboard</a>
                <a href="grades.php" class="nav-link"><i class="fas fa-chart-bar"></i> My Grades</a>
                <a href="tutorial.php" class="nav-link"><i class="fas fa-play-circle"></i> My Tutorials</a>
                <a href="enrollment.php" class="nav-link"><i class="fas fa-clipboard-check"></i> Enrollment</a>
            </nav>
            <div class="sidebar-footer"><small>&copy; <?php echo date('Y'); ?></small></div>
        </div>

        <!-- Main Content -->
        <div class="col-md-10 main-content">
            <div class="topbar">
                <div class="topbar-left">
                    <button class="mobile-menu-btn btn btn-sm" onclick="toggleSidebar()" style="display:none;border:1px solid var(--portal-border);background:#fff;border-radius:6px;padding:6px 10px;"><i class="fas fa-bars"></i></button>
                </div>
                <div class="topbar-right">
                    <button type="button" class="theme-toggle-btn" id="themeToggleBtn" aria-label="Switch to dark mode" title="Switch to dark mode" aria-pressed="false">
                        <i class="fas fa-sun theme-toggle-icon-sun" aria-hidden="true"></i>
                        <i class="fas fa-moon theme-toggle-icon-moon" aria-hidden="true"></i>
                    </button>
                    <div class="app-topbar-profile" id="appTopbarProfile">
                        <button type="button" class="app-topbar-profile-btn" id="appTopbarProfileBtn" onclick="toggleProfileMenu()" aria-haspopup="true" aria-expanded="false">
                            <div class="app-topbar-avatar">
                                <?php if ($profile_image): ?>
                                    <img src="<?php echo $profile_image; ?>" alt="">
                                <?php else: ?>
                                    <i class="fas fa-user"></i>
                                <?php endif; ?>
                            </div>
                            <span class="app-topbar-profile-text">
                                <strong><?php echo htmlspecialchars($student_name); ?></strong>
                                <small>Student</small>
                            </span>
                            <i class="fas fa-chevron-down app-topbar-chevron"></i>
                        </button>
                        <div class="app-topbar-dropdown" id="appTopbarDropdown">
                            <a href="profile.php"><i class="fas fa-user"></i> My Profile</a>
                            <div class="app-topbar-dropdown-divider"></div>
                            <a href="../../../logout.php" class="text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="subject-banner" style="--card-color: <?php echo htmlspecialchars($subject_color); ?>;">
                <div class="subject-banner-icon"><i class="fas fa-book-open"></i></div>
                <h2><?php echo htmlspecialchars($class['subject_name']); ?></h2>
                <div class="subject-banner-meta">
                    <div class="subject-avatar"><?php echo htmlspecialchars(spInitial($class['teacher_first'])); ?></div>
                    <span><?php echo htmlspecialchars($class['teacher_first'] . ' ' . $class['teacher_last']); ?></span>
                    <span class="subject-banner-chip">Student Class</span>
                    <?php if ($current_term): ?><span class="subject-banner-chip active"><?php echo htmlspecialchars($current_term); ?></span><?php endif; ?>
                    <span class="subject-banner-chip">SY <?php echo htmlspecialchars($active_sy['sy_name'] ?? ''); ?></span>
                    <?php if ($class_schedule): ?>
                        <?php $student_day_names = [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri']; ?>
                        <span class="subject-banner-chip"><i class="fas fa-clock me-1"></i><?php echo htmlspecialchars(implode('-', array_map(function ($row) use ($student_day_names) { return $student_day_names[(int)$row['day_of_week']] ?? ''; }, $class_schedule))); ?> · <?php echo date('g:i A', strtotime($class_schedule[0]['start_time'])); ?>–<?php echo date('g:i A', strtotime($class_schedule[0]['end_time'])); ?></span>
                    <?php endif; ?>
                </div>
                <div class="subject-banner-stats">
                    <div><strong><?php echo count($messages); ?></strong><span>Updates</span></div>
                    <div><strong><?php echo count(array_filter($messages, function ($message) { return !empty($message['attach_resource_id']); })); ?></strong><span>Activities</span></div>
                </div>
            </div>

            <?php if ($cw_flash !== ''): ?>
                <div class="flash-banner success"><i class="fas fa-check-circle me-1"></i><?php echo htmlspecialchars($cw_flash); ?></div>
            <?php endif; ?>
            <?php if ($commented_flash): ?>
                <div class="flash-banner success"><i class="fas fa-check-circle me-1"></i>Your comment was sent to your teacher.</div>
            <?php endif; ?>
            <?php if ($submitted_flash): ?>
                <div class="flash-banner success"><i class="fas fa-check-circle me-1"></i>Your answer was submitted.</div>
            <?php elseif ($submit_error !== ''): ?>
                <div class="flash-banner error"><i class="fas fa-exclamation-circle me-1"></i><?php echo htmlspecialchars($submit_error); ?></div>
            <?php endif; ?>

            <?php if (!empty($cw_items) || !empty($act_msgs)): ?>
                <div class="mb-4" id="your-work">
                    <?php if ($cw_open_id || $act_open_id): ?>
                        <a class="yw-back" href="subject.php?subject=<?php echo urlencode($subject_code); ?>"><i class="fas fa-arrow-left me-1"></i>Back to classwork</a>
                    <?php else: ?>
                        <h3 class="lm-section-title"><i class="fas fa-clipboard-list"></i> Classwork</h3>
                    <?php endif; ?>
                    <?php foreach ($cw_items as $cw):
                        $cw_state = classworkStatus(['status' => $cw['my_status'], 'is_late' => $cw['is_late'], 'score' => $cw['score']], $cw);
                        $cw_g = $cw['grading_category'] ?: classworkGradingCategory($cw['category']);
                        $cw_done = in_array($cw['my_status'], ['Submitted', 'Reviewed'], true);
                        $cw_graded = $cw_state['key'] === 'graded';
                        $cw_myfiles = $cw_files_mine[(int) $cw['assignment_id'] . '|' . (int) $student_id] ?? [];
                        $cw_fmt = function ($n) { return rtrim(rtrim(number_format((float) $n, 2), '0'), '.'); };
                        if ($cw_open_id && (int) $cw['assignment_id'] !== $cw_open_id) continue;
                        if ($act_open_id) continue;
                    ?>
                        <?php if (!$cw_open_id): ?>
                            <a class="yw-row" href="subject.php?subject=<?php echo urlencode($subject_code); ?>&amp;cw=<?php echo (int) $cw['assignment_id']; ?>">
                                <span class="yw-icon yw-<?php echo strtolower($cw_g ?: 'none'); ?>"><i class="fas <?php echo $cw_g === 'PT' ? 'fa-star' : ($cw_g === 'QA' ? 'fa-file-signature' : ($cw_g ? 'fa-clipboard-list' : 'fa-book-open')); ?>"></i></span>
                                <span class="yw-row-main">
                                    <span class="yw-row-title"><?php echo htmlspecialchars($cw['title']); ?></span>
                                    <span class="yw-meta"><?php echo htmlspecialchars($cw['category']); ?> · <?php echo !empty($cw['due_date']) ? 'Due ' . htmlspecialchars(date('M j, g:i A', strtotime($cw['due_date']))) : 'No due date'; ?></span>
                                </span>
                                <span class="yw-status yw-s-<?php echo $cw_state['key']; ?>"><?php echo htmlspecialchars($cw_graded ? 'Graded ' . $cw_fmt($cw['score']) . (!empty($cw['max_points']) ? '/' . $cw_fmt($cw['max_points']) : '') : $cw_state['label']); ?></span>
                                <i class="fas fa-chevron-right yw-row-go"></i>
                            </a>
                        <?php continue; endif; ?>
                        <div class="yw-card" id="cw-<?php echo (int) $cw['assignment_id']; ?>">
                            <div class="yw-main">
                                <div class="yw-head">
                                    <span class="yw-icon yw-<?php echo strtolower($cw_g ?: 'none'); ?>"><i class="fas <?php echo $cw_g === 'PT' ? 'fa-star' : ($cw_g === 'QA' ? 'fa-file-signature' : ($cw_g ? 'fa-clipboard-list' : 'fa-book-open')); ?>"></i></span>
                                    <div>
                                        <div class="yw-title"><?php echo htmlspecialchars($cw['title']); ?></div>
                                        <div class="yw-meta">
                                            <?php echo htmlspecialchars($cw['category']); ?> · <?php echo htmlspecialchars($cw_g ? CLASSWORK_GRADING_LABELS[$cw_g] : 'Not graded'); ?>
                                            <?php if (!empty($cw['max_points'])): ?> · <?php echo $cw_fmt($cw['max_points']); ?> points<?php endif; ?>
                                        </div>
                                        <div class="yw-meta"><?php echo !empty($cw['due_date']) ? '<i class="far fa-clock me-1"></i>Due ' . htmlspecialchars(date('M j, Y g:i A', strtotime($cw['due_date']))) : 'No due date'; ?></div>
                                    </div>
                                </div>
                                <?php if (trim((string) $cw['instructions']) !== ''): ?>
                                    <div class="yw-instructions"><?php echo nl2br(htmlspecialchars($cw['instructions'])); ?></div>
                                <?php endif; ?>
                                <?php foreach ($cw_attach[(int) $cw['assignment_id']] ?? [] as $att): ?>
                                    <div class="yw-file">
                                        <i class="fas <?php echo $att['attachment_type'] === 'link' ? 'fa-link' : classroomMimeIcon($att['mime_type'], $att['original_filename']); ?>"></i>
                                        <span><?php echo htmlspecialchars($att['attachment_type'] === 'link' ? $att['external_url'] : $att['original_filename']); ?></span>
                                        <a href="classwork_file.php?type=attachment&id=<?php echo (int) $att['attachment_id']; ?>" target="_blank" rel="noopener" class="btn-attach-action">View</a>
                                        <?php if ($att['attachment_type'] !== 'link'): ?><a href="classwork_file.php?type=attachment&id=<?php echo (int) $att['attachment_id']; ?>&download=1" class="btn-attach-action"><i class="fas fa-download me-1"></i>Download File</a><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <aside class="yw-work">
                                <div class="yw-work-head">
                                    <strong>Your work</strong>
                                    <span class="yw-status yw-s-<?php echo $cw_state['key']; ?>"><?php echo htmlspecialchars($cw_state['label']); ?></span>
                                </div>
                                <?php if ($cw_graded): ?>
                                    <div class="yw-score"><?php echo $cw_fmt($cw['score']); ?><?php echo !empty($cw['max_points']) ? '<small>/' . $cw_fmt($cw['max_points']) . '</small>' : ''; ?></div>
                                    <div class="yw-note"><?php echo ($cw['score_source'] ?? '') === 'AI' ? '<i class="fas fa-robot me-1"></i>Checked by AI — your teacher may still adjust it' : '<i class="fas fa-user-check me-1"></i>Checked by your teacher'; ?></div>
                                    <?php if (!empty($cw['is_late'])): ?><div class="yw-note">Submitted late</div><?php endif; ?>
                                    <?php if (!empty($cw['teacher_feedback'])): ?><div class="yw-feedback"><?php echo nl2br(htmlspecialchars($cw['teacher_feedback'])); ?></div><?php endif; ?>
                                <?php endif; ?>
                                <?php if ($cw_done && !empty($cw['submitted_at'])): ?>
                                    <div class="yw-note">Turned in <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($cw['submitted_at']))); ?></div>
                                <?php elseif ($cw_state['key'] === 'missing'): ?>
                                    <div class="yw-note yw-warn">The due date has passed. You can still turn it in — it will be marked late.</div>
                                <?php endif; ?>

                                <?php foreach ($cw_myfiles as $mf): ?>
                                    <div class="yw-myfile">
                                        <a href="classwork_file.php?type=mine&id=<?php echo (int) $mf['file_id']; ?>" target="_blank" rel="noopener"><i class="fas <?php echo classroomMimeIcon($mf['mime_type'], $mf['original_filename']); ?>"></i> <?php echo htmlspecialchars($mf['original_filename']); ?></a>
                                        <?php if (!$cw_done): ?>
                                            <form method="POST" action="classwork_action.php">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="assignment_id" value="<?php echo (int) $cw['assignment_id']; ?>">
                                                <input type="hidden" name="action" value="remove_file">
                                                <input type="hidden" name="file_id" value="<?php echo (int) $mf['file_id']; ?>">
                                                <button type="submit" title="Remove">&times;</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>

                                <?php if (!$cw_done): ?>
                                    <form method="POST" action="classwork_action.php" enctype="multipart/form-data" class="yw-add">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="assignment_id" value="<?php echo (int) $cw['assignment_id']; ?>">
                                        <input type="hidden" name="action" value="add_files">
                                        <label class="yw-btn yw-btn-outline"><i class="fas fa-plus me-1"></i>Add or create
                                            <input type="file" name="work_files[]" multiple hidden onchange="if(this.files.length){this.form.submit();}">
                                        </label>
                                    </form>
                                    <form method="POST" action="classwork_action.php" onsubmit="var b=this.querySelector('button');if(b.disabled)return false;b.disabled=true;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="assignment_id" value="<?php echo (int) $cw['assignment_id']; ?>">
                                        <input type="hidden" name="action" value="turn_in">
                                        <button type="submit" class="yw-btn yw-btn-primary"><?php echo $cw_myfiles ? 'Turn In' : 'Mark as done'; ?></button>
                                    </form>
                                <?php elseif (!$cw_graded): ?>
                                    <form method="POST" action="classwork_action.php">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="assignment_id" value="<?php echo (int) $cw['assignment_id']; ?>">
                                        <input type="hidden" name="action" value="unsubmit">
                                        <button type="submit" class="yw-btn yw-btn-outline">Unsubmit</button>
                                    </form>
                                <?php endif; ?>
                            </aside>
                        </div>
                    <?php endforeach; ?>
                    <?php /* ---- Activities sent by the teacher (Ask AI / Teacher AI): same list row and detail as classwork ---- */ ?>
                    <?php foreach ($act_msgs as $m):
                        $mid = (int) $m['message_id'];
                        if ($cw_open_id || ($act_open_id && $mid !== $act_open_id)) continue;
                        $rid = (int) $m['attach_resource_id'];
                        $as = $act_state($m);
                        $a_type = $attach_type_labels[$m['attach_resource_type']] ?? ($as['kind'] === 'file' ? 'Learning Resource' : 'Quiz');
                        $a_icon = in_array($as['kind'], ['quiz', 'gform'], true) ? 'fa-square-poll-vertical' : ($as['kind'] === 'material' ? 'fa-book-open' : 'fa-clipboard-list');
                        $a_from = trim($m['teacher_first'] . ' ' . $m['teacher_last']);
                    ?>
                        <?php if (!$act_open_id): ?>
                            <a class="yw-row" id="resource-<?php echo $rid; ?>" href="subject.php?subject=<?php echo urlencode($subject_code); ?>&amp;act=<?php echo $mid; ?>">
                                <span class="yw-icon <?php echo $as['kind'] === 'material' ? 'yw-none' : ''; ?>"><i class="fas <?php echo $a_icon; ?>"></i></span>
                                <span class="yw-row-main">
                                    <span class="yw-row-title"><?php echo htmlspecialchars($m['attach_title'] ?: $a_type); ?></span>
                                    <span class="yw-meta"><?php echo htmlspecialchars($a_type); ?><?php echo !empty($m['extra_files']) ? ' · PDF + Word' : ''; ?> · Posted <?php echo htmlspecialchars(date('M j, g:i A', strtotime($m['sent_at']))); ?></span>
                                </span>
                                <span class="yw-status yw-s-<?php echo $as['key']; ?>"><?php echo htmlspecialchars($as['score'] !== null ? $as['label'] . ' ' . $as['score'] : $as['label']); ?></span>
                                <i class="fas fa-chevron-right yw-row-go"></i>
                            </a>
                        <?php continue; endif; ?>

                        <div class="yw-card" id="resource-<?php echo $rid; ?>">
                            <div class="yw-main">
                                <span id="msg-<?php echo $mid; ?>" class="msg-anchor"></span>
                                <div class="yw-head">
                                    <span class="yw-icon <?php echo $as['kind'] === 'material' ? 'yw-none' : ''; ?>"><i class="fas <?php echo $a_icon; ?>"></i></span>
                                    <div>
                                        <div class="yw-title"><?php echo htmlspecialchars($m['attach_title'] ?: $a_type); ?></div>
                                        <div class="yw-meta"><?php echo htmlspecialchars($a_type); ?> · <?php echo htmlspecialchars($a_from); ?></div>
                                        <div class="yw-meta"><i class="far fa-clock me-1"></i>Posted <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($m['sent_at']))); ?></div>
                                    </div>
                                </div>
                                <?php if (trim((string) $m['final_message']) !== ''): ?>
                                    <div class="yw-msg"><?php echo htmlspecialchars($m['final_message']); ?></div>
                                <?php endif; ?>
                                <?php if ($as['kind'] === 'file' || $as['kind'] === 'material'): ?>
                                    <?php
                                        $is_docx = $m['attach_format'] === 'docx';
                                        $is_pptx = $m['attach_format'] === 'pptx';
                                        $size_kb = $m['attach_size'] ? max(1, round($m['attach_size'] / 1024)) . ' KB' : '';
                                    ?>
                                    <div class="yw-file">
                                        <i class="fas <?php echo $is_pptx ? 'fa-file-powerpoint' : ($is_docx ? 'fa-file-word' : 'fa-file-pdf'); ?>"></i>
                                        <span><?php echo htmlspecialchars($m['attach_filename'] ?: $m['attach_title']); ?><?php echo $size_kb ? ' <small class="yw-meta">· ' . htmlspecialchars($size_kb) . '</small>' : ''; ?></span>
                                        <a href="../../ai/view_resource.php?id=<?php echo $rid; ?>" target="_blank" rel="noopener" class="btn-attach-action">View</a>
                                        <a href="../../ai/download_resource.php?id=<?php echo $rid; ?>" class="btn-attach-action"><i class="fas fa-download me-1"></i>Download File</a>
                                    </div>
                                    <?php foreach ($m['extra_files'] ?? [] as $xf): $xrid = (int) $xf['attach_resource_id']; ?>
                                        <div class="yw-file">
                                            <i class="fas <?php echo $xf['attach_format'] === 'docx' ? 'fa-file-word' : 'fa-file-pdf'; ?>"></i>
                                            <span><?php echo htmlspecialchars($xf['attach_filename'] ?: $xf['attach_title']); ?></span>
                                            <a href="../../ai/view_resource.php?id=<?php echo $xrid; ?>" target="_blank" rel="noopener" class="btn-attach-action">View</a>
                                            <a href="../../ai/download_resource.php?id=<?php echo $xrid; ?>" class="btn-attach-action"><i class="fas fa-download me-1"></i>Download File</a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <aside class="yw-work">
                                <div class="yw-work-head">
                                    <strong>Your work</strong>
                                    <span class="yw-status yw-s-<?php echo $as['key']; ?>"><?php echo htmlspecialchars($as['label']); ?></span>
                                </div>
                                <?php if ($as['score'] !== null): ?>
                                    <div class="yw-score"><?php echo htmlspecialchars(preg_replace('/\s*\(.*\)$/', '', $as['score'])); ?></div>
                                <?php endif; ?>

                                <?php if ($as['kind'] === 'material'): ?>
                                    <div class="yw-note">Lesson material — open it with View above. Nothing to turn in.</div>

                                <?php elseif ($as['kind'] === 'gform'): ?>
                                    <?php
                                        $latest = $gform_scores[$rid] ?? null;
                                        $one_attempt = (int) ($m['attach_gform_one_attempt'] ?? 0) === 1;
                                    ?>
                                    <?php if ($latest): ?>
                                        <div class="yw-note"><i class="fas fa-robot me-1"></i>Checked automatically — submitted <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($latest['submitted_at']))); ?></div>
                                    <?php endif; ?>
                                    <?php if ($latest && $one_attempt): ?>
                                        <span class="yw-btn yw-btn-outline" style="cursor:default;"><i class="fas fa-lock me-1"></i>Quiz Completed</span>
                                    <?php else: ?>
                                        <a class="yw-btn yw-btn-primary" style="text-decoration:none;" href="<?php echo htmlspecialchars($m['attach_gform_view_url']); ?>" target="_blank" rel="noopener"><?php echo $latest ? 'Open Quiz Again' : 'Open Quiz'; ?></a>
                                        <div class="yw-note mt-2">Your score shows here after you submit the form.</div>
                                    <?php endif; ?>

                                <?php elseif ($as['kind'] === 'quiz'): ?>
                                    <?php
                                        $q_list = json_decode((string) ($m['attach_gform_questions_json'] ?? ''), true) ?: [];
                                        $q_flags = $portal_flags[$rid] ?? ['graded' => false, 'retake' => false];
                                        $q_done = !empty($m['ans_submission_id']);
                                        $q_locked = $q_done && !empty($q_flags['graded']) && empty($q_flags['retake']);
                                    ?>
                                    <div class="yw-note"><?php echo count($q_list); ?> items<?php echo !empty($m['attach_gform_max_score']) ? ' · ' . (int) $m['attach_gform_max_score'] . ' points' : ''; ?><?php echo !empty($q_flags['graded']) ? ' · One attempt only' : ''; ?></div>
                                    <?php if ($q_done): ?>
                                        <div class="yw-note">Turned in <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($m['ans_submitted_at']))); ?></div>
                                        <?php if (!empty($q_flags['retake'])): ?><div class="yw-note">Your teacher allowed you to retake this quiz.</div><?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($q_locked): ?>
                                        <span class="yw-btn yw-btn-outline" style="cursor:default;"><i class="fas fa-lock me-1"></i>Quiz Completed</span>
                                    <?php else: ?>
                                        <button type="button" class="yw-btn yw-btn-primary" data-bs-toggle="modal" data-bs-target="#quizModal<?php echo $rid; ?>"><?php echo $q_done ? 'Retake Quiz' : 'Answer Now'; ?></button>
                                        <?php spQuizModal($rid, $m['attach_title'], $q_list, $csrf_token, $q_done ? (json_decode((string) ($m['ans_answers_json'] ?? ''), true) ?: []) : []); ?>
                                    <?php endif; ?>

                                <?php else: /* file activity: attach the answered file, then Turn In */ ?>
                                    <?php
                                        $prev = !empty($m['ans_submission_id']) ? (json_decode((string) ($m['ans_answers_json'] ?? ''), true) ?: []) : [];
                                        $reviewed = ($as['key'] === 'graded');
                                    ?>
                                    <?php if ($reviewed): ?>
                                        <div class="yw-note"><i class="fas fa-user-check me-1"></i>Checked by your teacher</div>
                                        <?php if (!empty($m['ans_feedback'])): ?><div class="yw-feedback"><?php echo nl2br(htmlspecialchars($m['ans_feedback'])); ?></div><?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!empty($m['ans_submission_id'])): ?>
                                        <div class="yw-note">Turned in <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($m['ans_submitted_at']))); ?></div>
                                        <?php if (!empty($m['ans_answer_filename'])): ?>
                                            <div class="yw-myfile">
                                                <a href="../../ai/view_submission_answer.php?id=<?php echo (int) $m['ans_submission_id']; ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> <?php echo htmlspecialchars($m['ans_answer_filename']); ?></a>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!$reviewed): ?>
                                        <form method="POST" action="submit_resource_answer.php" enctype="multipart/form-data" onsubmit="var b=this.querySelector('button');if(b.disabled)return false;b.disabled=true;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="resource_id" value="<?php echo $rid; ?>">
                                            <input type="hidden" name="return_act" value="<?php echo $mid; ?>">
                                            <label class="yw-btn yw-btn-outline" style="margin-bottom:0;"><i class="fas fa-plus me-1"></i><?php echo !empty($m['ans_answer_filename']) ? 'Replace your file' : 'Add your answered file'; ?>
                                                <input type="file" name="answer_file" hidden accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                                       onchange="this.closest('form').querySelector('.yw-picked').textContent=this.files.length?this.files[0].name:'';">
                                            </label>
                                            <div class="yw-note yw-picked" style="margin:4px 0 0;"></div>
                                            <textarea name="answer_note" rows="2" placeholder="Note for your teacher (optional)"><?php echo htmlspecialchars($prev['note'] ?? ''); ?></textarea>
                                            <button type="submit" class="yw-btn yw-btn-primary"><?php echo !empty($m['ans_submission_id']) ? 'Update Turn In' : 'Turn In'; ?></button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </aside>

                            <?php
                                // Comments on the Word copy (its own message) show here too.
                                $mc_list = $message_comments[$mid] ?? [];
                                foreach ($m['extra_files'] ?? [] as $xf) {
                                    $mc_list = array_merge($mc_list, $message_comments[(int) $xf['message_id']] ?? []);
                                }
                                usort($mc_list, function ($a, $b) { return strcmp((string) $a['created_at'], (string) $b['created_at']); });
                            ?>
                            <div class="msg-comments">
                                <?php foreach ($mc_list as $mc): ?>
                                    <div class="msg-comment <?php echo $mc['author_role'] === 'Teacher' ? 'teacher' : 'student'; ?>">
                                        <b><?php echo htmlspecialchars($mc['author_role'] === 'Teacher' ? ($mc['author_name'] ?: 'Teacher') : 'You'); ?><small><?php echo date('M d, Y g:i A', strtotime($mc['created_at'])); ?></small></b><span><?php echo htmlspecialchars($mc['body']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <form method="POST" action="comment_message.php" class="msg-comment-form" onsubmit="var b=this.querySelector('button');if(b.disabled)return false;b.disabled=true;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="message_id" value="<?php echo $mid; ?>">
                                    <textarea name="comment" rows="1" maxlength="<?php echo MC_MAX_LENGTH; ?>" placeholder="Add a comment or ask your teacher a question..." required></textarea>
                                    <button type="submit" title="Send comment"><i class="fas fa-paper-plane"></i></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($lm_tasks) && !$cw_open_id && !$act_open_id): ?>
                <div class="mb-4" id="quizzes-examinations">
                    <h3 class="lm-section-title"><i class="fas fa-clipboard-list"></i> Assessments &amp; Lessons</h3>
                    <?php foreach ($lm_tasks as $lt):
                        $lt_status = $lt['display_status'];
                        $lt_open = aim_now() >= $lt['start_at'];
                    ?>
                        <div class="lm-card">
                            <div class="lm-card-head">
                                <div>
                                    <div class="lm-card-title"><?php echo htmlspecialchars($lt['title']); ?></div>
                                    <div class="lm-card-meta">
                                        <?php echo htmlspecialchars($lt['kind_label']); ?>
                                        <?php if (!empty($lt['topic'])): ?> · Topic: <?php echo htmlspecialchars($lt['topic']); ?><?php endif; ?>
                                    </div>
                                    <div class="lm-card-meta">
                                        <i class="far fa-clock me-1"></i><?php echo htmlspecialchars(aim_formatDateTime($lt['start_at'])); ?> – <?php echo htmlspecialchars(aim_formatDateTime($lt['end_at'])); ?>
                                    </div>
                                </div>
                                <span class="lm-status <?php echo strtolower($lt_status); ?>"><?php echo htmlspecialchars($lt_status); ?></span>
                            </div>
                            <?php if (!$lt_open): ?>
                                <div class="lm-locked"><i class="fas fa-lock me-1"></i>The learning materials open on <?php echo htmlspecialchars(aim_formatDateTime($lt['start_at'])); ?>.</div>
                            <?php elseif (!empty($lt['materials'])): ?>
                                <div class="lm-files">
                                    <?php foreach ($lt['materials'] as $lm):
                                        $lm_meta = aim_materialMeta($lm['resource_type']);
                                    ?>
                                        <a class="lm-file" href="view_learning_material.php?id=<?php echo (int) $lm['material_id']; ?>" target="_blank" rel="noopener">
                                            <i class="fas <?php echo htmlspecialchars($lm_meta['icon']); ?>"></i>
                                            <span>
                                                <?php echo htmlspecialchars(aim_materialDisplayTitle($lm)); ?>
                                                <?php if (!empty($lm['topic']) && $lm['topic'] !== aim_materialDisplayTitle($lm)): ?><br><small style="margin:0;">Topic: <?php echo htmlspecialchars($lm['topic']); ?></small><?php endif; ?>
                                            </span>
                                            <small><?php echo htmlspecialchars($lm_meta['label']); ?></small>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($cw_open_id || $act_open_id): ?>
                <?php /* one classwork is open: only its details and Your Work */ ?>
            <?php elseif (empty($feed_msgs) && !empty($act_msgs)): ?>
                <?php /* every item is an activity -- they are all in Classwork above */ ?>
            <?php elseif (empty($feed_msgs)): ?>
                <div class="msg-card empty-state">
                    <i class="fas fa-chalkboard"></i>
                    <h5>Nothing here yet</h5>
                    <p class="mb-0">Activities, tasks, and messages from <?php echo htmlspecialchars($teacher_name); ?> will show up here.</p>
                </div>
            <?php else: ?>
                <?php foreach ($feed_msgs as $m): ?>
                    <div class="msg-card" <?php echo !empty($m['attach_resource_id']) ? 'id="resource-' . (int) $m['attach_resource_id'] . '"' : ''; ?>>
                        <span id="msg-<?php echo (int) $m['message_id']; ?>" class="msg-anchor"></span>
                        <div class="msg-header">
                            <div>
                                <div class="msg-teacher"><?php echo htmlspecialchars($m['teacher_first'] . ' ' . $m['teacher_last']); ?></div>
                            </div>
                            <div class="msg-date"><?php echo date('M d, Y g:i A', strtotime($m['sent_at'])); ?></div>
                        </div>
                        <span class="msg-type"><?php echo htmlspecialchars($m['support_type']); ?></span>
                        <div class="msg-text"><?php echo htmlspecialchars($m['final_message']); ?></div>
                        <?php if (!empty($m['attach_resource_id']) && $m['attach_audience'] === 'student' && $is_portal_quiz($m)): ?>
                            <?php
                                $rid = (int) $m['attach_resource_id'];
                                $type_label = $attach_type_labels[$m['attach_resource_type']] ?? 'Quiz';
                                $pq_questions = json_decode((string) ($m['attach_gform_questions_json'] ?? ''), true) ?: [];
                                $pq_max = (int) ($m['attach_gform_max_score'] ?? 0);
                                $pq_flags = $portal_flags[$rid] ?? ['graded' => false, 'retake' => false];
                                $pq_submitted = !empty($m['ans_submission_id']);
                                // Submitted quizzes that count toward a grade are locked: no form, no
                                // button -- only the status and the score -- until the teacher allows a retake.
                                $pq_locked = $pq_submitted && !empty($pq_flags['graded']) && empty($pq_flags['retake']);
                                $pq_can_answer = !$pq_submitted || !$pq_locked;
                            ?>
                            <div class="msg-attachment">
                                <div class="msg-attachment-icon" style="color:#238863;"><i class="fas fa-square-poll-vertical"></i></div>
                                <div class="msg-attachment-info">
                                    <div class="msg-attachment-name"><?php echo htmlspecialchars($m['attach_title']); ?></div>
                                    <div class="msg-attachment-meta">
                                        <?php echo htmlspecialchars(implode(' • ', array_filter([$type_label, count($pq_questions) . ' items', $pq_max ? $pq_max . ' points' : '', !empty($pq_flags['graded']) ? 'One attempt only' : '']))); ?>
                                    </div>
                                    <?php if ($pq_submitted): ?>
                                        <span class="attach-status-pill reviewed"><i class="fas fa-check me-1"></i>Submitted</span>
                                        <div style="margin-top:4px;"><strong style="color:#238863;">Your score: <?php echo htmlspecialchars(spScoreText($m['ans_score'], $m['ans_max_score'])); ?></strong>
                                            <span style="font-size:11.5px;color:var(--portal-gray);"> — submitted <?php echo htmlspecialchars(date('M d, Y g:i A', strtotime($m['ans_submitted_at']))); ?></span></div>
                                        <?php if (!empty($pq_flags['retake'])): ?>
                                            <div style="font-size:12px;color:var(--portal-gray);margin-top:2px;">Your teacher allowed you to retake this quiz.</div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="msg-attachment-actions">
                                    <?php if ($pq_locked): ?>
                                        <span class="btn-attach-action" style="cursor:default;color:var(--portal-gray);"><i class="fas fa-lock me-1"></i>Quiz Completed</span>
                                    <?php else: ?>
                                        <button type="button" class="btn-attach-action" style="background:#14213D;color:#fff;border-color:#14213D;" data-bs-toggle="modal" data-bs-target="#quizModal<?php echo $rid; ?>">
                                            <i class="fas fa-pen me-1"></i><?php echo $pq_submitted ? 'Retake Quiz' : 'Answer Now'; ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!$pq_locked): ?>
                                <?php spQuizModal($rid, $m['attach_title'], $pq_questions, $csrf_token); ?>
                            <?php endif; ?>
                        <?php elseif (!empty($m['attach_resource_id']) && $m['attach_audience'] === 'student' && $m['attach_format'] === 'gform'): ?>
                            <?php
                                $type_label = $attach_type_labels[$m['attach_resource_type']] ?? 'Quiz';
                                $question_count = 0;
                                $decoded_q = json_decode((string) ($m['attach_gform_questions_json'] ?? ''), true);
                                if (is_array($decoded_q)) { $question_count = count($decoded_q); }
                                $max_score = (int) ($m['attach_gform_max_score'] ?? 0);
                                $latest = $gform_scores[(int) $m['attach_resource_id']] ?? null;
                                $one_attempt = (int) ($m['attach_gform_one_attempt'] ?? 0) === 1;
                                $locked = $one_attempt && $latest;
                            ?>
                            <div class="msg-attachment">
                                <div class="msg-attachment-icon" style="color:#238863;">
                                    <i class="fas fa-square-poll-vertical"></i>
                                </div>
                                <div class="msg-attachment-info">
                                    <div class="msg-attachment-name"><?php echo htmlspecialchars($m['attach_title']); ?></div>
                                    <div class="msg-attachment-meta">
                                        <?php echo htmlspecialchars(implode(' • ', array_filter([$type_label, $question_count ? $question_count . ' items' : '', $max_score ? $max_score . ' points' : '', $one_attempt ? 'One attempt only' : '']))); ?>
                                        <?php if ($latest): ?>
                                            <br><span class="attach-status-pill reviewed"><i class="fas fa-check me-1"></i>Submitted</span>
                                            <?php $latest_pct = ((float) $latest['max_score'] > 0) ? round(((float) $latest['score'] / (float) $latest['max_score']) * 100, 1) : null; ?>
                                            <br><strong style="color:#238863;">Your score: <?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $latest['score'], 1), '0'), '.')); ?>/<?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $latest['max_score'], 1), '0'), '.')); ?><?php echo $latest_pct !== null ? ' (' . htmlspecialchars(rtrim(rtrim(number_format($latest_pct, 1), '0'), '.')) . '%)' : ''; ?></strong> — submitted <?php echo htmlspecialchars(date('M d, Y g:i A', strtotime($latest['submitted_at']))); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="msg-attachment-actions">
                                    <?php if ($locked): ?>
                                        <span class="btn-attach-action" style="cursor:default;color:var(--portal-gray);"><i class="fas fa-lock me-1"></i>Quiz Completed</span>
                                    <?php else: ?>
                                        <a href="<?php echo htmlspecialchars($m['attach_gform_view_url']); ?>" target="_blank" rel="noopener" class="btn-attach-action"><i class="fas fa-square-poll-vertical me-1"></i><?php echo $latest ? 'Open Quiz Again' : 'Open Quiz'; ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php elseif (!empty($m['attach_resource_id']) && $m['attach_audience'] === 'student'): ?>
                            <?php
                                $is_docx = $m['attach_format'] === 'docx';
                                $is_pptx = $m['attach_format'] === 'pptx'; // PowerPoint lessons sent by Teacher AI
                                $type_label = $attach_type_labels[$m['attach_resource_type']] ?? 'Learning Resource';
                                $size_kb = $m['attach_size'] ? max(1, round($m['attach_size'] / 1024)) . ' KB' : '';
                                $native_questions = json_decode((string) ($m['attach_gform_questions_json'] ?? ''), true);
                                $has_native_quiz = is_array($native_questions) && count($native_questions) > 0;
                                $rid = (int) $m['attach_resource_id'];
                                $has_submission = !empty($m['ans_submission_id']);
                                $ans_reviewed = $has_submission && $m['ans_status'] === 'Reviewed';
                                // A graded quiz that has been submitted is locked (score only) until the teacher allows a retake.
                                $q_flags = $portal_flags[$rid] ?? ['graded' => false, 'retake' => false];
                                $q_locked = $has_native_quiz && $has_submission && !empty($q_flags['graded']) && empty($q_flags['retake']);
                            ?>
                            <div class="msg-attachment">
                                <div class="msg-attachment-icon <?php echo $is_pptx ? 'pptx' : ($is_docx ? 'docx' : 'pdf'); ?>">
                                    <i class="fas <?php echo $is_pptx ? 'fa-file-powerpoint' : ($is_docx ? 'fa-file-word' : 'fa-file-pdf'); ?>"></i>
                                </div>
                                <div class="msg-attachment-info">
                                    <div class="msg-attachment-name"><?php echo htmlspecialchars($m['attach_filename'] ?: $m['attach_title']); ?></div>
                                    <div class="msg-attachment-meta"><?php echo htmlspecialchars(implode(' • ', array_filter([$type_label, $is_pptx ? 'PowerPoint' : ($is_docx ? 'Microsoft Word' : 'PDF'), $size_kb]))); ?></div>
                                    <?php if ($has_native_quiz && $has_submission): ?>
                                        <span class="attach-status-pill reviewed"><i class="fas fa-check me-1"></i>Submitted</span>
                                        <div><strong style="color:#238863;">Your score: <?php echo htmlspecialchars(spScoreText($m['ans_score'], $m['ans_max_score'])); ?></strong> — answered <?php echo htmlspecialchars(date('M d, Y g:i A', strtotime($m['ans_submitted_at']))); ?></div>
                                        <?php if (!empty($q_flags['retake'])): ?><div style="font-size:12px;color:var(--portal-gray);">Your teacher allowed you to retake this quiz.</div><?php endif; ?>
                                    <?php elseif (!$has_native_quiz && $has_submission): ?>
                                        <div style="font-size:12px;color:var(--portal-gray);"><i class="fas fa-paperclip me-1"></i>You attached: <?php echo htmlspecialchars($m['ans_answer_filename'] ?: 'your answer'); ?></div>
                                        <?php if ($ans_reviewed): ?>
                                            <span class="attach-status-pill reviewed"><i class="fas fa-check me-1"></i>Checked by your teacher</span>
                                        <?php else: ?>
                                            <span class="attach-status-pill waiting"><i class="fas fa-hourglass-half me-1"></i>Waiting for your teacher to check</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="msg-attachment-actions">
                                    <a href="../../ai/view_resource.php?id=<?php echo $rid; ?>" target="_blank" rel="noopener" class="btn-attach-action"><i class="fas fa-eye me-1"></i>View</a>
                                    <a href="../../ai/download_resource.php?id=<?php echo $rid; ?>" class="btn-attach-action"><i class="fas fa-download me-1"></i>Download</a>
                                    <?php if ($is_pptx): ?>
                                        <?php /* A slide deck is study material -- nothing to answer or attach back. */ ?>
                                    <?php elseif ($q_locked): ?>
                                        <span class="btn-attach-action" style="cursor:default;color:var(--portal-gray);"><i class="fas fa-lock me-1"></i>Quiz Completed</span>
                                    <?php elseif ($has_native_quiz): ?>
                                        <button type="button" class="btn-attach-action" style="background:#14213D;color:#fff;border-color:#14213D;" data-bs-toggle="modal" data-bs-target="#quizModal<?php echo $rid; ?>">
                                            <i class="fas fa-pen me-1"></i><?php echo $has_submission ? 'Retake' : 'Answer Now'; ?>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn-attach-action" style="background:#14213D;color:#fff;border-color:#14213D;" data-bs-toggle="modal" data-bs-target="#answerModal<?php echo $rid; ?>">
                                            <i class="fas fa-pen me-1"></i><?php echo $has_submission ? 'View My Answer' : 'Submit Your Answer'; ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($has_native_quiz): ?>
                                <?php if (!$q_locked) { spQuizModal($rid, $m['attach_title'], $native_questions, $csrf_token, $has_submission ? (json_decode((string) ($m['ans_answers_json'] ?? ''), true) ?: []) : []); } ?>
                            <?php else: ?>
                                <?php
                                    $prev_manual = $has_submission ? (json_decode((string) ($m['ans_answers_json'] ?? ''), true) ?: []) : [];
                                    $prev_note = $prev_manual['note'] ?? '';
                                    $prev_filename = $m['ans_answer_filename'] ?? '';
                                ?>
                                <div class="modal fade" id="answerModal<?php echo $rid; ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-scrollable">
                                        <div class="modal-content">
                                            <form method="POST" action="submit_resource_answer.php" enctype="multipart/form-data">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="resource_id" value="<?php echo $rid; ?>">
                                                <div class="modal-header">
                                                    <h5 class="modal-title"><?php echo htmlspecialchars($m['attach_title']); ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body manual-answer-box">
                                                    <p style="font-size:12.5px;color:var(--portal-gray);">
                                                        View or download the activity above, answer it in your own copy (Word doc, printed and written on, or a photo of your paper), then attach that answered file here. This kind of activity has no single correct answer to auto-check, so your teacher will open it and check it themselves.
                                                    </p>

                                                    <?php if ($prev_filename): ?>
                                                        <div style="display:flex;align-items:center;gap:8px;background:#F8FAFC;border:1px solid var(--portal-border);border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:13px;">
                                                            <i class="fas fa-paperclip"></i>
                                                            <span style="flex:1;word-break:break-word;"><?php echo htmlspecialchars($prev_filename); ?></span>
                                                            <a href="../../ai/view_submission_answer.php?id=<?php echo (int) $m['ans_submission_id']; ?>" target="_blank" rel="noopener" style="font-size:12px;">View</a>
                                                            <a href="../../ai/download_submission_answer.php?id=<?php echo (int) $m['ans_submission_id']; ?>" style="font-size:12px;">Download</a>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!$ans_reviewed): ?>
                                                        <label class="form-label" style="font-size:12.5px;font-weight:600;"><?php echo $prev_filename ? 'Replace attached file (optional)' : 'Attach your answered file'; ?></label>
                                                        <input type="file" name="answer_file" class="form-control mb-2" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                                        <label class="form-label" style="font-size:12.5px;font-weight:600;">Note for your teacher (optional)</label>
                                                        <textarea name="answer_note" class="form-control" rows="3" placeholder="Anything you want your teacher to know..."><?php echo htmlspecialchars($prev_note); ?></textarea>
                                                    <?php elseif ($prev_note): ?>
                                                        <p class="text-muted mb-1" style="font-size:12.5px;">Your note:</p>
                                                        <div style="white-space:pre-wrap;font-size:13.5px;margin-bottom:10px;"><?php echo nl2br(htmlspecialchars($prev_note)); ?></div>
                                                    <?php endif; ?>

                                                    <?php if ($ans_reviewed): ?>
                                                        <div class="mt-3">
                                                            <span class="attach-status-pill reviewed"><i class="fas fa-check me-1"></i>Checked by your teacher</span>
                                                            <?php if (!empty($m['ans_feedback'])): ?>
                                                                <div class="mt-2" style="font-size:13px;background:#F8FAFC;border:1px solid var(--portal-border);border-radius:8px;padding:10px 12px;">
                                                                    <strong>Teacher's feedback:</strong><br><?php echo nl2br(htmlspecialchars($m['ans_feedback'])); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php elseif ($has_submission): ?>
                                                        <div class="mt-2"><span class="attach-status-pill waiting"><i class="fas fa-hourglass-half me-1"></i>Waiting for your teacher to check</span></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-sm" style="border:1px solid var(--portal-border);background:#fff;" data-bs-dismiss="modal">Close</button>
                                                    <?php if (!$ans_reviewed): ?>
                                                        <button type="submit" class="btn btn-sm" style="background:#14213D;color:#fff;"><?php echo $has_submission ? 'Update Submission' : 'Submit for Checking'; ?></button>
                                                    <?php endif; ?>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php $mc_list = $message_comments[(int) $m['message_id']] ?? []; ?>
                        <div class="msg-comments">
                            <?php foreach ($mc_list as $mc): ?>
                                <div class="msg-comment <?php echo $mc['author_role'] === 'Teacher' ? 'teacher' : 'student'; ?>">
                                    <b><?php echo htmlspecialchars($mc['author_role'] === 'Teacher' ? ($mc['author_name'] ?: 'Teacher') : 'You'); ?><small><?php echo date('M d, Y g:i A', strtotime($mc['created_at'])); ?></small></b><span><?php echo htmlspecialchars($mc['body']); ?></span>
                                </div>
                            <?php endforeach; ?>
                            <form method="POST" action="comment_message.php" class="msg-comment-form" onsubmit="var b=this.querySelector('button');if(b.disabled)return false;b.disabled=true;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="message_id" value="<?php echo (int) $m['message_id']; ?>">
                                <textarea name="comment" rows="1" maxlength="<?php echo MC_MAX_LENGTH; ?>" placeholder="Add a comment or ask your teacher a question..." required></textarea>
                                <button type="submit" title="Send comment"><i class="fas fa-paper-plane"></i></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sidebar toggle + topbar profile dropdown live in the shared
// static/js/student-portal-shell.js (linked in <head>).

// Links to an activity's old place in the feed (#msg-<id> / #resource-<id>,
// e.g. from a notification or after a comment) open its Classwork page instead.
(function () {
    var params = new URLSearchParams(location.search);
    if (params.get('act') || params.get('cw')) return;
    var map = <?php
        $act_map = [];
        foreach ($act_msgs as $am) {
            $act_map['msg-' . (int) $am['message_id']] = (int) $am['message_id'];
            $act_map['resource-' . (int) $am['attach_resource_id']] = (int) $am['message_id'];
            foreach ($am['extra_files'] ?? [] as $xf) {
                $act_map['msg-' . (int) $xf['message_id']] = (int) $am['message_id'];
                $act_map['resource-' . (int) $xf['attach_resource_id']] = (int) $am['message_id'];
            }
        }
        echo json_encode((object) $act_map);
    ?>;
    var key = location.hash.replace('#', '');
    if (map[key]) {
        params.set('act', map[key]);
        location.replace('subject.php?' + params.toString());
    }
})();

// A quiz form is sent once: disable the submit button after the first click so a
// double-click cannot post it twice. (The server enforces one attempt anyway.)
document.querySelectorAll('form[data-one-shot]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        // A quiz is only sent when EVERY question has an answer.
        if (form.hasAttribute('data-quiz-form')) {
            var blocks = form.querySelectorAll('.quiz-question-block');
            var missing = [];
            blocks.forEach(function (block, i) {
                var answered = !!block.querySelector('input[type="radio"]:checked');
                block.classList.toggle('is-missing', !answered);
                if (!answered) missing.push(i + 1);
            });
            var msg = form.querySelector('.quiz-missing-msg');
            if (missing.length) {
                e.preventDefault();
                if (msg) {
                    msg.textContent = 'Please answer all questions first. Unanswered: ' + (missing.length > 8 ? missing.slice(0, 8).join(', ') + '\u2026' : missing.join(', ')) + ' (' + missing.length + ' left)';
                    msg.hidden = false;
                }
                var first = form.querySelector('.quiz-question-block.is-missing');
                if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            if (msg) msg.hidden = true;
        }
        var btn = form.querySelector('button[type="submit"]');
        if (btn) { btn.disabled = true; btn.textContent = 'Submitting...'; }
    });
    // Clear the red mark as soon as a missing question gets an answer.
    form.addEventListener('change', function (e) {
        var block = e.target.closest ? e.target.closest('.quiz-question-block') : null;
        if (block) block.classList.remove('is-missing');
        var msg = form.querySelector('.quiz-missing-msg');
        if (msg && !form.querySelector('.quiz-question-block.is-missing')) msg.hidden = true;
    });
});
</script>
</body>
</html>
