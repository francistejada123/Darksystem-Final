<?php
// ============================================================
// >>> THIS FILE GOES IN:  modules/teachers/classroom.php  <<<
// ============================================================
// modules/teachers/classroom.php
// The Google-Classroom-style "classroom" page for ONE admin-assigned class
// (Teacher + Section + Subject + School Year + Term). Reached from the new
// class cards on the Teacher Dashboard (index.php) and from the Classwork/
// Stream/People links elsewhere.
//
// ACCESS RULE (same one already enforced everywhere else in this app):
// Teacher -> Assigned Section -> Assigned Subject -> Assigned Term, via
// teacher_subjects. classroomValidateAccess() (includes/classroom_helpers.php)
// is the ONLY place that decides this, re-checked on every GET and every
// POST here -- never trusted just because an earlier request already
// checked it, and never derived from grade level/strand alone.
//
// This page does not touch grading, assessment, attendance, or any
// existing table other than teacher_subjects/subjects/sections/students/
// enrollments (read-only). It only writes to the five new tables added by
// config/database.php's migration_2026_classroom_ui_v1 block.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';
require_once __DIR__ . '/../../includes/classroom_helpers.php';
require_once __DIR__ . '/../../includes/ai_document_generator.php'; // aiGenerateResourceFile(), aiResourceTypeLabel()
require_once __DIR__ . '/../../includes/classroom_monitoring_helpers.php'; // online quizzes/activities + student comments (read-only)
require_once __DIR__ . '/../../includes/classwork_helpers.php';   // assessment types -> WW / PT / QA, Your Work submissions, scores
require_once __DIR__ . '/../../includes/ai_assessment_items.php'; // aiAssessmentEnsureItem(), aiAssessmentClassMeta()
require_once __DIR__ . '/../../includes/classwork_comment_helpers.php'; // class + private comments on classwork
require_once __DIR__ . '/../../includes/classroom_post_manage.php';      // (...) menu: edit / delete / move a post or classwork
classworkEnsureSchema();
cpmEnsureSchema();

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Teacher')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Classroom';
$current_page = 'teachers';

$teacher_id = (int) $_SESSION['user_id'];
$teacher = getRecord("SELECT * FROM users WHERE user_id = ?", [$teacher_id]);

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
$school_year_id = $active_sy['sy_id'] ?? 0;

$section_id = (int) ($_GET['section_id'] ?? 0);
$subject_code = isset($_GET['subject_code']) ? sanitize($_GET['subject_code']) : '';

$all_terms = gh_threeTerms();
$current_term = gh_getCurrentTerm($school_year_id);
// The active term is controlled by the administrator's date windows.
// Keep the value internally for access checks and writes, but do not expose
// a teacher-facing term picker or allow a URL parameter to override it.
$term = $current_term ?? $all_terms[0];

$tab = in_array(($_GET['tab'] ?? ''), ['stream', 'classwork', 'people'], true) ? $_GET['tab'] : 'stream';

$access = classroomValidateAccess($teacher_id, $section_id, $subject_code, $school_year_id, $term);
$authorized = $access['success'];
$class = $access['class'] ?? null;
$class_schedule = [];
if ($authorized) {
    $class_schedule = getRecords("SELECT day_of_week, TIME_FORMAT(start_time, '%H:%i') AS start_time, TIME_FORMAT(end_time, '%H:%i') AS end_time FROM teacher_class_schedule WHERE teacher_id=? AND subject_code=? AND section_id=? AND school_year_id=? AND is_active=1 ORDER BY day_of_week, start_time", [$teacher_id, $subject_code, $section_id, $school_year_id]);
}

