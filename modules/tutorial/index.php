<?php
// modules/tutorial/index.php
// Tutorial System for At-Risk Students — AI-generated remediation plans.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/risk_helpers.php';
require_once __DIR__ . '/../../includes/tutorial_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Teacher'])) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'Tutorial System';
$current_page = 'tutorial';
$base_url = '../../';

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
$sy_id = $active_sy['sy_id'] ?? 0;
$teacher_id = $_SESSION['user_id'];
$is_teacher = hasRole('Teacher');

// Ensure ai_analysis is up-to-date before querying it.
// The AI Analytics page does this via syncStudentRiskAnalysis(); we must
// do the same here so the Tutorial System always reflects current grades.
$sync_students = [];
if ($is_teacher) {
    $sync_students = getRecords("
        SELECT DISTINCT s.student_id
        FROM students s
        JOIN enrollments e ON s.student_id = e.student_id
        JOIN teacher_subjects ts ON e.section_id = ts.section_id
        WHERE ts.teacher_id = ? AND e.school_year_id = ? AND e.enrollment_status = 'Approved'
    ", [$teacher_id, $sy_id]);
} else {
    $sync_students = getRecords("
        SELECT DISTINCT s.student_id
        FROM students s
        JOIN enrollments e ON s.student_id = e.student_id
        WHERE e.school_year_id = ? AND e.enrollment_status = 'Approved'
    ", [$sy_id]);
}
foreach ($sync_students as $stu) {
    syncStudentRiskAnalysis($stu['student_id'], $sy_id);
}

// At-risk students (from the AI Analytics table) scoped the same way
// modules/ai/index.php scopes them: teachers only see their own students.
if ($is_teacher) {
    $at_risk_students = getRecords("
        SELECT DISTINCT s.student_id, s.lrn, s.first_name, s.last_name, s.grade_level,
               a.risk_level, a.performance_score, a.analysis_data,
               tp.plan_id, tp.status AS plan_status
        FROM ai_analysis a
        JOIN students s ON a.student_id = s.student_id
        JOIN enrollments e ON s.student_id = e.student_id AND e.school_year_id = a.school_year_id
        JOIN teacher_subjects ts ON e.section_id = ts.section_id AND ts.school_year_id = a.school_year_id
        LEFT JOIN tutorial_plans tp ON tp.student_id = s.student_id AND tp.school_year_id = a.school_year_id AND tp.status IN ('Draft','Active')
        WHERE ts.teacher_id = ? AND a.school_year_id = ? AND a.risk_level IN ('High','Medium')
        ORDER BY a.risk_level = 'High' DESC, a.performance_score ASC
    ", [$teacher_id, $sy_id]);
} else {
    $at_risk_students = getRecords("
        SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.grade_level,
               a.risk_level, a.performance_score, a.analysis_data,
               tp.plan_id, tp.status AS plan_status
        FROM ai_analysis a
        JOIN students s ON a.student_id = s.student_id
        LEFT JOIN tutorial_plans tp ON tp.student_id = s.student_id AND tp.school_year_id = a.school_year_id AND tp.status IN ('Draft','Active')
        WHERE a.school_year_id = ? AND a.risk_level IN ('High','Medium')
        ORDER BY a.risk_level = 'High' DESC, a.performance_score ASC
    ", [$sy_id]);
}

// Existing tutorial plans (any status) for the summary list below.
$plan_filter_sql = "WHERE tp.school_year_id = ?";
$plan_params = [$sy_id];
if ($is_teacher) {
    $plan_filter_sql .= " AND EXISTS (
        SELECT 1 FROM tutorial_plan_items tpi WHERE tpi.plan_id = tp.plan_id AND tpi.tutor_id = ?
    )";
    $plan_params[] = $teacher_id;
}
$plans = getRecords("
    SELECT tp.*, s.lrn, s.first_name, s.last_name, s.grade_level,
           (SELECT COUNT(*) FROM tutorial_plan_items i WHERE i.plan_id = tp.plan_id) AS item_count,
           (SELECT COUNT(*) FROM tutorial_plan_items i WHERE i.plan_id = tp.plan_id AND i.status = 'Completed') AS completed_count
    FROM tutorial_plans tp
    JOIN students s ON tp.student_id = s.student_id
    $plan_filter_sql
    ORDER BY tp.created_at DESC
", $plan_params);

// Attach each plan's overall progress %, using the same clear, data-based
// formula as the individual plan page (average of each item's own
// tracked/derived progress — never a fabricated number).
foreach ($plans as &$p) {
    $plan_items = getRecords("SELECT status, watch_progress_percentage FROM tutorial_plan_items WHERE plan_id = ?", [$p['plan_id']]);
    $p['progress_percent'] = calculatePlanProgressPercent($plan_items);
}
unset($p);

// Teacher Summary — a quick count of video watch statuses across the
// items this Teacher/Administrator is authorized to see (a Teacher only
// sees items where they are the assigned tutor; an Administrator sees
// every item), so it's easy to spot who hasn't started or finished yet.
if ($is_teacher) {
    $watch_summary_rows = getRecords("
        SELECT status, COUNT(*) AS cnt
        FROM tutorial_plan_items tpi
        JOIN tutorial_plans tp ON tpi.plan_id = tp.plan_id
        WHERE tp.school_year_id = ? AND tpi.tutor_id = ?
        GROUP BY status
    ", [$sy_id, $teacher_id]);
} else {
    $watch_summary_rows = getRecords("
        SELECT status, COUNT(*) AS cnt
        FROM tutorial_plan_items tpi
        JOIN tutorial_plans tp ON tpi.plan_id = tp.plan_id
        WHERE tp.school_year_id = ?
        GROUP BY status
    ", [$sy_id]);
}
$watch_summary = ['Pending' => 0, 'In Progress' => 0, 'Completed' => 0];
foreach ($watch_summary_rows as $row) {
    if (isset($watch_summary[$row['status']])) {
        $watch_summary[$row['status']] = (int)$row['cnt'];
    }
}

include '../../includes/header.php';
?>

<style>
/* Scoped to this page. The View action is a link, so it needs to look
   like something you can press: a border, a pointer and a colour change
   on hover, plus a visible focus ring for keyboard users. Sized/coloured
   like the shared secondary button (Section 20's button system). */
.tut-page .btn-view-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 16px;
    height: 38px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface);
    color: var(--ink);
    font-size: 14.5px;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
    transition: all 0.15s;
}
.tut-page .btn-view-link:hover {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}
.tut-page .btn-view-link:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}
</style>

<div class="container-fluid py-4 tut-page">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="mb-1" style="color: var(--ink);font-weight:700;font-size:24px;">Tutorial System</h4>
            <p style="color: var(--text-muted);font-size:16px;margin-bottom:0;">AI-generated remediation plans for at-risk students.</p>
        </div>
    </div>

    <!-- Teacher Summary: quick counts of automatic video watch status -->
    <div class="row mb-3">
        <div class="col-md-4 mb-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3" style="padding:14px 18px;">
                    <div style="width:40px;height:40px;border-radius:8px;background: var(--surface-hover);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-circle-play" style="color: var(--text-muted);font-size:20px;"></i>
                    </div>
                    <div>
                        <h4 class="mb-0 fw-bold" style="color: var(--ink);font-size:28px;"><?php echo (int)$watch_summary['Pending']; ?></h4>
                        <small style="color: var(--text-muted);font-size:15px;">Not Started</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3" style="padding:14px 18px;">
                    <div style="width:40px;height:40px;border-radius:8px;background:#FFF7E6;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-eye" style="color:#B7791F;font-size:20px;"></i>
                    </div>
                    <div>
                        <h4 class="mb-0 fw-bold" style="color: var(--ink);font-size:28px;"><?php echo (int)$watch_summary['In Progress']; ?></h4>
                        <small style="color: var(--text-muted);font-size:15px;">In Progress</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3" style="padding:14px 18px;">
                    <div style="width:40px;height:40px;border-radius:8px;background:#EAF7F1;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-circle-check" style="color:#238863;font-size:20px;"></i>
                    </div>
                    <div>
                        <h4 class="mb-0 fw-bold" style="color: var(--ink);font-size:28px;"><?php echo (int)$watch_summary['Completed']; ?></h4>
                        <small style="color: var(--text-muted);font-size:15px;">Completed</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Needs a plan -->
    <div class="card border-0 shadow-sm" style="margin-bottom:16px;">
        <div class="card-header" style="background: var(--surface);border-bottom: 1px solid var(--border);padding:14px 18px;">
            <h5 class="mb-0" style="font-weight:600;color: var(--ink);font-size:19px;">At-Risk Students</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($at_risk_students)): ?>
                <div class="text-center py-4" style="color: var(--text-muted);font-size:16px;">
                    <i class="fas fa-check-circle d-block mb-2" style="font-size:20px;color:#238863;"></i>
                    No at-risk students right now.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle" style="font-size:16px;">
                        <thead style="background: var(--surface-hover);">
                            <tr>
                                <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">LRN</th>
                                <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Student Name</th>
                                <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Grade Level</th>
                                <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Risk Level</th>
                                <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Average</th>
                                <?php if ($is_teacher): ?>
                                <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($at_risk_students as $s): ?>
                                <tr>
                                    <td style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;font-size:17px;color: var(--ink);"><?php echo htmlspecialchars($s['lrn']); ?></td>
                                    <td style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;font-weight:600;font-size:17px;color: var(--ink);"><?php echo htmlspecialchars($s['last_name'] . ', ' . $s['first_name']); ?></td>
                                    <td style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;font-size:17px;color: var(--ink);"><?php echo htmlspecialchars($s['grade_level']); ?></td>
                                    <td style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;">
                                        <?php
                                        $rl = $s['risk_level'];
                                        $rl_bg = $rl === 'High' ? '#FDEEEE' : '#FFF7E6';
                                        $rl_color = $rl === 'High' ? '#C94A4A' : '#B7791F';
                                        ?>
                                        <span style="background:<?php echo $rl_bg; ?>;color:<?php echo $rl_color; ?>;padding:6px 16px;border-radius:20px;font-size:15px;font-weight:700;cursor:help;"
                                            data-bs-toggle="tooltip"
                                            data-bs-placement="top"
                                            title="<?php echo htmlspecialchars(getRiskLevelDescription($s['risk_level'])); ?>">
                                            <?php echo htmlspecialchars($s['risk_level']); ?>
                                        </span>
                                    </td>
                                    <td style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;font-weight:600;font-size:17px;color:#C94A4A;"><?php echo number_format($s['performance_score'], 2); ?>%</td>
                                    <?php if ($is_teacher): ?>
                                    <td style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;">
                                        <?php if ($s['plan_id']): ?>
                                            <a href="view.php?id=<?php echo (int)$s['plan_id']; ?>" class="btn-view-link">
                                                View Plan
                                            </a>
                                        <?php else: ?>
                                            <form action="generate.php" method="POST" class="d-inline">
                                                <input type="hidden" name="student_id" value="<?php echo (int)$s['student_id']; ?>">
                                                <input type="hidden" name="risk_level" value="<?php echo htmlspecialchars($s['risk_level']); ?>">
                                                <button type="submit" style="background:#14213D;color:#fff;border:1px solid #14213D;padding:9px 20px;border-radius:6px;font-size:15px;font-weight:600;cursor:pointer;white-space:nowrap;"
                                                    onclick="return appConfirm(this, <?php echo htmlspecialchars(json_encode('Generate a tutorial plan for ' . $s['last_name'] . ', ' . $s['first_name'] . '?'), ENT_QUOTES); ?>, {okText: 'Generate'})">
                                                    Generate Plan
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Existing plans -->
    <div class="card border-0 shadow-sm">
        <div class="card-header" style="background: var(--surface);border-bottom: 1px solid var(--border);padding:14px 18px;">
            <h5 class="mb-0" style="font-weight:600;color: var(--ink);font-size:19px;">Tutorial Plans</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($plans)): ?>
                <div class="text-center py-4" style="color: var(--text-muted);font-size:16px;">No tutorial plans yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle" style="font-size:16px;">
                        <thead style="background: var(--surface-hover);">
                            <tr>
                                <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Student</th>
                                <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Grade Level</th>
                                <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Progress</th>
                                <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Status</th>
                                <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plans as $p): ?>
                                <tr>
                                    <td style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;font-weight:600;font-size:17px;color: var(--ink);"><?php echo htmlspecialchars($p['last_name'] . ', ' . $p['first_name']); ?></td>
                                    <td style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;font-size:17px;color: var(--ink);"><?php echo htmlspecialchars($p['grade_level']); ?></td>
                                    <td style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;">
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <div style="flex:1;height:9px;background: var(--surface-hover);border-radius:5px;">
                                                <div style="height:100%;width:<?php echo $p['progress_percent']; ?>%;background:#238863;border-radius:5px;"></div>
                                            </div>
                                            <span style="font-size:15px;font-weight:700;color: var(--ink);white-space:nowrap;"><?php echo number_format($p['progress_percent'], 0); ?>%</span>
                                        </div>
                                    </td>
                                    <td style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;">
                                        <?php
                                        $sb = ['Draft' => '#f3f4f6,#667085', 'Active' => '#EDF4FB,#14213D', 'Completed' => '#EAF7F1,#238863', 'Cancelled' => '#f3f4f6,#667085'][$p['status']] ?? '#f3f4f6,#667085';
                                        list($sbg, $scl) = explode(',', $sb);
                                        ?>
                                        <span style="background:<?php echo $sbg; ?>;color:<?php echo $scl; ?>;padding:6px 16px;border-radius:20px;font-size:15px;font-weight:700;"><?php echo $p['status']; ?></span>
                                    </td>
                                    <td style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;">
                                        <a href="view.php?id=<?php echo (int)$p['plan_id']; ?>" class="btn-view-link">
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>