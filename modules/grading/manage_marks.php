<?php
// modules/grading/manage_marks.php
// Manage Marks — direct Term 1/2/3 grade encoding per level.
//
// Additive page only: it reuses the existing `grades` table (one row per
// student + subject + section + school year + term) and the existing
// computeSubjectFinalGrade() rule (Final = rounded mean, only when complete).
// It never creates a new grades concept, never deletes assessment items,
// scores, or existing grade rows. A blank input is always skipped (never
// stored, never clears an existing grade), so saving is non-destructive.
//
// Manual-wins rule: a typed value overwrites the term's transmuted/final
// grade and is flagged is_manual = 1 (plus manual_by/manual_at). The
// WW/PT/Exam breakdown columns are left untouched.

require_once __DIR__ . '/../../config/database.php';
if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';

if (!hasAnyRole(['Administrator', 'Teacher'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}
$is_admin = hasRole('Administrator');
$my_id = (int) ($_SESSION['user_id'] ?? 0);

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
if (!$active_sy) {
    $page_title = 'Manage Marks';
    $current_page = 'grading';
    $base_url = BASE_URL;
    include __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-warning mt-4">No active school year found.</div>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}
$sy_id = (int) $active_sy['sy_id'];

// ---- Additive schema: manual-encoding flags on grades (safe, re-runnable)
$has_manual_col = getRecord(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grades' AND COLUMN_NAME = 'is_manual'"
);
if (!$has_manual_col) {
    executeQuery("ALTER TABLE grades ADD COLUMN is_manual TINYINT(1) NOT NULL DEFAULT 0");
}
if (!getRecord("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grades' AND COLUMN_NAME = 'manual_by'")) {
    executeQuery("ALTER TABLE grades ADD COLUMN manual_by INT NULL DEFAULT NULL");
}
if (!getRecord("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grades' AND COLUMN_NAME = 'manual_at'")) {
    executeQuery("ALTER TABLE grades ADD COLUMN manual_at TIMESTAMP NULL DEFAULT NULL");
}

$grade_levels = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
$terms = gh_threeTerms(); // Term 1, Term 2, Term 3

$selected_grade = isset($_GET['grade']) ? sanitize($_GET['grade']) : '';
if (!in_array($selected_grade, $grade_levels, true)) {
    $selected_grade = '';
}
$selected_subject = isset($_GET['subject_code']) ? sanitize($_GET['subject_code']) : '';

// Subjects for the picked level (teachers: only what they handle in that grade).
$subjects = [];
if ($selected_grade !== '') {
    if ($is_admin) {
        $subjects = getRecords(
            "SELECT subject_code, subject_name, strand FROM subjects WHERE is_active = 1 AND grade_level = ? ORDER BY subject_name",
            [$selected_grade]
        );
    } else {
        $subjects = getRecords(
            "SELECT DISTINCT sub.subject_code, sub.subject_name, sub.strand
             FROM teacher_subjects ts
             JOIN sections s ON s.section_id = ts.section_id
             JOIN subjects sub ON sub.subject_code = ts.subject_code
             WHERE ts.teacher_id = ? AND ts.school_year_id = ? AND s.grade_level = ? AND sub.is_active = 1
             ORDER BY sub.subject_name",
            [$my_id, $sy_id, $selected_grade]
        );
    }
    $codes = array_column($subjects, 'subject_code');
    if ($selected_subject !== '' && !in_array($selected_subject, $codes, true)) {
        $selected_subject = '';
    }
    if ($selected_subject === '' && count($codes) === 1) {
        $selected_subject = $codes[0];
    }
}

// Students: every Approved enrollment in the active SY whose section is in
// this grade level (all sections pooled = per-level bagsakan).
$students = []; // each: student_id, lrn, last_name, first_name, section_id, section_name
$section_of = [];
if ($selected_grade !== '') {
    $students = getRecords(
        "SELECT s.student_id, s.lrn, s.last_name, s.first_name, sec.section_id, sec.section_name
         FROM students s
         INNER JOIN enrollments e ON s.student_id = e.student_id
         INNER JOIN sections sec ON sec.section_id = e.section_id
         WHERE e.school_year_id = ? AND e.enrollment_status = 'Approved'
         AND sec.grade_level = ? AND s.is_active = 1
         GROUP BY s.student_id
         ORDER BY s.last_name, s.first_name",
        [$sy_id, $selected_grade]
    );
    foreach ($students as $st) {
        $section_of[(int) $st['student_id']] = (int) $st['section_id'];
    }
}

// Existing term grades for the picked subject: [student_id][term] => row.
$marks = [];
if ($selected_grade !== '' && $selected_subject !== '' && !empty($students)) {
    $ids = array_column($students, 'student_id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = getRecords(
        "SELECT student_id, term, transmuted_grade, final_grade, remarks, is_manual
         FROM grades WHERE student_id IN ($ph) AND subject_code = ? AND school_year_id = ?",
        array_merge($ids, [$selected_subject, $sy_id])
    );
    foreach ($rows as $r) {
        $marks[(int) $r['student_id']][$r['term']] = $r;
    }
}

// ---- Save handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_marks') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid request. Please try again.';
        header('Location: manage_marks.php?grade=' . urlencode($selected_grade) . '&subject_code=' . urlencode($selected_subject));
        exit;
    }
    $p_grade = sanitize($_POST['grade'] ?? '');
    $p_subject = sanitize($_POST['subject_code'] ?? '');
    if (!in_array($p_grade, $grade_levels, true) || $p_subject === '') {
        $_SESSION['error_message'] = 'Pick a grade level and subject first.';
        header('Location: manage_marks.php');
        exit;
    }
    // Teachers may only save subjects they handle in that grade.
    if (!$is_admin) {
        $allowed = getRecord(
            "SELECT ts.ts_id FROM teacher_subjects ts JOIN sections s ON s.section_id = ts.section_id
             WHERE ts.teacher_id = ? AND ts.school_year_id = ? AND s.grade_level = ? AND ts.subject_code = ? LIMIT 1",
            [$my_id, $sy_id, $p_grade, $p_subject]
        );
        if (!$allowed) {
            $_SESSION['error_message'] = 'You do not handle this subject in ' . $p_grade . '.';
            header('Location: manage_marks.php?grade=' . urlencode($p_grade));
            exit;
        }
    }
    // Rebuild the student->section map server-side (never trust hidden inputs).
    $enrolled = getRecords(
        "SELECT s.student_id, sec.section_id FROM students s
         INNER JOIN enrollments e ON s.student_id = e.student_id
         INNER JOIN sections sec ON sec.section_id = e.section_id
         WHERE e.school_year_id = ? AND e.enrollment_status = 'Approved' AND sec.grade_level = ? AND s.is_active = 1
         GROUP BY s.student_id",
        [$sy_id, $p_grade]
    );
    $secmap = [];
    foreach ($enrolled as $er) {
        $secmap[(int) $er['student_id']] = (int) $er['section_id'];
    }
    // Teacher owner per section (for the teacher_id column); fall back to me.
    $owners = [];
    foreach (getRecords(
        "SELECT section_id, MIN(teacher_id) AS teacher_id FROM teacher_subjects
         WHERE subject_code = ? AND school_year_id = ? GROUP BY section_id",
        [$p_subject, $sy_id]
    ) as $ow) {
        $owners[(int) $ow['section_id']] = (int) $ow['teacher_id'];
    }

    $posted = $_POST['grades'] ?? [];
    $saved = 0;
    $rejected = [];
    foreach ($posted as $sid_raw => $tmap) {
        $sid = (int) $sid_raw;
        if (!isset($secmap[$sid]) || !is_array($tmap)) {
            continue;
        }
        $section_id = $secmap[$sid];
        $owner_id = $owners[$section_id] ?? $my_id;
        foreach ($terms as $t) {
            if (!array_key_exists($t, $tmap)) {
                continue;
            }
            $raw = trim((string) $tmap[$t]);
            if ($raw === '') {
                continue; // blank = skip, never clears existing data
            }
            if (!is_numeric($raw)) {
                $rejected[] = "Student #$sid $t: not a number";
                continue;
            }
            $g = (float) $raw;
            if ($g < 0 || $g > 100) {
                $rejected[] = "Student #$sid $t: must be 0-100";
                continue;
            }
            $g = (int) round($g);
            $remarks = $g >= 75 ? 'Passed' : 'Failed';
            executeQuery(
                "INSERT INTO grades (student_id, subject_code, teacher_id, section_id, school_year_id, term,
                    initial_grade, transmuted_grade, final_grade, remarks, encoded_by, is_manual, manual_by, manual_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,NOW())
                 ON DUPLICATE KEY UPDATE initial_grade = VALUES(initial_grade),
                    transmuted_grade = VALUES(transmuted_grade), final_grade = VALUES(final_grade),
                    remarks = VALUES(remarks), encoded_by = VALUES(encoded_by),
                    is_manual = 1, manual_by = VALUES(manual_by), manual_at = NOW()",
                [$sid, $p_subject, $owner_id, $section_id, $sy_id, $t, $g, $g, $g, $remarks, $my_id, $my_id]
            );
            $saved++;
        }
    }
    logActivity($my_id, 'grading', 'Saved manual term marks', $p_grade . ' / ' . $p_subject . ': ' . $saved . ' grade(s)');
    if (!empty($rejected)) {
        $_SESSION['error_message'] = 'Saved ' . $saved . ' grade(s). Rejected: ' . implode('; ', array_slice(array_unique($rejected), 0, 5));
    } else {
        $_SESSION['success_message'] = 'Saved ' . $saved . ' manual grade(s) for ' . $p_grade . ' / ' . $p_subject . '.';
    }
    header('Location: manage_marks.php?grade=' . urlencode($p_grade) . '&subject_code=' . urlencode($p_subject));
    exit;
}

