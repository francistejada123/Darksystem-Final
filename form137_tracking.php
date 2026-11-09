<?php
// modules/teachers/form137_tracking.php
// CLASS ADVISER — READ-ONLY Form 137 tracking for the adviser's OWN advisory section.
//
// Shows only: student name, LRN, grade / section, Form 137 status (To Follow / Received / Verified).
// Nothing here writes to the database, there are no forms or action buttons, and no document
// (Form 137 / 138 / birth certificate / any upload) is read, linked or shown. Status values are
// the existing online_applications.form137_status (via oeForm137Status()); the update control
// stays in modules/enrollment/form137_status.php (Administrator / Registrar / Enrollment Officer).

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/enrollment_helpers_online.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

// Teachers only; and only a teacher who is the adviser of at least one active section.
if (!hasRole('Teacher')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$teacher_id = (int) $_SESSION['user_id'];

$advisory = getRecords("
    SELECT section_id, section_name, grade_level, strand
    FROM sections
    WHERE adviser_id = ? AND is_active = 1
    ORDER BY grade_level, section_name
", [$teacher_id]);

if (empty($advisory)) {
    $_SESSION['error_message'] = 'Form 137 Tracking is only available to class advisers.';
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

// The section must be one of THIS adviser's own advisory sections; anything else falls back to the first one.
$section = $advisory[0];
$req_section = isset($_GET['section_id']) ? (int) $_GET['section_id'] : 0;
foreach ($advisory as $a) {
    if ((int) $a['section_id'] === $req_section) { $section = $a; break; }
}
$show_all = (($_GET['show'] ?? '') === 'all');

$active_sy = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
$school_year_id = $active_sy ? (int) $active_sy['sy_id'] : 0;

// Students of the adviser's own section, each with their latest New Student / Transferee application
// (the only applicants who have a Form 137 status). Everyone else has no Form 137 to follow up.
$students = getRecords("
    SELECT s.student_id, s.lrn, s.first_name, s.middle_name, s.last_name,
           oa.application_id, oa.application_type
    FROM students s
    JOIN enrollments e ON e.student_id = s.student_id
    JOIN online_applications oa ON oa.application_id = (
        SELECT MAX(oa2.application_id) FROM online_applications oa2
        WHERE oa2.student_id = s.student_id AND oa2.deleted_at IS NULL
    )
    WHERE e.section_id = ? AND e.school_year_id = ?
      AND e.enrollment_status = 'Approved' AND s.is_active = 1
      AND oa.application_type IN ('New Student', 'Transferee')
    ORDER BY s.last_name, s.first_name
", [(int) $section['section_id'], $school_year_id]);

$rows = [];
$counts = ['To Follow' => 0, 'Received' => 0, 'Verified' => 0];
foreach ($students as $st) {
    $status = oeForm137Status($st['application_id'], $st['application_type']);
    if (!isset($counts[$status])) continue;
    $counts[$status]++;
    if (!$show_all && $status === 'Verified') continue;   // default view = still pending
    $st['form137_status'] = $status;
    $rows[] = $st;
}

$badge = ['To Follow' => 'bg-warning text-dark', 'Received' => 'bg-info text-dark', 'Verified' => 'bg-success'];

$page_title = 'Form 137 Tracking';
$current_page = 'form137_tracking';
$base_url = '../../';
include '../../includes/header.php';
?>

<div class="row mt-4">
    <div class="col-12">
        <h2><i class="fas fa-folder-open text-primary me-2"></i>Form 137 Tracking</h2>
        <p class="text-muted mb-3">
            View-only list of students in your advisory section whose Form 137 is still pending.
            The Admin / Enrollment Officer updates the status.
        </p>

        <?php if (count($advisory) > 1): ?>
        <form method="get" class="mb-3 d-flex gap-2 align-items-center">
            <label for="section_id" class="mb-0 fw-semibold">Advisory section</label>
            <select name="section_id" id="section_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                <?php foreach ($advisory as $a): ?>
                    <option value="<?php echo (int) $a['section_id']; ?>" <?php echo (int) $a['section_id'] === (int) $section['section_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($a['grade_level'] . ' - ' . $a['section_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($show_all): ?><input type="hidden" name="show" value="all"><?php endif; ?>
        </form>
        <?php endif; ?>

        <div class="mb-3">
            <span class="badge bg-warning text-dark">To Follow: <?php echo $counts['To Follow']; ?></span>
            <span class="badge bg-info text-dark">Received: <?php echo $counts['Received']; ?></span>
            <span class="badge bg-success">Verified: <?php echo $counts['Verified']; ?></span>
            <a class="ms-2 small" href="?section_id=<?php echo (int) $section['section_id']; ?><?php echo $show_all ? '' : '&show=all'; ?>">
                <?php echo $show_all ? 'Show pending only' : 'Show all (including Verified)'; ?>
            </a>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>LRN</th>
                                <th>Grade / Section</th>
                                <th>Form 137 Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">
                                <?php echo $show_all ? 'No students with a Form 137 record in this section.' : 'No pending Form 137 in this section.'; ?>
                            </td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['last_name'] . ', ' . $r['first_name'] . (!empty($r['middle_name']) ? ' ' . mb_substr($r['middle_name'], 0, 1) . '.' : '')); ?></td>
                                <td><?php echo htmlspecialchars($r['lrn']); ?></td>
                                <td><?php echo htmlspecialchars($section['grade_level'] . ' - ' . $section['section_name']); ?></td>
                                <td><span class="badge <?php echo $badge[$r['form137_status']]; ?>"><?php echo htmlspecialchars($r['form137_status']); ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
