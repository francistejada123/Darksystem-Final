<?php
// modules/ai/view_submission_answer.php
// Streams a STUDENT's answered file (attached back for a manual-check
// activity — see includes/ai_resource_submission_helpers.php) INLINE. See
// download_submission_answer.php for the attachment version, and
// modules/ai/view_resource.php (the TEACHER's original generated file) for
// the sibling endpoint this one is modeled on — same ownership-rule shape,
// deliberately kept as its own self-contained file rather than a shared
// include, for the same auditability reason view_resource.php gives.
//
// SECURITY: never trusts a filename from the browser — only a numeric
// submission_id, looked up server-side. Exactly two callers are ever
// allowed to fetch a submission's answered file:
//   - the TEACHER who owns the parent resource (ai_generated_resources.
//     teacher_id matches the logged-in staff session), or
//   - the STUDENT who submitted it (ai_resource_submissions.student_id
//     matches the student portal session) — a student can never see
//     another student's attached answer this way.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php'; // also does session_start() if needed
require_once __DIR__ . '/../../includes/ai_resource_submission_helpers.php';

$submission_id = (int) ($_GET['id'] ?? 0);
if (!$submission_id) {
    http_response_code(400);
    exit('Missing submission.');
}

$submission = aiResourceGetSubmissionFile($submission_id);
if (!$submission || empty($submission['answer_stored_filename'])) {
    http_response_code(404);
    exit('Submitted file not found.');
}

$authorized = false;

if (hasRole('Teacher') && (int) $submission['teacher_id'] === (int) ($_SESSION['user_id'] ?? 0)) {
    $authorized = true;
} elseif (($_SESSION['role'] ?? '') === 'Student' && !empty($_SESSION['student_id'])) {
    $authorized = (int) $submission['student_id'] === (int) $_SESSION['student_id'];
}

if (!$authorized) {
    http_response_code(403);
    exit('You are not authorized to view this file.');
}

$path = realpath(AI_RESOURCE_ANSWER_UPLOAD_DIR . $submission['answer_stored_filename']);
$storageRoot = realpath(AI_RESOURCE_ANSWER_UPLOAD_DIR);
// Belt-and-suspenders: answer_stored_filename is always our own random
// bin2hex(16)+extension, but never trust a filesystem path built from a
// database value without also confirming it still resolves inside the
// expected directory (blocks any theoretical path-traversal value).
if ($path === false || $storageRoot === false || strpos($path, $storageRoot) !== 0 || !is_readable($path)) {
    http_response_code(404);
    exit('Submitted file not found.');
}

$mime = $submission['answer_mime_type'] ?: 'application/octet-stream';

if (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($submission['answer_original_filename'] ?: $submission['answer_stored_filename']) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
