<?php
// modules/enrollment/document_requests.php
//   (list)                 request queue with filters
//   ?id=N                  request details + status actions
//   ?id=N&certificate=1    printable document (real database values)
// Official Document Requests -- SEPARATE from Enrollment Registration.
// Status flow (staff): Pending -> Processing -> Ready for Release -> Released;
// Pending / Processing can also be Cancelled. Nothing goes backwards.

require_once __DIR__ . '/../../config/database.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/eo_scope.php';   // Enrollment Officer: own grade level only
require_once __DIR__ . '/../../includes/document_request_helpers.php';
require_once __DIR__ . '/../../includes/student_portal_data.php';   // spdCurriculumClasses(): Registered Subjects (COM), same source as Enrollment Registration

if (!hasAnyRole(['Administrator', 'Registrar', 'Enrollment Officer'])) {
    header('Location: ../../index.php');
    exit;
}
drEnsureSchema();

$page_title = 'Document Requests';
$current_page = 'document_requests';
$base_url = '../../';
$request_id = intval($_GET['id'] ?? 0);

/** One request with the student and the enrollment it was made for. */
function drLoad($id)
{
    return getRecord(
        "SELECT r.*, s.lrn, s.first_name, s.last_name, s.middle_name,
                e.enrollment_status, e.enrollment_date, e.grade_level AS e_grade, e.strand AS e_strand,
                sec.section_name, sy.sy_name,
                ru.first_name AS released_first, ru.last_name AS released_last
           FROM document_requests r
           JOIN students s ON s.student_id = r.student_id
           LEFT JOIN users ru ON ru.user_id = r.released_by
           LEFT JOIN enrollments e ON e.enrollment_id = r.enrollment_id
           LEFT JOIN sections sec ON sec.section_id = COALESCE(e.section_id, r.section_id)
           LEFT JOIN school_years sy ON sy.sy_id = COALESCE(e.school_year_id, r.school_year_id)
          WHERE r.request_id = ?",
        [(int) $id]
    );
}
function drName($r)
{
    $m = trim((string) ($r['middle_name'] ?? ''));
    return trim($r['last_name'] . ', ' . $r['first_name'] . ($m !== '' ? ' ' . mb_substr($m, 0, 1) . '.' : ''));
}
function drStrandLabel($s)
{
    $s = trim((string) $s);
    return $s === '' ? '' : (in_array(strtoupper($s), ['ASSH', 'HUMSS'], true) ? 'ASSH Cluster' : $s);
}

// ---- Delete a request (POST from the list; confirmed in a modal) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_request') {
    $del_id = intval($_POST['delete_id'] ?? 0);
    $r = $del_id > 0 ? drLoad($del_id) : null;
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token. Please try again.';
    } elseif (!$r) {
        $_SESSION['error_message'] = 'Request not found.';
    } else {
        eoGuard($r['grade_level'], 'document_requests.php');
        executeQuery("DELETE FROM document_requests WHERE request_id = ?", [$del_id]);
        logActivity((int) $_SESSION['user_id'], 'enrollment', 'Document request deleted',
            'Request #' . $del_id . ' (' . drDocumentLabel($r) . ', ' . $r['status'] . ') of ' . drName($r) . ' / LRN ' . $r['lrn']);
        $_SESSION['success_message'] = 'Request #' . $del_id . ' was deleted.';
    }
    $back = $_POST['back'] ?? '';
    header('Location: document_requests.php' . (is_string($back) && preg_match('/^\?[A-Za-z0-9_=&%+.\-]*$/', $back) ? $back : ''));
    exit;
}

// ---- Status change (POST) ----
if ($request_id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = drLoad($request_id);
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token. Please try again.';
    } elseif (!$r) {
        $_SESSION['error_message'] = 'Request not found.';
    } else {
        eoGuard($r['grade_level'], 'document_requests.php');
        // Claiming details (Ready for Release) / release confirmation are validated server-side in drStaffSetStatus().
        $res = drStaffSetStatus($request_id, (string) ($_POST['new_status'] ?? ''), (int) $_SESSION['user_id'], [
            'claiming_date' => $_POST['claiming_date'] ?? '', 'claiming_time_from' => $_POST['claiming_time_from'] ?? '',
            'claiming_time_until' => $_POST['claiming_time_until'] ?? '', 'claiming_location' => $_POST['claiming_location'] ?? '',
            'claiming_instructions' => $_POST['claiming_instructions'] ?? '', 'confirm_release' => $_POST['confirm_release'] ?? '',
        ]);
        $_SESSION[$res['ok'] ? 'success_message' : 'error_message'] = $res['message'];
    }
    header('Location: document_requests.php?id=' . $request_id);
    exit;
}

