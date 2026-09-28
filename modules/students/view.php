<?php
// modules/students/view.php
// Admin/Teacher view student details WITH PROFILE PICTURE

require_once __DIR__ . '/../../config/database.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/attendance_qr_helpers.php'; // attQrLrnLink()
require_once __DIR__ . '/../../includes/risk_helpers.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';

if (!hasAnyRole(['Administrator', 'Registrar', 'Teacher'])) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'Student Details';
$current_page = 'students';
$base_url = '../../';
$is_embedded = (($_GET['embed'] ?? '') === '1');

$student_id = intval($_GET['id'] ?? 0);
if ($student_id <= 0) {
    header('Location: index.php');
    exit;
}

// Get student data with profile image
$student = getRecord("
    SELECT s.*, sec.section_name, sy.sy_name 
    FROM students s 
    LEFT JOIN sections sec ON s.section_id = sec.section_id 
    LEFT JOIN school_years sy ON s.school_year_id = sy.sy_id 
    WHERE s.student_id = ? AND s.is_active = 1
", [$student_id]);

if (!$student) {
    header('Location: index.php');
    exit;
}

// Check profile image
$profile_image = '';
if (!empty($student['profile_image']) && file_exists('../../static/uploads/profiles/' . $student['profile_image'])) {
    $profile_image = '../../static/uploads/profiles/' . $student['profile_image'];
}

// Get grades (filtered by active school year)
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
$grades = getRecords("
    SELECT g.*, sub.subject_name 
    FROM grades g 
    JOIN subjects sub ON g.subject_code = sub.subject_code 
    WHERE g.student_id = ? AND g.school_year_id = ?
      AND " . gh_encodedTermSql('g') . "
    ORDER BY g.term
", [$student_id, $active_sy['sy_id'] ?? 0]);

// End-of-school-year status (Completed / Promoted) + Enrollment History.
// student_year_status is an overlay: enrollments and grades stay untouched.
// Admin/Registrar only -- teachers keep the original page.
$can_see_history = hasAnyRole(['Administrator', 'Registrar']);
$year_promoted_label = gh_yearStatusPromotedLabel();
gh_ensureStudentYearStatusTable();
if (!empty($active_sy['sy_id'])) { gh_sweepYearStatuses((int)$active_sy['sy_id']); }
$enrollment_history = [];
$active_year_status = null;
if ($can_see_history) {
    $enrollment_history = getRecords("
        SELECT e.enrollment_id, e.grade_level, e.enrollment_status, e.enrollment_date,
               e.school_year_id, sy.sy_name, sec.section_name,
               ys.status AS year_status, ys.general_average AS year_average
        FROM enrollments e
        LEFT JOIN school_years sy ON sy.sy_id = e.school_year_id
        LEFT JOIN sections sec ON sec.section_id = e.section_id
        LEFT JOIN student_year_status ys ON ys.student_id = e.student_id
             AND ys.school_year_id = e.school_year_id AND ys.grade_level = e.grade_level
             AND ys.status = " . $db->quote($year_promoted_label) . "
        WHERE e.student_id = ?
        ORDER BY sy.sy_id DESC, e.enrollment_id DESC
    ", [$student_id]);
    foreach ($enrollment_history as $eh) {
        if ((int)$eh['school_year_id'] === (int)($active_sy['sy_id'] ?? 0)
            && $eh['enrollment_status'] === 'Approved' && !empty($eh['year_status'])) {
            $active_year_status = $eh; break;
        }
    }
}

// Risk analysis — computed live from actual grade data (see
// includes/risk_helpers.php), not just read back from a possibly stale
// ai_analysis snapshot. This keeps risk_level/reason correct even if a
// grade was changed after the last time the AI Analytics dashboard ran.
$risk = calculateStudentRisk($student_id);

if (!$is_embedded) {
    include '../../includes/header.php';
} else {
    ?><!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($page_title); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <link href="<?php echo BASE_URL; ?>static/css/style.css?v=<?php echo @filemtime(__DIR__ . '/../../static/css/style.css') ?: time(); ?>" rel="stylesheet">
        <style>
            html, body { background:#f3f6fa; }
            body.student-details-embedded { min-width:0; padding:0; }
            body.student-details-embedded > .container-fluid { padding:0; }
            body.student-details-embedded .student-details-shell { padding:18px; }
            body.student-details-embedded .student-details-heading { margin-bottom:16px !important; }
            body.student-details-embedded .student-details-heading h2 { font-size:22px; }
            body.student-details-embedded .student-details-heading .student-details-actions { display:none; }
            body.student-details-embedded .card { box-shadow:0 5px 16px rgba(35,75,125,.07) !important; }
            body.student-details-embedded .student-details-shell { padding:14px 18px 18px; }
            body.student-details-embedded .student-details-heading { margin-top:0 !important; padding:0 2px 12px; border-bottom:1px solid #e1e8f2; }
            body.student-details-embedded .student-details-heading h2 { margin:0; font-size:21px; letter-spacing:-.02em; }
            body.student-details-embedded .student-details-layout { --bs-gutter-x:14px; --bs-gutter-y:14px; }
            body.student-details-embedded .student-details-layout > .col-md-4 { width:34%; }
            body.student-details-embedded .student-details-layout > .col-md-8 { width:66%; }
            body.student-details-embedded .student-profile-card { height:100%; margin:0 !important; }
            body.student-details-embedded .student-profile-card .card-body { padding:16px 18px 12px; }
            body.student-details-embedded .student-profile-card .mb-3 { margin-bottom:9px !important; }
            body.student-details-embedded .student-profile-card img,
            body.student-details-embedded .student-profile-card .fa-user-graduate { width:84px !important; height:84px !important; font-size:34px !important; }
            body.student-details-embedded .student-profile-card h5 { margin:8px 0 5px; font-size:16px; }
            body.student-details-embedded .student-profile-card hr { margin:12px 0 8px; }
            body.student-details-embedded .student-profile-card table { margin:0; font-size:13.5px; }
            body.student-details-embedded .student-profile-card table td { padding:6px 4px; border-bottom:1px solid #edf1f6; }
            body.student-details-embedded .student-profile-card table tr:last-child td { border-bottom:0; }
            body.student-details-embedded .student-profile-card table td:first-child { width:42%; color:#405875; }
            body.student-details-embedded .student-profile-card table td:last-child { color:#627895; word-break:break-word; }
            body.student-details-embedded .student-right-column .card { margin-bottom:14px !important; }
            body.student-details-embedded .student-right-column .card-header { padding:11px 15px !important; }
            body.student-details-embedded .student-right-column .card-header h5 { font-size:15px; }
            body.student-details-embedded .student-right-column .card-body { padding:13px 15px; }
            body.student-details-embedded .student-risk-metric { padding:10px !important; }
            body.student-details-embedded .student-risk-metric h6 { margin-bottom:4px; font-size:12.5px; }
            body.student-details-embedded .student-risk-metric h3 { margin:0; font-size:21px; }
            body.student-details-embedded .student-risk-alert { margin-top:10px !important; padding:9px 11px; font-size:12.5px; }
            body.student-details-embedded .student-grades-card .table th,
            body.student-details-embedded .student-grades-card .table td { padding:8px 10px; font-size:12.5px; white-space:nowrap; }
            body.student-details-embedded .student-grades-card .table th { color:#637894; font-size:12px; text-transform:uppercase; letter-spacing:.03em; }
            @media (max-width: 700px) {
                body.student-details-embedded .student-details-layout > .col-md-4,
                body.student-details-embedded .student-details-layout > .col-md-8 { width:100%; }
            }
        </style>
    </head>
    <body class="student-details-embedded">
    <?php
}
?>

<div class="student-details-shell">
<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4 student-details-heading">
            <h2><i class="fas fa-user-graduate text-primary me-2"></i>Student Details</h2>
            <div class="student-details-actions">
                <a href="edit.php?id=<?php echo $student['student_id']; ?>" class="btn btn-primary btn-sm">
                    <i class="fas fa-edit me-1"></i>Edit
                </a>
                <a href="index.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row student-details-layout">
    <div class="col-md-4">
        <div class="card shadow-sm rounded-3 mb-3 student-profile-card">
            <div class="card-body text-center">
                <!-- PROFILE PICTURE - ITO ANG BAGO! -->
                <div class="mb-3">
                    <?php if ($profile_image): ?>
                        <img src="<?php echo $profile_image; ?>" alt="Profile" 
                             style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 4px solid #1565c0;">
                    <?php else: ?>
                        <div style="width: 120px; height: 120px; border-radius: 50%; background: #e3f2fd; display: flex; align-items: center; justify-content: center; font-size: 50px; color: #1565c0; margin: 0 auto; border: 4px solid #1565c0;">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                    <?php endif; ?>
                </div>
                
                <h5><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></h5>
                <span class="badge bg-<?php echo $student['enrollment_status'] == 'Enrolled' ? 'success' : 'warning'; ?>">
                    <?php echo $student['enrollment_status'] ?? 'Pending'; ?>
                </span>
                <?php if ($active_year_status): ?>
                    <br><span class="badge bg-success mt-2"><i class="fas fa-graduation-cap me-1"></i><?php echo htmlspecialchars($active_year_status['year_status']); ?></span>
                <?php endif; ?>
                <?php if (!empty($student['strand'])): ?>
                    <br><span class="badge bg-info mt-2"><?php echo htmlspecialchars($student['strand'] === 'ASSH' ? 'ASSH Cluster' : $student['strand']); ?></span>
                <?php endif; ?>
                <hr>
                <table class="table table-sm table-borderless text-start">
                    <tr><td class="fw-bold">LRN:</td><td><?php echo attQrLrnLink($student['lrn']); ?></td></tr>
                    <tr><td class="fw-bold">Grade Level:</td><td><?php echo htmlspecialchars($student['grade_level']); ?></td></tr>
                    <tr><td class="fw-bold">Section:</td><td><?php echo htmlspecialchars($student['section_name'] ?? 'N/A'); ?></td></tr>
                    <tr><td class="fw-bold">Gender:</td><td><?php echo $student['gender']; ?></td></tr>
                    <tr><td class="fw-bold">Birth Date:</td><td><?php echo date('M d, Y', strtotime($student['birth_date'])); ?></td></tr>
                    <tr><td class="fw-bold">Contact:</td><td><?php echo htmlspecialchars($student['contact_number'] ?? 'N/A'); ?></td></tr>
                    <tr><td class="fw-bold">Email:</td><td><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></td></tr>
                    <tr><td class="fw-bold">Guardian:</td><td><?php echo htmlspecialchars($student['guardian_name'] ?? 'N/A'); ?></td></tr>
                    <?php
                        require_once __DIR__ . '/../../includes/student_password.php';
                        spwEnsureSchema();
                        $pw_row = getRecord("SELECT portal_password_set_at FROM students WHERE student_id = ?", [(int) $student['student_id']]);
                    ?>
                    <tr><td class="fw-bold">Portal Password:</td><td>
                        <?php if (!empty($pw_row['portal_password_set_at'])): ?>
                            <span class="badge bg-success">Set</span> <small class="text-muted"><?php echo date('M j, Y', strtotime($pw_row['portal_password_set_at'])); ?></small>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">Not created yet</span>
                        <?php endif; ?>
                    </td></tr>
                </table>
                <?php if (!empty($_SESSION['portal_link_flash'])): [$plf_type, $plf_msg] = $_SESSION['portal_link_flash']; unset($_SESSION['portal_link_flash']); ?>
                    <div class="alert alert-<?php echo $plf_type; ?> py-2 small text-start"><?php echo htmlspecialchars($plf_msg); ?></div>
                <?php endif; ?>
                <?php
                    // ---- Parent Portal access (Admin, Registrar, the section's adviser) ----
                    require_once __DIR__ . '/../parents/common.php';
                    if (ppStaffCanStudent((int) $student['student_id'])):
                        $pp_parents = ppParentsOf((int) $student['student_id']);
                        $pp_csrf = generateCSRFToken();
                        $pp_back = 'view.php?id=' . (int) $student['student_id'] . ($is_embedded ? '&embed=1' : '');
                ?>
                    <div class="text-start border rounded-3 p-2 mb-3" style="font-size:15px">
                        <div class="fw-bold mb-1"><i class="fas fa-people-roof me-1"></i> Parent Portal</div>
                        <?php if (!empty($_SESSION['pp_flash'])): $ppf = $_SESSION['pp_flash']; unset($_SESSION['pp_flash']); ?>
                            <div class="alert alert-<?php echo $ppf[0]; ?> py-2 small mb-2"><?php echo htmlspecialchars($ppf[1]); ?>
                                <?php if (!empty($ppf[2])): ?><textarea class="form-control form-control-sm mt-1" rows="4" readonly onclick="this.select()"><?php echo htmlspecialchars($ppf[2]); ?></textarea><?php endif; ?></div>
                        <?php endif; ?>
                        <?php if (!$pp_parents): ?>
                            <div class="text-muted small mb-2">No parent linked yet. Guardian mobile: <?php echo htmlspecialchars(smsMask($student['guardian_contact'] ?? '') ?: 'none'); ?></div>
                        <?php endif; ?>
                        <?php foreach ($pp_parents as $pp): ?>
                            <div class="d-flex align-items-center gap-1 border-top py-1">
                                <div class="flex-grow-1"><?php echo htmlspecialchars(($pp['full_name'] ?: 'Parent') . ($pp['relationship'] ? ' (' . $pp['relationship'] . ')' : '')); ?>
                                    <small class="d-block text-muted"><?php echo htmlspecialchars(smsMask($pp['mobile'])); ?> · <?php echo !empty($pp['email']) ? 'email ' . htmlspecialchars(ppMaskEmail($pp['email'])) : 'no email'; ?> · <?php echo $pp['last_login'] ? 'last sign-in ' . date('M j', strtotime($pp['last_login'])) : 'never signed in'; ?></small></div>
                                <form method="POST" action="../parents/actions.php" class="m-0"><input type="hidden" name="csrf_token" value="<?php echo $pp_csrf; ?>"><input type="hidden" name="student_id" value="<?php echo (int) $student['student_id']; ?>"><input type="hidden" name="parent_id" value="<?php echo (int) $pp['parent_id']; ?>"><input type="hidden" name="action" value="reset_pin"><button class="btn btn-sm btn-outline-secondary" title="Print a Reset PIN slip">PIN</button></form>
                                <form method="POST" action="../parents/actions.php" class="m-0" onsubmit="return confirm('Remove this parent\'s access to this child?');"><input type="hidden" name="csrf_token" value="<?php echo $pp_csrf; ?>"><input type="hidden" name="student_id" value="<?php echo (int) $student['student_id']; ?>"><input type="hidden" name="parent_id" value="<?php echo (int) $pp['parent_id']; ?>"><input type="hidden" name="back" value="../students/<?php echo htmlspecialchars($pp_back); ?>"><input type="hidden" name="action" value="unlink"><button class="btn btn-sm btn-outline-danger" title="Unlink"><i class="fas fa-link-slash"></i></button></form>
                            </div>
                        <?php endforeach; ?>
                        <div class="d-flex gap-1 mt-2 flex-wrap">
                            <form method="POST" action="../parents/actions.php" class="m-0"><input type="hidden" name="csrf_token" value="<?php echo $pp_csrf; ?>"><input type="hidden" name="student_id" value="<?php echo (int) $student['student_id']; ?>"><input type="hidden" name="back" value="../students/<?php echo htmlspecialchars($pp_back); ?>"><input type="hidden" name="action" value="send_sms"><button class="btn btn-sm btn-primary"><i class="fas fa-comment-sms me-1"></i>Send SMS</button></form>
                            <a class="btn btn-sm btn-outline-primary" target="_blank" href="../parents/print_slips.php?student_id=<?php echo (int) $student['student_id']; ?>"><i class="fas fa-print me-1"></i>Print Slip</a>
                            <a class="btn btn-sm btn-outline-secondary" target="_blank" href="../parents/progress_report.php?student_id=<?php echo (int) $student['student_id']; ?>"><i class="fas fa-file-lines me-1"></i>Progress Report</a>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (hasAnyRole(['Administrator', 'Registrar'])): ?>
                    <form method="POST" action="send_portal_link.php" onsubmit="return confirm('Email a Create / Set New Password link to this student?');">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="student_id" value="<?php echo (int) $student['student_id']; ?>">
                        <?php if ($is_embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="fas fa-key me-1"></i> Send Password Link</button>
                        <div class="small text-muted mt-1">The student sets their own password. The school never sees it.</div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-8 student-right-column">
        <!-- Risk / Performance Analysis -->
        <?php if ($risk['status'] === 'ok'): ?>
        <div class="card shadow-sm rounded-3 mb-3 student-risk-card">
            <div class="card-header bg-white border-0 pt-3">
                <h5 class="mb-0"><i class="fas fa-chart-line text-primary me-2"></i>Academic Risk Analysis</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-6">
                        <div class="border rounded p-3 text-center student-risk-metric">
                            <h6 class="text-muted">Average Grade</h6>
                            <h3><?php echo number_format($risk['average'], 2); ?>%</h3>
                            <small class="text-muted">Average of <?php echo (int)$risk['subject_count']; ?> subject(s)</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="border rounded p-3 text-center student-risk-metric">
                            <h6 class="text-muted">Risk Level</h6>
                            <h3>
                                <span class="badge bg-<?php echo $risk['risk_level'] == 'Low' ? 'success' : ($risk['risk_level'] == 'Medium' ? 'warning' : 'danger'); ?>"
                                    style="font-size: 20px; cursor: help;"
                                    data-bs-toggle="tooltip"
                                    data-bs-placement="top"
                                    title="<?php echo htmlspecialchars(getRiskLevelDescription($risk['risk_level'], $risk['pass_mark'])); ?>">
                                    <?php echo htmlspecialchars($risk['risk_level']); ?>
                                    <i class="fas fa-info-circle ms-1" style="font-size: 13.5px;"></i>
                                </span>
                            </h3>
                        </div>
                    </div>
                </div>
                <div class="alert alert-info mt-3 mb-0 student-risk-alert">
                    <i class="fas fa-lightbulb me-2"></i>
                    <strong>Reason:</strong> <?php echo htmlspecialchars($risk['reason']); ?>
                </div>
                <?php if (!empty($risk['failed_subjects'])): ?>
                <div class="mt-2">
                    <?php foreach ($risk['failed_subjects'] as $fs): ?>
                        <span class="badge bg-danger bg-opacity-75 me-1 mb-1"><?php echo htmlspecialchars($fs['subject_name']); ?>: <?php echo number_format($fs['grade'], 2); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php elseif ($risk['status'] === 'insufficient_data'): ?>
        <div class="card shadow-sm rounded-3 mb-3 student-risk-card">
            <div class="card-body">
                <span class="badge bg-secondary mb-2">Insufficient Data</span>
                <p class="mb-0 text-muted small"><?php echo htmlspecialchars($risk['reason']); ?></p>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Grades -->
        <div class="card shadow-sm rounded-3 student-grades-card">
            <div class="card-header bg-white border-0 pt-3">
                <h5 class="mb-0"><i class="fas fa-calculator text-primary me-2"></i>Grades</h5>
            </div>
            <div class="card-body p-0">
                <?php if ($grades && count($grades) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Subject</th>
                                    <th>Term</th>
                                    <th>WW</th>
                                    <th>PT</th>
                                    <th>EX</th>
                                    <th>Final</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($grades as $g): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($g['subject_name']); ?></td>
                                        <td><?php echo $g['term']; ?></td>
                                        <td><?php echo number_format($g['written_work'], 2); ?></td>
                                        <td><?php echo number_format($g['performance_task'], 2); ?></td>
                                        <td><?php echo number_format($g['examinations'], 2); ?></td>
                                        <?php $g_incomplete = in_array($g['remarks'], ['Incomplete', 'Not configured'], true); ?>
                                        <td>
                                            <?php if ($g_incomplete): ?>
                                                <span class="text-muted">&mdash;</span>
                                            <?php else: ?>
                                                <strong><?php echo number_format($g['final_grade'], 2); ?></strong>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($g_incomplete): ?>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($g['remarks']); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-<?php echo $g['final_grade'] >= getGradingPassMark() ? 'success' : 'danger'; ?>">
                                                    <?php echo htmlspecialchars($g['remarks']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-calculator fa-2x d-block mb-2"></i>
                        No grades recorded.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($can_see_history): ?>
        <!-- Enrollment History -->
        <div class="card shadow-sm rounded-3 mt-3 student-grades-card">
            <div class="card-header bg-white border-0 pt-3">
                <h5 class="mb-0"><i class="fas fa-history text-primary me-2"></i>Enrollment History</h5>
            </div>
            <div class="card-body p-0">
                <?php if ($enrollment_history): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>School Year</th>
                                    <th>Grade Level</th>
                                    <th>Section</th>
                                    <th>Enrolled</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($enrollment_history as $eh): ?>
                                    <?php $eh_st = $eh['enrollment_status']; ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($eh['sy_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($eh['grade_level'] ?? '-'); ?></td>
                                        <td><?php echo !empty($eh['section_name']) ? htmlspecialchars($eh['section_name']) : '<span class="text-muted">-</span>'; ?></td>
                                        <td style="white-space:nowrap;"><?php echo !empty($eh['enrollment_date']) ? date('M d, Y', strtotime($eh['enrollment_date'])) : '-'; ?></td>
                                        <td>
                                            <?php if ($eh_st === 'Approved' && !empty($eh['year_status'])): ?>
                                                <span class="badge bg-success"><i class="fas fa-graduation-cap me-1"></i><?php echo htmlspecialchars($eh['year_status']); ?></span>
                                                <?php if ($eh['year_average'] !== null): ?>
                                                    <small class="text-muted ms-1">GA <?php echo htmlspecialchars(number_format((float)$eh['year_average'], 2)); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-<?php echo $eh_st === 'Approved' ? 'success' : ($eh_st === 'Pending' ? 'warning' : 'secondary'); ?>"><?php echo htmlspecialchars($eh_st); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-history fa-2x d-block mb-2"></i>
                        No enrollment records.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<?php if (!$is_embedded): ?>
    <?php include '../../includes/footer.php'; ?>
<?php else: ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
<?php endif; ?>