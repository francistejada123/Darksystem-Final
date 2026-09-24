<?php
// modules/grading/detect_material_topic.php
//
// AJAX (multipart in, JSON out): reads a lesson file the teacher just chose
// in the Learning Materials modal -- or one already attached (material_id) --
// and returns its main topic, so the Topic field fills itself. Nothing is
// stored here; the file is only saved when the teacher submits the modal
// (save_item_material.php). Same upload validation as that endpoint.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/assessment_material_helpers.php';

header('Content-Type: application/json');

function detectTopicFail($message, $code = 400)
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if (!hasAnyRole(['Administrator', 'Teacher']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    detectTopicFail('Unauthorized.', 403);
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    detectTopicFail('Your session token expired. Refresh the page and try again.', 403);
}

$teacher_id = (int) $_SESSION['user_id'];
$ctx = aim_itemContext((int) ($_POST['item_id'] ?? 0), $teacher_id);
if (!$ctx) {
    detectTopicFail('That assessment item could not be found.', 404);
}
$context = 'Assessment: ' . $ctx['item']['item_name'] . ' | Subject: ' . $ctx['item']['subject_name'] . ' | ' . $ctx['item']['grade_level'];

$material_id = (int) ($_POST['material_id'] ?? 0);
if ($material_id > 0) {
    $material = aim_getMaterial($material_id);
    if (!$material || (int) $material['item_id'] !== (int) $ctx['item']['item_id']) {
        detectTopicFail('That material could not be found.', 404);
    }
    $input = aim_materialAiInput($material);
} else {
    $file = $_FILES['material_file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        detectTopicFail('Choose a file first.');
    }
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $type = tutorialMaterialTypeForExtension($ext);
    if ($type === null) {
        detectTopicFail('Please choose a PDF, Word, or PowerPoint file.');
    }
    $validation = tutorialValidateUploadedMaterial($file, $type);
    if (!$validation['ok']) {
        detectTopicFail($validation['error']);
    }
    $input = aim_readFileForAi($file['tmp_name'], $type, $ext);
}

@set_time_limit(90);
$result = aim_detectTopic($input, $context);
if (!$result['ok']) {
    detectTopicFail($result['error'], 200);
}
echo json_encode(['success' => true, 'topic' => $result['topic']]);
