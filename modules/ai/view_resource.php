<?php
// modules/ai/view_resource.php
// Streams a generated AI resource (PDF/DOCX) INLINE (Content-Disposition:
// inline — opens in the browser instead of forcing a download) for
// whoever is allowed to see it. See download_resource.php for the
// attachment version — both share the exact same ownership rule below,
// kept deliberately duplicated (not factored into a shared include) so
// each endpoint is fully self-contained and easy to audit on its own.
//
// SECURITY: never trusts a filename from the browser — only a numeric
// resource_id, looked up server-side, with its stored path never exposed
// to the client. Two, and only two, callers are ever allowed to fetch a
// resource:
//   - the TEACHER who owns it (ai_generated_resources.teacher_id matches
//     the logged-in staff session), or
//   - the STUDENT it was actually SENT to (there must be a real
//     student_support_messages row linking this resource_id to a message
//     with status='Sent' and that student's own student_id — being the
//     resource's student_id alone is not enough, since a resource can
//     exist as a Draft/Generated the teacher never sent), AND ONLY when
//     the resource's audience is 'student' — a teacher's own answer-key
//     copy (audience='teacher') is never streamed to a student session
//     under any circumstance, even if somehow linked to a message.
//
// This file supports BOTH session shapes in this codebase: staff
// (Teacher, via includes/auth.php's hasRole()/$_SESSION['user_id']) and
// Student (the student portal's own session shape — $_SESSION['role'] ===
// 'Student' + $_SESSION['student_id'], the same check
// modules/students/portal/messages.php uses; the student portal does not
// go through auth.php's isLoggedIn()/hasRole() at all).

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
    exit('You are not authorized to view this resource.');
}

$path = realpath(__DIR__ . '/../../static/uploads/ai_resources/' . $resource['stored_filename']);
$storageRoot = realpath(__DIR__ . '/../../static/uploads/ai_resources/');
// Belt-and-suspenders: stored_filename is always our own random
// bin2hex(16)+extension, but never trust a filesystem path built from a
// database value without also confirming it still resolves inside the
// expected directory (blocks any theoretical path-traversal value).
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
header('Content-Disposition: inline; filename="' . basename($resource['original_filename'] ?: $resource['stored_filename']) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
