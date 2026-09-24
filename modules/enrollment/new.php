<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/enrollment_helpers_online.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Registrar', 'Enrollment Officer'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'New Enrollment';
$current_page = 'enrollment';
$base_url = BASE_URL;

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
$grade_levels = getOnlineGradeLevels();
$valid_strands = ['GAS', 'ASSH'];
$errors = [];
$form = [
    'lrn' => '', 'last_name' => '', 'first_name' => '', 'middle_name' => '',
    'birth_date' => '', 'gender' => '', 'contact_number' => '', 'email' => '',
    'address' => '', 'guardian_name' => '', 'guardian_contact' => '',
    'grade_level' => '', 'strand' => '', 'general_average' => '',
];

/**
 * Normalise a Philippine mobile number to the 11-digit 09XXXXXXXXX form.
 * Accepts 09XXXXXXXXX, +639XXXXXXXXX and 639XXXXXXXXX, plus any spaces or
 * dashes the encoder typed. Returns '' when it is not a valid mobile number.
 */
function normalizePhMobile($raw) {
    $digits = preg_replace('/[^0-9]/', '', (string)$raw);
    if (strpos($digits, '63') === 0 && strlen($digits) === 12) {
        $digits = '0' . substr($digits, 2);
    }
    return preg_match('/^09[0-9]{9}$/', $digits) ? $digits : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid security token. Please refresh the page and try again.');
    }

    foreach ($form as $key => $default) {
        $form[$key] = trim(sanitize($_POST[$key] ?? ''));
    }
    $form['lrn'] = preg_replace('/[^0-9]/', '', $form['lrn']);

    if (!$active_sy) {
        $errors[] = 'No active school year is set. Please set one in Settings first.';
    }
    if ($form['last_name'] === '') $errors[] = 'Last name is required.';
    if ($form['first_name'] === '') $errors[] = 'First name is required.';

    // Birth date must be a real past date. A future birth date is always a
    // typo and would follow the learner through every report.
    if ($form['birth_date'] === '' || strtotime($form['birth_date']) === false) {
        $errors[] = 'A valid birth date is required.';
    } elseif (strtotime($form['birth_date']) > time()) {
        $errors[] = 'Birth date cannot be in the future.';
    }

    if (!in_array($form['gender'], ['Male', 'Female'], true)) $errors[] = 'Please select a gender.';
    if (!in_array($form['grade_level'], $grade_levels, true)) $errors[] = 'Please select a grade level.';
    if (isSeniorHigh($form['grade_level']) && !in_array($form['strand'], $valid_strands, true)) {
        $errors[] = 'Please select a strand (GAS or ASSH) for Grade 11/12.';
    }

    // ---- Required contact and guardian details ----
    if ($form['email'] === '') {
        $errors[] = 'Email is required.';
    } elseif (!validateEmail($form['email'])) {
        $errors[] = 'Email format is invalid.';
    }

    if ($form['contact_number'] === '') {
        $errors[] = 'Contact number is required.';
    } else {
        $mobile = normalizePhMobile($form['contact_number']);
        if ($mobile === '') {
            $errors[] = 'Contact number must be a valid mobile number (11 digits starting with 09).';
        } else {
            $form['contact_number'] = $mobile;
        }
    }

    if ($form['address'] === '') {
        $errors[] = 'Address is required.';
    } elseif (mb_strlen($form['address']) < 5) {
        $errors[] = 'Address is too short — enter the complete address.';
    }

    if ($form['guardian_name'] === '') {
        $errors[] = 'Guardian name is required.';
    } else {
        // sanitize() HTML-encodes the apostrophe, so "O'Brien" arrives as
        // "O&#039;Brien". Validate the decoded text, but keep storing the
        // sanitized value like the rest of the system does.
        $g_name_plain = html_entity_decode($form['guardian_name'], ENT_QUOTES, 'UTF-8');
        if (!preg_match('/^[\p{L}\p{M}][\p{L}\p{M}\.\-\x27 ]{1,}$/u', $g_name_plain)) {
            $errors[] = 'Guardian name must be a real name (letters only, at least 2 characters).';
        }
    }

    if ($form['guardian_contact'] === '') {
        $errors[] = 'Guardian contact is required.';
    } else {
        $g_mobile = normalizePhMobile($form['guardian_contact']);
        if ($g_mobile === '') {
            $errors[] = 'Guardian contact must be a valid mobile number (11 digits starting with 09).';
        } else {
            $form['guardian_contact'] = $g_mobile;
        }
    }

    // ---- LRN is now required ----
    if ($form['lrn'] === '') {
        $errors[] = 'LRN is required.';
    } elseif (strlen($form['lrn']) !== 12) {
        $errors[] = 'LRN must be exactly 12 digits.';
    }

    if ($form['lrn'] !== '') {
        $existing = getRecord("SELECT student_id FROM students WHERE lrn = ?", [$form['lrn']]);
        if ($existing) $errors[] = 'A student with this LRN already exists.';
    }

    if ($active_sy && $form['lrn'] !== '') {
        $duplicate = getRecord(
            "SELECT e.enrollment_id FROM enrollments e
             JOIN students s ON e.student_id = s.student_id
             WHERE s.lrn = ? AND e.school_year_id = ? AND e.enrollment_status != 'Cancelled'",
            [$form['lrn'], $active_sy['sy_id']]
        );
        if ($duplicate) {
            $errors[] = 'This student is already enrolled for the current school year.';
        }
    }

    // ---- General average is now required ----
    // The lower bound is 60 because the SY 2026-2027 transmutation table
    // floors every reported grade at 60 — nothing below that can be a real
    // general average on a learner's record.
    $average_grade = 0;
    if ($form['general_average'] === '') {
        $errors[] = 'General average is required.';
    } elseif (!is_numeric($form['general_average'])) {
        $errors[] = 'General average must be a number.';
    } elseif ($form['general_average'] < 60 || $form['general_average'] > 100) {
        $errors[] = 'General average must be between 60 and 100.';
    } else {
        $average_grade = floatval($form['general_average']);
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $section = null;
            if ($average_grade > 0) {
                $section = assignSectionForApplication($form['grade_level'], $average_grade, $form['strand'] ?: null, $active_sy['sy_id']);
            }
            $section_id = $section['section_id'] ?? null;

            $student_id = insertRecord(
                "INSERT INTO students (lrn, first_name, last_name, middle_name, birth_date, gender,
                    contact_number, email, address, guardian_name, guardian_contact,
                    grade_level, strand, section_id, school_year_id, enrollment_status, is_active)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Pending',1)",
                [
                    $form['lrn'], $form['first_name'], $form['last_name'], $form['middle_name'] ?: null,
                    $form['birth_date'], $form['gender'], $form['contact_number'], $form['email'],
                    $form['address'], $form['guardian_name'], $form['guardian_contact'],
                    $form['grade_level'], $form['strand'] ?: null, $section_id, $active_sy['sy_id'],
                ]
            );

            if (!$student_id) {
                throw new Exception('Failed to create the student record.');
            }

            $enrollment_id = insertRecord(
                "INSERT INTO enrollments (student_id, school_year_id, grade_level, strand, section_id, enrollment_date, enrollment_status, processed_by, remarks)
                 VALUES (?,?,?,?,?, NOW(), 'Pending', ?, 'Walk-in enrollment')",
                [$student_id, $active_sy['sy_id'], $form['grade_level'], $form['strand'] ?: null, $section_id, $_SESSION['user_id']]
            );

            if (!$enrollment_id) {
                throw new Exception('Failed to create the enrollment record.');
            }
            executeQuery("INSERT INTO enrollment_requirements (enrollment_id, submitted_at) VALUES (?, NOW())", [$enrollment_id]);

            if ($section_id) {
                executeQuery(
                    "UPDATE sections SET current_enrollment = current_enrollment + 1 WHERE section_id = ?",
                    [$section_id]
                );
            }

            logActivity($_SESSION['user_id'], 'New Enrollment', 'enrollment',
                "Walk-in enrollment created for {$form['last_name']}, {$form['first_name']} (student ID $student_id)");

            $db->commit();
            $_SESSION['success_message'] = 'Student enrolled successfully' .
                ($section ? ' and assigned to section ' . $section['section_name'] . '.' : '. No section assigned yet — assign one from Enrollment Management.');
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            // The real reason goes to the PHP error log (XAMPP:
            // xampp/apache/logs/error.log) so a failure like this is
            // diagnosable instead of being swallowed by a generic message.
            error_log('New enrollment failed: ' . $e->getMessage());
            $errors[] = 'An error occurred while enrolling the student. Please try again.';
        }
    }
}

