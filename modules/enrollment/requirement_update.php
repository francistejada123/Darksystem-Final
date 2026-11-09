<?php
// modules/enrollment/requirement_update.php
// ADMIN / REGISTRAR / ENROLLMENT OFFICER — move one pending requirement of an application along:
// To Follow -> Received ("Mark as Submitted") -> Verified ("Verify Requirement").
// When nothing is pending any more, the Requirements Status becomes Complete.
//
// Writes ONLY the requirement's own status (Form 137: online_applications.form137_status, any other
// document: online_app_requirements) and then re-computes requirements_status. It never touches the
// application / enrollment status, the student record, the section or the section capacity.
// Advisers cannot use this: view-only for them.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/eo_scope.php';
require_once __DIR__ . '/../../includes/enrollment_helpers_online.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Registrar', 'Enrollment Officer'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['error_message'] = 'Invalid request.';
    header('Location: applications.php');
    exit;
}

$application_id = intval($_POST['application_id'] ?? 0);
$item = (string) ($_POST['item'] ?? '');
$new_status = (string) ($_POST['new_status'] ?? '');

// Back to where the Admin came from: the application review page or the student record (nothing else).
$student_back = intval($_POST['student_id'] ?? 0);
$back = $student_back > 0 ? '../students/view.php?id=' . $student_back : 'view_application.php?id=' . $application_id;

$app = getRecord("SELECT application_id, reference_no, application_type, grade_level, status, deleted_at FROM online_applications WHERE application_id = ?", [$application_id]);
if (!$app) {
    $_SESSION['error_message'] = 'Application not found.';
    header('Location: applications.php');
    exit;
}
eoGuard($app['grade_level'], 'applications.php');

if (!empty($app['deleted_at'])) {
    $_SESSION['error_message'] = 'This application is in the Recycle Bin. Restore it first.';
    header('Location: applications_deleted.php');
    exit;
}
if (!in_array($new_status, OE_FORM137_STATUSES, true)) {
    $_SESSION['error_message'] = 'Invalid requirement status.';
    header('Location: ' . $back);
    exit;
}

oeEnsureRequirementsSchema();
oeEnsureForm137Schema();

if ($item === 'form137') {
    if (!oeForm137Counts($app['application_type'])) {
        $_SESSION['error_message'] = 'Form 137 does not apply to this application.';
        header('Location: ' . $back);
        exit;
    }
    $label = 'Form 137';
    $old = oeForm137Status($application_id, $app['application_type']);
    executeQuery("UPDATE online_applications SET form137_status = ? WHERE application_id = ?", [$new_status, $application_id]);
} else {
    $row = getRecord("SELECT requirement_id, label, status FROM online_app_requirements WHERE application_id = ? AND doc_key = ?", [$application_id, $item]);
    if (!$row) {
        $_SESSION['error_message'] = 'That requirement is not on this application.';
        header('Location: ' . $back);
        exit;
    }
    $label = $row['label'];
    $old = $row['status'];
    executeQuery("UPDATE online_app_requirements SET status = ?, updated_by = ? WHERE requirement_id = ?", [$new_status, $_SESSION['user_id'], $row['requirement_id']]);
}

$req = oeSyncRequirementsStatus($application_id);

logActivity($_SESSION['user_id'], 'online_enrollment', 'Requirement status updated',
    "Ref: {$app['reference_no']} — {$label}: {$old} → {$new_status}; Requirements Status: " . ($req ?? 'unchanged'));

$_SESSION['success_message'] = $label . ' is now ' . $new_status . '.' . ($req === OE_REQ_COMPLETE ? ' All requirements are complete.' : '');
header('Location: ' . $back);
exit;
