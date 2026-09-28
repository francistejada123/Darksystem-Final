<?php
// ============================================================
// >>> THIS FILE GOES IN:  modules/teachers/my_sections.php  <<<
// ============================================================
// modules/teachers/my_sections.php
// Teacher's My Sections - Grade Level → Sections → Students

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';
require_once __DIR__ . '/../../includes/attendance_qr_helpers.php'; // attQrLrnLink()

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

// Only Teachers can access this
if (!hasRole('Teacher')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'My Sections';
$current_page = 'teachers';
$base_url = '../../';

$teacher_id = $_SESSION['user_id'];
$selected_grade = isset($_GET['grade']) ? sanitize($_GET['grade']) : '';
$selected_section = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;
$selected_subject = isset($_GET['subject_code']) ? sanitize($_GET['subject_code']) : '';
$term = isset($_GET['term']) ? sanitize($_GET['term']) : getDefaultTerm();

// Get active school year
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
$school_year_id = $active_sy ? $active_sy['sy_id'] : 0;

// SERVER-SIDE grading-period validation. Only Term 1, Term 2 and Term 3 are
// accepted. The browser is never trusted, and the `chk_grades_term` CHECK
// constraint rejects anything else again at the database layer.
if (!isValidTerm($term)) {
    $term = getDefaultTerm();
}
// Term Transition: with no explicit ?term=, open the class on the term it is
// currently on (Term 1 until every subject is finalized and complete, then Term 2, ...).
if (!isset($_GET['term']) && $selected_section > 0 && $school_year_id) {
    $__cur = gh_syncSectionTerms($selected_section, $school_year_id, $teacher_id);
    $term = $__cur['current'];
}

// Get teacher info
$teacher = getRecord("SELECT * FROM users WHERE user_id = ?", [$teacher_id]);

// ============================================================
// GET ALL SECTIONS ASSIGNED TO THIS TEACHER WITH ACTUAL ENROLLMENT COUNT
// ============================================================
$my_sections = getRecords("
    SELECT DISTINCT s.section_id, s.section_name, s.grade_level, s.strand, s.capacity, s.current_enrollment,
           CONCAT(adv.last_name, ', ', adv.first_name) AS adviser_name,
           (SELECT COUNT(*) FROM enrollments e 
            WHERE e.section_id = s.section_id 
            AND e.school_year_id = ? 
            AND e.enrollment_status = 'Approved') as actual_enrollment
    FROM sections s
    JOIN teacher_subjects ts ON s.section_id = ts.section_id
    LEFT JOIN users adv ON s.adviser_id = adv.user_id
    WHERE s.is_active = 1 
    AND ts.teacher_id = ?
    AND ts.school_year_id = ?
    ORDER BY s.grade_level, s.section_name
", [$school_year_id, $teacher_id, $school_year_id]);

// Group sections by grade level
$sections_by_grade = [];
foreach ($my_sections as $sec) {
    $sections_by_grade[$sec['grade_level']][] = $sec;
}

// ============================================================
// GET SECTIONS FOR SELECTED GRADE
// ============================================================
$filtered_sections = [];
if ($selected_grade) {
    $filtered_sections = $sections_by_grade[$selected_grade] ?? [];
}

// ============================================================
// GET SUBJECTS FOR SELECTED SECTION
// ============================================================
$section_subjects = [];
$section_info = null;
$section_students = [];
$grade_data = [];

if ($selected_section > 0) {
    $section_info = getRecord("
        SELECT sec.*, CONCAT(adv.last_name, ', ', adv.first_name) AS adviser_name
        FROM sections sec
        LEFT JOIN users adv ON sec.adviser_id = adv.user_id
        WHERE sec.section_id = ?
    ", [$selected_section]);
    
    if ($section_info) {
        // Get subjects assigned to this teacher for this section, for the
        // TERM CURRENTLY BEING VIEWED. A Junior High subject has no term
        // value in subjects.term and is always included; a Senior High
        // subject is included only when it matches $term. This is also the
        // ownership list $selected_subject is checked against right below.
        $section_subjects = getRecords("
            SELECT ts.*, sub.subject_name, sub.subject_code, sub.subject_type, sub.term as subject_term
            FROM teacher_subjects ts
            JOIN subjects sub ON ts.subject_code = sub.subject_code
            WHERE ts.teacher_id = ?
            AND ts.section_id = ?
            AND ts.school_year_id = ?
            AND (sub.term IS NULL OR sub.term = ?)
            ORDER BY sub.subject_name
        ", [$teacher_id, $selected_section, $school_year_id, $term]);

        // ---- SUBJECT OWNERSHIP CHECK -- server-side, not just a hidden
        // button. $selected_subject came straight from the URL. It may only
        // proceed if it is one of this teacher's own subjects for this
        // section and the term currently being viewed, per the list just
        // built above -- otherwise nothing below (roster grades, the save
        // handler) may read or write anything for it.
        if (!empty($selected_subject)) {
            $subject_authorized = false;
            foreach ($section_subjects as $ss) {
                if ($ss['subject_code'] === $selected_subject) { $subject_authorized = true; break; }
            }
            if (!$subject_authorized) {
                $_SESSION['error_message'] = 'You are not assigned to this class/subject for the selected term.';
                $selected_subject = '';
            }
        }

        // Get students enrolled in this section
        $section_students = getRecords("
            SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.middle_name, 
                   s.grade_level, s.strand, s.email, s.contact_number,
                   e.enrollment_id, e.enrollment_status
            FROM students s
            JOIN enrollments e ON s.student_id = e.student_id
            WHERE e.section_id = ? 
            AND e.school_year_id = ?
            AND e.enrollment_status = 'Approved'
            AND s.is_active = 1
            ORDER BY s.last_name, s.first_name
        ", [$selected_section, $school_year_id]);
        
        // Get existing grades for these students
        if (!empty($section_students) && !empty($selected_subject)) {
            $student_ids = array_column($section_students, 'student_id');
            $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
            $grade_data_raw = getRecords("
                SELECT * FROM grades 
                WHERE student_id IN ($placeholders) 
                AND subject_code = ? 
                AND section_id = ? 
                AND school_year_id = ? 
                AND term = ?
            ", array_merge($student_ids, [$selected_subject, $selected_section, $school_year_id, $term]));
            
            $grade_data = [];
            foreach ($grade_data_raw as $g) {
                $grade_data[$g['student_id']] = $g;
            }
        }
    }
}

// ============================================================
// HANDLE GRADE SUBMISSION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grades'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token.';
    } elseif (empty($selected_subject)) {
        // The ownership check above already cleared $selected_subject when
        // the requested subject was not one of this teacher's own for this
        // section/term (or none was ever selected) -- reject the submit
        // itself rather than let it fall through to an INSERT with a blank
        // subject_code.
        $_SESSION['error_message'] = 'You are not assigned to this class/subject for the selected term.';
    } elseif (($__tt = gh_syncSectionTerms($selected_section, $school_year_id, $teacher_id)) && $term !== $__tt['current']) {
        // Term Transition (Grade Management): a class is on ONE term at a time.
        // A Completed term is locked, read-only history and the next term only
        // opens once every subject of the class is finalized and complete, so
        // grades can only be saved for the class's current term.
        $_SESSION['error_message'] = htmlspecialchars($term) . ' is '
            . (in_array($term, $__tt['completed'], true) ? 'already Completed for this section and locked (read-only).' : 'not enabled for this section yet -- it opens once ' . htmlspecialchars($__tt['current']) . ' is Completed.')
            . ' Grades can only be saved for ' . htmlspecialchars($__tt['current']) . '.';
    } else {
        $grades_data = $_POST['grades'] ?? [];
        $success_count = 0;
        $error_count = 0;
        $blocked_count = 0;   // skipped: grading rules not configured

        // Load the FULL subject row so getSubjectWeights() can see
        // subject_category and grade_level, not just the legacy subject_type.
        $subject_row  = getRecord("SELECT * FROM subjects WHERE subject_code = ?", [$selected_subject]);
        $subject_type = $subject_row['subject_type'] ?? 'Languages';

        // Grade level and curriculum type resolved from the SECTION, so
        // Grade 12 legacy vs Strengthened SHS is decided per cohort.
        $enc_section = getRecord("SELECT section_id, grade_level, curriculum_type FROM sections WHERE section_id = ?", [$selected_section]);
        $enc_grade_level = $enc_section['grade_level'] ?? ($subject_row['grade_level'] ?? null);
        $enc_curriculum  = getLearnerCurriculumType(null, $enc_grade_level, $selected_section);

        // Resolved once for the whole class — same subject, same section.
        $enc_weights = getSubjectWeights(
            $subject_row ?: $subject_type,
            $enc_grade_level,
            $active_sy,
            $enc_curriculum
        );

        $skipped_count = 0;    // nothing typed for this learner

        foreach ($grades_data as $student_id => $grade) {
            // A BLANK box means "not encoded yet". It must stay NULL and must
            // never be silently turned into a zero, which would fabricate a
            // failing score out of a box the teacher simply has not filled in.
            $raw_ww = $grade['written_work']     ?? '';
            $raw_pt = $grade['performance_task'] ?? '';
            $raw_ex = $grade['examinations']     ?? '';

            $written_work     = ($raw_ww === '' || $raw_ww === null) ? null : floatval($raw_ww);
            $performance_task = ($raw_pt === '' || $raw_pt === null) ? null : floatval($raw_pt);
            $examinations     = ($raw_ex === '' || $raw_ex === null) ? null : floatval($raw_ex);

            // Never encode against unconfigured rules.
            if (!empty($enc_weights['blocked'])) {
                $blocked_count++;
                continue;
            }

            // Percentage inputs must be 0-100; REJECT rather than clamp. Clamping
            // an impossible 123% down to 100% would manufacture a passing grade.
            $invalid = false;
            foreach ([$written_work, $performance_task, $examinations] as $pct_in) {
                if ($pct_in !== null && ($pct_in < 0 || $pct_in > 100)) { $invalid = true; break; }
            }
            if ($invalid) {
                $error_count++;
                continue;
            }

            // Nothing typed at all: do not create a row out of empty boxes.
            if ($written_work === null && $performance_task === null && $examinations === null) {
                $existing_check = getRecord(
                    "SELECT grade_id FROM grades WHERE student_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ?",
                    [$student_id, $selected_subject, $selected_section, $school_year_id, $term]
                );
                if (!$existing_check) {
                    $skipped_count++;
                    continue;
                }
            }

            // Server-side grading-period validation. The browser is not trusted,
            // and the database CHECK constraint rejects it again underneath this.
            if (!isValidTerm($term)) {
                $error_count++;
                continue;
            }

            $computed = computeTermGrade($enc_weights, [
                'ww' => $written_work,
                'pt' => $performance_task,
                'ex' => ($enc_weights['exam_mode'] === 'none') ? null : $examinations,
            ], $active_sy);

            $initial_grade    = $computed['initial_grade'];
            $transmuted_grade = $computed['transmuted_grade'];
            $final_grade      = $transmuted_grade;
            // Pass/fail comes from the transmuted grade via the central engine.
            $remarks          = $computed['remarks'];

            if ($transmuted_grade === null) {
                $error_count++;
                continue;
            }

            // The columns are NOT NULL DEFAULT 0.00, so an un-encoded component
            // is stored as 0 — but remarks stays 'Incomplete', which is what
            // marks the record as unfinished and keeps it out of every average,
            // passing rate, risk level and Final Grade (see gh_validTermSql()).
            $written_work     = $written_work     === null ? 0 : $written_work;
            $performance_task = $performance_task === null ? 0 : $performance_task;
            $examinations     = $examinations     === null ? 0 : $examinations;
            
            $existing = getRecord("SELECT grade_id FROM grades WHERE student_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ?",
                [$student_id, $selected_subject, $selected_section, $school_year_id, $term]);
            
            if ($existing) {
                $sql = "UPDATE grades SET 
                        written_work = ?, performance_task = ?, examinations = ?,
                        initial_grade = ?, transmuted_grade = ?, final_grade = ?, remarks = ?,
                        updated_at = NOW()
                        WHERE grade_id = ?";
                $params = [$written_work, $performance_task, $examinations, $initial_grade, $transmuted_grade, $final_grade, $remarks, $existing['grade_id']];
            } else {
                $sql = "INSERT INTO grades (student_id, subject_code, teacher_id, section_id, school_year_id, term,
                        written_work, performance_task, examinations, initial_grade, transmuted_grade, final_grade, remarks, encoded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $params = [
                    $student_id, $selected_subject, $teacher_id, $selected_section, $school_year_id, $term,
                    $written_work, $performance_task, $examinations, $initial_grade, $transmuted_grade, $final_grade, $remarks, $teacher_id
                ];
            }
            
            if (executeQuery($sql, $params)) {
                $success_count++;
            } else {
                $error_count++;
            }
        }
        
        logActivity($teacher_id, 'Saved grades', 'grading', "Saved grades for subject: $selected_subject, section: $selected_section, term: $term");
        
        $_SESSION['success_message'] = $success_count . ' grades saved successfully!';
        $problems = [];
        if ($error_count > 0) {
            $problems[] = $error_count . ' grade(s) were REJECTED — a percentage was outside 0-100, '
                        . 'or the grading period was not Term 1, Term 2 or Term 3. Invalid scores are never saved.';
        }
        if ($blocked_count > 0) {
            $problems[] = $blocked_count . ' learner(s) were skipped because the grading rules for this '
                        . 'subject are not configured yet.';
        }
        if (!empty($problems)) {
            $_SESSION['error_message'] = implode(' ', $problems);
        }
        
        header("Location: my_sections.php?grade=" . urlencode($selected_grade) . "&section_id=" . $selected_section . "&subject_code=" . urlencode($selected_subject) . "&term=" . urlencode($term));
        exit;
    }
}

$csrf_token = generateCSRFToken();
include '../../includes/header.php';
?>

<style>
    /* .grade-card / .section-card now come from the shared global
       components in static/css/style.css (same ones Student/Teacher/
       Subject Management and Class Records use) — this page no longer
       redefines its own radius/shadow/colors for them. */
    .grade-input {
        width: 100px;
    }
</style>

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-chalkboard-teacher text-primary me-2"></i>My Sections</h2>
                <span class="text-muted">
                    <strong>Teacher:</strong> <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>
                    <?php if (!empty($teacher['specialization'])): ?>
                        <span class="badge bg-info ms-2"><?php echo htmlspecialchars($teacher['specialization']); ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <a href="<?php echo BASE_URL; ?>index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>
    </div>
</div>

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

<!-- ============================================================ -->
<!-- STEP 1: GRADE LEVEL CARDS -->
<!-- ============================================================ -->
<?php if (empty($my_sections)): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>No sections assigned!</strong>
        <p class="mb-0">You have not been assigned to any sections yet. Please contact the administrator.</p>
    </div>
<?php else: ?>
    <div class="row g-3 mb-4">
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
            
            if (!$has_sections) continue;
        ?>
            <div class="col-md-4 col-lg-2">
                <div class="card grade-card <?php echo $is_active ? 'active' : 'border-0 shadow-sm'; ?>" 
                     onclick="window.location.href='?grade=<?php echo urlencode($grade); ?>&section_id=0&subject_code='">
                    <div class="card-body text-center">
                        <div class="grade-icon" style="color: <?php echo $color; ?>">
                            <span style="font-size: 32px; font-weight: 700;"><?php echo $display; ?></span>
                        </div>
                        <div class="grade-label"><?php echo $grade; ?></div>
                        <div class="section-count">
                            <?php echo count($grade_sections) . ' section' . (count($grade_sections) > 1 ? 's' : ''); ?>
                        </div>
                        <?php if ($is_active): ?>
                            <span class="badge bg-primary mt-2">Selected</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ============================================================ -->
    <!-- STEP 2: SECTIONS UNDER SELECTED GRADE -->
    <!-- ============================================================ -->
    <?php if ($selected_grade && !empty($filtered_sections)): ?>
        <div class="card shadow-sm rounded-3 mb-4">
            <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list text-primary me-2"></i>
                    Sections in <?php echo $selected_grade; ?>
                    <span class="badge bg-secondary ms-2"><?php echo count($filtered_sections); ?></span>
                </h5>
                <div>
                    <a href="?grade=0&section_id=0&subject_code=" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-times me-1"></i> Clear
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <?php foreach ($filtered_sections as $sec): 
                        $is_selected = ($selected_section == $sec['section_id']);
                    ?>
                        <div class="col-md-3 col-lg-2">
                            <a href="?grade=<?php echo urlencode($selected_grade); ?>&section_id=<?php echo $sec['section_id']; ?>&subject_code=" 
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
                                                <?php echo $sec['actual_enrollment'] ?? 0; ?>/<?php echo $sec['capacity'] ?? 45; ?> students
                                            </span>
                                        </div>
                                        <div class="text-muted small mt-1">
                                            <i class="fas fa-chalkboard-teacher me-1"></i><?php echo htmlspecialchars($sec['adviser_name'] ?? 'No adviser'); ?>
                                        </div>
                                        <?php if ($is_selected): ?>
                                            <span class="badge bg-primary mt-1">Viewing</span>
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
        <div class="card shadow-sm rounded-3 mb-4">
            <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-users text-primary me-2"></i>
                    <?php echo htmlspecialchars($section_info['section_name']); ?>
                    <?php if (!empty($section_info['strand'])): ?>
                        <span class="badge bg-info"><?php echo htmlspecialchars($section_info['strand'] === 'ASSH' ? 'ASSH Cluster' : $section_info['strand']); ?></span>
                    <?php endif; ?>
                    <span class="badge bg-secondary ms-2"><?php echo count($section_students); ?> students</span>
                    <div class="mt-1" style="font-size: 14.5px; font-weight: 400; color: #888;">
                        <i class="fas fa-chalkboard-teacher me-1"></i> Adviser:
                        <?php echo htmlspecialchars($section_info['adviser_name'] ?? 'Not yet assigned'); ?>
                    </div>
                </h5>
                <div>
                    <a href="?grade=<?php echo urlencode($selected_grade); ?>&section_id=0&subject_code=" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Sections
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($section_subjects)): ?>
                    <!-- ✅ WALANG SUBJECTS - Ipakita lang ang students list, walang warning -->
                    <?php if (!empty($section_students)): ?>
                        <div class="mt-2">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>LRN</th>
                                            <th>Student Name</th>
                                            <th>Grade Level</th>
                                            <th>Email</th>
                                            <th>Contact</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $counter = 1; ?>
                                        <?php foreach ($section_students as $student): ?>
                                            <tr>
                                                <td><?php echo $counter++; ?></td>
                                                <td><strong><?php echo attQrLrnLink($student['lrn']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></td>
                                                <td><?php echo htmlspecialchars($student['grade_level']); ?></td>
                                                <td><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($student['contact_number'] ?? 'N/A'); ?></td>
                                                <td><span class="badge bg-success">Enrolled</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-users fa-3x d-block mb-3"></i>
                            <h5>No students enrolled in this section</h5>
                            <p>Students will appear here after enrollment approval.</p>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- ✅ MAY SUBJECTS - Show subject tabs and grade encoding -->
                    <ul class="nav nav-tabs mb-3">
                        <?php foreach ($section_subjects as $subject): ?>
                            <li class="nav-item">
                                <a class="nav-link <?php echo ($selected_subject == $subject['subject_code']) ? 'active' : ''; ?>" 
                                   href="?grade=<?php echo urlencode($selected_grade); ?>&section_id=<?php echo $selected_section; ?>&subject_code=<?php echo urlencode($subject['subject_code']); ?>&term=<?php echo urlencode($term); ?>">
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    
                    <!-- Term Selector -->
                    <div class="row g-2 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Term</label>
                            <select class="form-select" id="termSelect" onchange="changeTerm()">
                                <?php
                                $terms = getActiveTerms();
                                foreach ($terms as $t):
                                ?>
                                    <option value="<?php echo $t; ?>" <?php echo $term == $t ? 'selected' : ''; ?>>
                                        <?php echo $t; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Grade Encoding -->
                    <?php if (empty($section_students)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-users fa-3x d-block mb-3"></i>
                            <h5>No students enrolled in this section</h5>
                            <p>Students will appear here after enrollment approval.</p>
                        </div>
                    <?php elseif (empty($selected_subject)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-book fa-3x d-block mb-3"></i>
                            <h5>Select a subject above</h5>
                            <p>Click on a subject tab to encode grades for this section.</p>
                        </div>
                    <?php else: ?>
                        <?php
                            // Resolve weights through the central engine using the full
                            // subject row, the section's grade level and the cohort's
                            // curriculum type — not the legacy subject_type alone.
                            $subject_row = getRecord("SELECT * FROM subjects WHERE subject_code = ?", [$selected_subject]);
                            $subject_type = $subject_row['subject_type'] ?? 'Languages';

                            $disp_grade_level = $section_info['grade_level'] ?? ($subject_row['grade_level'] ?? null);
                            $disp_curriculum  = getLearnerCurriculumType(null, $disp_grade_level, $selected_section);

                            $weights = getSubjectWeights(
                                $subject_row ?: $subject_type,
                                $disp_grade_level,
                                $active_sy,
                                $disp_curriculum
                            );
                            $ww_pct = (int)round((float)($weights['ww'] ?? 0) * 100);
                            $pt_pct = (int)round((float)($weights['pt'] ?? 0) * 100);
                            $qa_pct = (int)round((float)($weights['qa'] ?? 0) * 100);
                            // Component label from the central engine.
                            $exam_label = gh_examComponentLabel($active_sy);
                        ?>
                        <?php if (!empty($weights['blocked'])): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-triangle-exclamation me-2"></i>
                                <strong>Grading rules not configured for this subject.</strong>
                                <?php echo htmlspecialchars($weights['config_reason'] ?? ''); ?>
                                Grades cannot be encoded until this is set.
                            </div>
                        <?php elseif (!empty($weights['needs_config'])): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-circle-info me-2"></i>
                                <?php echo htmlspecialchars($weights['config_reason'] ?? ''); ?>
                                A provisional default is in use — confirm the subject category before treating these grades as official.
                            </div>
                        <?php endif; ?>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="save_grades" value="1">
                            
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 50px;">#</th>
                                            <th>Student Name</th>
                                            <th>LRN</th>
                                            <th style="width: 120px;">Written Works (<?php echo $ww_pct; ?>%)</th>
                                            <th style="width: 120px;">Performance Tasks (<?php echo $pt_pct; ?>%)</th>
                                            <th style="width: 120px;"><?php echo htmlspecialchars($exam_label); ?> (<?php echo $qa_pct; ?>%)</th>
                                            <th style="width: 80px;">Initial Grade</th>
                                            <th style="width: 80px;"><?php echo htmlspecialchars(gh_periodGradeLabel($active_sy)); ?></th>
                                            <th style="width: 100px;">Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $counter = 1; ?>
                                        <?php foreach ($section_students as $student): ?>
                                            <?php 
                                            $grade = $grade_data[$student['student_id']] ?? null;
                                            $ww = $grade ? $grade['written_work'] : '';
                                            $pt = $grade ? $grade['performance_task'] : '';
                                            $qa = $grade ? $grade['examinations'] : '';
                                            $initial = $grade ? $grade['initial_grade'] : '';
                                            $final = $grade ? $grade['final_grade'] : '';
                                            $remarks = $grade ? $grade['remarks'] : '';
                                            ?>
                                            <tr>
                                                <td><?php echo $counter++; ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?>
                                                    <input type="hidden" name="grades[<?php echo $student['student_id']; ?>][student_id]" value="<?php echo $student['student_id']; ?>">
                                                </td>
                                                <td><strong><?php echo attQrLrnLink($student['lrn']); ?></strong></td>
                                                <td>
                                                    <input type="number" name="grades[<?php echo $student['student_id']; ?>][written_work]" 
                                                           class="form-control form-control-sm grade-input" 
                                                           value="<?php echo $ww; ?>" min="0" max="100" step="0.01"
                                                           onchange="calculateGrade(this, '<?php echo $student['student_id']; ?>')">
                                                </td>
                                                <td>
                                                    <input type="number" name="grades[<?php echo $student['student_id']; ?>][performance_task]" 
                                                           class="form-control form-control-sm grade-input" 
                                                           value="<?php echo $pt; ?>" min="0" max="100" step="0.01"
                                                           onchange="calculateGrade(this, '<?php echo $student['student_id']; ?>')">
                                                </td>
                                                <td>
                                                    <input type="number" name="grades[<?php echo $student['student_id']; ?>][examinations]" 
                                                           class="form-control form-control-sm grade-input" 
                                                           value="<?php echo $qa; ?>" min="0" max="100" step="0.01"
                                                           onchange="calculateGrade(this, '<?php echo $student['student_id']; ?>')">
                                                </td>
                                                <td class="text-center initial-grade" id="initial-<?php echo $student['student_id']; ?>">
                                                    <?php echo $initial !== '' ? number_format($initial, 2) : ''; ?>
                                                </td>
                                                <td class="text-center final-grade" id="final-<?php echo $student['student_id']; ?>">
                                                    <?php echo $final !== '' ? number_format($final, 2) : ''; ?>
                                                </td>
                                                <td class="text-center remarks" id="remarks-<?php echo $student['student_id']; ?>">
                                                    <?php if ($remarks): ?>
                                                        <span class="badge bg-<?php echo $remarks == 'Passed' ? 'success' : 'danger'; ?>">
                                                            <?php echo $remarks; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="submit" class="btn btn-primary" onclick="return appConfirm(this, 'Save all grades for this subject?')">
                                    <i class="fas fa-save me-2"></i> Save Grades
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<script>
// Weights are emitted by getSubjectWeights() for THIS subject, grade level and
// curriculum. There is no hard-coded fallback: the old 0.30 / 0.50 / 0.20
// defaults here were the retired DO 8, s. 2015 global weights and could show a
// preview that disagreed with the grade the server actually saved.
var wwWeight = <?php echo json_encode(isset($weights) && $weights['ww'] !== null ? (float)$weights['ww'] : 0); ?>;
var ptWeight = <?php echo json_encode(isset($weights) && $weights['pt'] !== null ? (float)$weights['pt'] : 0); ?>;
var qaWeight = <?php echo json_encode(isset($weights) && $weights['ex'] !== null ? (float)$weights['ex'] : 0); ?>;

function changeTerm() {
    var term = document.getElementById('termSelect').value;
    var url = new URL(window.location.href);
    url.searchParams.set('term', term);
    window.location.href = url.toString();
}

function calculateGrade(input, studentId) {
    var row = input.closest('tr');
    var ww = parseFloat(row.querySelector('input[name="grades[' + studentId + '][written_work]"]').value) || 0;
    var pt = parseFloat(row.querySelector('input[name="grades[' + studentId + '][performance_task]"]').value) || 0;
    var qa = parseFloat(row.querySelector('input[name="grades[' + studentId + '][examinations]"]').value) || 0;

    // Preview nothing for an impossible percentage. The server REJECTS these;
    // showing a computed grade for them would be misleading.
    if (ww < 0 || ww > 100 || pt < 0 || pt > 100 || qa < 0 || qa > 100) {
        document.getElementById('initial-' + studentId).textContent = '—';
        document.getElementById('final-' + studentId).textContent = '—';
        document.getElementById('remarks-' + studentId).innerHTML =
            '<span class="badge bg-danger">Invalid score (0-100)</span>';
        return;
    }

    var initial = (ww * wwWeight) + (pt * ptWeight) + (qa * qaWeight);
    var transmuted = transmuteGrade(initial);
    // Pass/fail uses the TRANSMUTED grade, never the Initial Grade.
    var remarks = transmuted >= passMark ? 'Passed' : 'Failed';

    document.getElementById('initial-' + studentId).textContent = initial.toFixed(2);
    document.getElementById('final-' + studentId).textContent = transmuted;

    var remarksCell = document.getElementById('remarks-' + studentId);
    var badgeClass = remarks === 'Passed' ? 'success' : 'danger';
    remarksCell.innerHTML = '<span class="badge bg-' + badgeClass + '">' + remarks + '</span>';
}

// Emitted by the server for THIS school year so the live preview matches
// what is saved. This page previously hard-coded the DO 8, s. 2015 table,
// which returned 82 for an Initial Grade of 50 where SY 2026-2027 requires 70.
var transmutationTable = <?php echo gh_transmutationTableJson($active_sy); ?>;
var passMark = <?php echo json_encode((float)getGradingPassMark()); ?>;

// The Initial Grade is NOT rounded before the band lookup, so the
// boundaries stay decimal-safe.
function transmuteGrade(grade) {
    var g = parseFloat(grade);
    if (isNaN(g)) return 0;
    if (g < 0) g = 0;
    if (g > 100) g = 100;
    for (var i = 0; i < transmutationTable.length; i++) {
        if (g + 0.0000001 >= transmutationTable[i][0]) {
            return transmutationTable[i][1];
        }
    }
    return transmutationTable[transmutationTable.length - 1][1];
}
</script>

<?php include '../../includes/footer.php'; ?>