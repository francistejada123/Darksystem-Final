<?php
// modules/enrollment/view_application.php
// ADMIN — review one online enrollment application, view uploaded documents, approve or reject.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/enrollment_helpers_online.php';
require_once __DIR__ . '/../../includes/attendance_qr_helpers.php'; // attQrLrnLink()

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Registrar', 'Enrollment Officer'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$application_id = intval($_GET['id'] ?? 0);
$application = getRecord("SELECT * FROM online_applications WHERE application_id = ?", [$application_id]);

if (!$application) {
    $_SESSION['error_message'] = 'Application not found.';
    header('Location: applications.php');
    exit;
}

// Mark as Under Review the first time a staff member opens it
if ($application['status'] === 'Pending') {
    executeQuery("UPDATE online_applications SET status = 'Under Review', reviewed_by = ? WHERE application_id = ?", [$_SESSION['user_id'], $application_id]);
    $application['status'] = 'Under Review';
}

$documents = getApplicationDocuments($application_id);
// Approved applicants without a submitted LRN get a temporary one (T…) when the student record is created.
$application_student = !empty($application['student_id']) ? getRecord("SELECT lrn FROM students WHERE student_id = ?", [(int) $application['student_id']]) : null;
$application_student_lrn = $application_student['lrn'] ?? '';
// Continuing / Returning applicants are matched to their saved student record by LRN
// (approval reuses it -- never a second student). Show which details they changed.
$existing_match = null;
$existing_diffs = [];
if (!empty($application['lrn']) && in_array($application['application_type'], ['Continuing', 'Returning'], true)) {
    $existing_match = getRecord("SELECT * FROM students WHERE lrn = ?", [$application['lrn']]);
    if ($existing_match) {
        $cmp_fields = ['contact_number' => 'Mobile Number', 'email' => 'Email', 'address' => 'Address', 'guardian_name' => 'Parent/Guardian', 'guardian_contact' => 'Guardian Contact'];
        if ($application['application_type'] === 'Returning') {
            $cmp_fields = ['last_name' => 'Last Name', 'first_name' => 'First Name', 'middle_name' => 'Middle Name', 'gender' => 'Sex'] + $cmp_fields;
        }
        $norm_cmp = function ($v) { return trim(html_entity_decode((string) $v, ENT_QUOTES, 'UTF-8')); };
        foreach ($cmp_fields as $col => $label) {
            $was = $norm_cmp($existing_match[$col] ?? '');
            $now = $norm_cmp($application[$col] ?? '');
            if ($now !== '' && $now !== $was) $existing_diffs[] = ['label' => $label, 'was' => $was, 'now' => $now];
        }
    }
}
$doc_by_key = [];
foreach ($documents as $d) $doc_by_key[$d['doc_key']] = $d;
// The checklist follows the applicant's Applicant Type (Report Card / Birth Certificate rules);
// applications from before those rules, or with a combination they no longer allow, use the grade's original list.
$required_docs = getRequiredDocuments($application['grade_level'], $application['application_type'])
    ?: getRequiredDocuments($application['grade_level']);
// Anything else the applicant did upload is still listed.
$listed_doc_keys = array_column($required_docs, 'key');
foreach ($documents as $d) {
    if (!in_array($d['doc_key'], $listed_doc_keys, true)) {
        $required_docs[] = ['key' => $d['doc_key'], 'label' => $d['doc_label'], 'hint' => '', 'required' => false];
        $listed_doc_keys[] = $d['doc_key'];
    }
}

$page_title = 'Review Application';
$current_page = 'applications';
$base_url = BASE_URL;
$csrf_token = generateCSRFToken();

include '../../includes/header.php';

$badge = ['Pending' => 'pending', 'Under Review' => 'review', 'Approved' => 'approved', 'Rejected' => 'rejected'][$application['status']] ?? 'pending';
$full_name = trim($application['last_name'] . ', ' . $application['first_name'] . ' ' . $application['middle_name'] . ' ' . $application['suffix']);
?>

