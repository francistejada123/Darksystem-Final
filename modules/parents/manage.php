<?php
// modules/parents/manage.php -- Deactivate or permanently delete a parent account.

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/common.php';

if (!hasAnyRole(['Administrator', 'Registrar'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$q = trim((string) ($_POST['q'] ?? ''));
$back = 'index.php' . ($q !== '' ? '?q=' . rawurlencode($q) : '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['pp_flash'] = ['danger', 'The request expired or was not allowed. Please try again.'];
    header('Location: ' . $back);
    exit;
}

$parentId = filter_var($_POST['parent_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = (string) ($_POST['action'] ?? '');
if (!$parentId || !in_array($action, ['soft_delete', 'permanent_delete'], true)) {
    $_SESSION['pp_flash'] = ['danger', 'The selected parent account or action is invalid.'];
    header('Location: ' . $back);
    exit;
}
$confirmation = trim((string) ($_POST['confirmation'] ?? ''));
$requiredConfirmation = $action === 'soft_delete' ? 'Confirm Soft Delete' : 'Confirm Permanent Delete';
if (!hash_equals($requiredConfirmation, $confirmation)) {
    $_SESSION['pp_flash'] = ['danger', 'The confirmation was not completed. No changes were saved.'];
    header('Location: ' . $back);
    exit;
}

require_once __DIR__ . '/../../includes/parent_portal.php';
ppEnsureSchema();
global $db;

try {
    $db->beginTransaction();
    if ($action === 'soft_delete') {
        $stmt = $db->prepare('UPDATE parents SET is_active = 0 WHERE parent_id = ? AND is_active = 1');
        $stmt->execute([(int) $parentId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The parent account was not found or is no longer active.');
        }
        $successMessage = 'Parent account deactivated successfully.';
        $activityLabel = 'Deactivated parent account';
    } else {
        $stmt = $db->prepare('DELETE FROM parents WHERE parent_id = ? AND is_active = 1');
        $stmt->execute([(int) $parentId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The parent account was not found or is no longer active.');
        }
        $successMessage = 'Parent account permanently deleted successfully.';
        $activityLabel = 'Permanently deleted parent account';
    }
    $db->commit();
    logActivity((int) ($_SESSION['user_id'] ?? 0), 'parents', $activityLabel, 'Parent #' . (int) $parentId);
    $_SESSION['pp_flash'] = ['success', $successMessage];
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Parent account action failed for parent #' . (int) $parentId . ': ' . $e->getMessage());
    $_SESSION['pp_flash'] = ['danger', 'The parent account could not be updated. No changes were saved.'];
}

header('Location: ' . $back);
exit;