// ============================================================
// POST HANDLERS — both re-validate access independently from the POSTed
// class-identifying fields (never from $_GET, never from an earlier check
// in this same request) before writing anything.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_section_id = (int) ($_POST['section_id'] ?? 0);
    $post_subject_code = isset($_POST['subject_code']) ? sanitize($_POST['subject_code']) : '';
    $post_sy_id = (int) ($_POST['school_year_id'] ?? 0);
    $post_term = isset($_POST['term']) ? sanitize($_POST['term']) : '';
    $redirect_qs = 'section_id=' . $post_section_id . '&subject_code=' . urlencode($post_subject_code) . '&term=' . urlencode($post_term);

    $post_check = classroomValidateAccess($teacher_id, $post_section_id, $post_subject_code, $post_sy_id, $post_term);

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token. Please try again.';
        header('Location: classroom.php?' . $redirect_qs . '&tab=' . (in_array(($_POST['form_action'] ?? ''), ['create_classwork', 'grade_classwork', 'ai_check_classwork'], true) ? 'classwork' : 'stream'));
        exit;
    }

    if (!$post_check['success']) {
        $_SESSION['error_message'] = $post_check['error'];
        header('Location: classroom.php?' . $redirect_qs);
        exit;
    }
    $post_class = $post_check['class'];

    // ---- Post an announcement (Stream tab) ----
    if (($_POST['form_action'] ?? '') === 'post_announcement') {
        $body = trim((string) ($_POST['body'] ?? ''));
        $link_url = trim((string) ($_POST['link_url'] ?? ''));
        $link_url = ($link_url !== '' && filter_var($link_url, FILTER_VALIDATE_URL)) ? $link_url : '';

        $has_files = !empty($_FILES['attachments']['name'][0] ?? '');

        if ($body === '' && $link_url === '' && !$has_files) {
            $_SESSION['error_message'] = 'Write something, add a link, or attach a file before posting.';
            header('Location: classroom.php?' . $redirect_qs . '&tab=stream#stream');
            exit;
        }
        if (mb_strlen($body) > 5000) {
            $_SESSION['error_message'] = 'That announcement is too long (max 5000 characters).';
            header('Location: classroom.php?' . $redirect_qs . '&tab=stream#stream');
            exit;
        }

        $post_id = insertRecord(
            "INSERT INTO class_stream_posts (teacher_id, subject_code, section_id, school_year_id, term, body)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$teacher_id, $post_subject_code, $post_section_id, $post_sy_id, $post_term ?: null, $body]
        );

        if ($post_id) {
            if ($link_url !== '') {
                insertRecord(
                    "INSERT INTO class_stream_attachments (post_id, original_filename, attachment_type, external_url)
                     VALUES (?, ?, 'link', ?)",
                    [$post_id, $link_url, $link_url]
                );
            }
            if ($has_files) {
                $count = count($_FILES['attachments']['name']);
                for ($i = 0; $i < $count; $i++) {
                    if (($_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                    $file = [
                        'name' => $_FILES['attachments']['name'][$i],
                        'type' => $_FILES['attachments']['type'][$i],
                        'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                        'error' => $_FILES['attachments']['error'][$i],
                        'size' => $_FILES['attachments']['size'][$i],
                    ];
                    $saved = classroomSaveUpload($file, 'stream');
                    if ($saved['success']) {
                        insertRecord(
                            "INSERT INTO class_stream_attachments
                                (post_id, original_filename, stored_filename, mime_type, file_size, attachment_type)
                             VALUES (?, ?, ?, ?, ?, 'file')",
                            [$post_id, $saved['original_filename'], $saved['stored_filename'], $saved['mime_type'], $saved['file_size']]
                        );
                    }
                }
            }
            logActivity($teacher_id, 'Classroom', 'Posted announcement', "Post #$post_id, subject $post_subject_code, section #$post_section_id");
            // Email every student of this section (once per student per post).
            require_once __DIR__ . '/../../includes/student_email_notify.php';
            $ann_roster = classroomSectionStudents($post_section_id, $post_sy_id);
            $ann_files = array_column(getRecords("SELECT original_filename FROM class_stream_attachments WHERE post_id = ?", [(int) $post_id]), 'original_filename');
            $ann_note = '';
            if ($ann_roster) {
                senNotifyStudents(array_column($ann_roster, 'student_id'), [
                    'key' => 'announce:' . (int) $post_id,
                    'subject_code' => $post_subject_code, 'teacher_id' => $teacher_id,
                    'title' => 'Announcement', 'kind' => 'Announcement',
                    'announcement' => $body !== '' ? $body : ($ann_files ? 'Your teacher shared ' . count($ann_files) . ' file(s)/link(s).' : ''),
                    'attachments' => $ann_files,
                    'link' => 'modules/students/portal/subject.php?subject=' . rawurlencode($post_subject_code),
                    'send_now' => true,
                ]);
                $ann = senLastResult();
                $ann_bad = (int) $ann['failed'] + (int) $ann['no_email'];
                $ann_note = !empty($ann['switched_off'])
                    ? ' Email notifications are switched off in Settings › Email Settings.'
                    : ' Email sent to ' . (int) $ann['sent'] . ' of ' . count($ann_roster) . ' student(s).'
                        . ($ann_bad > 0 ? ' ' . $ann_bad . ' failed' . ($ann['reason'] !== '' ? ': ' . $ann['reason'] : ((int) $ann['no_email'] > 0 ? ' (no registered email).' : '.')) : '');
            }
            $_SESSION['success_message'] = 'Announcement posted.' . $ann_note;
            if ($ann_roster && (int) ($ann['sent'] ?? 0) === 0 && $ann_bad > 0) {
                $_SESSION['error_message'] = 'The announcement was posted, but no email was sent:' . ($ann['reason'] !== '' ? ' ' . $ann['reason'] : ' the students have no registered email.');
            }
        } else {
            $_SESSION['error_message'] = 'Could not post the announcement. Please try again.';
        }

        header('Location: classroom.php?' . $redirect_qs . '&tab=stream#stream');
        exit;
    }

    // ---- Score a student's work (Classwork tab) -> Grade Management ----
    if (($_POST['form_action'] ?? '') === 'grade_classwork') {
        $aid = (int) ($_POST['assignment_id'] ?? 0);
        $owned = getRecord("SELECT assignment_id FROM classwork_assignments WHERE assignment_id = ? AND teacher_id = ? AND section_id = ? AND subject_code = ?", [$aid, $teacher_id, $post_section_id, $post_subject_code]);
        $r = $owned ? classworkRecordScore($teacher_id, $aid, (int) ($_POST['student_id'] ?? 0), $_POST['score'] ?? '', $_POST['feedback'] ?? '')
                    : ['success' => false, 'error' => 'That assignment could not be found.'];
        if ($r['success']) {
            $_SESSION['success_message'] = 'Score saved. It is recorded in Grade Management under this assignment\'s category.';
        } else {
            $_SESSION['error_message'] = $r['error'];
        }
        header('Location: classroom.php?' . $redirect_qs . '&tab=classwork&open=' . $aid . '#cw-' . $aid);
        exit;
    }

    // ---- Check turned-in work with AI (one student, or everyone not yet checked) ----
    if (($_POST['form_action'] ?? '') === 'ai_check_classwork') {
        $aid = (int) ($_POST['assignment_id'] ?? 0);
        $owned = getRecord("SELECT assignment_id FROM classwork_assignments WHERE assignment_id = ? AND teacher_id = ? AND section_id = ? AND subject_code = ?", [$aid, $teacher_id, $post_section_id, $post_subject_code]);
        $done = 0;
        $errors = [];
        if ($owned) {
            $sid = (int) ($_POST['student_id'] ?? 0);
            $targets = $sid > 0
                ? [$sid]
                : array_column(getRecords("SELECT student_id FROM classwork_assignment_students WHERE assignment_id = ? AND status = 'Submitted'", [$aid]), 'student_id');
            @set_time_limit(60 + 90 * max(1, count($targets)));
            foreach ($targets as $t) {
                $r = classworkAiCheck($aid, (int) $t);
                if ($r['success']) { $done++; } else { $errors[] = $r['error']; }
            }
        }
        if ($done) {
            $_SESSION['success_message'] = 'AI checked ' . $done . ' submission(s) and recorded the scores. Open the work to review and change any score.';
        }
        if ($errors || !$done) {
            $_SESSION['error_message'] = $errors ? implode(' ', array_unique($errors)) : 'Nothing to check yet.';
        }
        header('Location: classroom.php?' . $redirect_qs . '&tab=classwork&open=' . $aid . '#cw-' . $aid);
        exit;
    }

    // ---- (...) menu on a post / classwork: Edit, Delete, Move to another class ----
    $pm_action = (string) ($_POST['form_action'] ?? '');
    if (in_array($pm_action, ['pm_edit_post', 'pm_delete_post', 'pm_move_post', 'pm_edit_classwork', 'pm_delete_classwork', 'pm_move_classwork'], true)) {
        require_once __DIR__ . '/../../includes/student_email_notify.php';
        $pm_back = '&tab=' . (($_POST['return_tab'] ?? '') === 'classwork' ? 'classwork#classwork' : 'stream#stream');
        $pm_done = function ($msg, $ok = true) use ($redirect_qs, $pm_back) {
            $_SESSION[$ok ? 'success_message' : 'error_message'] = $msg;
            header('Location: classroom.php?' . $redirect_qs . $pm_back);
            exit;
        };
        // "Email sent to 3 of 3 student(s)." for an update / move notice
        $pm_notify = function (array $studentIds, array $info) {
            if (!$studentIds) return ' No students to email.';
            senNotifyStudents($studentIds, $info + ['send_now' => true]);
            $r = senLastResult();
            $bad = (int) $r['failed'] + (int) $r['no_email'];
            return ' Email sent to ' . (int) $r['sent'] . ' of ' . count($studentIds) . ' student(s).' . ($bad ? ' ' . $bad . ' failed' . ($r['reason'] !== '' ? ': ' . $r['reason'] : '.') : '');
        };
        $pm_target = explode('|', (string) ($_POST['target_class'] ?? ''), 2);
        $pm_to_section = (int) ($pm_target[0] ?? 0);
        $pm_to_subject = (string) ($pm_target[1] ?? '');

        if (in_array($pm_action, ['pm_edit_post', 'pm_delete_post', 'pm_move_post'], true)) {
            $pm_post = getRecord("SELECT * FROM class_stream_posts WHERE post_id = ? AND teacher_id = ? AND section_id = ? AND subject_code = ? AND is_deleted = 0",
                [(int) ($_POST['post_id'] ?? 0), $teacher_id, $post_section_id, $post_subject_code]);
            if (!$pm_post) $pm_done('That post could not be found (only the teacher who posted it can change it).', false);
            $pid = (int) $pm_post['post_id'];

            if ($pm_action === 'pm_delete_post') {
                executeQuery("UPDATE class_stream_posts SET is_deleted = 1 WHERE post_id = ?", [$pid]);
                logActivity($teacher_id, 'Classroom', 'Deleted announcement', "Post #$pid, subject $post_subject_code, section #$post_section_id");
                $pm_done('The post was deleted. Students no longer see it.');
            }
            if ($pm_action === 'pm_move_post') {
                if (!cpmOwnsClass($teacher_id, $pm_post['school_year_id'], $pm_to_section, $pm_to_subject)) $pm_done('Choose one of your own classes to move the post to.', false);
                executeQuery("UPDATE class_stream_posts SET section_id = ?, subject_code = ? WHERE post_id = ?", [$pm_to_section, $pm_to_subject, $pid]);
                $cls = getRecord("SELECT sec.section_name, sub.subject_name FROM sections sec, subjects sub WHERE sec.section_id = ? AND sub.subject_code = ?", [$pm_to_section, $pm_to_subject]);
                logActivity($teacher_id, 'Classroom', 'Moved announcement', "Post #$pid from section #$post_section_id $post_subject_code to section #$pm_to_section $pm_to_subject");
                $note = '';
                if (!empty($_POST['notify'])) {
                    $note = $pm_notify(array_column(classroomSectionStudents($pm_to_section, $pm_post['school_year_id']), 'student_id'), [
                        'key' => 'announce:' . $pid . ':moved' . $pm_to_section, 'subject_code' => $pm_to_subject, 'teacher_id' => $teacher_id,
                        'title' => 'Announcement', 'kind' => 'Announcement', 'announcement' => $pm_post['body'],
                        'link' => 'modules/students/portal/subject.php?subject=' . rawurlencode($pm_to_subject),
                    ]);
                }
                $pm_done('The post was moved to ' . ($cls['subject_name'] ?? $pm_to_subject) . ' – ' . ($cls['section_name'] ?? 'the other section') . '.' . $note);
            }
            // edit
            $body = trim((string) ($_POST['body'] ?? ''));
            if (mb_strlen($body) > 5000) $pm_done('That announcement is too long (max 5000 characters).', false);
            $link = trim((string) ($_POST['link_url'] ?? ''));
            $link = ($link !== '' && filter_var($link, FILTER_VALIDATE_URL)) ? $link : '';
            cpmRemoveAttachments('stream', $pid, (array) ($_POST['remove_attachments'] ?? []));
            executeQuery("DELETE FROM class_stream_attachments WHERE post_id = ? AND attachment_type = 'link'", [$pid]);
            if ($link !== '') {
                insertRecord("INSERT INTO class_stream_attachments (post_id, original_filename, attachment_type, external_url) VALUES (?, ?, 'link', ?)", [$pid, $link, $link]);
            }
            cpmSaveUploads('new_attachments', 'stream', $pid);
            $left = getRecord("SELECT COUNT(*) AS n FROM class_stream_attachments WHERE post_id = ?", [$pid]);
            if ($body === '' && !(int) $left['n']) $pm_done('The post needs some text, a link or a file. Nothing else was changed except the attachments.', false);
            executeQuery("UPDATE class_stream_posts SET body = ? WHERE post_id = ?", [$body, $pid]);
            logActivity($teacher_id, 'Classroom', 'Edited announcement', "Post #$pid, subject $post_subject_code, section #$post_section_id");
            $note = '';
            if (!empty($_POST['notify'])) {
                $files = array_column(getRecords("SELECT original_filename FROM class_stream_attachments WHERE post_id = ?", [$pid]), 'original_filename');
                $note = $pm_notify(array_column(classroomSectionStudents($post_section_id, $pm_post['school_year_id']), 'student_id'), [
                    'key' => 'announce:' . $pid . ':u' . time(), 'subject_code' => $post_subject_code, 'teacher_id' => $teacher_id,
                    'title' => 'Announcement', 'kind' => 'Announcement', 'announcement' => $body, 'attachments' => $files, 'updated' => true,
                    'link' => 'modules/students/portal/subject.php?subject=' . rawurlencode($post_subject_code),
                ]);
            }
            $pm_done('The post was updated.' . $note);
        }

        // ---- classwork ----
        $pm_cw = getRecord("SELECT * FROM classwork_assignments WHERE assignment_id = ? AND teacher_id = ? AND section_id = ? AND subject_code = ? AND status <> 'Deleted'",
            [(int) ($_POST['assignment_id'] ?? 0), $teacher_id, $post_section_id, $post_subject_code]);
        if (!$pm_cw) $pm_done('That classwork could not be found (only the teacher who posted it can change it).', false);
        $aid = (int) $pm_cw['assignment_id'];
        $links = cpmClassworkLinks($pm_cw);

        if ($pm_action === 'pm_delete_classwork') {
            executeQuery("UPDATE classwork_assignments SET status = 'Deleted' WHERE assignment_id = ?", [$aid]);
            $kept = [];
            if ($links['submitted'] || $links['files']) $kept[] = $links['submitted'] . ' turned-in work';
            if ($links['scored'] || $links['item_scores']) $kept[] = max($links['scored'], $links['item_scores']) . ' score(s)';
            $itemNote = '';
            if (!empty($pm_cw['assessment_item_id'])) {
                if (!empty($_POST['remove_item']) && !$links['item_scores'] && !$links['scored']) {
                    executeQuery("DELETE FROM assessment_items WHERE item_id = ? AND teacher_id = ?", [(int) $pm_cw['assessment_item_id'], $teacher_id]);
                    $itemNote = ' Its item in Manage Assessment Items was removed too (it had no scores).';
                } else {
                    $itemNote = ' Its item stays in Manage Assessment Items' . (($links['item_scores'] || $links['scored']) ? ' with the scores' : '') . ' — remove it there if it is not needed.';
                }
            }
            logActivity($teacher_id, 'Classroom', 'Deleted classwork', "Assignment #$aid ({$pm_cw['title']}), subject $post_subject_code, section #$post_section_id" . ($kept ? '; kept: ' . implode(', ', $kept) : ''));
            $pm_done('"' . html_entity_decode($pm_cw['title'], ENT_QUOTES, 'UTF-8') . '" was deleted from the classroom; students no longer see it.'
                . ($kept ? ' Kept (not deleted): ' . implode(', ', $kept) . '.' : '') . $itemNote);
        }

        if ($pm_action === 'pm_move_classwork') {
            if ($links['submitted'] || $links['files'] || $links['scored'] || $links['item_scores']) {
                $pm_done('This classwork cannot be moved: students already turned in work or have scores. Delete it and post it again in the right class if needed.', false);
            }
            if (!cpmOwnsClass($teacher_id, $pm_cw['school_year_id'], $pm_to_section, $pm_to_subject)) $pm_done('Choose one of your own classes to move the classwork to.', false);
            $item = !empty($pm_cw['assessment_item_id']) ? getRecord("SELECT * FROM assessment_items WHERE item_id = ?", [(int) $pm_cw['assessment_item_id']]) : null;
            if ($item && $pm_to_subject !== $pm_cw['subject_code'] && trim((string) $item['component']) !== '') {
                $pm_done('This classwork belongs to the "' . $item['component'] . '" component of ' . $pm_cw['subject_code'] . ', so it cannot move to another subject. Post it again in the other subject instead.', false);
            }
            executeQuery("UPDATE classwork_assignments SET section_id = ?, subject_code = ? WHERE assignment_id = ?", [$pm_to_section, $pm_to_subject, $aid]);
            if ($item) {
                executeQuery("UPDATE assessment_items SET section_id = ?, subject_code = ? WHERE item_id = ?", [$pm_to_section, $pm_to_subject, (int) $item['item_id']]);
            }
            executeQuery("DELETE FROM classwork_assignment_students WHERE assignment_id = ?", [$aid]);
            $new_roster = classroomSectionStudents($pm_to_section, $pm_cw['school_year_id']);
            foreach ($new_roster as $st) {
                executeQuery("INSERT IGNORE INTO classwork_assignment_students (assignment_id, student_id, status, assigned_at) VALUES (?, ?, 'Assigned', NOW())", [$aid, (int) $st['student_id']]);
            }
            $cls = getRecord("SELECT sec.section_name, sub.subject_name FROM sections sec, subjects sub WHERE sec.section_id = ? AND sub.subject_code = ?", [$pm_to_section, $pm_to_subject]);
            logActivity($teacher_id, 'Classroom', 'Moved classwork', "Assignment #$aid from section #$post_section_id $post_subject_code to section #$pm_to_section $pm_to_subject");
            $note = '';
            if (!empty($_POST['notify'])) {
                $pm_cw['subject_code'] = $pm_to_subject;
                $note = $pm_notify(array_column($new_roster, 'student_id'), [
                    'key' => 'classwork:' . $aid . ':moved' . $pm_to_section, 'subject_code' => $pm_to_subject, 'teacher_id' => $teacher_id,
                    'title' => $pm_cw['title'], 'kind' => $pm_cw['category'], 'grading' => classworkGradingCategory($pm_cw['category']),
                    'deadline' => $pm_cw['due_date'], 'instruction' => $pm_cw['instructions'], 'topic' => $pm_cw['topic'] ?? '',
                    'link' => 'modules/students/portal/subject.php?subject=' . rawurlencode($pm_to_subject) . '&cw=' . $aid,
                ]);
            }
            $pm_done('"' . html_entity_decode($pm_cw['title'], ENT_QUOTES, 'UTF-8') . '" was moved to ' . ($cls['subject_name'] ?? $pm_to_subject) . ' – ' . ($cls['section_name'] ?? 'the other section')
                . ' and assigned to its ' . count($new_roster) . ' student(s).' . $note);
        }

        // edit classwork
        $title = trim((string) ($_POST['title'] ?? ''));
        $instructions = trim((string) ($_POST['instructions'] ?? ''));
        if ($title === '' || $instructions === '') $pm_done('A classwork needs a title and instructions.', false);
        $title = mb_substr($title, 0, 200);
        $instructions = mb_substr($instructions, 0, 12000);
        $topic = mb_substr(trim((string) ($_POST['topic'] ?? '')), 0, 200);
        $due_raw = trim((string) ($_POST['due_date'] ?? ''));
        $due = ($due_raw !== '' && strtotime($due_raw) !== false) ? date('Y-m-d H:i:s', strtotime($due_raw)) : null;
        $lock = (!empty($_POST['lock_after_due']) && $due !== null) ? 1 : 0;
        executeQuery(
            "UPDATE classwork_assignments SET title = ?, instructions = ?, topic = ?, due_date = ?, lock_after_due = ? WHERE assignment_id = ?",
            [$title, $instructions, $topic !== '' ? $topic : null, $due, $lock, $aid]
        );
        if (!empty($pm_cw['assessment_item_id']) && $title !== html_entity_decode($pm_cw['title'], ENT_QUOTES, 'UTF-8')) {
            // keep the Manage Assessment Items name the same as the classwork
            executeQuery("UPDATE assessment_items SET item_name = ? WHERE item_id = ? AND teacher_id = ?", [$title, (int) $pm_cw['assessment_item_id'], $teacher_id]);
        }
        cpmRemoveAttachments('classwork', $aid, (array) ($_POST['remove_attachments'] ?? []));
        cpmSaveUploads('new_attachments', 'classwork', $aid);
        logActivity($teacher_id, 'Classroom', 'Edited classwork', "Assignment #$aid ($title), subject $post_subject_code, section #$post_section_id");
        $note = '';
        if (!empty($_POST['notify'])) {
            $note = $pm_notify(array_column(getRecords("SELECT student_id FROM classwork_assignment_students WHERE assignment_id = ?", [$aid]), 'student_id'), [
                'key' => 'classwork:' . $aid . ':u' . time(), 'subject_code' => $post_subject_code, 'teacher_id' => $teacher_id,
                'title' => $title, 'kind' => $pm_cw['category'], 'grading' => classworkGradingCategory($pm_cw['category']),
                'deadline' => $due, 'instruction' => $instructions, 'topic' => $topic, 'updated' => true,
                'link' => 'modules/students/portal/subject.php?subject=' . rawurlencode($post_subject_code) . '&cw=' . $aid,
            ]);
        }
        $pm_done('"' . $title . '" was updated.' . $note);
    }

    // ---- Classwork comments: class (everyone) or private (teacher + one student) ----
    if (in_array(($_POST['form_action'] ?? ''), ['classwork_comment', 'classwork_comments_seen'], true)) {
        $aid = (int) ($_POST['assignment_id'] ?? 0);
        $owned = getRecord("SELECT assignment_id, title, subject_code, school_year_id FROM classwork_assignments WHERE assignment_id = ? AND teacher_id = ? AND section_id = ? AND subject_code = ?", [$aid, $teacher_id, $post_section_id, $post_subject_code]);
        if ($_POST['form_action'] === 'classwork_comments_seen') {
            if ($owned) cwcMarkSeen($aid);
            header('Content-Type: application/json');
            echo json_encode(['success' => (bool) $owned]);
            exit;
        }
        $vis = ($_POST['visibility'] ?? '') === 'private' ? 'private' : 'class';
        $sid = (int) ($_POST['student_id'] ?? 0);
        $back = '&tab=classwork&open=' . $aid . ($vis === 'private' ? '&pc=' . $sid : '&cwc=1') . '#cw-' . $aid;
        if (!$owned || ($vis === 'private' && !getRecord("SELECT 1 FROM classwork_assignment_students WHERE assignment_id = ? AND student_id = ?", [$aid, $sid]))) {
            $_SESSION['error_message'] = 'That classwork could not be found.';
        } elseif (!cwcAdd($aid, $vis, $vis === 'private' ? $sid : null, 'Teacher', $teacher_id, $_POST['body'] ?? '')) {
            $_SESSION['error_message'] = 'Write a comment first.';
        } else {
            cwcMarkSeen($aid);
            if ($vis === 'private') {
                // The student's portal bell: the teacher answered privately.
                executeQuery(
                    "INSERT INTO student_support_messages
                        (teacher_id, student_id, subject_code, school_year_id, support_type, final_message, source, status, is_seen, sent_at)
                     VALUES (?, ?, ?, ?, 'Classwork', ?, 'classwork', 'Sent', 0, NOW())",
                    [$teacher_id, $sid, $owned['subject_code'], (int) $owned['school_year_id'],
                     'Your teacher left a private comment on "' . html_entity_decode($owned['title'], ENT_QUOTES, 'UTF-8') . '". Open it in Classwork to read and reply.']
                );
            }
        }
        header('Location: classroom.php?' . $redirect_qs . $back);
        exit;
    }

    // ---- Retry what failed after posting: the students' emails, or the Google Form ----
    if (in_array(($_POST['form_action'] ?? ''), ['retry_classwork_email', 'retry_classwork_gform'], true)) {
        $aid = (int) ($_POST['assignment_id'] ?? 0);
        $owned = getRecord("SELECT assignment_id FROM classwork_assignments WHERE assignment_id = ? AND teacher_id = ? AND section_id = ? AND subject_code = ?", [$aid, $teacher_id, $post_section_id, $post_subject_code]);
        if (!$owned) {
            $_SESSION['error_message'] = 'That assignment could not be found.';
        } elseif ($_POST['form_action'] === 'retry_classwork_gform') {
            $gf = classworkCreateGoogleForm($aid);
            if ($gf['success']) {
                $_SESSION['success_message'] = 'The Google Form was created and attached to the assignment.';
            } else {
                $_SESSION['error_message'] = 'The Google Form could not be created: ' . $gf['error'];
            }
        } else {
            require_once __DIR__ . '/../../includes/student_email_notify.php';
            senEnsureSchema();
            $n = executeQuery("UPDATE student_email_notifications SET status = 'Queued', error = NULL WHERE notify_key = ? AND status IN ('Failed','Skipped')", ['classwork:' . $aid]);
            $n = $n ? $n->rowCount() : 0;
            if ($n > 0) {
                @set_time_limit(60 + 5 * $n);
                $d = senDispatchQueued(500, 'classwork:' . $aid);
                if ($d['failed'] || $d['skipped']) {
                    $_SESSION['error_message'] = 'Sent again: ' . $d['sent'] . ' of ' . $n . ' email(s) sent. ' . ($d['failed'] + $d['skipped']) . ' failed: ' . $d['reason'];
                } else {
                    $_SESSION['success_message'] = 'Sent again: all ' . $d['sent'] . ' email(s) were sent.';
                }
            } else {
                $_SESSION['success_message'] = 'There are no failed emails to send again.';
            }
            logActivity($teacher_id, 'Classroom', 'Retried classwork email', "Assignment #$aid, $n email(s) queued again");
        }
        header('Location: classroom.php?' . $redirect_qs . '&tab=classwork&open=' . $aid . '#cw-' . $aid);
        exit;
    }

    // ---- Create a classwork assignment (Classwork tab) ----
    if (($_POST['form_action'] ?? '') === 'create_classwork') {
        $cw_back = ($_POST['return_tab'] ?? '') === 'stream' ? '&tab=stream#stream' : '&tab=classwork#classwork';
        $title = trim((string) ($_POST['title'] ?? ''));
        // The assessment TYPE decides the grading category (Written Works /
        // Performance Task / Examination) -- never online vs. offline.
        $category = array_key_exists(($_POST['category'] ?? ''), CLASSWORK_TYPES) ? $_POST['category'] : 'Written Activity';
        $grading_category = classworkGradingCategory($category);
        $max_points = is_numeric($_POST['max_points'] ?? null) ? round((float) $_POST['max_points'], 2) : 0;
        $instructions = trim((string) ($_POST['instructions'] ?? ''));
        $due_date_raw = trim((string) ($_POST['due_date'] ?? ''));
        $due_date = ($due_date_raw !== '' && strtotime($due_date_raw) !== false) ? date('Y-m-d H:i:s', strtotime($due_date_raw)) : null;

        $ai_generated = !empty($_POST['ai_generated']) ? 1 : 0;
        $ai_resource_type = trim((string) ($_POST['ai_resource_type'] ?? ''));
        $ai_teacher_answer_key = trim((string) ($_POST['ai_teacher_answer_key'] ?? ''));
        // Only the file types that fit the selected assessment type are accepted
        // (Quiz -> Google Form; Written Activity -> Word / Google Docs; ...).
        $format_in = (string) ($_POST['generate_format'] ?? '');
        if (!in_array($format_in, classworkOutputFormats($category), true)) {
            $_SESSION['error_message'] = '"' . (CLASSWORK_OUTPUT_LABELS[$format_in] ?? $format_in) . '" is not available for a ' . $category . '. Please choose one of the listed file options.';
            header('Location: classroom.php?' . $redirect_qs . $cw_back);
            exit;
        }
        $generate_format = $format_in !== '' ? $format_in : null;
        $quiz_questions = [];
        if ($generate_format === 'gform') {
            require_once __DIR__ . '/../../includes/gemini_service.php'; // aiSanitizeQuizQuestions()
            $quiz_questions = aiSanitizeQuizQuestions(json_decode((string) ($_POST['ai_quiz_questions'] ?? ''), true) ?: []);
            if (!$quiz_questions) {
                $_SESSION['error_message'] = 'The Google Form is built from the AI quiz questions. Generate the quiz with AI (from the attached lesson), review it, click "Use this content", then assign.';
                header('Location: classroom.php?' . $redirect_qs . $cw_back);
                exit;
            }
        }

        if ($generate_format === 'gform') {
            $instructions = classworkQuizDirections($instructions) . "\n\nAnswer the Google Form below, then click Turn in.";
            if ($ai_teacher_answer_key === '') {
                $letters = range('A', 'Z');
                $ai_teacher_answer_key = implode("\n", array_map(function ($q, $i) use ($letters) {
                    return ($i + 1) . '. ' . $letters[$q['correct_index']] . ' - ' . $q['choices'][$q['correct_index']];
                }, $quiz_questions, array_keys($quiz_questions)));
            }
        }

        // One post per opened form: a double-click or a page refresh never
        // creates (and emails) the same assignment twice.
        $cw_nonce = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['cw_nonce'] ?? ''));
        $used_nonces = $_SESSION['cw_nonces_used'] ?? [];
        if ($cw_nonce !== '' && in_array($cw_nonce, $used_nonces, true)) {
            $_SESSION['success_message'] = 'This assignment was already posted (it was not posted twice).';
            header('Location: classroom.php?' . $redirect_qs . $cw_back);
            exit;
        }

        if ($title === '' || $instructions === '') {
            $_SESSION['error_message'] = 'Please provide a title and instructions/content for this assignment.';
            header('Location: classroom.php?' . $redirect_qs . $cw_back);
            exit;
        }
        if (mb_strlen($title) > 200) $title = mb_substr($title, 0, 200);
        if (mb_strlen($instructions) > 12000) $instructions = mb_substr($instructions, 0, 12000);

        // A graded assignment is saved in Manage Assessment Items under its
        // own category (an item with the same name is reused -- no duplicate),
        // so the teacher's score reaches Grade Management in the right place.
        $item = null;
        if ($grading_category !== null) {
            if ($max_points <= 0) {
                $_SESSION['error_message'] = 'Enter the total points (highest possible score) for this ' . $category . '.';
                header('Location: classroom.php?' . $redirect_qs . $cw_back);
                exit;
            }
            $ensured = aiAssessmentEnsureItem(
                $teacher_id, $post_section_id, $post_subject_code, $post_sy_id, $post_term,
                $grading_category, trim((string) ($_POST['exam_subtype'] ?? '')), trim((string) ($_POST['component'] ?? '')), $title, $max_points
            );
            if (empty($ensured['success'])) {
                $_SESSION['error_message'] = 'Not assigned: ' . $ensured['error'];
                header('Location: classroom.php?' . $redirect_qs . $cw_back);
                exit;
            }
            $item = $ensured['item'];
            $max_points = (float) $item['highest_score']; // the item's HPS is what the score is out of
        }

        $assignment_id = insertRecord(
            "INSERT INTO classwork_assignments
                (teacher_id, subject_code, section_id, school_year_id, term, title, category, instructions, ai_generated, output_format, due_date, status,
                 grading_category, assessment_item_id, max_points, assigned_at, answer_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Published', ?, ?, ?, NOW(), ?)",
            [$teacher_id, $post_subject_code, $post_section_id, $post_sy_id, $post_term ?: null, $title, $category, $instructions, $ai_generated, $generate_format, $due_date,
             $grading_category, $item ? (int) $item['item_id'] : null, $grading_category !== null ? $max_points : null,
             $ai_teacher_answer_key !== '' ? mb_substr($ai_teacher_answer_key, 0, 12000) : null]
        );

        if ($assignment_id && $cw_nonce !== '') {
            $used_nonces[] = $cw_nonce;
            $_SESSION['cw_nonces_used'] = array_slice($used_nonces, -30);
        }
        $topic = mb_substr(trim((string) ($_POST['topic'] ?? '')), 0, 200);
        if ($assignment_id && $topic !== '') {
            executeQuery("UPDATE classwork_assignments SET topic = ? WHERE assignment_id = ?", [$topic, (int) $assignment_id]);
        }
        if ($assignment_id && $quiz_questions) {
            executeQuery("UPDATE classwork_assignments SET quiz_questions_json = ? WHERE assignment_id = ?",
                [json_encode($quiz_questions, JSON_UNESCAPED_UNICODE), (int) $assignment_id]);
        }
        if ($assignment_id && !empty($_POST['lock_after_due']) && $due_date !== null) {
            executeQuery("UPDATE classwork_assignments SET lock_after_due = 1 WHERE assignment_id = ?", [(int) $assignment_id]);
        }
        if (!$assignment_id) {
            $_SESSION['error_message'] = 'Could not save the assignment. Please try again.';
            header('Location: classroom.php?' . $redirect_qs . $cw_back);
            exit;
        }

        // Optional: generate an actual downloadable file from the final,
        // teacher-reviewed content -- only now, at save time, never during
        // AI chat preview (see classroom_ai_chat.php's header comment for
        // why: never create a file for a draft that's never posted).
        $cw_file_note = '';
        if ($generate_format === 'gform') {
            // Quiz -> an auto-graded Google Form from the questions the teacher reviewed.
            $gf = classworkCreateGoogleForm((int) $assignment_id);
            if ($gf['success']) {
                $cw_file_note = ' A Google Form was created from the reviewed questions and attached.';
            } else {
                $_SESSION['error_message'] = 'The assignment was posted, but the Google Form could not be created: ' . $gf['error'] . ' Use "Create Google Form again" on the assignment card.';
            }
        } elseif ($generate_format !== null) {
            $resource = [
                'title' => $title,
                'resource_type' => $ai_resource_type !== '' ? $ai_resource_type : 'assignment',
                'student_content' => $instructions,
                'teacher_answer_key' => $ai_teacher_answer_key,
            ];
            $doc_context = [
                'student_name' => trim(($post_class['section_name'] ?? '') . ' (Whole Class)'),
                'grade_level' => $post_class['section_grade_level'] ?: $post_class['grade_level'],
                'subject_name' => $post_class['subject_name'] ?? '',
                'teacher_name' => $teacher ? trim($teacher['first_name'] . ' ' . $teacher['last_name']) : '',
                'date' => date('F j, Y'),
            ];
            // Google Docs = the same Word file, converted in Google Drive and shared by link.
            $file_result = aiGenerateResourceFile($resource, $doc_context, $generate_format === 'gdoc' ? 'docx' : $generate_format, 'student');
            $gdoc_done = false;
            if ($file_result['success'] && $generate_format === 'gdoc') {
                require_once __DIR__ . '/../../includes/google_forms_service.php';
                $gdoc = googleDocsCreateFromDocx(aiResourceStorageDir() . $file_result['stored_filename'], $title);
                if ($gdoc['success']) {
                    insertRecord(
                        "INSERT INTO classwork_assignment_attachments (assignment_id, original_filename, attachment_type, external_url) VALUES (?, ?, 'link', ?)",
                        [$assignment_id, mb_substr('Google Docs: ' . $title, 0, 255), $gdoc['view_url']]
                    );
                    @unlink(aiResourceStorageDir() . $file_result['stored_filename']);
                    $gdoc_done = true;
                    $cw_file_note = ' A Google Doc was created and attached (anyone with the link can view).';
                } else {
                    $_SESSION['error_message'] = 'Google Docs could not be created (' . $gdoc['error'] . '), so the Word (.docx) file was attached instead.';
                }
            }
            if ($gdoc_done) {
                // attached as a Google Docs link above
            } elseif ($file_result['success']) {
                // aiGenerateResourceFile() always writes into its own fixed
                // location, static/uploads/ai_resources/ (aiResourceStorageDir()
                // in includes/ai_document_generator.php) -- that's correct for
                // its usual caller (the per-student AI chat), but
                // classroom_download.php serves classwork attachments from
                // static/uploads/classwork/ instead, matching every other
                // classwork attachment. Move the file over (same random,
                // unguessable stored_filename either way -- safe to relocate)
                // rather than teaching ai_document_generator.php about a
                // second storage directory.
                $moved_ok = @rename(
                    aiResourceStorageDir() . $file_result['stored_filename'],
                    classroomUploadDirFor('classwork') . $file_result['stored_filename']
                );
                if ($moved_ok) {
                    insertRecord(
                        "INSERT INTO classwork_assignment_attachments
                            (assignment_id, original_filename, stored_filename, mime_type, file_size, attachment_type)
                         VALUES (?, ?, ?, ?, ?, 'file')",
                        [$assignment_id, $file_result['original_filename'], $file_result['stored_filename'], $file_result['mime_type'], $file_result['file_size']]
                    );
                } else {
                    $_SESSION['error_message'] = 'The assignment was posted, but the generated file could not be saved. Please try again.';
                }
            } else {
                $_SESSION['error_message'] = 'The assignment was posted, but the ' . (CLASSWORK_OUTPUT_LABELS[$generate_format] ?? strtoupper($generate_format)) . ' file could not be generated: ' . $file_result['error'];
            }
        }

        // Manually attached files (teacher-supplied, independent of AI).
        if (!empty($_FILES['attachments']['name'][0] ?? '')) {
            $count = count($_FILES['attachments']['name']);
            for ($i = 0; $i < $count; $i++) {
                if (($_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $file = [
                    'name' => $_FILES['attachments']['name'][$i],
                    'type' => $_FILES['attachments']['type'][$i],
                    'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                    'error' => $_FILES['attachments']['error'][$i],
                    'size' => $_FILES['attachments']['size'][$i],
                ];
                $saved = classroomSaveUpload($file, 'classwork');
                if ($saved['success']) {
                    insertRecord(
                        "INSERT INTO classwork_assignment_attachments
                            (assignment_id, original_filename, stored_filename, mime_type, file_size, attachment_type)
                         VALUES (?, ?, ?, ?, ?, 'file')",
                        [$assignment_id, $saved['original_filename'], $saved['stored_filename'], $saved['mime_type'], $saved['file_size']]
                    );
                }
            }
        }

        // Fan out a roster row per currently-enrolled student -- a status
        // marker for a future "N/total submitted" count. No submission UI
        // exists yet (this phase is the Teacher Portal only), so every row
        // simply starts and stays 'Assigned' for now; adding real student
        // submission later only ever UPDATEs this existing row, it never
        // needs a schema change.
        // Only students enrolled in THIS section (this school year) get it.
        $roster = classroomSectionStudents($post_section_id, $post_sy_id);
        foreach ($roster as $stu) {
            executeQuery(
                "INSERT IGNORE INTO classwork_assignment_students (assignment_id, student_id, status, assigned_at) VALUES (?, ?, 'Assigned', NOW())",
                [$assignment_id, $stu['student_id']]
            );
        }

        // "New" badge on each student's class card + portal bell.
        $cw_mailed = classworkNotifyStudents(
            ['assignment_id' => (int) $assignment_id, 'title' => $title, 'category' => $category, 'due_date' => $due_date, 'subject_code' => $post_subject_code, 'school_year_id' => $post_sy_id, 'instructions' => $instructions, 'topic' => $topic, 'send_now' => true],
            array_column($roster, 'student_id'),
            $teacher_id
        );

        logActivity($teacher_id, 'Classroom', 'Posted classwork', "Assignment #$assignment_id ($category, $title), subject $post_subject_code, section #$post_section_id");
        // "Email notifications sent to 34 of 35 students. 1 email failed (reason)."
        $cw_total = count($roster);
        $cw_sent = (int) ($cw_mailed['sent'] ?? 0);
        $cw_bad = (int) ($cw_mailed['failed'] ?? 0) + (int) ($cw_mailed['no_email'] ?? 0);
        $cw_mail_note = '';
        if ($cw_total > 0) {
            $cw_mail_note = !empty($cw_mailed['switched_off'])
                ? ' Email notifications are switched off in Settings › Email Settings, so no email was sent.'
                : ' Email notifications sent to ' . $cw_sent . ' of ' . $cw_total . ' student(s).'
                    . ($cw_bad > 0 ? ' ' . $cw_bad . ' email(s) failed'
                        . ((int) ($cw_mailed['no_email'] ?? 0) > 0 ? ' (' . (int) $cw_mailed['no_email'] . ' with no registered email)' : '')
                        . (($cw_mailed['reason'] ?? '') !== '' ? ': ' . $cw_mailed['reason'] : '.')
                        . ' See the email status at the bottom of the assignment card; you can send them again there.' : '')
                    . ((int) ($cw_mailed['pending'] ?? 0) > 0 ? ' ' . (int) $cw_mailed['pending'] . ' still pending.' : '');
        }
        $_SESSION['success_message'] = 'Assessment posted successfully: "' . $title . '" was assigned to ' . $cw_total . ' student(s) of ' . ($post_class['section_name'] ?? 'this section') . '.' . $cw_file_note
            . $cw_mail_note
            . ($item ?' Saved in Manage Assessment Items as "' . html_entity_decode($item['item_name'], ENT_QUOTES, 'UTF-8') . '" under ' . CLASSWORK_GRADING_LABELS[$grading_category] . ' (' . rtrim(rtrim(number_format($max_points, 2), '0'), '.') . ' pts).' : ' Not graded (learning material).');
        header('Location: classroom.php?' . $redirect_qs . $cw_back);
        exit;
    }
}

// ============================================================
// DATA FOR DISPLAY (only when authorized)
// ============================================================
$stream_posts = [];
$classwork_items = [];
$roster = [];
$online_work = ['sent' => [], 'prepared' => []];
$cw_email_status = [];
$cw_comments = [];
$student_comments = [];

if ($authorized) {
    $stream_posts = getRecords(
        "SELECT * FROM class_stream_posts
         WHERE teacher_id = ? AND section_id = ? AND subject_code = ? AND school_year_id = ? AND is_deleted = 0
         ORDER BY created_at DESC LIMIT 100",
        [$teacher_id, $section_id, $subject_code, $school_year_id]
    );
    if (!empty($stream_posts)) {
        $post_ids = array_column($stream_posts, 'post_id');
        $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
        $all_attachments = getRecords(
            "SELECT * FROM class_stream_attachments WHERE post_id IN ($placeholders) ORDER BY attachment_id ASC",
            $post_ids
        );
        $attachments_by_post = [];
        foreach ($all_attachments as $a) {
            $attachments_by_post[$a['post_id']][] = $a;
        }
        foreach ($stream_posts as &$p) {
            $p['attachments'] = $attachments_by_post[$p['post_id']] ?? [];
        }
        unset($p);
    }

    $classwork_items = getRecords(
        "SELECT ca.*,
                (SELECT COUNT(*) FROM classwork_assignment_students cs WHERE cs.assignment_id = ca.assignment_id) AS student_count
         FROM classwork_assignments ca
         WHERE ca.teacher_id = ? AND ca.section_id = ? AND ca.subject_code = ? AND ca.school_year_id = ? AND ca.status <> 'Deleted'
         ORDER BY ca.created_at DESC",
        [$teacher_id, $section_id, $subject_code, $school_year_id]
    );
    if (!empty($classwork_items)) {
        $assignment_ids = array_column($classwork_items, 'assignment_id');
        $placeholders = implode(',', array_fill(0, count($assignment_ids), '?'));
        $all_cw_attachments = getRecords(
            "SELECT * FROM classwork_assignment_attachments WHERE assignment_id IN ($placeholders) ORDER BY attachment_id ASC",
            $assignment_ids
        );
        $cw_attachments_by_assignment = [];
        foreach ($all_cw_attachments as $a) {
            $cw_attachments_by_assignment[$a['assignment_id']][] = $a;
        }
        // Each student's status / files / score (Your Work submissions).
        $cw_rows = getRecords(
            "SELECT cs.*, s.first_name, s.last_name FROM classwork_assignment_students cs
             INNER JOIN students s ON s.student_id = cs.student_id
             WHERE cs.assignment_id IN ($placeholders) ORDER BY s.last_name, s.first_name",
            $assignment_ids
        );
        $cw_files = classworkFilesFor($assignment_ids);
        $cw_rows_by = [];
        foreach ($cw_rows as $r) {
            $cw_rows_by[(int) $r['assignment_id']][] = $r;
        }
        foreach ($classwork_items as &$c) {
            $c['attachments'] = $cw_attachments_by_assignment[$c['assignment_id']] ?? [];
            $c['students'] = [];
            $c['counts'] = ['assigned' => 0, 'turned_in' => 0, 'late' => 0, 'missing' => 0, 'graded' => 0];
            foreach ($cw_rows_by[(int) $c['assignment_id']] ?? [] as $r) {
                $st = classworkStatus($r, $c);
                $c['counts'][$st['key']]++;
                $r['status_info'] = $st;
                $r['files'] = $cw_files[(int) $c['assignment_id'] . '|' . (int) $r['student_id']] ?? [];
                $c['students'][] = $r;
            }
        }
        unset($c);
        $cw_email_status = classworkEmailStatus(array_column($classwork_items, 'assignment_id'));
        $cw_comments = cwcForAssignments(array_column($classwork_items, 'assignment_id'));
    }

    $roster = classroomSectionStudents($section_id, $school_year_id);

    // Quizzes / activities sent to this class from Teacher AI (with each
    // student's status and score), and students' comments on messages.
    $online_work = classroomOnlineWork($teacher_id, $section_id, $subject_code, $school_year_id, $roster);
    $student_comments = classroomStudentComments($teacher_id, $section_id, $subject_code, $school_year_id);
}

$csrf_token = generateCSRFToken();

// (...) menu data: what each post / classwork holds, how many students were emailed,
// and what is connected to it (turned-in work, scores) -- read by the Edit / Move /
// Delete / View Details dialogs.
$pm_emailed = [];
$pm_classes = [];
if ($authorized && in_array($tab, ['stream', 'classwork'], true)) {
    $pm_keys = array_merge(
        array_map(function ($p) { return 'announce:' . (int) $p['post_id']; }, $stream_posts),
        array_map(function ($c) { return 'classwork:' . (int) $c['assignment_id']; }, $classwork_items)
    );
    if ($pm_keys) {
        require_once __DIR__ . '/../../includes/student_email_notify.php';
        senEnsureSchema();
        $ph = implode(',', array_fill(0, count($pm_keys), '?'));
        foreach (getRecords("SELECT notify_key, COUNT(*) AS n FROM student_email_notifications WHERE status = 'Sent' AND notify_key IN ($ph) GROUP BY notify_key", $pm_keys) as $r) {
            $pm_emailed[$r['notify_key']] = (int) $r['n'];
        }
    }
    foreach (cpmTeacherClasses($teacher_id, $school_year_id) as $c) {
        if ((int) $c['section_id'] === (int) $section_id && $c['subject_code'] === $subject_code) continue;
        $pm_classes[] = ['value' => (int) $c['section_id'] . '|' . $c['subject_code'], 'label' => $c['subject_name'] . ' – ' . $c['section_name'] . ' (' . $c['grade_level'] . ')'];
    }
}
$pm_post_data = function ($post) use ($pm_emailed) {
    $atts = [];
    $link = '';
    foreach ($post['attachments'] ?? [] as $a) {
        if ($a['attachment_type'] === 'link') { $link = (string) $a['external_url']; continue; }
        $atts[] = ['id' => (int) $a['attachment_id'], 'name' => $a['original_filename']];
    }
    return ['kind' => 'post', 'id' => (int) $post['post_id'], 'body' => (string) $post['body'], 'link' => $link, 'attachments' => $atts,
            'created' => date('M j, Y g:i A', strtotime($post['created_at'])),
            'updated' => ($post['updated_at'] ?? '') && strtotime($post['updated_at']) > strtotime($post['created_at']) + 5 ? date('M j, Y g:i A', strtotime($post['updated_at'])) : '',
            'emailed' => $pm_emailed['announce:' . (int) $post['post_id']] ?? 0];
};
$pm_cw_data = function ($item) use ($pm_emailed) {
    $atts = [];
    foreach ($item['attachments'] ?? [] as $a) {
        $atts[] = ['id' => (int) $a['attachment_id'], 'name' => $a['original_filename'] ?: $a['external_url']];
    }
    $links = cpmClassworkLinks($item);
    return ['kind' => 'classwork', 'id' => (int) $item['assignment_id'], 'title' => html_entity_decode((string) $item['title'], ENT_QUOTES, 'UTF-8'),
            'instructions' => (string) $item['instructions'], 'topic' => (string) ($item['topic'] ?? ''), 'category' => (string) $item['category'],
            'due' => !empty($item['due_date']) ? date('Y-m-d\TH:i', strtotime($item['due_date'])) : '',
            'due_label' => !empty($item['due_date']) ? date('M j, Y g:i A', strtotime($item['due_date'])) : 'No due date',
            'lock' => !empty($item['lock_after_due']), 'attachments' => $atts,
            'created' => date('M j, Y g:i A', strtotime($item['assigned_at'] ?? $item['created_at'])),
            'emailed' => $pm_emailed['classwork:' . (int) $item['assignment_id']] ?? 0,
            'has_item' => !empty($item['assessment_item_id']), 'links' => $links,
            'movable' => !($links['submitted'] || $links['files'] || $links['scored'] || $links['item_scores'])];
};
// The (...) button + menu (teacher only -- this page is the Teacher Portal).
$pm_menu = function (array $data) {
    return '<div class="dropdown pm-menu" data-pm="' . htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '">'
        . '<button type="button" class="pm-dots" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Post options" title="Options"><i class="fas fa-ellipsis-vertical"></i></button>'
        . '<ul class="dropdown-menu dropdown-menu-end">'
        . '<li><button type="button" class="dropdown-item" data-pm-do="edit"><i class="fas fa-pen me-2"></i>Edit Post</button></li>'
        . '<li><button type="button" class="dropdown-item" data-pm-do="move"><i class="fas fa-right-left me-2"></i>Move to another class / section</button></li>'
        . '<li><button type="button" class="dropdown-item" data-pm-do="details"><i class="fas fa-circle-info me-2"></i>View Details</button></li>'
        . '<li><hr class="dropdown-divider"></li>'
        . '<li><button type="button" class="dropdown-item text-danger" data-pm-do="delete"><i class="fas fa-trash me-2"></i>Delete Post</button></li>'
        . '</ul></div>';
};
include '../../includes/header.php';
?>

<style>
    /* Online quizzes/activities (Classwork) and student comments (Stream) */
    .cr-pills { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
    .cw-mail-line { display:flex; flex-wrap:wrap; align-items:center; gap:6px; margin-top:8px; font-size:12px; color:#64748b; }
    /* (...) post menu */
    .pm-menu { margin-left:auto; }
    .pm-dots { border:0; background:transparent; width:32px; height:32px; border-radius:50%; color:var(--gc-muted, #667085); }
    .pm-dots:hover, .pm-menu.show .pm-dots { background:var(--surface-muted, #f1f3f4); color:var(--gc-text, #14213D); }
    .pm-menu .dropdown-menu { font-size:13.5px; min-width:230px; }
    .pm-cw-meta { position:relative; padding-right:36px; }
    .pm-cw-meta .pm-menu { position:absolute; top:-6px; right:-6px; }
    .pm-work-wrap { position:relative; }
    .pm-work-wrap > .pm-menu { position:absolute; top:12px; right:12px; }
    .pm-work-wrap > .gc-post-work { padding-right:52px; }
    .pm-modal .pm-att { display:flex; gap:8px; align-items:center; font-size:13px; padding:4px 0; }
    .pm-modal .pm-att span { font-weight:600; }
    .pm-modal .pm-note { font-size:12px; color:var(--gc-muted, #667085); background:var(--surface-muted, #f8fafc); border-radius:8px; padding:8px 10px; }
    .pm-modal .pm-warn { font-size:13px; color:#92400e; background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; padding:8px 10px; }
    .cw-work-panel + .cw-mail-line { margin-top:10px; padding-top:8px; border-top:1px solid var(--gc-line, #e5e7eb); }
    .cw-list { display:flex; flex-direction:column; }
    .cw-toggle-card { cursor:pointer; transition:box-shadow .15s ease, border-color .15s ease; }
    .cw-toggle-card:hover { border-color:var(--gc-accent, #2563eb); box-shadow:0 1px 6px rgba(37,99,235,.12); }
    .cw-toggle-card table, .cw-toggle-card form { cursor:auto; }
    .cw-toggle-card .gc-work-body::after { content:'Click to view student work'; display:block; margin-top:8px; font-size:12px; color:var(--gc-accent, #2563eb); font-weight:600; }
    /* Student work popup (same look as Student Details) */
    #cwWorkModal .modal-content { border:0; border-radius:14px; overflow:hidden; }
    #cwWorkModal .modal-header { border-bottom:1px solid var(--gc-line, #e5e7eb); padding:14px 20px; }
    #cwWorkModal .modal-title { font-size:17px; font-weight:700; color:var(--gc-text, #14213D); }
    #cwWorkModal .modal-title i { color:var(--gc-accent, #1a73e8); }
    #cwWorkModal .modal-body { background:var(--surface-muted, #f5f7fb); padding:16px 18px; }
    #cwWorkModal .cr-students { margin:0; font-size:13.5px; }
    .cwm-meta { display:flex; flex-wrap:wrap; gap:6px 16px; font-size:13px; color:var(--gc-muted, #667085); margin-bottom:10px; }
    .cwm-meta i { margin-right:3px; }
    .cwm-counts { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px; }
    .cwm-tabs { display:flex; gap:4px; border-bottom:1px solid var(--gc-line, #e5e7eb); margin-bottom:12px; }
    .cwm-tabs button { border:0; background:none; padding:8px 12px; font-size:13.5px; font-weight:600; color:var(--gc-muted, #667085); border-bottom:2px solid transparent; margin-bottom:-1px; }
    .cwm-tabs button.active { color:var(--gc-accent, #1a73e8); border-bottom-color:var(--gc-accent, #1a73e8); }
    .cwm-tabs button i { margin-right:5px; }
    #cwWorkModal .cw-ai-all { margin:0 0 10px; }
    .cwm-section { background:var(--gc-card, #fff); border:1px solid var(--gc-line, #e5e7eb); border-radius:10px; overflow:hidden; }
    #cwWorkModal .cwm-section table { margin:0; width:100%; }
    #cwWorkModal .cwm-section th { background:var(--surface-muted, #f8fafc); text-transform:uppercase; font-size:11px; letter-spacing:.03em; padding:8px 12px; }
    #cwWorkModal .cwm-section td { padding:8px 12px; vertical-align:middle; }
    #cwWorkModal .cwm-section .cwm-c { width:70px; text-align:center; }
    #cwWorkModal .cwm-section .cw-note { margin:0; padding:8px 12px; font-size:12px; border-top:1px solid var(--gc-line, #e5e7eb); }
    .cwm-empty { padding:22px; text-align:center; color:var(--gc-muted, #667085); font-size:13px; }
    .cwm-empty i { margin-right:6px; }
    .cwm-pc-btn { border:1px solid var(--gc-line, #e5e7eb); background:var(--gc-card, #fff); border-radius:16px; padding:3px 10px; font-size:12.5px; color:var(--gc-muted, #667085); }
    .cwm-pc-btn:hover { color:var(--gc-accent, #1a73e8); border-color:var(--gc-accent, #1a73e8); }
    .cwm-pc-btn.has-new { background:#fef3c7; border-color:#f59e0b; color:#92400e; font-weight:700; }
    #cwWorkModal .cwm-pc-row td { background:var(--surface-muted, #f8fafc); padding:10px 12px; }
    .cwm-thread-head { font-size:12px; font-weight:600; color:var(--gc-muted, #667085); margin-bottom:8px; }
    .cwm-class-thread { padding:12px; }
    .cwm-none { font-size:12.5px; color:var(--gc-muted, #667085); margin-bottom:8px; }
    .cwm-cmt { font-size:13px; padding:7px 10px; border-radius:8px; margin-bottom:6px; background:var(--gc-card, #fff); border:1px solid var(--gc-line, #e5e7eb); }
    .cwm-cmt.is-teacher { background:#eef4ff; border-color:#d6e4ff; }
    .cwm-cmt b { font-size:12.5px; }
    .cwm-cmt small { color:var(--gc-muted, #667085); margin-left:6px; font-size:11px; }
    .cwm-cmt div { margin-top:2px; white-space:normal; }
    .cwm-cmt-form { display:flex; gap:8px; align-items:flex-end; margin-top:8px; }
    .cwm-cmt-form textarea { flex:1; font-size:13px; border:1px solid var(--gc-line, #d0d5dd); border-radius:18px; padding:7px 14px; resize:vertical; min-height:36px; background:var(--gc-card, #fff); color:var(--gc-text, #14213D); }
    .cwm-cmt-form button { border:0; background:var(--gc-accent, #1a73e8); color:#fff; border-radius:50%; width:36px; height:36px; flex-shrink:0; }
    .cw-new-cmt { display:inline-block; margin-left:8px; font-size:11px; font-weight:700; color:#92400e; background:#fef3c7; border-radius:10px; padding:1px 8px; vertical-align:middle; }
    .cw-mail-line.cw-mail-bad { color:#b91c1c; }
    .cw-mail-line .cw-mail-fail { font-weight:600; color:#b91c1c; }
    .cw-mail-line .cw-mail-err { flex-basis:100%; font-size:11px; color:#b91c1c; }
    .cw-quiz-review { max-height:260px; overflow:auto; margin:8px 0; padding:8px 10px; border:1px solid #e2e8f0; border-radius:8px; background:#fff; font-size:12px; }
    .cw-quiz-review ol { padding-left:18px; margin:0; }
    .cw-quiz-review li { margin-bottom:6px; }
    .cw-quiz-review .ok { color:#15803d; font-weight:600; }
    .cw-format-hint { font-size:11px; color:#64748b; margin-top:3px; }
    .gc-composer-line { display:flex; align-items:flex-start; gap:12px; margin-bottom:16px; }
    .gc-composer-line > .gc-composer { flex:1 1 auto; min-width:0; margin-bottom:0; }
    .gc-composer-line > .gc-btn-create { flex:0 0 auto; margin-top:12px; white-space:nowrap; }
    @media (max-width: 575px) { .gc-composer-line { flex-direction:column-reverse; align-items:stretch; } .gc-composer-line > .gc-btn-create { margin-top:0; justify-content:center; } }
    .cr-pill { display:inline-block; border-radius:99px; padding:2px 9px; font-size:11px; font-weight:600; background:#F2F4F7; color:#475467; }
    .cr-pill.upcoming { background:#EAF2FE; color:#1D4ED8; }
    .cr-pill.active { background:#FEF3E6; color:#B54708; }
    .cr-pill.completed, .cr-pill.submitted { background:#E9F9F1; color:#0F7B4E; }
    .cr-pill.missing, .cr-pill.not { background:#FDEEEE; color:#B42318; }
    .cr-students { margin-top:10px; font-size:12.5px; }
    .cr-students summary { cursor:pointer; color:#14213D; font-weight:600; }
    .cr-students table { width:100%; border-collapse:collapse; margin-top:8px; }
    .cr-students th { text-align:left; color:var(--text-muted,#667085); font-weight:600; border-bottom:1px solid var(--border,#e5e7eb); padding:5px 6px; }
    .cr-students td { border-bottom:1px solid var(--border-soft,#eef2f7); padding:5px 6px; }
    .cr-students td.cr-low { color:#B42318; font-weight:700; }
    .cr-link { font-size:12px; color:#14213D; }
    .cr-comment { display:block; text-decoration:none; color:inherit; border:1px solid var(--border-soft,#eef2f7); border-radius:8px; padding:8px 10px; margin-bottom:8px; }
    .cr-comment:hover { border-color:#14213D; }
    .cr-comment-head { display:flex; flex-wrap:wrap; gap:6px; align-items:baseline; font-size:12.5px; }
    .cr-comment-on { color:var(--text-muted,#667085); }
    .cr-comment-time { margin-left:auto; color:var(--text-muted,#667085); font-size:11.5px; }
    .cr-comment-body { font-size:13.5px; margin:4px 0; white-space:pre-line; }
    .cr-comment-state { font-size:11.5px; font-weight:600; color:#1D4ED8; }
    .cr-comment-state.is-replied { color:#0F7B4E; }
    body:has(.classroom-banner) .sidebar {
        width: 188px !important;
        max-width: 188px !important;
        flex-basis: 188px !important;
    }
    body:has(.classroom-banner) .sidebar .nav-link {
        padding-left: 18px !important;
        padding-right: 12px !important;
    }
    body:has(.classroom-banner) .main-content {
        width: calc(100% - 188px) !important;
        max-width: calc(100% - 188px) !important;
        margin-left: 188px !important;
    }
    .classroom-banner {
        position: relative;
        min-height: 224px;
        background-color: var(--class-accent, #123B6D);
        background-image:
            linear-gradient(110deg, rgba(8,31,61,0.9), rgba(18,59,109,0.68)),
            url('../../static/images/school%20bldg%20bg.png');
        background-size: cover;
        background-position: center 42%;
        color: #fff;
        border-radius: 12px;
        padding: 46px 28px 78px;
        margin-bottom: 18px;
        text-shadow: 0 1px 3px rgba(0,0,0,0.6);
    }
    .classroom-banner h3 { margin: 0 0 8px 0; font-size: 25px; font-weight: 700; color: #fff; }
    .classroom-banner .meta { font-size: 14px; color: rgba(255,255,255,0.96); opacity: 1; }
    .classroom-banner-chips { display: flex; gap: 8px; flex-wrap: wrap; margin: 14px 0 0 78px; }
    .classroom-banner-chip {
        display: inline-flex; align-items: center; padding: 5px 10px;
        border: 1px solid rgba(255,255,255,0.45); border-radius: 6px;
        background: rgba(15,39,72,0.42); color: #fff; font-size: 12px; font-weight: 600;
    }
    .classroom-banner-chip.active { background: #10a84b; }
    .classroom-customize {
        position: absolute; top: 16px; right: 20px; padding: 7px 12px;
        border: 1px solid rgba(255,255,255,0.55); border-radius: 8px;
        background: rgba(15,39,72,0.38); color: #fff; font-size: 12px; text-decoration: none; cursor: pointer;
    }
    .classroom-customize:hover { color: #fff; background: rgba(15,39,72,0.7); }
    .classroom-theme-options { display: flex; gap: 12px; }
    .classroom-theme-option { width: 38px; height: 38px; border: 3px solid #fff; border-radius: 50%; box-shadow: 0 0 0 1px #d5e1ef; cursor: pointer; }
    .classroom-theme-option:hover { transform: scale(1.08); }
    .classroom-banner-avatar {
        position: absolute;
        left: 24px;
        top: 50%;
        transform: translateY(-50%);
        width: 58px;
        height: 58px;
        border-radius: 14px;
        background: #087cf0;
        border: 2px solid rgba(255,255,255,0.45);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .classroom-banner-avatar i { font-size: 22px; color: #fff; }
    .classroom-banner h3,
    .classroom-banner .meta { margin-left: 78px; }
    .classroom-banner-stats {
        position: absolute;
        right: 22px;
        bottom: 18px;
        display: flex;
        gap: 10px;
    }
    .classroom-stat {
        min-width: 108px;
        padding: 8px 14px;
        border: 1px solid rgba(255,255,255,0.55);
        border-radius: 10px;
        background: rgba(10, 31, 61, 0.58);
        color: #fff;
        text-align: center;
    }
    .classroom-stat strong { display: block; font-size: 18px; line-height: 1.1; }
    .classroom-stat span { display: block; font-size: 11px; opacity: 0.9; }
    .classroom-tabs .nav-link { font-weight: 600; color: var(--text-muted, #666); }
    .classroom-tabs .nav-link.active { color: #14213D; border-bottom: 3px solid #14213D; }
    .classroom-tabs { border-bottom-color: #d9e4f0; }
    .classroom-tabs .nav-link { padding: 12px 24px; border: 0; }
    .classroom-tabs .nav-link:hover { border-color: transparent; color: #1268dc; }
    .stream-post {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 16px 18px;
        margin-bottom: 14px;
        box-shadow: 0 4px 16px rgba(31, 70, 112, 0.05);
    }
    #stream > .stream-post:first-child { padding: 18px 20px; }
    #stream > .stream-post:first-child textarea { min-height: 68px; border-color: #cbdced; border-radius: 9px; }
    .announcement-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .announcement-tool,
    .announcement-audience {
        display: inline-flex; align-items: center; gap: 6px; min-height: 34px;
        padding: 7px 12px; border: 1px solid #d5e1ef; border-radius: 9px;
        background: #fff; color: #31527a; font-size: 12px; font-weight: 600;
    }
    .announcement-tool { cursor: pointer; }
    .announcement-tool:hover { background: #f1f6fc; border-color: #9db9d8; color: #1268dc; }
    .announcement-tool i { color: #1268dc; }
    .announcement-audience { margin-left: auto; cursor: pointer; }
    .announcement-audience-wrap { position: relative; margin-left: auto; }
    .announcement-audience { cursor: pointer; }
    .announcement-audience-menu { display: none; position: absolute; right: 0; bottom: calc(100% + 6px); z-index: 20; min-width: 190px; padding: 6px; border: 1px solid #d5e1ef; border-radius: 9px; background: #fff; box-shadow: 0 10px 24px rgba(31,70,112,0.16); }
    .announcement-audience-menu.is-open { display: block; }
    .announcement-audience-option { display: block; width: 100%; padding: 8px 10px; border: 0; border-radius: 6px; background: transparent; color: #31527a; text-align: left; font-size: 12px; cursor: pointer; }
    .announcement-audience-option:hover, .announcement-audience-option.is-selected { background: #edf5ff; color: #1268dc; }
    .announcement-link-field { display: none; margin: 8px 0; }
    .announcement-link-field.is-visible { display: block; }
    .announcement-file-input { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
    .announcement-file-name { display: none; margin-left: 2px; color: #667085; font-size: 11px; }
    .announcement-post-button { min-height: 34px; padding: 7px 17px; border: 0; border-radius: 9px; background: #1268dc; color: #fff; font-size: 12px; font-weight: 700; }
    .announcement-post-button:hover { background: #0d55b8; }
    .stream-post .post-meta { font-size: 12px; color: var(--text-muted); margin-bottom: 6px; }
    .stream-post .post-body { white-space: pre-wrap; word-break: break-word; }
    .attachment-chip {
        display: inline-flex; align-items: center; gap: 6px;
        border: 1px solid var(--border); border-radius: 8px;
        padding: 5px 10px; margin: 4px 6px 0 0; font-size: 12px; text-decoration: none; color: inherit;
    }
    .attachment-chip:hover { background: var(--surface-hover); }
    .classwork-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 16px 18px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(20,33,61,0.06);
    }
    .classwork-card .category-badge { font-size: 11px; }
    /* People tab: a Classroom-style roster instead of a plain table --
       one row per student with an initials avatar, clearly separated from
       the next by a divider so the list doesn't read as one flat block. */
    .roster-list { border-top: 1px solid var(--border); }
    .roster-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 4px;
        border-bottom: 1px solid var(--border);
    }
    .roster-row:hover { background: var(--surface-hover); }
    .roster-avatar {
        flex-shrink: 0;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #14213D;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        font-weight: 700;
    }
    .roster-name { font-weight: 600; color: var(--text); }
    .roster-meta { font-size: 12px; color: var(--text-muted); }
    .ai-chat-box { border: 1px solid var(--border); border-radius: 10px; height: 220px; overflow-y: auto; padding: 10px; background: var(--surface-hover); }
    .ai-bubble { max-width: 85%; padding: 8px 12px; border-radius: 10px; margin-bottom: 8px; font-size: 13px; white-space: pre-wrap; }
    .ai-bubble.ai { background: var(--border); color: var(--text); }
    .ai-bubble.teacher { background: #14213D; color: #fff; margin-left: auto; }
    .ai-resource-preview { border: 1px dashed #14213D; border-radius: 10px; padding: 10px 12px; margin-top: 8px; background: var(--surface); }
    .roster-count-pill { font-size: 12px; }

    /* ================================================================
       Google-Classroom-style look (readable in light and dark theme):
       every colour comes from the theme variables in static/css/theme.css.
       ================================================================ */
    :root { --gc-accent: #1a73e8; --gc-accent-soft: #e8f0fe; --gc-card: var(--surface, #fff); --gc-line: var(--border, #dadce0); --gc-text: var(--text, #3c4043); --gc-head: var(--ink, #202124); --gc-muted: var(--text-muted, #5f6368); }
    html[data-theme="dark"] { --gc-accent: #8ab4f8; --gc-accent-soft: rgba(138,180,248,0.14); }

    .classroom-tabs { border-bottom: 1px solid var(--gc-line); gap: 4px; }
    .classroom-tabs .nav-link { color: var(--gc-muted); font-size: 14.5px; font-weight: 600; padding: 12px 22px; border: 0; border-radius: 8px 8px 0 0; position: relative; background: transparent; }
    .classroom-tabs .nav-link:hover { color: var(--gc-accent); background: var(--gc-accent-soft); }
    .classroom-tabs .nav-link.active { color: var(--gc-accent); background: transparent; border: 0; }
    .classroom-tabs .nav-link.active::after { content: ""; position: absolute; left: 14px; right: 14px; bottom: -1px; height: 4px; border-radius: 4px 4px 0 0; background: var(--gc-accent); }
    .classroom-banner { min-height: 210px; border-radius: 10px; }
    .classroom-banner h3 { font-size: 30px; letter-spacing: -0.01em; }
    .classroom-banner .meta { font-size: 15px; }

    .gc-stream { display: grid; grid-template-columns: 210px minmax(0, 1fr); gap: 22px; align-items: start; }
    @media (max-width: 900px) { .gc-stream { grid-template-columns: 1fr; } .gc-side { order: 2; } }
    .gc-side-card { background: var(--gc-card); border: 1px solid var(--gc-line); border-radius: 10px; padding: 16px; }
    .gc-side-card h6 { font-size: 14.5px; font-weight: 700; color: var(--gc-head); margin: 0 0 10px; }
    .gc-side-empty { font-size: 13px; color: var(--gc-muted); margin: 0 0 12px; }
    .gc-up { display: block; text-decoration: none; margin-bottom: 10px; }
    .gc-up-due { display: block; font-size: 12px; color: var(--gc-muted); }
    .gc-up-title { display: block; font-size: 13.5px; font-weight: 600; color: var(--gc-head); }
    .gc-up:hover .gc-up-title { color: var(--gc-accent); text-decoration: underline; }
    .gc-side-link { display: inline-block; font-size: 13.5px; font-weight: 700; color: var(--gc-accent); text-decoration: none; margin-top: 4px; }

    .gc-avatar { flex-shrink: 0; width: 40px; height: 40px; border-radius: 50%; background: var(--gc-accent); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; }
    html[data-theme="dark"] .gc-avatar { color: #0b1a33; }

    .stream-post { background: var(--gc-card); border: 1px solid var(--gc-line); border-radius: 10px; box-shadow: none; padding: 18px 20px; margin-bottom: 16px; color: var(--gc-text); }
    .gc-composer { padding: 14px 18px; }
    .gc-composer-toggle { display: flex; align-items: center; gap: 14px; width: 100%; border: 0; background: transparent; padding: 0; color: var(--gc-muted); font-size: 14.5px; text-align: left; cursor: pointer; }
    .gc-composer-toggle:hover span:last-child { color: var(--gc-accent); }
    .gc-composer .gc-composer-form { display: none; margin-top: 12px; }
    .gc-composer.is-open .gc-composer-toggle { display: none; }
    .gc-composer.is-open .gc-composer-form { display: block; margin-top: 0; }
    .gc-composer textarea { background: var(--surface-muted, #f8f9fa); color: var(--gc-text); border: 0; border-bottom: 2px solid var(--gc-line); border-radius: 6px 6px 0 0; font-size: 14.5px; }
    .gc-composer textarea:focus { border-bottom-color: var(--gc-accent); box-shadow: none; }
    .gc-cancel { min-height: 34px; padding: 7px 14px; border: 0; border-radius: 9px; background: transparent; color: var(--gc-muted); font-size: 13px; font-weight: 700; }
    .gc-cancel:hover { background: var(--surface-hover, #f1f3f4); }
    .announcement-tool, .announcement-audience { background: var(--gc-card); color: var(--gc-text); border-color: var(--gc-line); font-size: 12.5px; }
    .announcement-tool i { color: var(--gc-accent); }
    .announcement-post-button { background: var(--gc-accent); }
    html[data-theme="dark"] .announcement-post-button { color: #0b1a33; }
    .announcement-audience-menu { background: var(--gc-card); border-color: var(--gc-line); }
    .announcement-audience-option { color: var(--gc-text); }

    .gc-post-head { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
    .gc-post-name { font-size: 14.5px; font-weight: 600; color: var(--gc-head); line-height: 1.35; }
    .gc-post-date { font-size: 12.5px; color: var(--gc-muted); }
    .stream-post .post-body { font-size: 14.5px; line-height: 1.65; color: var(--gc-text); white-space: normal; }
    .gc-attachments { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    .attachment-chip { color: var(--gc-text); border-color: var(--gc-line); border-radius: 8px; padding: 8px 12px; margin: 0; font-size: 13px; background: var(--gc-card); }
    .attachment-chip i { color: var(--gc-accent); }
    .gc-post-work { display: flex; align-items: center; gap: 14px; text-decoration: none; padding: 14px 20px; }
    .gc-post-work:hover { box-shadow: 0 1px 3px rgba(60,64,67,0.3), 0 4px 8px 3px rgba(60,64,67,0.15); }
    .gc-post-work:hover .gc-post-name { color: var(--gc-accent); }
    .gc-empty { text-align: center; border: 1px dashed var(--gc-line); border-radius: 10px; padding: 36px 20px; color: var(--gc-muted); }
    .gc-empty i { font-size: 30px; color: var(--gc-accent); margin-bottom: 10px; }
    .gc-empty p { font-size: 16px; font-weight: 600; color: var(--gc-head); margin: 0 0 4px; }
    .gc-empty span { font-size: 13.5px; }

    .gc-work-icon { flex-shrink: 0; width: 40px; height: 40px; border-radius: 50%; background: var(--gc-accent); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 16px; }
    .gc-work-icon.is-pending { background: #e37400; }
    .gc-work-icon.is-assignment { background: #5f6368; }
    html[data-theme="dark"] .gc-work-icon { color: #0b1a33; }

    .gc-cw-head { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
    .gc-btn-create { display: inline-flex; align-items: center; gap: 8px; border: 0; border-radius: 24px; padding: 10px 22px; background: var(--gc-accent); color: #fff; font-size: 14px; font-weight: 700; box-shadow: 0 1px 3px rgba(60,64,67,0.3); }
    html[data-theme="dark"] .gc-btn-create { color: #0b1a33; }
    .gc-btn-create:hover { filter: brightness(1.08); }
    .gc-cw-hint { font-size: 13px; color: var(--gc-muted); }
    .gc-btn-outline { display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--gc-line); border-radius: 8px; padding: 7px 14px; color: var(--gc-accent); font-size: 13px; font-weight: 700; text-decoration: none; background: var(--gc-card); }
    .gc-btn-outline:hover { background: var(--gc-accent-soft); color: var(--gc-accent); }

    .classwork-card { background: var(--gc-card); border: 1px solid var(--gc-line); border-radius: 10px; box-shadow: none; padding: 16px 20px; margin-bottom: 12px; color: var(--gc-text); }
    .classwork-card.gc-work { display: flex; gap: 16px; align-items: flex-start; }
    .classwork-card.gc-work:hover { box-shadow: 0 1px 3px rgba(60,64,67,0.3), 0 4px 8px 3px rgba(60,64,67,0.15); }
    .gc-work-body { flex: 1; min-width: 0; }
    .classwork-card h6 { font-size: 15.5px; font-weight: 600; color: var(--gc-head); }
    .classwork-card .text-muted, .classwork-card .text-end { color: var(--gc-muted) !important; font-size: 12.5px !important; }
    .gc-cw-text { font-size: 14px; line-height: 1.6; color: var(--gc-text); margin-top: 8px; }
    .classwork-card .category-badge { font-size: 11.5px; font-weight: 700; }

    .cr-pill { font-size: 12px; padding: 3px 10px; }
    html[data-theme="dark"] .cr-pill { background: rgba(255,255,255,0.08); color: var(--gc-text); }
    html[data-theme="dark"] .cr-pill.upcoming { background: rgba(29,78,216,0.25); color: #9dbbff; }
    html[data-theme="dark"] .cr-pill.active { background: rgba(181,71,8,0.25); color: #ffc58a; }
    html[data-theme="dark"] .cr-pill.completed, html[data-theme="dark"] .cr-pill.submitted { background: rgba(15,123,78,0.25); color: #8fe3bb; }
    html[data-theme="dark"] .cr-pill.missing, html[data-theme="dark"] .cr-pill.not { background: rgba(180,35,24,0.25); color: #ffaaa3; }
    .cr-students { font-size: 13.5px; }
    .cr-students summary { color: var(--gc-accent); font-size: 13.5px; }
    .cr-students th { color: var(--gc-muted); font-size: 12.5px; }
    .cr-students td { color: var(--gc-text); }
    .cr-students td.cr-low { color: #d93025; }
    html[data-theme="dark"] .cr-students td.cr-low { color: #f28b82; }

    .cr-comments h6 { font-size: 15px; font-weight: 700; color: var(--gc-head); }
    .cr-link { color: var(--gc-accent); font-weight: 700; font-size: 13px; text-decoration: none; }
    .cr-comment { border-color: var(--gc-line); padding: 10px 12px; }
    .cr-comment:hover { border-color: var(--gc-accent); }
    .cr-comment-head { font-size: 13px; color: var(--gc-head); }
    .cr-comment-on, .cr-comment-time { color: var(--gc-muted); }
    .cr-comment-body { font-size: 14.5px; color: var(--gc-text); }
    .cr-comment-state { color: var(--gc-accent); font-size: 12.5px; }
    html[data-theme="dark"] .cr-comment-state.is-replied { color: #8fe3bb; }

    .roster-name { color: var(--gc-head); font-size: 14.5px; }
    .roster-meta { color: var(--gc-muted); font-size: 12.5px; }
    .roster-avatar { background: var(--gc-accent); }
    html[data-theme="dark"] .roster-avatar { color: #0b1a33; }

    /* Classwork: assessment type, student work and scores */
    .gc-work-icon.is-pt { background: #9334e6; }
    .gc-work-icon.is-exam { background: #d93025; }
    .category-badge.cw-cat-ww { background: #e8f0fe; color: #1967d2; }
    .category-badge.cw-cat-pt { background: #f3e8fd; color: #8430ce; }
    .category-badge.cw-cat-qa { background: #fce8e6; color: #c5221f; }
    .category-badge.cw-cat-none { background: var(--surface-hover, #f1f3f4); color: var(--gc-muted); }
    html[data-theme="dark"] .category-badge.cw-cat-ww { background: rgba(138,180,248,0.16); color: #aecbfa; }
    html[data-theme="dark"] .category-badge.cw-cat-pt { background: rgba(197,138,249,0.16); color: #d7aefb; }
    html[data-theme="dark"] .category-badge.cw-cat-qa { background: rgba(242,139,130,0.16); color: #f6aea9; }
    .cr-pill.graded { background: #e6f4ea; color: #137333; }
    html[data-theme="dark"] .cr-pill.graded { background: rgba(129,201,149,0.18); color: #a8dab5; }
    .cw-when { font-size: 11.5px; color: var(--gc-muted); margin-top: 2px; }
    .cw-file { display: block; font-size: 12.5px; color: var(--gc-accent); text-decoration: none; margin-bottom: 2px; }
    .cw-file:hover { text-decoration: underline; }
    .cw-grade-form { display: flex; gap: 4px; align-items: center; }
    .cw-grade-form input { width: 70px; border: 1px solid var(--gc-line); border-radius: 6px; padding: 3px 6px; font-size: 13px; background: var(--gc-card); color: var(--gc-text); }
    .cw-grade-form button { border: 0; border-radius: 6px; background: var(--gc-accent); color: #fff; width: 28px; height: 28px; }
    html[data-theme="dark"] .cw-grade-form button { color: #0b1a33; }
    .cw-note { font-size: 12px; color: var(--gc-muted); margin: 8px 0 0; }
    .cw-src { display: inline-block; font-size: 11px; font-weight: 700; border-radius: 99px; padding: 2px 8px; margin-left: 4px; }
    .cw-src-ai { background: #f3e8fd; color: #8430ce; }
    .cw-src-t { background: #e6f4ea; color: #137333; }
    html[data-theme="dark"] .cw-src-ai { background: rgba(197,138,249,0.16); color: #d7aefb; }
    html[data-theme="dark"] .cw-src-t { background: rgba(129,201,149,0.18); color: #a8dab5; }
    .cw-ai-fb { font-size: 12px; color: var(--gc-muted); margin-top: 4px; max-width: 360px; }
    .cw-ai-form button { margin-top: 6px; border: 1px solid var(--gc-line); background: var(--gc-card); color: #8430ce; border-radius: 6px; font-size: 11.5px; font-weight: 700; padding: 3px 8px; }
    html[data-theme="dark"] .cw-ai-form button { color: #d7aefb; }
    /* The <form> wraps header/body/footer, so it must pass the scrollable
       modal's height down -- otherwise the body is cut off and the footer
       (Assign to All Students) cannot be reached. */
    #newAssignmentModal .modal-content > form { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; max-height: 100%; overflow: hidden; }
    #newAssignmentModal .modal-content > form > .modal-body { overflow-y: auto; min-height: 0; }
    #newAssignmentModal .modal-footer { flex-shrink: 0; }
    .cw-class-line, .cw-grading-line { font-size: 12.5px; color: var(--gc-text); background: var(--gc-accent-soft); border-radius: 8px; padding: 8px 10px; margin-bottom: 12px; }
    .cw-grading-line { margin: -2px 0 12px; background: transparent; padding: 0 2px; color: var(--gc-muted); }
    .cw-ai-box { border: 1px solid var(--gc-line); border-radius: 10px; padding: 10px; background: var(--surface-muted, #f8f9fb); }
    .cw-ai-scope { font-size: 11.5px; color: var(--gc-muted); }
    .cw-preview-text { font-size: 12.5px; max-height: 140px; overflow-y: auto; white-space: pre-wrap; margin: 6px 0; color: var(--gc-text); }
    .ai-bubble.ai { background: var(--gc-card); color: var(--gc-text); border: 1px solid var(--gc-line); }
    .gc-btn-assign { background: var(--gc-accent); color: #fff; font-weight: 700; border-radius: 8px; }
    .gc-btn-assign:hover { color: #fff; filter: brightness(1.08); }
    html[data-theme="dark"] .gc-btn-assign, html[data-theme="dark"] .gc-btn-assign:hover { color: #0b1a33; }
</style>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($_SESSION['success_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($_SESSION['error_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<?php if (!$authorized): ?>
    <div class="alert alert-warning">
        <i class="fas fa-ban me-2"></i>
        <?php echo htmlspecialchars($access['error']); ?>
    </div>
<?php else: ?>

    <div class="classroom-banner">
        <div class="classroom-banner-avatar"><i class="fas fa-chalkboard-teacher"></i></div>
        <button type="button" class="classroom-customize" data-bs-toggle="modal" data-bs-target="#customizeClassModal"><i class="fas fa-gear me-1"></i> Customize Class</button>
        <h3><?php echo htmlspecialchars($class['subject_name']); ?></h3>
        <div class="meta">
            <?php echo htmlspecialchars($class['section_grade_level'] ?: $class['grade_level']); ?>
            <?php $strand = $class['section_strand'] ?: $class['strand']; if (!empty($strand)) echo ' &middot; ' . htmlspecialchars($strand === 'ASSH' ? 'ASSH Cluster' : $strand); ?>
            &middot; Section <?php echo htmlspecialchars($class['section_name']); ?>
            &middot; <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>
        </div>
        <div class="classroom-banner-chips">
            <span class="classroom-banner-chip"><?php echo htmlspecialchars(!empty($class['strand']) ? ($class['strand'] === 'ASSH' ? 'Social Studies' : $class['strand']) : 'Subject'); ?></span>
            <span class="classroom-banner-chip active"><?php echo htmlspecialchars($term); ?></span>
            <span class="classroom-banner-chip">SY <?php echo htmlspecialchars($active_sy['sy_name'] ?? ''); ?></span>
            <?php if ($class_schedule): ?>
                <?php $class_day_names = [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri']; ?>
                <span class="classroom-banner-chip"><i class="fas fa-clock me-1"></i><?php echo htmlspecialchars(implode('-', array_map(function ($row) use ($class_day_names) { return $class_day_names[(int)$row['day_of_week']] ?? ''; }, $class_schedule))); ?> · <?php echo date('g:i A', strtotime($class_schedule[0]['start_time'])); ?>–<?php echo date('g:i A', strtotime($class_schedule[0]['end_time'])); ?></span>
            <?php endif; ?>
        </div>
        <div class="classroom-banner-stats" aria-label="Class summary">
            <div class="classroom-stat"><strong><?php echo count($roster); ?></strong><span>Students</span></div>
            <div class="classroom-stat"><strong><?php echo count($classwork_items) + count($online_work['sent']); ?></strong><span>Assignments</span></div>
            <div class="classroom-stat"><strong><?php echo count($stream_posts); ?></strong><span>Announcements</span></div>
        </div>
    </div>

    <div class="modal fade" id="customizeClassModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-palette me-2"></i>Customize Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Choose an accent color for this class banner. This setting is saved on this browser only.</p>
                    <div class="classroom-theme-options" role="group" aria-label="Class banner color">
                        <button type="button" class="classroom-theme-option" data-class-color="#123B6D" style="background:#123B6D;" aria-label="Navy"></button>
                        <button type="button" class="classroom-theme-option" data-class-color="#1268DC" style="background:#1268DC;" aria-label="Blue"></button>
                        <button type="button" class="classroom-theme-option" data-class-color="#0F766E" style="background:#0F766E;" aria-label="Teal"></button>
                        <button type="button" class="classroom-theme-option" data-class-color="#7C3AED" style="background:#7C3AED;" aria-label="Violet"></button>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button></div>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var banner = document.querySelector('.classroom-banner');
        var key = 'egradepro-class-color-<?php echo htmlspecialchars($subject_code, ENT_QUOTES); ?>';
        if (!banner) return;
        function applyClassColor(color) {
            banner.style.setProperty('--class-accent', color);
            banner.style.backgroundImage = 'linear-gradient(110deg, ' + color + 'e6, ' + color + 'b3), url("../../static/images/school%20bldg%20bg.png")';
            document.querySelectorAll('[data-class-color]').forEach(function (item) {
                item.classList.toggle('is-selected', item.getAttribute('data-class-color') === color);
            });
        }
        try { var saved = localStorage.getItem(key); if (saved) applyClassColor(saved); } catch (e) {}
        document.querySelectorAll('[data-class-color]').forEach(function (option) {
            option.addEventListener('click', function () {
                var color = option.getAttribute('data-class-color');
                applyClassColor(color);
                try { localStorage.setItem(key, color); } catch (e) {}
            });
        });
    })();
    </script>

    <ul class="nav nav-tabs classroom-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'stream' ? 'active' : ''; ?>"
               href="?section_id=<?php echo $section_id; ?>&subject_code=<?php echo urlencode($subject_code); ?>&term=<?php echo urlencode($term); ?>&tab=stream">
                <i class="fas fa-stream me-1"></i> Stream
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'classwork' ? 'active' : ''; ?>"
               href="?section_id=<?php echo $section_id; ?>&subject_code=<?php echo urlencode($subject_code); ?>&term=<?php echo urlencode($term); ?>&tab=classwork">
                <i class="fas fa-tasks me-1"></i> Classwork
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'people' ? 'active' : ''; ?>"
               href="?section_id=<?php echo $section_id; ?>&subject_code=<?php echo urlencode($subject_code); ?>&term=<?php echo urlencode($term); ?>&tab=people">
                <i class="fas fa-users me-1"></i> People <span class="badge bg-secondary roster-count-pill"><?php echo count($roster); ?></span>
            </a>
        </li>
    </ul>

    <?php if ($tab === 'stream'): ?>
    <?php
        // Google-Classroom-style stream: "Upcoming" on the left, and one feed
        // of announcements + posted classwork (newest first) on the right.
        $gc_teacher_name = trim($teacher['first_name'] . ' ' . $teacher['last_name']);
        $gc_teacher_initials = mb_strtoupper(mb_substr(trim($teacher['first_name']), 0, 1) . mb_substr(trim($teacher['last_name']), 0, 1));
        $gc_now = date('Y-m-d H:i:s');
        $gc_upcoming = [];
        foreach ($online_work['sent'] as $ow) {
            if (!empty($ow['end_at']) && $ow['end_at'] >= $gc_now) $gc_upcoming[] = ['title' => $ow['title'], 'due' => $ow['end_at']];
        }
        foreach ($online_work['prepared'] as $pw) {
            if (!empty($pw['end_at']) && $pw['end_at'] >= $gc_now) $gc_upcoming[] = ['title' => $pw['title'], 'due' => $pw['end_at']];
        }
        foreach ($classwork_items as $ci) {
            if (!empty($ci['due_date']) && $ci['due_date'] >= $gc_now) $gc_upcoming[] = ['title' => $ci['title'], 'due' => $ci['due_date']];
        }
        usort($gc_upcoming, function ($a, $b) { return strcmp($a['due'], $b['due']); });
        $gc_feed = [];
        foreach ($stream_posts as $sp) $gc_feed[] = ['kind' => 'post', 'time' => $sp['created_at'], 'data' => $sp];
        foreach ($online_work['sent'] as $ow) $gc_feed[] = ['kind' => 'work', 'time' => $ow['sent_raw'], 'data' => $ow];
        foreach ($classwork_items as $ci) $gc_feed[] = ['kind' => 'classwork', 'time' => $ci['created_at'], 'data' => $ci];
        usort($gc_feed, function ($a, $b) { return strcmp($b['time'], $a['time']); });
        $gc_classwork_url = '?section_id=' . $section_id . '&subject_code=' . urlencode($subject_code) . '&term=' . urlencode($term) . '&tab=classwork';
    ?>
    <div id="stream" class="gc-stream">
        <aside class="gc-side">
            <div class="gc-side-card">
                <h6>Upcoming</h6>
                <?php if (!$gc_upcoming): ?>
                    <p class="gc-side-empty">Woohoo, no work due soon!</p>
                <?php else: foreach (array_slice($gc_upcoming, 0, 4) as $up): ?>
                    <a class="gc-up" href="<?php echo $gc_classwork_url; ?>">
                        <span class="gc-up-due">Due <?php echo date('D, M j · g:i A', strtotime($up['due'])); ?></span>
                        <span class="gc-up-title"><?php echo htmlspecialchars($up['title']); ?></span>
                    </a>
                <?php endforeach; endif; ?>
                <a class="gc-side-link" href="<?php echo $gc_classwork_url; ?>">View all</a>
            </div>
        </aside>
        <div class="gc-main">
        <div class="gc-composer-line">
        <div class="stream-post gc-composer" id="gcComposer">
            <button type="button" class="gc-composer-toggle" id="gcComposerToggle">
                <span class="gc-avatar"><?php echo htmlspecialchars($gc_teacher_initials); ?></span>
                <span>Announce something to your class</span>
            </button>
            <form method="post" enctype="multipart/form-data" class="gc-composer-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="form_action" value="post_announcement">
                <input type="hidden" name="section_id" value="<?php echo $section_id; ?>">
                <input type="hidden" name="subject_code" value="<?php echo htmlspecialchars($subject_code); ?>">
                <input type="hidden" name="school_year_id" value="<?php echo (int) $school_year_id; ?>">
                <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                <textarea class="form-control mb-2" name="body" rows="3" placeholder="Announce something to your class... (reminders, updates, deadlines, instructions)"></textarea>
                <div class="announcement-link-field" id="announcementLinkField">
                    <input type="url" class="form-control form-control-sm" id="announcementLinkUrl" name="link_url" placeholder="Paste a link (optional) — Google Drive, YouTube, etc.">
                </div>
                <input class="announcement-file-input" id="announcementFiles" type="file" name="attachments[]" multiple>
                <div class="announcement-actions">
                    <button type="button" class="announcement-tool" data-link-mode="link"><i class="fas fa-link"></i> Link</button>
                    <button type="button" class="announcement-tool" data-link-mode="drive"><i class="fab fa-google-drive"></i> Google Drive</button>
                    <button type="button" class="announcement-tool" data-link-mode="youtube"><i class="fab fa-youtube"></i> YouTube</button>
                    <label class="announcement-tool" for="announcementFiles"><i class="fas fa-paperclip"></i> Upload File</label>
                    <span class="announcement-file-name" id="announcementFileName"></span>
                    <div class="announcement-audience-wrap">
                        <input type="hidden" name="audience" id="announcementAudience" value="Everyone">
                        <button type="button" class="announcement-audience" id="announcementAudienceButton" aria-haspopup="listbox" aria-expanded="false"><i class="fas fa-globe"></i><span id="announcementAudienceLabel">Everyone</span><i class="fas fa-chevron-down" style="font-size:9px;"></i></button>
                        <div class="announcement-audience-menu" id="announcementAudienceMenu" role="listbox">
                            <button type="button" class="announcement-audience-option is-selected" data-audience="Everyone" role="option">Everyone</button>
                            <button type="button" class="announcement-audience-option" data-audience="Students in this class" role="option">Students in this class</button>
                        </div>
                    </div>
                    <button type="button" class="gc-cancel" id="gcComposerCancel">Cancel</button>
                    <button type="submit" class="announcement-post-button"><i class="fas fa-paper-plane me-1"></i> Post</button>
                </div>
            </form>
        </div>
        <button type="button" class="gc-btn-create" data-bs-toggle="modal" data-bs-target="#newAssignmentModal" title="New assignment, quiz or material">
            <i class="fas fa-plus"></i> Create
        </button>
        </div>

        <script>
        (function () {
            var field = document.getElementById('announcementLinkField');
            var input = document.getElementById('announcementLinkUrl');
            var fileInput = document.getElementById('announcementFiles');
            var fileName = document.getElementById('announcementFileName');
            var audienceButton = document.getElementById('announcementAudienceButton');
            var audienceMenu = document.getElementById('announcementAudienceMenu');
            var audienceInput = document.getElementById('announcementAudience');
            var audienceLabel = document.getElementById('announcementAudienceLabel');
            var composer = document.getElementById('gcComposer');
            var toggle = document.getElementById('gcComposerToggle');
            var cancel = document.getElementById('gcComposerCancel');
            if (composer && toggle) toggle.addEventListener('click', function () {
                composer.classList.add('is-open');
                var ta = composer.querySelector('textarea');
                if (ta) ta.focus();
            });
            if (composer && cancel) cancel.addEventListener('click', function () { composer.classList.remove('is-open'); });
            if (!field || !input) return;
            document.querySelectorAll('[data-link-mode]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var mode = button.getAttribute('data-link-mode');
                    field.classList.add('is-visible');
                    input.focus();
                    input.placeholder = mode === 'drive' ? 'Paste a Google Drive link...' : (mode === 'youtube' ? 'Paste a YouTube link...' : 'Paste a link (optional) — Google Drive, YouTube, etc.');
                });
            });
            if (fileInput && fileName) fileInput.addEventListener('change', function () {
                var count = fileInput.files ? fileInput.files.length : 0;
                fileName.textContent = count ? (count + ' file' + (count === 1 ? '' : 's') + ' selected') : '';
                fileName.style.display = count ? 'inline' : 'none';
            });
            if (audienceButton && audienceMenu) {
                audienceButton.addEventListener('click', function (event) {
                    event.stopPropagation();
                    var open = audienceMenu.classList.toggle('is-open');
                    audienceButton.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
                audienceMenu.querySelectorAll('[data-audience]').forEach(function (option) {
                    option.addEventListener('click', function () {
                        var value = option.getAttribute('data-audience');
                        audienceInput.value = value;
                        audienceLabel.textContent = value;
                        audienceMenu.querySelectorAll('[data-audience]').forEach(function (item) { item.classList.remove('is-selected'); });
                        option.classList.add('is-selected');
                        audienceMenu.classList.remove('is-open');
                        audienceButton.setAttribute('aria-expanded', 'false');
                    });
                });
                document.addEventListener('click', function () {
                    audienceMenu.classList.remove('is-open');
                    audienceButton.setAttribute('aria-expanded', 'false');
                });
            }
        })();
        </script>

        <?php if (!empty($student_comments)): ?>
            <div class="stream-post cr-comments">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0"><i class="fas fa-comments me-2"></i>Student Comments</h6>
                    <a href="message_comments.php" class="cr-link">All comments</a>
                </div>
                <?php foreach ($student_comments as $sc): ?>
                    <a class="cr-comment" href="message_comments.php?message_id=<?php echo (int) $sc['message_id']; ?>">
                        <div class="cr-comment-head">
                            <strong><?php echo htmlspecialchars($sc['student_name']); ?></strong>
                            <span class="cr-comment-on">on <?php echo htmlspecialchars($sc['support_type']); ?>: “<?php echo htmlspecialchars(mb_strimwidth(preg_replace('/\s+/', ' ', $sc['final_message']), 0, 60, '…')); ?>”</span>
                            <span class="cr-comment-time"><?php echo date('M j, g:i A', strtotime($sc['created_at'])); ?></span>
                        </div>
                        <div class="cr-comment-body"><?php echo htmlspecialchars($sc['body']); ?></div>
                        <span class="cr-comment-state <?php echo $sc['replied'] ? 'is-replied' : ''; ?>"><?php echo $sc['replied'] ? '<i class="fas fa-check"></i> Replied' : '<i class="fas fa-reply"></i> Reply'; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($gc_feed)): ?>
            <div class="gc-empty"><i class="fas fa-comments"></i><p>This is where you'll talk to your class.</p><span>Use the stream to share announcements. Posted classwork shows up here too.</span></div>
        <?php else: foreach ($gc_feed as $f): ?>
            <?php if ($f['kind'] === 'post'): $post = $f['data']; ?>
            <div class="stream-post gc-post">
                <div class="gc-post-head">
                    <span class="gc-avatar"><?php echo htmlspecialchars($gc_teacher_initials); ?></span>
                    <div>
                        <div class="gc-post-name"><?php echo htmlspecialchars($gc_teacher_name); ?></div>
                        <div class="gc-post-date"><?php echo date('M j, Y · g:i A', strtotime($post['created_at'])); ?></div>
                    </div>
                    <?php echo $pm_menu($pm_post_data($post)); ?>
                </div>
                <?php if ($post['body'] !== ''): ?>
                    <div class="post-body"><?php echo nl2br(htmlspecialchars($post['body'])); ?></div>
                <?php endif; ?>
                <?php if (!empty($post['attachments'])): ?>
                    <div class="gc-attachments">
                        <?php foreach ($post['attachments'] as $att): ?>
                            <?php if ($att['attachment_type'] === 'link'): ?>
                                <a class="attachment-chip" href="<?php echo htmlspecialchars($att['external_url']); ?>" target="_blank" rel="noopener">
                                    <i class="fas fa-link"></i> <?php echo htmlspecialchars(mb_strimwidth($att['external_url'], 0, 40, '…')); ?>
                                </a>
                            <?php else: ?>
                                <a class="attachment-chip" href="classroom_download.php?kind=stream&id=<?php echo (int) $att['attachment_id']; ?>" target="_blank">
                                    <i class="fas <?php echo classroomMimeIcon($att['mime_type'], $att['original_filename']); ?>"></i>
                                    <?php echo htmlspecialchars($att['original_filename']); ?>
                                    <span class="text-muted"><?php echo classroomFileSizeLabel($att['file_size']); ?></span>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php else: $w = $f['data']; $w_title = $f['kind'] === 'work' ? $w['title'] : $w['title']; ?>
            <div class="pm-work-wrap">
            <a class="stream-post gc-post gc-post-work" href="<?php echo $gc_classwork_url; ?><?php echo $f['kind'] === 'classwork' ? '&amp;open=' . (int) $w['assignment_id'] : ''; ?>">
                <span class="gc-work-icon"><i class="fas <?php echo $f['kind'] === 'work' ? 'fa-clipboard-list' : 'fa-file-lines'; ?>"></i></span>
                <div>
                    <div class="gc-post-name"><?php echo htmlspecialchars($gc_teacher_name); ?> posted a new assignment: <?php echo htmlspecialchars($w_title); ?></div>
                    <div class="gc-post-date"><?php echo date('M j, Y · g:i A', strtotime($f['time'])); ?><?php if ($f['kind'] === 'work' && $w['window']): ?> · <?php echo htmlspecialchars($w['window']); ?><?php endif; ?></div>
                </div>
            </a>
            <?php if ($f['kind'] === 'classwork') echo $pm_menu($pm_cw_data($w)); ?>
            </div>
            <?php endif; ?>
        <?php endforeach; endif; ?>
        </div><!-- /.gc-main -->
    </div>
    <?php endif; ?>

    <?php if ($tab === 'classwork'): ?>
    <div id="classwork">
        <div class="gc-cw-head">
            <span class="gc-cw-hint">Online quizzes and activities from Teacher AI show here automatically with each student's status.</span>
        </div>
        <?php $cw_order = function ($when) { $t = $when ? strtotime((string) $when) : 0; return (int) -floor(($t ?: 0) / 60); }; ?>
        <div class="cw-list">

        <?php foreach ($online_work['prepared'] as $pw): ?>
            <div class="classwork-card cr-prepared gc-work" style="order:<?php echo $cw_order($pw['updated_at'] ?? null); ?>">
                <span class="gc-work-icon is-pending"><i class="fas fa-hourglass-half"></i></span>
                <div class="gc-work-body d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <span class="badge category-badge mb-1" style="background:#FEF3E6;color:#B54708;"><?php echo $pw['lesson_sent'] ? 'Lesson sent to ' . (int) $pw['lesson_sent'] . ' student(s) – no online quiz yet' : 'Prepared – not sent yet'; ?></span>
                        <h6 class="mb-0"><?php echo htmlspecialchars($pw['title']); ?></h6>
                        <div class="text-muted" style="font-size:12px;"><i class="far fa-clock me-1"></i><?php echo htmlspecialchars($pw['window']); ?></div>
                    </div>
                    <a class="gc-btn-outline" href="../grading/items.php?section_id=<?php echo $section_id; ?>&subject_code=<?php echo urlencode($subject_code); ?>&term=<?php echo urlencode($term); ?>&open_ai=<?php echo (int) $pw['item_id']; ?>"><i class="fas fa-robot me-1"></i>Open AI to finish &amp; send</a>
                </div>
            </div>
        <?php endforeach; ?>

        <?php foreach ($online_work['sent'] as $ow): ?>
            <div class="classwork-card cr-online gc-work cw-toggle-card" style="order:<?php echo $cw_order($ow['sent_raw'] ?? null); ?>">
                <span class="gc-work-icon"><i class="fas fa-clipboard-list"></i></span>
                <div class="gc-work-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <span class="badge category-badge mb-1" style="background:#E9F9F1;color:#0F7B4E;"><i class="fas fa-laptop me-1"></i><?php echo htmlspecialchars($ow['type_label']); ?> · <?php echo htmlspecialchars($ow['format']); ?></span>
                        <h6 class="mb-1"><?php echo htmlspecialchars($ow['title']); ?></h6>
                        <?php if ($ow['item']): ?><div class="text-muted" style="font-size:12px;"><i class="fas fa-table-list me-1"></i>Recorded as <?php echo htmlspecialchars($ow['item']); ?></div><?php endif; ?>
                    </div>
                    <div class="text-end text-muted" style="font-size:12px;">
                        Sent <?php echo htmlspecialchars($ow['sent_at']); ?><br>
                        <?php if ($ow['window']): ?><i class="far fa-clock me-1"></i><?php echo htmlspecialchars($ow['window']); ?><br><?php endif; ?>
                        <i class="fas fa-users me-1"></i><?php echo (int) $ow['total']; ?> student(s)<?php if ($ow['avg_percent'] !== null): ?> · Avg <?php echo (int) $ow['avg_percent']; ?>%<?php endif; ?>
                    </div>
                </div>
                <div class="cr-pills">
                    <?php if ($ow['has_task']): ?>
                        <?php foreach (['Upcoming' => 'upcoming', 'Active' => 'active', 'Completed' => 'completed', 'Missing' => 'missing'] as $lbl => $cls): ?>
                            <span class="cr-pill <?php echo $cls; ?>"><?php echo $lbl; ?>: <?php echo (int) $ow['counts'][$lbl]; ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="cr-pill completed">Submitted: <?php echo (int) $ow['submitted']; ?></span>
                        <span class="cr-pill missing">Not submitted: <?php echo (int) ($ow['total'] - $ow['submitted']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="cw-work-panel cr-students" hidden data-title="<?php echo htmlspecialchars($ow['title']); ?>">
                    <div class="cwm-meta">
                        <span><?php echo htmlspecialchars($ow['type_label']); ?> · <?php echo htmlspecialchars($ow['format']); ?><?php if ($ow['item']): ?> · <?php echo htmlspecialchars($ow['item']); ?><?php endif; ?></span>
                        <span><i class="far fa-clock"></i> <?php echo $ow['window'] ? htmlspecialchars($ow['window']) : 'Sent ' . htmlspecialchars($ow['sent_at']); ?></span>
                        <?php if ($ow['avg_percent'] !== null): ?><span>Class average <?php echo (int) $ow['avg_percent']; ?>%</span><?php endif; ?>
                    </div>
                    <div class="cwm-section">
                    <table>
                        <thead><tr><th>Student</th><th>Status</th><th>Score</th><th>Submitted</th></tr></thead>
                        <tbody>
                        <?php foreach ($ow['students'] as $st): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($st['name']); ?></td>
                                <td><span class="cr-pill <?php echo strtolower(preg_replace('/[^A-Za-z]+.*$/', '', $st['status'])); ?>"><?php echo htmlspecialchars($st['status']); ?></span></td>
                                <td<?php echo ($st['percent'] !== null && $st['percent'] < getGradingPassMark()) ? ' class="cr-low"' : ''; ?>><?php echo htmlspecialchars($st['score'] ?: '—'); ?></td>
                                <td><?php echo htmlspecialchars($st['submitted_at'] ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($classwork_items) && empty($online_work['sent']) && empty($online_work['prepared'])): ?>
            <div class="empty-state"><i class="fas fa-tasks fa-2x"></i>No classwork has been posted for this class yet.</div>
        <?php else: foreach ($classwork_items as $item):
            $cw_grading = $item['grading_category'] ?? classworkGradingCategory($item['category']);
            $cw_open = (int) ($_GET['open'] ?? 0) === (int) $item['assignment_id'];
        ?>
            <div class="classwork-card gc-work cw-toggle-card<?php echo $cw_open ? ' cw-auto-open' : ''; ?>" id="cw-<?php echo (int) $item['assignment_id']; ?>" style="order:<?php echo $cw_order($item['assigned_at'] ?? $item['created_at']); ?>">
                <span class="gc-work-icon <?php echo $cw_grading === 'PT' ? 'is-pt' : ($cw_grading === 'QA' ? 'is-exam' : ($cw_grading ? '' : 'is-assignment')); ?>"><i class="fas <?php echo $cw_grading === 'PT' ? 'fa-star' : ($cw_grading === 'QA' ? 'fa-file-signature' : ($cw_grading ? 'fa-clipboard-list' : 'fa-book-open')); ?>"></i></span>
                <div class="gc-work-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <span class="badge category-badge mb-1 cw-cat-<?php echo strtolower($cw_grading ?: 'none'); ?>"><?php echo htmlspecialchars($item['category']); ?> · <?php echo htmlspecialchars($cw_grading ? CLASSWORK_GRADING_LABELS[$cw_grading] : 'Not graded'); ?><?php if (!empty($item['max_points'])): ?> · <?php echo rtrim(rtrim(number_format((float) $item['max_points'], 2), '0'), '.'); ?> pts<?php endif; ?></span>
                        <h6 class="mb-1"><?php echo htmlspecialchars($item['title']); ?>
                            <?php $cw_unseen = (int) ($cw_comments[(int) $item['assignment_id']]['unseen'] ?? 0); if ($cw_unseen): ?><span class="cw-new-cmt"><i class="fas fa-comment-dots"></i> <?php echo $cw_unseen; ?> new</span><?php endif; ?>
                        </h6>
                    </div>
                    <div class="text-end text-muted pm-cw-meta" style="font-size:12px;">
                        <?php echo $pm_menu($pm_cw_data($item)); ?>
                        Assigned <?php echo date('M j, Y g:i A', strtotime($item['assigned_at'] ?? $item['created_at'])); ?><br>
                        <?php if (!empty($item['due_date'])): ?>Due <?php echo date('M j, Y g:i A', strtotime($item['due_date'])); ?><?php echo !empty($item['lock_after_due']) ? ' · <i class="fas fa-lock"></i> closed after due' : ''; ?><br><?php else: ?>No due date<br><?php endif; ?>
                        <i class="fas fa-users me-1"></i><?php echo count($item['students']); ?> student(s)
                    </div>
                </div>
                <div class="post-body gc-cw-text">
                    <?php echo nl2br(htmlspecialchars(mb_strimwidth($item['instructions'], 0, 400, '…'))); ?>
                </div>
                <?php if (!empty($item['attachments'])): ?>
                    <div class="mt-2">
                        <?php foreach ($item['attachments'] as $att): ?>
                            <a class="attachment-chip" href="classroom_download.php?kind=classwork&id=<?php echo (int) $att['attachment_id']; ?>" target="_blank">
                                <i class="fas <?php echo $att['attachment_type'] === 'link' ? (strpos((string) $att['external_url'], '/forms/') !== false ? 'fa-list-check' : 'fa-file-lines') : classroomMimeIcon($att['mime_type'], $att['original_filename']); ?>"></i>
                                <?php echo htmlspecialchars($att['original_filename']); ?>
                                <?php if ($att['attachment_type'] !== 'link'): ?><span class="text-muted"><?php echo classroomFileSizeLabel($att['file_size']); ?></span><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                        <?php if (!empty($item['google_form_edit_url'])): ?>
                            <a class="attachment-chip" href="<?php echo htmlspecialchars($item['google_form_edit_url']); ?>" target="_blank" rel="noopener"><i class="fas fa-pen"></i> Edit Google Form (teacher only)</a>
                        <?php endif; ?>
                        <?php if (!empty($item['quiz_questions_json']) || trim((string) ($item['answer_key'] ?? '')) !== ''): ?>
                            <a class="attachment-chip" href="classwork_answer_key.php?id=<?php echo (int) $item['assignment_id']; ?>" target="_blank"><i class="fas fa-file-pdf" style="color:#dc2626;"></i> Answer Key.pdf <span class="text-muted">(teacher only)</span></a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($item['quiz_questions_json']) && empty($item['google_form_id'])): ?>
                    <form method="post" class="cw-mail-line cw-mail-bad">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="form_action" value="retry_classwork_gform">
                        <input type="hidden" name="assignment_id" value="<?php echo (int) $item['assignment_id']; ?>">
                        <input type="hidden" name="section_id" value="<?php echo (int) $section_id; ?>">
                        <input type="hidden" name="subject_code" value="<?php echo htmlspecialchars($subject_code); ?>">
                        <input type="hidden" name="school_year_id" value="<?php echo (int) $school_year_id; ?>">
                        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                        <i class="fas fa-triangle-exclamation"></i> The Google Form for this quiz was not created yet.
                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2">Create Google Form again</button>
                    </form>
                <?php endif; ?>
                <div class="cr-pills">
                    <span class="cr-pill upcoming">Assigned: <?php echo (int) $item['counts']['assigned']; ?></span>
                    <span class="cr-pill completed">Turned in: <?php echo (int) $item['counts']['turned_in']; ?></span>
                    <span class="cr-pill active">Submitted late: <?php echo (int) $item['counts']['late']; ?></span>
                    <span class="cr-pill missing">Missing: <?php echo (int) $item['counts']['missing']; ?></span>
                    <?php if ($cw_grading): ?><span class="cr-pill graded">Graded: <?php echo (int) $item['counts']['graded']; ?></span><?php endif; ?>
                </div>
                <?php
                    // Turned in with a file but not checked yet (e.g. the AI timed out at turn-in).
                    $cw_unchecked = $cw_grading ? count(array_filter($item['students'], function ($s) { return $s['status'] === 'Submitted' && $s['files']; })) : 0;
                ?>
                <?php
                    $cw_aid = (int) $item['assignment_id'];
                    $cw_cm = $cw_comments[$cw_aid] ?? ['class' => [], 'private' => [], 'unseen' => 0];
                    $cw_hidden = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_token) . '">'
                        . '<input type="hidden" name="section_id" value="' . (int) $section_id . '">'
                        . '<input type="hidden" name="subject_code" value="' . htmlspecialchars($subject_code) . '">'
                        . '<input type="hidden" name="school_year_id" value="' . (int) $school_year_id . '">'
                        . '<input type="hidden" name="term" value="' . htmlspecialchars($term) . '">'
                        . '<input type="hidden" name="assignment_id" value="' . $cw_aid . '">';
                    $cw_cols = $cw_grading ? 5 : 4;
                ?>
                <div class="cw-work-panel cr-students" hidden data-title="<?php echo htmlspecialchars($item['title']); ?>" data-aid="<?php echo $cw_aid; ?>" data-unseen="<?php echo (int) $cw_cm['unseen']; ?>">
                    <div class="cwm-meta">
                        <span><?php echo htmlspecialchars($item['category']); ?> · <?php echo htmlspecialchars($cw_grading ? CLASSWORK_GRADING_LABELS[$cw_grading] : 'Not graded'); ?><?php if (!empty($item['max_points'])): ?> · <?php echo rtrim(rtrim(number_format((float) $item['max_points'], 2), '0'), '.'); ?> pts<?php endif; ?></span>
                        <span><i class="far fa-clock"></i> <?php echo !empty($item['due_date']) ? 'Due ' . date('M j, Y g:i A', strtotime($item['due_date'])) . (!empty($item['lock_after_due']) ? ' · closed after due' : '') : 'No due date'; ?></span>
                    </div>
                    <div class="cwm-counts">
                        <span class="cr-pill upcoming">Assigned: <?php echo (int) $item['counts']['assigned']; ?></span>
                        <span class="cr-pill completed">Turned in: <?php echo (int) $item['counts']['turned_in']; ?></span>
                        <span class="cr-pill active">Late: <?php echo (int) $item['counts']['late']; ?></span>
                        <span class="cr-pill missing">Missing: <?php echo (int) $item['counts']['missing']; ?></span>
                        <?php if ($cw_grading): ?><span class="cr-pill graded">Graded: <?php echo (int) $item['counts']['graded']; ?></span><?php endif; ?>
                    </div>
                    <div class="cwm-tabs" role="tablist">
                        <button type="button" class="active" data-cwtab="work"><i class="fas fa-users"></i> Student Work</button>
                        <button type="button" data-cwtab="comments"><i class="fas fa-comments"></i> Class comments<?php echo $cw_cm['class'] ? ' (' . count($cw_cm['class']) . ')' : ''; ?></button>
                    </div>
                    <div class="cwm-pane" data-pane="work">
                    <?php if ($cw_unchecked): ?>
                    <form method="post" class="cw-ai-form cw-ai-all" onsubmit="var b=this.querySelector('button');if(b.disabled)return false;b.disabled=true;b.innerHTML='<i class=&quot;fas fa-spinner fa-spin&quot;></i> AI is checking…';">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="form_action" value="ai_check_classwork">
                        <input type="hidden" name="section_id" value="<?php echo $section_id; ?>">
                        <input type="hidden" name="subject_code" value="<?php echo htmlspecialchars($subject_code); ?>">
                        <input type="hidden" name="school_year_id" value="<?php echo (int) $school_year_id; ?>">
                        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                        <input type="hidden" name="assignment_id" value="<?php echo (int) $item['assignment_id']; ?>">
                        <button type="submit"><i class="fas fa-robot"></i> Check all turned-in work with AI (<?php echo $cw_unchecked; ?>)</button>
                    </form>
                <?php endif; ?>
                    <div class="cwm-section">
                    <?php if (!$item['students']): ?>
                        <div class="cwm-empty"><i class="fas fa-user-slash"></i> No students assigned yet.</div>
                    <?php else: ?>
                    <table>
                        <thead><tr><th>Student</th><th>Status</th><th>Work</th><?php if ($cw_grading): ?><th>Score<?php echo !empty($item['max_points']) ? ' / ' . rtrim(rtrim(number_format((float) $item['max_points'], 2), '0'), '.') : ''; ?></th><?php endif; ?><th class="cwm-c">Private</th></tr></thead>
                        <tbody>
                        <?php foreach ($item['students'] as $st):
                            $cw_pc = $cw_cm['private'][(int) $st['student_id']] ?? [];
                            $cw_pc_new = count(array_filter($cw_pc, function ($c) { return $c['author_role'] === 'Student' && !(int) $c['seen_by_teacher']; }));
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($st['last_name'] . ', ' . $st['first_name']); ?></td>
                                <td>
                                    <span class="cr-pill <?php echo ['assigned' => 'upcoming', 'turned_in' => 'completed', 'late' => 'active', 'missing' => 'missing', 'graded' => 'graded'][$st['status_info']['key']]; ?>"><?php echo htmlspecialchars($st['status_info']['label']); ?></span>
                                    <?php if (!empty($st['is_late']) && $st['status_info']['key'] === 'graded'): ?><span class="cr-pill active">Late</span><?php endif; ?>
                                    <?php if (($st['score_source'] ?? '') === 'AI'): ?><span class="cw-src cw-src-ai"><i class="fas fa-robot"></i> AI-checked</span>
                                    <?php elseif (($st['score_source'] ?? '') === 'Teacher'): ?><span class="cw-src cw-src-t"><i class="fas fa-user-check"></i> Checked by teacher<?php echo $st['ai_score'] !== null ? ' (AI said ' . rtrim(rtrim(number_format((float) $st['ai_score'], 2), '0'), '.') . ')' : ''; ?></span><?php endif; ?>
                                    <?php if (!empty($st['submitted_at'])): ?><div class="cw-when"><?php echo date('M j, g:i A', strtotime($st['submitted_at'])); ?></div><?php endif; ?>
                                    <?php if (!empty($st['ai_feedback'])): ?><div class="cw-ai-fb"><?php echo htmlspecialchars(mb_strimwidth($st['ai_feedback'], 0, 220, '…')); ?></div><?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$st['files']): ?><span class="text-muted">—</span><?php endif; ?>
                                    <?php foreach ($st['files'] as $sf): ?>
                                        <a class="cw-file" href="classroom_download.php?kind=submission&id=<?php echo (int) $sf['file_id']; ?>" target="_blank"><i class="fas <?php echo classroomMimeIcon($sf['mime_type'], $sf['original_filename']); ?>"></i> <?php echo htmlspecialchars($sf['original_filename']); ?></a>
                                    <?php endforeach; ?>
                                </td>
                                <?php if ($cw_grading): ?>
                                <td>
                                    <form method="post" class="cw-grade-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="form_action" value="grade_classwork">
                                        <input type="hidden" name="section_id" value="<?php echo $section_id; ?>">
                                        <input type="hidden" name="subject_code" value="<?php echo htmlspecialchars($subject_code); ?>">
                                        <input type="hidden" name="school_year_id" value="<?php echo (int) $school_year_id; ?>">
                                        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                                        <input type="hidden" name="assignment_id" value="<?php echo (int) $item['assignment_id']; ?>">
                                        <input type="hidden" name="student_id" value="<?php echo (int) $st['student_id']; ?>">
                                        <input type="number" name="score" step="0.01" min="0" <?php echo !empty($item['max_points']) ? 'max="' . (float) $item['max_points'] . '"' : ''; ?> value="<?php echo $st['score'] !== null ? rtrim(rtrim(number_format((float) $st['score'], 2), '0'), '.') : ''; ?>" placeholder="—" required>
                                        <button type="submit" title="Save score (goes to Grade Management)"><i class="fas fa-check"></i></button>
                                    </form>
                                    <?php if (in_array($st['status'], ['Submitted', 'Reviewed'], true) && $st['files']): ?>
                                    <form method="post" class="cw-ai-form" onsubmit="var b=this.querySelector('button');if(b.disabled)return false;b.disabled=true;b.innerHTML='<i class=&quot;fas fa-spinner fa-spin&quot;></i> Checking…';">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="form_action" value="ai_check_classwork">
                                        <input type="hidden" name="section_id" value="<?php echo $section_id; ?>">
                                        <input type="hidden" name="subject_code" value="<?php echo htmlspecialchars($subject_code); ?>">
                                        <input type="hidden" name="school_year_id" value="<?php echo (int) $school_year_id; ?>">
                                        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                                        <input type="hidden" name="assignment_id" value="<?php echo (int) $item['assignment_id']; ?>">
                                        <input type="hidden" name="student_id" value="<?php echo (int) $st['student_id']; ?>">
                                        <button type="submit"><i class="fas fa-robot"></i> <?php echo $st['score'] === null ? 'Check with AI' : 'Re-check with AI'; ?></button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td class="cwm-c">
                                    <button type="button" class="cwm-pc-btn<?php echo $cw_pc_new ? ' has-new' : ''; ?>" data-sid="<?php echo (int) $st['student_id']; ?>" title="Private comments with this student">
                                        <i class="far fa-comment"></i><?php echo $cw_pc ? ' ' . count($cw_pc) : ''; ?>
                                    </button>
                                </td>
                            </tr>
                            <tr class="cwm-pc-row" data-sid="<?php echo (int) $st['student_id']; ?>" hidden>
                                <td colspan="<?php echo $cw_cols; ?>">
                                    <div class="cwm-thread">
                                        <div class="cwm-thread-head"><i class="fas fa-lock"></i> Private comments with <?php echo htmlspecialchars($st['first_name']); ?> — only you and this student can see these</div>
                                        <?php if (!$cw_pc): ?><div class="cwm-none">No private comments yet.</div><?php endif; ?>
                                        <?php foreach ($cw_pc as $c): ?>
                                            <div class="cwm-cmt <?php echo $c['author_role'] === 'Teacher' ? 'is-teacher' : 'is-student'; ?>">
                                                <b><?php echo htmlspecialchars($c['author_role'] === 'Teacher' ? 'You' : $c['author_name']); ?></b><small><?php echo cwcWhen($c['created_at']); ?></small>
                                                <div><?php echo nl2br(htmlspecialchars($c['body'])); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                        <form method="post" class="cwm-cmt-form">
                                            <?php echo $cw_hidden; ?>
                                            <input type="hidden" name="form_action" value="classwork_comment">
                                            <input type="hidden" name="visibility" value="private">
                                            <input type="hidden" name="student_id" value="<?php echo (int) $st['student_id']; ?>">
                                            <textarea name="body" rows="1" maxlength="2000" required placeholder="Add a private comment…"></textarea>
                                            <button type="submit" title="Send"><i class="fas fa-paper-plane"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                    <?php if ($cw_grading && !empty($item['assessment_item_id'])): ?>
                        <p class="cw-note"><i class="fas fa-circle-info me-1"></i>AI checks turned-in work first; your score always wins. Saved to <?php echo htmlspecialchars(CLASSWORK_GRADING_LABELS[$cw_grading]); ?>.</p>
                    <?php endif; ?>
                    </div>
                    </div>
                    <div class="cwm-pane" data-pane="comments" hidden>
                        <div class="cwm-section cwm-thread cwm-class-thread">
                            <div class="cwm-thread-head"><i class="fas fa-users"></i> Class comments — everyone in the class can see these</div>
                            <?php if (!$cw_cm['class']): ?><div class="cwm-none">No class comments yet.</div><?php endif; ?>
                            <?php foreach ($cw_cm['class'] as $c): ?>
                                <div class="cwm-cmt <?php echo $c['author_role'] === 'Teacher' ? 'is-teacher' : 'is-student'; ?>">
                                    <b><?php echo htmlspecialchars($c['author_role'] === 'Teacher' ? 'You' : $c['author_name']); ?></b><small><?php echo cwcWhen($c['created_at']); ?></small>
                                    <div><?php echo nl2br(htmlspecialchars($c['body'])); ?></div>
                                </div>
                            <?php endforeach; ?>
                            <form method="post" class="cwm-cmt-form">
                                <?php echo $cw_hidden; ?>
                                <input type="hidden" name="form_action" value="classwork_comment">
                                <input type="hidden" name="visibility" value="class">
                                <textarea name="body" rows="1" maxlength="2000" required placeholder="Add a class comment…"></textarea>
                                <button type="submit" title="Post"><i class="fas fa-paper-plane"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php $cw_mail = $cw_email_status[(int) $item['assignment_id']] ?? []; if ($cw_mail): ?>
                    <form method="post" class="cw-mail-line<?php echo !empty($cw_mail['Failed']) ? ' cw-mail-bad' : ''; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="form_action" value="retry_classwork_email">
                        <input type="hidden" name="assignment_id" value="<?php echo (int) $item['assignment_id']; ?>">
                        <input type="hidden" name="section_id" value="<?php echo (int) $section_id; ?>">
                        <input type="hidden" name="subject_code" value="<?php echo htmlspecialchars($subject_code); ?>">
                        <input type="hidden" name="school_year_id" value="<?php echo (int) $school_year_id; ?>">
                        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                        <i class="fas fa-envelope"></i> Email:
                        <?php if (!empty($cw_mail['Sent'])): ?><span><?php echo (int) $cw_mail['Sent']; ?> sent</span><?php endif; ?>
                        <?php if (!empty($cw_mail['Queued'])): ?><span><?php echo (int) $cw_mail['Queued']; ?> pending</span><?php endif; ?>
                        <?php if (!empty($cw_mail['Failed'])): ?><span class="cw-mail-fail" title="<?php echo htmlspecialchars($cw_mail['error_Failed'] ?? ''); ?>"><?php echo (int) $cw_mail['Failed']; ?> failed</span><?php endif; ?>
                        <?php if (!empty($cw_mail['Skipped'])): ?><span title="<?php echo htmlspecialchars($cw_mail['error_Skipped'] ?? ''); ?>"><?php echo (int) $cw_mail['Skipped']; ?> not sent (<?php echo htmlspecialchars($cw_mail['error_Skipped'] ?? 'switched off'); ?>)</span><?php endif; ?>
                        <?php if (!empty($cw_mail['Failed']) || !empty($cw_mail['Skipped'])): ?>
                            <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-2">Send again</button>
                            <?php if (!empty($cw_mail['error_Failed'])): ?><div class="cw-mail-err"><?php echo htmlspecialchars(mb_strimwidth($cw_mail['error_Failed'], 0, 260, '…')); ?></div><?php endif; ?>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
    <!-- Student work popup: a card's panel is moved in here while it is open. -->
    <div class="modal fade" id="cwWorkModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content cwm-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-clipboard-list me-2"></i><span id="cwWorkTitle"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="cwWorkBody"></div>
            </div>
        </div>
    </div>
    <script>
    // Click a card to see its student work in a popup. Links and buttons on the
    // card (files, Send again, ...) keep working as usual.
    (function () {
        var modalEl = document.getElementById('cwWorkModal');
        var body = document.getElementById('cwWorkBody');
        var current = null;   // {panel, home}
        var card = null;
        function openFor(c) {
            card = c;
            var panel = c.querySelector('.cw-work-panel');
            if (!panel || !window.bootstrap) return;
            var home = document.createComment('cw-panel');
            panel.parentNode.insertBefore(home, panel);
            body.appendChild(panel);
            panel.hidden = false;
            current = { panel: panel, home: home };
            document.getElementById('cwWorkTitle').textContent = panel.getAttribute('data-title') || 'Student work';
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
            // New student comments on this classwork are now seen.
            if (panel.getAttribute('data-unseen') > 0) {
                var fd = new FormData();
                fd.append('form_action', 'classwork_comments_seen');
                fd.append('assignment_id', panel.getAttribute('data-aid'));
                ['csrf_token', 'section_id', 'subject_code', 'school_year_id', 'term'].forEach(function (n) {
                    var i = panel.querySelector('input[name=' + n + ']'); if (i) fd.append(n, i.value);
                });
                fetch('classroom.php', { method: 'POST', body: fd }).catch(function () {});
                panel.setAttribute('data-unseen', '0');
                var badge = card.querySelector('.cw-new-cmt'); if (badge) badge.remove();
            }
        }
        function showTab(panel, name) {
            panel.querySelectorAll('.cwm-tabs button').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-cwtab') === name); });
            panel.querySelectorAll('.cwm-pane').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== name; });
        }
        function togglePrivate(panel, sid, force) {
            var row = panel.querySelector('.cwm-pc-row[data-sid="' + sid + '"]');
            if (!row) return;
            row.hidden = force === undefined ? !row.hidden : !force;
            if (!row.hidden) { var t = row.querySelector('textarea'); if (t) t.focus(); }
        }
        body.addEventListener('click', function (e) {
            var panel = body.querySelector('.cw-work-panel');
            var tab = e.target.closest('.cwm-tabs button');
            if (tab && panel) { showTab(panel, tab.getAttribute('data-cwtab')); return; }
            var pc = e.target.closest('.cwm-pc-btn');
            if (pc && panel) { pc.classList.remove('has-new'); togglePrivate(panel, pc.getAttribute('data-sid')); }
        });
        // Enter sends a comment; Shift+Enter adds a new line.
        body.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey && e.target.matches('.cwm-cmt-form textarea') && e.target.value.trim() !== '') {
                e.preventDefault();
                e.target.form.requestSubmit ? e.target.form.requestSubmit() : e.target.form.submit();
            }
        });
        modalEl.addEventListener('hidden.bs.modal', function () {
            if (!current) return;
            current.panel.hidden = true;
            current.home.parentNode.insertBefore(current.panel, current.home);
            current.home.remove();
            current = null;
        });
        document.querySelectorAll('#classwork .cw-toggle-card').forEach(function (card) {
            card.addEventListener('click', function (e) {
                if (e.target.closest('a, button, input, select, textarea, label, form, .pm-menu')) return;
                if (window.getSelection && String(window.getSelection()).length) return;
                openFor(card);
            });
        });
        // After saving a score the same popup opens again.
        var auto = document.querySelector('#classwork .cw-auto-open');
        if (auto) window.addEventListener('load', function () {
            openFor(auto);
            var q = new URLSearchParams(location.search), panel = body.querySelector('.cw-work-panel');
            if (!panel) return;
            if (q.get('cwc')) showTab(panel, 'comments');
            if (q.get('pc')) togglePrivate(panel, q.get('pc'), true);
        });
    })();
    </script>
    <?php endif; ?>

    <?php if ($tab === 'stream' || $tab === 'classwork'): ?>
    <!-- (...) menu dialogs: Edit / Move / Delete / View Details -->
    <div class="modal fade pm-modal" id="pmEditModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data">
                    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-pen me-2"></i><span class="pm-title">Edit Post</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="section_id" value="<?php echo (int) $section_id; ?>">
                        <input type="hidden" name="subject_code" value="<?php echo htmlspecialchars($subject_code); ?>">
                        <input type="hidden" name="school_year_id" value="<?php echo (int) $school_year_id; ?>">
                        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                        <input type="hidden" name="return_tab" value="<?php echo htmlspecialchars($tab); ?>">
                        <input type="hidden" name="form_action" class="pm-action">
                        <input type="hidden" name="post_id" class="pm-post-id">
                        <input type="hidden" name="assignment_id" class="pm-cw-id">
                        <div class="pm-only-post">
                            <label class="form-label small mb-1">Announcement</label>
                            <textarea class="form-control mb-2" name="body" rows="5" maxlength="5000"></textarea>
                            <label class="form-label small mb-1">Link (optional)</label>
                            <input type="url" class="form-control form-control-sm mb-2" name="link_url" placeholder="https://…">
                        </div>
                        <div class="pm-only-cw">
                            <label class="form-label small mb-1">Title</label>
                            <input type="text" class="form-control mb-2" name="title" maxlength="200">
                            <label class="form-label small mb-1">Topic</label>
                            <input type="text" class="form-control form-control-sm mb-2" name="topic" maxlength="200">
                            <label class="form-label small mb-1">Instructions</label>
                            <textarea class="form-control mb-2" name="instructions" rows="6" maxlength="12000"></textarea>
                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label small mb-1">Due date &amp; time</label>
                                    <input type="datetime-local" class="form-control form-control-sm" name="due_date">
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <label class="small" style="display:flex;gap:6px;align-items:center;"><input type="checkbox" class="form-check-input mt-0" name="lock_after_due" value="1"> Close after the due date</label>
                                </div>
                            </div>
                            <div class="pm-note"><i class="fas fa-circle-info me-1"></i>Points and assessment type stay the same (they are part of Grade Management). To change the subject or section, use <strong>Move to another class / section</strong>.</div>
                        </div>
                        <label class="form-label small mb-1 mt-2">Attached files</label>
                        <div class="pm-atts mb-2"></div>
                        <input type="file" class="form-control form-control-sm" name="new_attachments[]" multiple>
                        <div class="pm-notify mt-3">
                            <label style="display:flex;gap:8px;align-items:flex-start;"><input type="checkbox" class="form-check-input mt-1" name="notify" value="1">
                                <span><strong>Notify students about this update</strong><br><small class="text-muted pm-notify-text"></small></span></label>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save changes</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal fade pm-modal" id="pmMoveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-right-left me-2"></i>Move to another class / section</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="section_id" value="<?php echo (int) $section_id; ?>">
                        <input type="hidden" name="subject_code" value="<?php echo htmlspecialchars($subject_code); ?>">
                        <input type="hidden" name="school_year_id" value="<?php echo (int) $school_year_id; ?>">
                        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                        <input type="hidden" name="return_tab" value="<?php echo htmlspecialchars($tab); ?>">
                        <input type="hidden" name="form_action" class="pm-action">
                        <input type="hidden" name="post_id" class="pm-post-id">
                        <input type="hidden" name="assignment_id" class="pm-cw-id">
                        <p class="small mb-2">Now in: <strong><?php echo htmlspecialchars($class['subject_name'] . ' – ' . $class['section_name']); ?></strong></p>
                        <?php if (!$pm_classes): ?>
                            <div class="pm-warn">You have no other class this school year to move it to.</div>
                        <?php else: ?>
                            <label class="form-label small mb-1">Move to</label>
                            <select class="form-select mb-2" name="target_class" required>
                                <?php foreach ($pm_classes as $c): ?><option value="<?php echo htmlspecialchars($c['value']); ?>"><?php echo htmlspecialchars($c['label']); ?></option><?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <div class="pm-warn pm-move-block" hidden></div>
                        <div class="pm-note pm-move-info" hidden></div>
                        <label class="mt-2" style="display:flex;gap:8px;align-items:flex-start;"><input type="checkbox" class="form-check-input mt-1" name="notify" value="1"> <span><strong>Email the students of the new class</strong><br><small class="text-muted">Only if you tick this.</small></span></label>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary pm-move-btn" <?php echo $pm_classes ? '' : 'disabled'; ?>>Move</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal fade pm-modal" id="pmDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header"><h5 class="modal-title">Delete this post?</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="section_id" value="<?php echo (int) $section_id; ?>">
                        <input type="hidden" name="subject_code" value="<?php echo htmlspecialchars($subject_code); ?>">
                        <input type="hidden" name="school_year_id" value="<?php echo (int) $school_year_id; ?>">
                        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                        <input type="hidden" name="return_tab" value="<?php echo htmlspecialchars($tab); ?>">
                        <input type="hidden" name="form_action" class="pm-action">
                        <input type="hidden" name="post_id" class="pm-post-id">
                        <input type="hidden" name="assignment_id" class="pm-cw-id">
                        <p class="mb-2">This post will be removed from the classroom stream and will no longer be visible to students.</p>
                        <div class="pm-warn pm-del-warn" hidden></div>
                        <label class="pm-del-item mt-2" style="display:none;gap:8px;align-items:flex-start;"><input type="checkbox" class="form-check-input mt-1" name="remove_item" value="1"> <span>Also remove its item from <strong>Manage Assessment Items</strong> (it has no scores yet)</span></label>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>Delete Post</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal fade pm-modal" id="pmDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title"><i class="fas fa-circle-info me-2"></i>Post details</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body"><table class="table table-sm mb-0 pm-details"></table></div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var CLASS_LABEL = <?php echo json_encode($class['subject_name'] . ' – ' . $class['section_name']); ?>;
        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
        function prep(modalId, data, action) {
            var m = document.getElementById(modalId);
            m.querySelector('.pm-action').value = action;
            m.querySelector('.pm-post-id').value = data.kind === 'post' ? data.id : '';
            m.querySelector('.pm-cw-id').value = data.kind === 'classwork' ? data.id : '';
            return m;
        }
        function show(m) { bootstrap.Modal.getOrCreateInstance(m).show(); }
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-pm-do]');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            var data = JSON.parse(btn.closest('.pm-menu').getAttribute('data-pm'));
            var isPost = data.kind === 'post';
            var what = btn.getAttribute('data-pm-do');
            if (what === 'edit') {
                var m = prep('pmEditModal', data, isPost ? 'pm_edit_post' : 'pm_edit_classwork');
                m.querySelector('.pm-title').textContent = isPost ? 'Edit Post' : 'Edit ' + data.category;
                m.querySelector('.pm-only-post').hidden = !isPost;
                m.querySelector('.pm-only-cw').hidden = isPost;
                var f = m.querySelector('form');
                f.elements['body'].value = isPost ? data.body : '';
                f.elements['link_url'].value = isPost ? data.link : '';
                f.elements['title'].value = isPost ? '' : data.title;
                f.elements['topic'].value = isPost ? '' : data.topic;
                f.elements['instructions'].value = isPost ? '' : data.instructions;
                f.elements['due_date'].value = isPost ? '' : data.due;
                f.elements['lock_after_due'].checked = !isPost && data.lock;
                f.elements['title'].required = f.elements['instructions'].required = !isPost;
                f.querySelector('input[type=file]').value = '';
                m.querySelector('.pm-atts').innerHTML = data.attachments.length
                    ? data.attachments.map(function (a) { return '<label class="pm-att"><input type="checkbox" name="remove_attachments[]" value="' + a.id + '"> Remove <span>' + esc(a.name) + '</span></label>'; }).join('')
                    : '<div class="small text-muted">No attached files. Add new ones below.</div>';
                // Only offered when students were already emailed about this post.
                m.querySelector('.pm-notify').hidden = !data.emailed;
                f.elements['notify'].checked = false;
                m.querySelector('.pm-notify-text').textContent = data.emailed ? data.emailed + ' student(s) were emailed about this post. Tick this to send them an "Updated" email. Nothing is sent if you leave it unticked.' : '';
                show(m);
            } else if (what === 'move') {
                var mm = prep('pmMoveModal', data, isPost ? 'pm_move_post' : 'pm_move_classwork');
                var blocked = !isPost && !data.movable;
                var block = mm.querySelector('.pm-move-block');
                block.hidden = !blocked;
                block.innerHTML = blocked ? '<i class="fas fa-triangle-exclamation me-1"></i>This classwork cannot be moved: ' + data.links.submitted + ' student(s) turned in work and/or ' + Math.max(data.links.scored, data.links.item_scores) + ' have scores. Moving it would separate that work from the class. Delete it and post it again in the right class if needed.' : '';
                var info = mm.querySelector('.pm-move-info');
                info.hidden = isPost || blocked;
                info.innerHTML = '<i class="fas fa-circle-info me-1"></i>It will be assigned to the students of the new class' + (data.has_item ? ', and its item in Manage Assessment Items moves with it' : '') + '. Nobody has turned in work yet, so nothing is lost.';
                var mb = mm.querySelector('.pm-move-btn');
                if (mb) mb.disabled = blocked || !mm.querySelector('select');
                show(mm);
            } else if (what === 'delete') {
                var dm = prep('pmDeleteModal', data, isPost ? 'pm_delete_post' : 'pm_delete_classwork');
                var w = dm.querySelector('.pm-del-warn');
                var parts = [];
                if (!isPost) {
                    if (data.has_item) parts.push('This post is connected to an assessment (Manage Assessment Items). Deleting the classroom post will not automatically delete existing scores or submissions.');
                    if (data.links.submitted || data.links.files) parts.push(data.links.submitted + ' student(s) already turned in work — it is kept.');
                    if (data.links.scored || data.links.item_scores) parts.push(Math.max(data.links.scored, data.links.item_scores) + ' score(s) are recorded — they are kept in Grade Management.');
                }
                w.hidden = !parts.length;
                w.innerHTML = parts.map(function (p) { return '<div><i class="fas fa-triangle-exclamation me-1"></i>' + esc(p) + '</div>'; }).join('');
                var canRemoveItem = !isPost && data.has_item && !data.links.scored && !data.links.item_scores;
                dm.querySelector('.pm-del-item').style.display = canRemoveItem ? 'flex' : 'none';
                dm.querySelector('input[name=remove_item]').checked = false;
                show(dm);
            } else if (what === 'details') {
                var rows = [['Type', isPost ? 'Announcement' : data.category], ['Class', CLASS_LABEL], ['Posted', data.created]];
                if (isPost) {
                    if (data.updated) rows.push(['Last edited', data.updated]);
                    rows.push(['Text', data.body || '—']);
                    if (data.link) rows.push(['Link', data.link]);
                } else {
                    rows.push(['Title', data.title], ['Topic', data.topic || '—'], ['Due', data.due_label + (data.lock ? ' (closed after due)' : '')],
                        ['Assigned to', data.links.roster + ' student(s)'], ['Turned in', data.links.submitted + ' student(s)'],
                        ['Scored', Math.max(data.links.scored, data.links.item_scores) + ' student(s)'],
                        ['In Grade Management', data.has_item ? 'Yes (Manage Assessment Items)' : 'No (not graded)']);
                }
                rows.push(['Files', data.attachments.length ? data.attachments.map(function (a) { return a.name; }).join(', ') : '—'], ['Emails sent', data.emailed + ' student(s)']);
                document.querySelector('#pmDetailsModal .pm-details').innerHTML = rows.map(function (r) {
                    return '<tr><th style="width:36%">' + esc(r[0]) + '</th><td style="white-space:pre-line">' + esc(r[1]) + '</td></tr>';
                }).join('');
                show(document.getElementById('pmDetailsModal'));
            }
        }, true);
    })();
    </script>
    <!-- New Assignment modal (opened from the Stream or the Classwork tab) -->
    <?php $cw_meta = aiAssessmentClassMeta($teacher_id, $section_id, $subject_code, $school_year_id) ?: ['has_exam' => false, 'required_subtypes' => [], 'is_multi' => false, 'components' => []]; ?>
    <div class="modal fade" id="newAssignmentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data" id="newAssignmentForm">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>New Assignment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="form_action" value="create_classwork">
                        <input type="hidden" name="section_id" value="<?php echo $section_id; ?>">
                        <input type="hidden" name="subject_code" value="<?php echo htmlspecialchars($subject_code); ?>">
                        <input type="hidden" name="school_year_id" value="<?php echo (int) $school_year_id; ?>">
                        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                        <input type="hidden" name="ai_generated" id="cwAiGenerated" value="0">
                        <input type="hidden" name="ai_resource_type" id="cwAiResourceType" value="">
                        <input type="hidden" name="ai_teacher_answer_key" id="cwAiAnswerKey" value="">
                        <input type="hidden" name="ai_quiz_questions" id="cwAiQuizQuestions" value="">
                        <input type="hidden" name="return_tab" value="<?php echo $tab === 'stream' ? 'stream' : 'classwork'; ?>">
                        <input type="hidden" name="cw_nonce" id="cwNonce" value="<?php echo bin2hex(random_bytes(12)); ?>">


                        <div class="row g-2 mb-2">
                            <div class="col-md-7">
                                <label class="form-label small mb-1">Title</label>
                                <input type="text" class="form-control" name="title" id="cwTitle" required maxlength="200" placeholder="e.g. Activity 1 – Philippine Poetry">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small mb-1">Assessment Type</label>
                                <select class="form-select" name="category" id="cwCategory">
                                    <?php $cw_groups = []; foreach (CLASSWORK_TYPES as $cw_type => $cw_def) { if ($cw_def[0] === 'QA' && !$cw_meta['has_exam']) continue; $cw_groups[$cw_def[2]][] = $cw_type; } ?>
                                    <?php foreach ($cw_groups as $cw_group => $cw_types): ?>
                                        <optgroup label="<?php echo htmlspecialchars($cw_group); ?>">
                                            <?php foreach ($cw_types as $cw_type): ?>
                                                <option value="<?php echo htmlspecialchars($cw_type); ?>" data-grading="<?php echo htmlspecialchars((string) CLASSWORK_TYPES[$cw_type][0]); ?>" <?php echo $cw_type === 'Written Activity' ? 'selected' : ''; ?>><?php echo htmlspecialchars($cw_type); ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="cw-grading-line" id="cwGradingLine"></div>
                        <label class="form-label small mb-1">Topic <span class="text-muted">(optional — shown in the students' email)</span></label>
                        <input type="text" class="form-control form-control-sm mb-2" name="topic" id="cwTopic" maxlength="200" placeholder="e.g. Philippine Poetry: elements of a poem">

                        <label class="form-label small mb-1">Instructions</label>
                        <textarea class="form-control mb-2" name="instructions" id="cwInstructions" rows="6" required maxlength="12000" placeholder="e.g. Identify and observe the characteristics/elements of Philippine poetry. Then generate the full activity with AI below, or write it yourself."></textarea>

                        <div class="row g-2 mb-2">
                            <div class="col-md-4" id="cwPointsWrap">
                                <label class="form-label small mb-1">Points (highest possible score)</label>
                                <input type="number" class="form-control form-control-sm" name="max_points" id="cwPoints" min="1" max="9999" step="1" value="20">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Due Date &amp; Time</label>
                                <input type="datetime-local" class="form-control form-control-sm" name="due_date">
                                <label class="form-check-label small mt-1" style="display:flex;gap:6px;align-items:center;">
                                    <input type="checkbox" class="form-check-input mt-0" name="lock_after_due" value="1">
                                    Close after the due date (no late turn-in)
                                </label>
                            </div>
                            <div class="col-md-4" id="cwSubtypeWrap" style="display:none;">
                                <label class="form-label small mb-1">Examination</label>
                                <select class="form-select form-select-sm" name="exam_subtype">
                                    <?php foreach (($cw_meta['required_subtypes'] ?: ['TE']) as $cw_st): ?>
                                        <option value="<?php echo $cw_st; ?>"><?php echo htmlspecialchars(gh_examSubtypes()[$cw_st]['label'] ?? $cw_st); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($cw_meta['is_multi']): ?>
                            <div class="col-md-4" id="cwComponentWrap">
                                <label class="form-label small mb-1">Component</label>
                                <select class="form-select form-select-sm" name="component">
                                    <?php foreach ($cw_meta['components'] as $cw_comp): ?>
                                        <option value="<?php echo htmlspecialchars($cw_comp); ?>"><?php echo htmlspecialchars($cw_comp); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Attach lesson / files (PDF, Word, PPT, image…)</label>
                                <input type="file" class="form-control form-control-sm" name="attachments[]" id="cwFiles" multiple>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Also create a file from the instructions</label>
                                <select class="form-select form-select-sm" name="generate_format" id="cwGenerateFormat">
                                    <option value="">No file (text only)</option>
                                </select>
                                <div class="cw-format-hint" id="cwFormatHint"></div>
                            </div>
                        </div>

                        <div class="cw-ai-box">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-1">
                                <strong style="font-size:13px;"><i class="fas fa-robot me-1"></i> Generate with AI</strong>
                                <span class="cw-ai-scope" id="cwAiScope"></span>
                            </div>
                            <div class="ai-chat-box" id="cwAiChatBox"></div>
                            <div class="input-group input-group-sm mt-2">
                                <input type="text" class="form-control" id="cwAiInput" placeholder="e.g. Generate it from the attached lesson and my instructions">
                                <button class="btn btn-outline-secondary" type="button" id="cwAiSendBtn"><i class="fas fa-paper-plane"></i></button>
                            </div>
                            <div id="cwAiPreviewContainer"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn gc-btn-assign">
                            <i class="fas fa-paper-plane me-1"></i> Assign to All Students
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var chatBox = document.getElementById('cwAiChatBox');
        var input = document.getElementById('cwAiInput');
        var sendBtn = document.getElementById('cwAiSendBtn');
        var previewContainer = document.getElementById('cwAiPreviewContainer');
        var catEl = document.getElementById('cwCategory');
        var filesEl = document.getElementById('cwFiles');
        var GRADING = { WW: 'Written Works', PT: 'Performance Task', QA: 'Examination' };
        var fmtEl = document.getElementById('cwGenerateFormat');
        var fmtHint = document.getElementById('cwFormatHint');
        var quizQEl = document.getElementById('cwAiQuizQuestions');
        // "Also create a file" choices per assessment type (same list the server checks).
        var FORMAT_LABELS = <?php echo json_encode(CLASSWORK_OUTPUT_LABELS); ?>;
        var FORMATS = <?php $cw_fmt_map = []; foreach (array_keys(CLASSWORK_TYPES) as $cw_t) { $cw_fmt_map[$cw_t] = classworkOutputFormats($cw_t); } echo json_encode($cw_fmt_map); ?>;
        var GOOGLE_READY = <?php require_once __DIR__ . '/../../includes/google_forms_service.php'; echo googleFormsIsConfigured() ? 'true' : 'false'; ?>;
        var FORMAT_HINTS = {
            '': 'Students see the instructions as text.',
            gform: 'Generate the quiz with AI from the attached lesson, review the questions, then assign — the Google Form is created from them.',
            docx: 'A Word file is made from the final instructions when you assign.',
            gdoc: 'A Google Doc is made from the final instructions and shared by link when you assign.',
            pdf: 'A PDF is made from the final instructions when you assign.'
        };
        var history = [];
        var currentDraft = null;
        var sending = false;

        function grading() { var o = catEl.options[catEl.selectedIndex]; return o ? o.getAttribute('data-grading') : ''; }
        // The grading category comes from the assessment type -- shown so the
        // teacher sees exactly where the score will count.
        function applyType() {
            var g = grading();
            document.getElementById('cwGradingLine').innerHTML = g
                ? '<i class="fas fa-table-list me-1"></i>Counts toward <strong>' + GRADING[g] + '</strong> in Grade Management (saved as an item in Manage Assessment Items).'
                : '<i class="fas fa-circle-info me-1"></i>Learning material only — not graded.';
            document.getElementById('cwPointsWrap').style.display = g ? '' : 'none';
            document.getElementById('cwSubtypeWrap').style.display = g === 'QA' ? '' : 'none';
            var comp = document.getElementById('cwComponentWrap');
            if (comp) comp.style.display = g ? '' : 'none';
            document.getElementById('cwAiScope').textContent = catEl.value + ' · ' + (g ? GRADING[g] : 'Not graded') + ' · ' + <?php echo json_encode($class['subject_name'] . ' · Section ' . $class['section_name'] . ' · ' . $term); ?>;
            applyFormats();
        }

        // Rebuild the file options for the selected type right away; an option
        // that does not fit the new type is never kept.
        function applyFormats() {
            var allowed = FORMATS[catEl.value] || ['', 'pdf', 'docx'];
            var keep = fmtEl.value;
            fmtEl.innerHTML = '';
            allowed.forEach(function (v) {
                var o = document.createElement('option');
                o.value = v;
                var needsGoogle = (v === 'gform' || v === 'gdoc');
                o.textContent = FORMAT_LABELS[v] + (needsGoogle && !GOOGLE_READY ? ' (Google not set up)' : '');
                if (needsGoogle && !GOOGLE_READY) o.disabled = true;
                fmtEl.appendChild(o);
            });
            var usable = allowed.filter(function (v) { return GOOGLE_READY || (v !== 'gform' && v !== 'gdoc'); });
            fmtEl.value = usable.indexOf(keep) !== -1 && keep !== '' ? keep : (usable[0] || '');
            if (allowed.indexOf('gform') === -1) quizQEl.value = '';
            showFormatHint();
        }
        function showFormatHint() {
            var h = FORMAT_HINTS[fmtEl.value] || '';
            if (fmtEl.value === 'gform') {
                var n = 0;
                try { n = (JSON.parse(quizQEl.value || '[]') || []).length; } catch (e) {}
                h = n ? '\u2713 ' + n + ' reviewed question(s) will be turned into the Google Form.' : h;
            }
            fmtHint.textContent = h;
            fmtHint.style.color = (fmtEl.value === 'gform' && !quizQEl.value) ? '#b45309' : '';
        }
        fmtEl.addEventListener('change', showFormatHint);

        function addBubble(role, text) {
            var div = document.createElement('div');
            div.className = 'ai-bubble ' + (role === 'ai' ? 'ai' : 'teacher');
            div.textContent = text;
            chatBox.appendChild(div);
            chatBox.scrollTop = chatBox.scrollHeight;
        }

        function renderPreview(data) {
            previewContainer.innerHTML = '';
            if (data.response_type !== 'resource') return;
            var box = document.createElement('div');
            box.className = 'ai-resource-preview';
            var qs = Array.isArray(data.quiz_questions) ? data.quiz_questions : [];
            var wantsForm = fmtEl.value === 'gform';
            var fileNote = fmtEl.value ? (' &middot; ' + escapeHtml(FORMAT_LABELS[fmtEl.value] || fmtEl.value)) : '';
            var review = '';
            if (wantsForm && qs.length) {
                review = '<div class="cw-quiz-review"><strong>Google Form questions (' + qs.length + ') — review before assigning:</strong><ol>' +
                    qs.map(function (q) {
                        return '<li>' + escapeHtml(q.question) + '<br>' + (q.choices || []).map(function (c, i) {
                            return i === q.correct_index ? '<span class="ok">\u2713 ' + escapeHtml(c) + '</span>' : escapeHtml(c);
                        }).join(' &middot; ') + ' <span class="text-muted">(' + (q.points || 1) + ' pt)</span></li>';
                    }).join('') + '</ol></div>';
            } else if (wantsForm) {
                review = '<div class="cw-format-hint" style="color:#b45309;">No multiple-choice questions came back for the Google Form. Ask the AI: "Make it multiple choice for Google Form".</div>';
            }
            box.innerHTML =
                '<div style="font-size:12px;"><strong>' + escapeHtml(catEl.value) + ':</strong> ' + escapeHtml(data.title || '') + fileNote + '</div>' +
                (data.lesson_topic ? '<div class="cw-format-hint"><i class="fas fa-book-open me-1"></i>Lesson topic detected: <strong>' + escapeHtml(data.lesson_topic) + '</strong></div>' : '') +
                '<div class="cw-preview-text">' + escapeHtml((data.student_content || '').slice(0, 800)) + '</div>' +
                review +
                '<button type="button" class="btn btn-sm btn-outline-primary" id="cwUseAiContentBtn">Use this content</button>';
            previewContainer.appendChild(box);
            document.getElementById('cwUseAiContentBtn').addEventListener('click', function () {
                var t = document.getElementById('cwTitle');
                if (!t.value.trim()) t.value = data.title || '';
                document.getElementById('cwInstructions').value = data.student_content || '';
                document.getElementById('cwAiGenerated').value = '1';
                document.getElementById('cwAiResourceType').value = data.resource_type || '';
                document.getElementById('cwAiAnswerKey').value = data.teacher_answer_key || '';
                var tp = document.getElementById('cwTopic');
                if (tp && !tp.value.trim() && data.lesson_topic) tp.value = data.lesson_topic;
                quizQEl.value = qs.length ? JSON.stringify(qs) : '';
                // The assessment type stays exactly what the teacher selected;
                // a file type the AI suggests is used only if it fits that type.
                var allowed = FORMATS[catEl.value] || [];
                if (data.output_format && allowed.indexOf(data.output_format) !== -1) {
                    fmtEl.value = data.output_format;
                }
                showFormatHint();
                this.textContent = '\u2713 Content applied — review it above, then assign';
            });
        }

        function escapeHtml(s) {
            var d = document.createElement('div');
            d.textContent = s || '';
            return d.innerHTML;
        }

        function send(opts) {
            opts = opts || {};
            if (sending) return;
            var message = input.value.trim();
            if (message === '' && history.length > 0 && !opts.greet) return;
            if (message !== '') { addBubble('teacher', message); history.push({role: 'teacher', text: message}); }
            input.value = '';
            sending = true;
            sendBtn.disabled = true;

            var fd = new FormData();
            fd.append('csrf_token', <?php echo json_encode($csrf_token); ?>);
            fd.append('section_id', <?php echo (int) $section_id; ?>);
            fd.append('subject_code', <?php echo json_encode($subject_code); ?>);
            fd.append('school_year_id', <?php echo (int) $school_year_id; ?>);
            fd.append('term', <?php echo json_encode($term); ?>);
            fd.append('message', message);
            fd.append('history', JSON.stringify(history));
            fd.append('current_draft', currentDraft ? JSON.stringify(currentDraft) : '');
            // What the teacher set up in the form -- the AI follows it.
            fd.append('assessment_type', catEl.value);
            fd.append('assignment_title', document.getElementById('cwTitle').value);
            fd.append('assignment_instructions', document.getElementById('cwInstructions').value);
            fd.append('max_points', document.getElementById('cwPoints').value);
            fd.append('output_choice', fmtEl.value);
            if (filesEl && filesEl.files) {
                for (var i = 0; i < filesEl.files.length && i < 3; i++) fd.append('lesson_files[]', filesEl.files[i]);
            }

            fetch('classroom_ai_chat.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    sending = false;
                    sendBtn.disabled = false;
                    if (!data.success) {
                        addBubble('ai', data.error || 'The AI assistant is unavailable right now.');
                        return;
                    }
                    addBubble('ai', data.reply);
                    history.push({role: 'ai', text: data.reply});
                    if (data.response_type === 'resource') {
                        currentDraft = {
                            resource_type: data.resource_type,
                            title: data.title,
                            student_content: data.student_content,
                            teacher_answer_key: data.teacher_answer_key,
                        };
                        renderPreview(data);
                    }
                })
                .catch(function () {
                    sending = false;
                    sendBtn.disabled = false;
                    addBubble('ai', 'Connection issue — please try again.');
                });
        }

        sendBtn.addEventListener('click', function () { send(); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); send(); }
        });

        // Greeting follows the selected type; changing the type (or the
        // attached lesson) before anything was generated greets again.
        function regreet() {
            if (currentDraft || sending) return;
            chatBox.innerHTML = '';
            history = [];
            send({ greet: true });
        }
        catEl.addEventListener('change', function () { applyType(); regreet(); });
        if (filesEl) filesEl.addEventListener('change', regreet);
        applyType();

        // Assign: a Google Form needs the reviewed AI questions; one click posts once.
        var formEl = document.getElementById('newAssignmentForm');
        formEl.addEventListener('submit', function (e) {
            if (fmtEl.value === 'gform' && !quizQEl.value) {
                e.preventDefault();
                fmtHint.textContent = 'Generate the quiz with AI first, review the questions and click "Use this content" — the Google Form is made from them. Or choose "No file".';
                fmtHint.style.color = '#b91c1c';
                fmtEl.focus();
                return;
            }
            var btn = formEl.querySelector('button[type=submit]');
            if (formEl.dataset.sent === '1') { e.preventDefault(); return; }
            formEl.dataset.sent = '1';
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Assigning…'; }
        });

        var modalEl = document.getElementById('newAssignmentModal');
        var greeted = false;
        modalEl.addEventListener('shown.bs.modal', function () {
            if (!greeted) { greeted = true; send({ greet: true }); }
        });
        // "+ Create" from the Stream opens the New Assignment form right away.
        if (/[?&]create=1\b/.test(location.search)) {
            window.addEventListener('load', function () { bootstrap.Modal.getOrCreateInstance(modalEl).show(); });
            window.history.replaceState(null, '', location.href.replace(/&create=1\b/, ''));
        }
    })();
    </script>
    <?php endif; ?>

    <?php if ($tab === 'people'): ?>
    <?php
        // Initials for the round avatar chip -- first letter of first + last
        // name, display only (same idea as the sidebar's profile avatar).
        $__gc_initials = function ($first, $last) {
            $first = trim((string) $first);
            $last = trim((string) $last);
            $s = mb_substr($first, 0, 1) . mb_substr($last, 0, 1);
            return $s !== '' ? mb_strtoupper($s) : '?';
        };
    ?>
    <div id="people">
        <div class="classwork-card">
            <h6 class="mb-2"><i class="fas fa-chalkboard-teacher me-2"></i>Teacher</h6>
            <div class="roster-list">
                <div class="roster-row">
                    <div class="roster-avatar"><?php echo htmlspecialchars($__gc_initials($teacher['first_name'], $teacher['last_name'])); ?></div>
                    <div>
                        <div class="roster-name"><?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?></div>
                        <?php if (!empty($teacher['email'])): ?><div class="roster-meta"><?php echo htmlspecialchars($teacher['email']); ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="classwork-card">
            <h6 class="mb-2"><i class="fas fa-user-graduate me-2"></i>Students <span class="badge bg-secondary"><?php echo count($roster); ?></span></h6>
            <?php if (empty($roster)): ?>
                <div class="empty-state"><i class="fas fa-user-graduate fa-2x"></i>No students are enrolled in this section yet.</div>
            <?php else: ?>
                <div class="roster-list">
                    <?php foreach ($roster as $stu): ?>
                    <div class="roster-row">
                        <div class="roster-avatar"><?php echo htmlspecialchars($__gc_initials($stu['first_name'], $stu['last_name'])); ?></div>
                        <div class="flex-grow-1">
                            <div class="roster-name"><?php echo htmlspecialchars($stu['last_name'] . ', ' . $stu['first_name'] . (!empty($stu['middle_name']) ? ' ' . mb_substr($stu['middle_name'], 0, 1) . '.' : '')); ?></div>
                            <div class="roster-meta">LRN <?php echo htmlspecialchars($stu['lrn'] ?? '—'); ?><?php if (!empty($stu['email'])) echo ' &middot; ' . htmlspecialchars($stu['email']); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