<?php
// ---- Display-only values for the review layout (nothing here changes data) ----
$ar_initials = mb_strtoupper(mb_substr(trim($application['first_name']), 0, 1) . mb_substr(trim($application['last_name']), 0, 1));
$ar_display_name = trim($application['first_name'] . ' ' . ($application['middle_name'] !== '' && $application['middle_name'] !== null ? mb_substr($application['middle_name'], 0, 1) . '. ' : '') . $application['last_name'] . ' ' . $application['suffix']);
$ar_grade_num = (int) preg_replace('/\D/', '', (string) $application['grade_level']);
$ar_level = $ar_grade_num >= 11 ? 'Senior High School' . ($application['strand'] ? ' · ' . ($application['strand'] === 'ASSH' ? 'ASSH Cluster' : $application['strand']) : '') : 'Junior High School';
$ar_submitted = strtotime($application['created_at']);
$ar_days = (int) floor((time() - $ar_submitted) / 86400);
$ar_ago = $ar_days <= 0 ? 'Today' : ($ar_days === 1 ? 'Yesterday' : $ar_days . ' days ago');
$ar_age = !empty($application['birth_date']) ? (int) date_diff(date_create($application['birth_date']), date_create('today'))->y : null;
$ar_decided = in_array($application['status'], ['Approved', 'Rejected'], true);
$ar_req_total = 0;
$ar_req_done = 0;
foreach ($required_docs as $rd) {
    if (!empty($rd['required'])) {
        $ar_req_total++;
        if (isset($doc_by_key[$rd['key']])) $ar_req_done++;
    }
}
$ar_size = function ($bytes) {
    $bytes = (int) $bytes;
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024) . ' KB';
    return $bytes > 0 ? $bytes . ' B' : '';
};
$ar_can_decide = in_array($application['status'], ['Pending', 'Under Review'], true);
?>

<style>
.app-review { padding: 20px 24px 110px; }
.app-review .ar-crumbs { font-size: 13px; color: var(--text-muted); margin-bottom: 6px; }
.app-review .ar-crumbs a { color: var(--text-muted); text-decoration: none; }
.app-review .ar-crumbs a:hover { color: var(--ink); }
.app-review .ar-crumbs .sep { margin: 0 8px; font-size: 10px; opacity: .7; }
.app-review .ar-crumbs .current { color: var(--ink); font-weight: 600; }
.app-review .ar-title-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
.app-review .ar-title-row h4 { font-size: 26px; font-weight: 800; color: var(--ink); margin: 0 0 4px; letter-spacing: -.01em; }
.app-review .ar-title-row p { margin: 0; font-size: 14px; color: var(--text-muted); }
.app-review .btn-back { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; font-size: 14px; font-weight: 600; color: var(--ink); background: var(--surface); border: 1px solid var(--border); text-decoration: none; }
.app-review .btn-back:hover { background: var(--surface-hover); color: var(--ink); }

