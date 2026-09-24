<?php
// modules/teachers/teacher_ai_resource_file.php
// Teacher-only viewer/downloader for what Teacher AI generated. Students never
// come through here -- they receive their own per-student copy through the
// existing modules/ai/view_resource.php / download_resource.php, which require
// a Sent message row.
//
//   ?id=N&mode=view      PDF opens inline; Word / PowerPoint open an HTML preview
//   ?id=N&mode=download  the real file as an attachment
//   ?id=N&mode=key       teacher-only PDF with the answer key (only if one exists)
//
// SECURITY: only a numeric id is accepted; the row must belong to the logged-in
// teacher; the stored path is rebuilt server-side and must resolve inside
// static/uploads/ai_resources/ (teacherAiResourceFilePath()).

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/teacher_ai_resources.php';

if (!isLoggedIn() || !hasRole('Teacher')) {
    http_response_code(403);
    exit('Unauthorized');
}

$teacherId = (int) ($_SESSION['user_id'] ?? 0);
$row = teacherAiGetResource($teacherId, (int) ($_GET['id'] ?? 0));
if (!$row || empty($row['output_format'])) {
    http_response_code(404);
    exit('Resource not found.');
}
$mode = (string) ($_GET['mode'] ?? 'view');
$format = (string) $row['output_format'];

if (ob_get_level()) {
    ob_end_clean();
}
header('X-Content-Type-Options: nosniff');

if ($mode === 'key') {
    if (trim((string) $row['teacher_answer_key']) === '') {
        http_response_code(404);
        exit('This resource has no answer key.');
    }
    $bytes = aiRenderResourcePdf(
        ['title' => $row['title'], 'resource_type' => $row['resource_type'], 'student_content' => $row['student_content'], 'teacher_answer_key' => $row['teacher_answer_key']],
        ['student_name' => '', 'grade_level' => '', 'subject_name' => '', 'teacher_name' => teacherAiTeacherName($teacherId), 'date' => date('F j, Y'), 'omit_empty_rows' => true],
        'teacher'
    );
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . teacherAiDownloadName($row['title'] . ' answer key', 'pdf') . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

if ($format === 'gform') {
    // A Google Form lives in Google Forms; the card links straight to it.
    http_response_code(404);
    exit('Open this Google Form from its link.');
}

$path = teacherAiResourceFilePath($row);
if ($path === null) {
    http_response_code(404);
    exit('Resource file not found.');
}
$meta = teacherAiFormatMeta($format);
$fileName = basename((string) ($row['original_filename'] ?: $row['stored_filename']));
$fileName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName);

if ($mode === 'download') {
    header('Content-Type: ' . ($row['mime_type'] ?: $meta['mime']));
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($format === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// Word / PowerPoint cannot be shown inline by a browser: show a faithful HTML preview.
header('Content-Type: text/html; charset=utf-8');
echo teacherAiPreviewHtml($row, 'teacher_ai_resource_file.php?id=' . (int) $row['resource_id'] . '&mode=download');
