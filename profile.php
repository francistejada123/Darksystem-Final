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

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0"><i class="fas fa-user-circle text-primary me-2"></i>My Profile</h2>
        </div>
    </div>
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

<div class="profile-row-cards mb-4">
    <!-- Profile Summary -->
    <div class="card shadow-sm rounded-3" style="flex: 0 0 320px;">
        <div class="card-body text-center">
            <div class="profile-avatar">
                <?php if ($profile_image): ?>
                    <img src="<?php echo $profile_image; ?>" alt="Profile Photo">
                <?php else: ?>
                    <i class="fas fa-user-circle"></i>
                <?php endif; ?>
                <div class="profile-upload-overlay">
                    <form method="POST" action="" enctype="multipart/form-data" style="position:relative;">
                        <input type="file" name="profile_image" accept="image/*" onchange="this.form.submit()">
                        <i class="fas fa-camera"></i>
                    </form>
                </div>
            </div>
            <div class="profile-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
            <div class="profile-meta"><?php echo htmlspecialchars($user['role_name']); ?></div>
            <span class="profile-status-pill <?php echo $user['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $user['is_active'] ? '✓ Active' : 'Inactive'; ?></span>
            <div class="profile-summary-divider"></div>
            <div class="profile-summary-id-label">Username</div>
            <div class="profile-summary-id-value">@<?php echo htmlspecialchars($user['username']); ?></div>
            <div class="profile-hint"><i class="fas fa-camera me-1"></i> Click the camera icon to upload &middot; JPG, PNG, GIF, WEBP &middot; Max 2MB</div>
        </div>
    </div>

    <!-- Personal Information (merged with contact details) -->
    <div class="card shadow-sm rounded-3">
        <div class="profile-card-header">
            <span><i class="fas fa-id-card me-1"></i> Personal Information</span>
            <button type="button" class="profile-btn-edit-outline" id="toggleEditBtn" onclick="toggleEdit()"><i class="fas fa-pen me-1"></i> Edit Profile</button>
        </div>
        <div class="card-body">

            <!-- View Mode -->
            <div id="viewMode">
                <div class="profile-info-row"><span class="profile-info-label"><i class="fas fa-user profile-info-icon"></i>Full Name</span><span class="profile-info-value"><?php echo htmlspecialchars(trim($user['first_name'] . ' ' . ($user['middle_name'] ?? '') . ' ' . $user['last_name'])); ?></span></div>
                <div class="profile-info-row"><span class="profile-info-label"><i class="fas fa-venus-mars profile-info-icon"></i>Gender</span><span class="profile-info-value"><?php echo htmlspecialchars($user['sex'] ?? 'Not specified'); ?></span></div>
                <div class="profile-info-row"><span class="profile-info-label"><i class="fas fa-envelope profile-info-icon"></i>Email</span><span class="profile-info-value"><?php echo htmlspecialchars($user['email']); ?></span></div>
                <div class="profile-info-row"><span class="profile-info-label"><i class="fas fa-phone profile-info-icon"></i>Contact Number</span><span class="profile-info-value"><?php echo htmlspecialchars($user['contact_number'] ?? 'Not provided'); ?></span></div>
            </div>

            <!-- Edit Mode -->
            <div id="editMode" style="display:none;">
                <div class="profile-edit-notice"><i class="fas fa-info-circle me-1"></i> Update your account information below. Leave the password fields blank if you don't want to change your password.</div>
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

                    <div class="profile-section-label" style="margin-top:20px;"><i class="fas fa-lock"></i> Change Password</div>
                    <p class="text-muted small mb-3">Leave blank if you don't want to change your password.</p>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="profile-form-label">Current Password</label>
                            <input type="password" name="current_password" class="profile-form-input" placeholder="Enter current password">
                        </div>
                        <div class="col-md-4">
                            <label class="profile-form-label">New Password</label>
                            <input type="password" name="new_password" class="profile-form-input" placeholder="Min 6 characters">
                        </div>
                        <div class="col-md-4">
                            <label class="profile-form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="profile-form-input" placeholder="Confirm new password">
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
</div>

