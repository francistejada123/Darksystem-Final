<?php
// modules/students/portal/profile.php
// Student Portal - My Profile with Edit Functionality

session_start();
require_once '../../../config/database.php';
require_once '../../../includes/attendance_qr_helpers.php'; // attQrLrnLink() -- tap the LRN to show your attendance QR code

if (!isset($_SESSION['student_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../../../student_login.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'];
$lrn = $_SESSION['student_lrn'];

$errors = [];
$success = '';
$upload_error = '';
$upload_success = '';

// Get student data
$student = getRecord("
    SELECT s.*, sec.section_name, sec.adviser_id, sy.sy_name,
           CONCAT(adv.last_name, ', ', adv.first_name) AS adviser_name
    FROM students s
    LEFT JOIN sections sec ON s.section_id = sec.section_id
    LEFT JOIN users adv ON sec.adviser_id = adv.user_id
    LEFT JOIN school_years sy ON s.school_year_id = sy.sy_id
    WHERE s.student_id = ?
", [$student_id]);

if (!$student) {
    header('Location: dashboard.php');
    exit;
}

// Handle profile photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_image'])) {
    $file = $_FILES['profile_image'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $max_size = 2 * 1024 * 1024;
    
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
                $filename = 'profile_' . $student_id . '_' . time() . '.' . $ext;
                $upload_path = '../../../static/uploads/profiles/' . $filename;
                
                if (!is_dir('../../../static/uploads/profiles/')) {
                    mkdir('../../../static/uploads/profiles/', 0777, true);
                }
                
                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    $old = getRecord("SELECT profile_image FROM students WHERE student_id = ?", [$student_id]);
                    if ($old && !empty($old['profile_image']) && file_exists('../../../static/uploads/profiles/' . $old['profile_image'])) {
                        unlink('../../../static/uploads/profiles/' . $old['profile_image']);
                    }
                    executeQuery("UPDATE students SET profile_image = ? WHERE student_id = ?", [$filename, $student_id]);
                    $_SESSION['profile_image'] = $filename;
                    $upload_success = 'Profile photo updated successfully!';
                    $student = getRecord("SELECT * FROM students WHERE student_id = ?", [$student_id]);
                } else {
                    $upload_error = 'Failed to upload image. Please try again.';
                }
            }
        }
    }
}

// A correction of official information (name, birth date, gender): a request the Registrar approves.
require_once '../../../includes/student_records_helpers.php';
$sr_student_fields = array_intersect_key(srCorrectionFields(), array_flip(['first_name', 'middle_name', 'last_name', 'birth_date', 'gender']));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_correction'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } elseif (!isset($sr_student_fields[$_POST['field_name'] ?? ''])) {
        $errors[] = 'Choose which information to correct.';
    } else {
        $sr = srRequestCorrection($student_id, $_POST['field_name'], (string) ($_POST['new_value'] ?? ''), (string) ($_POST['reason'] ?? ''), 'Student', $student_id);
        if ($sr['success']) {
            $success = 'Your correction request was sent. The Registrar will review it; your record changes once it is approved.';
        } else {
            $errors[] = htmlspecialchars($sr['error']);
        }
    }
}

// Change password (current password + new one; nobody else can see or set it)
require_once __DIR__ . '/../../../includes/student_password.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    spwEnsureSchema();
    $pw_st = getRecord("SELECT student_id, lrn, portal_password_hash, portal_failed_logins, portal_locked_until FROM students WHERE student_id = ?", [(int) $_SESSION['student_id']]);
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } elseif (!$pw_st || !spwVerify($pw_st, $_POST['current_password'] ?? '')) {
        $errors[] = 'Your current password is not correct.';
    } elseif (($pw_problem = spwProblem($_POST['new_password'] ?? '', $_POST['confirm_password'] ?? '', $pw_st['lrn'])) !== '') {
        $errors[] = $pw_problem;
    } else {
        spwSet($pw_st['student_id'], $_POST['new_password']);
        logActivity(null, 'student', 'Changed portal password', 'Student ' . $pw_st['lrn']);
        $success = 'Your password was changed.';
    }
}

