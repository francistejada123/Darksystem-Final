<?php
// modules/grading/admin_view.php
// Grade Management - Administrator (STRICT VIEW ONLY)
// Flow: Grade Level -> Sections -> Subjects -> Students & Grades (read-only)
//
// Reachable two ways: (1) require_once'd from modules/grading/index.php,
// which already has config/database.php, auth.php, grading_helpers.php
// loaded and BASE_URL defined and $active_sy computed — every require_once
// and the BASE_URL check below are then guaranteed no-ops; or (2) requested
// directly — a bookmark, or view_student.php's own "Back" link falls back
// to this exact URL when it has no grade/section to return to — in which
// case this file must be fully self-sufficient, exactly like
// view_student.php already is. Skipping this bootstrap is what caused the
// "Undefined constant BASE_URL" fatal error when this page was opened on
// its own.
//
// Nothing on this page writes to the database. Every query below is a
// plain SELECT; there is no form, no POST handler, and no editable input
// anywhere in this file. That is the actual enforcement — the UI simply
// never renders a way to change a grade, on top of the existing
// server-side role gate in modules/grading/index.php (Administrators are
// routed here and never reach the Teacher save_grades handler at all) and
// in items.php (assessment items are only ever matched against the
// signed-in teacher's own teacher_subjects row).

require_once __DIR__ . '/../../config/database.php';
if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

