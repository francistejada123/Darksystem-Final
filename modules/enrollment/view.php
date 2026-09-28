<?php
// modules/enrollment/view.php
// View enrollment details

require_once __DIR__ . '/../../config/database.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/eo_scope.php';   // Enrollment Officer: own grade level only
require_once __DIR__ . '/../../includes/attendance_qr_helpers.php'; // attQrLrnLink()

if (!hasAnyRole(['Administrator', 'Registrar', 'Enrollment Officer'])) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'Enrollment Details';
$current_page = 'enrollment';
$base_url = '../../';

$enrollment_id = intval($_GET['id'] ?? 0);
if ($enrollment_id <= 0) {
    header('Location: index.php');
    exit;
}

// Get enrollment details (with the end-of-year status, if the learner already has one --
// see Section 17c in includes/grading_helpers.php)
require_once __DIR__ . '/../../includes/grading_helpers.php';
gh_ensureStudentYearStatusTable();
$enrollment = getRecord("
    SELECT e.*, s.lrn, s.first_name, s.last_name, s.middle_name, s.birth_date, s.gender,
           s.contact_number, s.email, s.address, s.guardian_name, s.guardian_contact,
           sec.section_name, sy.sy_name,
           ys.status AS year_status, ys.general_average AS year_average,
           u.first_name as processed_first, u.last_name as processed_last
    FROM enrollments e 
    JOIN students s ON e.student_id = s.student_id 
    LEFT JOIN sections sec ON e.section_id = sec.section_id 
    LEFT JOIN school_years sy ON e.school_year_id = sy.sy_id 
    LEFT JOIN users u ON e.processed_by = u.user_id 
    LEFT JOIN student_year_status ys ON ys.student_id = e.student_id AND ys.school_year_id = e.school_year_id
                                    AND ys.grade_level = e.grade_level AND ys.status = 'Completed / Promoted'
    WHERE e.enrollment_id = ?", 
    [$enrollment_id]
);

if (!$enrollment) {
    header('Location: index.php');
    exit;
}
eoGuard($enrollment['grade_level']);

// Requirements the learner uploaded through Online Enrollment (if this learner came in that way).
// The application is linked to the student record on approval; older/edge cases fall back to the LRN.
require_once __DIR__ . '/../../includes/enrollment_helpers_online.php';
enEnsureIncompleteStatus(); // walk-in: Rejected -> Incomplete Requirements (once)
$online_application = getRecord(
    "SELECT * FROM online_applications WHERE student_id = ? ORDER BY application_id DESC LIMIT 1",
    [$enrollment['student_id']]
);
if (!$online_application && !empty($enrollment['lrn'])) {
    $online_application = getRecord(
        "SELECT * FROM online_applications WHERE lrn = ? ORDER BY application_id DESC LIMIT 1",
        [$enrollment['lrn']]
    );
}
$submitted_docs = [];
if ($online_application) {
    $app_documents = getApplicationDocuments((int) $online_application['application_id']);
    $doc_by_key = [];
    foreach ($app_documents as $d) {
        $doc_by_key[$d['doc_key']] = $d;
    }
    // Same checklist the Online Application Review page uses.
    $submitted_docs = getRequiredDocuments($online_application['grade_level'], $online_application['application_type'])
        ?: getRequiredDocuments($online_application['grade_level']);
    $listed_doc_keys = array_column($submitted_docs, 'key');
    foreach ($app_documents as $d) {
        if (!in_array($d['doc_key'], $listed_doc_keys, true)) {
            $submitted_docs[] = ['key' => $d['doc_key'], 'label' => $d['doc_label'], 'hint' => '', 'required' => false];
            $listed_doc_keys[] = $d['doc_key'];
        }
    }
}

include '../../includes/header.php';

$status_class = [
    'Pending' => 'pending',
    'Approved' => 'approved',
    'Rejected' => 'incomplete',
    'Incomplete Requirements' => 'incomplete',
    'Transferred' => 'transferred'
];
$st = $enrollment['enrollment_status'];
$cls = $status_class[$st] ?? 'pending';
?>

