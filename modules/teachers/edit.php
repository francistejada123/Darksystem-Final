<?php
// modules/teachers/edit.php
// Edit teacher

require_once __DIR__ . '/../../config/database.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/teacher_validation.php';
require_once __DIR__ . '/../../includes/teacher_department.php';

if (!hasRole('Administrator')) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'Edit Teacher';
$current_page = 'teachers';
$base_url = '../../';

$teacher_id = intval($_GET['id'] ?? 0);
$errors = [];
$teacher = null;

if ($teacher_id > 0) {
    $teacher = getRecord("SELECT * FROM users WHERE user_id = ? AND role_id = 3", [$teacher_id]);
    if (!$teacher) {
        $_SESSION['error_message'] = 'Teacher not found.';
        header('Location: index.php');
        exit;
    }
} else {
    $_SESSION['error_message'] = 'Invalid teacher ID.';
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $username = sanitize($_POST['username'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $first_name = sanitize($_POST['first_name'] ?? '');
        $last_name = sanitize($_POST['last_name'] ?? '');
        $middle_name = sanitize($_POST['middle_name'] ?? '');
        $suffix = sanitize($_POST['suffix'] ?? '');
        $sex = sanitize($_POST['sex'] ?? '');
        $contact_number = sanitize($_POST['contact_number'] ?? '');
        $is_active = intval($_POST['is_active'] ?? 1);

        $highest_educ_attainment = sanitize($_POST['highest_educ_attainment'] ?? '');
        $degree_course = sanitize($_POST['degree_course'] ?? '');
        $specialization = sanitize($_POST['specialization'] ?? '');
        $years_experience = (int)($_POST['years_experience'] ?? 0);
        $position_designation = sanitize($_POST['position_designation'] ?? '');
        $prc_license_number = sanitize($_POST['prc_license_number'] ?? '');
        $qualification_status = sanitize($_POST['qualification_status'] ?? 'Pending');
        // Department / Level is the source of truth for which classes this
        // teacher may ever be assigned to (JHS -> Grade 7-10, SHS -> Grade 11-12).
        // Kept editable here so an Admin can correct a mis-selected department;
        // existing out-of-scope assignments are flagged, never deleted.
        $department_level = tdNormalize($_POST['department_level'] ?? ($teacher['department_level'] ?? ''));

        if (empty($username)) $errors[] = 'Username is required.';
        if (empty($email)) $errors[] = 'Email is required.';
        elseif (!validateEmail($email)) $errors[] = 'Invalid email address.';
        $errors = array_merge($errors, tvValidate($_POST, 'edit'));
        $contact_number = tvNormalizeMobile($contact_number);

        if (empty($errors)) {
            $check = getRecord("SELECT user_id FROM users WHERE username = ? AND user_id != ?", [$username, $teacher_id]);
            if ($check) $errors[] = 'Username already exists.';
        }

        if (empty($errors)) {
            $check = getRecord("SELECT user_id FROM users WHERE email = ? AND user_id != ?", [$email, $teacher_id]);
            if ($check) $errors[] = 'Email already exists.';
            elseif ($owner = diEmailOwner($email, 0, $teacher_id)) $errors[] = 'This email is already used by ' . $owner . '. Use the teacher\'s own email.';
        }

        if (empty($errors)) {
            $sql = "UPDATE users SET
                    username = ?,
                    email = ?,
                    contact_number = ?,
                    first_name = ?,
                    last_name = ?,
                    middle_name = ?,
                    suffix = ?,
                    sex = ?,
                    highest_educ_attainment = ?,
                    specialization = ?,
                    degree_course = ?,
                    prc_license_number = ?,
                    position_designation = ?,
                    years_experience = ?,
                    department_level = ?,
                    qualification_status = ?,
                    is_active = ?
                    WHERE user_id = ?";
            $params = [
                $username, $email, $contact_number ?: null,
                $first_name, $last_name, $middle_name ?: null, $suffix ?: null, $sex ?: null,
                $highest_educ_attainment ?: null, $specialization ?: null, $degree_course ?: null,
                $prc_license_number ?: null, $position_designation ?: null, $years_experience,
                $department_level ?: null,
                $qualification_status, $is_active, $teacher_id
            ];

            $result = executeQuery($sql, $params);

            if ($result) {
                logActivity($_SESSION['user_id'], 'Updated teacher', 'teachers', "Updated teacher: $last_name, $first_name");
                $_SESSION['success_message'] = 'Teacher updated successfully!';
                header('Location: index.php?success=1');
                exit;
            } else {
                $errors[] = 'Failed to update teacher. Please try again.';
            }
        }
    }
}

$csrf_token = generateCSRFToken();
include '../../includes/header.php';
?>

<style>
.tform-page { background: var(--surface-hover); }
.tform-page .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.tform-page .page-header h4 { font-size: 20px; font-weight: 700; color: var(--ink); margin: 0; }
.tform-page .page-header-actions { display: flex; gap: 8px; }
.tform-page .btn-secondary { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 6px; font-size: 14.5px; font-weight: 500; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); text-decoration: none; transition: all 0.15s; }
.tform-page .btn-secondary:hover { background: var(--surface-hover); color: var(--ink); }
.tform-page .error-alert { background: #fdecea; border: 1px solid #f5c6cb; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; color: #C94A4A; font-size: 14.5px; }
.tform-page .error-alert ul { margin: 4px 0 0 0; padding-left: 18px; }
.tform-page .form-card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; margin-bottom: 20px; }
.tform-page .form-card-header { padding: 14px 20px; border-bottom: 1px solid var(--border-soft); display: flex; align-items: center; gap: 8px; }
.tform-page .form-card-header h5 { font-size: 15px; font-weight: 600; color: var(--ink); margin: 0; }
.tform-page .form-card-header i { font-size: 14.5px; color: var(--ink); }
.tform-page .form-card-body { padding: 20px; }
.tform-page .form-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.tform-page .form-group { display: flex; flex-direction: column; gap: 4px; }
.tform-page .form-group label { font-size: 13.5px; font-weight: 600; color: var(--text-muted); }
.tform-page .form-group label .req { color: #C94A4A; }
.tform-page .form-group label .muted { color: #9ca3af; font-weight: 400; }
.tform-page .form-group input,
.tform-page .form-group select { border: 1px solid var(--border); border-radius: 6px; padding: 8px 12px; font-size: 14.5px; color: var(--ink); background: var(--surface); height: 38px; }
.tform-page .form-group input:focus,
.tform-page .form-group select:focus { outline: none; border-color: var(--ink); box-shadow: 0 0 0 2px rgba(20,33,61,0.08); }
.tform-page .form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 24px; }
.tform-page .btn-cancel { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; font-size: 14.5px; font-weight: 500; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); text-decoration: none; transition: all 0.15s; }
.tform-page .btn-cancel:hover { background: var(--surface-hover); }
.tform-page .btn-submit { display: inline-flex; align-items: center; gap: 6px; padding: 8px 20px; border-radius: 6px; font-size: 14.5px; font-weight: 500; color: #fff; background: #14213D; border: none; cursor: pointer; transition: all 0.15s; }
.tform-page .btn-submit:hover { filter: brightness(0.9); }
@media (max-width: 768px) { .tform-page .form-grid { grid-template-columns: 1fr; } }
</style>

<?php
if (!empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['username', 'email', 'first_name', 'last_name', 'middle_name', 'suffix', 'sex', 'contact_number',
              'highest_educ_attainment', 'degree_course', 'specialization', 'years_experience',
              'position_designation', 'prc_license_number', 'qualification_status', 'is_active'] as $k) {
        if (isset($_POST[$k])) $teacher[$k] = $_POST[$k];
    }
}
?>
<div class="tform-page" style="padding: 24px;">

    <div class="page-header">
        <h4>Edit Teacher</h4>
        <div class="page-header-actions">
            <a href="view.php?id=<?php echo $teacher_id; ?>" class="btn-secondary">
                <i class="fas fa-eye"></i> View Profile
            </a>
            <a href="index.php" class="btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="error-alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo $error; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <p class="tv-note" style="font-size:14.5px;color:var(--text-muted);margin:-8px 0 16px;">Fields marked <span style="color:#C94A4A;font-weight:700;">*</span> are required.</p>
    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

        <div class="form-card">
            <div class="form-card-header">
                <i class="fas fa-user"></i>
                <h5>Basic Information</h5>
            </div>
            <div class="form-card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>First Name <span class="req">*</span></label>
                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($teacher['first_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" value="<?php echo htmlspecialchars($teacher['middle_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Last Name <span class="req">*</span></label>
                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($teacher['last_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Suffix</label>
                        <input type="text" name="suffix" placeholder="Jr., Sr., III" value="<?php echo htmlspecialchars($teacher['suffix'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Sex / Gender <span class="req">*</span></label>
                        <select name="sex" required>
                            <option value="">-- Select --</option>
                            <option value="Male" <?php echo (($teacher['sex'] ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo (($teacher['sex'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
                            <option value="Other" <?php echo (($teacher['sex'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Email <span class="req">*</span></label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($teacher['email']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Contact Number <span class="req">*</span></label>
                        <input type="text" name="contact_number" placeholder="09XXXXXXXXX" required inputmode="numeric" maxlength="13" pattern="(09|\+639)[0-9]{9}" title="Mobile number, e.g. 09171234567" value="<?php echo htmlspecialchars($teacher['contact_number'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="form-card">
            <div class="form-card-header">
                <i class="fas fa-graduation-cap"></i>
                <h5>Professional Information</h5>
            </div>
            <div class="form-card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Department / Level <span class="req">*</span></label>
                        <select name="department_level" required>
                            <option value="">-- Select --</option>
                            <option value="JHS" <?php echo tdNormalize($teacher['department_level'] ?? '') === 'JHS' ? 'selected' : ''; ?>>JHS (Junior High School) &mdash; Grades 7&ndash;10 only</option>
                            <option value="SHS" <?php echo tdNormalize($teacher['department_level'] ?? '') === 'SHS' ? 'selected' : ''; ?>>SHS (Senior High School) &mdash; Grades 11&ndash;12 only</option>
                        </select>
                        <small class="text-muted">This decides which classes, sections and subjects this teacher can be assigned to.</small>
                    </div>
                    <div class="form-group">
                        <label>Highest Educational Attainment <span class="req">*</span></label>
                        <select name="highest_educ_attainment" required>
                            <option value="">-- Select --</option>
                            <?php
                            $hea_options = ["Bachelor's Degree", "Master's Degree", "Doctorate", "Post Graduate Diploma"];
                            foreach ($hea_options as $opt):
                            ?>
                                <option value="<?php echo $opt; ?>" <?php echo (($teacher['highest_educ_attainment'] ?? '') === $opt) ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Degree / Course <span class="req">*</span></label>
                        <input type="text" name="degree_course" placeholder="e.g. Bachelor of Secondary Education" required maxlength="150" value="<?php echo htmlspecialchars($teacher['degree_course'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Specialization / Major <span class="req">*</span></label>
                        <select name="specialization" required>
                            <option value="">-- Select Specialization --</option>
                            <?php
                            $specs = ['Mathematics','English','Science','Filipino','Social Studies','MAPEH','TLE','ICT','ESP','Values Education','Physical Education','Music','Arts','Health','Computer','Research','Statistics','General Education'];
                            foreach ($specs as $spec):
                            ?>
                                <option value="<?php echo $spec; ?>" <?php echo (($teacher['specialization'] ?? '') === $spec) ? 'selected' : ''; ?>><?php echo $spec; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Years of Teaching Experience <span class="req">*</span></label>
                        <input type="number" name="years_experience" placeholder="0" min="0" max="50" required value="<?php echo htmlspecialchars($teacher['years_experience'] ?? 0); ?>">
                    </div>
                    <div class="form-group">
                        <label>Position / Designation <span class="req">*</span></label>
                        <input type="text" name="position_designation" placeholder="e.g. Teacher I, Master Teacher" required maxlength="100" value="<?php echo htmlspecialchars($teacher['position_designation'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>PRC License Number</label>
                        <input type="text" name="prc_license_number" placeholder="License # (digits)" inputmode="numeric" pattern="[0-9]{6,10}" title="6 to 10 digits" value="<?php echo htmlspecialchars($teacher['prc_license_number'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Qualification Status</label>
                        <select name="qualification_status">
                            <option value="Pending" <?php echo (($teacher['qualification_status'] ?? '') === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="Qualified" <?php echo (($teacher['qualification_status'] ?? '') === 'Qualified') ? 'selected' : ''; ?>>Qualified</option>
                            <option value="Not Qualified" <?php echo (($teacher['qualification_status'] ?? '') === 'Not Qualified') ? 'selected' : ''; ?>>Not Qualified</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-card">
            <div class="form-card-header">
                <i class="fas fa-key"></i>
                <h5>Account Information</h5>
            </div>
            <div class="form-card-body">
                <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr;">
                    <div class="form-group">
                        <label>Username <span class="req">*</span></label>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($teacher['username']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <div style="font-size:14.5px;color:var(--text-muted);line-height:1.45;padding-top:6px;"><i class="fas fa-lock me-1"></i>Only the teacher sets their password. If they forget it, they use <strong>Forgot Password</strong> on the Teacher Login.</div>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="is_active">
                            <option value="1" <?php echo $teacher['is_active'] ? 'selected' : ''; ?>>Active</option>
                            <option value="0" <?php echo !$teacher['is_active'] ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <a href="index.php" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-submit">
                <i class="fas fa-save"></i> Update Teacher
            </button>
        </div>
    </form>

</div>

<?php include '../../includes/footer.php'; ?>
