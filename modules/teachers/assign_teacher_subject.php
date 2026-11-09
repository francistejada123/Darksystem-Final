<?php
// modules/teachers/assign_subject.php
// I-assign ang teacher sa subjects at sections

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';
require_once __DIR__ . '/../../includes/teacher_department.php';
require_once __DIR__ . '/../../includes/fixed_timetable.php';

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
$teachers = getRecords("SELECT user_id, first_name, last_name, specialization, department_level
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

// Read-only fixed timetable lookup (JHS Grade 7-10 only, computed live --
// no teacher needed). Any other grade gets {ok:false}; the form is untouched.
if (isset($_GET['lookup_schedule'])) {
    header('Content-Type: application/json; charset=utf-8');
    $lk_subject = sanitize($_GET['subject_code'] ?? '');
    $lk_section = intval($_GET['section_id'] ?? 0);
    $lk_sec = $lk_section ? getRecord('SELECT section_id, grade_level FROM sections WHERE section_id = ?', [$lk_section]) : null;
    $lk_jhs = $lk_sec && in_array($lk_sec['grade_level'], ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'], true);
    if (!$lk_jhs || $lk_subject === '') {
        echo json_encode(['ok' => false, 'has_schedule' => false]);
        exit;
    }
    $lk_ord = array_column(
        getRecords("SELECT subject_code FROM subjects WHERE is_active = 1 AND grade_level = ? ORDER BY subject_name", [$lk_sec['grade_level']]),
        'subject_code'
    );
    $lk_slot = ftSlotFor($lk_ord, $lk_subject, $lk_sec['grade_level']);
    if (!$lk_slot) {
        echo json_encode(['ok' => true, 'has_schedule' => false]);
        exit;
    }
    echo json_encode(['ok' => true, 'has_schedule' => true, 'shift' => $lk_slot['shift_label'], 'summary' => $lk_slot['summary']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid security token. Please refresh the page and try again.');
    }

    $teacher_id = $_POST['teacher_id'] ?? 0;
    $subject_code = $_POST['subject_code'] ?? '';
    $section_id = $_POST['section_id'] ?? 0;
    $school_year_id = $active_sy['sy_id'] ?? 0;

    // Department / Level comes from the teacher's registration record and is
    // the source of truth: JHS teachers may only take Grade 7-10 classes,
    // SHS teachers only Grade 11-12. Checked here on every POST so the rule
    // holds even if the form's dropdowns are bypassed or tampered with.
    $dept_check = ['ok' => true, 'message' => ''];

    // Validation
    if (empty($teacher_id)) $errors[] = 'Please select a teacher.';
    if (empty($subject_code)) $errors[] = 'Please select a subject.';
    if (empty($section_id)) $errors[] = 'Please select a section.';
    if (empty($school_year_id)) $errors[] = 'No active school year found.';

    if (empty($errors)) {
        $sec_row = getRecord('SELECT section_id, grade_level FROM sections WHERE section_id = ?', [(int) $section_id]);
        if (!$sec_row) {
            $errors[] = 'Invalid section selected.';
        } else {
            $dept_check = tdCheckAssignment(tdDepartmentFor($teacher_id), $sec_row['grade_level']);
            if (!$dept_check['ok']) $errors[] = $dept_check['message'];
        }
    }

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
        } elseif (!$dept_check['ok']) {
            // Already reported above; keep it first so the reason is clear.
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

    // JHS Grade 7-10 only: auto-attach the fixed timetable slot (alternate
    // days, 45-min period inside the shift window). Best-effort: when the
    // slot is occupied (day/time overlap, never subject name), the teacher is
    // still assigned and the schedule is left for the Schedule button.
    // Senior High keeps the existing behaviour exactly.
    $auto_slot = null;
    $auto_note = '';
    if (empty($errors) && in_array($picked_section['grade_level'] ?? '', ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'], true)) {
        $auto_ord = array_column(
            getRecords("SELECT subject_code FROM subjects WHERE is_active = 1 AND grade_level = ? ORDER BY subject_name", [$picked_section['grade_level']]),
            'subject_code'
        );
        $auto_slot = ftSlotFor($auto_ord, $subject_code, $picked_section['grade_level']);
        if ($auto_slot) {
            foreach ($auto_slot['days'] as $auto_day) {
                $auto_tconf = getRecord(
                    "SELECT sub.subject_name, sec.section_name, sec.grade_level
                     FROM teacher_class_schedule tcs
                     JOIN subjects sub ON sub.subject_code = tcs.subject_code
                     JOIN sections sec ON sec.section_id = tcs.section_id
                     WHERE tcs.school_year_id = ? AND tcs.day_of_week = ? AND tcs.is_active = 1 AND tcs.teacher_id = ?
                     AND NOT (tcs.subject_code = ? AND tcs.section_id = ?)
                     AND ? < tcs.end_time AND ? > tcs.start_time LIMIT 1",
                    [$school_year_id, $auto_day, $teacher_id, $subject_code, $section_id, $auto_slot['start'], $auto_slot['end']]
                );
                if ($auto_tconf) {
                    $auto_note = 'no auto-schedule: teacher has ' . $auto_tconf['subject_name']
                        . ' (' . $auto_tconf['grade_level'] . '-' . $auto_tconf['section_name'] . ') at that time';
                    break;
                }
                $auto_sconf = getRecord(
                    "SELECT sub.subject_name FROM teacher_class_schedule tcs
                     JOIN subjects sub ON sub.subject_code = tcs.subject_code
                     WHERE tcs.school_year_id = ? AND tcs.day_of_week = ? AND tcs.is_active = 1 AND tcs.section_id = ?
                     AND NOT (tcs.subject_code = ? AND tcs.section_id = ?)
                     AND ? < tcs.end_time AND ? > tcs.start_time LIMIT 1",
                    [$school_year_id, $auto_day, $section_id, $subject_code, $section_id, $auto_slot['start'], $auto_slot['end']]
                );
                if ($auto_sconf) {
                    $auto_note = 'no auto-schedule: section already has ' . $auto_sconf['subject_name'] . ' at that time';
                    break;
                }
            }
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
            if ($auto_slot && $auto_note === '') {
                executeQuery(
                    "DELETE FROM teacher_class_schedule WHERE teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ?",
                    [$teacher_id, $subject_code, $section_id, $school_year_id]
                );
                $auto_saved = true;
                foreach ($auto_slot['days'] as $auto_day) {
                    $auto_ok = executeQuery(
                        "INSERT INTO teacher_class_schedule (teacher_id, subject_code, section_id, school_year_id, day_of_week, start_time, end_time, shift_type, shift, is_active)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                        [$teacher_id, $subject_code, $section_id, $school_year_id, $auto_day, $auto_slot['start'], $auto_slot['end'], $auto_slot['shift_type'], $auto_slot['shift']]
                    );
                    if (!$auto_ok) { $auto_saved = false; break; }
                }
                $_SESSION['success_message'] = $auto_saved
                    ? 'Subject assigned successfully! Class schedule attached (' . $auto_slot['shift_label'] . ' · ' . $auto_slot['summary'] . ').'
                    : 'Subject assigned successfully! Note: the class schedule could not be saved — set it via Schedule.';
            } elseif ($auto_slot) {
                $_SESSION['success_message'] .= ' Note: ' . $auto_note . ' — set it via Schedule.';
            }
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
                    <select name="teacher_id" id="atsTeacherSelect" class="form-select" required>
                        <option value="">-- Select Teacher --</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo $teacher['user_id']; ?>"
                                    data-dept="<?php echo htmlspecialchars(tdNormalize($teacher['department_level'] ?? '')); ?>">
                                <?php echo htmlspecialchars($teacher['last_name'] . ', ' . $teacher['first_name']); ?>
                                <?php if (!empty($teacher['specialization'])): ?>
                                    (<?php echo htmlspecialchars($teacher['specialization']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted" id="atsDeptHint">Pick a teacher to load only the classes of their Department / Level.</small>
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

            <div class="alert alert-info mt-3 mb-0" id="atsFixedBox" style="display:none;font-size:14.5px;"></div>

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
    var teacherSelect = document.getElementById('atsTeacherSelect');
    var deptHint = document.getElementById('atsDeptHint');
    var sectionSelect = document.getElementById('atsSectionSelect');
    var subjectSelect = document.getElementById('atsSubjectSelect');
    var subjectOptions = Array.prototype.slice.call(subjectSelect.options);
    var sectionOptions = Array.prototype.slice.call(sectionSelect.options);

    // The chosen teacher's Department / Level (saved at registration) decides
    // which grade levels are selectable: JHS = Grade 7-10, SHS = Grade 11-12.
    // The server re-checks this on submit; this is only for a clean picking
    // experience and to stop wrong choices appearing at all.
    function departmentGrades(dept) {
        if (dept === 'SHS') return ['Grade 11', 'Grade 12'];
        if (dept === 'JHS') return ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'];
        return null; // not set on file: offer everything
    }

    function applyDepartment() {
        var opt = teacherSelect.options[teacherSelect.selectedIndex];
        var dept = opt ? opt.getAttribute('data-dept') : '';
        var allowed = departmentGrades(dept);
        sectionSelect.value = '';

        sectionOptions.forEach(function (o) {
            if (!o.value) { o.hidden = false; return; }
            o.hidden = !!(allowed && allowed.indexOf(o.getAttribute('data-grade')) === -1);
        });

        if (deptHint) {
            deptHint.textContent = !dept
                ? 'This teacher has no Department / Level on file \u2014 all grade levels are shown.'
                : 'Department / Level: ' + dept + ' \u2014 only ' + (dept === 'SHS' ? 'Grade 11 and Grade 12' : 'Grade 7, 8, 9 and 10') + ' classes are selectable.';
        }
        filterSubjects();
    }

    function filterSubjects() {
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
    }

    // JHS only: show the fixed timetable slot (Shift + Days + Time) read-only
    // once Subject + Section are picked. Display only -- never feeds the POST.
    var fixedBox = document.getElementById('atsFixedBox');
    function refreshFixedSchedule() {
        if (!fixedBox) return;
        var secOpt = sectionSelect.options[sectionSelect.selectedIndex];
        var grade = secOpt ? secOpt.getAttribute('data-grade') : null;
        var isJhs = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'].indexOf(grade) >= 0;
        var code = subjectSelect.value;
        if (!isJhs || !code || !sectionSelect.value) { fixedBox.style.display = 'none'; return; }
        fixedBox.style.display = '';
        fixedBox.textContent = 'Loading class schedule…';
        fetch('assign_teacher_subject.php?lookup_schedule=1&subject_code=' + encodeURIComponent(code) + '&section_id=' + encodeURIComponent(sectionSelect.value))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok || !d.has_schedule) { fixedBox.style.display = 'none'; return; }
                fixedBox.innerHTML = '<strong>Class schedule (read-only):</strong> ' + d.shift + ' · ' + d.summary
                    + ' — auto-attached on Assign.';
            })
            .catch(function () { fixedBox.style.display = 'none'; });
    }

    teacherSelect.addEventListener('change', applyDepartment);
    sectionSelect.addEventListener('change', filterSubjects);
    sectionSelect.addEventListener('change', refreshFixedSchedule);
    subjectSelect.addEventListener('change', refreshFixedSchedule);
})();
</script>

<?php include '../../includes/footer.php'; ?>