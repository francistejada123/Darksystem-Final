<?php
// modules/grading/view_item_material.php
//
// ID-based, server-authorized endpoint that streams an uploaded Learning
// Material file (PDF/Word/PowerPoint) attached to an assessment item. A
// link-type material is opened directly via its resource_url and never
// passes through here. Same "teacher/admin only, ownership re-checked on
// every request" model as modules/tutorial/view_material.php.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/assessment_material_helpers.php';

function viewItemMaterialDeny($code, $message)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

if (!hasAnyRole(['Administrator', 'Teacher'])) {
    viewItemMaterialDeny(403, 'Please log in to view this material.');
}

$material_id = intval($_GET['id'] ?? 0);
$material = $material_id > 0 ? aim_getMaterial($material_id) : null;
if (!$material) {
    viewItemMaterialDeny(404, 'Material not found.');
}

$teacher_id = $_SESSION['user_id'];
$item = getRecord("SELECT item_id FROM assessment_items WHERE item_id = ? AND teacher_id = ?", [$material['item_id'], $teacher_id]);
if (!$item) {
    // Administrators may view any teacher's item; a Teacher may only view their own.
    if (!hasRole('Administrator')) {
        viewItemMaterialDeny(403, 'You do not have access to this material.');
    }
}

if (!in_array($material['resource_type'], ['pdf', 'word', 'powerpoint'], true) || empty($material['resource_file'])) {
    viewItemMaterialDeny(404, 'No file is attached to this material.');
}

$path = aim_materialStorageDir() . '/' . basename($material['resource_file']);
if (!is_file($path)) {
    viewItemMaterialDeny(404, 'This file is no longer available.');
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
