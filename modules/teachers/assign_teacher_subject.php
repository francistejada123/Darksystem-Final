<?php
// modules/teachers/assign_subject.php
// I-assign ang teacher sa subjects at sections

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';

if (!hasRole('Administrator')) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'Assign Teacher Subject';
$current_page = 'teachers';
$base_url = '../../';
$errors = [];
$success = false;

// Kunin ang listahan ng teachers (role_id = 3)
$teachers = getRecords("SELECT user_id, first_name, last_name, specialization
                        FROM users
                        WHERE role_id = 3 AND is_active = 1
                        ORDER BY last_name, first_name");

// Kunin ang listahan ng subjects -- strand and term included so the form
// below can filter to only what matches the selected section (same
// grade/strand/term scoping as Teacher Management's "Assign Subject to
// Teacher" flow and modules/teachers/assign.php).
$subjects = getRecords("SELECT subject_code, subject_name, grade_level, strand, term
                        FROM subjects
                        WHERE is_active = 1
                        ORDER BY grade_level, subject_name");

// Kunin ang listahan ng sections
$sections = getRecords("SELECT section_id, section_name, grade_level, strand
                        FROM sections
                        WHERE is_active = 1
                        ORDER BY grade_level, section_name");

// Kunin ang active school year
$active_sy = getRecord("SELECT sy_id, sy_name FROM school_years WHERE is_active = 1");
$current_term_live = gh_getCurrentTerm($active_sy['sy_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid security token. Please refresh the page and try again.');
    }

    $teacher_id = $_POST['teacher_id'] ?? 0;
    $subject_code = $_POST['subject_code'] ?? '';
    $section_id = $_POST['section_id'] ?? 0;
    $school_year_id = $active_sy['sy_id'] ?? 0;

    // Validation
    if (empty($teacher_id)) $errors[] = 'Please select a teacher.';
    if (empty($subject_code)) $errors[] = 'Please select a subject.';
    if (empty($section_id)) $errors[] = 'Please select a section.';
    if (empty($school_year_id)) $errors[] = 'No active school year found.';

    // SERVER-SIDE guard against a subject/section mismatch -- see
    // modules/teachers/assign.php for the same check and its rationale.
    if (empty($errors)) {
        $picked_section = null;
        foreach ($sections as $sec) {
            if ((int)$sec['section_id'] === (int)$section_id) { $picked_section = $sec; break; }
        }
        $picked_subject = null;
        foreach ($subjects as $subj) {
            if ($subj['subject_code'] === $subject_code) { $picked_subject = $subj; break; }
        }
        if (!$picked_section || !$picked_subject) {
            $errors[] = 'Invalid subject or section selected.';
        } elseif ($picked_subject['grade_level'] !== $picked_section['grade_level']) {
            $errors[] = 'That subject does not belong to this section\'s grade level.';
        } elseif (!empty($picked_section['strand']) && $picked_subject['strand'] !== $picked_section['strand']) {
            $errors[] = 'That subject does not belong to this section\'s strand.';
        } elseif (!empty($picked_section['strand']) && !empty($picked_subject['term']) && $picked_subject['term'] !== $current_term_live) {
            $errors[] = $current_term_live
                ? 'That subject belongs to ' . htmlspecialchars($picked_subject['term']) . ', but ' . htmlspecialchars($current_term_live) . ' is the term currently active.'
                : 'No term is active today, so a Senior High subject cannot be assigned right now. Set this term\'s dates first (Teacher Management\'s "Term Dates" button).';
        }
    }

    // Check if duplicate
    if (empty($errors)) {
        // One teacher per subject in a section (the database enforces it too).
        $check = getRecord("SELECT ts.teacher_id, u.first_name, u.last_name FROM teacher_subjects ts
                           LEFT JOIN users u ON u.user_id = ts.teacher_id
                           WHERE ts.subject_code = ? AND ts.section_id = ? AND ts.school_year_id = ?",
                           [$subject_code, $section_id, $school_year_id]);
        if ($check) {
            $errors[] = ((int) $check['teacher_id'] === (int) $teacher_id)
                ? 'This teacher is already assigned to this subject and section.'
                : 'This subject is already assigned to ' . trim(($check['first_name'] ?? '') . ' ' . ($check['last_name'] ?? '')) . ' for this section. Unassign it first.';
        }
    }

    // If no errors, insert
    if (empty($errors)) {
        $sql = "INSERT INTO teacher_subjects (teacher_id, subject_code, section_id, school_year_id)
                VALUES (?, ?, ?, ?)";
        $result = executeQuery($sql, [$teacher_id, $subject_code, $section_id, $school_year_id]);

        if ($result) {
            $teacher = getRecord("SELECT first_name, last_name FROM users WHERE user_id = ?", [$teacher_id]);
            logActivity($_SESSION['user_id'], 'Assigned subject to teacher', 'teachers',
                       "Assigned $subject_code to {$teacher['first_name']} {$teacher['last_name']}");
            $success = true;
            $_SESSION['success_message'] = 'Subject assigned successfully!';
        } else {
            $errors[] = 'Failed to assign subject. Please try again.';
        }
    }
}

$csrf_token = generateCSRFToken();
include '../../includes/header.php';
?>

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-chalkboard-teacher text-primary me-2"></i>Assign Subject to Teacher</h2>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Teachers
            </a>
        </div>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i>Subject assigned successfully!
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
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

<?php if (!$current_term_live): ?>
    <div class="alert alert-warning" style="font-size:14.5px;">
        <i class="fas fa-triangle-exclamation me-1"></i>
        No term is active today. A Senior High subject cannot be assigned until this term's dates are set
        (Teacher Management's "Term Dates" button) -- Junior High assignment is unaffected.
    </div>
<?php endif; ?>
<div class="card shadow-sm rounded-3">
    <div class="card-body">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Select Teacher</label>
                    <select name="teacher_id" class="form-select" required>
                        <option value="">-- Select Teacher --</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo $teacher['user_id']; ?>">
                                <?php echo htmlspecialchars($teacher['last_name'] . ', ' . $teacher['first_name']); ?>
                                <?php if (!empty($teacher['specialization'])): ?>
                                    (<?php echo htmlspecialchars($teacher['specialization']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Section</label>
                    <select name="section_id" id="atsSectionSelect" class="form-select" required>
                        <option value="">-- Select Section --</option>
                        <?php foreach ($sections as $section): ?>
                            <option value="<?php echo $section['section_id']; ?>"
                                    data-grade="<?php echo htmlspecialchars($section['grade_level']); ?>"
                                    data-strand="<?php echo htmlspecialchars($section['strand'] ?? ''); ?>">
                                <?php echo htmlspecialchars($section['section_name']); ?>
                                (<?php echo htmlspecialchars($section['grade_level']); ?>
                                <?php echo !empty($section['strand']) ? '- ' . ($section['strand'] === 'ASSH' ? 'ASSH Cluster' : $section['strand']) : ''; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Subject</label>
                    <select name="subject_code" id="atsSubjectSelect" class="form-select" required disabled>
                        <option value="">-- Select a section first --</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo $subject['subject_code']; ?>"
                                    data-grade="<?php echo htmlspecialchars($subject['grade_level']); ?>"
                                    data-strand="<?php echo htmlspecialchars($subject['strand'] ?? ''); ?>"
                                    data-term="<?php echo htmlspecialchars($subject['term'] ?? ''); ?>"
                                    hidden>
                                <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
                                (<?php echo htmlspecialchars($subject['grade_level']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">School Year</label>
                    <input type="text" class="form-control" value="<?php echo $active_sy['sy_name'] ?? 'No Active SY'; ?>" disabled>
                    <input type="hidden" name="school_year_id" value="<?php echo $active_sy['sy_id'] ?? 0; ?>">
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4">
                <a href="index.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Assign Subject
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Same section-driven subject filtering as modules/teachers/assign.php --
// see that file for the full rationale. The server re-checks all of this
// on submit; this is only for a clean picking experience.
(function () {
    var CURRENT_TERM = <?php echo json_encode($current_term_live); ?>;
    var sectionSelect = document.getElementById('atsSectionSelect');
    var subjectSelect = document.getElementById('atsSubjectSelect');
    var subjectOptions = Array.prototype.slice.call(subjectSelect.options);

    sectionSelect.addEventListener('change', function () {
        var opt = sectionSelect.options[sectionSelect.selectedIndex];
        var grade = opt ? opt.getAttribute('data-grade') : null;
        var strand = opt ? opt.getAttribute('data-strand') : '';
        subjectSelect.value = '';

        if (!grade) {
            subjectSelect.disabled = true;
            subjectOptions.forEach(function (o) { o.hidden = true; });
            return;
        }

        subjectSelect.disabled = false;
        subjectOptions.forEach(function (o) {
            if (!o.value) { o.hidden = false; return; }
            var matchesGrade = o.getAttribute('data-grade') === grade;
            var matchesStrand = strand ? (o.getAttribute('data-strand') === strand) : !o.getAttribute('data-strand');
            var term = o.getAttribute('data-term');
            var matchesTerm = strand ? (term === CURRENT_TERM) : true;
            o.hidden = !(matchesGrade && matchesStrand && matchesTerm);
        });
    });
})();
</script>

<?php include '../../includes/footer.php'; ?>