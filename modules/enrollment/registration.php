<?php
// modules/enrollment/registration.php?id=ENROLLMENT_ID[&print=1][&embed=1]
// embed=1: only the registration sheet (no admin layout, no buttons) -- used
// inside the "View Registration" modal of Student Details > Enrollment History,
// whose Print button prints just this sheet.
// Enrollment Registration of one enrollment (Admin / Registrar / Enrollment
// Officer): student + enrollment information and the Registered Subjects.
// VIEW + PRINT only -- nothing is saved or changed here.
//
// The subjects come from spdCurriculumClasses() (includes/student_portal_data.php),
// the same function the Student Portal Dashboard, My Classes and Enrollment page
// use, so all of them list the same subjects. Teacher information is not shown.

require_once __DIR__ . '/../../config/database.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/eo_scope.php';            // Enrollment Officer: own grade level only
require_once __DIR__ . '/../../includes/student_portal_data.php'; // spdCurriculumClasses()
require_once __DIR__ . '/../../includes/fixed_timetable.php';     // class Time display (JHS)

if (!hasAnyRole(['Administrator', 'Registrar', 'Enrollment Officer'])) {
    header('Location: ../../index.php');
    exit;
}

$enrollment_id = intval($_GET['id'] ?? 0);
$enrollment = $enrollment_id > 0 ? getRecord("
    SELECT e.*, s.lrn, s.first_name, s.last_name, s.middle_name,
           sec.section_name, sec.strand AS section_strand, sy.sy_name
    FROM enrollments e
    JOIN students s ON e.student_id = s.student_id
    LEFT JOIN sections sec ON e.section_id = sec.section_id
    LEFT JOIN school_years sy ON e.school_year_id = sy.sy_id
    WHERE e.enrollment_id = ?", [$enrollment_id]) : null;
if (!$enrollment) {
    header('Location: index.php');
    exit;
}
eoGuard($enrollment['grade_level']);

$is_shs = in_array($enrollment['grade_level'], ['Grade 11', 'Grade 12'], true);
$strand = trim((string) ($enrollment['strand'] ?: $enrollment['section_strand']));
$strand_label = $strand === '' ? '' : (in_array(strtoupper($strand), ['ASSH', 'HUMSS'], true) ? 'ASSH Cluster' : $strand);
$middle = trim((string) ($enrollment['middle_name'] ?? ''));
$student_name = trim($enrollment['last_name'] . ', ' . $enrollment['first_name']
    . ($middle !== '' ? ' ' . mb_substr($middle, 0, 1) . '.' : ''));

$subjects = spdCurriculumClasses((int) $enrollment['student_id'], (int) $enrollment['school_year_id']);

// Class Time per subject for this section+SY: saved schedule rows first
// (any teacher), else the fixed JHS timetable (same rule as Teacher
// Management), else a dash. Read-only -- nothing is written here.
$reg_sched_map = [];
$reg_ft_codes = [];
if (!empty($enrollment['section_id']) && !empty($subjects)) {
    foreach (getRecords(
        "SELECT subject_code, day_of_week, TIME_FORMAT(start_time, '%H:%i') AS start_time, TIME_FORMAT(end_time, '%H:%i') AS end_time
           FROM teacher_class_schedule
          WHERE section_id = ? AND school_year_id = ? AND is_active = 1
          ORDER BY day_of_week, start_time",
        [(int) $enrollment['section_id'], (int) $enrollment['school_year_id']]
    ) ?: [] as $sr) {
        $reg_sched_map[$sr['subject_code']][] = $sr;
    }
    $reg_by_name = $subjects;
    usort($reg_by_name, fn($a, $b) => strcmp($a['subject_name'], $b['subject_name']));
    $reg_ft_codes = array_column($reg_by_name, 'subject_code');
}
$reg_day_names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
function regTimeSummary($rows, $day_names) {
    $groups = [];
    foreach ((array) $rows as $r) {
        $groups[$r['start_time'] . '|' . $r['end_time']][] = $day_names[(int) $r['day_of_week']] ?? ('Day ' . (int) $r['day_of_week']);
    }
    $parts = [];
    foreach ($groups as $k => $dns) {
        [$s, $e] = explode('|', $k);
        $parts[] = implode('-', $dns) . ' · ' . date('g:i A', strtotime($s)) . ' – ' . date('g:i A', strtotime($e));
    }
    return implode('; ', $parts);
}
$terms = array_values(array_unique(array_filter(array_map(function ($r) { return trim((string) ($r['term'] ?? '')); }, $subjects))));
$auto_print = !empty($_GET['print']);
$school_name = function_exists('getSchoolName') ? getSchoolName() : 'Imelda Integrated Secondary School';

$page_title = 'Enrollment Registration';
$current_page = 'enrollment';
$base_url = '../../';
$is_embedded = (($_GET['embed'] ?? '') === '1');
if ($is_embedded) {
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrollment Registration - <?php echo htmlspecialchars($student_name); ?></title>
    <style>
        html, body { margin: 0; background: #fff; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; }
        body.reg-embed { padding: 18px; }
        body.reg-embed .reg-sheet { border: none; padding: 8px 6px; }
    </style>
<?php
} else {
    include '../../includes/header.php';
}
?>
<style>
.reg-page .reg-actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; margin-bottom: 16px; }
.reg-page .reg-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 6px; font-size: 14.5px; font-weight: 500; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); text-decoration: none; cursor: pointer; }
.reg-page .reg-btn:hover { background: var(--surface-hover); color: var(--ink); }
.reg-page .reg-btn.primary { background: #14213D; color: #fff; border-color: #14213D; }
.reg-page .reg-btn.primary:hover { filter: brightness(1.1); color: #fff; }
.reg-sheet { max-width: 820px; margin: 0 auto; background: #fff; color: #111827; border: 1px solid #E5E7EB; border-radius: 10px; padding: 36px 44px; }
.reg-sheet .reg-head { text-align: center; border-bottom: 2px solid #14213D; padding-bottom: 14px; margin-bottom: 22px; }
.reg-sheet .reg-head img { height: 64px; width: 64px; object-fit: contain; margin-bottom: 6px; }
.reg-sheet .reg-school { font-size: 20px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; margin: 0; }
.reg-sheet .reg-title { font-size: 16px; font-weight: 700; letter-spacing: .12em; margin: 6px 0 2px; }
.reg-sheet .reg-sy { font-size: 14.5px; color: #374151; margin: 0; }
.reg-sheet h6 { font-size: 13.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #14213D; margin: 0 0 10px; }
.reg-sheet .reg-info { display: grid; grid-template-columns: 1fr 1fr; gap: 0 32px; margin-bottom: 24px; }
.reg-sheet .reg-row { display: flex; padding: 7px 0; border-bottom: 1px solid #F3F4F6; font-size: 14.5px; }
.reg-sheet .reg-row span:first-child { width: 150px; flex-shrink: 0; color: #6B7280; font-weight: 500; }
.reg-sheet .reg-row span:last-child { font-weight: 600; }
.reg-sheet table { width: 100%; border-collapse: collapse; font-size: 14.5px; }
.reg-sheet th { text-align: left; padding: 8px 10px; font-size: 12.5px; text-transform: uppercase; letter-spacing: .05em; color: #374151; background: #F9FAFB; border: 1px solid #E5E7EB; }
.reg-sheet td { padding: 8px 10px; border: 1px solid #E5E7EB; }
.reg-sheet tr { page-break-inside: avoid; break-inside: avoid; }
.reg-sheet .reg-note { font-size: 12.5px; color: #6B7280; margin-top: 10px; }
.reg-sheet .reg-empty { padding: 18px; text-align: center; color: #6B7280; border: 1px dashed #D1D5DB; border-radius: 8px; }
@media (max-width: 700px) {
    .reg-sheet { padding: 22px 18px; }
    .reg-sheet .reg-info { grid-template-columns: 1fr; }
}
@media print {
    @page { size: A4 portrait; margin: 14mm; }
    body * { visibility: hidden !important; }
    .reg-sheet, .reg-sheet * { visibility: visible !important; }
    .reg-sheet { position: absolute; left: 0; top: 0; width: 100%; max-width: none; border: none; border-radius: 0; padding: 0; margin: 0; }
    .reg-actions { display: none !important; }
    .reg-sheet .reg-info { grid-template-columns: 1fr 1fr; }
    .reg-sheet th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

<?php if ($is_embedded): ?>
</head>
<body class="reg-embed">
<?php endif; ?>
<div class="reg-page" style="<?php echo $is_embedded ? '' : 'padding: 24px;'; ?>">
    <?php if (!$is_embedded): ?>
    <div class="reg-actions">
        <a href="view.php?id=<?php echo (int) $enrollment['enrollment_id']; ?>" class="reg-btn"><i class="fas fa-arrow-left"></i> Back to Enrollment Details</a>
        <button type="button" class="reg-btn primary" onclick="window.print()"><i class="fas fa-print"></i> Print Enrollment Registration</button>
    </div>
    <?php endif; ?>

    <div class="reg-sheet">
        <div class="reg-head">
            <?php if (file_exists(__DIR__ . '/../../static/images/logo.png')): ?>
                <img src="<?php echo BASE_URL; ?>static/images/logo.png" alt="">
            <?php endif; ?>
            <p class="reg-school"><?php echo htmlspecialchars($school_name); ?></p>
            <p class="reg-title">ENROLLMENT REGISTRATION</p>
            <p class="reg-sy">School Year <?php echo htmlspecialchars($enrollment['sy_name'] ?? ''); ?></p>
        </div>

        <h6>Student / Enrollment Information</h6>
        <div class="reg-info">
            <div>
                <div class="reg-row"><span>Student Name</span><span><?php echo htmlspecialchars($student_name); ?></span></div>
                <div class="reg-row"><span>LRN</span><span><?php echo htmlspecialchars($enrollment['lrn']); ?></span></div>
                <div class="reg-row"><span>Grade Level</span><span><?php echo htmlspecialchars($enrollment['grade_level']); ?></span></div>
                <?php if ($is_shs && $strand_label !== ''): ?>
                    <div class="reg-row"><span>Strand</span><span><?php echo htmlspecialchars($strand_label); ?></span></div>
                <?php endif; ?>
            </div>
            <div>
                <div class="reg-row"><span>Section</span><span><?php echo htmlspecialchars($enrollment['section_name'] ?? 'Not Assigned'); ?></span></div>
                <div class="reg-row"><span>Enrollment Date</span><span><?php echo !empty($enrollment['enrollment_date']) ? date('F d, Y', strtotime($enrollment['enrollment_date'])) : '—'; ?></span></div>
                <div class="reg-row"><span>Enrollment Status</span><span><?php echo htmlspecialchars($enrollment['enrollment_status']); ?></span></div>
            </div>
        </div>

        <h6>Registered Subjects<?php echo $terms ? ' &mdash; ' . htmlspecialchars(implode(', ', $terms)) : ''; ?></h6>
        <?php if ($subjects): ?>
            <table>
                <thead>
                    <tr><th style="width:44px;">#</th><th style="width:200px;">Subject Code</th><th>Subject Name</th><th>Time</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($subjects as $i => $sub): ?>
                        <?php
                            $reg_rows = $reg_sched_map[$sub['subject_code']] ?? [];
                            if ($reg_rows) {
                                $reg_time = regTimeSummary($reg_rows, $reg_day_names);
                            } else {
                                $reg_slot = ftSlotFor($reg_ft_codes, $sub['subject_code'], $enrollment['grade_level'] ?? '');
                                $reg_time = $reg_slot ? $reg_slot['summary'] : '';
                            }
                        ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo htmlspecialchars($sub['subject_code']); ?></td>
                            <td><?php echo htmlspecialchars($sub['subject_name']); ?></td>
                            <td><?php echo $reg_time !== '' ? htmlspecialchars($reg_time) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="reg-note">Total: <?php echo count($subjects); ?> subject<?php echo count($subjects) === 1 ? '' : 's'; ?>. Printed <?php echo date('F d, Y'); ?>.</p>
        <?php else: ?>
            <div class="reg-empty">No registered subjects yet<?php echo empty($enrollment['section_id']) ? ' &mdash; the student has no section assigned.' : '.'; ?></div>
        <?php endif; ?>
    </div>
</div>
<?php if ($auto_print): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
<?php endif; ?>
<?php if ($is_embedded): ?>
</body>
</html>
<?php else: ?>
<?php include '../../includes/footer.php'; ?>
<?php endif; ?>