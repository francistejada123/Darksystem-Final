<?php
// modules/ai/download_submission_answer.php
// Same submitted file as view_submission_answer.php, but forces a download
// (Content-Disposition: attachment) instead of opening inline. Same
// ownership rule, deliberately duplicated rather than shared — see the
// header comment in view_submission_answer.php for the full rationale and
// the exact authorization contract (the teacher who owns the parent
// resource, or the student who submitted it — never another student).

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
    exit('You are not authorized to download this file.');
}

$path = realpath(AI_RESOURCE_ANSWER_UPLOAD_DIR . $submission['answer_stored_filename']);
$storageRoot = realpath(AI_RESOURCE_ANSWER_UPLOAD_DIR);
if ($path === false || $storageRoot === false || strpos($path, $storageRoot) !== 0 || !is_readable($path)) {
    http_response_code(404);
    exit('Submitted file not found.');
}

$mime = $submission['answer_mime_type'] ?: 'application/octet-stream';

if (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($submission['answer_original_filename'] ?: $submission['answer_stored_filename']) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
