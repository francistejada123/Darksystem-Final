<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/teacher_ai_documents.php';

if (!isLoggedIn() || !hasRole('Teacher') || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit('Unauthorized');
}
$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrf)) {
    http_response_code(403);
    exit('Expired session token. Refresh the page and try again.');
}
$format = $_POST['format'] ?? '';
$title = trim((string)($_POST['title'] ?? 'E-GRADEPRO Teacher AI Resource'));
$content = trim((string)($_POST['content'] ?? ''));
if (!in_array($format, ['pdf', 'ppt'], true) || $content === '' || mb_strlen($content) > 50000) {
    http_response_code(422);
    exit('Invalid resource request.');
}
if ($format === 'pdf') {
    $bytes = teacherAiPdfBytes($title, $content);
    header('Content-Type: application/pdf');
} else {
    $bytes = teacherAiPptxBytes($title, $content);
    header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
}
header('Content-Disposition: attachment; filename="' . teacherAiDownloadName($title, $format) . '"');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
