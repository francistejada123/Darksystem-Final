<?php
// modules/students/edit.php
// Edit student details with strand support - UPDATE SECTION COUNT

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student_records_helpers.php'; // portal access + correction requests
srEnsureSchema();

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Registrar'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Edit Student';
$current_page = 'students';
$base_url = BASE_URL;

$student_id = intval($_GET['id'] ?? 0);
$is_embedded = ($_GET['embed'] ?? '') === '1';
if ($student_id <= 0) {
    header('Location: index.php');
    exit;
}

// Get student data
$student = getRecord("SELECT * FROM students WHERE student_id = ? AND is_active = 1", [$student_id]);
if (!$student) {
    header('Location: index.php');
    exit;
}

$errors = [];
$success = false;

// Get sections with strand info
$sections = getRecords("SELECT section_id, section_name, grade_level, strand, is_senior_high FROM sections WHERE is_active = 1 ORDER BY grade_level, section_name");

$edit_back = 'edit.php?id=' . $student_id . ($is_embedded ? '&embed=1' : '');
// A correction request for view-only personal / guardian information.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_correction') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token.';
    } else {
        $r = srRequestCorrection($student_id, (string) ($_POST['field_name'] ?? ''), (string) ($_POST['new_value'] ?? ''),
            (string) ($_POST['reason'] ?? ''), (string) ($_SESSION['role'] ?? 'Staff'), (int) $_SESSION['user_id']);
        if ($r['success']) {
            $_SESSION['success_message'] = 'Correction request sent to the Registrar for approval.';
        } else {
            $_SESSION['error_message'] = $r['error'];
        }
    }
    header('Location: ' . $edit_back . '#corrections');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        // Personal and guardian information are view-only here (they come from
        // the student's enrollment): always the saved values, never the form.
        $lrn = $student['lrn'];
        $first_name = $student['first_name'];
        $last_name = $student['last_name'];
        $middle_name = $student['middle_name'] ?? '';
        $birth_date = $student['birth_date'];
        $gender = $student['gender'];
        $contact_number = $student['contact_number'] ?? '';
        $email = $student['email'] ?? '';
        $address = $student['address'] ?? '';
        $guardian_name = $student['guardian_name'] ?? '';
        $guardian_contact = $student['guardian_contact'] ?? '';
        $grade_level = sanitize($_POST['grade_level'] ?? '');
        $strand = sanitize($_POST['strand'] ?? '');
        $section_id = !empty($_POST['section_id']) ? intval($_POST['section_id']) : null;
        $enrollment_status = sanitize($_POST['enrollment_status'] ?? '');
        $portal_access = !empty($_POST['portal_access']) ? 1 : 0;
        
        // Validation (only the editable Academic Information)
        if (empty($grade_level)) $errors[] = 'Grade level is required.';
        
        // If Grade 11 or 12, strand is required
        if (($grade_level === 'Grade 11' || $grade_level === 'Grade 12') && empty($strand)) {
            $errors[] = 'Strand is required for Grade 11 and 12 students.';
        }
        
        
        // If no errors, update
        if (empty($errors)) {
            // Get the old section_id before update
            $old_section_id = $student['section_id'];
            $old_enrollment_status = $student['enrollment_status'];
            
            // Only Academic Information is saved; personal and guardian
            // information are not touched.
            $sql = "UPDATE students SET 
                    grade_level = ?, strand = ?, section_id = ?, enrollment_status = ?, portal_access = ?
                    WHERE student_id = ?";
            
            $params = [$grade_level, $strand, $section_id, $enrollment_status, $portal_access, $student_id];
            
            $result = executeQuery($sql, $params);
            
            if ($result) {
                // ✅ UPDATE SECTION ENROLLMENT COUNTS - FIX SECTION CHANGE
                
                // If section changed and student was enrolled
                if ($old_section_id != $section_id && $enrollment_status == 'Enrolled') {
                    // Decrement old section count
                    if ($old_section_id) {
                        $old_section = getRecord("SELECT current_enrollment FROM sections WHERE section_id = ?", [$old_section_id]);
                        if ($old_section && $old_section['current_enrollment'] > 0) {
                            executeQuery("UPDATE sections SET current_enrollment = current_enrollment - 1 WHERE section_id = ?", [$old_section_id]);
                        }
                    }
                    
                    // Increment new section count
                    if ($section_id) {
                        executeQuery("UPDATE sections SET current_enrollment = current_enrollment + 1 WHERE section_id = ?", [$section_id]);
                    }
                }
                
                logActivity($_SESSION['user_id'], 'Updated student', 'students', "Updated student: $last_name, $first_name (ID: $student_id)");
                if ((int) ($student['portal_access'] ?? 0) !== $portal_access) {
                    logActivity($_SESSION['user_id'], 'students', $portal_access ? 'Portal access granted' : 'Portal access removed',
                        "Student Portal without Online Enrollment for $last_name, $first_name (ID: $student_id)");
                }
                $_SESSION['success_message'] = 'Student updated successfully!';
                header('Location: index.php?success=1');
                exit;
            } else {
                $errors[] = 'Failed to update student. Please try again.';
            }
        }
    }
}