// Parent Portal: see who is linked; make a one-time code to give to a parent.
require_once __DIR__ . '/../../../includes/parent_portal.php';
ppEnsureSchema();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['parent_code']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $pp_st = getRecord("SELECT guardian_contact FROM students WHERE student_id = ?", [(int) $student_id]);
    $_SESSION['pp_student_code'] = ppIssueCode((int) $student_id, 'student', $pp_st['guardian_contact'] ?? null);
    logActivity(null, 'student', 'Parent code created', 'Student #' . (int) $student_id . ' made a Parent Portal code');
}
$pp_code = (!empty($_SESSION['pp_student_code']) && ppFindCode($_SESSION['pp_student_code'])) ? $_SESSION['pp_student_code'] : null;
$pp_linked = ppParentsOf((int) $student_id);

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $contact_number = sanitize($_POST['contact_number'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $guardian_name = sanitize($_POST['guardian_name'] ?? '');
        $guardian_contact = sanitize($_POST['guardian_contact'] ?? '');
        
        if (!empty($email) && !validateEmail($email)) {
            $errors[] = 'Invalid email address.';
        }
        if (!empty($email) && $email !== $student['email']) {
            $check = getRecord("SELECT student_id FROM students WHERE email = ? AND student_id != ?", [$email, $student_id]);
            if ($check) $errors[] = 'Email already exists in the system.';
        }
        
        if (empty($errors)) {
            $sql = "UPDATE students SET contact_number = ?, email = ?, address = ?, guardian_name = ?, guardian_contact = ? WHERE student_id = ?";
            $params = [$contact_number, $email, $address, $guardian_name, $guardian_contact, $student_id];
            $result = executeQuery($sql, $params);
            
            if ($result) {
                $success = 'Profile updated successfully!';
                $student = getRecord("SELECT * FROM students WHERE student_id = ?", [$student_id]);
                $_SESSION['student_name'] = $student['first_name'] . ' ' . $student['last_name'];
            } else {
                $errors[] = 'Failed to update profile. Please try again.';
            }
        }
    }
}

// Check profile image
$profile_image = '';
if (!empty($student['profile_image']) && file_exists('../../../static/uploads/profiles/' . $student['profile_image'])) {
    $profile_image = '../../../static/uploads/profiles/' . $student['profile_image'];
}