$page_title = 'Manage Marks';
$page_subtitle = 'Type Term 1-3 grades per level — Final auto-computes.';
$current_page = 'grading';
$base_url = BASE_URL;
$csrf_token = generateCSRFToken();
include __DIR__ . '/../../includes/header.php';
?>
<div style="padding:24px;max-width:1200px;margin:0 auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <div>
            <h4 style="margin:0;font-size:26px;font-weight:700;">Manage Marks</h4>
            <p style="margin:4px 0 0;color:#667085;"><?php echo htmlspecialchars($active_sy['sy_name']); ?> &middot; Type Term 1-3 grades, Final = average (rounded). Blank = skip.</p>
        </div>
        <a href="index.php" style="padding:9px 16px;border:1px solid #ddd;border-radius:8px;text-decoration:none;color:#333;background:#fff;">&larr; Grade Management</a>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div style="padding:12px 16px;background:#EAF7F1;color:#1E7B45;border-radius:8px;margin-bottom:12px;"><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div style="padding:12px 16px;background:#FDEEEE;color:#C94A4A;border-radius:8px;margin-bottom:12px;"><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></div>
    <?php endif; ?>

    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;background:#fff;border:1px solid #eee;border-radius:10px;padding:14px 16px;">
        <div>
            <label style="font-size:13px;color:#667085;">Grade Level</label><br>
            <select name="grade" onchange="this.form.submit()" style="padding:9px 12px;border:1px solid #ddd;border-radius:8px;min-width:160px;">
                <option value="">-- Select --</option>
                <?php foreach ($grade_levels as $gl): ?>
                    <option value="<?php echo $gl; ?>" <?php echo $selected_grade === $gl ? 'selected' : ''; ?>><?php echo $gl; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:13px;color:#667085;">Subject (<?php echo count($subjects); ?>)</label><br>
            <select name="subject_code" onchange="this.form.submit()" style="padding:9px 12px;border:1px solid #ddd;border-radius:8px;min-width:260px;" <?php echo $selected_grade === '' ? 'disabled' : ''; ?>>
                <option value="">-- Select subject --</option>
                <?php foreach ($subjects as $sub): ?>
                    <option value="<?php echo htmlspecialchars($sub['subject_code']); ?>" <?php echo $selected_subject === $sub['subject_code'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($sub['subject_name'] . ' (' . $sub['subject_code'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($selected_grade !== '' && $selected_subject !== ''): ?>
            <div style="align-self:end;color:#667085;font-size:14px;"><?php echo count($students); ?> student(s)</div>
        <?php endif; ?>
    </form>

    <?php if ($selected_grade !== '' && $selected_subject !== '' && !empty($students)): ?>
    <form method="post" id="marksForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="save_marks">
        <input type="hidden" name="grade" value="<?php echo htmlspecialchars($selected_grade); ?>">
        <input type="hidden" name="subject_code" value="<?php echo htmlspecialchars($selected_subject); ?>">
        <div style="overflow-x:auto;background:#fff;border:1px solid #eee;border-radius:10px;">
            <table style="width:100%;border-collapse:collapse;font-size:14.5px;min-width:760px;">
                <thead>
                    <tr style="background:#f8f9fb;">
                        <th style="text-align:left;padding:12px 14px;border-bottom:1px solid #eee;">Student</th>
                        <th style="padding:12px;border-bottom:1px solid #eee;">Section</th>
                        <?php foreach ($terms as $t): ?>
                            <th style="padding:12px;border-bottom:1px solid #eee;"><?php echo htmlspecialchars($t); ?><br><span style="font-weight:400;font-size:12px;color:#888;">MAX 100</span></th>
                        <?php endforeach; ?>
                        <th style="padding:12px;border-bottom:1px solid #eee;">Final Grade<br><span style="font-weight:400;font-size:12px;color:#888;">auto</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $st):
                        $sid = (int) $st['student_id'];
                        $tg = [];
                        foreach ($terms as $t) {
                            $v = $marks[$sid][$t]['transmuted_grade'] ?? null;
                            if ($v !== null && (float) $v > 0) {
                                $tg[$t] = (float) $v;
                            }
                        }
                        $fg = computeSubjectFinalGrade($tg, $terms, $active_sy);
                        $final_disp = $fg['value'] ?? null;
                        $final_label = $fg['label'] ?? '';
                    ?>
                    <tr data-student="<?php echo $sid; ?>">
                        <td style="padding:10px 14px;border-bottom:1px solid #f1f1f1;font-weight:600;">
                            <?php echo htmlspecialchars($st['last_name'] . ', ' . $st['first_name']); ?>
                            <span style="font-weight:400;color:#888;font-size:12.5px;"><?php echo htmlspecialchars($st['lrn'] ?? ''); ?></span>
                        </td>
                        <td style="padding:10px;border-bottom:1px solid #f1f1f1;text-align:center;color:#555;"><?php echo htmlspecialchars($st['section_name']); ?></td>
                        <?php foreach ($terms as $t):
                            $v = $marks[$sid][$t]['transmuted_grade'] ?? '';
                            $v = ($v === null || (float) $v == 0) ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
                            $is_man = !empty($marks[$sid][$t]['is_manual']);
                        ?>
                        <td style="padding:8px;border-bottom:1px solid #f1f1f1;text-align:center;">
                            <input type="number" min="0" max="100" step="1" name="grades[<?php echo $sid; ?>][<?php echo htmlspecialchars($t); ?>]"
                                value="<?php echo htmlspecialchars($v); ?>" data-term="<?php echo htmlspecialchars($t); ?>"
                                title="<?php echo $is_man ? 'Manual entry' : 'Computed / existing'; ?>"
                                style="width:90px;padding:8px 10px;border:1px solid <?php echo $is_man ? '#F59E0B' : '#ddd'; ?>;border-radius:8px;text-align:center;">
                        </td>
                        <?php endforeach; ?>
                        <td class="final-cell" style="padding:10px;border-bottom:1px solid #f1f1f1;text-align:center;font-weight:700;">
                            <?php echo $final_disp !== null ? htmlspecialchars($final_disp) . '<br><span style="font-weight:400;font-size:12px;color:#888;">' . htmlspecialchars($final_label) . '</span>' : '<span style="color:#aaa;">-</span>'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px;">
            <a href="manage_marks.php?grade=<?php echo urlencode($selected_grade); ?>&subject_code=<?php echo urlencode($selected_subject); ?>" style="padding:10px 18px;border:1px solid #ddd;border-radius:8px;text-decoration:none;color:#333;background:#fff;">Cancel</a>
            <button type="submit" style="padding:10px 22px;border:none;border-radius:8px;background:#0F766E;color:#fff;font-weight:600;cursor:pointer;">Save All Marks</button>
        </div>
    </form>
    <script>
    // Live Final preview: average of the 3 term inputs, rounded. Blank = incomplete.
    document.querySelectorAll('#marksForm tr[data-student]').forEach(function (row) {
        var inputs = row.querySelectorAll('input[data-term]');
        var cell = row.querySelector('.final-cell');
        function recalc() {
            var vals = [];
            inputs.forEach(function (i) {
                var s = (i.value || '').trim();
                if (s === '') return;
                var n = parseFloat(s);
                if (!isNaN(n) && n >= 0 && n <= 100) vals.push(n);
            });
            if (vals.length === 3) {
                var avg = Math.round((vals[0] + vals[1] + vals[2]) / 3);
                cell.innerHTML = avg + '<br><span style="font-weight:400;font-size:12px;color:#888;">Final Grade</span>';
            } else if (vals.length > 0) {
                var p = Math.round(vals.reduce(function (a, b) { return a + b; }, 0) / vals.length);
                cell.innerHTML = '<span style="color:#888;">' + p + '</span><br><span style="font-weight:400;font-size:12px;color:#aaa;">Current Average</span>';
            } else {
                cell.innerHTML = '<span style="color:#aaa;">-</span>';
            }
        }
        inputs.forEach(function (i) { i.addEventListener('input', recalc); });
    });
    </script>
    <?php elseif ($selected_grade !== '' && $selected_subject !== '' && empty($students)): ?>
        <div style="background:#fff;border:1px solid #eee;border-radius:10px;padding:24px;text-align:center;color:#888;">No approved-enrolled students in <?php echo htmlspecialchars($selected_grade); ?> for <?php echo htmlspecialchars($active_sy['sy_name']); ?>.</div>
    <?php elseif ($selected_grade !== ''): ?>
        <div style="background:#fff;border:1px solid #eee;border-radius:10px;padding:24px;text-align:center;color:#888;">Pick a subject to load its students.</div>
    <?php else: ?>
        <div style="background:#fff;border:1px solid #eee;border-radius:10px;padding:24px;text-align:center;color:#888;">Select a grade level above to start.</div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
