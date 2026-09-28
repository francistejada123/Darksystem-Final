<?php
// modules/students/teacher_students.php
// Teacher view - My Students (Students handled by the teacher)

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

// Only Teachers can access this page
if (!hasRole('Teacher')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'My Students';
$current_page = 'students';
$base_url = BASE_URL;

$teacher_id = $_SESSION['user_id'];

// Get selected grade filter
$selected_grade = isset($_GET['grade']) ? sanitize($_GET['grade']) : '';

// Get active school year
$active_sy = getRecord("SELECT sy_id, sy_name FROM school_years WHERE is_active = 1");
$school_year_id = $active_sy ? $active_sy['sy_id'] : 0;

// Get teacher's assigned sections with students
$teacher_sections = getRecords("
    SELECT DISTINCT ts.section_id, s.section_name, s.grade_level, s.strand,
           COUNT(DISTINCT ts.subject_code) as subject_count
    FROM teacher_subjects ts
    JOIN sections s ON ts.section_id = s.section_id
    WHERE ts.teacher_id = ? AND ts.school_year_id = ?
    GROUP BY ts.section_id
    ORDER BY s.grade_level, s.section_name
", [$teacher_id, $school_year_id]);

// Get students per section - ONLY sections with students
$section_students = [];
$total_students = 0;
$available_grades = [];

foreach ($teacher_sections as $section) {
    // Check if section matches selected grade filter
    if (!empty($selected_grade) && $section['grade_level'] != $selected_grade) {
        continue;
    }
    
    $students = getRecords("
        SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.middle_name,
               s.grade_level, s.strand, s.enrollment_status
        FROM students s
        JOIN enrollments e ON s.student_id = e.student_id
        WHERE e.section_id = ? 
        AND e.school_year_id = ?
        AND e.enrollment_status = 'Approved'
        AND s.is_active = 1
        ORDER BY s.last_name, s.first_name
    ", [$section['section_id'], $school_year_id]);
    
    // Track available grade levels for filter
    if (!in_array($section['grade_level'], $available_grades)) {
        $available_grades[] = $section['grade_level'];
    }
    
    // ONLY ADD SECTION IF IT HAS STUDENTS
    if (count($students) > 0) {
        $section_students[$section['section_id']] = [
            'section' => $section,
            'students' => $students,
            'count' => count($students)
        ];
        $total_students += count($students);
    }
}

include '../../includes/header.php';
?>

<style>
    /* Scoped under .my-students-page so nothing here leaks onto other
       pages. Renamed .section-card -> .ts-section-card: this is a full
       section DETAIL card (header bar + student table inside), not the
       compact Grade/Section SELECTOR grid item that static/css/style.css
       now standardizes as .section-card across Student/Teacher/Subject
       Management — reusing that class name here would have been a false
       "same component" collision, so it gets its own name instead.
       Colors switched from raw blue hex to the shared design tokens, and
       the gradient header became a flat fill (Section 1's "avoid
       excessive gradients"). */
    .my-students-page .ts-section-card {
        transition: box-shadow 0.2s ease;
        border-radius: var(--radius);
        overflow: hidden;
    }
    .my-students-page .ts-section-card:hover {
        box-shadow: var(--shadow-md) !important;
    }
    .my-students-page .ts-section-card .card-header {
        background: var(--ink);
        color: white;
        padding: 15px 20px;
    }
    .my-students-page .ts-section-card .card-header .section-name {
        font-size: 18px;
        font-weight: 700;
    }
    .my-students-page .ts-section-card .card-header .section-count {
        font-size: 14.5px;
        opacity: 0.8;
    }
    .my-students-page .student-row {
        transition: background 0.2s;
    }
    .my-students-page .student-row:hover {
        background: var(--surface-hover);
    }
    .my-students-page .badge-strand {
        background: var(--primary-soft);
        color: var(--primary);
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 12.5px;
    }
    .my-students-page .filter-badge {
        cursor: pointer;
        transition: all 0.2s;
        padding: 8px 16px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 14.5px;
        background: var(--bg);
        color: var(--text-secondary, var(--text-muted));
        border: 1px solid var(--border);
        text-decoration: none;
        display: inline-block;
    }
    .my-students-page .filter-badge:hover {
        background: var(--surface-hover);
        text-decoration: none;
        color: var(--ink);
    }
    .my-students-page .filter-badge.active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }
    .my-students-page .filter-badge.active:hover {
        background: #4A4EDB;
        color: white;
    }
    .my-students-page .filter-badge .count {
        background: rgba(255,255,255,0.2);
        padding: 1px 8px;
        border-radius: 12px;
        margin-left: 5px;
        font-size: 12.5px;
    }
    .my-students-page .filter-badge.active .count {
        background: rgba(255,255,255,0.25);
    }
    .my-students-page .no-students-main {
        padding: 60px 20px;
        text-align: center;
        color: var(--text-muted);
    }
    .my-students-page .no-students-main .icon {
        font-size: 60px;
        margin-bottom: 20px;
        color: var(--border);
    }
    .my-students-page .section-divider {
        border-bottom: 2px dashed var(--border-soft);
        margin: 20px 0;
    }
</style>

<div class="my-students-page">

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>
                <i class="fas fa-user-graduate text-primary me-2"></i>
                My Students
                <span class="badge bg-secondary ms-2"><?php echo $total_students; ?> total</span>
            </h2>
            <div>
                <span class="badge bg-info me-2">
                    <i class="fas fa-calendar me-1"></i>
                    <?php echo $active_sy ? $active_sy['sy_name'] : 'N/A'; ?>
                </span>
                <button class="btn btn-outline-primary btn-sm" onclick="location.reload()">
                    <i class="fas fa-sync me-1"></i> Refresh
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Grade Level Filters -->
<div class="card shadow-sm rounded-3 mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center flex-wrap gap-2">
            <span class="fw-bold text-muted me-2"><i class="fas fa-filter me-1"></i>Filter by Grade:</span>
            <a href="?grade=" class="filter-badge <?php echo empty($selected_grade) ? 'active' : ''; ?>">
                <i class="fas fa-undo me-1"></i> All
                <span class="count"><?php echo count($section_students); ?></span>
            </a>
            <?php
            $grade_levels = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
            foreach ($grade_levels as $grade):
                // Count sections for this grade
                $grade_count = 0;
                foreach ($section_students as $sec) {
                    if ($sec['section']['grade_level'] == $grade) {
                        $grade_count++;
                    }
                }
                if ($grade_count > 0 || $selected_grade == $grade):
                    $grade_num = str_replace('Grade ', '', $grade);
            ?>
                <a href="?grade=<?php echo urlencode($grade); ?>" 
                   class="filter-badge <?php echo $selected_grade == $grade ? 'active' : ''; ?>">
                    <?php echo $grade_num; ?> - <?php echo $grade; ?>
                    <span class="count"><?php echo $grade_count; ?></span>
                </a>
            <?php endif; endforeach; ?>
        </div>
    </div>
</div>

<!-- Sections with Students - Each section is SEPARATED -->
<?php if (count($section_students) > 0): ?>
    <?php 
    $current_grade = '';
    $counter = 0;
    foreach ($section_students as $section_id => $data): 
        $section = $data['section'];
        $students = $data['students'];
        $count = $data['count'];
        $is_shs = ($section['grade_level'] === 'Grade 11' || $section['grade_level'] === 'Grade 12');
        $counter++;
        
        // Show grade level header when grade changes
        if ($section['grade_level'] != $current_grade) {
            $current_grade = $section['grade_level'];
            $grade_num = str_replace('Grade ', '', $current_grade);
    ?>
        <div class="section-divider"></div>
        <div class="d-flex align-items-center mb-3">
            <h4 class="mb-0 text-primary">
                <i class="fas fa-graduation-cap me-2"></i>
                <?php echo $current_grade; ?>
            </h4>
            <span class="badge bg-secondary ms-2">
                <?php 
                    $grade_count = 0;
                    foreach ($section_students as $sec) {
                        if ($sec['section']['grade_level'] == $current_grade) {
                            $grade_count += $sec['count'];
                        }
                    }
                    echo $grade_count . ' students';
                ?>
            </span>
        </div>
    <?php } ?>
    
        <!-- EACH SECTION IS A SEPARATE CARD -->
        <div class="card ts-section-card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <span class="section-name">
                        <i class="fas fa-users me-2"></i>
                        <?php echo htmlspecialchars($section['section_name']); ?>
                    </span>
                    <span class="badge bg-light text-dark ms-2">
                        <i class="fas fa-layer-group me-1"></i>
                        <?php echo htmlspecialchars($section['grade_level']); ?>
                    </span>
                    <?php if ($is_shs && !empty($section['strand'])): ?>
                        <span class="badge-strand ms-2">
                            <i class="fas fa-layer-group me-1"></i>
                            <?php echo htmlspecialchars($section['strand'] === 'ASSH' ? 'ASSH Cluster' : $section['strand']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="section-count">
                    <i class="fas fa-user-graduate me-1"></i>
                    <?php echo $count; ?> student<?php echo $count > 1 ? 's' : ''; ?>
                    <span class="ms-2 text-light opacity-75">
                        <i class="fas fa-book me-1"></i>
                        <?php echo $section['subject_count']; ?> subject<?php echo $section['subject_count'] > 1 ? 's' : ''; ?>
                    </span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>LRN</th>
                                <th>Student Name</th>
                                <th>Grade Level</th>
                                <?php if ($is_shs): ?>
                                    <th>Strand</th>
                                <?php endif; ?>
                                <th>Status</th>
                                <th class="text-center" style="width: 130px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $row_counter = 1; ?>
                            <?php foreach ($students as $student): ?>
                                <tr class="student-row">
                                    <td><?php echo $row_counter++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($student['lrn']); ?></strong></td>
                                    <td>
                                        <?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?>
                                        <?php if (!empty($student['middle_name'])): ?>
                                            <?php echo ' ' . htmlspecialchars($student['middle_name']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['grade_level']); ?></td>
                                    <?php if ($is_shs): ?>
                                        <td>
                                            <?php if (!empty($student['strand'])): ?>
                                                <span class="badge-strand"><?php echo htmlspecialchars($student['strand'] === 'ASSH' ? 'ASSH Cluster' : $student['strand']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <span class="badge bg-<?php echo $student['enrollment_status'] == 'Enrolled' ? 'success' : 'warning'; ?>">
                                            <?php echo $student['enrollment_status'] ?? 'Pending'; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="view.php?id=<?php echo $student['student_id']; ?>" 
                                               class="btn btn-outline-primary" title="View Profile">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="../grading/?section_id=<?php echo $section_id; ?>" 
                                               class="btn btn-outline-success" title="View Grades">
                                                <i class="fas fa-calculator"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
    <?php endforeach; ?>
<?php else: ?>
    <div class="card shadow-sm rounded-3">
        <div class="card-body text-center py-5 no-students-main">
            <div class="icon">
                <i class="fas fa-user-graduate"></i>
            </div>
            <h4 class="text-muted">No students assigned yet</h4>
            <p class="text-muted">You don't have any students assigned to your classes.</p>
            <p class="text-muted small">Please contact the administrator to assign sections to you.</p>
        </div>
    </div>
<?php endif; ?>

</div><!-- /.my-students-page -->

<?php include '../../includes/footer.php'; ?>