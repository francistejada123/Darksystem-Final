<?php
// modules/enrollment/form137_status.php
// ADMIN / REGISTRAR / ENROLLMENT OFFICER — update the Form 137 follow-up status
// (To Follow / Received / Verified) of a New Student or Transferee application.
//
// This ONLY writes online_applications.form137_status. It deliberately does not touch the
// application status (Approved stays Approved), the student record, the enrollment, the
// assigned section or the section capacity, and it never creates anything.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/eo_scope.php';   // Enrollment Officer: own grade level only
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
$new_status = (string) ($_POST['form137_status'] ?? '');
$back = 'view_application.php?id=' . $application_id;

$app = getRecord("SELECT application_id, reference_no, application_type, grade_level, deleted_at FROM online_applications WHERE application_id = ?", [$application_id]);
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

if (!oeForm137Counts($app['application_type'])) {
    $_SESSION['error_message'] = 'Form 137 status only applies to New Student and Transferee applications.';
    header('Location: ' . $back);
    exit;
}

if (!in_array($new_status, OE_FORM137_STATUSES, true)) {
    $_SESSION['error_message'] = 'Invalid Form 137 status.';
    header('Location: ' . $back);
    exit;
}

oeEnsureForm137Schema();
$old_status = oeForm137Status($application_id, $app['application_type']);

// The one and only write: the Form 137 status column.
executeQuery("UPDATE online_applications SET form137_status = ? WHERE application_id = ?", [$new_status, $application_id]);

logActivity(
    $_SESSION['user_id'],
    'online_enrollment',
    'Form 137 status updated',
    "Ref: {$app['reference_no']} — {$old_status} → {$new_status}"
);

// "To Follow" + Incomplete Requirements: if Form 137 was the ONLY reason, the application goes back to
// Under Review. It is never approved here (staff still click Approve Application) and nothing else
// (student, enrollment, section, capacity) is touched. Any other incomplete item keeps it Incomplete.
$moved_note = '';
if ($new_status === 'To Follow') {
    $cur = getRecord("SELECT status, remarks FROM online_applications WHERE application_id = ?", [$application_id]);
    if ($cur && $cur['status'] === OE_INCOMPLETE) {
        $have_docs = array_column(getApplicationDocuments($application_id), 'doc_key');
        $other_missing = false;
        foreach (getRequiredDocuments($app['grade_level'], $app['application_type']) as $rd) {
            if (!empty($rd['required']) && !in_array($rd['key'], $have_docs, true)) $other_missing = true;
        }
        $r = trim((string) $cur['remarks']);
        $only_137_remark = ($r === '' || $r === 'Incomplete requirements. Please contact the registrar.'
            || (preg_match('/\b137\b/', $r) && !preg_match('/138|psa|birth|lrn|report card|good moral|grade level/i', $r)));
        if (!$other_missing && $only_137_remark) {
            executeQuery(
                "UPDATE online_applications SET status = 'Under Review', remarks = ? WHERE application_id = ? AND status = ?",
                ['Form 137 is To Follow. The application is back under review.', $application_id, OE_INCOMPLETE]
            );
            logActivity($_SESSION['user_id'], 'online_enrollment', 'Application back to Under Review (Form 137 To Follow)', "Ref: {$app['reference_no']}");
            $moved_note = ' The application is back to Under Review. Approve it manually after review.';
        }
    }
}

$_SESSION['success_message'] = 'Form 137 status for ' . $app['reference_no'] . ' is now ' . $new_status . '.' . $moved_note;
header('Location: ' . $back);
exit;
