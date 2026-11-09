<?php
// profile.php
// User profile management with profile picture

require_once 'config/database.php';
require_once 'includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$page_title = 'My Profile';
$base_url = BASE_URL;

$user = getCurrentUser();
$errors = [];
$success = false;
$upload_error = '';
$upload_success = '';

// Handle profile photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_image'])) {
    $file = $_FILES['profile_image'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $max_size = 2 * 1024 * 1024; // 2MB

    if ($file['error'] === UPLOAD_ERR_OK) {
        if ($file['size'] > $max_size) {
            $upload_error = 'Image size must be less than 2MB.';
        } else {
            // Never trust the client-supplied MIME type (Content-Type header)
            // or the original filename's extension — both are attacker
            // controlled and can be spoofed to smuggle a .php file past a
            // "JPG only" check. getimagesize() actually reads and decodes
            // the file, so this only passes for real image data.
            $image_info = @getimagesize($file['tmp_name']);
            $ext_map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];

            if ($image_info === false || !isset($ext_map[$image_info[2]])) {
                $upload_error = 'Only JPG, PNG, GIF, and WEBP images are allowed.';
            } else {
                // Generate unique filename — extension comes from the verified
                // image type above, never from the uploaded file's own name.
                $ext = $ext_map[$image_info[2]];
                $filename = 'admin_' . $user['user_id'] . '_' . time() . '.' . $ext;
                $upload_path = 'static/uploads/profiles/' . $filename;

                // Create directory if not exists
                if (!is_dir('static/uploads/profiles/')) {
                    mkdir('static/uploads/profiles/', 0777, true);
                }

                // Move uploaded file
                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    // Delete old photo if exists
                    $old = getRecord("SELECT profile_image FROM users WHERE user_id = ?", [$user['user_id']]);
                    if ($old && !empty($old['profile_image']) && file_exists('static/uploads/profiles/' . $old['profile_image'])) {
                        unlink('static/uploads/profiles/' . $old['profile_image']);
                    }

                    // Update database
                    executeQuery("UPDATE users SET profile_image = ? WHERE user_id = ?", [$filename, $user['user_id']]);
                    $upload_success = 'Profile photo updated successfully!';

                    // Refresh user data
                    $user = getCurrentUser();
                } else {
                    $upload_error = 'Failed to upload image. Please try again.';
                }
            }
        }
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $first_name = sanitize($_POST['first_name'] ?? '');
        $last_name = sanitize($_POST['last_name'] ?? '');
        $middle_name = sanitize($_POST['middle_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validation
        if (empty($first_name)) $errors[] = 'First name is required.';
        if (empty($last_name)) $errors[] = 'Last name is required.';
        if (empty($email)) $errors[] = 'Email is required.';
        if (!validateEmail($email)) $errors[] = 'Invalid email address.';

        // Check email uniqueness
        if (empty($errors)) {
            $check = getRecord("SELECT user_id FROM users WHERE email = ? AND user_id != ?", [$email, $user['user_id']]);
            if ($check) $errors[] = 'Email already exists in the system.';
        }

        // Password change
        if (!empty($current_password) || !empty($new_password) || !empty($confirm_password)) {
            if (empty($current_password)) {
                $errors[] = 'Current password is required to change password.';
            } elseif (!verifyPassword($current_password, $user['password_hash'])) {
                $errors[] = 'Current password is incorrect.';
            } elseif (empty($new_password) || strlen($new_password) < 6) {
                $errors[] = 'New password must be at least 6 characters.';
            } elseif ($new_password !== $confirm_password) {
                $errors[] = 'Passwords do not match.';
            }
        }

        // Update profile
        if (empty($errors)) {
            if (!empty($new_password)) {
                $hashed_password = hashPassword($new_password);
                $sql = "UPDATE users SET first_name = ?, last_name = ?, middle_name = ?, email = ?, password_hash = ? WHERE user_id = ?";
                $params = [$first_name, $last_name, $middle_name, $email, $hashed_password, $user['user_id']];
            } else {
                $sql = "UPDATE users SET first_name = ?, last_name = ?, middle_name = ?, email = ? WHERE user_id = ?";
                $params = [$first_name, $last_name, $middle_name, $email, $user['user_id']];
            }

            if (executeQuery($sql, $params)) {
                $_SESSION['user_name'] = $first_name . ' ' . $last_name;
                logActivity($_SESSION['user_id'], 'Updated profile', 'profile', "User updated their profile");
                $_SESSION['success_message'] = 'Profile updated successfully!';
                header('Location: profile.php?success=1');
                exit;
            } else {
                $errors[] = 'Failed to update profile. Please try again.';
            }
        }
    }
}

