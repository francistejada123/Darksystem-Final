<?php
// enrollment_status.php
// Public tracker — applicants check their online enrollment application status.

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/enrollment_helpers_online.php';

oeEnsureCorrectionStatus();
$ref = sanitize($_GET['ref'] ?? $_POST['ref'] ?? '');
// Opened without a reference (refresh / reopened browser): the application submitted here.
if ($ref === '' && ($mine = oeSubmittedRef()) !== null) {
    $ref = $mine;
}
$just_submitted = isset($_GET['submitted']);
$application = null;
$documents = [];
$approved_lrn = null;
$error = '';

if ($ref !== '') {
    $application = getRecord("SELECT * FROM online_applications WHERE reference_no = ?", [$ref]);
    if ($application) {
        $documents = getApplicationDocuments($application['application_id']);
        // Approved: the new student's LRN (first row of Application Details) opens their QR code.
        if ($application['status'] === 'Approved' && !empty($application['student_id'])) {
            $approved_student = getRecord("SELECT lrn FROM students WHERE student_id = ? AND is_active = 1", [(int) $application['student_id']]);
            if ($approved_student && $approved_student['lrn'] !== '') {
                $approved_lrn = $approved_student['lrn'];
            }
        }
    } else {
        $error = 'No application found for reference number "' . htmlspecialchars($ref) . '".';
    }
}

// Incomplete Requirements (New Student / Transferee without Form 137): the applicant
// uploads it here; the application goes back to Pending for the registrar.
$missing = $application ? oeMissingRequirements($application['application_id'], $application['application_type']) : [];
$upload_msg = '';
$upload_err = '';
if ($application && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_form137') {
    $check = preg_replace('/\D/', '', (string) ($_POST['confirm_lrn'] ?? ''));
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $upload_err = 'Your session expired. Please reload the page and try again.';
    } elseif (!in_array($application['status'], ['Pending', 'Under Review', OE_INCOMPLETE], true) || !$missing) {
        $upload_err = 'Form 137 is not needed for this application.';
    } elseif ($check === '' || $check !== preg_replace('/\D/', '', (string) $application['lrn'])) {
        $upload_err = 'Enter the same LRN you used in your application.';
    } else {
        $res = saveApplicationFile($_FILES['form137'] ?? null, $application['reference_no'], 'form137');
        if (!$res['success']) {
            $upload_err = $res['message'];
        } else {
            executeQuery(
                "INSERT INTO application_documents (application_id, doc_key, doc_label, file_name, file_path, file_type, file_size) VALUES (?,?,?,?,?,?,?)",
                [(int) $application['application_id'], 'form137', 'Form 137 (Permanent Record)', $res['name'], $res['path'], $res['type'], $res['size']]
            );
            if ($application['status'] === OE_INCOMPLETE) {
                executeQuery("UPDATE online_applications SET status = 'Pending', remarks = ? WHERE application_id = ?",
                    ['Form 137 uploaded on ' . date('M j, Y g:i A') . '. Waiting for review.', (int) $application['application_id']]);
            }
            foreach (getRecords("SELECT u.user_id FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.role_name IN ('Administrator','Registrar','Enrollment Officer') AND u.is_active = 1") as $s) {
                executeQuery(
                    "INSERT INTO notifications (user_id, title, message, type, link, is_read) VALUES (?, ?, ?, 'info', ?, 0)",
                    [$s['user_id'], 'Form 137 uploaded', $application['first_name'] . ' ' . $application['last_name'] . ' uploaded Form 137. Reference No. ' . $application['reference_no'] . '.',
                     'modules/enrollment/view_application.php?id=' . (int) $application['application_id']]
                );
            }
            logActivity(null, 'online_enrollment', 'Form 137 uploaded', 'Ref: ' . $application['reference_no']);
            header('Location: enrollment_status.php?ref=' . urlencode($application['reference_no']) . '&uploaded=1');
            exit;
        }
    }
}
if (isset($_GET['uploaded'])) $upload_msg = 'Form 137 uploaded. Your application is back with the registrar for review.';
$csrf_token = generateCSRFToken();