<style>
.enroll-view { background: var(--surface-hover); }
.enroll-view .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.enroll-view .page-header h4 { font-size: 20px; font-weight: 700; color: var(--ink); margin: 0; }
.enroll-view .page-header-actions { display: flex; gap: 8px; align-items: center; }
.enroll-view .btn-back { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 6px; font-size: 14.5px; font-weight: 500; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); text-decoration: none; transition: all 0.15s; }
.enroll-view .btn-back:hover { background: var(--surface-hover); color: var(--ink); }
.enroll-view .btn-approve { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; font-size: 14.5px; font-weight: 500; color: #fff; background: #238863; border: none; text-decoration: none; transition: all 0.15s; }
.enroll-view .btn-approve:hover { filter: brightness(0.9); }
.enroll-view .btn-reject { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; font-size: 14.5px; font-weight: 500; color: #C94A4A; background: var(--surface); border: 1px solid var(--border); text-decoration: none; transition: all 0.15s; }
.enroll-view .btn-reject:hover { background: #fdecea; border-color: #C94A4A; }
.enroll-view .info-card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.enroll-view .info-card-header { padding: 16px 20px; border-bottom: 1px solid var(--border-soft); display: flex; align-items: center; gap: 8px; }
.enroll-view .info-card-header h5 { font-size: 15px; font-weight: 600; color: var(--ink); margin: 0; }
.enroll-view .info-card-header i { color: var(--ink); font-size: 15px; }
.enroll-view .info-card-body { padding: 16px 20px; }
.enroll-view .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.enroll-view .detail-item { display: flex; flex-direction: column; gap: 2px; }
.enroll-view .detail-label { font-size: 12.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); }
.enroll-view .detail-value { font-size: 15px; color: var(--ink); }
.enroll-view .status-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12.5px; font-weight: 600; }
.enroll-view .status-pending { background: #FFF7E6; color: #B7791F; }
.enroll-view .status-approved { background: #EAF7F1; color: #238863; }
.enroll-view .status-rejected { background: #FDEEEE; color: #C94A4A; }
.enroll-view .status-incomplete { background: #FFF4E5; color: #B45309; }
.enroll-view .btn-incomplete { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; font-size: 14.5px; font-weight: 600; color: #B45309; background: var(--surface); border: 1px solid #F5C77E; text-decoration: none; transition: all 0.15s; }
.enroll-view .btn-incomplete:hover { background: #FFF4E5; border-color: #B45309; color: #92400E; }
.enroll-view .status-transferred { background: #cff4fc; color: #055160; }
.enroll-view .assign-card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; border-left: 3px solid #14213D; }
.enroll-view .assign-card .info-card-header { border-bottom: 1px solid var(--border-soft); }
.enroll-view .assign-form { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
.enroll-view .assign-form .form-group { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 200px; }
.enroll-view .assign-form label { font-size: 13.5px; font-weight: 600; color: var(--text-muted); }
.enroll-view .assign-form select { border: 1px solid var(--border); border-radius: 6px; padding: 8px 12px; font-size: 14.5px; color: var(--ink); background: var(--surface); }
.enroll-view .assign-form select:focus { outline: none; border-color: var(--ink); box-shadow: 0 0 0 2px rgba(20,33,61,0.08); }
.enroll-view .btn-save { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; font-size: 14.5px; font-weight: 500; color: #fff; background: #14213D; border: none; cursor: pointer; text-decoration: none; transition: all 0.15s; }
.enroll-view .btn-save:hover { filter: brightness(0.9); }
.enroll-view .doc-list { padding: 4px 20px 8px; }
.enroll-view .doc-item { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border-soft); }
.enroll-view .doc-item:last-child { border-bottom: none; }
.enroll-view .doc-item .doc-name { font-size: 14.5px; font-weight: 500; color: var(--ink); }
.enroll-view .doc-item .doc-required { font-size: 12.5px; color: #C94A4A; }
.enroll-view .doc-item .doc-optional { font-size: 12.5px; color: var(--text-muted); }
.enroll-view .btn-doc { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 5px; font-size: 13.5px; font-weight: 500; color: var(--ink); background: var(--surface-hover); border: 1px solid var(--border); text-decoration: none; transition: all 0.15s; white-space: nowrap; }
.enroll-view .btn-doc:hover { background: #14213D; color: #fff; border-color: var(--ink); }
.enroll-view .doc-not-submitted { font-size: 12.5px; color: var(--text-muted); background: var(--surface-hover); padding: 3px 8px; border-radius: 4px; border: 1px solid var(--border); white-space: nowrap; }
.enroll-view .doc-meta { font-size: 13.5px; color: var(--text-muted); padding: 12px 20px 0; }
.enroll-view .doc-meta a { color: var(--ink); font-weight: 600; text-decoration: underline; }
.enroll-view .doc-empty { padding: 16px 20px; font-size: 14.5px; color: var(--text-muted); }
.enroll-view .section-label { font-size: 12.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); margin-bottom: 12px; }
@media (max-width: 768px) {
    .enroll-view .detail-grid { grid-template-columns: 1fr; }
    .enroll-view .page-header { flex-direction: column; gap: 12px; align-items: flex-start; }
    .enroll-view .page-header-actions { width: 100%; }
    .enroll-view .assign-form { flex-direction: column; }
}
</style>

<div class="enroll-view" style="padding: 24px;">

    <div class="page-header">
        <div>
            <h4>Enrollment Details</h4>
        </div>
        <div class="page-header-actions">
            <?php if (in_array($enrollment['enrollment_status'], ['Pending', 'Incomplete Requirements'], true)): ?>
                <a href="approve.php?id=<?php echo $enrollment['enrollment_id']; ?>" class="btn-approve" onclick="return appConfirm(this, 'Approve this enrollment?')">
                    <i class="fas fa-check"></i> Approve
                </a>
                <?php if ($enrollment['enrollment_status'] === 'Pending'): ?>
                <a href="reject.php?id=<?php echo $enrollment['enrollment_id']; ?>" class="btn-incomplete" onclick="return appConfirm(this, 'Mark this enrollment as Incomplete Requirements? The student will be emailed what is missing.', {okText: 'Incomplete Requirements'})">
                    <i class="fas fa-file-circle-exclamation"></i> Incomplete Requirements
                </a>
                <?php endif; ?>
            <?php endif; ?>
            <a href="index.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <div class="detail-grid" style="grid-template-columns: 1fr 1fr; margin-bottom: 24px;">
        <!-- Student Information -->
        <div class="info-card">
            <div class="info-card-header">
                <i class="fas fa-user"></i>
                <h5>Student Information</h5>
            </div>
            <div class="info-card-body">
                <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="detail-item">
                        <span class="detail-label">LRN</span>
                        <span class="detail-value"><?php echo attQrLrnLink($enrollment['lrn']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Full Name</span>
                        <span class="detail-value"><?php echo htmlspecialchars($enrollment['last_name'] . ', ' . $enrollment['first_name'] . ' ' . ($enrollment['middle_name'] ?? '')); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Birth Date</span>
                        <span class="detail-value"><?php echo date('F d, Y', strtotime($enrollment['birth_date'])); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Gender</span>
                        <span class="detail-value"><?php echo htmlspecialchars($enrollment['gender']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Contact</span>
                        <span class="detail-value"><?php echo htmlspecialchars($enrollment['contact_number'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Email</span>
                        <span class="detail-value"><?php echo htmlspecialchars($enrollment['email'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="detail-item" style="grid-column: span 2;">
                        <span class="detail-label">Address</span>
                        <span class="detail-value"><?php echo nl2br(htmlspecialchars($enrollment['address'] ?? 'N/A')); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Guardian</span>
                        <span class="detail-value"><?php echo htmlspecialchars($enrollment['guardian_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Guardian Contact</span>
                        <span class="detail-value"><?php echo htmlspecialchars($enrollment['guardian_contact'] ?? 'N/A'); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enrollment Information -->
        <div class="info-card">
            <div class="info-card-header">
                <i class="fas fa-graduation-cap"></i>
                <h5>Enrollment Information</h5>
            </div>
            <div class="info-card-body">
                <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="detail-item">
                        <span class="detail-label">Grade Level</span>
                        <span class="detail-value"><?php echo htmlspecialchars($enrollment['grade_level']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Section</span>
                        <span class="detail-value"><?php echo htmlspecialchars($enrollment['section_name'] ?? 'Not Assigned'); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">School Year</span>
                        <span class="detail-value"><?php echo htmlspecialchars($enrollment['sy_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status</span>
                        <span class="detail-value">
                            <?php if ($st === 'Approved' && ($enrollment['year_status'] ?? '') === gh_yearStatusPromotedLabel()): ?>
                                <span class="status-badge status-approved"><i class="fas fa-graduation-cap me-1"></i><?php echo htmlspecialchars(gh_yearStatusPromotedLabel()); ?></span>
                                <?php if ($enrollment['year_average'] !== null): ?><small class="text-muted ms-1">General Average <?php echo number_format((float)$enrollment['year_average'], 2); ?></small><?php endif; ?>
                            <?php else: ?>
                                <span class="status-badge status-<?php echo $cls; ?>"><?php echo $st; ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Enrollment Date</span>
                        <span class="detail-value"><?php echo date('F d, Y h:i A', strtotime($enrollment['enrollment_date'])); ?></span>
                    </div>
                    <?php if ($enrollment['processed_by']): ?>
                        <div class="detail-item">
                            <span class="detail-label">Processed By</span>
                            <span class="detail-value"><?php echo htmlspecialchars($enrollment['processed_first'] . ' ' . $enrollment['processed_last']); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($enrollment['remarks']): ?>
                        <div class="detail-item" style="grid-column: span 2;">
                            <span class="detail-label">Remarks</span>
                            <span class="detail-value"><?php echo nl2br(htmlspecialchars($enrollment['remarks'])); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Submitted Requirements (uploaded through Online Enrollment) -->
    <div class="info-card" style="margin-bottom: 24px;">
        <div class="info-card-header">
            <i class="fas fa-folder-open"></i>
            <h5>Submitted Requirements</h5>
        </div>
        <?php if ($online_application): ?>
            <div class="doc-meta">
                From online application
                <a href="view_application.php?id=<?php echo (int) $online_application['application_id']; ?>"><?php echo htmlspecialchars($online_application['reference_no']); ?></a>
                &middot; <?php echo htmlspecialchars($online_application['grade_level']); ?>
                (<?php echo htmlspecialchars($online_application['application_type']); ?>)
            </div>
            <div class="doc-list">
                <?php foreach ($submitted_docs as $doc):
                    $d = $doc_by_key[$doc['key']] ?? null; ?>
                    <div class="doc-item">
                        <div>
                            <div class="doc-name"><?php echo htmlspecialchars($doc['label']); ?></div>
                            <span class="<?php echo $doc['required'] ? 'doc-required' : 'doc-optional'; ?>"><?php echo $doc['required'] ? 'Required' : 'Optional'; ?></span>
                        </div>
                        <?php if ($d): ?>
                            <a href="<?php echo BASE_URL . htmlspecialchars($d['file_path']); ?>" target="_blank" class="btn-doc">
                                <i class="fas fa-file-arrow-down"></i> View
                            </a>
                        <?php else: ?>
                            <span class="doc-not-submitted">Not submitted</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="doc-empty">No online application on file for this learner (walk-in enrollment), so there are no uploaded requirements to show.</div>
        <?php endif; ?>
    </div>

    <?php if ($enrollment['enrollment_status'] === 'Approved' && empty($enrollment['section_id'])):
        $available_sections = getRecords(
            "SELECT section_id, section_name FROM sections WHERE grade_level = ? AND academic_year = (SELECT sy_name FROM school_years WHERE sy_id = ?) ORDER BY section_name",
            [$enrollment['grade_level'], $enrollment['school_year_id']]
        );
    ?>
        <div class="assign-card">
            <div class="info-card-header">
                <i class="fas fa-layer-group"></i>
                <h5>Assign Section</h5>
            </div>
            <div class="info-card-body">
                <form method="POST" action="assign_section.php" class="assign-form">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="enrollment_id" value="<?php echo $enrollment['enrollment_id']; ?>">
                    <div class="form-group">
                        <label>Available Sections for <?php echo htmlspecialchars($enrollment['grade_level']); ?></label>
                        <select name="section_id" required>
                            <option value="">-- Select Section --</option>
                            <?php foreach ($available_sections as $sec): ?>
                                <option value="<?php echo $sec['section_id']; ?>"><?php echo htmlspecialchars($sec['section_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-save" onclick="return appConfirm(this, 'Assign this section to the student?')">
                        <i class="fas fa-check"></i> Assign Section
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php include '../../includes/footer.php'; ?>
