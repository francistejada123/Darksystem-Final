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
$pp_state = null;

if ($ref !== '') {
    $application = getRecord("SELECT * FROM online_applications WHERE reference_no = ? AND deleted_at IS NULL", [$ref]);
    if ($application) {
        $documents = getApplicationDocuments($application['application_id']);
        // Approved: the new student's LRN (first row of Application Details) opens their QR code.
        if ($application['status'] === 'Approved' && !empty($application['student_id'])) {
            $approved_student = getRecord("SELECT lrn FROM students WHERE student_id = ? AND is_active = 1", [(int) $application['student_id']]);
            if ($approved_student && $approved_student['lrn'] !== '') {
                $approved_lrn = $approved_student['lrn'];
            }
        }
        // Parent Portal activation stage. Email verification is SIMULATED: once
        // the enrollment is approved the address counts as verified, so the
        // guardian registration -> create password -> activated sequence can
        // run straight from this page. Nothing is sent and no code is needed.
        require_once __DIR__ . '/includes/parent_portal.php';
        ppEnsureSchema();
        $pp_state = ppActivationState($application);

        // Student Portal credentials for an APPROVED application.
        // spwEnsurePortalCredentials() creates them once if they are missing --
        // that is what rescues students approved before this feature existed --
        // and otherwise only READS the existing temporary password back, so
        // refreshing this page never rotates it.
        $sp = ['state' => 'none', 'password' => '', 'lrn' => (string) $application['lrn']];
        if ($application['status'] === 'Approved' && !empty($application['student_id'])) {
            require_once __DIR__ . '/includes/student_password.php';
            spwEnsureSchema();
            $sp = spwEnsurePortalCredentials((int) $application['student_id']);
            $sp['lrn'] = $sp['lrn'] ?: (string) $application['lrn'];
        }
    } else {
        $error = 'No application found for reference number "' . htmlspecialchars($ref) . '".';
    }
}

