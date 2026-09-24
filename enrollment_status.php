<?php
// enrollment_status.php
// Public tracker — applicants check their online enrollment application status.

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/enrollment_helpers_online.php';

$ref = sanitize($_GET['ref'] ?? $_POST['ref'] ?? '');
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

$status_meta = [
    'Pending' => ['warning', 'fa-hourglass-half', 'Your application is queued for review by the registrar.'],
    'Under Review' => ['info', 'fa-magnifying-glass', 'The registrar is currently checking your documents.'],
    'Approved' => ['success', 'fa-circle-check', 'Congratulations! Your enrollment has been approved.'],
    'Rejected' => ['danger', 'fa-circle-xmark', 'Your application was not approved. See the remarks below.'],
];
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
            <div class="card-x text-center">
                <i class="fas <?php echo $m[1]; ?> text-<?php echo $m[0]; ?>" style="font-size:56px"></i>
                <h4 class="mt-3 fw-bold">Application <?php echo htmlspecialchars($application['status']); ?></h4>
                <p class="text-muted"><?php echo $m[2]; ?></p>
                <span class="badge bg-<?php echo $m[0]; ?> px-3 py-2"><?php echo htmlspecialchars($application['reference_no']); ?></span>
            </div>

            <div class="card-x">
                <h6 class="fw-bold mb-3">Application Details</h6>
                <table class="table table-sm mb-0">
                    <?php if ($approved_lrn !== null): ?>
                        <tr><th width="40%">LRN</th><td>
                            <a href="modules/attendance/student_qr.php?ref=<?php echo urlencode($application['reference_no']); ?>" class="fw-bold" style="color:#0d6efd;text-decoration:underline" title="Click to show your QR code"><i class="fas fa-qrcode me-1"></i><?php echo htmlspecialchars($approved_lrn); ?></a>
                            <div class="small text-muted">Click your LRN to show your QR code.</div>
                        </td></tr>
                    <?php endif; ?>
                    <tr><th width="40%">Applicant</th><td><?php echo htmlspecialchars($application['last_name'] . ', ' . $application['first_name'] . ' ' . $application['middle_name']); ?></td></tr>
                    <tr><th>Grade Level</th><td><?php echo htmlspecialchars($application['grade_level']); ?><?php echo $application['strand'] ? ' — ' . htmlspecialchars($application['strand']) : ''; ?></td></tr>
                    <tr><th>Applicant Type</th><td><?php echo htmlspecialchars($application['application_type']); ?></td></tr>
                    <tr><th>Date Submitted</th><td><?php echo formatDate($application['created_at'], 'M d, Y h:i A'); ?></td></tr>
                    <tr><th>Documents Uploaded</th><td><?php echo count($documents); ?> file(s)</td></tr>
                    <?php if (!empty($application['remarks'])): ?>
                        <tr><th>Registrar's Remarks</th><td><?php echo nl2br(htmlspecialchars($application['remarks'])); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        <?php endif; ?>

        <div class="text-center">
            <a href="online_enrollment.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-file-pen me-1"></i>New online application</a>
        </div>
    </div>
    <script src="static/js/theme.js"></script>
</body>

</html>