<?php
// modules/teachers/view_section.php
// View a specific section's students and subjects

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Teacher')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'View Section';
$current_page = 'teachers';
$base_url = '../../';

$teacher_id = $_SESSION['user_id'];
$section_id = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;

if ($section_id <= 0) {
    header('Location: my_sections.php');
    exit;
}

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
$school_year_id = $active_sy ? $active_sy['sy_id'] : 0;

// Get section info
$section = getRecord("SELECT * FROM sections WHERE section_id = ?", [$section_id]);
if (!$section) {
    $_SESSION['error_message'] = 'Section not found.';
    header('Location: my_sections.php');
    exit;
}

// Check if teacher is assigned to this section
$is_assigned = getRecord("
    SELECT ts_id FROM teacher_subjects 
    WHERE teacher_id = ? AND section_id = ? AND school_year_id = ?
", [$teacher_id, $section_id, $school_year_id]);

if (!$is_assigned) {
    $_SESSION['error_message'] = 'You are not assigned to this section.';
    header('Location: my_sections.php');
    exit;
}

// Get subjects for this section
$subjects = getRecords("
    SELECT ts.*, sub.subject_name, sub.subject_code, sub.subject_type
    FROM teacher_subjects ts
    JOIN subjects sub ON ts.subject_code = sub.subject_code
    WHERE ts.teacher_id = ? AND ts.section_id = ? AND ts.school_year_id = ?
    ORDER BY sub.subject_name
", [$teacher_id, $section_id, $school_year_id]);

// Get students in this section
$students = getRecords("
    SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.middle_name, s.grade_level, s.strand
    FROM students s
    JOIN enrollments e ON s.student_id = e.student_id
    WHERE e.section_id = ? AND e.school_year_id = ? AND e.enrollment_status = 'Approved'
    ORDER BY s.last_name, s.first_name
", [$section_id, $school_year_id]);

$teacher = getRecord("SELECT * FROM users WHERE user_id = ?", [$teacher_id]);

include '../../includes/header.php';
?>

<style>
    .subject-card {
        transition: all 0.3s ease;
        cursor: pointer;
        border-radius: 10px;
    }
    .subject-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.1) !important;
    }
</style>

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2>
                    <i class="fas fa-layer-group text-primary me-2"></i>
                    <?php echo htmlspecialchars($section['section_name']); ?>
                    <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($section['grade_level']); ?></span>
                    <?php if (!empty($section['strand'])): ?>
                        <span class="badge bg-info"><?php echo htmlspecialchars($section['strand'] === 'ASSH' ? 'ASSH Cluster' : $section['strand']); ?></span>
                    <?php endif; ?>
                </h2>
                <span class="text-muted">
                    <strong>Teacher:</strong> <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>
                    <span class="badge bg-secondary ms-2"><?php echo count($students); ?> students</span>
                </span>
            </div>
            <a href="my_sections.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to My Sections
            </a>
        </div>
    </div>
</div>

<!-- Students List -->
<div class="card shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white border-0 pt-3">
        <h5 class="mb-0">
            <i class="fas fa-users text-primary me-2"></i>
            Students
            <span class="badge bg-secondary ms-2"><?php echo count($students); ?></span>
        </h5>
    </div>
    <div class="card-body p-0">
        <?php if (count($students) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>LRN</th>
                            <th>Student Name</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; ?>
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td><strong><?php echo htmlspecialchars($student['lrn']); ?></strong></td>
                                <td><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></td>
                                <td><span class="badge bg-success">Enrolled</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-4 text-muted">
                <i class="fas fa-users fa-2x d-block mb-2"></i>
                <p>No students enrolled in this section.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Subjects List -->
<div class="card shadow-sm rounded-3">
    <div class="card-header bg-white border-0 pt-3">
        <h5 class="mb-0">
            <i class="fas fa-book text-primary me-2"></i>
            Subjects
            <span class="badge bg-secondary ms-2"><?php echo count($subjects); ?></span>
        </h5>
    </div>
    <div class="card-body">
        <?php if (count($subjects) > 0): ?>
            <div class="row g-3">
                <?php foreach ($subjects as $subject): ?>
                    <div class="col-md-4 col-lg-3">
                        <a href="../grading/index.php?section_id=<?php echo $section_id; ?>&subject_code=<?php echo urlencode($subject['subject_code']); ?>&term=<?php echo urlencode(getDefaultTerm()); ?>" 
                           class="text-decoration-none">
                            <div class="card subject-card border-0 shadow-sm h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-book-open fa-2x text-primary d-block mb-2"></i>
                                    <h6 class="mb-0"><?php echo htmlspecialchars($subject['subject_name']); ?></h6>
                                    <small class="text-muted"><?php echo htmlspecialchars($subject['subject_code']); ?></small>
                                    <div class="mt-2">
                                        <span class="badge bg-info"><?php echo htmlspecialchars($subject['subject_type'] ?? 'General'); ?></span>
                                    </div>
                                    <button class="btn btn-primary btn-sm mt-2">
                                        <i class="fas fa-edit me-1"></i> Encode Grades
                                    </button>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-4 text-muted">
                <i class="fas fa-book fa-2x d-block mb-2"></i>
                <p>No subjects assigned to this section.</p>
                <p class="small text-warning">
                    <i class="fas fa-info-circle me-1"></i>
                    Please contact the administrator to assign subjects.
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>