.app-review .ar-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; box-shadow: 0 1px 3px rgba(16,24,40,.04); }
.app-review .ar-summary { display: flex; align-items: center; gap: 22px; padding: 18px 24px; margin-bottom: 18px; flex-wrap: wrap; }
.app-review .ar-avatar { width: 64px; height: 64px; border-radius: 50%; background: #dbe8fb; color: #1d5fbf; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; flex-shrink: 0; }
.app-review .ar-who { min-width: 220px; }
.app-review .ar-who .name { font-size: 21px; font-weight: 800; color: var(--ink); line-height: 1.2; }
.app-review .ar-who .meta { font-size: 13.5px; color: var(--text-muted); margin-top: 4px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.app-review .ar-who .meta strong { color: var(--ink); font-weight: 600; }
.app-review .ar-divider { width: 1px; align-self: stretch; background: var(--border-soft); }
.app-review .ar-fact { display: flex; align-items: center; gap: 12px; }
.app-review .ar-fact .ic { width: 44px; height: 44px; border-radius: 50%; border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; color: var(--ink); font-size: 17px; }
.app-review .ar-fact .lbl { font-size: 12px; color: var(--text-muted); }
.app-review .ar-fact .val { font-size: 15px; font-weight: 700; color: var(--ink); }
.app-review .ar-fact .sub { font-size: 12px; color: var(--text-muted); }
.app-review .ar-steps { display: flex; align-items: flex-start; margin-left: auto; }
.app-review .ar-step { display: flex; flex-direction: column; align-items: center; width: 96px; position: relative; font-size: 13px; color: var(--text-muted); }
.app-review .ar-step .dot { width: 24px; height: 24px; border-radius: 50%; border: 2px solid #c5cedb; background: var(--surface); display: flex; align-items: center; justify-content: center; margin-bottom: 8px; z-index: 1; color: #fff; font-size: 11px; }
.app-review .ar-step.done .dot { background: #1f9d6b; border-color: #1f9d6b; }
.app-review .ar-step.current .dot { border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,.15); }
.app-review .ar-step.current .dot::after { content: ""; width: 12px; height: 12px; border-radius: 50%; background: #2563eb; }
.app-review .ar-step.current { color: var(--ink); font-weight: 700; }
.app-review .ar-step:not(:last-child)::after { content: ""; position: absolute; top: 11px; left: 60px; width: 72px; height: 2px; background: #c5cedb; }
.app-review .ar-step.done:not(:last-child)::after { background: #1f9d6b; }

.app-review .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; }
.app-review .status-pending { background: #FFF7E6; color: #B7791F; }
.app-review .status-review { background: #dff3fb; color: #0b6a8a; }
.app-review .status-approved { background: #EAF7F1; color: #238863; }
.app-review .status-rejected { background: #FDEEEE; color: #C94A4A; }

.app-review .content-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 18px; align-items: start; }
.app-review .ar-card + .ar-card { margin-top: 18px; }
.app-review .ar-card-head { display: flex; align-items: center; gap: 12px; padding: 16px 22px; border-bottom: 1px solid var(--border-soft); }
.app-review .ar-card-head i.head-ic { color: #2563eb; font-size: 18px; width: 22px; text-align: center; }
.app-review .ar-card-head h5 { margin: 0; font-size: 16.5px; font-weight: 700; color: var(--ink); }
.app-review .ar-card-head .right { margin-left: auto; font-size: 13.5px; font-weight: 700; color: #1f9d6b; }
.app-review .ar-card-head .right.warn { color: #B7791F; }
.app-review .detail-table { width: 100%; border-collapse: collapse; }
.app-review .detail-table th { width: 36%; padding: 11px 22px; font-size: 13.5px; font-weight: 500; color: var(--text-muted); text-align: left; border-bottom: 1px solid var(--border-soft); background: var(--surface-hover); vertical-align: top; }
.app-review .detail-table td { padding: 11px 22px; font-size: 14px; color: var(--ink); border-bottom: 1px solid var(--border-soft); }
.app-review .detail-table tr:last-child th, .app-review .detail-table tr:last-child td { border-bottom: none; }
.app-review .ar-note-body { padding: 16px 22px; font-size: 13.5px; color: var(--ink); }

.app-review .ar-doc { display: flex; align-items: center; gap: 16px; padding: 16px 22px; border-bottom: 1px solid var(--border-soft); }
.app-review .ar-doc:last-child { border-bottom: none; }
.app-review .ar-thumb { width: 72px; height: 72px; border-radius: 8px; border: 1px solid var(--border); background: #f6f7f9; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; color: #C94A4A; font-size: 28px; }
.app-review .ar-thumb img { width: 100%; height: 100%; object-fit: cover; }
.app-review .ar-thumb.missing { color: #9aa4b2; font-size: 24px; }
.app-review .ar-doc-main { flex: 1; min-width: 0; }
.app-review .ar-doc-main .dname { font-size: 15px; font-weight: 700; color: var(--ink); }
.app-review .ar-doc-main .dstate { font-size: 13.5px; font-weight: 600; color: #1f9d6b; margin-top: 3px; }
.app-review .ar-doc-main .dstate.missing { color: #C94A4A; }
.app-review .ar-doc-main .dstate.optional { color: var(--text-muted); }
.app-review .ar-doc-main .dmeta { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; }
.app-review .btn-view { display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px; border-radius: 10px; font-size: 14px; font-weight: 700; color: #2563eb; background: #eef4ff; border: 1px solid #d6e4ff; text-decoration: none; }
.app-review .btn-view:hover { background: #2563eb; color: #fff; }
.app-review .btn-dl { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 10px; border: 1px solid var(--border); color: var(--text-muted); text-decoration: none; margin-left: 6px; }
.app-review .btn-dl:hover { color: var(--ink); background: var(--surface-hover); }

.app-review .ar-decision { padding: 18px 22px; }
.app-review .ar-lrn-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 6px; }
.app-review .ar-lrn-row .lbl { font-size: 14.5px; font-weight: 700; color: var(--ink); }
.app-review .ar-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 99px; font-size: 12.5px; font-weight: 700; }
.app-review .ar-chip.ok { background: #e7f6ee; color: #1f7a54; }
.app-review .ar-chip.changed { background: #fff4e0; color: #9a5b00; }
.app-review .ar-chip.none { background: var(--surface-hover); color: var(--text-muted); }
.app-review .ar-lrn-help { font-size: 12.5px; color: var(--text-muted); margin: 0 0 12px; }
.app-review .ar-lrn-grid { display: grid; grid-template-columns: minmax(180px, 220px) 1fr; gap: 12px; align-items: center; margin-bottom: 16px; }
.app-review .ar-lrn-grid input { border: 1px solid var(--border); border-radius: 10px; padding: 11px 14px; font-size: 15px; width: 100%; font-family: ui-monospace, monospace; background: var(--surface-hover); color: var(--ink); }
.app-review .ar-lrn-grid input:focus { outline: none; border-color: #2563eb; background: var(--surface); box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
.app-review .ar-info-box { display: flex; gap: 8px; font-size: 12px; color: #33507a; background: #eef4ff; border-radius: 10px; padding: 10px 12px; line-height: 1.45; }
.app-review .ar-info-box i { color: #2563eb; margin-top: 2px; }
.app-review .ar-remarks-lbl { font-size: 14.5px; font-weight: 700; color: var(--ink); margin-bottom: 8px; display: block; }
.app-review .ar-remarks-lbl span { font-weight: 400; color: var(--text-muted); }
.app-review .ar-remarks { position: relative; }
.app-review .ar-remarks textarea { border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px 26px; font-size: 14px; color: var(--ink); width: 100%; resize: vertical; min-height: 92px; background: var(--surface); }
.app-review .ar-remarks textarea:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
.app-review .ar-remarks .count { position: absolute; right: 12px; bottom: 10px; font-size: 12px; color: var(--text-muted); }
.app-review .ar-hint { font-size: 12.5px; color: var(--text-muted); margin: 10px 0 0; }
.app-review .ar-decided { padding: 18px 22px; font-size: 14px; color: var(--ink); }

/* Sticky action bar */
.ar-actionbar { position: fixed; left: var(--sidebar-width, 240px); right: 0; bottom: 0; z-index: 30; display: flex; align-items: center; gap: 14px; padding: 12px 28px; background: var(--surface); border-top: 1px solid var(--border); box-shadow: 0 -6px 18px rgba(16,24,40,.06); }
body.sidebar-collapsed .ar-actionbar { left: var(--sidebar-collapsed-width, 72px); }
.ar-actionbar .app-id { font-size: 14px; color: var(--text-muted); }
.ar-actionbar .app-id strong { color: var(--ink); }
.ar-actionbar .spacer { flex: 1; }
.ar-actionbar .btn-reject { display: inline-flex; align-items: center; gap: 10px; padding: 12px 22px; border-radius: 10px; font-size: 15px; font-weight: 700; color: var(--ink); background: var(--surface); border: 1px solid var(--border); cursor: pointer; }
.ar-actionbar .btn-reject i { color: #d93025; }
.ar-actionbar .btn-reject:hover { border-color: #d93025; background: #fdecea; }
.ar-actionbar .btn-approve { display: inline-flex; align-items: center; gap: 10px; padding: 12px 34px; border-radius: 10px; font-size: 15px; font-weight: 700; color: #fff; background: #1b7a6e; border: none; cursor: pointer; }
.ar-actionbar .btn-approve:hover { filter: brightness(1.08); }

html[data-theme="dark"] .app-review .ar-avatar { background: rgba(138,180,248,.18); color: #aecbfa; }
html[data-theme="dark"] .app-review .ar-thumb { background: #16223A; }
html[data-theme="dark"] .app-review .btn-view { background: rgba(138,180,248,.12); border-color: rgba(138,180,248,.25); color: #aecbfa; }
html[data-theme="dark"] .app-review .ar-info-box { background: rgba(138,180,248,.10); color: #c7d7f2; }
html[data-theme="dark"] .app-review .ar-chip.ok { background: rgba(129,201,149,.16); color: #a8dab5; }
html[data-theme="dark"] .app-review .ar-chip.changed { background: rgba(253,214,99,.14); color: #fdd663; }
html[data-theme="dark"] .ar-actionbar .btn-reject:hover { background: rgba(217,48,37,.12); }
@media (max-width: 1100px) { .app-review .ar-steps { margin-left: 0; } .app-review .ar-divider { display: none; } }
@media (max-width: 900px) {
    .app-review .content-grid { grid-template-columns: 1fr; }
    .app-review .ar-lrn-grid { grid-template-columns: 1fr; }
    .ar-actionbar { left: 0; padding: 10px 14px; flex-wrap: wrap; }
    .ar-actionbar .app-id { width: 100%; }
}
</style>

<div class="app-review">

    <div class="ar-crumbs">
        <a href="applications.php">Applications</a><i class="fas fa-chevron-right sep"></i><a href="applications.php">Inbox</a><i class="fas fa-chevron-right sep"></i><span class="current">Review Application</span>
    </div>
    <div class="ar-title-row">
        <div>
            <h4>Online Application Review</h4>
            <p>Review the applicant's information, submitted documents, and decide on the application.</p>
        </div>
    </div>

    <!-- Summary -->
    <div class="ar-card ar-summary">
        <div class="ar-avatar"><?php echo htmlspecialchars($ar_initials); ?></div>
        <div class="ar-who">
            <div class="name"><?php echo htmlspecialchars($ar_display_name); ?></div>
            <div class="meta">Application ID: <strong><?php echo htmlspecialchars($application['reference_no']); ?></strong>
                <span class="status-badge status-<?php echo $badge; ?>"><?php echo htmlspecialchars($application['status']); ?></span></div>
        </div>
        <div class="ar-divider"></div>
        <div class="ar-fact">
            <div class="ic"><i class="fas fa-graduation-cap"></i></div>
            <div><div class="lbl">Applying for</div><div class="val"><?php echo htmlspecialchars($application['grade_level']); ?></div><div class="sub"><?php echo htmlspecialchars($ar_level); ?></div></div>
        </div>
        <div class="ar-divider"></div>
        <div class="ar-fact">
            <div class="ic"><i class="far fa-calendar"></i></div>
            <div><div class="lbl">Date Submitted</div><div class="val"><?php echo date('M j, Y', $ar_submitted); ?></div><div class="sub"><?php echo htmlspecialchars($ar_ago); ?></div></div>
        </div>
        <div class="ar-divider"></div>
        <div class="ar-steps" aria-label="Application progress">
            <div class="ar-step done"><span class="dot"><i class="fas fa-check"></i></span>Submitted</div>
            <div class="ar-step <?php echo $ar_decided ? 'done' : 'current'; ?>"><span class="dot"><?php echo $ar_decided ? '<i class="fas fa-check"></i>' : ''; ?></span>Under Review</div>
            <div class="ar-step <?php echo $ar_decided ? 'done' : ''; ?>"><span class="dot"><?php echo $ar_decided ? '<i class="fas fa-check"></i>' : ''; ?></span><?php echo $ar_decided ? htmlspecialchars($application['status']) : 'Decided'; ?></div>
        </div>
    </div>

    <div class="content-grid">
        <div>
            <?php if ($existing_match): ?>
            <!-- Existing student record (Continuing / Returning) -->
            <div class="ar-card">
                <div class="ar-card-head"><i class="fas fa-id-badge head-ic"></i><h5>Existing Student Record</h5></div>
                <div class="ar-note-body">
                    <p style="margin:0 0 8px;">
                        Matched by LRN to <strong><?php echo htmlspecialchars($existing_match['first_name'] . ' ' . $existing_match['last_name']); ?></strong>
                        (student ID <?php echo (int) $existing_match['student_id']; ?>, last on record: <?php echo htmlspecialchars($existing_match['grade_level']); ?>).
                        Approving <strong>reuses this record</strong> &mdash; no duplicate student is created.
                        <?php echo $application['application_type'] === 'Returning' ? 'The learner reviewed and re-submitted every detail.' : 'Name, sex and date of birth were locked; only the details below could be changed.'; ?>
                    </p>
                    <?php if ($existing_diffs): ?>
                        <table class="detail-table">
                            <?php foreach ($existing_diffs as $df): ?>
                                <tr><th><?php echo htmlspecialchars($df['label']); ?></th><td><span style="text-decoration:line-through;opacity:.65"><?php echo htmlspecialchars($df['was'] !== '' ? $df['was'] : '(empty)'); ?></span> &rarr; <strong><?php echo htmlspecialchars($df['now']); ?></strong></td></tr>
                            <?php endforeach; ?>
                        </table>
                        <?php if ($application['status'] !== 'Approved'): ?><div style="font-size:12px;opacity:.75;margin-top:6px">These changed details are written to the student record when you approve.</div><?php endif; ?>
                    <?php elseif ($application['status'] === 'Approved'): ?>
                        <div style="opacity:.85"><i class="fas fa-circle-check"></i> Approved &mdash; the learner's changed details (if any) were applied to the student record.</div>
                    <?php else: ?>
                        <div style="opacity:.85"><i class="fas fa-circle-check"></i> No details changed from the saved record.</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Learner Information -->
            <div class="ar-card">
                <div class="ar-card-head"><i class="fas fa-user head-ic"></i><h5>Learner Information</h5></div>
                <table class="detail-table">
                    <tr><th>Full Name</th><td><?php echo htmlspecialchars($full_name); ?></td></tr>
                    <tr><th>LRN</th><td><?php echo attQrLrnLink($application['lrn'] ?: ($application_student_lrn ?: 'None (new learner)')); ?></td></tr>
                    <tr><th>Applying for</th><td><strong><?php echo htmlspecialchars($application['grade_level']); ?></strong><?php echo $application['strand'] ? ' — ' . htmlspecialchars($application['strand'] === 'ASSH' ? 'ASSH Cluster' : $application['strand']) : ''; ?> (<?php echo htmlspecialchars($application['application_type']); ?>)</td></tr>
                    <tr><th>Date of Birth</th><td><?php echo formatDate($application['birth_date']); ?><?php echo $ar_age !== null ? ' &nbsp;•&nbsp; ' . $ar_age . ' years old' : ''; ?><?php echo $application['birth_place'] ? ' &nbsp;•&nbsp; ' . htmlspecialchars($application['birth_place']) : ''; ?></td></tr>
                    <tr><th>Sex</th><td><?php echo htmlspecialchars($application['gender']); ?></td></tr>
                    <tr><th>Religion / Nationality</th><td><?php echo htmlspecialchars($application['religion'] . ' / ' . $application['nationality']); ?></td></tr>
                    <tr><th>Contact</th><td><?php echo htmlspecialchars($application['contact_number']); ?> &nbsp;•&nbsp; <?php echo htmlspecialchars($application['email']); ?></td></tr>
                    <tr><th>Address</th><td><?php echo htmlspecialchars($application['address']); ?></td></tr>
                </table>
            </div>

            <!-- Parent / Guardian -->
            <div class="ar-card">
                <div class="ar-card-head"><i class="fas fa-users head-ic"></i><h5>Parent / Guardian Information</h5></div>
                <table class="detail-table">
                    <tr><th>Father</th><td><?php echo htmlspecialchars($application['father_name'] ?: '—'); ?></td></tr>
                    <tr><th>Mother</th><td><?php echo htmlspecialchars($application['mother_name'] ?: '—'); ?></td></tr>
                    <tr><th>Guardian</th><td><?php echo htmlspecialchars($application['guardian_name']); ?> (<?php echo htmlspecialchars($application['guardian_relationship'] ?: 'Guardian'); ?>)</td></tr>
                    <tr><th>Contact (Guardian)</th><td><?php echo htmlspecialchars($application['guardian_contact']); ?></td></tr>
                </table>
            </div>
        </div>

        <div>
            <!-- Submitted Requirements -->
            <div class="ar-card">
                <div class="ar-card-head">
                    <i class="fas fa-file-lines head-ic"></i><h5>Submitted Requirements (<?php echo htmlspecialchars($application['grade_level']); ?>)</h5>
                    <?php if ($ar_req_total): ?>
                        <span class="right <?php echo $ar_req_done < $ar_req_total ? 'warn' : ''; ?>"><i class="fas <?php echo $ar_req_done < $ar_req_total ? 'fa-circle-exclamation' : 'fa-circle-check'; ?> me-1"></i><?php echo $ar_req_done; ?> of <?php echo $ar_req_total; ?> required</span>
                    <?php endif; ?>
                </div>
                <?php foreach ($required_docs as $doc):
                    $d = $doc_by_key[$doc['key']] ?? null;
                    $d_url = $d ? BASE_URL . $d['file_path'] : '';
                    $d_is_img = $d && strpos((string) $d['file_type'], 'image/') === 0;
                    $d_ext = $d ? strtoupper(pathinfo($d['file_path'], PATHINFO_EXTENSION)) : '';
                ?>
                    <div class="ar-doc">
                        <div class="ar-thumb <?php echo $d ? '' : 'missing'; ?>">
                            <?php if ($d_is_img): ?><img src="<?php echo htmlspecialchars($d_url); ?>" alt="">
                            <?php elseif ($d): ?><i class="fas <?php echo $d_ext === 'PDF' ? 'fa-file-pdf' : 'fa-file'; ?>"></i>
                            <?php else: ?><i class="far fa-file"></i><?php endif; ?>
                        </div>
                        <div class="ar-doc-main">
                            <div class="dname"><?php echo htmlspecialchars($doc['label']); ?></div>
                            <?php if ($d): ?>
                                <div class="dstate"><i class="fas fa-circle-check me-1"></i>File uploaded</div>
                                <div class="dmeta"><?php echo date('M j, Y', strtotime($d['uploaded_at'])); ?><?php echo $ar_size($d['file_size']) ? ' &nbsp;•&nbsp; ' . $ar_size($d['file_size']) : ''; ?><?php echo $d_ext ? ' &nbsp;•&nbsp; ' . htmlspecialchars($d_ext) : ''; ?></div>
                            <?php else: ?>
                                <div class="dstate <?php echo $doc['required'] ? 'missing' : 'optional'; ?>"><i class="fas <?php echo $doc['required'] ? 'fa-circle-xmark' : 'fa-circle-minus'; ?> me-1"></i>Not submitted<?php echo $doc['required'] ? ' (required)' : ' (optional)'; ?></div>
                            <?php endif; ?>
                        </div>
                        <?php if ($d): ?>
                            <a href="<?php echo htmlspecialchars($d_url); ?>" target="_blank" rel="noopener" class="btn-view"><i class="fas fa-eye"></i> View</a>
                            <a href="<?php echo htmlspecialchars($d_url); ?>" download class="btn-dl" title="Download"><i class="fas fa-download"></i></a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Decision -->
            <div class="ar-card">
                <div class="ar-card-head"><i class="fas fa-shield-halved head-ic"></i><h5>Decision</h5></div>
                <?php if ($ar_can_decide): ?>
                    <form method="POST" action="application_action.php" id="arDecisionForm" class="ar-decision">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="application_id" value="<?php echo $application_id; ?>">

                        <div class="ar-lrn-row">
                            <span class="lbl">LRN Verification</span>
                            <span class="ar-chip" id="arLrnChip"></span>
                        </div>
                        <p class="ar-lrn-help">Check this LRN against the uploaded Form 137/138 before approving — this is what gets saved to the student record.</p>
                        <div class="ar-lrn-grid">
                            <input type="text" name="lrn" id="arLrn" maxlength="12" inputmode="numeric" pattern="[0-9]{12}"
                                   title="12 digits, or leave blank if the learner has no LRN yet"
                                   value="<?php echo htmlspecialchars($application['lrn'] ?? ''); ?>"
                                   placeholder="12-digit LRN, or leave blank" data-original="<?php echo htmlspecialchars($application['lrn'] ?? ''); ?>">
                            <div class="ar-info-box"><i class="fas fa-circle-info"></i><span>If it doesn't match the uploaded documents, encode the correct LRN here based on the uploaded documents.</span></div>
                        </div>

                        <label class="ar-remarks-lbl" for="arRemarks">Remarks <span>(sent to the applicant, optional)</span></label>
                        <div class="ar-remarks">
                            <textarea name="remarks" id="arRemarks" rows="3" placeholder="e.g. All documents are clear and complete."></textarea>
                            <span class="count" id="arRemarksCount">0 / 500</span>
                        </div>
                        <p class="ar-hint"><i class="fas fa-circle-info me-1"></i>Approving creates the student record and a pending enrollment with an automatically assigned section.</p>
                    </form>
                <?php else: ?>
                    <div class="ar-decided">
                        <strong>Already <?php echo htmlspecialchars($application['status']); ?></strong>
                        <?php if ($application['reviewed_at']): ?><br><small style="color: var(--text-muted);">on <?php echo formatDate($application['reviewed_at'], 'M d, Y h:i A'); ?></small><?php endif; ?>
                        <?php if ($application['remarks']): ?><hr style="border: none; border-top: 1px solid var(--border-soft); margin: 12px 0;"><?php echo nl2br(htmlspecialchars($application['remarks'])); ?><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Sticky action bar -->
<div class="ar-actionbar">
    <span class="app-id">Application ID: <strong><?php echo htmlspecialchars($application['reference_no']); ?></strong></span>
    <span class="status-badge status-<?php echo $badge; ?>" style="font-size:12px;font-weight:700;padding:4px 12px;border-radius:20px;<?php echo ['pending' => 'background:#FFF7E6;color:#B7791F', 'review' => 'background:#dff3fb;color:#0b6a8a', 'approved' => 'background:#EAF7F1;color:#238863', 'rejected' => 'background:#FDEEEE;color:#C94A4A'][$badge]; ?>"><?php echo htmlspecialchars($application['status']); ?></span>
    <span class="spacer"></span>
    <?php if ($ar_can_decide): ?>
        <button type="submit" form="arDecisionForm" name="action" value="reject" class="btn-reject" onclick="return appConfirm(this, 'Reject this application? The remarks are sent to the applicant.', {danger: true, okText: 'Reject'})">
            <i class="fas fa-triangle-exclamation"></i> Reject Application
        </button>
        <button type="submit" form="arDecisionForm" name="action" value="approve" class="btn-approve" onclick="return appConfirm(this, 'Approve this application and create the student record?')">
            <i class="fas fa-check"></i> Approve Application
        </button>
    <?php else: ?>
        <a href="applications.php" class="btn-reject" style="text-decoration:none;"><i class="fas fa-arrow-left" style="color:inherit"></i> Back to Inbox</a>
    <?php endif; ?>
</div>

<script>
(function () {
    // LRN chip: the same as what the applicant entered, changed, or none.
    var lrn = document.getElementById('arLrn'), chip = document.getElementById('arLrnChip');
    function paint() {
        if (!lrn || !chip) return;
        var v = lrn.value.trim(), orig = lrn.getAttribute('data-original') || '';
        if (v === '') { chip.className = 'ar-chip none'; chip.innerHTML = '<i class="fas fa-circle-minus"></i> No LRN yet'; }
        else if (!/^\d{12}$/.test(v)) { chip.className = 'ar-chip changed'; chip.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Must be 12 digits'; }
        else if (v === orig) { chip.className = 'ar-chip ok'; chip.innerHTML = '<i class="fas fa-circle-check"></i> Same as the applicant entered'; }
        else { chip.className = 'ar-chip changed'; chip.innerHTML = '<i class="fas fa-pen"></i> Corrected from ' + orig.replace(/[^0-9]/g, ''); }
    }
    if (lrn) { lrn.addEventListener('input', function () { lrn.value = lrn.value.replace(/\D/g, '').slice(0, 12); paint(); }); paint(); }
    var rm = document.getElementById('arRemarks'), cnt = document.getElementById('arRemarksCount');
    if (rm && cnt) rm.addEventListener('input', function () { cnt.textContent = rm.value.length + ' / 500'; cnt.style.color = rm.value.length > 500 ? '#d93025' : ''; });
})();
</script>

<?php include '../../includes/footer.php'; ?>