<?php if ($user['role_name'] === 'Teacher'): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm rounded-3">
            <div class="profile-card-header"><span><i class="fas fa-chalkboard-teacher me-1"></i> Professional Information</span></div>
            <div class="card-body">
                <div class="profile-account-grid">
                    <div class="profile-info-row"><span class="profile-info-label"><i class="fas fa-briefcase profile-info-icon"></i>Position</span><span class="profile-info-value"><?php echo htmlspecialchars($user['position_designation'] ?? 'Not specified'); ?></span></div>
                    <div class="profile-info-row"><span class="profile-info-label"><i class="fas fa-flask profile-info-icon"></i>Specialization</span><span class="profile-info-value"><?php echo htmlspecialchars($user['specialization'] ?? 'Not specified'); ?></span></div>
                    <div class="profile-info-row"><span class="profile-info-label"><i class="fas fa-graduation-cap profile-info-icon"></i>Degree/Course</span><span class="profile-info-value"><?php echo htmlspecialchars($user['degree_course'] ?? 'Not specified'); ?></span></div>
                    <div class="profile-info-row"><span class="profile-info-label"><i class="fas fa-book-reader profile-info-icon"></i>Highest Educ. Attainment</span><span class="profile-info-value"><?php echo htmlspecialchars($user['highest_educ_attainment'] ?? 'Not specified'); ?></span></div>
                    <div class="profile-info-row"><span class="profile-info-label"><i class="fas fa-id-badge profile-info-icon"></i>PRC License No.</span><span class="profile-info-value"><?php echo htmlspecialchars($user['prc_license_number'] ?? 'Not provided'); ?></span></div>
                    <div class="profile-info-row"><span class="profile-info-label"><i class="fas fa-hourglass-half profile-info-icon"></i>Years of Experience</span><span class="profile-info-value"><?php echo (int)($user['years_experience'] ?? 0); ?></span></div>
                    <div class="profile-info-row"><span class="profile-info-label"><i class="fas fa-certificate profile-info-icon"></i>Qualification Status</span><span class="profile-info-value"><?php echo htmlspecialchars($user['qualification_status'] ?? 'Pending'); ?></span></div>
                    <div class="profile-info-row"><span class="profile-info-label"><i class="fas fa-user-check profile-info-icon"></i>SHS-Qualified</span><span class="profile-info-value"><?php echo !empty($user['can_handle_shs']) ? 'Yes' : 'No'; ?></span></div>
                </div>
                <div class="profile-info-row" style="border-top:1px solid var(--border-soft); margin-top:4px; padding-top:14px;">
                    <span class="profile-info-label"><i class="fas fa-chalkboard profile-info-icon"></i>Assigned Subjects</span>
                    <span class="profile-info-value">
                        <?php if (!empty($my_classes)):
                            $class_lines = [];
                            foreach ($my_classes as $c) {
                                $class_lines[] = htmlspecialchars($c['subject_name'] . ' — ' . $c['grade_level'] . ' ' . $c['section_name']);
                            }
                            echo implode('<br>', $class_lines);
                        else: ?>
                            No classes assigned yet.
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Account Information -->
<div class="row">
    <div class="col-12">
        <div class="card shadow-sm rounded-3">
            <div class="profile-card-header"><span><i class="fas fa-shield-alt me-1"></i> Account Information</span></div>
            <div class="card-body">
                <div class="profile-account-grid">
                    <div class="profile-info-row"><span class="profile-info-label"><i class="fas fa-user-tag profile-info-icon"></i>Username</span><span class="profile-info-value">@<?php echo htmlspecialchars($user['username']); ?></span></div>
                    <div class="profile-info-row"><span class="profile-info-label"><i class="fas fa-calendar-plus profile-info-icon"></i>Date Created</span><span class="profile-info-value"><?php echo date('F d, Y', strtotime($user['created_at'])); ?></span></div>
                    <div class="profile-info-row"><span class="profile-info-label"><i class="fas fa-user-shield profile-info-icon"></i>Account Type</span><span class="profile-info-value"><?php echo htmlspecialchars($user['role_name']); ?></span></div>
                    <div class="profile-info-row"><span class="profile-info-label"><i class="fas fa-clock profile-info-icon"></i>Last Login</span><span class="profile-info-value"><?php echo $user['last_login'] ? date('F d, Y h:i A', strtotime($user['last_login'])) : 'Never'; ?></span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleEdit() {
    var viewMode = document.getElementById('viewMode');
    var editMode = document.getElementById('editMode');
    var btn = document.getElementById('toggleEditBtn');
    if (editMode.style.display === 'none') {
        viewMode.style.display = 'none';
        editMode.style.display = 'block';
        btn.innerHTML = '<i class="fas fa-times me-1"></i> Cancel';
    } else {
        viewMode.style.display = 'block';
        editMode.style.display = 'none';
        btn.innerHTML = '<i class="fas fa-pen me-1"></i> Edit';
    }
}
</script>

<?php include 'includes/footer.php'; ?>