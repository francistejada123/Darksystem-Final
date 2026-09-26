<?php
// modules/teachers/add.php
// Add new teacher

require_once __DIR__ . '/../../config/database.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/teacher_validation.php';

if (!hasRole('Administrator')) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'Add Teacher';
$current_page = 'teachers';
$base_url = '../../';

$errors = [];

// The simple teacher record adds two optional columns to users. Create them once if
// they are missing (same idea as the other *EnsureSchema helpers), so no manual SQL
// is needed. Existing rows and columns are untouched.
try {
    if (!getRecord("SHOW COLUMNS FROM users LIKE 'employee_id'")) {
        executeQuery("ALTER TABLE users ADD COLUMN employee_id VARCHAR(50) NULL DEFAULT NULL");
    }
    if (!getRecord("SHOW COLUMNS FROM users LIKE 'department_level'")) {
        executeQuery("ALTER TABLE users ADD COLUMN department_level VARCHAR(10) NULL DEFAULT NULL");
    }
} catch (Throwable $e) {
    error_log('teachers/add.php schema check failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $username = sanitize($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $email = sanitize($_POST['email'] ?? '');
        $first_name = sanitize($_POST['first_name'] ?? '');
        $last_name = sanitize($_POST['last_name'] ?? '');
        $middle_name = sanitize($_POST['middle_name'] ?? '');
        $suffix = sanitize($_POST['suffix'] ?? '');
        $sex = sanitize($_POST['sex'] ?? '');
        $contact_number = sanitize($_POST['contact_number'] ?? '');

        // Simple teacher record fields
        $employee_id = sanitize($_POST['employee_id'] ?? '');
        $position_designation = sanitize($_POST['position_designation'] ?? '');
        $specialization = sanitize($_POST['specialization'] ?? '');
        $department_level = sanitize($_POST['department_level'] ?? '');
        $employment_status = sanitize($_POST['employment_status'] ?? 'Active');

        if (empty($username)) $errors[] = 'Username is required.';
        if (empty($password) || strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
        if (empty($email)) $errors[] = 'Email is required.';
        elseif (!validateEmail($email)) $errors[] = 'Invalid email address.';
        $errors = array_merge($errors, tvValidate($_POST, 'add'));
        $contact_number = tvNormalizeMobile($contact_number);
        if (!in_array($employment_status, ['Active', 'Inactive'], true)) $errors[] = 'Employment Status must be Active or Inactive.';
        if (strlen($employee_id) > 50) $errors[] = 'Teacher ID / Employee ID must be 50 characters or fewer.';

        if (empty($errors)) {
            $check = getRecord("SELECT user_id FROM users WHERE username = ?", [$username]);
            if ($check) $errors[] = 'Username already exists.';
        }

        if (empty($errors)) {
            $check = getRecord("SELECT user_id FROM users WHERE email = ?", [$email]);
            if ($check) $errors[] = 'Email already exists.';
        }

        if (empty($errors) && $employee_id !== '') {
            $check = getRecord("SELECT user_id FROM users WHERE employee_id = ?", [$employee_id]);
            if ($check) $errors[] = 'Teacher ID / Employee ID already exists.';
        }

        if (empty($errors)) {
            $hashed_password = hashPassword($password);

            // Employment Status uses the existing users.is_active flag (Inactive teachers
            // cannot log in). The removed fields (education, degree, years, PRC license,
            // qualification) are simply left at their column defaults.
            $sql = "INSERT INTO users (
                        username, password_hash, email, contact_number, profile_image,
                        first_name, last_name, middle_name, suffix, sex,
                        specialization, position_designation, employee_id, department_level,
                        can_handle_shs, role_id, is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $params = [
                $username,
                $hashed_password,
                $email,
                $contact_number ?: null,
                null,
                $first_name,
                $last_name,
                $middle_name ?: null,
                $suffix ?: null,
                $sex ?: null,
                $specialization ?: null,
                $position_designation ?: null,
                $employee_id ?: null,
                $department_level ?: null,
                ($department_level === 'SHS') ? 1 : 0,
                3,
                ($employment_status === 'Active') ? 1 : 0
            ];
            $result = executeQuery($sql, $params);

            if ($result) {
                logActivity($_SESSION['user_id'], 'Added teacher', 'teachers', "Added teacher: $last_name, $first_name");
                $_SESSION['success_message'] = 'Teacher added successfully!';
                header('Location: index.php?success=1');
                exit;
            } else {
                $errors[] = 'Failed to add teacher. Please try again.';
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
.tform-page .btn-back { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 500; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); text-decoration: none; transition: all 0.15s; }
.tform-page .btn-back:hover { background: var(--surface-hover); color: var(--ink); }
.tform-page .error-alert { background: #fdecea; border: 1px solid #f5c6cb; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; color: #C94A4A; font-size: 13px; }
.tform-page .error-alert ul { margin: 4px 0 0 0; padding-left: 18px; }
.tform-page .form-card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; margin-bottom: 20px; }
.tform-page .form-card-header { padding: 14px 20px; border-bottom: 1px solid var(--border-soft); display: flex; align-items: center; gap: 8px; }
.tform-page .form-card-header h5 { font-size: 14px; font-weight: 600; color: var(--ink); margin: 0; }
.tform-page .form-card-header i { font-size: 13px; color: var(--ink); }
.tform-page .form-card-body { padding: 20px; }
.tform-page .form-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.tform-page .form-group { display: flex; flex-direction: column; gap: 4px; }
.tform-page .form-group label { font-size: 12px; font-weight: 600; color: var(--text-muted); }
.tform-page .form-group label .req { color: #C94A4A; }
.tform-page .form-group input,
.tform-page .form-group select { border: 1px solid var(--border); border-radius: 6px; padding: 8px 12px; font-size: 13px; color: var(--ink); background: var(--surface); height: 38px; }
.tform-page .form-group input:focus,
.tform-page .form-group select:focus { outline: none; border-color: var(--ink); box-shadow: 0 0 0 2px rgba(20,33,61,0.08); }
.tform-page .form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 24px; }
.tform-page .btn-cancel { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 500; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); text-decoration: none; transition: all 0.15s; }
.tform-page .btn-cancel:hover { background: var(--surface-hover); }
.tform-page .btn-submit { display: inline-flex; align-items: center; gap: 6px; padding: 8px 20px; border-radius: 6px; font-size: 13px; font-weight: 500; color: #fff; background: #14213D; border: none; cursor: pointer; transition: all 0.15s; }
.tform-page .btn-submit:hover { filter: brightness(0.9); }
@media (max-width: 768px) { .tform-page .form-grid { grid-template-columns: 1fr; } }
</style>

<div class="tform-page" style="padding: 24px;">

    <div class="page-header">
        <h4>Add Teacher</h4>
        <a href="index.php" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
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

    <p class="tv-note" style="font-size:13px;color:var(--text-muted);margin:-8px 0 16px;">Fields marked <span style="color:#C94A4A;font-weight:700;">*</span> are required.</p>
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
                        <input type="text" name="first_name" placeholder="First name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" placeholder="Middle name" value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Last Name <span class="req">*</span></label>
                        <input type="text" name="last_name" placeholder="Last name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Suffix</label>
                        <input type="text" name="suffix" placeholder="Jr., Sr., III" value="<?php echo htmlspecialchars($_POST['suffix'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Sex / Gender <span class="req">*</span></label>
                        <select name="sex" required>
                            <option value="">-- Select --</option>
                            <option value="Male" <?php echo (($_POST['sex'] ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo (($_POST['sex'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
                            <option value="Other" <?php echo (($_POST['sex'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Email <span class="req">*</span></label>
                        <input type="email" name="email" placeholder="teacher@email.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Contact Number <span class="req">*</span></label>
                        <input type="text" name="contact_number" placeholder="09XXXXXXXXX" required inputmode="numeric" maxlength="13" pattern="(09|\+639)[0-9]{9}" title="Mobile number, e.g. 09171234567" value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ''); ?>">
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
                        <label>Teacher ID / Employee ID</label>
                        <input type="text" name="employee_id" maxlength="50" placeholder="e.g. 1234567" value="<?php echo htmlspecialchars($_POST['employee_id'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Position / Designation <span class="req">*</span></label>
                        <input type="text" name="position_designation" placeholder="e.g. Teacher I, Master Teacher" required maxlength="100" value="<?php echo htmlspecialchars($_POST['position_designation'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Specialization / Subject Area <span class="req">*</span></label>
                        <select name="specialization" required>
                            <option value="">-- Select Subject Area --</option>
                            <?php
                            $specs = ['Mathematics','English','Science','Filipino','Social Studies','MAPEH','TLE','ICT','ESP','Values Education','Physical Education','Music','Arts','Health','Computer','Research','Statistics','General Education'];
                            foreach ($specs as $spec):
                            ?>
                                <option value="<?php echo $spec; ?>" <?php echo (($_POST['specialization'] ?? '') === $spec) ? 'selected' : ''; ?>><?php echo $spec; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Department / Level <span class="req">*</span></label>
                        <select name="department_level" required>
                            <option value="">-- Select --</option>
                            <option value="JHS" <?php echo (($_POST['department_level'] ?? '') === 'JHS') ? 'selected' : ''; ?>>JHS (Junior High School)</option>
                            <option value="SHS" <?php echo (($_POST['department_level'] ?? '') === 'SHS') ? 'selected' : ''; ?>>SHS (Senior High School)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Employment Status</label>
                        <select name="employment_status">
                            <option value="Active" <?php echo (($_POST['employment_status'] ?? 'Active') !== 'Inactive') ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo (($_POST['employment_status'] ?? '') === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
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
                <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="form-group">
                        <label>Username <span class="req">*</span></label>
                        <input type="text" name="username" placeholder="Enter username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Password <span class="req">*</span></label>
                        <input type="password" name="password" placeholder="Enter password (min 6 chars)" required>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <a href="index.php" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-submit">
                <i class="fas fa-save"></i> Save Teacher
            </button>
        </div>
    </form>

</div>

<?php include '../../includes/footer.php'; ?>
