<?php
// modules/grading/view_student.php
// Grade Management - Administrator (VIEW ONLY) - Student Details
// Reached only via the Grade Level -> Section -> Students drill-down (eye icon).
// No Edit button here on purpose: admin can only VIEW grades from this flow.

require_once __DIR__ . '/../../config/database.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/risk_helpers.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Student Details';
$current_page = 'grading';
$base_url = BASE_URL;

$student_id   = intval($_GET['id'] ?? 0);
$back_grade   = isset($_GET['grade']) ? sanitize($_GET['grade']) : '';
$back_section = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;

if ($student_id <= 0) {
    header('Location: index.php');
    exit;
}

$student = getRecord("
    SELECT s.*, sec.section_name, sec.adviser_id,
           CONCAT(u.last_name, ', ', u.first_name) AS adviser_name
    FROM students s
    LEFT JOIN sections sec ON s.section_id = sec.section_id
    LEFT JOIN users u ON sec.adviser_id = u.user_id
    WHERE s.student_id = ? AND s.is_active = 1
", [$student_id]);

if (!$student) {
    header('Location: index.php');
    exit;
}

// Grades across all subjects/terms (filtered by active school year)
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
$grades = getRecords("
    SELECT g.*, sub.subject_name
    FROM grades g
    JOIN subjects sub ON g.subject_code = sub.subject_code
    WHERE g.student_id = ? AND g.school_year_id = ?
      AND " . gh_encodedTermSql('g') . "
    ORDER BY g.term
", [$student_id, $active_sy['sy_id'] ?? 0]);

// Risk analysis — computed live from actual grade data (see
// includes/risk_helpers.php), never a stale snapshot.
$risk = calculateStudentRisk($student_id);

// Per-subject teachers (used as a fallback if no homeroom adviser is set)
$subject_teachers = getRecords("
    SELECT DISTINCT sub.subject_name, CONCAT(u.last_name, ', ', u.first_name) AS teacher_name
    FROM grades g
    JOIN subjects sub ON g.subject_code = sub.subject_code
    JOIN users u ON g.teacher_id = u.user_id
    WHERE g.student_id = ?
      AND " . gh_validTermSql('g') . "
    ORDER BY sub.subject_name
", [$student_id]);

// Back link: preserve where the admin drilled down from
$back_url = 'admin_view.php';
if (!empty($back_grade) && $back_section > 0) {
    $back_url = 'index.php?grade=' . urlencode($back_grade) . '&section_id=' . $back_section;
} elseif (!empty($back_grade)) {
    $back_url = 'index.php?grade=' . urlencode($back_grade);
} else {
    $back_url = 'index.php';
}

include __DIR__ . '/../../includes/header.php';
?>

<style>
    .view-only-badge {
        background: #EDF4FB;
        color: var(--ink);
        font-weight: 600;
        padding: 5px 12px;
        border-radius: 6px;
        font-size: 13.5px;
    }
    .teacher-box {
        background: var(--surface-hover);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 14px 18px;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .teacher-box .teacher-icon {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background: #14213D;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        flex-shrink: 0;
    }
    .teacher-box .teacher-label {
        font-size: 12.5px;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: var(--text-muted);
        font-weight: 600;
    }
    .teacher-box .teacher-name {
        font-size: 15px;
        font-weight: 600;
        color: var(--ink);
    }
    .student-heading {
        font-size: 24px;
        font-weight: 700;
        color: var(--ink);
        margin-bottom: 4px;
    }
</style>

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h4 class="mb-0" style="color: var(--ink);font-weight:600;">Student Details</h4>
            <div class="d-flex align-items-center gap-2">
                <span class="view-only-badge">View Only</span>
                <a href="<?php echo $back_url; ?>" class="btn btn-sm" style="border: 1px solid var(--border);color: var(--text-muted);background: var(--surface);">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Who handles this student -->
<div class="teacher-box">
    <div class="teacher-icon"><i class="fas fa-chalkboard-teacher"></i></div>
    <div>
        <div class="teacher-label">Adviser / Teacher</div>
        <?php if (!empty($student['adviser_name'])): ?>
            <div class="teacher-name"><?php echo htmlspecialchars($student['adviser_name']); ?></div>
        <?php elseif (!empty($subject_teachers)): ?>
            <div class="teacher-name">
                <?php echo htmlspecialchars(implode(' · ', array_map(function($t) {
                    return $t['subject_name'] . ': ' . $t['teacher_name'];
                }, $subject_teachers))); ?>
            </div>
        <?php else: ?>
            <div class="teacher-name text-muted">No adviser assigned</div>
        <?php endif; ?>
    </div>
</div>

<!-- Student name -->
<div class="student-heading"><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></div>
<div class="mb-4">
    <span class="badge bg-<?php echo $student['enrollment_status'] == 'Enrolled' ? 'success' : 'warning'; ?>">
        <?php echo $student['enrollment_status'] ?? 'Pending'; ?>
    </span>
    <?php if (!empty($student['strand'])): ?>
        <span class="badge bg-info ms-1"><?php echo htmlspecialchars($student['strand'] === 'ASSH' ? 'ASSH Cluster' : $student['strand']); ?></span>
    <?php endif; ?>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm" style="margin-bottom:12px;">
            <div class="card-header" style="background: var(--surface);border-bottom: 1px solid var(--border);padding:14px 18px;">
                <h6 class="mb-0" style="font-weight:600;color: var(--ink);font-size:15px;">Student Information</h6>
            </div>
            <div class="card-body" style="padding:14px 18px;">
                <table class="table table-sm table-borderless text-start mb-0" style="font-size:14.5px;">
                    <tr><td style="font-weight:600;color: var(--text-muted);">LRN:</td><td><?php echo htmlspecialchars($student['lrn']); ?></td></tr>
                    <tr><td style="font-weight:600;color: var(--text-muted);">Grade Level:</td><td><?php echo htmlspecialchars($student['grade_level']); ?></td></tr>
                    <tr><td style="font-weight:600;color: var(--text-muted);">Section:</td><td><?php echo htmlspecialchars($student['section_name'] ?? 'N/A'); ?></td></tr>
                    <tr><td style="font-weight:600;color: var(--text-muted);">Gender:</td><td><?php echo htmlspecialchars($student['gender']); ?></td></tr>
                    <tr><td style="font-weight:600;color: var(--text-muted);">Birth Date:</td><td><?php echo date('M d, Y', strtotime($student['birth_date'])); ?></td></tr>
                    <tr><td style="font-weight:600;color: var(--text-muted);">Contact:</td><td><?php echo htmlspecialchars($student['contact_number'] ?? 'N/A'); ?></td></tr>
                    <tr><td style="font-weight:600;color: var(--text-muted);">Email:</td><td><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></td></tr>
                    <tr><td style="font-weight:600;color: var(--text-muted);">Guardian:</td><td><?php echo htmlspecialchars($student['guardian_name'] ?? 'N/A'); ?></td></tr>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <!-- Risk / Performance Analysis -->
        <?php if ($risk['status'] === 'ok'): ?>
        <div class="card border-0 shadow-sm" style="margin-bottom:12px;">
            <div class="card-header" style="background: var(--surface);border-bottom: 1px solid var(--border);padding:14px 18px;">
                <h5 class="mb-0" style="font-weight:600;color: var(--ink);font-size:15px;">Academic Risk Analysis</h5>
            </div>
            <div class="card-body" style="padding:18px;">
                <div class="row">
                    <div class="col-6">
                        <div style="border: 1px solid var(--border);border-radius:8px;padding:16px;text-align:center;">
                            <h6 style="color: var(--text-muted);font-size:14.5px;margin-bottom:4px;">Average Grade</h6>
                            <h3 style="color: var(--ink);font-weight:700;"><?php echo number_format($risk['average'], 2); ?>%</h3>
                            <small style="color: var(--text-muted);">Average of <?php echo (int)$risk['subject_count']; ?> subject(s)</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div style="border: 1px solid var(--border);border-radius:8px;padding:16px;text-align:center;">
                            <h6 style="color: var(--text-muted);font-size:14.5px;margin-bottom:4px;">Risk Level</h6>
                            <h3>
                                <span style="font-size:18px;font-weight:600;padding:4px 12px;border-radius:6px;cursor:help;background:<?php echo $risk['risk_level'] == 'Low' ? '#EAF7F1' : ($risk['risk_level'] == 'Medium' ? '#FFF7E6' : '#FDEEEE'); ?>;color:<?php echo $risk['risk_level'] == 'Low' ? '#238863' : ($risk['risk_level'] == 'Medium' ? '#B7791F' : '#C94A4A'); ?>;"
                                    data-bs-toggle="tooltip"
                                    data-bs-placement="top"
                                    title="<?php echo htmlspecialchars(getRiskLevelDescription($risk['risk_level'], $risk['pass_mark'])); ?>">
                                    <?php echo htmlspecialchars($risk['risk_level']); ?>
                                </span>
                            </h3>
                        </div>
                    </div>
                </div>
                <div style="background:#EDF4FB;border: 1px solid var(--border);border-radius:8px;padding:12px;margin-top:14px;font-size:14.5px;color: var(--text-muted);">
                    <strong style="color: var(--ink);">Reason:</strong> <?php echo htmlspecialchars($risk['reason']); ?>
                </div>
                <?php if (!empty($risk['failed_subjects'])): ?>
                <div class="mt-2">
                    <?php foreach ($risk['failed_subjects'] as $fs): ?>
                        <span style="background:#FDEEEE;color:#C94A4A;padding:3px 8px;border-radius:4px;font-size:13.5px;font-weight:500;margin-right:6px;margin-bottom:4px;display:inline-block;"><?php echo htmlspecialchars($fs['subject_name']); ?>: <?php echo number_format($fs['grade'], 2); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php elseif ($risk['status'] === 'insufficient_data'): ?>
        <div class="card border-0 shadow-sm" style="margin-bottom:12px;">
            <div class="card-body" style="padding:14px 18px;">
                <span style="background: var(--surface-hover);color: var(--text-muted);padding:3px 8px;border-radius:4px;font-size:13.5px;font-weight:500;">Insufficient Data</span>
                <p class="mb-0 mt-2" style="font-size:14.5px;color: var(--text-muted);"><?php echo htmlspecialchars($risk['reason']); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Grades -->
        <div class="card border-0 shadow-sm">
            <div class="card-header" style="background: var(--surface);border-bottom: 1px solid var(--border);padding:14px 18px;">
                <h5 class="mb-0" style="font-weight:600;color: var(--ink);font-size:15px;">Grades</h5>
            </div>
            <div class="card-body p-0">
                <?php if ($grades && count($grades) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" style="font-size:14.5px;">
                            <thead style="background: var(--surface-hover);">
                                <tr>
                                    <th style="font-size:13.5px;text-transform:uppercase;letter-spacing:0.4px;color: var(--text-muted);border-bottom: 1px solid var(--border);">Subject</th>
                                    <th style="font-size:13.5px;text-transform:uppercase;letter-spacing:0.4px;color: var(--text-muted);border-bottom: 1px solid var(--border);">Term</th>
                                    <th style="font-size:13.5px;text-transform:uppercase;letter-spacing:0.4px;color: var(--text-muted);border-bottom: 1px solid var(--border);">WW</th>
                                    <th style="font-size:13.5px;text-transform:uppercase;letter-spacing:0.4px;color: var(--text-muted);border-bottom: 1px solid var(--border);">PT</th>
                                    <th style="font-size:13.5px;text-transform:uppercase;letter-spacing:0.4px;color: var(--text-muted);border-bottom: 1px solid var(--border);">QA</th>
                                    <th style="font-size:13.5px;text-transform:uppercase;letter-spacing:0.4px;color: var(--text-muted);border-bottom: 1px solid var(--border);">Final</th>
                                    <th style="font-size:13.5px;text-transform:uppercase;letter-spacing:0.4px;color: var(--text-muted);border-bottom: 1px solid var(--border);">Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($grades as $g): ?>
                                    <tr>
                                        <td style="border-bottom: 1px solid var(--border-soft);"><?php echo htmlspecialchars($g['subject_name']); ?></td>
                                        <td style="border-bottom: 1px solid var(--border-soft);"><?php echo htmlspecialchars($g['term']); ?></td>
                                        <td style="border-bottom: 1px solid var(--border-soft);"><?php echo number_format($g['written_work'], 2); ?></td>
                                        <td style="border-bottom: 1px solid var(--border-soft);"><?php echo number_format($g['performance_task'], 2); ?></td>
                                        <td style="border-bottom: 1px solid var(--border-soft);"><?php echo number_format($g['examinations'], 2); ?></td>
                                        <td style="border-bottom: 1px solid var(--border-soft);font-weight:600;"><?php echo number_format($g['final_grade'], 2); ?></td>
                                        <td style="border-bottom: 1px solid var(--border-soft);">
                                            <span style="padding:3px 8px;border-radius:4px;font-size:13.5px;font-weight:500;background:<?php echo $g['final_grade'] >= 75 ? '#EAF7F1' : '#FDEEEE'; ?>;color:<?php echo $g['final_grade'] >= 75 ? '#238863' : '#C94A4A'; ?>;">
                                                <?php echo htmlspecialchars($g['remarks']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4" style="color: var(--text-muted);">
                        No grades recorded.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
