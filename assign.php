<?php
// modules/teachers/assign.php
// Admin: Assign subjects to teacher


require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Assign Subjects to Teacher';
$current_page = 'teachers';
$base_url = '../../';

$teacher_id = intval($_GET['id'] ?? 0);
$errors = [];
$success = false;

if ($teacher_id <= 0) {
    $_SESSION['error_message'] = 'Invalid teacher ID.';
    header('Location: index.php');
    exit;
}

$teacher = getRecord("SELECT * FROM users WHERE user_id = ? AND role_id = 3", [$teacher_id]);
if (!$teacher) {
    $_SESSION['error_message'] = 'Teacher not found.';
    header('Location: index.php');
    exit;
}

// Get active school year
$active_sy = getRecord("SELECT sy_id, sy_name FROM school_years WHERE is_active = 1");
$school_year_id = $active_sy ? $active_sy['sy_id'] : 0;

// Get all active subjects
$subjects = getRecords("SELECT subject_code, subject_name, grade_level FROM subjects WHERE is_active = 1 ORDER BY grade_level, subject_name");

// Get all active sections
$sections = getRecords("SELECT section_id, section_name, grade_level, strand FROM sections WHERE is_active = 1 ORDER BY grade_level, section_name");

// Get teacher's currently assigned subjects
$assigned_subjects = getRecords("
    SELECT ts.*, sub.subject_name, sec.section_name, sec.grade_level
    FROM teacher_subjects ts
    JOIN subjects sub ON ts.subject_code = sub.subject_code
    JOIN sections sec ON ts.section_id = sec.section_id
    WHERE ts.teacher_id = ? AND ts.school_year_id = ?
    ORDER BY sec.grade_level, sec.section_name, sub.subject_name
", [$teacher_id, $school_year_id]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'assign') {
            $subject_code = sanitize($_POST['subject_code'] ?? '');
            $section_id = intval($_POST['section_id'] ?? 0);
            
            // Validation
            if (empty($subject_code)) $errors[] = 'Please select a subject.';
            if (empty($section_id)) $errors[] = 'Please select a section.';
            
            // Check if already assigned
            if (empty($errors)) {
                $check = getRecord("
                    SELECT ts_id FROM teacher_subjects 
                    WHERE teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ?
                ", [$teacher_id, $subject_code, $section_id, $school_year_id]);
                
                if ($check) {
                    $errors[] = 'This subject is already assigned to this teacher for this section.';
                }
            }
            
            // If no errors, insert
            if (empty($errors)) {
                $sql = "INSERT INTO teacher_subjects (teacher_id, subject_code, section_id, school_year_id) 
                        VALUES (?, ?, ?, ?)";
                $result = executeQuery($sql, [$teacher_id, $subject_code, $section_id, $school_year_id]);
                
                if ($result) {
                    logActivity($_SESSION['user_id'], 'Assigned subject to teacher', 'teachers', 
                               "Assigned $subject_code to {$teacher['first_name']} {$teacher['last_name']}");
                    $_SESSION['success_message'] = 'Subject assigned successfully!';
                    header('Location: assign.php?id=' . $teacher_id);
                    exit;
                } else {
                    $errors[] = 'Failed to assign subject. Please try again.';
                }
            }
        } elseif ($action === 'remove') {
            $ts_id = intval($_POST['ts_id'] ?? 0);
            
            $result = executeQuery("DELETE FROM teacher_subjects WHERE ts_id = ? AND teacher_id = ?", [$ts_id, $teacher_id]);
            if ($result) {
                logActivity($_SESSION['user_id'], 'Removed subject from teacher', 'teachers', 
                           "Removed subject from {$teacher['first_name']} {$teacher['last_name']}");
                $_SESSION['success_message'] = 'Subject removed successfully!';
            } else {
                $_SESSION['error_message'] = 'Failed to remove subject.';
            }
            header('Location: assign.php?id=' . $teacher_id);
            exit;
        }
    }
}

$csrf_token = generateCSRFToken();
include '../../includes/header.php';
?>

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-chalkboard-teacher text-primary me-2"></i>Assign Subjects to Teacher</h2>
            <div>
                <span class="text-muted me-3">
                    <strong>Teacher:</strong> <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>
                    <?php if (!empty($teacher['specialization'])): ?>
                        <span class="badge bg-info ms-2"><?php echo htmlspecialchars($teacher['specialization']); ?></span>
                    <?php endif; ?>
                </span>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Teachers
                </a>
            </div>
        </div>
    </div>
</div>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i> <?php echo $_SESSION['success_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i> <?php echo $_SESSION['error_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo $error; ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- ASSIGN NEW SUBJECT FORM -->
<!-- ============================================================ -->
<div class="card shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white border-0 pt-3">
        <h5 class="mb-0"><i class="fas fa-plus-circle text-success me-2"></i>Assign New Subject</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="assign">
            
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">Select Subject</label>
                    <select name="subject_code" class="form-select" required>
                        <option value="">-- Select Subject --</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo $subject['subject_code']; ?>">
                                <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
                                (<?php echo htmlspecialchars($subject['grade_level']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($teacher['specialization'])): ?>
                        <small class="text-muted">
                            <i class="fas fa-info-circle"></i> 
                            Teacher's specialization: <strong><?php echo htmlspecialchars($teacher['specialization']); ?></strong>
                        </small>
                    <?php endif; ?>
                </div>
                
                <div class="col-md-5">
                    <label class="form-label">Select Section</label>
                    <select name="section_id" class="form-select" required>
                        <option value="">-- Select Section --</option>
                        <?php foreach ($sections as $section): ?>
                            <option value="<?php echo $section['section_id']; ?>">
                                <?php echo htmlspecialchars($section['section_name']); ?>
                                (<?php echo htmlspecialchars($section['grade_level']); ?>
                                <?php echo !empty($section['strand']) ? '- ' . $section['strand'] : ''; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-plus me-2"></i>Assign
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- CURRENT ASSIGNED SUBJECTS -->
<!-- ============================================================ -->
<div class="card shadow-sm rounded-3">
    <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="fas fa-list text-primary me-2"></i>
            Currently Assigned Subjects
            <span class="badge bg-secondary"><?php echo count($assigned_subjects); ?></span>
        </h5>
        <div>
            <span class="badge bg-info">School Year: <?php echo $active_sy['sy_name'] ?? 'N/A'; ?></span>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (count($assigned_subjects) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Subject Code</th>
                            <th>Subject Name</th>
                            <th>Grade Level</th>
                            <th>Section</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; ?>
                        <?php foreach ($assigned_subjects as $assigned): ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td><strong><?php echo htmlspecialchars($assigned['subject_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($assigned['subject_name']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($assigned['grade_level']); ?></span></td>
                                <td><?php echo htmlspecialchars($assigned['section_name']); ?></td>
                                <td class="text-center">
                                    <form method="POST" action="" class="d-inline" 
                                          onsubmit="return confirm('Remove this subject from <?php echo htmlspecialchars($teacher['first_name']); ?>?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="ts_id" value="<?php echo $assigned['ts_id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-trash me-1"></i> Remove
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-book fa-3x d-block mb-3"></i>
                <h5>No subjects assigned yet</h5>
                <p>Use the form above to assign subjects to this teacher.</p>
                <p class="small">
                    <i class="fas fa-info-circle text-info"></i>
                    Based on specialization: <strong><?php echo htmlspecialchars($teacher['specialization'] ?? 'Not set'); ?></strong>
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>