$csrf_token = generateCSRFToken();
$page_title = 'My Profile';
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
    <title>My Profile - EGRADEPRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../../../static/css/style.css" rel="stylesheet">
    <link href="../../../static/css/portal-theme.css" rel="stylesheet">
    <link href="../../../static/css/student-portal-classroom-ui.css?v=<?php echo (int) @filemtime(__DIR__ . '/../../../static/css/student-portal-classroom-ui.css'); ?>" rel="stylesheet">
    <link href="../../../static/css/theme.css?v=<?php echo (int) @filemtime(__DIR__ . '/../../../static/css/theme.css'); ?>" rel="stylesheet">
    <script src="../../../static/js/student-portal-shell.js"></script>
    <script src="../../../static/js/theme.js"></script>
    <style>
        :root {
            --portal-bg: #F1F4F8;
            --portal-primary: #14213D;
            --portal-card: #ffffff;
            --portal-border: #e5e7eb;
            --portal-green: #238863;
            --portal-red: #C94A4A;
            --portal-yellow: #B7791F;
            --portal-gray: #667085;
        }
        * { box-sizing: border-box; }
        body { background: var(--portal-bg); font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; }

        .sidebar { background: var(--portal-primary) !important; min-height: 100vh; padding: 0; display: flex; flex-direction: column; }
        .sidebar .logo { padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; gap: 10px; }
        .sidebar .logo img { height: 36px; width: 36px; border-radius: 8px; object-fit: contain; }
        .sidebar .logo span { color: #fff; font-size: 15px; font-weight: 700; letter-spacing: 0.02em; line-height: 1.3; }
        .sidebar .logo small { display: block; font-size: 9px; font-weight: 500; letter-spacing: 0.1em; color: rgba(255,255,255,0.4); }
        .sidebar .nav { padding: 12px 0; flex: 1; }
        .sidebar .nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 20px; color: rgba(255,255,255,0.55); font-size: 13px; font-weight: 500; text-decoration: none; transition: all 0.15s ease; border-left: 3px solid transparent; }
        .sidebar .nav-link i { width: 18px; text-align: center; font-size: 14px; }
        .sidebar .nav-link:hover { color: #fff; background: rgba(255,255,255,0.06); }
        .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,0.08); border-left-color: #fff; }
        .sidebar .nav-link.text-danger { color: rgba(201,74,74,0.7); }
        .sidebar .nav-link.text-danger:hover { color: #C94A4A; background: rgba(201,74,74,0.08); }
        .sidebar .nav-divider { height: 1px; background: rgba(255,255,255,0.08); margin: 8px 20px; }
        .sidebar-footer { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.08); }
        .sidebar-footer small { color: rgba(255,255,255,0.3); font-size: 11px; }

        .main-content { padding: 24px 32px; min-height: 100vh; }
        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; }
        .topbar-left { display: flex; align-items: center; gap: 14px; }
        .topbar-right { display: flex; align-items: center; gap: 10px; }
        .topbar h4 { margin: 0; font-size: 20px; font-weight: 700; color: var(--portal-primary); }
        .topbar small { color: var(--portal-gray); font-size: 12px; }

        .card-portal { background: var(--portal-card); border: 1px solid var(--portal-border); border-radius: 8px; margin-bottom: 20px; }
        .card-portal-header { padding: 16px 20px; border-bottom: 1px solid var(--portal-border); font-size: 14px; font-weight: 600; color: var(--portal-primary); display: flex; align-items: center; justify-content: space-between; }
        .card-portal-body { padding: 20px; }

        .profile-card { text-align: center; padding: 24px 20px; }
        .profile-avatar { width: 120px; height: 120px; border-radius: 50%; margin: 0 auto 16px; position: relative; overflow: hidden; background: #f1f5f9; display: flex; align-items: center; justify-content: center; border: 3px solid var(--portal-border); }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .profile-avatar i { font-size: 48px; color: var(--portal-gray); }
        .upload-overlay { position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.5); padding: 6px; text-align: center; cursor: pointer; }
        .upload-overlay:hover { background: rgba(0,0,0,0.65); }
        .upload-overlay i { color: #fff; font-size: 14px; }
        .upload-overlay input { position: absolute; opacity: 0; width: 100%; height: 100%; top: 0; left: 0; cursor: pointer; }
        .profile-name { font-size: 18px; font-weight: 700; color: var(--portal-primary); margin-bottom: 4px; }
        .profile-lrn { font-size: 13px; color: var(--portal-gray); margin-bottom: 12px; }
        .profile-status { display: inline-block; padding: 3px 12px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .profile-status.enrolled { background: rgba(35,136,99,0.08); color: var(--portal-green); }
        .profile-status.pending { background: rgba(183,121,31,0.1); color: var(--portal-yellow); }
        .profile-strand { display: inline-block; padding: 3px 12px; border-radius: 4px; font-size: 12px; font-weight: 600; background: #F2F4F7; color: #344054; border: 1px solid #E4E7EC; margin-top: 6px; }
        .profile-hint { font-size: 11px; color: var(--portal-gray); margin-top: 16px; }

        .info-row { display: flex; padding: 10px 0; border-bottom: 1px solid #f3f4f6; }
        .info-row:last-child { border-bottom: none; }
        .info-label { width: 140px; font-size: 13px; font-weight: 500; color: var(--portal-gray); flex-shrink: 0; }
        .info-value { font-size: 13px; color: #374151; font-weight: 500; }

        .section-label { font-size: 16px; font-weight: 600; color: var(--portal-primary); margin: 24px 0 10px; padding-bottom: 8px; border-bottom: 1px solid var(--portal-border); display: flex; align-items: center; gap: 6px; }
        .section-label:first-of-type { margin-top: 0; }

        .btn-edit { padding: 7px 16px; border-radius: 6px; font-size: 12px; font-weight: 500; border: none; background: var(--portal-primary); color: #fff; cursor: pointer; transition: all 0.15s; }
        .btn-edit:hover { background: #1a2f54; }
        .btn-cancel { padding: 7px 16px; border-radius: 6px; font-size: 12px; font-weight: 500; border: 1px solid var(--portal-border); background: #fff; color: var(--portal-gray); cursor: pointer; transition: all 0.15s; }
        .btn-cancel:hover { background: #f1f5f9; }

        .edit-notice { padding: 12px 16px; background: rgba(183,121,31,0.06); border-left: 3px solid var(--portal-yellow); border-radius: 0 6px 6px 0; font-size: 13px; color: #92700c; margin-bottom: 20px; }

        .form-label-custom { font-size: 12px; font-weight: 500; color: var(--portal-gray); margin-bottom: 4px; display: block; }
        .form-input-custom { width: 100%; padding: 8px 12px; border: 1px solid var(--portal-border); border-radius: 6px; font-size: 13px; color: #374151; background: #fff; transition: border-color 0.15s; }
        .form-input-custom:focus { outline: none; border-color: var(--portal-primary); box-shadow: 0 0 0 2px rgba(20,33,61,0.06); }
        textarea.form-input-custom { resize: vertical; min-height: 60px; }

        .alert-portal { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .alert-portal.success { background: rgba(35,136,99,0.06); color: var(--portal-green); border: 1px solid rgba(35,136,99,0.15); }
        .alert-portal.danger { background: rgba(201,74,74,0.06); color: var(--portal-red); border: 1px solid rgba(201,74,74,0.15); }

        /* Profile page — multi-card grid layout, per-field icons, account info grid */
        .profile-row-cards { display: flex; align-items: flex-start; gap: 20px; }
        .profile-row-cards > .card-portal { flex: 1; min-width: 0; margin-bottom: 20px; }
        .profile-summary-divider { height: 1px; background: var(--portal-border); margin: 16px 0; }
        .profile-summary-id-label { font-size: 11px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--portal-gray); margin-bottom: 4px; }
        .profile-summary-id-value { font-size: 16px; font-weight: 700; color: var(--portal-primary); margin-bottom: 14px; }
        .info-row .info-icon { width: 18px; display: inline-flex; justify-content: center; color: var(--portal-gray); font-size: 12px; margin-right: 8px; }
        .btn-edit-outline { padding: 6px 16px; border-radius: 20px; font-size: 12px; font-weight: 600; border: 1px solid #356EA8; background: #fff; color: #356EA8; cursor: pointer; transition: all 0.15s; white-space: nowrap; }
        .btn-edit-outline:hover { background: rgba(53,110,168,0.08); }
        .account-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 32px; }
        .profile-empty-note { font-size: 13px; color: var(--portal-gray); font-style: italic; padding: 10px 0; }

        @media (max-width: 900px) {
            .profile-row-cards { flex-direction: column; }
            .profile-row-cards > .card-portal { flex: 1 1 100% !important; width: 100%; }
            .account-info-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .sidebar { position: fixed !important; top: 0; left: -280px; width: 260px; height: 100vh; z-index: 1050; transition: left 0.25s ease; overflow-y: auto; box-shadow: 4px 0 24px rgba(0,0,0,0.15); }
            .sidebar.show { left: 0; }
            .mobile-menu-btn { display: inline-flex !important; }
            .main-content { padding: 16px !important; margin-left: 0 !important; }
            .topbar { flex-wrap: wrap; gap: 10px; }
            .topbar h4 { font-size: 16px !important; }
            .profile-avatar { width: 90px; height: 90px; }
            .profile-avatar i { font-size: 36px; }
        }
        @media (min-width: 769px) {
            .mobile-menu-btn { display: none !important; }
            .sidebar-overlay { display: none !important; }
            .sidebar { display: flex !important; position: fixed !important; top: 0 !important; left: 0 !important; width: var(--sidebar-width, 240px) !important; height: 100vh !important; overflow-y: auto !important; }
            .main-content { margin-left: var(--sidebar-width, 240px) !important; width: calc(100% - var(--sidebar-width, 240px)) !important; max-width: calc(100% - var(--sidebar-width, 240px)) !important; flex: 0 0 auto !important; }
        }
    </style>
    <?php include __DIR__ . '/../../../includes/pwa_head.php'; ?>
</head>
<body>
<script>document.body.classList.add('sidebar-collapsed');</script>
<script>document.body.classList.add('sidebar-collapsed');</script>
<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-2 sidebar" id="mainSidebar">
            <div class="logo">
                <img src="../../../static/images/logo.png" alt="Logo" onerror="this.style.display='none'">
                <span>EGRADEPRO<small>STUDENT PORTAL</small></span>
            </div>
            <nav class="nav flex-column">
                <a href="dashboard.php" class="nav-link"><i class="fas fa-th-large"></i> Dashboard</a>
                <a href="grades.php" class="nav-link"><i class="fas fa-chart-bar"></i> My Grades</a>
                <a href="tutorial.php" class="nav-link"><i class="fas fa-play-circle"></i> My Tutorials</a>
                <a href="enrollment.php" class="nav-link"><i class="fas fa-clipboard-check"></i> Enrollment</a>
            </nav>
            <div class="sidebar-footer"><small>&copy; <?php echo date('Y'); ?></small></div>
        </div>

        <!-- Main Content -->
        <div class="col-md-10 main-content">
            <!-- Top Bar -->
            <div class="topbar">
                <div class="topbar-left">
                    <button class="mobile-menu-btn btn btn-sm" onclick="toggleSidebar()" style="display:none;border:1px solid var(--portal-border);background:#fff;border-radius:6px;padding:6px 10px;"><i class="fas fa-bars"></i></button>
                    <div>
                        <h4>My Profile</h4>
                        <small>View and manage your personal, academic, and account information</small>
                    </div>
                </div>
                <div class="topbar-right">
                    <button type="button" class="theme-toggle-btn" id="themeToggleBtn" aria-label="Switch to dark mode" title="Switch to dark mode" aria-pressed="false">
                        <i class="fas fa-sun theme-toggle-icon-sun" aria-hidden="true"></i>
                        <i class="fas fa-moon theme-toggle-icon-moon" aria-hidden="true"></i>
                    </button>
                    <div class="app-topbar-profile" id="appTopbarProfile">
                        <button type="button" class="app-topbar-profile-btn" id="appTopbarProfileBtn" onclick="toggleProfileMenu()" aria-haspopup="true" aria-expanded="false">
                            <div class="app-topbar-avatar">
                                <?php if ($profile_image): ?>
                                    <img src="<?php echo $profile_image; ?>" alt="">
                                <?php else: ?>
                                    <i class="fas fa-user"></i>
                                <?php endif; ?>
                            </div>
                            <span class="app-topbar-profile-text">
                                <strong><?php echo htmlspecialchars($student_name); ?></strong>
                                <small>Student</small>
                            </span>
                            <i class="fas fa-chevron-down app-topbar-chevron"></i>
                        </button>
                        <div class="app-topbar-dropdown" id="appTopbarDropdown">
                            <a href="profile.php"><i class="fas fa-user"></i> My Profile</a>
                            <div class="app-topbar-dropdown-divider"></div>
                            <a href="../../../logout.php" class="text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($upload_success): ?>
                <div class="alert-portal success"><i class="fas fa-check-circle"></i> <?php echo $upload_success; ?></div>
            <?php endif; ?>
            <?php if ($upload_error): ?>
                <div class="alert-portal danger"><i class="fas fa-exclamation-circle"></i> <?php echo $upload_error; ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert-portal success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="alert-portal danger"><i class="fas fa-exclamation-circle"></i> <ul style="margin:0;padding-left:16px;"><?php foreach ($errors as $error): ?><li><?php echo $error; ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <div class="profile-row-cards">
                <!-- Profile Summary -->
                <div class="card-portal" style="flex: 0 0 320px;">
                    <div class="card-portal-body profile-card">
                        <div class="profile-avatar">
                            <?php if ($profile_image): ?>
                                <img src="<?php echo $profile_image; ?>" alt="Profile Photo">
                            <?php else: ?>
                                <i class="fas fa-user-graduate"></i>
                            <?php endif; ?>
                            <div class="upload-overlay">
                                <form method="POST" action="" enctype="multipart/form-data" style="position:relative;">
                                    <input type="file" name="profile_image" accept="image/*" onchange="this.form.submit()">
                                    <i class="fas fa-camera"></i>
                                </form>
                            </div>
                        </div>
                        <div class="profile-name"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                        <div class="profile-lrn">Student</div>
                        <span class="profile-status <?php echo $student['enrollment_status'] == 'Enrolled' ? 'enrolled' : 'pending'; ?>"><?php echo $student['enrollment_status'] === 'Enrolled' ? '✓ Enrolled' : ($student['enrollment_status'] ?? 'Pending'); ?></span>
                        <?php if (!empty($student['strand'])): ?><br><span class="profile-strand"><?php echo htmlspecialchars($student['strand'] === 'ASSH' ? 'ASSH Cluster' : $student['strand']); ?></span><?php endif; ?>
                        <div class="profile-summary-divider"></div>
                        <div class="profile-summary-id-label">LRN</div>
                        <div class="profile-summary-id-value"><?php echo attQrLrnLink($student['lrn']); ?></div>
                        <?php if (attQrIsApproved($student_id)): ?><div class="profile-hint"><i class="fas fa-qrcode me-1"></i> Tap your LRN to show your attendance QR code</div><?php endif; ?>
                        <div class="profile-hint"><i class="fas fa-camera me-1"></i> Click camera to upload &middot; JPG, PNG &middot; Max 2MB</div>
                    </div>
                </div>

                <!-- Personal Information (merged with contact details) -->
                <div class="card-portal">
                    <div class="card-portal-header">
                        <span><i class="fas fa-id-card me-1"></i> Personal Information</span>
                        <button class="btn-edit-outline" id="toggleEditBtn" onclick="toggleEdit()"><i class="fas fa-pen me-1"></i> Edit Profile</button>
                    </div>
                    <div class="card-portal-body">

                        <!-- View Mode -->
                        <div id="viewMode">
                            <div class="info-row"><span class="info-label"><i class="fas fa-user info-icon"></i>Full Name</span><span class="info-value"><?php echo htmlspecialchars($student['first_name']); ?><?php if (!empty($student['middle_name'])): ?> <?php echo htmlspecialchars($student['middle_name']); ?><?php endif; ?> <?php echo htmlspecialchars($student['last_name']); ?></span></div>
                            <div class="info-row"><span class="info-label"><i class="fas fa-venus-mars info-icon"></i>Gender</span><span class="info-value"><?php echo $student['gender']; ?></span></div>
                            <div class="info-row"><span class="info-label"><i class="fas fa-birthday-cake info-icon"></i>Birth Date</span><span class="info-value"><?php echo date('F d, Y', strtotime($student['birth_date'])); ?></span></div>
                            <div class="info-row"><span class="info-label"><i class="fas fa-phone info-icon"></i>Contact Number</span><span class="info-value"><?php echo htmlspecialchars($student['contact_number'] ?? 'N/A'); ?></span></div>
                            <div class="info-row"><span class="info-label"><i class="fas fa-envelope info-icon"></i>Email</span><span class="info-value"><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></span></div>
                            <div class="info-row"><span class="info-label"><i class="fas fa-map-marker-alt info-icon"></i>Address</span><span class="info-value"><?php echo nl2br(htmlspecialchars($student['address'] ?? 'N/A')); ?></span></div>
                        </div>

                        <!-- Edit Mode -->
                        <div id="editMode" style="display: none;">
                            <div class="edit-notice"><i class="fas fa-info-circle me-1"></i> Update your contact information below.</div>
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="update_profile" value="1">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label-custom">Contact Number</label>
                                        <input type="text" name="contact_number" class="form-input-custom" value="<?php echo htmlspecialchars($student['contact_number'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-custom">Email</label>
                                        <input type="email" name="email" class="form-input-custom" value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label-custom">Address</label>
                                        <textarea name="address" class="form-input-custom" rows="2"><?php echo htmlspecialchars($student['address'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-custom">Guardian Name</label>
                                        <input type="text" name="guardian_name" class="form-input-custom" value="<?php echo htmlspecialchars($student['guardian_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-custom">Guardian Contact</label>
                                        <input type="text" name="guardian_contact" class="form-input-custom" value="<?php echo htmlspecialchars($student['guardian_contact'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end gap-2 mt-4">
                                    <button type="button" class="btn-cancel" onclick="toggleEdit()"><i class="fas fa-times me-1"></i> Cancel</button>
                                    <button type="submit" class="btn-edit"><i class="fas fa-save me-1"></i> Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Parent Portal -->
            <div class="card-portal">
                <div class="card-portal-header"><span><i class="fas fa-people-roof me-1"></i> Parent Portal</span></div>
                <div class="card-portal-body">
                    <p class="text-muted" style="font-size:14px">Your parent or guardian can see your grades, attendance and tasks (view only). Only the school can remove a linked parent.</p>
                    <?php if ($pp_linked): ?>
                        <?php foreach ($pp_linked as $pl): ?>
                            <div class="info-row"><span class="info-label"><i class="fas fa-user-shield info-icon"></i><?php echo htmlspecialchars($pl['full_name'] ?: 'Parent'); ?><?php echo $pl['relationship'] ? ' (' . htmlspecialchars($pl['relationship']) . ')' : ''; ?></span><span class="info-value"><?php echo htmlspecialchars(smsMask($pl['mobile'])); ?></span></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="profile-empty-note">No parent linked yet.</div>
                    <?php endif; ?>
                    <?php if ($pp_code): $pp_link = ppSetupLink($pp_code); ?>
                        <div style="margin-top:12px;background:#F1F5F9;border-radius:12px;padding:14px;text-align:center">
                            <div id="ppQr" data-link="<?php echo htmlspecialchars($pp_link); ?>" style="width:150px;height:150px;margin:0 auto 8px"></div>
                            <div style="font-size:13px;color:#64748b">Code</div>
                            <div style="font-size:24px;font-weight:800;letter-spacing:3px"><?php echo htmlspecialchars(ppFormatCode($pp_code)); ?></div>
                            <div style="font-size:12.5px;word-break:break-all"><?php echo htmlspecialchars($pp_link); ?></div>
                            <button type="button" class="btn btn-sm btn-primary mt-2" id="ppShare"><i class="fas fa-share me-1"></i> Share to parent</button>
                            <div style="font-size:12.5px;color:#64748b;margin-top:6px">Let your parent scan this, or send it by Messenger. They enter your birthday and make a PIN. Works once, expires in <?php echo PP_CODE_DAYS; ?> days.</div>
                        </div>
                        <script src="../../../static/js/vendor/qrcode.min.js"></script>
                        <script>
                        (function () {
                            var el = document.getElementById('ppQr'), link = el.getAttribute('data-link');
                            var qr = qrcode(0, 'M'); qr.addData(link); qr.make(); el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2 });
                            var svg = el.querySelector('svg'); if (svg) { svg.style.width = '100%'; svg.style.height = '100%'; }
                            var msg = 'EGradePro Parent Portal: ' + link + ' Code: ' + <?php echo json_encode($pp_code); ?>;
                            document.getElementById('ppShare').addEventListener('click', function () {
                                if (navigator.share) navigator.share({ title: 'EGradePro Parent Portal', text: msg }).catch(function () {});
                                else if (navigator.clipboard) { navigator.clipboard.writeText(msg); this.textContent = 'Copied!'; }
                            });
                        })();
                        </script>
                    <?php else: ?>
                        <form method="POST" class="mt-2"><input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>"><input type="hidden" name="parent_code" value="1">
                            <button class="btn btn-outline-primary"><i class="fas fa-qrcode me-1"></i> Make a code for my parent</button></form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Change Password -->
            <div class="card-portal">
                <div class="card-portal-header"><span><i class="fas fa-lock me-1"></i> Change Password</span></div>
                <div class="card-portal-body">
                    <form method="POST" action="" autocomplete="off" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="change_password" value="1">
                        <div class="col-12 col-md-4"><label class="form-label small fw-semibold mb-1">Current password</label><input type="password" name="current_password" class="form-control" required autocomplete="current-password"></div>
                        <div class="col-12 col-md-3"><label class="form-label small fw-semibold mb-1">New password</label><input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password"></div>
                        <div class="col-12 col-md-3"><label class="form-label small fw-semibold mb-1">Confirm new password</label><input type="password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password"></div>
                        <div class="col-12 col-md-2"><button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-1"></i> Save</button></div>
                        <div class="col-12"><small class="text-muted">At least 8 characters with letters and numbers. Forgot it? Sign out and use <strong>Create / Forgot password</strong> on the Student Login.</small></div>
                    </form>
                </div>
            </div>

            <div class="profile-row-cards">
                <!-- Guardian Information -->
                <div class="card-portal">
                    <div class="card-portal-header"><span><i class="fas fa-user-shield me-1"></i> Guardian Information</span></div>
                    <div class="card-portal-body">
                        <?php if (!empty($student['guardian_name'])): ?>
                        <div class="info-row"><span class="info-label"><i class="fas fa-user-shield info-icon"></i>Guardian Name</span><span class="info-value"><?php echo htmlspecialchars($student['guardian_name']); ?></span></div>
                        <div class="info-row"><span class="info-label"><i class="fas fa-phone-alt info-icon"></i>Guardian Contact</span><span class="info-value"><?php echo htmlspecialchars($student['guardian_contact'] ?? 'N/A'); ?></span></div>
                        <?php else: ?>
                        <div class="profile-empty-note">No guardian information on file yet.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Academic Information -->
                <div class="card-portal">
                    <div class="card-portal-header"><span><i class="fas fa-graduation-cap me-1"></i> Academic Information</span></div>
                    <div class="card-portal-body">
                        <div class="info-row"><span class="info-label"><i class="fas fa-layer-group info-icon"></i>Grade Level</span><span class="info-value"><?php echo htmlspecialchars($student['grade_level']); ?></span></div>
                        <div class="info-row"><span class="info-label"><i class="fas fa-chalkboard info-icon"></i>Section</span><span class="info-value"><?php echo htmlspecialchars($student['section_name'] ?? 'Not Assigned'); ?></span></div>
                        <div class="info-row"><span class="info-label"><i class="fas fa-user-tie info-icon"></i>Adviser</span><span class="info-value"><?php echo htmlspecialchars($student['adviser_name'] ?? 'Not yet assigned'); ?></span></div>
                        <?php if (!empty($student['strand'])): ?>
                        <div class="info-row"><span class="info-label"><i class="fas fa-flag info-icon"></i>Strand</span><span class="info-value"><?php echo htmlspecialchars($student['strand'] === 'ASSH' ? 'ASSH Cluster' : $student['strand']); ?></span></div>
                        <?php endif; ?>
                        <div class="info-row"><span class="info-label"><i class="fas fa-calendar-alt info-icon"></i>School Year</span><span class="info-value"><?php echo htmlspecialchars($student['sy_name'] ?? 'N/A'); ?></span></div>
                    </div>
                </div>
            </div>

            <!-- Account Information -->
            <div class="card-portal">
                <div class="card-portal-header"><span><i class="fas fa-shield-alt me-1"></i> Account Information</span></div>
                <div class="card-portal-body">
                    <div class="account-info-grid">
                        <div>
                            <div class="info-row"><span class="info-label"><i class="fas fa-id-badge info-icon"></i>Enrollment Status</span><span class="info-value"><?php echo htmlspecialchars($student['enrollment_status'] ?? 'N/A'); ?></span></div>
                            <div class="info-row"><span class="info-label"><i class="fas fa-book-reader info-icon"></i>Curriculum</span><span class="info-value"><?php echo htmlspecialchars($student['curriculum_type'] ?? 'N/A'); ?></span></div>
                        </div>
                        <div>
                            <div class="info-row"><span class="info-label"><i class="fas fa-calendar-plus info-icon"></i>Date Registered</span><span class="info-value"><?php echo !empty($student['created_at']) ? date('F d, Y', strtotime($student['created_at'])) : 'N/A'; ?></span></div>
                            <div class="info-row"><span class="info-label"><i class="fas fa-key info-icon"></i>Login Method</span><span class="info-value">LRN-Based Login</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Request a correction (official information) -->
            <?php $sr_requests = srStudentRequests($student_id); ?>
            <div class="card-portal" id="corrections">
                <div class="card-portal-header"><span><i class="fas fa-pen-to-square me-1"></i> Request a correction</span></div>
                <div class="card-portal-body">
                    <p style="font-size:13px;color:var(--portal-gray);margin-bottom:10px;">
                        Is your name, birth date or gender wrong? Send a request with the correct value and the basis (e.g. your PSA birth certificate).
                        The Registrar reviews it, and your record changes once it is approved. (Contact, address and guardian details you can edit yourself above.)
                    </p>
                    <form method="POST" action="#corrections" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="request_correction" value="1">
                        <div class="col-md-3">
                            <label class="form-label" style="font-size:12.5px;font-weight:600;">Information</label>
                            <select name="field_name" class="form-select form-select-sm" required>
                                <option value="">Choose...</option>
                                <?php foreach ($sr_student_fields as $fk => $fl): ?>
                                    <option value="<?php echo $fk; ?>"><?php echo htmlspecialchars($fl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" style="font-size:12.5px;font-weight:600;">Correct value</label>
                            <input type="text" name="new_value" class="form-control form-control-sm" maxlength="255" placeholder="e.g. Maria / 2013-05-14 / Female" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="font-size:12.5px;font-weight:600;">Reason / basis</label>
                            <input type="text" name="reason" class="form-control form-control-sm" maxlength="500" placeholder="e.g. Per my PSA birth certificate" required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-sm w-100" style="background:#14213D;color:#fff;">Send request</button>
                        </div>
                    </form>
                    <?php if (!empty($sr_requests)): ?>
                        <div class="table-responsive mt-3">
                            <table class="table table-sm mb-0" style="font-size:12.5px;">
                                <thead><tr><th>Information</th><th>Requested</th><th>Date</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($sr_requests as $rq): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(srCorrectionFields()[$rq['field_name']] ?? $rq['field_name']); ?></td>
                                        <td><?php echo htmlspecialchars($rq['new_value']); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($rq['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($rq['status']); ?><?php echo !empty($rq['review_note']) ? ' — ' . htmlspecialchars($rq['review_note']) : ''; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleEdit() {
    var viewMode = document.getElementById('viewMode');
    var editMode = document.getElementById('editMode');
    var btn = document.getElementById('toggleEditBtn');
    if (viewMode.style.display === 'none') {
        viewMode.style.display = 'block';
        editMode.style.display = 'none';
        btn.innerHTML = '<i class="fas fa-pen me-1"></i> Edit';
    } else {
        viewMode.style.display = 'none';
        editMode.style.display = 'block';
        btn.innerHTML = '<i class="fas fa-times me-1"></i> Cancel';
    }
}
// Sidebar toggle + topbar profile dropdown now live in the shared
// static/js/student-portal-shell.js (linked in <head>).
</script>
</body>
</html>