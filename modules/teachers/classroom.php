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
classworkEnsureSchema();

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
            $_SESSION['success_message'] = 'Announcement posted.';
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

    // ---- Create a classwork assignment (Classwork tab) ----
    if (($_POST['form_action'] ?? '') === 'create_classwork') {
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
        $generate_format = in_array(($_POST['generate_format'] ?? ''), ['pdf', 'docx'], true) ? $_POST['generate_format'] : null;

        if ($title === '' || $instructions === '') {
            $_SESSION['error_message'] = 'Please provide a title and instructions/content for this assignment.';
            header('Location: classroom.php?' . $redirect_qs . '&tab=classwork#classwork');
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
                header('Location: classroom.php?' . $redirect_qs . '&tab=classwork#classwork');
                exit;
            }
            $ensured = aiAssessmentEnsureItem(
                $teacher_id, $post_section_id, $post_subject_code, $post_sy_id, $post_term,
                $grading_category, trim((string) ($_POST['exam_subtype'] ?? '')), trim((string) ($_POST['component'] ?? '')), $title, $max_points
            );
            if (empty($ensured['success'])) {
                $_SESSION['error_message'] = 'Not assigned: ' . $ensured['error'];
                header('Location: classroom.php?' . $redirect_qs . '&tab=classwork#classwork');
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

        if (!$assignment_id) {
            $_SESSION['error_message'] = 'Could not save the assignment. Please try again.';
            header('Location: classroom.php?' . $redirect_qs . '&tab=classwork#classwork');
            exit;
        }

        // Optional: generate an actual downloadable file from the final,
        // teacher-reviewed content -- only now, at save time, never during
        // AI chat preview (see classroom_ai_chat.php's header comment for
        // why: never create a file for a draft that's never posted).
        if ($generate_format !== null) {
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
            $file_result = aiGenerateResourceFile($resource, $doc_context, $generate_format, 'student');
            if ($file_result['success']) {
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
                $_SESSION['error_message'] = 'The assignment was posted, but the ' . strtoupper($generate_format) . ' file could not be generated: ' . $file_result['error'];
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
        classworkNotifyStudents(
            ['title' => $title, 'category' => $category, 'due_date' => $due_date, 'subject_code' => $post_subject_code, 'school_year_id' => $post_sy_id],
            array_column($roster, 'student_id'),
            $teacher_id
        );

        logActivity($teacher_id, 'Classroom', 'Posted classwork', "Assignment #$assignment_id ($category, $title), subject $post_subject_code, section #$post_section_id");
        $_SESSION['success_message'] = '"' . $title . '" was assigned to ' . count($roster) . ' student(s) of ' . ($post_class['section_name'] ?? 'this section') . '.'
            . ($item ? ' Saved in Manage Assessment Items as "' . html_entity_decode($item['item_name'], ENT_QUOTES, 'UTF-8') . '" under ' . CLASSWORK_GRADING_LABELS[$grading_category] . ' (' . rtrim(rtrim(number_format($max_points, 2), '0'), '.') . ' pts).' : ' Not graded (learning material).');
        header('Location: classroom.php?' . $redirect_qs . '&tab=classwork#classwork');
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
         WHERE ca.teacher_id = ? AND ca.section_id = ? AND ca.subject_code = ? AND ca.school_year_id = ?
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
    }

    $roster = classroomSectionStudents($section_id, $school_year_id);

    // Quizzes / activities sent to this class from Teacher AI (with each
    // student's status and score), and students' comments on messages.
    $online_work = classroomOnlineWork($teacher_id, $section_id, $subject_code, $school_year_id, $roster);
    $student_comments = classroomStudentComments($teacher_id, $section_id, $subject_code, $school_year_id);
}

$csrf_token = generateCSRFToken();
include '../../includes/header.php';
?>

<style>
    /* Online quizzes/activities (Classwork) and student comments (Stream) */
    .cr-pills { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
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
            <a class="stream-post gc-post gc-post-work" href="<?php echo $gc_classwork_url; ?>">
                <span class="gc-work-icon"><i class="fas <?php echo $f['kind'] === 'work' ? 'fa-clipboard-list' : 'fa-file-lines'; ?>"></i></span>
                <div>
                    <div class="gc-post-name"><?php echo htmlspecialchars($gc_teacher_name); ?> posted a new assignment: <?php echo htmlspecialchars($w_title); ?></div>
                    <div class="gc-post-date"><?php echo date('M j, Y · g:i A', strtotime($f['time'])); ?><?php if ($f['kind'] === 'work' && $w['window']): ?> · <?php echo htmlspecialchars($w['window']); ?><?php endif; ?></div>
                </div>
            </a>
            <?php endif; ?>
        <?php endforeach; endif; ?>
        </div><!-- /.gc-main -->
    </div>
    <?php endif; ?>

    <?php if ($tab === 'classwork'): ?>
    <div id="classwork">
        <div class="gc-cw-head">
            <button type="button" class="gc-btn-create" data-bs-toggle="modal" data-bs-target="#newAssignmentModal">
                <i class="fas fa-plus"></i> Create
            </button>
            <span class="gc-cw-hint">Online quizzes and activities from Teacher AI show here automatically with each student's status.</span>
        </div>

        <?php foreach ($online_work['prepared'] as $pw): ?>
            <div class="classwork-card cr-prepared gc-work">
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
            <div class="classwork-card cr-online gc-work">
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
                <details class="cr-students">
                    <summary>View students</summary>
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
                </details>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($classwork_items) && empty($online_work['sent']) && empty($online_work['prepared'])): ?>
            <div class="empty-state"><i class="fas fa-tasks fa-2x"></i>No classwork has been posted for this class yet.</div>
        <?php else: foreach ($classwork_items as $item):
            $cw_grading = $item['grading_category'] ?? classworkGradingCategory($item['category']);
            $cw_open = (int) ($_GET['open'] ?? 0) === (int) $item['assignment_id'];
        ?>
            <div class="classwork-card gc-work" id="cw-<?php echo (int) $item['assignment_id']; ?>">
                <span class="gc-work-icon <?php echo $cw_grading === 'PT' ? 'is-pt' : ($cw_grading === 'QA' ? 'is-exam' : ($cw_grading ? '' : 'is-assignment')); ?>"><i class="fas <?php echo $cw_grading === 'PT' ? 'fa-star' : ($cw_grading === 'QA' ? 'fa-file-signature' : ($cw_grading ? 'fa-clipboard-list' : 'fa-book-open')); ?>"></i></span>
                <div class="gc-work-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <span class="badge category-badge mb-1 cw-cat-<?php echo strtolower($cw_grading ?: 'none'); ?>"><?php echo htmlspecialchars($item['category']); ?> · <?php echo htmlspecialchars($cw_grading ? CLASSWORK_GRADING_LABELS[$cw_grading] : 'Not graded'); ?><?php if (!empty($item['max_points'])): ?> · <?php echo rtrim(rtrim(number_format((float) $item['max_points'], 2), '0'), '.'); ?> pts<?php endif; ?></span>
                        <h6 class="mb-1"><?php echo htmlspecialchars($item['title']); ?></h6>
                    </div>
                    <div class="text-end text-muted" style="font-size:12px;">
                        Assigned <?php echo date('M j, Y g:i A', strtotime($item['assigned_at'] ?? $item['created_at'])); ?><br>
                        <?php if (!empty($item['due_date'])): ?>Due <?php echo date('M j, Y g:i A', strtotime($item['due_date'])); ?><br><?php else: ?>No due date<br><?php endif; ?>
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
                                <i class="fas <?php echo classroomMimeIcon($att['mime_type'], $att['original_filename']); ?>"></i>
                                <?php echo htmlspecialchars($att['original_filename']); ?>
                                <span class="text-muted"><?php echo classroomFileSizeLabel($att['file_size']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
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
                <details class="cr-students" <?php echo $cw_open ? 'open' : ''; ?>>
                    <summary>Student work</summary>
                    <table>
                        <thead><tr><th>Student</th><th>Status</th><th>Work</th><?php if ($cw_grading): ?><th>Score<?php echo !empty($item['max_points']) ? ' / ' . rtrim(rtrim(number_format((float) $item['max_points'], 2), '0'), '.') : ''; ?></th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($item['students'] as $st): ?>
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
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($cw_grading && !empty($item['assessment_item_id'])): ?>
                        <p class="cw-note"><i class="fas fa-table-list me-1"></i>AI checks turned-in work first and records the score; open the student's file and change the score any time — your score always wins. Scores are saved to Manage Assessment Items → <?php echo htmlspecialchars(CLASSWORK_GRADING_LABELS[$cw_grading]); ?> only.</p>
                    <?php endif; ?>
                </details>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- New Assignment modal -->
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
                                    <option value="pdf">PDF</option>
                                    <option value="docx">Word (DOCX)</option>
                                </select>
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
        }

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
            var fileNote = data.output_format ? (' &middot; will generate a ' + data.output_format.toUpperCase() + ' file') : '';
            box.innerHTML =
                '<div style="font-size:12px;"><strong>' + escapeHtml(catEl.value) + ':</strong> ' + escapeHtml(data.title || '') + fileNote + '</div>' +
                '<div class="cw-preview-text">' + escapeHtml((data.student_content || '').slice(0, 800)) + '</div>' +
                '<button type="button" class="btn btn-sm btn-outline-primary" id="cwUseAiContentBtn">Use this content</button>';
            previewContainer.appendChild(box);
            document.getElementById('cwUseAiContentBtn').addEventListener('click', function () {
                var t = document.getElementById('cwTitle');
                if (!t.value.trim()) t.value = data.title || '';
                document.getElementById('cwInstructions').value = data.student_content || '';
                document.getElementById('cwAiGenerated').value = '1';
                document.getElementById('cwAiResourceType').value = data.resource_type || '';
                document.getElementById('cwAiAnswerKey').value = data.teacher_answer_key || '';
                // The assessment type stays exactly what the teacher selected.
                if (data.output_format) {
                    document.getElementById('cwGenerateFormat').value = data.output_format;
                }
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

        var modalEl = document.getElementById('newAssignmentModal');
        var greeted = false;
        modalEl.addEventListener('shown.bs.modal', function () {
            if (!greeted) { greeted = true; send({ greet: true }); }
        });
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