// Check profile image
$profile_image = '';
if (!empty($user['profile_image']) && file_exists('static/uploads/profiles/' . $user['profile_image'])) {
    $profile_image = 'static/uploads/profiles/' . $user['profile_image'];
}

// Teacher-only: assigned subjects/sections for the active school year —
// same source data (and the same query shape) as the "My Classes" table
// on the Teacher Dashboard (index.php), just for display here. Read-only,
// purely additive — no existing query above is touched.
$my_classes = [];
if (($user['role_name'] ?? '') === 'Teacher') {
    $active_sy_profile = getRecord("SELECT * FROM school_years WHERE is_active = 1");
    if ($active_sy_profile) {
        $my_classes = getRecords("
            SELECT s.section_name, s.grade_level, s.strand, sub.subject_name
            FROM teacher_subjects ts
            JOIN sections s ON ts.section_id = s.section_id
            JOIN subjects sub ON ts.subject_code = sub.subject_code
            WHERE ts.teacher_id = ? AND ts.school_year_id = ?
            ORDER BY s.grade_level, s.section_name, sub.subject_name
        ", [$user['user_id'], $active_sy_profile['sy_id']]);
    }
}

$csrf_token = generateCSRFToken();
include 'includes/header.php';
?>

<style>
/* ===== My Profile (layout only -- same data, forms and backend) ===== */
.apf-head { margin: 24px 0 18px; }
.apf-head h2 { margin: 0; font-size: 24px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 10px; }
.apf-head p { margin: 4px 0 0; color: var(--text-muted); font-size: 15px; }
.apf-grid { display: grid; gap: 18px; }
.apf-grid-top { grid-template-columns: 300px minmax(0, 1fr); align-items: start; }
.apf-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 4px 14px rgba(20,33,61,0.05); padding: 18px 20px; margin-bottom: 18px; min-width: 0; }
.apf-card-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
.apf-card-head h5 { margin: 0; font-size: 16px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 10px; }
.apf-ic { width: 32px; height: 32px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; background: rgba(53,110,168,0.12); color: #356EA8; }
.apf-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 24px; }
.apf-field { min-width: 0; padding-bottom: 10px; border-bottom: 1px solid var(--border-soft); }
.apf-span { grid-column: 1 / -1; }
.apf-label { font-size: 12.5px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; display: flex; align-items: center; gap: 6px; }
.apf-label i { width: 14px; text-align: center; font-size: 12px; }
.apf-value { font-size: 15px; font-weight: 600; color: var(--ink); line-height: 1.4; word-break: break-word; }
.apf-empty { color: var(--text-muted); font-weight: 400; font-style: italic; }
.apf-summary { text-align: center; }
.apf-summary .profile-avatar { width: 104px; height: 104px; margin: 0 auto 12px; }
.apf-summary .profile-name { margin-bottom: 2px; }
.apf-role { font-size: 14px; color: var(--text-muted); margin-bottom: 10px; }
.apf-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 13px; font-weight: 600; white-space: nowrap; }
.apf-badge.ok { background: var(--success-tint); color: var(--success); }
.apf-badge.off { background: rgba(201,74,74,0.10); color: #C94A4A; }
.apf-id-box { border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; margin: 14px 0 12px; }
.apf-id-box .apf-label { justify-content: center; }
.apf-id-value { font-size: 16px; font-weight: 700; color: var(--ink); word-break: break-all; }
.apf-hint { font-size: 12.5px; color: var(--text-muted); margin-top: 8px; }
.apf-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 600; border: 1px solid transparent; cursor: pointer; transition: background-color .15s, border-color .15s; white-space: nowrap; }
.apf-btn-primary { background: #356EA8; color: #fff; }
.apf-btn-primary:hover { background: #2B5C8D; color: #fff; }
.apf-btn-outline { background: transparent; color: #356EA8; border-color: #356EA8; border-radius: 999px; padding: 6px 14px; }
.apf-btn-outline:hover { background: rgba(53,110,168,0.08); }
.apf-btn-light { background: transparent; color: var(--ink); border-color: var(--border); width: 100%; }
.apf-btn-light:hover { background: var(--surface-hover); }
.apf-form-label { display: block; font-size: 13.5px; font-weight: 600; color: var(--text-muted); margin-bottom: 5px; }
.apf-pw-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.apf-pw { position: relative; }
.apf-pw .profile-form-input { width: 100%; padding-right: 40px; height: 42px; }
.apf-eye { position: absolute; right: 4px; top: 50%; transform: translateY(-50%); border: 0; background: transparent; color: var(--text-muted); width: 34px; height: 34px; border-radius: 6px; cursor: pointer; }
.apf-eye:hover { color: var(--ink); }
.apf-pw-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 12px; flex-wrap: wrap; }
.apf-pw-foot small { flex: 1 1 260px; color: var(--text-muted); font-size: 13.5px; }
.apf-classes { margin: 0; padding-left: 18px; }
.apf-badge.wait { background: rgba(183,121,31,0.12); color: #B7791F; }
.apf-badge.dept { background: rgba(53,110,168,0.12); color: #356EA8; }
.apf-badge.sec { background: var(--surface-hover); color: var(--ink); border: 1px solid var(--border); font-weight: 600; }
.apf-sub-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 18px 0 10px; padding-top: 14px; border-top: 1px solid var(--border-soft); font-size: 14.5px; font-weight: 700; color: var(--ink); }
.apf-sub-head i { color: #356EA8; margin-right: 6px; }
.apf-count { font-size: 13px; font-weight: 600; color: var(--text-muted); }
.apf-assign-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 16px; }
.apf-assign { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 12px; border: 1px solid var(--border-soft); border-radius: 8px; min-width: 0; }
.apf-assign-subj { font-size: 14.5px; font-weight: 600; color: var(--ink); min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.apf-assign-subj i { color: var(--text-muted); font-size: 12.5px; margin-right: 4px; }
@media (max-width: 900px) { .apf-assign-list { grid-template-columns: 1fr; } }
@media (max-width: 420px) { .apf-assign { flex-direction: column; align-items: flex-start; } .apf-assign-subj { white-space: normal; } }
@media (max-width: 900px) { .apf-grid-top { grid-template-columns: 1fr; } }
@media (max-width: 600px) {
    .apf-fields, .apf-pw-grid { grid-template-columns: 1fr; }
    .apf-card { padding: 16px; }
    .apf-pw-foot .apf-btn { width: 100%; }
}
</style>

<?php
// Display helper (no data changes).
$apf_val = function ($v, $empty = 'Not provided') {
    $v = trim((string) ($v ?? ''));
    return $v === '' ? '<span class="apf-empty">' . $empty . '</span>' : htmlspecialchars($v);
};
?>

<div class="apf-head">
    <h2><i class="fas fa-user-circle text-primary"></i> My Profile</h2>
    <p><?php echo ($user['role_name'] ?? '') === 'Teacher'
        ? 'View and manage your personal, professional, and account information.'
        : 'View and manage your personal and account information'; ?></p>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="profile-alert success"><i class="fas fa-check-circle"></i> Profile updated successfully!</div>
<?php endif; ?>

<?php if ($upload_success): ?>
    <div class="profile-alert success"><i class="fas fa-check-circle"></i> <?php echo $upload_success; ?></div>
<?php endif; ?>

<?php if ($upload_error): ?>
    <div class="profile-alert danger"><i class="fas fa-exclamation-circle"></i> <?php echo $upload_error; ?></div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="profile-alert danger">
        <i class="fas fa-exclamation-circle"></i>
        <ul class="mb-0" style="padding-left:16px;">
            <?php foreach ($errors as $error): ?>
                <li><?php echo $error; ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Profile summary + Personal information -->
<div class="apf-grid apf-grid-top">
    <div class="apf-card apf-summary">
        <div class="profile-avatar">
            <?php if ($profile_image): ?>
                <img src="<?php echo $profile_image; ?>" alt="Profile Photo">
            <?php else: ?>
                <i class="fas fa-user-circle"></i>
            <?php endif; ?>
            <div class="profile-upload-overlay" title="Change profile photo">
                <form method="POST" action="" enctype="multipart/form-data" style="position:relative;">
                    <input type="file" name="profile_image" id="apfImageInput" accept="image/*" onchange="this.form.submit()">
                    <i class="fas fa-camera"></i>
                </form>
            </div>
        </div>
        <div class="profile-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
        <div class="apf-role"><?php echo htmlspecialchars($user['role_name']); ?></div>
        <span class="apf-badge <?php echo $user['is_active'] ? 'ok' : 'off'; ?>"><?php echo $user['is_active'] ? '<i class="fas fa-check-circle"></i> Active' : 'Inactive'; ?></span>
        <div class="apf-id-box">
            <div class="apf-label">Username</div>
            <div class="apf-id-value">@<?php echo htmlspecialchars($user['username']); ?></div>
        </div>
        <label for="apfImageInput" class="apf-btn apf-btn-light"><i class="fas fa-camera"></i> Change Photo</label>
        <div class="apf-hint">JPG, PNG, GIF or WEBP &middot; Max 2MB</div>
    </div>

    <div class="apf-card">
        <div class="apf-card-head">
            <h5><span class="apf-ic"><i class="fas fa-id-card"></i></span> Personal Information</h5>
            <button type="button" class="apf-btn apf-btn-outline" id="toggleEditBtn" onclick="toggleEdit()"><i class="fas fa-pen"></i> Edit Profile</button>
        </div>

        <!-- View Mode -->
        <div id="viewMode">
            <div class="apf-fields">
                <div class="apf-field"><div class="apf-label"><i class="fas fa-user"></i> Full Name</div><div class="apf-value"><?php echo htmlspecialchars(trim($user['first_name'] . ' ' . ($user['middle_name'] ?? '') . ' ' . $user['last_name'])); ?></div></div>
                <div class="apf-field"><div class="apf-label"><i class="fas fa-venus-mars"></i> Gender</div><div class="apf-value"><?php echo $apf_val($user['sex'] ?? '', 'Not specified'); ?></div></div>
                <div class="apf-field"><div class="apf-label"><i class="fas fa-envelope"></i> Email</div><div class="apf-value"><?php echo $apf_val($user['email'] ?? ''); ?></div></div>
                <div class="apf-field"><div class="apf-label"><i class="fas fa-phone"></i> Contact Number</div><div class="apf-value"><?php echo $apf_val($user['contact_number'] ?? ''); ?></div></div>
            </div>
        </div>

        <!-- Edit Mode (same form and backend as before; password moved to its own card below) -->
        <div id="editMode" style="display:none;">
            <div class="profile-edit-notice"><i class="fas fa-info-circle me-1"></i> Update your account information below. To change your password, use the Change Password section.</div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="update_profile" value="1">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="profile-form-label">First Name</label>
                        <input type="text" name="first_name" class="profile-form-input" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="profile-form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="profile-form-input" value="<?php echo htmlspecialchars($user['middle_name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="profile-form-label">Last Name</label>
                        <input type="text" name="last_name" class="profile-form-input" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="profile-form-label">Email</label>
                        <input type="email" name="email" class="profile-form-input" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button type="button" class="profile-btn-cancel" onclick="toggleEdit()"><i class="fas fa-times me-1"></i> Cancel</button>
                    <button type="submit" class="profile-btn-edit"><i class="fas fa-save me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($user['role_name'] === 'Teacher'):
    // Department / Level exactly as registered (users.department_level) -- display only.
    $apf_dept = strtoupper(trim((string)($user['department_level'] ?? '')));
    $apf_dept_label = $apf_dept === 'JHS' ? 'JHS &mdash; Grades 7&ndash;10' : ($apf_dept === 'SHS' ? 'SHS &mdash; Grades 11&ndash;12' : '');
?>
<!-- Professional Information (teachers only -- same data as before) -->
<div class="apf-card">
    <div class="apf-card-head">
        <h5><span class="apf-ic"><i class="fas fa-chalkboard-teacher"></i></span> Professional Information</h5>
    </div>
    <div class="apf-fields">
        <div class="apf-field"><div class="apf-label"><i class="fas fa-briefcase"></i> Position / Designation</div><div class="apf-value"><?php echo $apf_val($user['position_designation'] ?? '', 'Not specified'); ?></div></div>
        <div class="apf-field"><div class="apf-label"><i class="fas fa-flask"></i> Specialization / Subject Area</div><div class="apf-value"><?php echo $apf_val($user['specialization'] ?? '', 'Not specified'); ?></div></div>
        <div class="apf-field"><div class="apf-label"><i class="fas fa-graduation-cap"></i> Degree / Course</div><div class="apf-value"><?php echo $apf_val($user['degree_course'] ?? '', 'Not specified'); ?></div></div>
        <div class="apf-field"><div class="apf-label"><i class="fas fa-book-reader"></i> Highest Educational Attainment</div><div class="apf-value"><?php echo $apf_val($user['highest_educ_attainment'] ?? '', 'Not specified'); ?></div></div>
        <div class="apf-field"><div class="apf-label"><i class="fas fa-id-badge"></i> PRC License Number</div><div class="apf-value"><?php echo $apf_val($user['prc_license_number'] ?? ''); ?></div></div>
        <div class="apf-field"><div class="apf-label"><i class="fas fa-hourglass-half"></i> Years of Teaching Experience</div><div class="apf-value"><?php echo (int)($user['years_experience'] ?? 0); ?> <?php echo (int)($user['years_experience'] ?? 0) === 1 ? 'year' : 'years'; ?></div></div>
        <div class="apf-field"><div class="apf-label"><i class="fas fa-school"></i> Department / Level</div><div class="apf-value"><?php echo $apf_dept_label !== '' ? '<span class="apf-badge dept">' . $apf_dept_label . '</span>' : '<span class="apf-empty">Not set</span>'; ?></div></div>
        <div class="apf-field"><div class="apf-label"><i class="fas fa-certificate"></i> Qualification Status</div><div class="apf-value"><?php $apf_q = $user['qualification_status'] ?? 'Pending'; ?><span class="apf-badge <?php echo $apf_q === 'Qualified' ? 'ok' : ($apf_q === 'Not Qualified' ? 'off' : 'wait'); ?>"><?php echo htmlspecialchars($apf_q); ?></span></div></div>
    </div>

    <!-- Teaching Assignments: the teacher's current assignments (teacher_subjects, active school year) -->
    <div class="apf-sub-head">
        <span><i class="fas fa-chalkboard"></i> Teaching Assignments</span>
        <span class="apf-count"><?php echo count($my_classes); ?> class<?php echo count($my_classes) === 1 ? '' : 'es'; ?></span>
    </div>
    <?php if (!empty($my_classes)): ?>
        <div class="apf-assign-list">
            <?php foreach ($my_classes as $c): ?>
                <div class="apf-assign">
                    <span class="apf-assign-subj"><i class="fas fa-book"></i> <?php echo htmlspecialchars($c['subject_name']); ?></span>
                    <span class="apf-badge sec"><?php echo htmlspecialchars($c['grade_level'] . ' – ' . $c['section_name']); ?><?php echo !empty($c['strand']) ? ' · ' . htmlspecialchars($c['strand']) : ''; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="apf-empty">No classes assigned yet.</div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Account Information -->
<div class="apf-card">
    <div class="apf-card-head">
        <h5><span class="apf-ic"><i class="fas fa-shield-alt"></i></span> Account Information</h5>
    </div>
    <div class="apf-fields">
        <div class="apf-field"><div class="apf-label"><i class="fas fa-user-tag"></i> Username</div><div class="apf-value">@<?php echo htmlspecialchars($user['username']); ?></div></div>
        <div class="apf-field"><div class="apf-label"><i class="fas fa-user-shield"></i> Account Type</div><div class="apf-value"><?php echo htmlspecialchars($user['role_name']); ?></div></div>
        <div class="apf-field"><div class="apf-label"><i class="fas fa-toggle-on"></i> Account Status</div><div class="apf-value"><span class="apf-badge <?php echo $user['is_active'] ? 'ok' : 'off'; ?>"><?php echo $user['is_active'] ? '<i class="fas fa-check-circle"></i> Active' : 'Inactive'; ?></span></div></div>
        <div class="apf-field"><div class="apf-label"><i class="fas fa-calendar-plus"></i> Date Created</div><div class="apf-value"><?php echo !empty($user['created_at']) ? date('F d, Y', strtotime($user['created_at'])) : '<span class="apf-empty">N/A</span>'; ?></div></div>
        <div class="apf-field"><div class="apf-label"><i class="fas fa-clock"></i> Last Login</div><div class="apf-value"><?php echo $user['last_login'] ? date('F d, Y h:i A', strtotime($user['last_login'])) : 'Never'; ?></div></div>
    </div>
</div>

<!-- Change Password: posts to the SAME profile-update handler above (current
     name/email are sent unchanged, so only the password changes). -->
<div class="apf-card">
    <div class="apf-card-head">
        <h5><span class="apf-ic"><i class="fas fa-lock"></i></span> Change Password</h5>
    </div>
    <form method="POST" action="" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="update_profile" value="1">
        <input type="hidden" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>">
        <input type="hidden" name="middle_name" value="<?php echo htmlspecialchars($user['middle_name'] ?? ''); ?>">
        <input type="hidden" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>">
        <input type="hidden" name="email" value="<?php echo htmlspecialchars($user['email']); ?>">
        <div class="apf-pw-grid">
            <div>
                <label class="apf-form-label" for="apfCurPw">Current Password</label>
                <div class="apf-pw"><input type="password" id="apfCurPw" name="current_password" class="profile-form-input" placeholder="Enter current password" required autocomplete="current-password"><button type="button" class="apf-eye" aria-label="Show password"><i class="fas fa-eye"></i></button></div>
            </div>
            <div>
                <label class="apf-form-label" for="apfNewPw">New Password</label>
                <div class="apf-pw"><input type="password" id="apfNewPw" name="new_password" class="profile-form-input" placeholder="Min 6 characters" required minlength="6" autocomplete="new-password"><button type="button" class="apf-eye" aria-label="Show password"><i class="fas fa-eye"></i></button></div>
            </div>
            <div>
                <label class="apf-form-label" for="apfConfPw">Confirm New Password</label>
                <div class="apf-pw"><input type="password" id="apfConfPw" name="confirm_password" class="profile-form-input" placeholder="Confirm new password" required minlength="6" autocomplete="new-password"><button type="button" class="apf-eye" aria-label="Show password"><i class="fas fa-eye"></i></button></div>
            </div>
        </div>
        <div class="apf-pw-foot">
            <small><i class="fas fa-shield-halved me-1"></i> Use at least 6 characters. Enter your current password to confirm the change.</small>
            <button type="submit" class="apf-btn apf-btn-primary"><i class="fas fa-save"></i> Save Changes</button>
        </div>
    </form>
</div>

<script>
function toggleEdit() {
    var viewMode = document.getElementById('viewMode');
    var editMode = document.getElementById('editMode');
    var btn = document.getElementById('toggleEditBtn');
    if (editMode.style.display === 'none') {
        viewMode.style.display = 'none';
        editMode.style.display = 'block';
        btn.innerHTML = '<i class="fas fa-times"></i> Cancel';
    } else {
        viewMode.style.display = 'block';
        editMode.style.display = 'none';
        btn.innerHTML = '<i class="fas fa-pen"></i> Edit Profile';
    }
}
// Show / hide password (display only).
document.querySelectorAll('.apf-eye').forEach(function (b) {
    b.addEventListener('click', function () {
        var input = b.parentNode.querySelector('input');
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        b.innerHTML = show ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
        b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
});
</script>

<?php include 'includes/footer.php'; ?>