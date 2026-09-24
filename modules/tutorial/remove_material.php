<?php
// modules/tutorial/remove_material.php
//
// Removes the Learning Material currently assigned to a Tutorial Plan item
// -- the item ROW itself is never deleted, only its resource_* fields are
// cleared back to "no material assigned" and its progress fields reset.
// The confirmation ("Remove Learning Material? Are you sure...") happens
// client-side before this endpoint is ever called, same pattern as every
// other destructive action already used elsewhere in this app.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/tutorial_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!hasRole('Teacher')) {
    $_SESSION['error_message'] = 'Tutorial plans are view-only for your role.';
    header('Location: index.php');
    exit;
}

$plan_id = intval($_POST['plan_id'] ?? 0);
$item_id = intval($_POST['item_id'] ?? 0);

if ($plan_id <= 0 || $item_id <= 0) {
    header('Location: index.php');
    exit;
}

$item = getRecord("SELECT * FROM tutorial_plan_items WHERE item_id = ? AND plan_id = ?", [$item_id, $plan_id]);
if (!$item) {
    $_SESSION['error_message'] = 'That Tutorial Plan item could not be found.';
    header('Location: view.php?id=' . $plan_id);
    exit;
}

$oldStoredFile = null;
if (in_array($item['resource_type'], ['pdf', 'word', 'powerpoint'], true) && !empty($item['resource_file'])) {
    $oldStoredFile = $item['resource_file'];
}

$resetSql = tutorialMaterialProgressResetSql();

executeQuery("
    UPDATE tutorial_plan_items
    SET resource_type = 'link',
        resource_url = NULL,
        resource_file = NULL,
        resource_file_original_name = NULL,
        resource_file_size = NULL,
        resource_mime_type = NULL,
        resource_title = NULL,
        resource_description = NULL,
        $resetSql
    WHERE item_id = ? AND plan_id = ?
", [$item_id, $plan_id]);

if ($oldStoredFile) {
    $oldPath = tutorialMaterialStorageDir() . '/' . basename($oldStoredFile);
    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
}

$_SESSION['success_message'] = 'Learning material removed.';
header('Location: view.php?id=' . $plan_id);
exit;
