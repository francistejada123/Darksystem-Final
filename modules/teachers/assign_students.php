<?php
// modules/teachers/assign_students.php
// Admin: Assign students to teacher - HIERARCHICAL VIEW
// Grade Level → Sections → Students


require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Assign Students to Teacher';
$current_page = 'teachers';
$base_url = '../../';

$teacher_id = intval($_GET['id'] ?? 0);
$selected_grade = isset($_GET['grade']) ? sanitize($_GET['grade']) : '';
$selected_section = isset($_GET['section']) ? intval($_GET['section']) : 0;
$selected_subject = isset($_GET['subject']) ? sanitize($_GET['subject']) : '';

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

// ============================================================
// GET ALL SECTIONS GROUPED BY GRADE LEVEL
// ============================================================
$all_sections = getRecords("
    SELECT section_id, section_name, grade_level, strand, capacity, current_enrollment
    FROM sections 
    WHERE is_active = 1 
    ORDER BY grade_level, section_name
");

// Group sections by grade level
$sections_by_grade = [];
foreach ($all_sections as $sec) {
    $sections_by_grade[$sec['grade_level']][] = $sec;
}

// ============================================================
// GET TEACHER'S ASSIGNED SECTIONS & SUBJECTS
// ============================================================
$teacher_assignments = getRecords("
    SELECT ts.ts_id, ts.section_id, ts.subject_code, 
           s.section_name, s.grade_level, s.strand, s.capacity, s.current_enrollment,
           sub.subject_name
    FROM teacher_subjects ts
    JOIN sections s ON ts.section_id = s.section_id
    JOIN subjects sub ON ts.subject_code = sub.subject_code
    WHERE ts.teacher_id = ? AND ts.school_year_id = ?
    ORDER BY s.grade_level, s.section_name, sub.subject_name
", [$teacher_id, $school_year_id]);

// Get unique sections from teacher's assignments
$teacher_section_ids = [];
foreach ($teacher_assignments as $assign) {
    if (!in_array($assign['section_id'], $teacher_section_ids)) {
        $teacher_section_ids[] = $assign['section_id'];
    }
}

// ============================================================
// GET STUDENTS FOR SELECTED SECTION
// ============================================================
$section_students = [];
$section_info = null;

if ($selected_section > 0) {
    $section_info = getRecord("SELECT * FROM sections WHERE section_id = ?", [$selected_section]);
    
    if ($section_info) {
        // Get students in this section
        $section_students = getRecords("
            SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.grade_level, s.strand,
                   s.enrollment_status, s.email, s.contact_number
            FROM students s
            JOIN enrollments e ON s.student_id = e.student_id
            WHERE e.section_id = ? 
            AND e.school_year_id = ?
            AND e.enrollment_status = 'Approved'
            AND s.is_active = 1
            ORDER BY s.last_name, s.first_name
        ", [$selected_section, $school_year_id]);
    }
}

// ============================================================
// HANDLE POST - Assign/Remove Student
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token.';
        header('Location: assign_students.php?id=' . $teacher_id . '&grade=' . $selected_grade . '&section=' . $selected_section . '&subject=' . $selected_subject);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    $student_id = intval($_POST['student_id'] ?? 0);
    $section_id = intval($_POST['section_id'] ?? 0);
    $subject_code = sanitize($_POST['subject_code'] ?? '');
    
    if ($action === 'assign' && $student_id > 0 && $section_id > 0) {
        // Check if student already enrolled
        $existing = getRecord("
            SELECT enrollment_id FROM enrollments 
            WHERE student_id = ? AND school_year_id = ? AND enrollment_status = 'Approved'
        ", [$student_id, $school_year_id]);
        
        if ($existing) {
            $_SESSION['error_message'] = 'Student already enrolled this school year.';
        } else {
            // GET SECTION DETAILS
            $section = getRecord("SELECT section_id, section_name, grade_level FROM sections WHERE section_id = ?", [$section_id]);
            
            if (!$section) {
                $_SESSION['error_message'] = 'Section not found.';
                header('Location: assign_students.php?id=' . $teacher_id . '&grade=' . $selected_grade . '&section=' . $selected_section . '&subject=' . $selected_subject);
                exit;
            }
            
            // Get student info
            $student = getRecord("SELECT * FROM students WHERE student_id = ?", [$student_id]);
            
            // UPDATE STUDENT - Enroll them
            $update_student = "UPDATE students SET 
                               enrollment_status = 'Enrolled',
                               section_id = ?
                               WHERE student_id = ?";
            executeQuery($update_student, [$section_id, $student_id]);
            
            // CREATE ENROLLMENT RECORD
            $sql = "INSERT INTO enrollments (student_id, school_year_id, grade_level, strand, section_id, 
                    enrollment_date, enrollment_status, processed_by) 
                    VALUES (?, ?, ?, ?, ?, NOW(), 'Approved', ?)";
            $result = executeQuery($sql, [
                $student_id, 
                $school_year_id, 
                $section['grade_level'],
                $student['strand'] ?? '', 
                $section_id, 
                $_SESSION['user_id']
            ]);
            
            if ($result) {
                // UPDATE SECTION ENROLLMENT COUNT
                executeQuery("UPDATE sections SET current_enrollment = current_enrollment + 1 WHERE section_id = ?", [$section_id]);
                
                logActivity($_SESSION['user_id'], 'Assigned student to teacher', 'teachers', 
                    "Assigned student: {$student['last_name']}, {$student['first_name']} to section: {$section['section_name']} (Grade: {$section['grade_level']})");
                $_SESSION['success_message'] = 'Student assigned successfully to ' . $section['section_name'];
            } else {
                $_SESSION['error_message'] = 'Failed to assign student.';
            }
        }
    } elseif ($action === 'remove' && $student_id > 0) {
        // Unenroll student
        $result = executeQuery("DELETE FROM enrollments WHERE student_id = ? AND school_year_id = ? AND enrollment_status = 'Approved'", 
            [$student_id, $school_year_id]);
        
        if ($result) {
            // UPDATE SECTION ENROLLMENT COUNT
            $student = getRecord("SELECT section_id FROM students WHERE student_id = ?", [$student_id]);
            if ($student && $student['section_id']) {
                executeQuery("UPDATE sections SET current_enrollment = current_enrollment - 1 WHERE section_id = ?", [$student['section_id']]);
            }
            
            executeQuery("UPDATE students SET enrollment_status = 'Pending', section_id = NULL WHERE student_id = ?", [$student_id]);
            $_SESSION['success_message'] = 'Student removed successfully!';
        } else {
            $_SESSION['error_message'] = 'Failed to remove student.';
        }
    }
    
    header('Location: assign_students.php?id=' . $teacher_id . '&grade=' . $selected_grade . '&section=' . $selected_section . '&subject=' . $selected_subject);
    exit;
}

$csrf_token = generateCSRFToken();

include '../../includes/header.php';
?>

<!-- Success/Error Messages -->
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

<div class="row mt-4 asg-head">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-user-graduate text-primary me-2"></i>Assign Students to Teacher</h2>
            <div>
                <span class="text-muted me-3">
                    <strong>Teacher:</strong> <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>
                    <?php if (!empty($teacher['specialization'])): ?>
                        <span class="badge bg-info ms-2">
                            <i class="fas fa-graduation-cap me-1"></i>
                            <?php echo htmlspecialchars($teacher['specialization']); ?>
                        </span>
                    <?php endif; ?>
                </span>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Teachers
                </a>
            </div>
        </div>
    </div>
</div>

<?php if (empty($teacher_assignments)): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>
        This teacher has no assigned sections. Please assign subjects/sections first in 
        <a href="assign.php?id=<?php echo $teacher_id; ?>" class="alert-link">Assign Subjects</a>.
    </div>
<?php else: ?>

<!-- ============================================================ -->
<!-- STEP 1: GRADE LEVEL CARDS -->
<!-- ============================================================ -->
<div class="card shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white border-0 pt-3">
        <h5 class="mb-0">
            <i class="fas fa-layer-group text-primary me-2"></i>
            Step 1: Select Grade Level
        </h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php 
            $grade_levels = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
            foreach ($grade_levels as $grade): 
                $grade_sections = $sections_by_grade[$grade] ?? [];
                $has_sections = count($grade_sections) > 0;
                $is_active = ($selected_grade === $grade);
                $color = '';
                $display = '';
                
                switch($grade) {
                    case 'Grade 7': $color = '#2E7D67'; $display = '7'; break;
                    case 'Grade 8': $color = '#356EA8'; $display = '8'; break;
                    case 'Grade 9': $color = '#A06A1B'; $display = '9'; break;
                    case 'Grade 10': $color = '#5B5EA6'; $display = '10'; break;
                    case 'Grade 11': $color = '#994C52'; $display = '11'; break;
                    case 'Grade 12': $color = '#287A78'; $display = '12'; break;
                }
            ?>
                <div class="col-md-4 col-lg-2">
                    <div class="card grade-card <?php echo $is_active ? 'active' : 'border-0 shadow-sm'; ?>" 
                         onclick="window.location.href='?id=<?php echo $teacher_id; ?>&grade=<?php echo urlencode($grade); ?>&section=0&subject=0'">
                        <div class="card-body text-center">
                            <div class="grade-icon" style="color: <?php echo $color; ?>">
                                <span style="font-size: 32px; font-weight: 700;"><?php echo $display; ?></span>
                            </div>
                            <div class="grade-label"><?php echo $grade; ?></div>
                            <div class="section-count">
                                <?php echo $has_sections ? count($grade_sections) . ' sections' : 'No sections'; ?>
                            </div>
                            <?php if ($is_active): ?>
                                <span class="badge bg-primary mt-2">Selected</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- STEP 2: SECTIONS UNDER SELECTED GRADE -->
<!-- ============================================================ -->
<?php if ($selected_grade && !empty($sections_by_grade[$selected_grade])): ?>
    <div class="card shadow-sm rounded-3 mb-4">
        <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-list text-primary me-2"></i>
                Step 2: Select Section in <?php echo $selected_grade; ?>
                <span class="badge bg-secondary ms-2"><?php echo count($sections_by_grade[$selected_grade]); ?> sections</span>
            </h5>
            <?php if ($selected_grade): ?>
                <a href="?id=<?php echo $teacher_id; ?>&grade=0&section=0&subject=0" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Clear
                </a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="row g-2">
                <?php foreach ($sections_by_grade[$selected_grade] as $sec): 
                    $is_selected = ($selected_section == $sec['section_id']);
                    $is_teacher_assigned = in_array($sec['section_id'], $teacher_section_ids);
                ?>
                    <div class="col-md-3 col-lg-2">
                        <a href="?id=<?php echo $teacher_id; ?>&grade=<?php echo urlencode($selected_grade); ?>&section=<?php echo $sec['section_id']; ?>&subject=0" 
                           class="text-decoration-none">
                            <div class="card section-card <?php echo $is_selected ? 'active' : 'border-0 shadow-sm'; ?>">
                                <div class="card-body text-center py-3">
                                    <div class="section-name <?php echo $is_selected ? 'text-primary' : 'text-dark'; ?>">
                                        <?php echo htmlspecialchars($sec['section_name']); ?>
                                    </div>
                                    <?php if (!empty($sec['strand'])): ?>
                                        <div class="section-strand">
                                            <span class="badge bg-info"><?php echo htmlspecialchars($sec['strand'] === 'ASSH' ? 'ASSH Cluster' : $sec['strand']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="mt-1">
                                        <span class="badge bg-secondary">
                                            <?php echo $sec['current_enrollment'] ?? 0; ?>/<?php echo $sec['capacity'] ?? 45; ?>
                                        </span>
                                    </div>
                                    <?php if ($is_selected): ?>
                                        <span class="badge bg-primary mt-1">Viewing</span>
                                    <?php endif; ?>
                                    <?php if (!$is_teacher_assigned): ?>
                                        <span class="badge bg-warning mt-1">Not Assigned</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- STEP 3: STUDENTS IN SELECTED SECTION -->
<!-- ============================================================ -->
<?php if ($selected_section > 0 && $section_info): ?>
    <div class="card shadow-sm rounded-3">
        <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-users text-primary me-2"></i>
                Students in <?php echo htmlspecialchars($section_info['section_name']); ?>
                <span class="badge bg-secondary ms-2"><?php echo count($section_students); ?> students</span>
            </h5>
            <div>
                <span class="badge bg-success">Teacher: <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?></span>
                <?php if ($selected_grade): ?>
                    <a href="?id=<?php echo $teacher_id; ?>&grade=<?php echo urlencode($selected_grade); ?>&section=0&subject=0" 
                       class="btn btn-sm btn-outline-secondary ms-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Sections
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (count($section_students) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>LRN</th>
                                <th>Student Name</th>
                                <th>Grade Level</th>
                                <th>Strand</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; ?>
                            <?php foreach ($section_students as $student): ?>
                                <?php 
                                $is_enrolled = ($student['enrollment_status'] === 'Enrolled');
                                ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($student['lrn']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['grade_level']); ?></td>
                                    <td>
                                        <?php if (!empty($student['strand'])): ?>
                                            <span class="badge bg-info"><?php echo htmlspecialchars($student['strand'] === 'ASSH' ? 'ASSH Cluster' : $student['strand']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $is_enrolled ? 'success' : 'warning'; ?>">
                                            <?php echo $is_enrolled ? 'Enrolled' : 'Pending'; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($is_enrolled): ?>
                                            <form method="POST" action="" class="d-inline" 
                                                  onsubmit="return confirm('Remove <?php echo htmlspecialchars($student['first_name']); ?> from this section?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="remove">
                                                <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" title="Remove from section">
                                                    <i class="fas fa-user-minus me-1"></i> Remove
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" action="" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="assign">
                                                <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                                                <input type="hidden" name="section_id" value="<?php echo $selected_section; ?>">
                                                <input type="hidden" name="subject_code" value="">
                                                <button type="submit" class="btn btn-success btn-sm" title="Assign to section">
                                                    <i class="fas fa-user-plus me-1"></i> Assign
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-users fa-3x d-block mb-3"></i>
                    <h5>No students in this section</h5>
                    <p>Students will appear here after enrollment approval.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php endif; ?>

<style>
    /* .grade-card / .section-card now come from the shared global
       components in static/css/style.css (same ones Student/Teacher/
       Subject Management and Class Records use) — this page no longer
       redefines its own radius/shadow/colors for them. */

    /* ---- Bigger, easier-to-read text (this page only) ---- */
    .asg-head h2 { font-size: 30px; }
    .asg-head .text-muted { font-size: 16px; }
    .asg-head .badge { font-size: 15px; padding: 6px 10px; }
    .asg-head .btn { font-size: 15.5px; padding: 10px 18px; }
    .main-content .card-header h5 { font-size: 19px; }
    .main-content .card-header .badge { font-size: 15px; padding: 5px 10px; }
    .main-content .card-header .btn-sm { font-size: 15.5px; padding: 7px 14px; }
    .grade-card .grade-icon span { font-size: 36px !important; }
    .grade-card .grade-label { font-size: 18px !important; }
    .grade-card .section-count { font-size: 15px !important; }
    .grade-card .badge { font-size: 14.5px !important; padding: 5px 12px; }
    .section-card .section-name { font-size: 19px !important; font-weight: 700; }
    .section-card .badge { font-size: 15px !important; padding: 5px 10px; }
    .section-card .card-body { padding-top: 22px !important; padding-bottom: 22px !important; }
    .main-content .table > :not(caption) > * > th { font-size: 14.5px; }
    .main-content .table > :not(caption) > * > td { font-size: 15px; padding-top: 12px; padding-bottom: 12px; }
    .main-content .table .badge { font-size: 14.5px; }
    .main-content .table .btn-sm { font-size: 15px; padding: 6px 14px; }
</style>

<?php include '../../includes/footer.php'; ?>