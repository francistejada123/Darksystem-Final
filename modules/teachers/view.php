<?php
// modules/teachers/view.php
// Teacher Profile / Details view (Admin/Registrar)

require_once __DIR__ . '/../../config/database.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/teacher_validation.php';

if (!hasAnyRole(['Administrator', 'Registrar'])) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'Teacher Profile';
$current_page = 'teachers';
$base_url = '../../';

$teacher_id = intval($_GET['id'] ?? 0);
if ($teacher_id <= 0) {
    $_SESSION['error_message'] = 'Invalid teacher ID.';
    header('Location: index.php');
    exit;
}

$teacher = getRecord("SELECT * FROM users WHERE user_id = ? AND role_id = 3", [$teacher_id]);
if (!$teacher) {
    $_SESSION['error_message'] = 'Teacher not found.';
    header('Location: index.php');
    exit;
}

$active_sy = getRecord("SELECT sy_id, sy_name FROM school_years WHERE is_active = 1");
$school_year_id = $active_sy ? $active_sy['sy_id'] : 0;

$assignments = getRecords("
    SELECT ts.ts_id, ts.subject_code, ts.section_id, ts.school_year_id,
           sub.subject_name, sub.subject_type,
           sec.section_name, sec.grade_level, sec.strand,
           sy.sy_name
    FROM teacher_subjects ts
    JOIN subjects sub ON ts.subject_code = sub.subject_code
    JOIN sections sec ON ts.section_id = sec.section_id
    JOIN school_years sy ON ts.school_year_id = sy.sy_id
    WHERE ts.teacher_id = ?
    ORDER BY sec.grade_level, sec.section_name, sub.subject_name
", [$teacher_id]);

$missing_fields = tvMissingFields($teacher);
// Shows the value, or a "Not set" marker when the field is empty.
function tpValue($v, string $suffix = ''): string
{
    $v = trim((string) ($v ?? ''));
    return $v === '' ? '<span class="not-set"><i class="fas fa-circle-exclamation"></i> Not set</span>' : htmlspecialchars($v) . $suffix;
}
$grade_counts = [];
foreach ($assignments as $a) { $grade_counts[$a['grade_level']] = ($grade_counts[$a['grade_level']] ?? 0) + 1; }
uksort($grade_counts, function ($x, $y) { return (int) filter_var($x, FILTER_SANITIZE_NUMBER_INT) <=> (int) filter_var($y, FILTER_SANITIZE_NUMBER_INT); });
usort($assignments, function ($x, $y) {
    return [(int) filter_var($x['grade_level'], FILTER_SANITIZE_NUMBER_INT), $x['section_name'], $x['subject_name']]
       <=> [(int) filter_var($y['grade_level'], FILTER_SANITIZE_NUMBER_INT), $y['section_name'], $y['subject_name']];
});
$photo = '';
if (!empty($teacher['profile_image'])) {
    $img = $teacher['profile_image'];
    if (preg_match('#^(https?:)?//#', $img)) {
        $photo = $img;
    } else {
        foreach (['static/uploads/profiles/' . basename($img), ltrim($img, '/')] as $rel) {
            if (is_file(__DIR__ . '/../../' . $rel)) { $photo = '../../' . $rel; break; }
        }
    }
}

include '../../includes/header.php';

$qs = $teacher['qualification_status'] ?? 'Pending';
$qs_class = match($qs) { 'Qualified' => 'qualified', 'Not Qualified' => 'not-qualified', default => 'pending-qs' };
?>

<style>
.tprofile { background: var(--surface-hover); }
.tprofile .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.tprofile .page-header h4 { font-size: 20px; font-weight: 700; color: var(--ink); margin: 0; }
.tprofile .page-header-actions { display: flex; gap: 8px; }
.tprofile .btn-primary-action { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 500; color: #fff; background: #14213D; border: none; text-decoration: none; transition: all 0.15s; }
.tprofile .btn-primary-action:hover { filter: brightness(0.9); }
.tprofile .btn-back { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 500; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); text-decoration: none; transition: all 0.15s; }
.tprofile .btn-back:hover { background: var(--surface-hover); color: var(--ink); }
.tprofile .content-grid { display: grid; grid-template-columns: 1fr 340px; gap: 24px; }
.tprofile .info-card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; margin-bottom: 20px; }
.tprofile .info-card-header { padding: 14px 20px; border-bottom: 1px solid var(--border-soft); display: flex; align-items: center; justify-content: space-between; }
.tprofile .info-card-header h5 { font-size: 14px; font-weight: 600; color: var(--ink); margin: 0; display: flex; align-items: center; gap: 8px; }
.tprofile .info-card-header i { font-size: 13px; color: var(--ink); }
.tprofile .info-card-body { padding: 20px; }
.tprofile .detail-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.tprofile .detail-item { display: flex; flex-direction: column; gap: 2px; }
.tprofile .detail-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); }
.tprofile .detail-value { font-size: 14px; color: var(--ink); }
.tprofile .status-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.tprofile .status-active { background: #EAF7F1; color: #238863; }
.tprofile .status-inactive { background: #FDEEEE; color: #C94A4A; }
.tprofile .qs-qualified { background: #EAF7F1; color: #238863; }
.tprofile .qs-not-qualified { background: #FDEEEE; color: #C94A4A; }
.tprofile .qs-pending-qs { background: var(--surface-hover); color: var(--text-muted); }
.tprofile .data-table { width: 100%; border-collapse: collapse; }
.tprofile .data-table thead th { background: var(--surface-hover); border-bottom: 1px solid var(--border); padding: 10px 16px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); text-align: left; }
.tprofile .data-table tbody td { padding: 12px 16px; font-size: 13px; color: var(--ink); border-bottom: 1px solid var(--border-soft); vertical-align: middle; }
.tprofile .data-table tbody tr:hover { background: var(--surface-hover); }
.tprofile .data-table tbody tr:last-child td { border-bottom: none; }
.tprofile .grade-level-tag { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; background: #F2F4F7; color: #344054; }
.tprofile .empty-state { text-align: center; padding: 32px 20px; color: var(--text-muted); }
.tprofile .empty-state i { font-size: 28px; margin-bottom: 8px; opacity: 0.4; display: block; }
.tprofile .empty-state p { font-size: 13px; margin: 0; }

/* Sidebar */
.tprofile .sidebar-card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; margin-bottom: 20px; text-align: center; }
.tprofile .sidebar-card .card-body { padding: 24px 20px; }
.tprofile .avatar { width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 700; color: #fff; background: #14213D; margin: 0 auto 12px auto; }
.tprofile .sidebar-card h5 { font-size: 16px; font-weight: 600; color: var(--ink); margin: 0 0 4px 0; }
.tprofile .sidebar-card .sub { font-size: 12px; color: var(--text-muted); }
.tprofile .sidebar-header { padding: 14px 20px; border-bottom: 1px solid var(--border-soft); text-align: left; }
.tprofile .sidebar-header h6 { font-size: 13px; font-weight: 600; color: var(--ink); margin: 0; display: flex; align-items: center; gap: 6px; }
.tprofile .sidebar-header i { font-size: 12px; color: var(--ink); }
.tprofile .stat-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 20px; border-bottom: 1px solid var(--border-soft); }
.tprofile .stat-row:last-child { border-bottom: none; }
.tprofile .stat-row .label { font-size: 12px; color: var(--text-muted); }
.tprofile .stat-row .value { font-size: 14px; font-weight: 600; color: var(--ink); }
/* Empty fields + incomplete-profile notice */
.tprofile .not-set { color: #B45309; font-style: italic; font-size: 13.5px; }
.tprofile .not-set i { font-size: 12px; margin-right: 3px; }
.tprofile .tp-incomplete { display: flex; align-items: flex-start; gap: 12px; padding: 14px 18px; margin-bottom: 20px; border: 1px solid #F5D9A8; border-left: 4px solid #D97706; border-radius: 8px; background: #FFF8EB; color: #7A4A06; font-size: 14px; }
.tprofile .tp-incomplete i { color: #D97706; font-size: 18px; margin-top: 2px; }
.tprofile .tp-incomplete strong { display: block; color: #7A4A06; margin-bottom: 2px; }
.tprofile .tp-incomplete a { margin-left: auto; align-self: center; white-space: nowrap; padding: 8px 14px; border-radius: 6px; background: #D97706; color: #fff; font-weight: 600; font-size: 13px; text-decoration: none; }
.tprofile .tp-incomplete a:hover { background: #B45309; }
/* Grade level filter above Subject Assignments */
.tprofile .tp-grade-filter { display: flex; flex-wrap: wrap; gap: 8px; padding: 12px 20px; border-bottom: 1px solid var(--border-soft); }
.tprofile .tp-grade-filter button { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 999px; border: 1px solid var(--border); background: var(--surface); color: var(--ink); font-size: 13px; font-weight: 600; cursor: pointer; transition: background .15s, border-color .15s, color .15s; }
.tprofile .tp-grade-filter button span { display: inline-grid; place-items: center; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; background: var(--surface-hover); color: var(--text-muted); font-size: 11.5px; }
.tprofile .tp-grade-filter button:hover { border-color: #14213D; }
.tprofile .tp-grade-filter button.active { background: #14213D; border-color: #14213D; color: #fff; }
.tprofile .tp-grade-filter button.active span { background: rgba(255,255,255,.2); color: #fff; }
html[data-theme="dark"] .tprofile .tp-incomplete { background: rgba(217,119,6,.12); border-color: rgba(217,119,6,.4); color: #F5C77E; }
html[data-theme="dark"] .tprofile .tp-incomplete strong { color: #F5C77E; }
html[data-theme="dark"] .tprofile .not-set { color: #F5B454; }
html[data-theme="dark"] .tprofile .tp-grade-filter button.active { background: #3B82F6; border-color: #3B82F6; }
@media (max-width: 768px) {
    .tprofile .tp-incomplete { flex-wrap: wrap; }
    .tprofile .content-grid { grid-template-columns: 1fr; }
    .tprofile .detail-grid { grid-template-columns: 1fr 1fr; }
}
</style>

<div class="tprofile" style="padding: 24px;">

    <div class="page-header">
        <h4>Teacher Profile</h4>
        <div class="page-header-actions">
            <a href="edit.php?id=<?php echo $teacher_id; ?>" class="btn-primary-action">
                <i class="fas fa-edit"></i> Edit
            </a>
            <a href="index.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <?php if ($missing_fields): ?>
        <div class="tp-incomplete" role="status">
            <i class="fas fa-triangle-exclamation"></i>
            <div>
                <strong>Incomplete profile</strong>
                Missing: <?php echo htmlspecialchars(implode(', ', $missing_fields)); ?>.
            </div>
            <a href="edit.php?id=<?php echo $teacher_id; ?>"><i class="fas fa-pen me-1"></i> Complete profile</a>
        </div>
    <?php endif; ?>

    <div class="content-grid">
        <!-- Main Content -->
        <div>
            <!-- Basic Information -->
            <div class="info-card">
                <div class="info-card-header">
                    <h5><i class="fas fa-user"></i> Basic Information</h5>
                </div>
                <div class="info-card-body">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">Full Name</span>
                            <span class="detail-value"><?php
                                $full_name = htmlspecialchars($teacher['first_name']) . ' ' . htmlspecialchars($teacher['last_name']);
                                if (!empty($teacher['middle_name'])) $full_name = htmlspecialchars($teacher['first_name']) . ' ' . htmlspecialchars($teacher['middle_name'] . ' ') . htmlspecialchars($teacher['last_name']);
                                if (!empty($teacher['suffix'])) $full_name .= ' ' . htmlspecialchars($teacher['suffix']);
                                echo $full_name;
                            ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Sex / Gender</span>
                            <span class="detail-value"><?php echo tpValue($teacher['sex'] ?? ''); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Email</span>
                            <span class="detail-value"><?php echo htmlspecialchars($teacher['email']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Contact Number</span>
                            <span class="detail-value"><?php echo tpValue($teacher['contact_number'] ?? ''); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Status</span>
                            <span class="detail-value"><span class="status-badge status-<?php echo $teacher['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $teacher['is_active'] ? 'Active' : 'Inactive'; ?></span></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Professional Information -->
            <div class="info-card">
                <div class="info-card-header">
                    <h5><i class="fas fa-graduation-cap"></i> Professional Information</h5>
                </div>
                <div class="info-card-body">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">Teacher ID / Employee ID</span>
                            <span class="detail-value"><?php echo tpValue($teacher['employee_id'] ?? ''); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Department / Level</span>
                            <span class="detail-value"><?php echo tpValue($teacher['department_level'] ?? ''); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Employment Status</span>
                            <span class="detail-value"><?php echo tpValue($teacher['employment_status'] ?? ''); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Highest Educational Attainment</span>
                            <span class="detail-value"><?php echo tpValue($teacher['highest_educ_attainment'] ?? ''); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Degree / Course</span>
                            <span class="detail-value"><?php echo tpValue($teacher['degree_course'] ?? ''); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Specialization</span>
                            <span class="detail-value"><?php echo tpValue($teacher['specialization'] ?? ''); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Years of Experience</span>
                            <span class="detail-value"><?php echo htmlspecialchars($teacher['years_experience'] ?? 0); ?> years</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Position / Designation</span>
                            <span class="detail-value"><?php echo tpValue($teacher['position_designation'] ?? ''); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">PRC License Number</span>
                            <span class="detail-value"><?php echo tpValue($teacher['prc_license_number'] ?? ''); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Qualification Status</span>
                            <span class="detail-value"><span class="status-badge <?php echo $qs_class; ?>"><?php echo htmlspecialchars($qs); ?></span></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Subject Assignments -->
            <div class="info-card">
                <div class="info-card-header">
                    <h5><i class="fas fa-book"></i> Subject Assignments</h5>
                    <span id="tpAssignCount" style="display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; border-radius: 11px; background: var(--surface-hover); font-size: 11px; font-weight: 600; color: var(--text-muted); padding: 0 6px;"><?php echo count($assignments); ?></span>
                </div>
                <?php if (count($grade_counts) > 1): ?>
                    <div class="tp-grade-filter" role="group" aria-label="Filter by grade level">
                        <button type="button" class="active" data-grade="">All <span><?php echo count($assignments); ?></span></button>
                        <?php foreach ($grade_counts as $g => $n): ?>
                            <button type="button" data-grade="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?> <span><?php echo $n; ?></span></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($assignments)): ?>
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Grade</th>
                                    <th>Section</th>
                                    <th>Subject</th>
                                    <th>SY</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $a): ?>
                                    <tr data-grade="<?php echo htmlspecialchars($a['grade_level']); ?>">
                                        <td><span class="grade-level-tag"><?php echo htmlspecialchars($a['grade_level']); ?></span></td>
                                        <td>
                                            <?php echo htmlspecialchars($a['section_name']); ?>
                                            <?php if (!empty($a['strand'])): ?>
                                                <small style="color: var(--text-muted);">/ <?php echo htmlspecialchars($a['strand']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($a['subject_name']); ?>
                                            <small style="color: var(--text-muted);">(<?php echo htmlspecialchars($a['subject_code']); ?>)</small>
                                        </td>
                                        <td><small style="color: var(--text-muted);"><?php echo htmlspecialchars($a['sy_name']); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-book"></i>
                        <p>No subject assignments yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sidebar -->
        <div>
            <!-- Profile Card -->
            <div class="sidebar-card">
                <div class="card-body">
                    <?php if ($photo !== ''): ?>
                        <img src="<?php echo htmlspecialchars($photo); ?>" alt="" class="rounded-circle mb-3" width="80" height="80" style="object-fit:cover;">
                    <?php else: ?>
                        <div class="avatar">
                            <?php echo strtoupper(mb_substr($teacher['first_name'],0,1) . mb_substr($teacher['last_name'],0,1)); ?>
                        </div>
                    <?php endif; ?>
                    <h5><?php
                        echo htmlspecialchars($teacher['first_name']) . ' ' . htmlspecialchars($teacher['last_name']);
                        if (!empty($teacher['suffix'])) echo ' ' . htmlspecialchars($teacher['suffix']);
                    ?></h5>
                    <div class="sub"><?php echo htmlspecialchars($teacher['specialization'] ?? 'No specialization'); ?></div>
                    <div style="margin-top: 8px;">
                        <span class="status-badge status-<?php echo $teacher['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $teacher['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="info-card">
                <div class="sidebar-header">
                    <h6><i class="fas fa-chart-bar"></i> Quick Stats</h6>
                </div>
                <div>
                    <div class="stat-row">
                        <span class="label">Assigned Sections</span>
                        <span class="value"><?php
                            $unique_sections = array_unique(array_map(fn($a) => $a['section_id'], $assignments));
                            echo count($unique_sections);
                        ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">Assigned Subjects</span>
                        <span class="value"><?php
                            $unique_subjects = array_unique(array_map(fn($a) => $a['subject_code'], $assignments));
                            echo count($unique_subjects);
                        ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">Experience</span>
                        <span class="value"><?php echo htmlspecialchars($teacher['years_experience'] ?? 0); ?> yrs</span>
                    </div>
                </div>
            </div>

            <!-- Account Info -->
            <div class="info-card">
                <div class="sidebar-header">
                    <h6><i class="fas fa-key"></i> Account Info</h6>
                </div>
                <div>
                    <div class="stat-row">
                        <span class="label">Username</span>
                        <span class="value"><?php echo htmlspecialchars($teacher['username']); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">Last Login</span>
                        <span class="value"><?php echo $teacher['last_login'] ? date('M j, Y g:i A', strtotime($teacher['last_login'])) : 'Never'; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">Joined</span>
                        <span class="value"><?php echo date('M j, Y', strtotime($teacher['created_at'])); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
// Subject Assignments: show one grade level at a time.
document.querySelectorAll('.tp-grade-filter button').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var g = btn.getAttribute('data-grade'), shown = 0;
        document.querySelectorAll('.tp-grade-filter button').forEach(function (b) { b.classList.toggle('active', b === btn); });
        document.querySelectorAll('.tprofile .data-table tbody tr[data-grade]').forEach(function (tr) {
            var on = !g || tr.getAttribute('data-grade') === g;
            tr.style.display = on ? '' : 'none';
            if (on) shown++;
        });
        var c = document.getElementById('tpAssignCount');
        if (c) c.textContent = shown;
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
