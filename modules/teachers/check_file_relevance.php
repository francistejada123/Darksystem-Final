<?php
// modules/teachers/check_file_relevance.php
//
// AJAX (multipart in, JSON out): warns when a file a teacher just chose does not look like it
// belongs to the subject. Nothing is stored and nothing is ever blocked -- see
// includes/file_relevance_check.php. Used by Classroom (announcement, new assignment, edit
// post) and the two Learning Materials forms.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/file_relevance_check.php';

header('Content-Type: application/json');

if (!hasAnyRole(['Administrator', 'Teacher']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['success' => false, 'warnings' => []]);
    exit;
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'warnings' => []]);
    exit;
}
session_write_close();   // do not hold the session while the AI reads the files
@set_time_limit(150);

$subject = trim((string) ($_POST['subject_name'] ?? ''));
$context = trim((string) ($_POST['context'] ?? ''));
$warnings = [];
$results = [];
$files = $_FILES['files'] ?? null;
if ($subject !== '' && is_array($files) && is_array($files['name'] ?? null)) {
    $n = 0;
    foreach ($files['name'] as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        if (++$n > 5) break;   // at most 5 files per check
        $r = frcCheckFile($files['tmp_name'][$i], $name, $subject, $context);
        $results[] = ['name' => (string) $name, 'status' => $r['status'], 'reason' => $r['reason']];
        if ($r['status'] === 'unrelated') {
            $warnings[] = ['name' => (string) $name, 'reason' => $r['reason']];
        }
    }
}
echo json_encode(['success' => true, 'warnings' => $warnings, 'results' => $results]);