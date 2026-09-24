<?php
// modules/students/portal/view_learning_material.php
//
// Student-side, ID-based endpoint for a Quiz / Examination learning material
// the teacher sent with "Add to All Students" (Manage Assessment Items).
// Access is re-checked on every request: the material's item must have been
// sent to THIS student, and its Start Date & Time must have passed. A file
// is streamed; a link redirects to its URL. Mirrors
// modules/grading/view_item_material.php (the teacher-side viewer).

session_start();
require_once '../../../config/database.php';
require_once '../../../includes/assessment_material_helpers.php';

function viewLearningMaterialDeny($code, $message)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

if (!isset($_SESSION['student_id']) || ($_SESSION['role'] ?? '') !== 'Student') {
    viewLearningMaterialDeny(403, 'Please log in to view this material.');
}

$material_id = intval($_GET['id'] ?? 0);
$material = $material_id > 0 ? aim_getMaterial($material_id) : null;
if (!$material || !aim_studentCanViewMaterial((int) $_SESSION['student_id'], $material)) {
    viewLearningMaterialDeny(404, 'This material is not available to you yet.');
}

if ($material['resource_type'] === 'link') {
    $url = (string) $material['resource_url'];
    if (!preg_match('#^https?://#i', $url)) {
        viewLearningMaterialDeny(404, 'This link is not available.');
    }
    header('Location: ' . $url);
    exit;
}

if (empty($material['resource_file'])) {
    viewLearningMaterialDeny(404, 'No file is attached to this material.');
}

$path = aim_materialStorageDir() . '/' . basename($material['resource_file']);
if (!is_file($path)) {
    viewLearningMaterialDeny(404, 'This file is no longer available.');
}

$displayName = tutorialSafeDisplayFilename($material['resource_file_original_name'] ?? 'material');
$mime = tutorialMaterialCanonicalMime($material['resource_type'], $material['resource_file']);

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . str_replace('"', '', $displayName) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($path);
exit;
