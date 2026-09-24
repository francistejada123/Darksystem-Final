<?php
// modules/students/portal/classwork_action.php
//
// "Your Work" on a student's subject page (Classroom classwork):
//   add_files  -- attach one or more files (PDF, Word, PPT, image, ...)
//   remove_file-- remove an attached file before turning in
//   turn_in    -- Turn In. Allowed after the due date too: it is then saved
//                 as late ("Missing – Submitted Late"), never blocked.
//   unsubmit   -- take it back to change it (not once the teacher graded it)
// Only the student's own row for an assignment of their own section.

session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/classwork_helpers.php';

if (!isset($_SESSION['student_id']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: ../../../student_login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}
classworkEnsureSchema();

$student_id = (int) $_SESSION['student_id'];
$assignment_id = (int) ($_POST['assignment_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

$row = getRecord(
    "SELECT cs.*, ca.subject_code, ca.title, ca.due_date, ca.teacher_id, ca.status AS assignment_status
     FROM classwork_assignment_students cs
     INNER JOIN classwork_assignments ca ON ca.assignment_id = cs.assignment_id
     WHERE cs.assignment_id = ? AND cs.student_id = ?",
    [$assignment_id, $student_id]
);
$back = $row ? 'subject.php?subject=' . urlencode($row['subject_code']) : 'dashboard.php';
$go = function ($params) use ($back, $assignment_id, $row) {
    header('Location: ' . $back . ($row ? '&' . http_build_query($params + ['cw' => $assignment_id]) : ''));
    exit;
};

if (!$row || $row['assignment_status'] !== 'Published') {
    $go(['submit_error' => 'That classwork could not be found.']);
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $go(['submit_error' => 'Invalid security token. Please try again.']);
}
$graded = $row['status'] === 'Reviewed' && $row['score'] !== null;
$turnedIn = in_array($row['status'], ['Submitted', 'Reviewed'], true);

if ($action === 'add_files') {
    if ($turnedIn) {
        $go(['submit_error' => 'Unsubmit first to change your work.']);
    }
    $saved = 0;
    $error = '';
    if (isset($_FILES['work_files']) && is_array($_FILES['work_files']['name'] ?? null)) {
        foreach ($_FILES['work_files']['name'] as $i => $name) {
            if (($_FILES['work_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $r = classworkSaveSubmissionFile([
                'name' => $name, 'type' => $_FILES['work_files']['type'][$i] ?? '', 'tmp_name' => $_FILES['work_files']['tmp_name'][$i] ?? '',
                'error' => $_FILES['work_files']['error'][$i], 'size' => $_FILES['work_files']['size'][$i] ?? 0,
            ]);
            if (!$r['success']) { $error = $name . ': ' . $r['error']; continue; }
            insertRecord(
                "INSERT INTO classwork_submission_files (assignment_id, student_id, original_filename, stored_filename, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?)",
                [$assignment_id, $student_id, $r['original_filename'], $r['stored_filename'], $r['mime_type'], $r['file_size']]
            );
            $saved++;
        }
    }
    if ($error !== '') {
        $go(['submit_error' => $error]);
    }
    $go($saved ? ['cw_msg' => $saved . ' file(s) added. Click Turn In when you are done.'] : ['submit_error' => 'Choose a file first.']);
}

if ($action === 'remove_file') {
    if ($turnedIn) {
        $go(['submit_error' => 'Unsubmit first to change your work.']);
    }
    $f = getRecord("SELECT * FROM classwork_submission_files WHERE file_id = ? AND assignment_id = ? AND student_id = ?", [(int) ($_POST['file_id'] ?? 0), $assignment_id, $student_id]);
    if ($f) {
        executeQuery("DELETE FROM classwork_submission_files WHERE file_id = ?", [(int) $f['file_id']]);
        @unlink(CLASSWORK_SUBMISSION_DIR . basename($f['stored_filename']));
    }
    $go(['cw_msg' => 'File removed.']);
}

if ($action === 'turn_in') {
    if ($turnedIn) {
        $go(['cw_msg' => 'Already turned in.']);
    }
    $late = !empty($row['due_date']) && date('Y-m-d H:i:s') > $row['due_date'] ? 1 : 0;
    executeQuery(
        "UPDATE classwork_assignment_students SET status = 'Submitted', submitted_at = NOW(), is_late = ? WHERE assignment_id = ? AND student_id = ?",
        [$late, $assignment_id, $student_id]
    );
    $name = getRecord("SELECT TRIM(CONCAT(first_name, ' ', last_name)) AS n FROM students WHERE student_id = ?", [$student_id]);
    executeQuery(
        "INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (?, ?, ?, 'info', ?, NOW())",
        [(int) $row['teacher_id'], ($name['n'] ?? 'A student') . ' turned in ' . $row['title'] . ($late ? ' (late)' : ''),
         'Classwork: "' . $row['title'] . '" was turned in' . ($late ? ' after the due date.' : '.'),
         BASE_URL . 'modules/teachers/classroom.php?section_id=' . (int) (getRecord("SELECT section_id FROM classwork_assignments WHERE assignment_id = ?", [$assignment_id])['section_id'] ?? 0)
            . '&subject_code=' . urlencode($row['subject_code']) . '&tab=classwork&open=' . $assignment_id . '#cw-' . $assignment_id]
    );
    // AI checks it right away and records the score; the teacher reviews
    // it and can change the score at any time.
    $ai = null;
    $a = getRecord("SELECT grading_category, category FROM classwork_assignments WHERE assignment_id = ?", [$assignment_id]);
    $hasFiles = getRecord("SELECT COUNT(*) AS n FROM classwork_submission_files WHERE assignment_id = ? AND student_id = ?", [$assignment_id, $student_id]);
    if ($a && ($a['grading_category'] ?: classworkGradingCategory($a['category'])) && (int) ($hasFiles['n'] ?? 0) > 0) {
        $ai = classworkAiCheck($assignment_id, $student_id);
    }
    $msg = $late ? 'Turned in late. Your teacher will see it as "Missing – Submitted Late".' : 'Turned in!';
    if ($ai && $ai['success']) {
        $msg .= ' It was checked by AI; your teacher will review it and may adjust the score.';
    } elseif ($ai) {
        $msg .= ' Your teacher will check it.';
    }
    $go(['cw_msg' => $msg]);
}

if ($action === 'unsubmit') {
    if ($graded) {
        $go(['submit_error' => 'This was already checked, so it can no longer be unsubmitted. Ask your teacher if you need to change it.']);
    }
    executeQuery(
        "UPDATE classwork_assignment_students SET status = 'Assigned', submitted_at = NULL, is_late = 0 WHERE assignment_id = ? AND student_id = ?",
        [$assignment_id, $student_id]
    );
    $go(['cw_msg' => 'Unsubmitted. Add or change your files, then Turn In again.']);
}

$go(['submit_error' => 'Unknown action.']);
