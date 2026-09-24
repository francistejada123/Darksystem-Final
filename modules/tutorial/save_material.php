<?php
// modules/tutorial/save_material.php
//
// Handles "Add Material" AND "Replace Material" for a single Tutorial Plan
// item's Learning Material -- either an uploaded file (PDF/Word/PowerPoint)
// or a video link. Reuses the exact same authorization model already used
// by view.php's "update_video" handler (hasRole('Teacher')) so this does
// not change who is allowed to edit a Tutorial Plan -- only what they can
// attach to it.
//
// A "Replace" is just another call to this same endpoint for an item that
// already has a material: the old uploaded file (if any) is deleted from
// disk and the tutorial_plan_items ROW is updated in place -- the item
// itself, its subject/current/target grade, and its plan membership are
// never touched or recreated.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/tutorial_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

function saveMaterialFail($planId, $message)
{
    $_SESSION['error_message'] = $message;
    $dest = $planId > 0 ? ('view.php?id=' . $planId) : 'index.php';
    header('Location: ' . $dest);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!hasRole('Teacher')) {
    // Same rule as every other Tutorial Plan edit action: Admins can view,
    // only Teachers can change it.
    saveMaterialFail(intval($_POST['plan_id'] ?? 0), 'Tutorial plans are view-only for your role.');
}

$plan_id = intval($_POST['plan_id'] ?? 0);
$item_id = intval($_POST['item_id'] ?? 0);
$kind = $_POST['resource_kind'] ?? '';

if ($plan_id <= 0 || $item_id <= 0 || !in_array($kind, ['link', 'upload'], true)) {
    saveMaterialFail($plan_id, 'Invalid request.');
}

// Look the item up scoped to this plan_id -- the same "belongs to this
// plan" check view.php's update_video handler relies on (WHERE item_id = ?
// AND plan_id = ?), so an item_id can't be used to touch a different plan.
$item = getRecord("SELECT * FROM tutorial_plan_items WHERE item_id = ? AND plan_id = ?", [$item_id, $plan_id]);
if (!$item) {
    saveMaterialFail($plan_id, 'That Tutorial Plan item could not be found.');
}

$title = trim($_POST['material_title'] ?? '');
$title = $title !== '' ? mb_substr($title, 0, 255) : null;
$description = trim($_POST['material_description'] ?? '');
$description = $description !== '' ? $description : null;

// If this item currently has an uploaded file and we're about to replace it
// with something else (a new file, or a video link), delete the old
// physical file so replaced materials don't pile up on disk.
$oldStoredFile = null;
if (in_array($item['resource_type'], ['pdf', 'word', 'powerpoint'], true) && !empty($item['resource_file'])) {
    $oldStoredFile = $item['resource_file'];
}

$resetSql = tutorialMaterialProgressResetSql();

if ($kind === 'link') {
    $url = trim($_POST['video_url'] ?? '');
    if ($url === '') {
        saveMaterialFail($plan_id, 'Please enter a video URL.');
    }
    if (!preg_match('#^https?://#i', $url) || mb_strlen($url) > 500) {
        saveMaterialFail($plan_id, 'Please enter a valid video URL (starting with http:// or https://).');
    }

    $updateResult = executeQuery("
        UPDATE tutorial_plan_items
        SET resource_type = 'link',
            resource_url = ?,
            resource_file = NULL,
            resource_file_original_name = NULL,
            resource_file_size = NULL,
            resource_mime_type = NULL,
            resource_title = ?,
            resource_description = ?,
            $resetSql
        WHERE item_id = ? AND plan_id = ?
    ", [$url, $title, $description, $item_id, $plan_id]);

    // executeQuery() returns false (and only logs to error_log) on a DB
    // error instead of throwing -- without this check a failed UPDATE
    // (for example because a column this query needs is missing from the
    // live database) used to fall straight through to the "success"
    // redirect below, so the teacher saw "Video link saved" and the item
    // just silently stayed as "No material assigned".
    if ($updateResult === false) {
        saveMaterialFail($plan_id, 'The video link could not be saved (a database error occurred). Please try again or contact support.');
    }

    if ($oldStoredFile) {
        $oldPath = tutorialMaterialStorageDir() . '/' . basename($oldStoredFile);
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $_SESSION['success_message'] = 'Video link saved.';
    header('Location: view.php?id=' . $plan_id);
    exit;
}

// $kind === 'upload'
if (!isset($_FILES['material_file']) || !is_array($_FILES['material_file'])) {
    saveMaterialFail($plan_id, 'Please choose a file to upload.');
}

$file = $_FILES['material_file'];
$ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
$targetType = tutorialMaterialTypeForExtension($ext);

if ($targetType === null) {
    saveMaterialFail($plan_id, 'This file type is not supported. Please upload a PDF, Word, or PowerPoint file.');
}

$validation = tutorialValidateUploadedMaterial($file, $targetType);
if (!$validation['ok']) {
    saveMaterialFail($plan_id, $validation['error']);
}

$storedName = tutorialGenerateStoredFilename($file['name']);
$storageDir = tutorialMaterialStorageDir();
$destPath = $storageDir . '/' . $storedName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    saveMaterialFail($plan_id, 'The file could not be saved on the server. Please try again.');
}

$originalName = tutorialSafeDisplayFilename($file['name']);

$updateResult = executeQuery("
    UPDATE tutorial_plan_items
    SET resource_type = ?,
        resource_url = NULL,
        resource_file = ?,
        resource_file_original_name = ?,
        resource_file_size = ?,
        resource_mime_type = ?,
        resource_title = ?,
        resource_description = ?,
        $resetSql
    WHERE item_id = ? AND plan_id = ?
", [
    $targetType,
    $storedName,
    $originalName,
    (int) $file['size'],
    $validation['mime'],
    $title,
    $description,
    $item_id,
    $plan_id,
]);

// Same "don't silently claim success" check as the video-link branch above.
if ($updateResult === false) {
    @unlink($destPath); // already moved onto disk -- don't leave an orphaned file behind
    saveMaterialFail($plan_id, 'The file could not be saved (a database error occurred). Please try again or contact support.');
}

if ($oldStoredFile) {
    $oldPath = $storageDir . '/' . basename($oldStoredFile);
    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
}

$_SESSION['success_message'] = 'Learning material uploaded.';
header('Location: view.php?id=' . $plan_id);
exit;