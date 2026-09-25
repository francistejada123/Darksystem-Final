<?php
// modules/students/portal/classwork_file.php
//
// Student-side file access for Classroom classwork:
//   ?type=attachment&id=  the teacher's file on an assignment given to me
//   ?type=mine&id=        a file I attached to my own work
//   ?type=stream&id=      a file / link on a Stream announcement of my class
// &download=1 forces a download instead of opening it in the browser.

session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/classwork_helpers.php';

function classworkFileDeny($code, $msg)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg);
}

if (!isset($_SESSION['student_id']) || ($_SESSION['role'] ?? '') !== 'Student') {
    classworkFileDeny(403, 'Please log in.');
}
classworkEnsureSchema();
$student_id = (int) $_SESSION['student_id'];
$id = (int) ($_GET['id'] ?? 0);

if (($_GET['type'] ?? '') === 'stream') {
    // only announcements of a class this student is enrolled in (and not deleted)
    $row = getRecord(
        "SELECT a.original_filename, a.stored_filename, a.mime_type, a.attachment_type, a.external_url
         FROM class_stream_attachments a
         INNER JOIN class_stream_posts p ON p.post_id = a.post_id AND p.is_deleted = 0
         INNER JOIN enrollments e ON e.section_id = p.section_id AND e.school_year_id = p.school_year_id AND e.student_id = ? AND e.enrollment_status = 'Approved'
         WHERE a.attachment_id = ?",
        [$student_id, $id]
    );
    $dir = classroomUploadDirFor('stream');
} elseif (($_GET['type'] ?? '') === 'mine') {
    $row = getRecord("SELECT original_filename, stored_filename, mime_type, 'file' AS attachment_type, NULL AS external_url FROM classwork_submission_files WHERE file_id = ? AND student_id = ?", [$id, $student_id]);
    $dir = CLASSWORK_SUBMISSION_DIR;
} else {
    $row = getRecord(
        "SELECT a.original_filename, a.stored_filename, a.mime_type, a.attachment_type, a.external_url
         FROM classwork_assignment_attachments a
         INNER JOIN classwork_assignments ca ON ca.assignment_id = a.assignment_id AND ca.status = 'Published'
         INNER JOIN classwork_assignment_students cs ON cs.assignment_id = a.assignment_id AND cs.student_id = ?
         WHERE a.attachment_id = ?",
        [$student_id, $id]
    );
    $dir = classroomUploadDirFor('classwork');
}
if (!$row) {
    classworkFileDeny(404, 'File not found.');
}
if ($row['attachment_type'] === 'link') {
    if (!preg_match('#^https?://#i', (string) $row['external_url'])) classworkFileDeny(404, 'Link not available.');
    header('Location: ' . $row['external_url']);
    exit;
}
$path = realpath($dir . basename((string) $row['stored_filename']));
$root = realpath($dir);
if ($path === false || $root === false || strpos($path, $root) !== 0 || !is_readable($path)) {
    classworkFileDeny(404, 'File not found.');
}
$name = str_replace(['"', "\r", "\n"], '', basename($row['original_filename'] ?: $row['stored_filename']));
header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