// ---- Printable document ----
if ($request_id > 0 && !empty($_GET['certificate'])) {
    $r = drLoad($request_id);
    if (!$r) { header('Location: document_requests.php'); exit; }
    eoGuard($r['grade_level'], 'document_requests.php');
    if (!in_array($r['status'], [DR_PROCESSING, DR_READY, DR_RELEASED], true)) {
        $_SESSION['error_message'] = 'Start processing the request before printing the document.';
        header('Location: document_requests.php?id=' . $request_id);
        exit;
    }
    $kind = drDocKind($r['document_type']);
    $sy_id = (int) ($r['school_year_id'] ?? 0);
    if ($kind === 'other') {
        $_SESSION['error_message'] = 'An Other Document is prepared manually; there is no system template for it.';
        header('Location: document_requests.php?id=' . $request_id);
        exit;
    }
    $subjects = $kind === 'com' ? spdCurriculumClasses((int) $r['student_id'], $sy_id) : [];
    $grades = $kind === 'grades' ? drOfficialGrades((int) $r['student_id'], $sy_id) : [];
    if ($kind === 'grades' && !$grades) {
        $_SESSION['error_message'] = 'Official/finalized grades are not yet available for this request.';
        header('Location: document_requests.php?id=' . $request_id);
        exit;
    }
    $grade = $r['e_grade'] ?: $r['grade_level'];
    $strand = in_array($grade, ['Grade 11', 'Grade 12'], true) ? drStrandLabel($r['e_strand'] ?: $r['strand']) : '';
    $school = function_exists('getSchoolName') ? getSchoolName() : 'Imelda Integrated Secondary School';
    // COE / COM formal template values (Certificate of Grades keeps its own layout below).
    $formal = in_array($kind, ['coe', 'com'], true);
    $addr_row = getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'school_address'");
    $school_address = trim((string) ($addr_row['setting_value'] ?? '')) ?: 'Hasa-Hasa St. cor. Langaray St., Longos, Malabon City';
    $issued_ts = time();
    $day = (int) date('j', $issued_ts);
    $ordinal = $day . ((($day % 100) >= 11 && ($day % 100) <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$day % 10] ?? 'th'));
    $issued_text = $ordinal . ' day of ' . date('F Y', $issued_ts);
    $purpose_text = drPurposeLabel($r);
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($r['document_type'] . ' - ' . drName($r)); ?></title>
<style>
    /* margin 0: Chrome/Edge print their own date/title/URL/page-number only inside the page
       margin, so with no margin they have nowhere to go. The white space is kept as padding
       on .doc instead (repeated on every page by box-decoration-break: clone). */
    @page { size: A4 portrait; margin: 0; }
    body { font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color: #111827; margin: 0; background: #f3f4f6; }
    .doc { max-width: 760px; margin: 24px auto; background: #fff; padding: 48px 56px; border: 1px solid #e5e7eb; border-radius: 8px; }
    .head { text-align: center; border-bottom: 2px solid #14213D; padding-bottom: 14px; margin-bottom: 28px; }
    .head img { height: 70px; width: 70px; object-fit: contain; }
    .school { font-size: 20px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; margin: 6px 0 0; }
    .title { font-size: 18px; font-weight: 700; letter-spacing: .14em; margin: 22px 0 0; text-transform: uppercase; }
    p.lead { font-size: 15.5px; margin: 0 0 14px; }
    .rows { margin: 0 0 18px 24px; }
    .row { display: flex; padding: 6px 0; font-size: 15.5px; }
    .row span:first-child { width: 170px; color: #4b5563; }
    .row span:last-child { font-weight: 700; }
    h3 { font-size: 14px; letter-spacing: .08em; text-transform: uppercase; color: #14213D; margin: 22px 0 8px; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th { text-align: left; background: #f9fafb; padding: 7px 9px; border: 1px solid #e5e7eb; font-size: 12.5px; text-transform: uppercase; letter-spacing: .04em; }
    td { padding: 7px 9px; border: 1px solid #e5e7eb; }
    tr { page-break-inside: avoid; break-inside: avoid; }
    .muted { color: #6b7280; font-size: 13px; }
    .bar { text-align: right; max-width: 760px; margin: 16px auto 0; }
    .bar button { background: #14213D; color: #fff; border: 0; padding: 9px 18px; border-radius: 6px; font-size: 14.5px; cursor: pointer; }
    /* ---- COE / COM formal template ---- */
    .doc.formal { font-family: 'Times New Roman', Times, Georgia, serif; color: #000; padding: 40px 56px; }
    .fhead { text-align: center; line-height: 1.35; border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 26px; }
    .fhead img { height: 78px; width: 78px; object-fit: contain; margin-bottom: 6px; }
    .fhead .gov { font-size: 13.5px; margin: 0; }
    .fhead .div { font-size: 13.5px; font-weight: 700; margin: 0; letter-spacing: .02em; }
    .fhead .sch { font-size: 19px; font-weight: 700; margin: 8px 0 0; letter-spacing: .03em; text-transform: uppercase; }
    .fhead .addr { font-size: 13.5px; margin: 2px 0 0; font-style: italic; }
    .ftitle { text-align: center; font-size: 20px; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; margin: 0 0 26px; }
    .formal p.body { font-size: 15.5px; line-height: 1.75; text-align: justify; margin: 0 0 16px; }
    .formal p.twimc { font-size: 15.5px; font-weight: 700; margin: 0 0 16px; }
    .formal .indent { text-indent: 40px; }
    .formal .frows { margin: 4px 0 20px 40px; }
    .formal .frow { display: flex; font-size: 15.5px; padding: 3px 0; }
    .formal .frow span:first-child { width: 160px; }
    .formal .frow span:last-child { font-weight: 700; }
    .formal h3 { font-family: inherit; color: #000; font-size: 14px; margin: 18px 0 8px; }
    .formal table { font-size: 14px; margin-bottom: 18px; }
    .formal th { background: #f3f4f6; color: #000; border-color: #9ca3af; }
    .formal td { border-color: #9ca3af; }
    .formal thead { display: table-header-group; }
    .sign { margin-top: 90px; width: 300px; margin-left: auto; text-align: center; page-break-inside: avoid; break-inside: avoid; }
    .sign .line { border-top: 1px solid #000; padding-top: 4px; font-size: 14.5px; font-weight: 700; }
    .sign .sub { font-size: 13px; }
    .stamp { margin-top: 18px; font-size: 12.5px; color: #374151; font-style: italic; }
    .closing { page-break-inside: avoid; break-inside: avoid; }
    @media print {
        html, body { background: #fff; margin: 0; padding: 0; }
        .bar { display: none !important; }
        .doc { border: none; border-radius: 0; margin: 0; padding: 15mm 18mm; max-width: none; box-shadow: none;
               -webkit-box-decoration-break: clone; box-decoration-break: clone; }
        .formal th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        /* COE / COM: same layout, tighter vertical rhythm so a normal COM fits one A4 page */
        .doc.formal { padding: 11mm 17mm 10mm; }
        .fhead { padding-bottom: 8px; margin-bottom: 14px; line-height: 1.3; }
        .fhead img { height: 64px; width: 64px; margin-bottom: 3px; }
        .fhead .sch { margin-top: 5px; }
        .ftitle { margin: 0 0 14px; }
        .formal p.twimc { margin: 0 0 8px; }
        .formal p.body { line-height: 1.5; margin: 0 0 10px; }
        .formal .frows { margin: 2px 0 10px 40px; }
        .formal .frow { padding: 1px 0; }
        .formal h3 { margin: 10px 0 6px; break-after: avoid; page-break-after: avoid; }
        .formal table { margin-bottom: 12px; }
        .formal th, .formal td { padding: 4px 8px; }
        .sign { margin-top: 70px; }
        .stamp { margin-top: 10px; }
    }
</style>
</head>
<body>
<div class="bar"><button type="button" onclick="window.print()">Print</button></div>
<?php if ($formal): ?>
<div class="doc formal">
    <div class="fhead">
        <?php if (file_exists(__DIR__ . '/../../static/images/logo.png')): ?><img src="<?php echo BASE_URL; ?>static/images/logo.png" alt=""><?php endif; ?>
        <p class="gov">Republic of the Philippines</p>
        <p class="gov">Department of Education</p>
        <p class="gov">National Capital Region</p>
        <p class="div">SCHOOLS DIVISION OFFICE &ndash; MALABON CITY</p>
        <p class="sch"><?php echo htmlspecialchars($school); ?></p>
        <p class="addr"><?php echo htmlspecialchars($school_address); ?></p>
    </div>
    <p class="ftitle"><?php echo $kind === 'com' ? 'Certificate of Matriculation' : 'Certificate of Enrollment'; ?></p>

    <p class="twimc">TO WHOM IT MAY CONCERN:</p>
    <p class="body indent">This is to certify that <strong><?php echo htmlspecialchars(mb_strtoupper(trim($r['first_name'] . ' ' . (trim((string) ($r['middle_name'] ?? '')) !== '' ? mb_substr(trim($r['middle_name']), 0, 1) . '. ' : '') . $r['last_name']))); ?></strong>,
        with Learner Reference Number (LRN) <strong><?php echo htmlspecialchars($r['lrn']); ?></strong>, is officially enrolled at
        <?php echo htmlspecialchars($school); ?> for School Year <strong><?php echo htmlspecialchars($r['sy_name'] ?? ''); ?></strong>.</p>
    <div class="frows">
        <div class="frow"><span>Grade Level:</span><span><?php echo htmlspecialchars($grade); ?></span></div>
        <?php if ($strand !== ''): ?><div class="frow"><span>Strand:</span><span><?php echo htmlspecialchars($strand); ?></span></div><?php endif; ?>
        <div class="frow"><span>Section:</span><span><?php echo htmlspecialchars($r['section_name'] ?? 'Not Assigned'); ?></span></div>
        <?php if ($kind === 'com' && !empty($r['enrollment_date'])): ?>
            <div class="frow"><span>Enrollment Date:</span><span><?php echo date('F d, Y', strtotime($r['enrollment_date'])); ?></span></div>
        <?php endif; ?>
    </div>

    <?php if ($kind === 'com'): ?>
        <h3>REGISTERED SUBJECTS</h3>
        <?php if ($subjects): ?>
            <table><thead><tr><th style="width:40px;">#</th><th style="width:190px;">Subject Code</th><th>Subject Name</th></tr></thead><tbody>
            <?php foreach ($subjects as $i => $sub): ?>
                <tr><td><?php echo $i + 1; ?></td><td><?php echo htmlspecialchars($sub['subject_code']); ?></td><td><?php echo htmlspecialchars($sub['subject_name']); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php else: ?><p class="muted">No registered subjects found for this enrollment.</p><?php endif; ?>
    <?php endif; ?>

    <div class="closing">
        <p class="body indent">This certification is issued upon the request of the above-named learner for <strong><?php echo htmlspecialchars($purpose_text); ?></strong> purposes.</p>
        <p class="body indent">Issued this <?php echo htmlspecialchars($issued_text); ?> at <?php echo htmlspecialchars($school); ?>, Malabon City.</p>
        <div class="sign">
            <div class="line">Authorized School Personnel</div>
            <div class="sub">Signature over Printed Name</div>
            <div class="stamp">[School Stamp / Seal if required]</div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="doc">
    <div class="head">
        <?php if (file_exists(__DIR__ . '/../../static/images/logo.png')): ?><img src="<?php echo BASE_URL; ?>static/images/logo.png" alt=""><?php endif; ?>
        <p class="school"><?php echo htmlspecialchars($school); ?></p>
        <p class="title"><?php echo htmlspecialchars(preg_replace('/\s*\((COE|COM)\)$/', '', drDocumentLabel($r))); ?></p>
    </div>
    <p class="lead">This certifies that:</p>
    <div class="rows">
        <div class="row"><span>Student Name</span><span><?php echo htmlspecialchars(drName($r)); ?></span></div>
        <div class="row"><span>LRN</span><span><?php echo htmlspecialchars($r['lrn']); ?></span></div>
    </div>
    <p class="lead"><?php echo $kind === 'grades' ? 'has the following official grades for:' : 'is currently enrolled for:'; ?></p>
    <div class="rows">
        <div class="row"><span>School Year</span><span><?php echo htmlspecialchars($r['sy_name'] ?? ''); ?></span></div>
        <div class="row"><span>Grade Level</span><span><?php echo htmlspecialchars($grade); ?></span></div>
        <?php if ($strand !== ''): ?><div class="row"><span>Strand</span><span><?php echo htmlspecialchars($strand); ?></span></div><?php endif; ?>
        <div class="row"><span>Section</span><span><?php echo htmlspecialchars($r['section_name'] ?? 'Not Assigned'); ?></span></div>
        <?php if ($kind === 'coe'): ?>
            <div class="row"><span>Enrollment Status</span><span><?php echo htmlspecialchars($r['enrollment_status'] === 'Approved' ? 'Officially Enrolled' : ($r['enrollment_status'] ?? '')); ?></span></div>
        <?php endif; ?>
        <?php if ($kind === 'com' && !empty($r['enrollment_date'])): ?>
            <div class="row"><span>Enrollment Date</span><span><?php echo date('F d, Y', strtotime($r['enrollment_date'])); ?></span></div>
        <?php endif; ?>
    </div>
    <?php if ($kind === 'com'): ?>
        <h3>Registered Subjects</h3>
        <?php if ($subjects): ?>
            <table><thead><tr><th style="width:40px;">#</th><th style="width:190px;">Subject Code</th><th>Subject Name</th></tr></thead><tbody>
            <?php foreach ($subjects as $i => $sub): ?>
                <tr><td><?php echo $i + 1; ?></td><td><?php echo htmlspecialchars($sub['subject_code']); ?></td><td><?php echo htmlspecialchars($sub['subject_name']); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php else: ?><p class="muted">No registered subjects found for this enrollment.</p><?php endif; ?>
    <?php elseif ($kind === 'grades'):
        $by_subject = [];
        foreach ($grades as $g) { $by_subject[$g['subject_code']]['name'] = $g['subject_name']; $by_subject[$g['subject_code']][$g['term']] = $g['final_grade']; }
    ?>
        <h3>Official Grades</h3>
        <table><thead><tr><th>Subject</th><th style="width:90px;">Term 1</th><th style="width:90px;">Term 2</th><th style="width:90px;">Term 3</th></tr></thead><tbody>
        <?php foreach ($by_subject as $code => $sg): ?>
            <tr><td><?php echo htmlspecialchars($sg['name']); ?></td>
                <?php foreach (['Term 1', 'Term 2', 'Term 3'] as $tt): ?><td><?php echo isset($sg[$tt]) ? htmlspecialchars(rtrim(rtrim(number_format((float) $sg[$tt], 2), '0'), '.')) : '—'; ?></td><?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <p class="muted">Only finalized term grades are listed. "—" means the term has no finalized grade yet.</p>
    <?php endif; ?>
    <div class="rows" style="margin-top:18px;">
        <div class="row"><span>Purpose</span><span><?php echo htmlspecialchars(drPurposeLabel($r)); ?></span></div>
        <div class="row"><span>Date Issued</span><span><?php echo date('F d, Y'); ?></span></div>
    </div>
    <div class="sign">
        <div class="line">Authorized School Personnel</div>
        <div class="sub">Signature over Printed Name</div>
        <div class="stamp">[School Stamp / Seal if required]</div>
    </div>
</div>
<?php endif; ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html>
<?php
    exit;
}

// ---- Details ----
$detail = null;
if ($request_id > 0) {
    $detail = drLoad($request_id);
    if (!$detail) { header('Location: document_requests.php'); exit; }
    eoGuard($detail['grade_level'], 'document_requests.php');
}

// ---- List ----
$f_status = in_array($_GET['status'] ?? '', [DR_PENDING, DR_PROCESSING, DR_READY, DR_RELEASED, DR_CANCELLED], true) ? $_GET['status'] : '';
$f_grade = in_array($_GET['grade'] ?? '', ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'], true) ? $_GET['grade'] : '';
$f_sy = intval($_GET['sy'] ?? 0);
$f_q = trim((string) ($_GET['q'] ?? ''));
$rows = [];
if (!$detail) {
    $where = '1 = 1';
    $params = [];
    [$eo_w, $eo_p] = eoSql('r.grade_level');
    $where .= $eo_w; $params = array_merge($params, $eo_p);
    if ($f_status !== '') { $where .= ' AND r.status = ?'; $params[] = $f_status; }
    if ($f_grade !== '') { $where .= ' AND r.grade_level = ?'; $params[] = $f_grade; }
    if ($f_sy > 0) { $where .= ' AND r.school_year_id = ?'; $params[] = $f_sy; }
    if ($f_q !== '') {
        $where .= " AND (s.lrn LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.last_name, ', ', s.first_name) LIKE ?)";
        $like = '%' . $f_q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $rows = getRecords(
        "SELECT r.*, s.lrn, s.first_name, s.last_name, s.middle_name, sec.section_name, sy.sy_name
           FROM document_requests r
           JOIN students s ON s.student_id = r.student_id
           LEFT JOIN sections sec ON sec.section_id = r.section_id
           LEFT JOIN school_years sy ON sy.sy_id = r.school_year_id
          WHERE $where
          ORDER BY FIELD(r.status, 'Pending', 'Processing', 'Ready for Release', 'Released', 'Cancelled'), r.requested_at DESC
          LIMIT 300",
        $params
    ) ?: [];
}
$school_years = getRecords("SELECT sy_id, sy_name FROM school_years ORDER BY sy_id DESC") ?: [];
$grade_options = eoGrade() !== null ? array_filter([eoGrade()]) : ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];

include '../../includes/header.php';
?>
<style>
.dr-page .dr-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; margin-bottom: 18px; }
.dr-page .dr-card-h { padding: 14px 18px; border-bottom: 1px solid var(--border); font-weight: 600; color: var(--ink); display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
.dr-page .dr-card-b { padding: 16px 18px; }
.dr-page .dr-row { display: flex; padding: 7px 0; border-bottom: 1px solid var(--border-soft, #f1f3f6); font-size: 14.5px; }
.dr-page .dr-row:last-child { border-bottom: none; }
.dr-page .dr-row span:first-child { width: 170px; flex-shrink: 0; color: var(--text-muted); }
.dr-page .dr-row span:last-child { font-weight: 600; color: var(--ink); }
.dr-page .dr-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 6px; font-size: 14.5px; font-weight: 500; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); text-decoration: none; cursor: pointer; }
.dr-page .dr-btn:hover { background: var(--surface-hover); color: var(--ink); }
.dr-page .dr-btn.primary { background: #14213D; color: #fff; border-color: #14213D; }
.dr-page .dr-btn.danger { color: #C94A4A; }
.dr-page .dr-btn.danger:hover { background: #FDECEA; border-color: #C94A4A; color: #B3261E; }
.dr-page td .dr-btn + .dr-btn { margin-left: 6px; }
.dr-page td .dr-btn { padding: 6px 12px; font-size: 14px; }
.dr-page table td, .dr-page table th { font-size: 14.5px; vertical-align: middle; }
</style>
<div class="dr-page" style="padding: 24px;">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="mb-0">Document Requests</h4>
            <p class="text-muted mb-0" style="font-size:14.5px;">Official enrollment documents requested by students (e.g. for 4Ps or scholarships).</p>
        </div>
        <?php if ($detail): ?><a href="document_requests.php" class="dr-btn"><i class="fas fa-arrow-left"></i> All Requests</a><?php endif; ?>
    </div>

<?php if ($detail):
    $grade = $detail['e_grade'] ?: $detail['grade_level'];
    $strand = in_array($grade, ['Grade 11', 'Grade 12'], true) ? drStrandLabel($detail['e_strand'] ?: $detail['strand']) : '';
    $actions = drStaffTransitions()[$detail['status']] ?? [];
    $csrf = generateCSRFToken();
    $dkind = drDocKind($detail['document_type']);
    $grades_available = $dkind === 'grades' ? (bool) drOfficialGrades((int) $detail['student_id'], (int) $detail['school_year_id']) : true;
?>
    <div class="dr-card">
        <div class="dr-card-h">
            <span><i class="fas fa-file-signature me-2"></i>Document Request Details</span>
            <span class="badge bg-<?php echo drStatusBadgeClass($detail['status']); ?>" style="font-size:13px;"><?php echo htmlspecialchars(strtoupper($detail['status'])); ?></span>
        </div>
        <div class="dr-card-b">
            <div class="row g-4">
                <div class="col-lg-4">
                    <h6 class="text-uppercase text-muted" style="font-size:12.5px;letter-spacing:.06em;">Request</h6>
                    <div class="dr-row"><span>Request ID</span><span>#<?php echo (int) $detail['request_id']; ?></span></div>
                    <div class="dr-row"><span>Request Date</span><span><?php echo date('M d, Y g:i A', strtotime($detail['requested_at'])); ?></span></div>
                    <div class="dr-row"><span>Document Type</span><span><?php echo htmlspecialchars($detail['document_type'] === 'Certificate of Enrollment' ? DR_DOC_COE : $detail['document_type']); ?></span></div>
                    <?php if ($dkind === 'other'): ?>
                        <div class="dr-row"><span>Specified Document</span><span><?php echo htmlspecialchars($detail['document_other'] ?? '—'); ?></span></div>
                    <?php endif; ?>
                    <div class="dr-row"><span>Purpose</span><span><?php echo htmlspecialchars($detail['purpose']); ?></span></div>
                    <?php if ($detail['purpose'] === 'Other'): ?>
                        <div class="dr-row"><span>Specified Purpose</span><span><?php echo htmlspecialchars($detail['purpose_other'] ?? '—'); ?></span></div>
                    <?php endif; ?>
                    <div class="dr-row"><span>Additional Note</span><span><?php echo $detail['note'] ? nl2br(htmlspecialchars($detail['note'])) : '<span class="text-muted">—</span>'; ?></span></div>
                </div>
                <div class="col-lg-4">
                    <h6 class="text-uppercase text-muted" style="font-size:12.5px;letter-spacing:.06em;">Student</h6>
                    <div class="dr-row"><span>Name</span><span><?php echo htmlspecialchars(drName($detail)); ?></span></div>
                    <div class="dr-row"><span>LRN</span><span><?php echo htmlspecialchars($detail['lrn']); ?></span></div>
                    <h6 class="text-uppercase text-muted mt-3" style="font-size:12.5px;letter-spacing:.06em;">Enrollment</h6>
                    <div class="dr-row"><span>School Year</span><span><?php echo htmlspecialchars($detail['sy_name'] ?? '—'); ?></span></div>
                    <div class="dr-row"><span>Grade Level</span><span><?php echo htmlspecialchars($grade); ?></span></div>
                    <?php if ($strand !== ''): ?><div class="dr-row"><span>Strand</span><span><?php echo htmlspecialchars($strand); ?></span></div><?php endif; ?>
                    <div class="dr-row"><span>Section</span><span><?php echo htmlspecialchars($detail['section_name'] ?? 'Not Assigned'); ?></span></div>
                    <div class="dr-row"><span>Enrollment Status</span><span>
                        <?php if (!empty($detail['enrollment_status'])): ?>
                            <span class="badge bg-<?php echo $detail['enrollment_status'] === 'Approved' ? 'success' : 'warning'; ?>"><?php echo htmlspecialchars($detail['enrollment_status']); ?></span>
                        <?php else: ?><span class="text-danger">Enrollment record not found</span><?php endif; ?>
                    </span></div>
                    <?php if (!empty($detail['enrollment_id'])): ?>
                        <div class="mt-2"><a href="view.php?id=<?php echo (int) $detail['enrollment_id']; ?>" class="small"><i class="fas fa-up-right-from-square me-1"></i>Open enrollment record</a></div>
                    <?php endif; ?>
                </div>
                <div class="col-lg-4">
                    <h6 class="text-uppercase text-muted" style="font-size:12.5px;letter-spacing:.06em;">Timeline</h6>
                    <div class="dr-row"><span>Requested</span><span><?php echo date('M d, Y g:i A', strtotime($detail['requested_at'])); ?></span></div>
                    <?php foreach (['processing_at' => 'Processing', 'ready_at' => 'Ready for Release', 'released_at' => 'Released', 'cancelled_at' => 'Cancelled'] as $col => $lbl): if (!empty($detail[$col])): ?>
                        <div class="dr-row"><span><?php echo $lbl; ?></span><span><?php echo date('M d, Y g:i A', strtotime($detail[$col])); ?><?php echo ($col === 'cancelled_at' && !empty($detail['cancelled_by_student'])) ? ' <small class="text-muted">(by student)</small>' : ''; ?></span></div>
                    <?php endif; endforeach; ?>
                </div>
            </div>
        </div>
        <?php if (!empty($detail['claiming_date']) && in_array($detail['status'], [DR_READY, DR_RELEASED], true)): $claim_time = drClaimTimeText($detail); ?>
        <div class="dr-card-b" style="border-top:1px solid var(--border);">
            <div class="row g-4">
                <div class="col-lg-6">
                    <h6 class="text-uppercase text-muted" style="font-size:12.5px;letter-spacing:.06em;"><i class="fas fa-calendar-check me-1"></i>Release / Claiming Details</h6>
                    <div class="dr-row"><span>Claiming Date</span><span><?php echo date('F d, Y', strtotime($detail['claiming_date'])); ?></span></div>
                    <?php if ($claim_time !== ''): ?><div class="dr-row"><span>Claiming Time</span><span><?php echo htmlspecialchars($claim_time); ?></span></div><?php endif; ?>
                    <div class="dr-row"><span>Claiming Location</span><span><?php echo htmlspecialchars($detail['claiming_location']); ?></span></div>
                    <?php if (!empty($detail['claiming_instructions'])): ?><div class="dr-row"><span>Instructions</span><span><?php echo nl2br(htmlspecialchars($detail['claiming_instructions'])); ?></span></div><?php endif; ?>
                </div>
                <?php if ($detail['status'] === DR_RELEASED): ?>
                <div class="col-lg-6">
                    <h6 class="text-uppercase text-muted" style="font-size:12.5px;letter-spacing:.06em;"><i class="fas fa-circle-check me-1"></i>Release Information</h6>
                    <div class="dr-row"><span>Status</span><span><span class="badge bg-success">RELEASED</span></span></div>
                    <div class="dr-row"><span>Released On</span><span><?php echo !empty($detail['released_at']) ? date('F d, Y \a\t g:i A', strtotime($detail['released_at'])) : '—'; ?></span></div>
                    <div class="dr-row"><span>Released By</span><span><?php echo htmlspecialchars(trim(($detail['released_first'] ?? '') . ' ' . ($detail['released_last'] ?? '')) ?: '—'); ?></span></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="dr-card-b d-flex gap-2 flex-wrap justify-content-end" style="border-top:1px solid var(--border);">
            <?php if ($dkind === 'other'): ?>
                <span class="text-muted me-auto" style="font-size:14px;"><i class="fas fa-circle-info me-1"></i>Manual document: prepare "<?php echo htmlspecialchars(drDocumentLabel($detail)); ?>" outside the system, then update the status here.</span>
            <?php elseif ($dkind === 'grades' && !$grades_available): ?>
                <span class="text-danger me-auto" style="font-size:14px;"><i class="fas fa-triangle-exclamation me-1"></i>Official/finalized grades are not yet available for this request.</span>
            <?php elseif (in_array($detail['status'], [DR_PROCESSING, DR_READY, DR_RELEASED], true)): ?>
                <a href="document_requests.php?id=<?php echo (int) $detail['request_id']; ?>&amp;certificate=1" target="_blank" rel="noopener" class="dr-btn"><i class="fas fa-print"></i> Print <?php echo htmlspecialchars(drDocumentLabel($detail)); ?></a>
            <?php endif; ?>
            <?php foreach ($actions as $to => $label): ?>
                <?php if ($to === DR_READY || $to === DR_RELEASED): ?>
                    <button type="button" class="dr-btn primary" data-bs-toggle="modal" data-bs-target="<?php echo $to === DR_READY ? '#readyModal' : '#releaseModal'; ?>"><?php echo htmlspecialchars($label); ?></button>
                    <?php continue; ?>
                <?php endif; ?>
                <form method="POST" class="d-inline" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode($label . '?'), ENT_QUOTES); ?>);">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="new_status" value="<?php echo htmlspecialchars($to); ?>">
                    <button type="submit" class="dr-btn <?php echo $to === DR_CANCELLED ? 'danger' : 'primary'; ?>"><?php echo htmlspecialchars($label); ?></button>
                </form>
            <?php endforeach; ?>
            <?php if (!$actions): ?><span class="text-muted" style="font-size:14px;">No further action — this request is <?php echo htmlspecialchars($detail['status']); ?>.</span><?php endif; ?>
        </div>
    </div>

    <?php if (isset($actions[DR_READY])): ?>
    <!-- Mark as Ready for Release: claiming details are required (checked again on the server). -->
    <div class="modal fade" id="readyModal" tabindex="-1" aria-labelledby="readyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content" id="readyForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="new_status" value="<?php echo htmlspecialchars(DR_READY); ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="readyModalLabel"><i class="fas fa-box-open me-2"></i>Mark as Ready for Release</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="dr-row"><span>Document</span><span><?php echo htmlspecialchars(drDocumentLabel($detail)); ?></span></div>
                    <div class="dr-row mb-3"><span>Student</span><span><?php echo htmlspecialchars(drName($detail)); ?></span></div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="claim_date">Claiming Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="claim_date" name="claiming_date" min="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="row g-2 mb-1">
                        <div class="col-6">
                            <label class="form-label fw-semibold" for="claim_from">Available From</label>
                            <input type="time" class="form-control" id="claim_from" name="claiming_time_from">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold" for="claim_until">Available Until</label>
                            <input type="time" class="form-control" id="claim_until" name="claiming_time_until">
                        </div>
                    </div>
                    <div class="form-text mb-3">Optional. Leave both empty if there is no set time.</div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="claim_loc">Claiming Location <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="claim_loc" name="claiming_location" maxlength="150" list="claim_loc_list" placeholder="e.g. School Office" required>
                        <datalist id="claim_loc_list">
                            <?php foreach (getRecords("SELECT DISTINCT claiming_location FROM document_requests WHERE claiming_location IS NOT NULL AND claiming_location <> '' ORDER BY claiming_location LIMIT 20") ?: [] as $loc): ?>
                                <option value="<?php echo htmlspecialchars($loc['claiming_location']); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-semibold" for="claim_ins">Claiming Instructions</label>
                        <textarea class="form-control" id="claim_ins" name="claiming_instructions" rows="3" maxlength="1000" placeholder="e.g. Please bring your school ID or other valid identification."></textarea>
                    </div>
                    <div class="alert alert-danger py-2 mt-3 mb-0 d-none" id="readyError"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i>Confirm Ready for Release</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    document.getElementById('readyForm').addEventListener('submit', function (e) {
        var d = document.getElementById('claim_date').value, f = document.getElementById('claim_from').value,
            u = document.getElementById('claim_until').value, l = document.getElementById('claim_loc').value.trim(),
            err = document.getElementById('readyError'), msg = '';
        if (!d) msg = 'Please enter the Claiming Date.';
        else if (d < <?php echo json_encode(date('Y-m-d')); ?>) msg = 'The Claiming Date cannot be in the past.';
        else if (!l) msg = 'Please enter the Claiming Location.';
        else if (u && !f) msg = 'Enter "Available From" as well, or leave both times empty.';
        else if (f && u && u <= f) msg = '"Available Until" must be later than "Available From".';
        if (msg) { e.preventDefault(); err.textContent = msg; err.classList.remove('d-none'); }
    });
    </script>
    <?php endif; ?>

    <?php if (isset($actions[DR_RELEASED])): ?>
    <!-- Mark as Released: explicit confirmation (the server also requires confirm_release). -->
    <div class="modal fade" id="releaseModal" tabindex="-1" aria-labelledby="releaseModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="new_status" value="<?php echo htmlspecialchars(DR_RELEASED); ?>">
                <input type="hidden" name="confirm_release" value="1">
                <div class="modal-header">
                    <h5 class="modal-title" id="releaseModalLabel"><i class="fas fa-hand-holding me-2"></i>Mark Document as Released</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="dr-row"><span>Student</span><span><?php echo htmlspecialchars(drName($detail)); ?></span></div>
                    <div class="dr-row"><span>Document</span><span><?php echo htmlspecialchars(drDocumentLabel($detail)); ?></span></div>
                    <div class="dr-row"><span>Scheduled Claiming Date</span><span><?php echo !empty($detail['claiming_date']) ? date('F d, Y', strtotime($detail['claiming_date'])) : '—'; ?></span></div>
                    <p class="mt-3 mb-0">Confirm that this document has been claimed/released.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i>Confirm Release</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
<?php else: ?>
    <form method="GET" class="dr-card dr-card-b d-flex gap-2 flex-wrap align-items-end">
        <div style="min-width:220px;flex:1;"><input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($f_q); ?>" placeholder="Search by LRN or name..."></div>
        <select name="status" class="form-select" style="width:auto;">
            <option value="">All Status</option>
            <?php foreach ([DR_PENDING, DR_PROCESSING, DR_READY, DR_RELEASED, DR_CANCELLED] as $st): ?><option value="<?php echo $st; ?>" <?php echo $f_status === $st ? 'selected' : ''; ?>><?php echo $st; ?></option><?php endforeach; ?>
        </select>
        <select name="grade" class="form-select" style="width:auto;">
            <option value="">All Grade Levels</option>
            <?php foreach ($grade_options as $g): ?><option value="<?php echo $g; ?>" <?php echo $f_grade === $g ? 'selected' : ''; ?>><?php echo $g; ?></option><?php endforeach; ?>
        </select>
        <select name="sy" class="form-select" style="width:auto;">
            <option value="0">All School Years</option>
            <?php foreach ($school_years as $sy): ?><option value="<?php echo (int) $sy['sy_id']; ?>" <?php echo $f_sy === (int) $sy['sy_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($sy['sy_name']); ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="dr-btn primary"><i class="fas fa-filter"></i> Filter</button>
        <a href="document_requests.php" class="dr-btn"><i class="fas fa-rotate-left"></i> Reset</a>
    </form>
    <div class="dr-card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Student</th><th>Grade / Section</th><th>Document</th><th>Purpose</th><th>Requested</th><th>Status</th><th class="text-end">Action</th></tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No document requests found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars(drName($r)); ?></strong><div class="text-muted small"><?php echo htmlspecialchars($r['lrn']); ?></div></td>
                            <td><?php echo htmlspecialchars($r['grade_level'] ?? ''); ?><?php echo !empty($r['section_name']) ? ' / ' . htmlspecialchars($r['section_name']) : ''; ?></td>
                            <td><?php echo htmlspecialchars(drDocumentLabel($r)); ?><?php echo drDocKind($r['document_type']) === 'other' ? '<div class="text-muted small">Other Document</div>' : ''; ?></td>
                            <td><?php echo htmlspecialchars(drPurposeLabel($r)); ?></td>
                            <td style="white-space:nowrap;"><?php echo date('M d, Y', strtotime($r['requested_at'])); ?></td>
                            <td><span class="badge bg-<?php echo drStatusBadgeClass($r['status']); ?>"><?php echo htmlspecialchars($r['status']); ?></span></td>
                            <td class="text-end" style="white-space:nowrap;">
                                <a href="document_requests.php?id=<?php echo (int) $r['request_id']; ?>" class="dr-btn"><i class="fas fa-eye"></i> View</a>
                                <button type="button" class="dr-btn danger js-dr-delete" data-bs-toggle="modal" data-bs-target="#deleteRequestModal"
                                        data-id="<?php echo (int) $r['request_id']; ?>"
                                        data-student="<?php echo htmlspecialchars(drName($r)); ?>"
                                        data-document="<?php echo htmlspecialchars(drDocumentLabel($r)); ?>"
                                        data-status="<?php echo htmlspecialchars($r['status']); ?>"
                                        aria-label="Delete request #<?php echo (int) $r['request_id']; ?>">
                                    <i class="fas fa-trash-can"></i> Delete
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <!-- Delete confirmation -->
    <div class="modal fade" id="deleteRequestModal" tabindex="-1" aria-labelledby="deleteRequestLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                <input type="hidden" name="action" value="delete_request">
                <input type="hidden" name="delete_id" id="del_id" value="">
                <input type="hidden" name="back" value="<?php echo htmlspecialchars(!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''); ?>">
                <div class="modal-header">
                    <h5 class="modal-title text-danger" id="deleteRequestLabel"><i class="fas fa-trash-can me-2"></i>Delete Document Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="dr-row"><span>Request ID</span><span id="del_req"></span></div>
                    <div class="dr-row"><span>Student</span><span id="del_student"></span></div>
                    <div class="dr-row"><span>Document</span><span id="del_document"></span></div>
                    <div class="dr-row"><span>Status</span><span id="del_status"></span></div>
                    <div class="alert alert-danger py-2 mt-3 mb-0" style="font-size:14px;">
                        <i class="fas fa-triangle-exclamation me-1"></i>This permanently removes the request. The student will no longer see it in My Document Requests. This cannot be undone.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash-can me-1"></i>Delete Request</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var m = document.getElementById('deleteRequestModal');
        if (!m) return;
        m.addEventListener('show.bs.modal', function (e) {
            var b = e.relatedTarget;
            if (!b) return;
            document.getElementById('del_id').value = b.getAttribute('data-id');
            document.getElementById('del_req').textContent = '#' + b.getAttribute('data-id');
            document.getElementById('del_student').textContent = b.getAttribute('data-student');
            document.getElementById('del_document').textContent = b.getAttribute('data-document');
            document.getElementById('del_status').textContent = b.getAttribute('data-status');
        });
    });
    </script>
<?php endif; ?>
</div>
<?php include '../../includes/footer.php'; ?>