$csrf_token = generateCSRFToken();
include '../../includes/header.php';
?>

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-user-plus text-primary me-2"></i>New Enrollment</h2>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to List
            </a>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$active_sy): ?>
    <div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-2"></i>No active school year is set. Set one in Settings before enrolling students.</div>
<?php endif; ?>

<form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-sm rounded-3 mb-4">
                <div class="card-header bg-white border-0 pt-3">
                    <h5 class="mb-0"><i class="fas fa-user text-primary me-2"></i>Student Information</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">LRN</label>
                        <input type="text" name="lrn" class="form-control" maxlength="12" inputmode="numeric" pattern="[0-9]{12}" required placeholder="e.g. 123456789012" value="<?php echo htmlspecialchars($form['lrn']); ?>">
                        <p class="text-muted small mb-0 mt-1">Exactly 12 digits.</p>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Last Name</label>
                            <input type="text" name="last_name" class="form-control" required value="<?php echo htmlspecialchars($form['last_name']); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">First Name</label>
                            <input type="text" name="first_name" class="form-control" required value="<?php echo htmlspecialchars($form['first_name']); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control" value="<?php echo htmlspecialchars($form['middle_name']); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-semibold">Birth Date</label>
                            <input type="date" name="birth_date" class="form-control" required max="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($form['birth_date']); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-semibold">Gender</label>
                            <select name="gender" class="form-select" required>
                                <option value="">-- Select --</option>
                                <option value="Male" <?php echo $form['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo $form['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Contact Number</label>
                            <input type="text" name="contact_number" class="form-control" required inputmode="numeric" maxlength="13" placeholder="09XXXXXXXXX" value="<?php echo htmlspecialchars($form['contact_number']); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($form['email']); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Address</label>
                        <textarea name="address" class="form-control" rows="2" required placeholder="House/Street, Barangay, City"><?php echo htmlspecialchars($form['address']); ?></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Guardian Name</label>
                            <input type="text" name="guardian_name" class="form-control" required value="<?php echo htmlspecialchars($form['guardian_name']); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Guardian Contact</label>
                            <input type="text" name="guardian_contact" class="form-control" required inputmode="numeric" maxlength="13" placeholder="09XXXXXXXXX" value="<?php echo htmlspecialchars($form['guardian_contact']); ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm rounded-3 mb-4">
                <div class="card-header bg-white border-0 pt-3">
                    <h5 class="mb-0"><i class="fas fa-clipboard-list text-primary me-2"></i>Enrollment Details</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Grade Level</label>
                        <select name="grade_level" id="gradeLevelSelect" class="form-select" required onchange="toggleStrandField()">
                            <option value="">-- Select --</option>
                            <?php foreach ($grade_levels as $g): ?>
                                <option value="<?php echo $g; ?>" <?php echo $form['grade_level'] === $g ? 'selected' : ''; ?>><?php echo $g; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="strandField" style="<?php echo in_array($form['grade_level'], ['Grade 11', 'Grade 12'], true) ? '' : 'display:none;'; ?>">
                        <label class="form-label fw-semibold">Strand (SHS)</label>
                        <select name="strand" class="form-select">
                            <option value="">-- Select --</option>
                            <?php foreach ($valid_strands as $s): ?>
                                <option value="<?php echo $s; ?>" <?php echo $form['strand'] === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">General Average</label>
                        <input type="number" step="0.01" min="60" max="100" name="general_average" class="form-control" required placeholder="e.g. 88.50" value="<?php echo htmlspecialchars($form['general_average']); ?>">
                        <p class="text-muted small mb-0 mt-1"><i class="fas fa-circle-info me-1"></i>Between 60 and 100. Used to auto-assign a section.</p>
                    </div>
                    <hr>
                    <button type="submit" class="btn btn-success btn-lg w-100" <?php echo !$active_sy ? 'disabled' : ''; ?>>
                        <i class="fas fa-user-plus me-2"></i>Enroll Student
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function toggleStrandField() {
    var grade = document.getElementById('gradeLevelSelect').value;
    var strandField = document.getElementById('strandField');
    strandField.style.display = (grade === 'Grade 11' || grade === 'Grade 12') ? '' : 'none';
}
</script>

<?php include '../../includes/footer.php'; ?>