$csrf_token = generateCSRFToken();
if ($is_embedded) {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Edit Student</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
        <link href="<?php echo BASE_URL; ?>static/css/style.css?v=<?php echo @filemtime(__DIR__ . '/../../static/css/style.css') ?: time(); ?>" rel="stylesheet">
        <style>
            html, body { background:#f3f6fa; overflow-x:hidden; }
            body.student-edit-embedded { min-width:0; padding:0; }
            body.student-edit-embedded .container-fluid { padding:0; }
            body.student-edit-embedded .edit-student-shell { padding:16px 18px 18px; }
            body.student-edit-embedded .edit-student-heading { margin:0 2px 14px; padding-bottom:12px; border-bottom:1px solid #e1e8f2; }
            body.student-edit-embedded .edit-student-heading h2 { margin:0; font-size:21px; letter-spacing:-.02em; color:#14213d; }
            body.student-edit-embedded .edit-student-heading a { display:none; }
            body.student-edit-embedded .card { border:0; box-shadow:0 5px 16px rgba(35,75,125,.07) !important; }
            body.student-edit-embedded .card-body { padding:16px 18px 14px; }
            body.student-edit-embedded form { padding-bottom:72px; }
            body.student-edit-embedded form h5 { display:flex; align-items:center; gap:6px; margin:0 0 10px !important; padding-top:4px; font-size:15px; }
            body.student-edit-embedded form h5:not(:first-child) { margin-top:16px !important; }
            body.student-edit-embedded form .row { --bs-gutter-x:12px; --bs-gutter-y:9px; margin-bottom:12px !important; }
            body.student-edit-embedded .form-label { margin-bottom:4px; color:#405875; font-size:12.5px; font-weight:700; }
            body.student-edit-embedded .form-control,
            body.student-edit-embedded .form-select { min-height:36px; padding:7px 10px; border-color:#d6dfeb; border-radius:8px; font-size:13.5px; }
            body.student-edit-embedded textarea.form-control { min-height:58px; }
            body.student-edit-embedded .edit-form-actions { position:fixed; left:0; right:0; bottom:0; z-index:10; width:auto; margin:0; padding:12px 18px 14px; border-top:1px solid #e1e8f2; background:rgba(255,255,255,.98); box-shadow:0 -5px 14px rgba(35,75,125,.08); }
            body.student-edit-embedded .edit-form-actions .btn { padding:8px 15px; border-radius:8px; font-size:13.5px; }
            body.student-edit-embedded .alert { margin-bottom:12px; padding:10px 12px; font-size:13.5px; }
            @media (max-width:700px) {
                body.student-edit-embedded .edit-student-shell { padding:12px; }
                body.student-edit-embedded .card-body { padding:14px 12px; }
            }
        </style>
    </head>
    <body class="student-edit-embedded">
    <?php
} else {
    include '../../includes/header.php';
}
?>

<!-- Rest of the HTML remains the same -->
<div class="edit-student-shell">
<div class="row mt-0">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4 edit-student-heading">
            <h2><i class="fas fa-user-edit text-primary me-2"></i>Edit Student</h2>
            <?php if (!$is_embedded): ?>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to List
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Display errors -->
<?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i>
        <strong>Please fix the following errors:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $error): ?>
                <li><?php echo $error; ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Edit Student Form -->
<div class="card shadow-sm rounded-3">
    <div class="card-body">
        <style>
            /* Personal & Guardian Information: view only */
            .form-control.is-readonly, .form-select.is-readonly { background: #f1f4f8; color: #334155; border-color: #e2e8f0; cursor: default; box-shadow: none; }
            .form-control.is-readonly:focus { box-shadow: none; border-color: #e2e8f0; }
            .form-select.is-readonly:disabled { opacity: 1; }
            html[data-theme="dark"] .form-control.is-readonly, html[data-theme="dark"] .form-select.is-readonly { background: #16223A; color: #DCE3EF; border-color: #263352; }
            .view-only-tag { font-size: 12.5px; font-weight: 700; color: #64748b; background: #eef2f7; border-radius: 99px; padding: 2px 8px; margin-left: 6px; }
            .view-only-note { font-size: 13.5px; color: #64748b; margin: -4px 0 12px; }
        </style>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <!-- Personal Information -->
            <h5 class="mb-3 text-primary"><i class="fas fa-user me-2"></i>Personal Information <span class="view-only-tag"><i class="fas fa-eye me-1"></i>View only</span></h5>
            <p class="view-only-note">Personal and guardian information come from the student's enrollment and can only be viewed here. Only the Academic Information below can be changed. To fix a mistake, use <a href="#corrections">Request a correction</a> below &mdash; the Registrar approves it.</p>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label">LRN</label>
                    <input type="text" name="lrn" class="form-control is-readonly" readonly tabindex="-1" value="<?php echo htmlspecialchars($student['lrn']); ?>" maxlength="12">
                </div>
                <div class="col-md-3">
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" class="form-control is-readonly" readonly tabindex="-1" value="<?php echo htmlspecialchars($student['first_name']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Middle Name</label>
                    <input type="text" name="middle_name" class="form-control is-readonly" readonly tabindex="-1" value="<?php echo htmlspecialchars($student['middle_name'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" class="form-control is-readonly" readonly tabindex="-1" value="<?php echo htmlspecialchars($student['last_name']); ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Birth Date</label>
                    <input type="date" name="birth_date" class="form-control is-readonly" readonly tabindex="-1" value="<?php echo htmlspecialchars($student['birth_date']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-select is-readonly" disabled>
                        <option value="">Select Gender</option>
                        <option value="Male" <?php echo $student['gender'] == 'Male' ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo $student['gender'] == 'Female' ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Contact Number</label>
                    <input type="text" name="contact_number" class="form-control is-readonly" readonly tabindex="-1" value="<?php echo htmlspecialchars($student['contact_number'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control is-readonly" readonly tabindex="-1" value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>">
                </div>
                
                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control is-readonly" readonly tabindex="-1" rows="2"><?php echo htmlspecialchars($student['address'] ?? ''); ?></textarea>
                </div>
            </div>
            
            <!-- Guardian Information -->
            <h5 class="mb-3 text-primary"><i class="fas fa-user-friends me-2"></i>Guardian Information <span class="view-only-tag"><i class="fas fa-eye me-1"></i>View only</span></h5>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">Guardian Name</label>
                    <input type="text" name="guardian_name" class="form-control is-readonly" readonly tabindex="-1" value="<?php echo htmlspecialchars($student['guardian_name'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Guardian Contact</label>
                    <input type="text" name="guardian_contact" class="form-control is-readonly" readonly tabindex="-1" value="<?php echo htmlspecialchars($student['guardian_contact'] ?? ''); ?>">
                </div>
            </div>
            
            <!-- Academic Information -->
            <h5 class="mb-3 text-primary"><i class="fas fa-graduation-cap me-2"></i>Academic Information</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label">Grade Level</label>
                    <select name="grade_level" class="form-select" required id="gradeLevelSelect">
                        <option value="">Select Grade Level</option>
                        <?php
                        $grades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
                        foreach ($grades as $grade):
                        ?>
                            <option value="<?php echo $grade; ?>" <?php echo $student['grade_level'] == $grade ? 'selected' : ''; ?>>
                                <?php echo $grade; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3" id="strandDiv" style="display: <?php echo ($student['grade_level'] === 'Grade 11' || $student['grade_level'] === 'Grade 12') ? 'block' : 'none'; ?>;">
                    <label class="form-label">Strand (for SHS)</label>
                    <select name="strand" class="form-select">
                        <option value="">Select Strand</option>
                        <option value="GAS" <?php echo ($student['strand'] ?? '') == 'GAS' ? 'selected' : ''; ?>>GAS - General Academic Strand</option>
                        <option value="ASSH" <?php echo ($student['strand'] ?? '') == 'ASSH' ? 'selected' : ''; ?>>ASSH Cluster (Arts, Social Sciences, and Humanities)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Section</label>
                    <select name="section_id" class="form-select">
                        <option value="">Select Section</option>
                        <?php foreach ($sections as $sec): ?>
                            <option value="<?php echo $sec['section_id']; ?>" <?php echo $student['section_id'] == $sec['section_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sec['section_name']); ?>
                                <?php if ($sec['is_senior_high']): ?>
                                    (<?php echo htmlspecialchars($sec['strand'] === 'ASSH' ? 'ASSH Cluster' : $sec['strand']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Enrollment Status</label>
                    <select name="enrollment_status" class="form-select">
                        <option value="Pending" <?php echo ($student['enrollment_status'] ?? '') == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Enrolled" <?php echo ($student['enrollment_status'] ?? '') == 'Enrolled' ? 'selected' : ''; ?>>Enrolled</option>
                        <option value="Transferred" <?php echo ($student['enrollment_status'] ?? '') == 'Transferred' ? 'selected' : ''; ?>>Transferred</option>
                        <option value="Graduated" <?php echo ($student['enrollment_status'] ?? '') == 'Graduated' ? 'selected' : ''; ?>>Graduated</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-check" style="display:flex;gap:8px;align-items:flex-start;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:10px 12px;">
                        <input type="checkbox" class="form-check-input mt-1" name="portal_access" value="1" <?php echo !empty($student['portal_access']) ? 'checked' : ''; ?>>
                        <span><strong>Allow Student Portal login without Online Enrollment</strong><br>
                            <small class="text-muted">For walk-in or older records with no approved online application. The student signs in with their LRN (<?php echo htmlspecialchars($student['lrn']); ?>).</small></span>
                    </label>
                </div>
            </div>
            
            <div class="edit-form-actions d-flex justify-content-end gap-2">
                <?php if (!$is_embedded): ?>
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Update Student
                </button>
            </div>
        </form>

        <!-- Correction requests: personal / guardian information changes only after Registrar approval -->
        <?php
            $sr_fields = srCorrectionFields();
            $sr_requests = srStudentRequests($student_id);
            $sr_flash_ok = $_SESSION['success_message'] ?? null;
            $sr_flash_err = $_SESSION['error_message'] ?? null;
            if ($is_embedded) { unset($_SESSION['success_message'], $_SESSION['error_message']); }
        ?>
        <div id="corrections" class="mt-4 pt-3" style="border-top:1px solid #E2E8F0;">
            <h5 class="mb-2 text-primary"><i class="fas fa-pen-to-square me-2"></i>Request a correction</h5>
            <p class="view-only-note">For a wrong name, birth date, gender, contact or guardian detail. The Registrar reviews the request; the record changes only when it is approved.</p>
            <?php if ($is_embedded && $sr_flash_ok): ?><div class="alert alert-success py-2" style="font-size:14.5px;"><?php echo htmlspecialchars($sr_flash_ok); ?></div><?php endif; ?>
            <?php if ($is_embedded && $sr_flash_err): ?><div class="alert alert-danger py-2" style="font-size:14.5px;"><?php echo htmlspecialchars($sr_flash_err); ?></div><?php endif; ?>
            <form method="POST" action="" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="request_correction">
                <div class="col-md-3">
                    <label class="form-label small mb-1">Information</label>
                    <select name="field_name" class="form-select form-select-sm" required>
                        <option value="">Choose...</option>
                        <?php foreach ($sr_fields as $fk => $fl): ?>
                            <option value="<?php echo $fk; ?>"><?php echo htmlspecialchars($fl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Correct value</label>
                    <input type="text" name="new_value" class="form-control form-control-sm" maxlength="255" placeholder="e.g. Maria / 2013-05-14 / Female" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1">Reason / basis</label>
                    <input type="text" name="reason" class="form-control form-control-sm" maxlength="500" placeholder="e.g. Per PSA birth certificate" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="fas fa-paper-plane me-1"></i>Send request</button>
                </div>
            </form>
            <?php if (!empty($sr_requests)): ?>
                <div class="table-responsive mt-3">
                    <table class="table table-sm" style="font-size:14px;">
                        <thead><tr><th>Information</th><th>From</th><th>To</th><th>Reason</th><th>By</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($sr_requests as $rq): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($sr_fields[$rq['field_name']] ?? $rq['field_name']); ?></td>
                                <td><?php echo htmlspecialchars((string) $rq['old_value']); ?></td>
                                <td><strong><?php echo htmlspecialchars($rq['new_value']); ?></strong></td>
                                <td><?php echo htmlspecialchars($rq['reason']); ?></td>
                                <td><?php echo htmlspecialchars($rq['requested_by_role']); ?> · <?php echo date('M j, Y', strtotime($rq['created_at'])); ?></td>
                                <td><span class="badge <?php echo $rq['status'] === 'Approved' ? 'bg-success' : ($rq['status'] === 'Rejected' ? 'bg-danger' : 'bg-warning text-dark'); ?>"><?php echo $rq['status']; ?></span>
                                    <?php if (!empty($rq['review_note'])): ?><br><small class="text-muted"><?php echo htmlspecialchars($rq['review_note']); ?></small><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if (hasAnyRole(['Registrar', 'Administrator'])): ?>
                <a href="<?php echo BASE_URL; ?>modules/students/corrections.php" class="small" target="_top"><i class="fas fa-list-check me-1"></i>Review all correction requests</a>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
// Show/hide strand based on grade level
document.getElementById('gradeLevelSelect').addEventListener('change', function() {
    var strandDiv = document.getElementById('strandDiv');
    if (this.value === 'Grade 11' || this.value === 'Grade 12') {
        strandDiv.style.display = 'block';
    } else {
        strandDiv.style.display = 'none';
    }
});
</script>

</div>
<?php if ($is_embedded): ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
<?php else: ?>
    <?php include '../../includes/footer.php'; ?>
<?php endif; ?>