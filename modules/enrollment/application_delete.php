<?php
// modules/enrollment/application_delete.php
// Online application: Soft Delete (to the Recycle Bin), Restore, and
// Permanent Delete -- the same three actions as Students
// (modules/students/delete.php / restore.php / delete_permanent.php).
//
//   ?id=..&action=delete     Administrator / Registrar   -> Recycle Bin (can be restored)
//   ?id=..&action=restore    Administrator / Registrar   -> back to Online Applications
//   ?id=..&action=permanent&confirm=yes   Administrator only -> the application, its
//        documents and their uploaded files are removed for good.
// A student account created when the application was approved is NOT deleted
// (delete it from Students if needed).

require_once __DIR__ . '/../../config/database.php';
if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/enrollment_helpers_online.php';
require_once __DIR__ . '/../../includes/student_delete_helpers.php'; // egp_safeUploadPath()

$action = $_GET['action'] ?? 'delete';
$from = $_GET['from'] ?? '';
$back = ($from === 'bin' || $action === 'restore') ? 'applications_deleted.php' : 'applications.php';

if (!hasAnyRole(['Administrator', 'Registrar']) || ($action === 'permanent' && !hasRole('Administrator'))) {
    $_SESSION['error_message'] = 'You do not have permission to do that.';
    header('Location: ' . $back);
    exit;
}
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0 || !verifyCSRFToken($_GET['csrf_token'] ?? '')) {
    $_SESSION['error_message'] = 'Invalid or expired request. Please try again.';
    header('Location: ' . $back);
    exit;
}
$app = getRecord("SELECT application_id, reference_no, first_name, last_name, lrn, deleted_at FROM online_applications WHERE application_id = ?", [$id]);
if (!$app) {
    $_SESSION['error_message'] = 'Application not found.';
    header('Location: ' . $back);
    exit;
}
$label = $app['last_name'] . ', ' . $app['first_name'] . ' (Ref. ' . $app['reference_no'] . ')';

if ($action === 'delete') {
    if ($app['deleted_at'] === null) {
        executeQuery("UPDATE online_applications SET deleted_at = NOW(), deleted_by = ? WHERE application_id = ?", [(int) $_SESSION['user_id'], $id]);
        logActivity($_SESSION['user_id'], 'enrollment', 'Deleted online application', "Moved to Recycle Bin: $label");
    }
    $_SESSION['success_message'] = 'Application deleted: ' . $label . '. You can restore it from the Recycle Bin.';
    header('Location: ' . $back);
    exit;
}

if ($action === 'restore') {
    if ($app['deleted_at'] !== null) {
        executeQuery("UPDATE online_applications SET deleted_at = NULL, deleted_by = NULL WHERE application_id = ?", [$id]);
        logActivity($_SESSION['user_id'], 'enrollment', 'Restored online application', "Restored: $label");
    }
    $_SESSION['success_message'] = 'Application restored: ' . $label . '.';
    header('Location: applications_deleted.php');
    exit;
}

if ($action === 'permanent') {
    if (($_GET['confirm'] ?? '') !== 'yes') {
        $_SESSION['error_message'] = 'Permanent delete was not confirmed. Nothing was deleted.';
        header('Location: ' . $back);
        exit;
    }
    $files = array_column(getRecords("SELECT file_path FROM application_documents WHERE application_id = ?", [$id]), 'file_path');
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM application_documents WHERE application_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM online_applications WHERE application_id = ?")->execute([$id]);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Permanent application delete failed for ' . $id . ': ' . $e->getMessage());
        $_SESSION['error_message'] = 'The application could not be deleted, so nothing was removed. Please try again.';
        header('Location: ' . $back);
        exit;
    }
    // Uploaded files: after the commit, only inside the project folder.
    $root = realpath(__DIR__ . '/../../');
    $removed = 0;
    foreach ($files as $f) {
        $path = egp_safeUploadPath($root, $f);
        if ($path !== null && @unlink($path)) $removed++;
    }
    logActivity($_SESSION['user_id'], 'enrollment', 'PERMANENT DELETE online application', "Permanently deleted: $label; " . count($files) . " document(s), $removed file(s) removed");
    $_SESSION['success_message'] = 'Application permanently deleted: ' . $label . '.';
    header('Location: ' . $back);
    exit;
}

header('Location: ' . $back);