// status => [label shown to the applicant, colour, icon, message]
$status_meta = [
    'Pending' => ['Submitted', 'primary', 'fa-paper-plane', 'Application successfully received.'],
    'Under Review' => ['Under Review', 'info', 'fa-magnifying-glass', 'Application is currently being checked.'],
    'Incomplete Requirements' => ['Incomplete Requirements', 'warning', 'fa-file-circle-exclamation', 'A requirement is still missing. See what to submit below.'],
    'Approved' => ['Approved', 'success', 'fa-circle-check', 'Enrollment has been approved.'],
];
$status_meta['For Correction'] = $status_meta['Rejected'] = $status_meta['Incomplete Requirements'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
    (function () {
        try {
            var t = localStorage.getItem('egradepro-theme');
            if (!t) { t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light'; }
            if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        } catch (e) {}
    })();
    </script>
    <title>Track Enrollment Application</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="static/css/style.css" rel="stylesheet">
    <link href="static/css/theme.css" rel="stylesheet">
    <style>
        body { background: #eef2f7; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .wrap { max-width: 760px; margin: 50px auto; padding: 0 15px; }
        .card-x { background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(13, 71, 161, .12); padding: 26px 28px; margin-bottom: 20px; }
        html[data-theme="dark"] body { background: var(--bg); }
        html[data-theme="dark"] .card-x { background: var(--surface); box-shadow: var(--shadow-md); }
        html[data-theme="dark"] h3, html[data-theme="dark"] h4, html[data-theme="dark"] h6 { color: var(--ink); }
        html[data-theme="dark"] .text-muted { color: var(--text-muted) !important; }
    </style>
    <?php include __DIR__ . '/includes/pwa_head.php'; ?>
</head>

<body>
    <button type="button" class="theme-toggle-btn" id="themeToggleBtn"
        style="position:fixed;top:16px;right:16px;z-index:500;"
        aria-label="Switch to dark mode" title="Switch to dark mode" aria-pressed="false">
        <i class="fas fa-sun theme-toggle-icon-sun" aria-hidden="true"></i>
        <i class="fas fa-moon theme-toggle-icon-moon" aria-hidden="true"></i>
    </button>
    <div class="wrap">
        <div class="text-center mb-4">
            <h3 class="fw-bold text-primary"><i class="fas fa-magnifying-glass me-2"></i>Track Your Enrollment</h3>
            <p class="text-muted mb-0">Enter the reference number you received after submitting your application.</p>
        </div>

        <div class="card-x">
            <form method="GET" class="d-flex gap-2">
                <input type="text" name="ref" class="form-control" placeholder="e.g. OE-2026-4F7K2Q" value="<?php echo htmlspecialchars($ref); ?>" required>
                <button class="btn btn-primary px-4">Track</button>
            </form>
            <?php if ($error): ?><div class="alert alert-danger mt-3 mb-0"><?php echo $error; ?></div><?php endif; ?>
        </div>

        <?php if ($application): ?>
            <?php $m = $status_meta[$application['status']] ?? $status_meta['Pending']; ?>
            <?php if ($just_submitted): ?>
                <div class="alert alert-success"><i class="fas fa-circle-check me-1"></i><strong>Application submitted!</strong> Save your tracking number below. You can come back to this page any time to see your status.</div>
            <?php endif; ?>
            <div class="card-x text-center">
                <i class="fas <?php echo $m[2]; ?> text-<?php echo $m[1]; ?>" style="font-size:56px"></i>
                <h4 class="mt-3 fw-bold"><?php echo htmlspecialchars($m[0]); ?></h4>
                <p class="text-muted"><?php echo $m[3]; ?></p>
                <div class="small text-muted mb-1">Application / Tracking Number</div>
                <span class="badge bg-<?php echo $m[1]; ?> px-3 py-2" style="font-size:15px;letter-spacing:.5px"><?php echo htmlspecialchars($application['reference_no']); ?></span>
            </div>

            <?php if ($upload_msg !== ''): ?><div class="alert alert-success"><i class="fas fa-circle-check me-1"></i><?php echo htmlspecialchars($upload_msg); ?></div><?php endif; ?>
            <?php if ($missing && in_array($application['status'], ['Pending', 'Under Review', OE_INCOMPLETE], true)): ?>
                <div class="card-x" style="border:2px solid #f59e0b">
                    <h6 class="fw-bold mb-1"><i class="fas fa-file-circle-exclamation text-warning me-1"></i>Missing requirement: <?php echo htmlspecialchars(implode(', ', $missing)); ?></h6>
                    <p class="text-muted small mb-2">Upload it when you have it (PDF or photo, up to 5 MB). Your application then goes back to the registrar for review.</p>
                    <?php if ($upload_err !== ''): ?><div class="alert alert-danger py-2 small"><?php echo htmlspecialchars($upload_err); ?></div><?php endif; ?>
                    <form method="POST" enctype="multipart/form-data" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="ref" value="<?php echo htmlspecialchars($application['reference_no']); ?>">
                        <input type="hidden" name="action" value="upload_form137">
                        <div class="col-md-5"><input type="text" name="confirm_lrn" class="form-control" inputmode="numeric" maxlength="12" placeholder="Your LRN (to confirm)" required></div>
                        <div class="col-md-7"><input type="file" name="form137" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp" required></div>
                        <div class="col-12"><button class="btn btn-warning fw-bold"><i class="fas fa-upload me-1"></i>Upload Form 137</button></div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($application['status'] === 'Approved'): ?>
                <div class="card-x text-center" style="border:2px solid #198754">
                    <h5 class="fw-bold text-success mb-2"><i class="fas fa-user-graduate me-2"></i>Enrollment Approved</h5>
                    <p class="mb-3">Your enrollment application has been approved. You may now access your EGradePro Student Portal.</p>
                    <a href="student_login.php" class="btn btn-success px-4"><i class="fas fa-right-to-bracket me-1"></i>Go to Student Login</a>
                </div>
            <?php endif; ?>

            <div class="card-x">
                <h6 class="fw-bold mb-3">Application Details</h6>
                <table class="table table-sm mb-0">
                    <?php if ($approved_lrn !== null): ?>
                        <tr><th width="40%">LRN</th><td>
                            <a href="modules/attendance/student_qr.php?ref=<?php echo urlencode($application['reference_no']); ?>" class="fw-bold" style="color:#0d6efd;text-decoration:underline" title="Click to show your QR code"><i class="fas fa-qrcode me-1"></i><?php echo htmlspecialchars($approved_lrn); ?></a>
                            <div class="small text-muted">Click your LRN to show your QR code.</div>
                        </td></tr>
                    <?php endif; ?>
                    <tr><th width="40%">Applicant Name</th><td><?php echo htmlspecialchars($application['last_name'] . ', ' . $application['first_name'] . ' ' . $application['middle_name']); ?></td></tr>
                    <tr><th>Tracking Number</th><td><?php echo htmlspecialchars($application['reference_no']); ?></td></tr>
                    <tr><th>Grade Level</th><td><?php echo htmlspecialchars($application['grade_level']); ?><?php echo $application['strand'] ? ' — ' . htmlspecialchars($application['strand']) : ''; ?></td></tr>
                    <tr><th>Applicant Type</th><td><?php echo htmlspecialchars($application['application_type']); ?></td></tr>
                    <tr><th>Date Submitted</th><td><?php echo formatDate($application['created_at'], 'M d, Y h:i A'); ?></td></tr>
                    <tr><th>Current Status</th><td><span class="badge bg-<?php echo $m[1]; ?>"><?php echo htmlspecialchars($m[0]); ?></span></td></tr>
                    <tr><th>Documents Uploaded</th><td><?php echo count($documents); ?> file(s)</td></tr>
                    <?php if (!empty($application['remarks'])): ?>
                        <tr><th><?php echo $application['status'] === OE_INCOMPLETE ? 'What is missing' : 'Remarks from the Enrollment Officer'; ?></th><td><?php echo nl2br(htmlspecialchars($application['remarks'])); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        <?php endif; ?>

        <div class="text-center">
            <a href="online_enrollment.php?new=1" class="btn btn-outline-primary btn-sm" onclick="return confirm('Start a new application for another learner? Your current application stays saved; keep its tracking number.');"><i class="fas fa-file-pen me-1"></i>New application for another learner</a>
        </div>
    </div>
    <script src="static/js/theme.js"></script>
</body>

</html>