if (empty($active_sy)) {
    $active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
}
if (!$active_sy) {
    include __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-warning mt-4">⚠️ No active school year found.</div>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

$page_title = 'Grade Management';
$current_page = 'grading';
$base_url = BASE_URL;

$selected_grade   = isset($_GET['grade']) ? sanitize($_GET['grade']) : '';
$selected_section = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;
$selected_subject = isset($_GET['subject_code']) ? sanitize($_GET['subject_code']) : '';

// Same grading-period concept the Teacher flow already uses — this page
// never introduces a new term/period idea, it only lets the admin pick
// which already-existing term to look at.
$term = isset($_GET['term']) ? sanitize($_GET['term']) : getDefaultTerm();
if (!isValidTerm($term)) {
    $term = getDefaultTerm();
}
$terms = getActiveTerms();
$exam_label = gh_examComponentLabel($active_sy);

$grade_levels = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
// Same per-grade colours as the Students, Teachers and Subjects pages, so a
// grade is recognisably the same colour wherever it appears in the system.
$grade_palette = [
    'Grade 7'  => ['accent' => '#2E7D67', 'soft' => '#F1F8F5'],
    'Grade 8'  => ['accent' => '#356EA8', 'soft' => '#F2F6FA'],
    'Grade 9'  => ['accent' => '#A06A1B', 'soft' => '#FBF7EE'],
    'Grade 10' => ['accent' => '#5B5EA6', 'soft' => '#F4F3FA'],
    'Grade 11' => ['accent' => '#994C52', 'soft' => '#FAF2F3'],
    'Grade 12' => ['accent' => '#287A78', 'soft' => '#F0F8F7'],
];

// ============================================================
// ALL ACTIVE SECTIONS (grouped by grade level) — school-wide, not
// limited to any one teacher, since the admin can see everything.
// ============================================================
$all_sections = getRecords("
    SELECT
        s.section_id,
        s.section_name,
        s.grade_level,
        s.adviser_id,
        CONCAT(u.last_name, ', ', u.first_name) AS adviser_name,
        (
            SELECT COUNT(*) FROM enrollments e
            WHERE e.section_id = s.section_id
            AND e.school_year_id = ?
            AND e.enrollment_status = 'Approved'
        ) AS student_count
    FROM sections s
    LEFT JOIN users u ON s.adviser_id = u.user_id
    WHERE s.is_active = 1
    ORDER BY s.grade_level, s.section_name
", [$active_sy['sy_id']]);

$sections_by_grade = [];
foreach ($all_sections as $sec) {
    $sections_by_grade[$sec['grade_level']][] = $sec;
}

// ============================================================
// SELECTED SECTION -> SUBJECTS
// Every subject assigned to this section, regardless of which teacher
// owns it — the admin is monitoring the whole section, not one teacher's
// slice of it.
// ============================================================
$section_info = null;
$section_subjects = [];

if ($selected_section > 0) {
    foreach ($all_sections as $sec) {
        if ($sec['section_id'] == $selected_section) {
            $section_info = $sec;
            break;
        }
    }

    if ($section_info) {
        $section_subjects = getRecords("
            SELECT
                ts.subject_code,
                ts.teacher_id,
                sub.subject_name,
                sub.subject_type,
                CONCAT(u.last_name, ', ', u.first_name) AS teacher_name
            FROM teacher_subjects ts
            INNER JOIN subjects sub ON ts.subject_code = sub.subject_code
            LEFT JOIN users u ON ts.teacher_id = u.user_id
            WHERE ts.section_id = ?
            AND ts.school_year_id = ?
            AND sub.is_active = 1
            ORDER BY sub.subject_name
        ", [$selected_section, $active_sy['sy_id']]);
    }
}

// ============================================================
// SELECTED SUBJECT -> STUDENTS + THEIR EXISTING GRADES FOR THE SELECTED
// TERM. This reads straight from the same `grades` table the Teacher
// flow already writes to — nothing here is computed, only displayed.
// A student with no row yet just shows as "Not yet graded".
// ============================================================
$selected_subject_info = null;
$section_students = [];

if ($selected_section > 0 && !empty($selected_subject) && $section_info) {
    foreach ($section_subjects as $subj) {
        if ($subj['subject_code'] === $selected_subject) {
            $selected_subject_info = $subj;
            break;
        }
    }

    if ($selected_subject_info) {
        $section_students = getRecords("
            SELECT
                s.student_id, s.lrn, s.first_name, s.last_name,
                g.written_work, g.performance_task, g.examinations,
                g.final_grade, g.remarks
            FROM students s
            INNER JOIN enrollments e ON s.student_id = e.student_id
                AND e.section_id = ?
                AND e.school_year_id = ?
                AND e.enrollment_status = 'Approved'
            LEFT JOIN grades g ON g.student_id = s.student_id
                AND g.subject_code = ?
                AND g.section_id = ?
                AND g.school_year_id = ?
                AND g.term = ?
            WHERE s.is_active = 1
            ORDER BY s.last_name, s.first_name
        ", [$selected_section, $active_sy['sy_id'], $selected_subject, $selected_section, $active_sy['sy_id'], $term]);
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<style>
    /* Grade Level cards come from the shared global .grade-card component
       in static/css/style.css (same one used by Student/Teacher/Subject
       Management and the Teacher grading flow). Section cards come from
       the shared global .section-card component the same way (its
       .sec-name/.sec-meta inner classes are what the global rules target,
       so this page renders identically to Student/Teacher Management's
       section step instead of keeping its own slightly-different copy).
       Everything else here is scoped under .grading-admin-view. */

    .grading-admin-view .page-subtitle {
        font-size: 15px;
        color: var(--text-muted);
        margin-top: 2px;
    }

    .grading-admin-view .view-only-badge {
        background: var(--primary-soft);
        color: var(--primary);
        font-weight: 600;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 15px;
        white-space: nowrap;
    }

    /* Simple, clean breadcrumb — plain text + chevrons, not pill buttons.
       Only rendered once the admin has actually selected a grade level;
       the very first screen shows no breadcrumb at all. */
    .grading-admin-view .admin-crumb {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        font-size: 15px;
        margin-bottom: 20px;
    }
    .grading-admin-view .admin-crumb a {
        color: var(--primary);
        font-weight: 500;
        text-decoration: none;
    }
    .grading-admin-view .admin-crumb a:hover { text-decoration: underline; }
    .grading-admin-view .admin-crumb a:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
        border-radius: 3px;
    }
    .grading-admin-view .admin-crumb .crumb-current {
        color: var(--ink);
        font-weight: 600;
    }
    .grading-admin-view .admin-crumb .crumb-sep {
        color: var(--text-muted-2, var(--border));
        font-size: 13.5px;
    }

    /* Compact hint panel shown below the Grade Level cards on the very
       first screen — never more than a small note, never half the page. */
    .grading-admin-view .empty-hint-panel {
        margin-top: 20px;
        padding: 16px 20px;
        background: var(--surface-muted);
        border: 1px dashed var(--border);
        border-radius: var(--radius);
        max-width: 480px;
    }
    .grading-admin-view .empty-hint-panel h6 {
        color: var(--ink);
        font-weight: 600;
        font-size: 15px;
        margin-bottom: 4px;
    }
    .grading-admin-view .empty-hint-panel p {
        margin: 0;
        font-size: 15px;
        color: var(--text-muted);
    }

    /* Subject cards — the exact same card the Teacher's own "My Subjects"
       step already uses (modules/grading/index.php: subject name, code,
       a subject-type badge, then a button) so this reads as the same
       component in both roles instead of a differently-shaped card. The
       button says "View Students" either way; here it just leads to a
       read-only table instead of the encode flow. */
    .grading-admin-view .subject-card {
        cursor: pointer;
        border: 1px solid var(--border) !important;
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm) !important;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
        height: 100%;
    }
    .grading-admin-view .subject-card:hover {
        border-color: var(--primary) !important;
        box-shadow: var(--shadow-md) !important;
    }
    .grading-admin-view .subject-card .subject-teacher {
        font-size: 13.5px;
        color: var(--text-muted);
        margin-top: 6px;
    }

    .grading-admin-view .student-name { font-weight: 600; color: var(--ink); }
    /* Table text matched to the rest of the system. The padding selector
       mirrors Bootstrap's own so table-sm cannot shrink a table back. */
    .grading-admin-view .table > :not(caption) > * > * {
        padding: 14px 16px;
    }
    .grading-admin-view .table thead th {
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        font-weight: 600;
        vertical-align: middle;
    }
    .grading-admin-view .table tbody td {
        font-size: 15px;
        vertical-align: middle;
    }
    .grading-admin-view td .badge {
        padding: 5px 14px;
        border-radius: 20px;
        font-size: 14.5px;
        font-weight: 600;
    }
    .grading-admin-view .btn-eye {
        background: var(--primary-soft);
        color: var(--primary);
        border: none;
        width: 42px;
        height: 42px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        transition: all .2s ease;
    }
    .grading-admin-view .btn-eye:hover { background: var(--primary); color: #fff; }
    .grading-admin-view .btn-eye:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

    .grading-admin-view .term-select {
        max-width: 200px;
        border-radius: 8px;
        border: 1px solid var(--border);
        font-size: 15px;
        padding: 8px 12px;
    }

    /* ---- Bigger, easier-to-read text ---- */
    .grading-admin-view h2 { font-size: 32px; }
    .grading-admin-view .page-subtitle { font-size: 16px; }
    .grading-admin-view .view-only-badge { font-size: 15px; padding: 9px 18px; }
    .grading-admin-view .admin-crumb { font-size: 16px; }
    .grading-admin-view .admin-crumb .crumb-sep { font-size: 14.5px; }
    .grading-admin-view h4 { font-size: 24px; }
    .grading-admin-view .btn-sm { font-size: 15.5px; padding: 8px 14px; }
    .grading-admin-view .grade-card .grade-num { font-size: 36px !important; }
    .grading-admin-view .grade-card .grade-label { font-size: 18px !important; }
    .grading-admin-view .grade-card .section-count { font-size: 15px !important; }
    .grading-admin-view .section-card .sec-name { font-size: 20px !important; }
    .grading-admin-view .section-card .sec-meta { font-size: 15px !important; }
    .grading-admin-view .section-card .card-body { padding-top: 24px; padding-bottom: 24px; }
    .grading-admin-view .empty-hint-panel h6 { font-size: 16px; }
    .grading-admin-view .empty-hint-panel p { font-size: 15px; }
    .grading-admin-view .subject-card .subject-teacher { font-size: 15px; }
    .grading-admin-view .subject-card h5, .grading-admin-view .subject-card .card-title { font-size: 18px; }
    .grading-admin-view .table thead th { font-size: 14px; }
    .grading-admin-view .term-select { font-size: 15px; }
</style>

<div class="grading-admin-view">

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
            <div>
                <h2 class="mb-0"><i class="fas fa-calculator text-primary me-2"></i>Grade Management</h2>
                <div class="page-subtitle">View class records and student grades.</div>
            </div>
            <span class="view-only-badge"><i class="fas fa-eye me-1"></i> View Only</span>
        </div>
    </div>
</div>

<?php
// ============================================================
// PROGRESSIVE BREADCRUMB — nothing at all on the very first screen;
// one more segment appears for every level the admin drills into.
// Each ancestor segment links back to that level's own listing.
// ============================================================
if (!empty($selected_grade)):
    $crumb_qs_grade = 'grade=' . urlencode($selected_grade);
    $crumb_qs_section = $crumb_qs_grade . ($selected_section > 0 ? '&section_id=' . $selected_section : '');
?>
<div class="admin-crumb">
    <a href="?<?php echo $crumb_qs_grade; ?>"><?php echo htmlspecialchars($selected_grade); ?></a>

    <?php if ($selected_section > 0 && $section_info): ?>
        <span class="crumb-sep"><i class="fas fa-chevron-right"></i></span>
        <a href="?<?php echo $crumb_qs_section; ?>"><?php echo htmlspecialchars($section_info['section_name']); ?></a>

        <?php if (!empty($selected_subject) && $selected_subject_info): ?>
            <span class="crumb-sep"><i class="fas fa-chevron-right"></i></span>
            <a href="?<?php echo $crumb_qs_section; ?>&subject_code=<?php echo urlencode($selected_subject); ?>">
                <?php echo htmlspecialchars($selected_subject_info['subject_name']); ?>
            </a>
            <span class="crumb-sep"><i class="fas fa-chevron-right"></i></span>
            <span class="crumb-current">Students</span>
        <?php else: ?>
            <span class="crumb-sep"><i class="fas fa-chevron-right"></i></span>
            <span class="crumb-current">Subjects</span>
        <?php endif; ?>
    <?php else: ?>
        <span class="crumb-sep"><i class="fas fa-chevron-right"></i></span>
        <span class="crumb-current">Sections</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($selected_section > 0 && !empty($selected_subject) && $selected_subject_info): ?>
    <!-- ============================================================ -->
    <!-- STEP 4: STUDENTS + EXISTING GRADES FOR THE SELECTED SUBJECT   -->
    <!-- (read-only — no inputs, no save, no encode links anywhere)    -->
    <!-- ============================================================ -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0">
            <i class="fas fa-book text-primary me-2"></i>
            <?php echo htmlspecialchars($selected_subject_info['subject_name']); ?>
            <span class="badge bg-secondary ms-2" style="font-size:15px;padding:6px 13px;"><?php echo count($section_students); ?></span>
            <div class="mt-1" style="font-size: 15px; font-weight: 400; color: var(--text-muted);">
                <i class="fas fa-chalkboard-teacher me-1"></i> Teacher:
                <?php echo !empty($selected_subject_info['teacher_name']) ? htmlspecialchars($selected_subject_info['teacher_name']) : 'Not yet assigned'; ?>
            </div>
        </h4>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <select class="term-select" onchange="window.location.href='?grade=<?php echo urlencode($selected_grade); ?>&section_id=<?php echo $selected_section; ?>&subject_code=<?php echo urlencode($selected_subject); ?>&term='+encodeURIComponent(this.value)">
                <?php foreach ($terms as $t): ?>
                    <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $t === $term ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                <?php endforeach; ?>
            </select>
            <a href="?grade=<?php echo urlencode($selected_grade); ?>&section_id=<?php echo $selected_section; ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to Subjects
            </a>
        </div>
    </div>

    <?php if (empty($section_students)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i> No students enrolled in this section yet.
        </div>
    <?php else: ?>
        <div class="card shadow-sm rounded-3">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>LRN</th>
                                <th>Student Name</th>
                                <th>Written Works</th>
                                <th>Performance Tasks</th>
                                <th><?php echo htmlspecialchars($exam_label); ?></th>
                                <th>Final Grade</th>
                                <th>Status</th>
                                <th style="width: 70px;" class="text-center">View</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; ?>
                            <?php foreach ($section_students as $student): ?>
                                <?php $has_grade = $student['final_grade'] !== null; ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($student['lrn']); ?></strong></td>
                                    <td class="student-name"><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></td>
                                    <td><?php echo $has_grade ? number_format($student['written_work'], 2) : '&mdash;'; ?></td>
                                    <td><?php echo $has_grade ? number_format($student['performance_task'], 2) : '&mdash;'; ?></td>
                                    <td><?php echo $has_grade ? number_format($student['examinations'], 2) : '&mdash;'; ?></td>
                                    <td style="font-weight:600;"><?php echo $has_grade ? number_format($student['final_grade'], 2) : '&mdash;'; ?></td>
                                    <td>
                                        <?php if ($has_grade): ?>
                                            <span class="badge" style="background:<?php echo $student['final_grade'] >= 75 ? '#EAF7F1' : '#FDEEEE'; ?>;color:<?php echo $student['final_grade'] >= 75 ? '#238863' : '#C94A4A'; ?>;">
                                                <?php echo htmlspecialchars($student['remarks'] ?? ($student['final_grade'] >= 75 ? 'Passed' : 'Failed')); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Not yet graded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="view_student.php?id=<?php echo $student['student_id']; ?>&grade=<?php echo urlencode($selected_grade); ?>&section_id=<?php echo $selected_section; ?>"
                                           class="btn-eye" title="View student">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($selected_section > 0 && $section_info): ?>
    <!-- ============================================================ -->
    <!-- STEP 3: SUBJECTS OFFERED IN THE SELECTED SECTION              -->
    <!-- ============================================================ -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0">
            <i class="fas fa-book text-primary me-2"></i>
            Subjects in <?php echo htmlspecialchars($section_info['grade_level'] . ' - ' . $section_info['section_name']); ?>
            <span class="badge bg-secondary ms-2" style="font-size:15px;padding:6px 13px;"><?php echo count($section_subjects); ?></span>
        </h4>
        <a href="?grade=<?php echo urlencode($selected_grade); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to Sections
        </a>
    </div>

    <?php if (empty($section_subjects)): ?>
        <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i> No subjects have been assigned to this section yet.</div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($section_subjects as $subject): ?>
                <div class="col-md-4 col-lg-3">
                    <div class="card subject-card border-0 shadow-sm">
                        <div class="card-body text-center">
                            <h6 class="mb-1" style="color: var(--ink);font-weight:600;"><?php echo htmlspecialchars($subject['subject_name']); ?></h6>
                            <small style="color: var(--text-muted);"><?php echo htmlspecialchars($subject['subject_code']); ?></small>
                            <div class="mt-2">
                                <span style="background: var(--surface-hover);color: var(--text-muted);padding:3px 8px;border-radius:4px;font-size:12.5px;font-weight:500;"><?php echo htmlspecialchars($subject['subject_type'] ?? 'General'); ?></span>
                            </div>
                            <div class="subject-teacher">
                                <i class="fas fa-chalkboard-teacher me-1"></i>
                                <?php echo !empty($subject['teacher_name']) ? htmlspecialchars($subject['teacher_name']) : 'Not yet assigned'; ?>
                            </div>
                            <a href="?grade=<?php echo urlencode($selected_grade); ?>&section_id=<?php echo $selected_section; ?>&subject_code=<?php echo urlencode($subject['subject_code']); ?>"
                               class="btn btn-sm mt-3"
                               style="background:#14213D;color:#fff;border:none;padding:5px 16px;font-size:14.5px;">
                                View Students
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php elseif (!empty($selected_grade)): ?>
    <!-- ============================================================ -->
    <!-- STEP 2: SECTIONS UNDER SELECTED GRADE                         -->
    <!-- ============================================================ -->
    <?php $grade_sections = $sections_by_grade[$selected_grade] ?? []; ?>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0">
            <i class="fas fa-layer-group text-primary me-2"></i>
            Sections in <?php echo htmlspecialchars($selected_grade); ?>
            <span class="badge bg-secondary ms-2" style="font-size:15px;padding:6px 13px;"><?php echo count($grade_sections); ?></span>
        </h4>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to Grade Levels
        </a>
    </div>

    <?php if (empty($grade_sections)): ?>
        <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i> No active sections under this grade level.</div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($grade_sections as $section): ?>
                <div class="col-md-4 col-lg-3">
                    <div class="card section-card border-0"
                         role="button" tabindex="0"
                         aria-label="View subjects and grades for <?php echo htmlspecialchars($section['section_name']); ?>"
                         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
                         onclick="window.location.href='?grade=<?php echo urlencode($selected_grade); ?>&section_id=<?php echo $section['section_id']; ?>'">
                        <div class="card-body text-center">
                            <div class="sec-name text-primary">
                                <i class="fas fa-users me-2"></i><?php echo htmlspecialchars($section['section_name']); ?>
                            </div>
                            <div class="sec-meta">
                                <?php echo !empty($section['adviser_name']) ? htmlspecialchars($section['adviser_name']) : 'No adviser assigned'; ?>
                            </div>
                            <div class="sec-meta"><?php echo $section['student_count'] ?? 0; ?> student<?php echo ($section['student_count'] ?? 0) == 1 ? '' : 's'; ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

<?php else: ?>
    <!-- ============================================================ -->
    <!-- STEP 1: GRADE LEVEL CARDS (initial screen — no breadcrumb,    -->
    <!-- no Sections/Students navigation shown until something is     -->
    <!-- actually selected)                                            -->
    <!-- ============================================================ -->
    <div class="row g-3">
        <?php foreach ($grade_levels as $grade):
            $grade_sections = $sections_by_grade[$grade] ?? [];
            if (empty($grade_sections)) continue;
            $color = $grade_palette[$grade]['accent'] ?? '#667085';
            $soft  = $grade_palette[$grade]['soft'] ?? '#f8f9fb';
            $display = str_replace('Grade ', '', $grade);
            $total_students = array_sum(array_column($grade_sections, 'student_count'));
            $sec_count = count($grade_sections);
        ?>
            <div class="col-md-4 col-lg-2">
                <div class="card grade-card border-0 shadow-sm"
                     style="--accent: <?php echo $color; ?>; --accent-soft: <?php echo $soft; ?>;"
                     role="button" tabindex="0"
                     aria-label="View sections, subjects and students in <?php echo htmlspecialchars($grade); ?>"
                     onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
                     onclick="window.location.href='?grade=<?php echo urlencode($grade); ?>'">
                    <div class="card-body text-center">
                        <div class="grade-icon">
                            <span class="grade-num"><?php echo $display; ?></span>
                        </div>
                        <div class="grade-label"><?php echo $grade; ?></div>
                        <div class="section-count">
                            <?php echo $sec_count . ' section' . ($sec_count == 1 ? '' : 's'); ?>
                            &middot; <?php echo $total_students; ?> student<?php echo $total_students == 1 ? '' : 's'; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($all_sections)): ?>
        <div class="alert alert-warning mt-3">
            <i class="fas fa-exclamation-triangle me-2"></i> No active sections found for the current school year.
        </div>
    <?php else: ?>
        <div class="empty-hint-panel">
            <h6><i class="fas fa-hand-pointer me-1"></i> Select a Grade Level</h6>
            <p>Choose a grade level above to view its sections, subjects, students and class records.</p>
        </div>
    <?php endif; ?>
<?php endif; ?>

</div><!-- /.grading-admin-view -->

<?php include __DIR__ . '/../../includes/footer.php'; ?>
