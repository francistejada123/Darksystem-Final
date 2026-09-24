<?php
// modules/teachers/classroom_download.php
// Ownership-checked file server for Stream-post and Classwork attachments.
// Same convention as modules/ai/download_resource.php: a random stored
// filename on disk, the real name only in the database, and access is
// re-derived here from teacher_subjects on every request rather than
// trusting that the page that linked here already checked it.
//
// ?kind=stream|classwork & id=<attachment_id>

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/classroom_helpers.php';
require_once __DIR__ . '/../../includes/classwork_helpers.php'; // CLASSWORK_SUBMISSION_DIR -- students' turned-in files

if (!hasRole('Teacher')) {
    http_response_code(403);
    exit('Unauthorized.');
}

$teacher_id = (int) ($_SESSION['user_id'] ?? 0);
$kind = in_array(($_GET['kind'] ?? ''), ['classwork', 'submission'], true) ? $_GET['kind'] : 'stream';
$attachment_id = (int) ($_GET['id'] ?? 0);

if (!$attachment_id) {
    http_response_code(400);
    exit('Missing attachment.');
}

if ($kind === 'stream') {
    $row = getRecord(
        "SELECT a.attachment_id, a.original_filename, a.stored_filename, a.mime_type, a.attachment_type, a.external_url,
                p.teacher_id, p.section_id, p.subject_code, p.school_year_id, p.term
         FROM class_stream_attachments a
         JOIN class_stream_posts p ON a.post_id = p.post_id
         WHERE a.attachment_id = ? AND p.is_deleted = 0",
        [$attachment_id]
    );
} elseif ($kind === 'submission') {
    // A student's turned-in file -- only the teacher who assigned the work.
    classworkEnsureSchema();
    $row = getRecord(
        "SELECT f.file_id AS attachment_id, f.original_filename, f.stored_filename, f.mime_type, 'file' AS attachment_type, NULL AS external_url,
                ca.teacher_id, ca.section_id, ca.subject_code, ca.school_year_id, ca.term
         FROM classwork_submission_files f
         JOIN classwork_assignments ca ON f.assignment_id = ca.assignment_id
         WHERE f.file_id = ?",
        [$attachment_id]
    );
} else {
    $row = getRecord(
        "SELECT a.attachment_id, a.original_filename, a.stored_filename, a.mime_type, a.attachment_type, a.external_url,
                ca.teacher_id, ca.section_id, ca.subject_code, ca.school_year_id, ca.term
         FROM classwork_assignment_attachments a
         JOIN classwork_assignments ca ON a.assignment_id = ca.assignment_id
         WHERE a.attachment_id = ?",
        [$attachment_id]
    );
}

if (!$row) {
    http_response_code(404);
    exit('Attachment not found.');
}

// Ownership: only the teacher who owns this class may fetch its
// attachments. (Phase 1 of the Classroom redesign is teacher-facing only —
// there is no student-side consumption of these attachments yet.)
if ((int) $row['teacher_id'] !== $teacher_id) {
    http_response_code(403);
    exit('You are not authorized to access this attachment.');
}
$check = classroomValidateAccess($teacher_id, $row['section_id'], $row['subject_code'], $row['school_year_id'], $row['term']);
if (!$check['success']) {
    http_response_code(403);
    exit('You are not authorized to access this attachment.');
}

if ($row['attachment_type'] === 'link') {
    header('Location: ' . $row['external_url']);
    exit;
}

$dir = $kind === 'submission' ? CLASSWORK_SUBMISSION_DIR : classroomUploadDirFor($kind);
$path = realpath($dir . $row['stored_filename']);
$storageRoot = realpath($dir);
if ($path === false || $storageRoot === false || strpos($path, $storageRoot) !== 0 || !is_readable($path)) {
    http_response_code(404);
    exit('File not found.');
}

$mime = $row['mime_type'] ?: 'application/octet-stream';

if (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($row['original_filename'] ?: $row['stored_filename']) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
