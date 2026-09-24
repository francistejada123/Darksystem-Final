<?php
// modules/ai/download_resource.php
// Same resource as view_resource.php, but forces a download
// (Content-Disposition: attachment) instead of opening inline. Same
// ownership rule, deliberately duplicated rather than shared — see the
// header comment in view_resource.php for the full rationale and the
// exact authorization contract (teacher owner, or the student it was
// actually SENT to, and never a teacher-audience answer-key file to a
// student under any circumstance).

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php'; // also does session_start() if needed

$resource_id = (int) ($_GET['id'] ?? 0);
if (!$resource_id) {
    http_response_code(400);
    exit('Missing resource.');
}

$resource = getRecord("SELECT * FROM ai_generated_resources WHERE resource_id = ?", [$resource_id]);
if (!$resource || empty($resource['stored_filename']) || empty($resource['output_format'])) {
    http_response_code(404);
    exit('Resource not found.');
}

$authorized = false;

if (hasRole('Teacher') && (int) $resource['teacher_id'] === (int) ($_SESSION['user_id'] ?? 0)) {
    $authorized = true;
} elseif (($_SESSION['role'] ?? '') === 'Student' && !empty($_SESSION['student_id'])) {
    if ($resource['audience'] === 'student' && (int) $resource['student_id'] === (int) $_SESSION['student_id']) {
        $sent = getRecord(
            "SELECT message_id FROM student_support_messages WHERE resource_id = ? AND student_id = ? AND status = 'Sent'",
            [$resource_id, $_SESSION['student_id']]
        );
        $authorized = (bool) $sent;
    }
}

if (!$authorized) {
    http_response_code(403);
    exit('You are not authorized to download this resource.');
}

$path = realpath(__DIR__ . '/../../static/uploads/ai_resources/' . $resource['stored_filename']);
$storageRoot = realpath(__DIR__ . '/../../static/uploads/ai_resources/');
if ($path === false || $storageRoot === false || strpos($path, $storageRoot) !== 0 || !is_readable($path)) {
    http_response_code(404);
    exit('Resource file not found.');
}

$mime = $resource['mime_type'] ?: ($resource['output_format'] === 'docx'
    ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    : 'application/pdf');

if (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($resource['original_filename'] ?: $resource['stored_filename']) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