// Form 137 (New Student / Transferee) is NOT uploaded online after applying. The learner brings the
// physical copy to the school; staff update its status. It never affects the application status.
$form137_state = $application ? oeForm137Status($application['application_id'], $application['application_type']) : 'Not Applicable';

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
        .wrap { max-width: 860px; margin: 40px auto; padding: 0 16px 40px; }

        /* ---- Base card ------------------------------------------------ */
        .card-x {
            background: #fff; border: 1px solid #E3E9F2; border-radius: 18px;
            box-shadow: 0 4px 18px rgba(15, 35, 80, .07);
            padding: 26px 28px; margin-bottom: 18px;
        }
        .card-x:last-child { margin-bottom: 0; }
        .card-head { display: flex; align-items: center; gap: 13px; margin-bottom: 18px; }
        .card-head .ic {
            width: 44px; height: 44px; border-radius: 13px; flex: 0 0 auto;
            display: flex; align-items: center; justify-content: center; font-size: 19px;
        }
        .card-head h5 { margin: 0; font-size: 17px; font-weight: 800; letter-spacing: -.01em; }
        .card-head .sub { font-size: 13.5px; color: #667085; margin: 2px 0 0; }

        /* Accent variants: green / orange / blue / purple / neutral */
        .a-green  { border-color: #BBE5CE; }  .a-green  .ic { background: #E8F7EF; color: #1F9D6B; }
        .a-orange { border-color: #F8DCA8; }  .a-orange .ic { background: #FEF3E2; color: #B45309; }
        .a-blue   { border-color: #BFDBFE; }  .a-blue   .ic { background: #E7F0FE; color: #1D4ED8; }
        .a-purple { border-color: #DDD0F7; }  .a-purple .ic { background: #F1EBFD; color: #6D28D9; }
        .a-grey   { border-color: #E3E9F2; }  .a-grey   .ic { background: #F1F5F9; color: #475467; }

        /* ---- Track form ---------------------------------------------- */
        .track-form { display: flex; gap: 10px; }
        .track-form .form-control {
            border-radius: 12px; border: 1px solid #D6DEEA; padding: 13px 16px;
            font-size: 15px; letter-spacing: .4px; height: 50px;
        }
        .track-form .form-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
        .track-form .btn { border-radius: 12px; padding: 0 30px; font-weight: 700; height: 50px; }

        /* ---- Status hero ---------------------------------------------- */
        .hero { text-align: center; }
        .hero .big { font-size: 54px; line-height: 1; }
        .hero h4 { font-size: 22px; font-weight: 800; margin: 12px 0 4px; }
        .hero .lbl { font-size: 12px; letter-spacing: 1.2px; text-transform: uppercase; color: #667085; font-weight: 700; }
        .hero .ref {
            display: inline-block; margin-top: 6px; font-size: 17px; font-weight: 800;
            letter-spacing: 1.4px; padding: 9px 20px; border-radius: 12px;
        }
        .next-step {
            margin-top: 20px; text-align: left; background: #F0F7FF; border: 1px solid #BFDBFE;
            border-radius: 13px; padding: 14px 16px; font-size: 14.5px; color: #1E3A5F;
        }
        .next-step b { display: block; margin-bottom: 3px; color: #1565C0; }

        /* ---- Details grid --------------------------------------------- */
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 22px; }
        .field .k { font-size: 12px; text-transform: uppercase; letter-spacing: .8px; color: #667085; font-weight: 700; margin-bottom: 3px; }
        .field .v { font-size: 15.5px; color: #101828; font-weight: 600; word-break: break-word; }
        .field.span2 { grid-column: 1 / -1; }
        .lrn-link { color: #1D4ED8; font-weight: 800; text-decoration: none; letter-spacing: .6px; }
        .lrn-link:hover { text-decoration: underline; }

        /* ---- Credentials ---------------------------------------------- */
        .cred-row { margin-bottom: 16px; }
        .cred-row .k { font-size: 12px; text-transform: uppercase; letter-spacing: .8px; color: #475569; font-weight: 700; margin-bottom: 6px; }
        .cred-box {
            display: flex; align-items: center; gap: 8px;
            background: #F8FAFF; border: 1px solid #BFDBFE; border-radius: 12px; padding: 10px 12px;
        }
        .cred-box .val {
            flex: 1; min-width: 0; font-family: Consolas, 'SF Mono', monospace;
            font-size: 20px; font-weight: 800; letter-spacing: 1.5px; color: #1D4ED8;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .cred-box .val.pw { color: #B91C1C; }
        .cred-btn {
            border: 1px solid #BFDBFE; background: #fff; color: #1D4ED8; border-radius: 9px;
            font-size: 13px; font-weight: 700; padding: 8px 13px; white-space: nowrap; transition: all .15s;
        }
        .cred-btn:hover { background: #1D4ED8; color: #fff; border-color: #1D4ED8; }
        .cred-note {
            background: #FEF3E2; border: 1px solid #F8DCA8; border-radius: 12px;
            padding: 12px 14px; font-size: 14px; color: #7A4A08;
        }
        .btn-wide { display: block; width: 100%; border-radius: 12px; padding: 13px; font-weight: 700; font-size: 15.5px; }
        .btn-purple { background: #6D28D9; border-color: #6D28D9; color: #fff; }
        .btn-purple:hover { background: #5B21B6; border-color: #5B21B6; color: #fff; }

        /* ---- Copy toast ----------------------------------------------- */
        #copyToast {
            position: fixed; left: 50%; bottom: 26px; transform: translateX(-50%) translateY(20px);
            background: #101828; color: #fff; font-size: 14px; font-weight: 600;
            padding: 11px 20px; border-radius: 12px; z-index: 3000; opacity: 0;
            pointer-events: none; transition: opacity .2s, transform .2s;
        }
        #copyToast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

        @media (max-width: 640px) {
            .wrap { margin: 22px auto; padding: 0 12px 30px; }
            .card-x { padding: 20px 18px; border-radius: 15px; }
            .grid-2 { grid-template-columns: 1fr; gap: 12px; }
            .track-form { flex-direction: column; }
            .track-form .btn { width: 100%; }
            .cred-box { flex-wrap: wrap; }
            .cred-box .val { flex-basis: 100%; font-size: 18px; }
            .hero .big { font-size: 44px; }
        }

        html[data-theme="dark"] body { background: var(--bg); }
        html[data-theme="dark"] .card-x { background: var(--surface); border-color: var(--border); box-shadow: var(--shadow-md); }
        html[data-theme="dark"] h3, html[data-theme="dark"] h4, html[data-theme="dark"] h5, html[data-theme="dark"] h6 { color: var(--ink); }
        html[data-theme="dark"] .text-muted, html[data-theme="dark"] .field .k, html[data-theme="dark"] .hero .lbl { color: var(--text-muted) !important; }
        html[data-theme="dark"] .field .v { color: var(--ink); }
        html[data-theme="dark"] .a-green .ic  { background: rgba(31,157,107,.15); }
        html[data-theme="dark"] .a-orange .ic { background: rgba(180,83,9,.18); }
        html[data-theme="dark"] .a-blue .ic   { background: rgba(29,78,216,.18); }
        html[data-theme="dark"] .a-purple .ic { background: rgba(109,40,217,.18); }
        html[data-theme="dark"] .a-grey .ic   { background: var(--surface-hover); }
        html[data-theme="dark"] .card-x.a-green  { border-color: rgba(31,157,107,.35); }
        html[data-theme="dark"] .card-x.a-orange { border-color: rgba(180,83,9,.4); }
        html[data-theme="dark"] .card-x.a-blue   { border-color: rgba(29,78,216,.4); }
        html[data-theme="dark"] .card-x.a-purple { border-color: rgba(109,40,217,.4); }
        html[data-theme="dark"] .hero .ref, html[data-theme="dark"] .cred-box { background: var(--surface-hover); border-color: var(--border); }
        html[data-theme="dark"] .next-step { background: rgba(29,78,216,.10); border-color: rgba(29,78,216,.35); color: #c7d7f2; }
        html[data-theme="dark"] .next-step b { color: #aecbfa; }
        html[data-theme="dark"] .cred-note { background: rgba(180,83,9,.12); border-color: rgba(180,83,9,.35); color: #fdd663; }
        html[data-theme="dark"] .cred-btn { background: var(--surface-hover); border-color: var(--border); color: #aecbfa; }
        html[data-theme="dark"] .lrn-link { color: #aecbfa; }
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
            <form method="GET" class="track-form">
                <input type="text" name="ref" class="form-control" placeholder="e.g. OE-2026-4F7K2Q" value="<?php echo htmlspecialchars($ref); ?>" required>
                <button class="btn btn-primary">Track</button>
            </form>
            <?php if ($error): ?><div class="alert alert-danger mt-3 mb-0"><?php echo $error; ?></div><?php endif; ?>
        </div>

        <?php if ($application): ?>
            <?php $m = $status_meta[$application['status']] ?? $status_meta['Pending']; ?>
            <?php if ($just_submitted): ?>
                <div class="alert alert-success"><i class="fas fa-circle-check me-1"></i><strong>Application submitted!</strong> Save your tracking number below. You can come back to this page any time to see your status.</div>
            <?php endif; ?>
            <div class="card-x hero <?php echo $application['status'] === 'Approved' ? 'a-green' : ''; ?>">
                <i class="fas <?php echo $m[2]; ?> text-<?php echo $m[1]; ?> big"></i>
                <h4><?php echo htmlspecialchars($m[0]); ?></h4>
                <p class="text-muted mb-0"><?php echo $m[3]; ?></p>
                <div class="lbl" style="margin-top:18px;">Tracking Number</div>
                <div class="ref" style="background:<?php echo $m[1] === 'success' ? '#E8F7EF;color:#1F9D6B' : ($m[1] === 'warning' ? '#FEF3E2;color:#B45309' : ($m[1] === 'info' ? '#E7F0FE;color:#1D4ED8' : '#E7F0FE;color:#1D4ED8')); ?>;"><?php echo htmlspecialchars($application['reference_no']); ?></div>
                <?php if ($application['status'] === 'Approved'): ?>
                    <div class="next-step">
                        <b><i class="fas fa-arrow-down me-1"></i>Next Step</b>
                        Your Student Portal credentials are ready &mdash; you will find your LRN and a temporary password in the blue card below. Sign in once with those, and you will be asked to create your own password straight away.
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($form137_state !== 'Not Applicable' && in_array($application['status'], ['Pending', 'Under Review', OE_INCOMPLETE, 'Approved'], true)): ?>
                <div class="card-x <?php echo $form137_state === 'To Follow' ? 'a-orange' : 'a-green'; ?>">
                    <div class="card-head" style="margin-bottom:12px;">
                        <div class="ic"><i class="fas <?php echo $form137_state === 'To Follow' ? 'fa-file-circle-exclamation' : 'fa-file-circle-check'; ?>"></i></div>
                        <div>
                            <h5>Form 137: <?php echo htmlspecialchars($form137_state); ?></h5>
                            <p class="sub" style="color:<?php echo $form137_state === 'To Follow' ? '#B45309' : '#1F9D6B'; ?>;">
                                <?php if ($form137_state === 'To Follow'): ?>
                                    You can submit this later &mdash; it is not needed today.
                                <?php else: ?>
                                    Received by the school.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <p class="text-muted small mb-0" style="font-size:14.5px;line-height:1.6;">
                        <?php if ($form137_state === 'To Follow'): ?>
                            <strong>Your Form 137 can be submitted later.</strong> Please bring the physical copy to the school when classes start or when your previous school releases it. You do not need to upload it online, and it does not affect your application status<?php echo $application['status'] === 'Approved' ? ' or your section assignment' : ''; ?>.
                        <?php elseif ($form137_state === 'Received'): ?>
                            The school has received your Form 137.
                        <?php else: ?>
                            Your Form 137 has been received and verified by the school.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($application['status'] === 'Approved'): ?>
                <div class="card-x text-center" style="border:2px solid #198754">
                    <h5 class="fw-bold text-success mb-2"><i class="fas fa-user-graduate me-2"></i>Enrollment Approved</h5>
                    <p class="mb-3">Your enrollment application has been approved. Your Student Portal credentials are shown below.</p>
                </div>
            <?php endif; ?>

            <?php if ($application['status'] === 'Incomplete Requirements'): ?>
                <?php
                // Incomplete Requirements is NOT approval: neither the Student Portal
                // password nor the Parent Portal may be created yet. The missing
                // Form 138 / Birth Certificate has to be settled by the Admin first.
                // (A genuinely Incomplete application reaches the portal flow only once
                // its ENROLLMENT is approved -- e.g. To-Follow Form 137 -- which
                // ppEnrollmentApproved() detects below and $pp_state reflects.)
                ?>
                <div class="card-x text-center" style="border:2px solid #fd7e14">
                    <h5 class="fw-bold mb-2"><i class="fas fa-hourglass-half me-2"></i>Waiting for Approval</h5>
                    <p class="mb-0">Your Parent Portal account cannot be set up yet. A required document is still missing, so the school has not approved the enrollment. Once the Enrollment Officer approves it, this page will show the Parent Portal registration step.</p>
                </div>
            <?php endif; ?>

            <div class="card-x a-grey">
                <div class="card-head">
                    <div class="ic"><i class="fas fa-file-lines"></i></div>
                    <div><h5>Application Details</h5></div>
                </div>
                <div class="grid-2">
                    <?php if ($approved_lrn !== null): ?>
                        <div class="field">
                            <div class="k">LRN</div>
                            <div class="v"><a href="modules/attendance/student_qr.php?ref=<?php echo urlencode($application['reference_no']); ?>" class="lrn-link" title="Click to show your QR code"><i class="fas fa-qrcode me-1"></i><?php echo htmlspecialchars($approved_lrn); ?></a></div>
                        </div>
                    <?php endif; ?>
                    <div class="field">
                        <div class="k">Applicant Name</div>
                        <div class="v"><?php echo htmlspecialchars($application['last_name'] . ', ' . $application['first_name'] . ' ' . $application['middle_name']); ?></div>
                    </div>
                    <div class="field">
                        <div class="k">Tracking Number</div>
                        <div class="v"><?php echo htmlspecialchars($application['reference_no']); ?></div>
                    </div>
                    <div class="field">
                        <div class="k">Grade Level</div>
                        <div class="v"><?php echo htmlspecialchars($application['grade_level']); ?><?php echo $application['strand'] ? ' &middot; ' . htmlspecialchars($application['strand']) : ''; ?></div>
                    </div>
                    <div class="field">
                        <div class="k">Applicant Type</div>
                        <div class="v"><?php echo htmlspecialchars($application['application_type']); ?></div>
                    </div>
                    <div class="field">
                        <div class="k">Date Submitted</div>
                        <div class="v"><?php echo formatDate($application['created_at'], 'M d, Y h:i A'); ?></div>
                    </div>
                    <div class="field">
                        <div class="k">Current Status</div>
                        <div class="v"><span class="badge bg-<?php echo $m[1]; ?>"><?php echo htmlspecialchars($m[0]); ?></span></div>
                    </div>
                    <div class="field">
                        <div class="k">Documents Uploaded</div>
                        <div class="v"><?php echo count($documents); ?> file(s)</div>
                    </div>
                    <?php if (!empty($application['remarks'])): ?>
                        <div class="field span2">
                            <div class="k"><?php echo $application['status'] === OE_INCOMPLETE ? 'What is missing' : 'Remarks from the Enrollment Officer'; ?></div>
                            <div class="v" style="font-weight:500;line-height:1.6;"><?php echo nl2br(htmlspecialchars($application['remarks'])); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($sp['state'] === 'credentials' && $sp['password'] !== ''): ?>
                <?php /* STUDENT PORTAL CREDENTIALS -- below Application Details.
                         Shows the ONE temporary password generated at approval;
                         refreshing the page reads it back rather than making a new one. */ ?>
                <div class="card-x a-blue">
                    <div class="card-head">
                        <div class="ic"><i class="fas fa-key"></i></div>
                        <div><h5 style="color:#1D4ED8;">Student Portal Credentials</h5>
                        <p class="sub">Sign in with these, then create your own password.</p></div>
                    </div>
                    <div class="cred-row">
                        <div class="k">LRN / Username</div>
                        <div class="cred-box">
                            <span class="val" id="tpLrn"><?php echo htmlspecialchars($sp['lrn']); ?></span>
                            <button type="button" class="cred-btn" data-copy="tpLrn"><i class="fas fa-copy me-1"></i>Copy</button>
                        </div>
                    </div>
                    <div class="cred-row">
                        <div class="k">Temporary Password</div>
                        <div class="cred-box">
                            <span class="val pw" id="tpPassword">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</span>
                            <button type="button" class="cred-btn" id="tpToggle"><i class="fas fa-eye me-1"></i>Show</button>
                            <button type="button" class="cred-btn" data-copy="tpPassword" id="tpCopy"><i class="fas fa-copy me-1"></i>Copy</button>
                        </div>
                    </div>
                    <div class="cred-note mb-3">
                        <i class="fas fa-circle-info me-1"></i>This is a <strong>temporary password</strong>. Use it for your first login only &mdash; you will be required to create a new password immediately after signing in.
                    </div>
                    <a href="student_login.php" class="btn btn-success btn-wide"><i class="fas fa-right-to-bracket me-1"></i>Go to Student Portal Login</a>
                </div>
                <script>
                // Show / Hide + Copy. The password value is already in this page;
                // a refresh only READS it back, it is never regenerated.
                (function () {
                    var REAL = <?php echo json_encode($sp['password']); ?>;
                    var pw = document.getElementById('tpPassword');
                    var toggle = document.getElementById('tpToggle');
                    var copyPw = document.getElementById('tpCopy');
                    var shown = false;
                    function paint() {
                        pw.textContent = shown ? REAL : '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022';
                        toggle.innerHTML = shown ? '<i class="fas fa-eye-slash me-1"></i>Hide' : '<i class="fas fa-eye me-1"></i>Show';
                        copyPw.disabled = !shown;
                    }
                    toggle.addEventListener('click', function () { shown = !shown; paint(); });
                    paint();

                    var toast = document.getElementById('copyToast');
                    function say(msg) {
                        if (!toast) return;
                        toast.textContent = msg;
                        toast.classList.add('show');
                        clearTimeout(toast._t);
                        toast._t = setTimeout(function () { toast.classList.remove('show'); }, 1800);
                    }
                    document.querySelectorAll('[data-copy]').forEach(function (b) {
                        b.addEventListener('click', function () {
                            var src = document.getElementById(b.getAttribute('data-copy'));
                            if (!src) return;
                            var text = src.id === 'tpPassword' && !shown ? '' : src.textContent.trim();
                            if (!text) { say('Show the password first to copy it.'); return; }
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(text).then(function () { say('Copied!'); }, function () { say('Press and hold to copy.'); });
                            } else {
                                say('Press and hold to copy.');
                            }
                        });
                    });
                })();
                </script>
            <?php elseif ($sp['state'] === 'activated'): ?>
                <?php /* Password already changed: the temporary password is gone
                         and must never be shown again. */ ?>
                <div class="card-x a-green">
                    <div class="card-head">
                        <div class="ic"><i class="fas fa-circle-check"></i></div>
                        <div><h5 style="color:#1F9D6B;">Student Portal Activated</h5>
                        <p class="sub">Your password has been set.</p></div>
                    </div>
                    <p class="text-muted mb-3" style="font-size:14.5px;line-height:1.6;">
                        Sign in with your LRN <strong><?php echo htmlspecialchars($sp['lrn']); ?></strong> and the password you created. The temporary password is no longer valid.
                    </p>
                    <a href="student_login.php" class="btn btn-success btn-wide"><i class="fas fa-right-to-bracket me-1"></i>Go to Student Portal Login</a>
                </div>
            <?php elseif ($sp['state'] === 'unavailable'): ?>
                <div class="card-x a-orange">
                    <div class="card-head">
                        <div class="ic"><i class="fas fa-triangle-exclamation"></i></div>
                        <div><h5 style="color:#B45309;">Credentials Unavailable</h5>
                        <p class="sub">Your portal account could not be prepared automatically.</p></div>
                    </div>
                    <p class="text-muted mb-0" style="font-size:14.5px;line-height:1.6;">Please contact the registrar's office and they will set it up for you.</p>
                </div>
            <?php endif; ?>

            <?php /* Parent Portal: no guardian registration needed any more. Parents
                     sign in from the Student Portal login page (Parent tab) with the
                     learner's LRN and the parent/guardian name on record. */ ?>

            </div>
        <?php endif; ?>

        <?php /* Must stay the LAST thing on the page. Opens a fresh application via
                 online_enrollment.php?new=1, which starts a blank form -- it only
                 reads, never touches, the application shown above. */ ?>
        <div class="card-x a-grey">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                <div>
                    <div style="font-size:16px;font-weight:800;margin-bottom:2px;">New application for another learner</div>
                    <div class="text-muted" style="font-size:14.5px;">Submit a new enrollment application for another student.</div>
                </div>
                <a href="online_enrollment.php?new=1" class="btn btn-outline-primary px-4" style="border-radius:12px;font-weight:700;white-space:nowrap;"
                   onclick="return confirm('Start a new application for another learner? Your current application stays saved; keep its tracking number.');">
                    <i class="fas fa-plus me-1"></i>Start New Enrollment
                </a>
            </div>
        </div>
    </div>
    <div id="copyToast" role="status" aria-live="polite"></div>
    <?php /* Parent Portal pop-up removed: parents now sign in from the Student
             Portal login page (Parent tab) with the learner's LRN and the
             parent/guardian name on record -- no separate registration. */ ?>
    <script src="static/js/theme.js"></script>
</body>

</html>
