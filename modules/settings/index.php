<?php
// modules/settings/index.php
// System settings

require_once '../../config/database.php';
require_once '../../includes/auth.php';
syncSectionEnrollmentCounts(); // section enrollment counts (and the delete-section guard) use the real numbers

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'System Settings';
$current_page = 'settings';
$base_url = BASE_URL;

$errors = [];
$success = false;

// The school only offers two Senior High strands. Keep this list as the
// single source of truth — it drives the Add Section form AND validation
// below, so anything typed or tampered with outside these two is rejected.
$valid_strands = ['GAS', 'ASSH'];
$strand_labels = [
    'GAS'  => 'GAS - General Academic Strand',
    'ASSH' => 'ASSH Cluster (Arts, Social Sciences, and Humanities)',
];

// Get all settings
$settings = getRecords("SELECT * FROM system_settings ORDER BY setting_group, setting_key");

// Group settings by group
$grouped_settings = [];
foreach ($settings as $setting) {
    $grouped_settings[$setting['setting_group']][$setting['setting_key']] = $setting['setting_value'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $setting_group = sanitize($_POST['setting_group'] ?? 'general');

        // Retired in the DepEd Three-Term cleanup (SY 2026-2027). Grades 7-12
        // component weights come ONLY from the verified subject category in
        // includes/grading_helpers.php. Refuse to (re)create a global override
        // even if an old cached form or a crafted POST sends one.
        $retired_settings = ['grading_ww_weight', 'grading_pt_weight', 'grading_qa_weight'];

        foreach ($_POST as $key => $value) {
            if (strpos($key, 'setting_') === 0) {
                $setting_key = substr($key, 8);
                $setting_value = sanitize($value);

                if (in_array($setting_key, $retired_settings, true)) {
                    continue;
                }

                $existing = getRecord("SELECT setting_id FROM system_settings WHERE setting_key = ?", [$setting_key]);
                if ($existing) {
                    $sql = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
                } else {
                    $sql = "INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)";
                }
                executeQuery($sql, [$setting_value, $setting_key, $setting_group]);
            }
        }

        logActivity($_SESSION['user_id'], 'settings', 'Updated settings', "Updated $setting_group settings");
        $_SESSION['success_message'] = 'Settings updated successfully!';
        header('Location: index.php?success=1');
        exit;
    }
}

$csrf_token = generateCSRFToken();

// ============================================================
// DELETE SECTION / STRAND
// Actually verifies the row was removed before reporting success,
// and refuses to delete a section that still has enrolled students
// so nobody loses their section assignment silently.
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'delete_section' && isset($_GET['id'])) {
    $section_id = intval($_GET['id']);
    $section_row = getRecord("SELECT section_name, current_enrollment FROM sections WHERE section_id = ?", [$section_id]);

    if (!$section_row) {
        $_SESSION['error_message'] = 'That section/strand no longer exists.';
    } elseif ((int)$section_row['current_enrollment'] > 0) {
        $_SESSION['error_message'] = "Cannot delete \"{$section_row['section_name']}\" — it still has {$section_row['current_enrollment']} enrolled student(s). Move or unenroll them first.";
    } else {
        $delete_result = executeQuery("DELETE FROM sections WHERE section_id = ?", [$section_id]);

        if ($delete_result && $delete_result->rowCount() > 0) {
            logActivity($_SESSION['user_id'], 'settings', 'Deleted section', "Deleted section/strand: {$section_row['section_name']}");
            $_SESSION['success_message'] = "\"{$section_row['section_name']}\" was deleted from the database.";
        } else {
            $_SESSION['error_message'] = 'The section/strand could not be deleted. Please try again.';
        }
    }

    header('Location: index.php?tab=sections');
    exit;
}

// Handle add section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_section') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $section_name = sanitize($_POST['section_name'] ?? '');
        $grade_level = sanitize($_POST['grade_level'] ?? '');
        $strand = sanitize($_POST['strand'] ?? '');
        $academic_year = sanitize($_POST['academic_year'] ?? '');
        $capacity = intval($_POST['capacity'] ?? 45);
        $is_senior_high = ($grade_level === 'Grade 11' || $grade_level === 'Grade 12') ? 1 : 0;

        $valid_grades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];

        if (empty($section_name) || !in_array($grade_level, $valid_grades, true)) {
            $errors[] = 'Enter a section name and select a valid grade level.';
        } elseif ($is_senior_high && !in_array($strand, $valid_strands, true)) {
            $errors[] = 'Select a valid strand (GAS or ASSH) for Grade 11 or Grade 12.';
        } elseif ($capacity < 1 || $capacity > 60) {
            $errors[] = 'Capacity must be between 1 and 60.';
        } else {
            if (!$is_senior_high) {
                $strand = null;
            }

            if (empty($academic_year)) {
                $active_year = getRecord("SELECT sy_name FROM school_years WHERE is_active = 1 ORDER BY sy_id DESC LIMIT 1");
                $academic_year = $active_year['sy_name'] ?? (date('Y') . '-' . (date('Y') + 1));
            }

            $existing_section = getRecord(
                "SELECT section_id FROM sections WHERE section_name = ? AND academic_year = ? LIMIT 1",
                [$section_name, $academic_year]
            );

            if ($existing_section) {
                $errors[] = 'That section already exists for the selected academic year.';
            } else {
                $sql = "INSERT INTO sections (section_name, grade_level, strand, is_senior_high, academic_year, capacity, current_enrollment, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, 0, 1)";
                $section_id = insertRecord($sql, [$section_name, $grade_level, $strand, $is_senior_high, $academic_year, $capacity]);

                if ($section_id !== false) {
                    logActivity($_SESSION['user_id'], 'settings', 'Added section', "Added {$section_name} ({$grade_level})");
                    $_SESSION['success_message'] = 'Section added successfully!';
                    header('Location: index.php?tab=sections');
                    exit;
                }

                $errors[] = 'The section could not be saved. Check that your sections table uses the current database schema.';
            }
        }
    }
}

// General Average range per section (used when online applications are assigned a section).
require_once __DIR__ . '/../../includes/enrollment_helpers_online.php';
onlineEnsureSectionRangeColumns();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_section_range') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token.';
    } else {
        $rid = (int) ($_POST['section_id'] ?? 0);
        $rmin = trim((string) ($_POST['min_average'] ?? ''));
        $rmax = trim((string) ($_POST['max_average'] ?? ''));
        $okNum = function ($v) { return $v === '' || (is_numeric($v) && $v >= 60 && $v <= 100); };
        if (!$okNum($rmin) || !$okNum($rmax)) {
            $_SESSION['error_message'] = 'A General Average range uses numbers from 60 to 100 (leave both blank for no range).';
        } elseif ($rmin !== '' && $rmax !== '' && (float) $rmin > (float) $rmax) {
            $_SESSION['error_message'] = 'The lowest General Average cannot be higher than the highest.';
        } else {
            executeQuery("UPDATE sections SET min_average = ?, max_average = ? WHERE section_id = ?",
                [$rmin === '' ? null : round((float) $rmin, 2), $rmax === '' ? null : round((float) $rmax, 2), $rid]);
            logActivity($_SESSION['user_id'], 'settings', 'Section GA range', "Section #$rid: " . ($rmin === '' && $rmax === '' ? 'no range' : ($rmin ?: '...') . '-' . ($rmax ?: '...')));
            $_SESSION['success_message'] = 'Section General Average range saved.';
        }
    }
    header('Location: index.php?tab=sections');
    exit;
}

// Get sections
$sections = getRecords("SELECT * FROM sections WHERE is_active = 1 ORDER BY grade_level, section_name");

// Section summary stats
$section_total = count($sections);
$section_jhs = count(array_filter($sections, fn($s) => !$s['is_senior_high']));
$section_shs = count(array_filter($sections, fn($s) => $s['is_senior_high']));
$section_enrolled = array_sum(array_column($sections, 'current_enrollment'));

// Get school years
$school_years = getRecords("SELECT * FROM school_years ORDER BY sy_name DESC");

include '../../includes/header.php';
?>

<div class="mb-4">
    <h2 class="mb-1"><i class="fas fa-cog text-primary me-2"></i>System Settings</h2>
    <p class="text-muted mb-0">Configure school details, grading rules, sections/strands, and school years.</p>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo htmlspecialchars(implode(' ', $errors)); ?>
    </div>
<?php endif; ?>

<!-- Tab navigation -->
<ul class="nav nav-tabs mt-2" id="settingsTab" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo !isset($_GET['tab']) || $_GET['tab'] === 'general' ? 'active' : ''; ?>"
            data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab">
            <i class="fas fa-cog me-1"></i> General
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo isset($_GET['tab']) && $_GET['tab'] === 'grading' ? 'active' : ''; ?>"
            data-bs-toggle="tab" data-bs-target="#grading" type="button" role="tab">
            <i class="fas fa-calculator me-1"></i> Grading
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo isset($_GET['tab']) && $_GET['tab'] === 'attendance' ? 'active' : ''; ?>"
            data-bs-toggle="tab" data-bs-target="#attendance" type="button" role="tab">
            <i class="fas fa-clock me-1"></i> Attendance Schedule
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo isset($_GET['tab']) && $_GET['tab'] === 'sections' ? 'active' : ''; ?>"
            data-bs-toggle="tab" data-bs-target="#sections" type="button" role="tab">
            <i class="fas fa-layer-group me-1"></i> Sections / Strands
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo isset($_GET['tab']) && $_GET['tab'] === 'school_years' ? 'active' : ''; ?>"
            data-bs-toggle="tab" data-bs-target="#school_years" type="button" role="tab">
            <i class="fas fa-calendar me-1"></i> School Years
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link" href="<?php echo BASE_URL; ?>modules/settings/backup.php">
            <i class="fas fa-database me-1"></i> Backup &amp; Restore
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link" href="<?php echo BASE_URL; ?>modules/settings/email.php">
            <i class="fas fa-envelope me-1"></i> Email Settings
        </a>
    </li>
</ul>

<div class="tab-content pt-4" id="settingsTabContent">
    <!-- General Settings -->
    <div class="tab-pane fade show <?php echo !isset($_GET['tab']) || $_GET['tab'] === 'general' ? 'active' : ''; ?>"
        id="general" role="tabpanel">
        <div class="card shadow-sm rounded-3">
            <div class="card-header bg-white border-0 pt-3">
                <h5 class="mb-0"><i class="fas fa-cog text-primary me-2"></i>General Settings</h5>
                <p class="text-muted small mb-0 mt-1">Shown on report cards, certificates, and outgoing emails.</p>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="setting_group" value="general">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">School Name</label>
                            <input type="text" name="setting_school_name" class="form-control"
                                value="<?php echo htmlspecialchars($grouped_settings['general']['school_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">School Address</label>
                            <input type="text" name="setting_school_address" class="form-control"
                                value="<?php echo htmlspecialchars($grouped_settings['general']['school_address'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">School Contact</label>
                            <input type="text" name="setting_school_contact" class="form-control"
                                value="<?php echo htmlspecialchars($grouped_settings['general']['school_contact'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">School Email</label>
                            <input type="email" name="setting_school_email" class="form-control"
                                value="<?php echo htmlspecialchars($grouped_settings['general']['school_email'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Grading Settings -->
    <div class="tab-pane fade <?php echo isset($_GET['tab']) && $_GET['tab'] === 'grading' ? 'show active' : ''; ?>"
        id="grading" role="tabpanel">
        <div class="card shadow-sm rounded-3">
            <div class="card-header bg-white border-0 pt-3">
                <h5 class="mb-0"><i class="fas fa-calculator text-primary me-2"></i>Grading Settings (DepEd Three-Term E-Class Record, SY 2026-2027)</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="setting_group" value="grading">

                    <div class="alert alert-info d-flex align-items-start gap-2">
                        <i class="fas fa-info-circle mt-1"></i>
                        <div>
                            <strong>Component weights are not set here.</strong>
                            For Grades 7&ndash;12 they come from each subject's official curriculum category
                            (DO 015, s. 2026) in the central grading engine, so a school-wide override cannot
                            silently replace a prescribed weight. Set a subject's category under
                            <em>Subjects</em>.
                            <div class="mt-2 small text-muted">
                                Core learning areas 20 / 50 / 30 &nbsp;&middot;&nbsp;
                                TLE and MAPEH 20 / 60 / 20 &nbsp;&middot;&nbsp;
                                Examinations = ST1 30% + ST2 30% + Term Examination 40%
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Passing Grade</label>
                            <input type="number" name="setting_grading_pass_mark" class="form-control"
                                value="<?php echo htmlspecialchars($grouped_settings['grading']['grading_pass_mark'] ?? 75); ?>"
                                min="60" max="100">
                            <div class="form-text">DepEd default is 75.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">At-Risk Make-up Credit (%)</label>
                            <input type="number" name="setting_makeup_credit_percent" class="form-control"
                                value="<?php echo htmlspecialchars($grouped_settings['grading']['makeup_credit_percent'] ?? 50); ?>"
                                min="1" max="100" step="1">
                            <div class="form-text">Part of the points a make-up of a missing assessment earns (AI Analytics catch-up quiz / make-up activity). 50 = half points.</div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Attendance Schedule Settings -->
    <div class="tab-pane fade <?php echo isset($_GET['tab']) && $_GET['tab'] === 'attendance' ? 'show active' : ''; ?>"
        id="attendance" role="tabpanel">
        <div class="card shadow-sm rounded-3">
            <div class="card-header bg-white border-0 pt-3">
                <h5 class="mb-0"><i class="fas fa-clock text-primary me-2"></i>Attendance Schedule</h5>
                <p class="text-muted small mb-0 mt-1">
                    Sets this school's actual JHS Morning/Afternoon and SHS class program hours. Teacher My Attendance
                    and the admin attendance monitor both read these values — nothing about class hours is hard-coded
                    in the source code, so update this whenever the school's program changes.
                </p>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="setting_group" value="attendance">

                    <h6 class="fw-bold mb-3" style="color: var(--ink);">JHS — Morning Shift</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label">Start</label>
                            <input type="time" name="setting_jhs_morning_start" class="form-control" value="<?php echo htmlspecialchars($grouped_settings['attendance']['jhs_morning_start'] ?? '07:00'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Break Start</label>
                            <input type="time" name="setting_jhs_morning_break_start" class="form-control" value="<?php echo htmlspecialchars($grouped_settings['attendance']['jhs_morning_break_start'] ?? '09:30'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Break End</label>
                            <input type="time" name="setting_jhs_morning_break_end" class="form-control" value="<?php echo htmlspecialchars($grouped_settings['attendance']['jhs_morning_break_end'] ?? '09:45'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">End</label>
                            <input type="time" name="setting_jhs_morning_end" class="form-control" value="<?php echo htmlspecialchars($grouped_settings['attendance']['jhs_morning_end'] ?? '12:00'); ?>">
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3" style="color: var(--ink);">JHS — Afternoon Shift</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label">Start</label>
                            <input type="time" name="setting_jhs_afternoon_start" class="form-control" value="<?php echo htmlspecialchars($grouped_settings['attendance']['jhs_afternoon_start'] ?? '12:30'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Break Start</label>
                            <input type="time" name="setting_jhs_afternoon_break_start" class="form-control" value="<?php echo htmlspecialchars($grouped_settings['attendance']['jhs_afternoon_break_start'] ?? '15:00'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Break End</label>
                            <input type="time" name="setting_jhs_afternoon_break_end" class="form-control" value="<?php echo htmlspecialchars($grouped_settings['attendance']['jhs_afternoon_break_end'] ?? '15:15'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">End</label>
                            <input type="time" name="setting_jhs_afternoon_end" class="form-control" value="<?php echo htmlspecialchars($grouped_settings['attendance']['jhs_afternoon_end'] ?? '17:30'); ?>">
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3" style="color: var(--ink);">Senior High School</h6>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Start</label>
                            <input type="time" name="setting_shs_start" class="form-control" value="<?php echo htmlspecialchars($grouped_settings['attendance']['shs_start'] ?? '08:00'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Break Start</label>
                            <input type="time" name="setting_shs_break_start" class="form-control" value="<?php echo htmlspecialchars($grouped_settings['attendance']['shs_break_start'] ?? '10:30'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Break End</label>
                            <input type="time" name="setting_shs_break_end" class="form-control" value="<?php echo htmlspecialchars($grouped_settings['attendance']['shs_break_end'] ?? '10:45'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">End</label>
                            <input type="time" name="setting_shs_end" class="form-control" value="<?php echo htmlspecialchars($grouped_settings['attendance']['shs_end'] ?? '14:00'); ?>">
                        </div>
                    </div>

                    <div class="alert alert-info d-flex align-items-start gap-2 mt-4">
                        <i class="fas fa-info-circle mt-1"></i>
                        <div>
                            These times only apply to a teacher once their actual class days/times are set under
                            <strong>Attendance &rarr; Manage Teaching Schedule</strong> (Administrator/Registrar).
                            Assigning a subject alone (Subjects page) does not schedule a day/time by itself.
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="fw-bold mb-1" style="color: var(--ink);">Student Attendance Risk Thresholds</h6>
                    <p class="text-muted small mb-3">
                        Controls when a STUDENT (not a teacher) is flagged with an Attendance Risk in AI Analytics.
                        This is purely an early-warning/intervention signal — it never adds to or subtracts from
                        Written Works, Performance Tasks, Examinations, or the Term Grade.
                    </p>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Warning threshold (%)</label>
                            <input type="number" min="0" max="100" step="1" name="setting_attendance_risk_pct_threshold" class="form-control" value="<?php echo htmlspecialchars($grouped_settings['attendance']['attendance_risk_pct_threshold'] ?? '90'); ?>">
                            <div class="form-text">Flag when attendance % falls below this.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Repeated absences (total)</label>
                            <input type="number" min="1" step="1" name="setting_attendance_risk_absence_threshold" class="form-control" value="<?php echo htmlspecialchars($grouped_settings['attendance']['attendance_risk_absence_threshold'] ?? '3'); ?>">
                            <div class="form-text">Flag at this many total absences this term.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Consecutive absences</label>
                            <input type="number" min="1" step="1" name="setting_attendance_risk_consecutive_absences" class="form-control" value="<?php echo htmlspecialchars($grouped_settings['attendance']['attendance_risk_consecutive_absences'] ?? '3'); ?>">
                            <div class="form-text">Flag on this many Absents in a row.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Frequent lateness (total)</label>
                            <input type="number" min="1" step="1" name="setting_attendance_risk_late_threshold" class="form-control" value="<?php echo htmlspecialchars($grouped_settings['attendance']['attendance_risk_late_threshold'] ?? '5'); ?>">
                            <div class="form-text">Flag at this many total Late records this term.</div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Sections / Strands Management -->
    <div class="tab-pane fade <?php echo isset($_GET['tab']) && $_GET['tab'] === 'sections' ? 'show active' : ''; ?>"
        id="sections" role="tabpanel">

        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card border-0 shadow-sm rounded-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Total Sections</h6>
                                <h2 class="mb-0 fw-bold"><?php echo number_format($section_total); ?></h2>
                            </div>
                            <div class="stats-icon bg-primary bg-opacity-10"><i class="fas fa-layer-group text-primary"></i></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card border-0 shadow-sm rounded-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Junior High</h6>
                                <h2 class="mb-0 fw-bold"><?php echo number_format($section_jhs); ?></h2>
                            </div>
                            <div class="stats-icon bg-secondary bg-opacity-10"><i class="fas fa-school text-secondary"></i></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card border-0 shadow-sm rounded-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Senior High (GAS/ASSH)</h6>
                                <h2 class="mb-0 fw-bold"><?php echo number_format($section_shs); ?></h2>
                            </div>
                            <div class="stats-icon bg-warning bg-opacity-10"><i class="fas fa-graduation-cap text-warning"></i></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card border-0 shadow-sm rounded-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Total Enrolled</h6>
                                <h2 class="mb-0 fw-bold"><?php echo number_format($section_enrolled); ?></h2>
                            </div>
                            <div class="stats-icon bg-success bg-opacity-10"><i class="fas fa-user-graduate text-success"></i></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm rounded-3">
            <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-0"><i class="fas fa-layer-group text-primary me-2"></i>Sections / Strands Management</h5>
                    <p class="text-muted small mb-0 mt-1">Senior High strands are limited to <strong>GAS</strong> and <strong>ASSH</strong>. Deleting a section removes it from the database immediately.</p>
                </div>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSectionModal">
                    <i class="fas fa-plus me-1"></i>Add Section / Strand
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Grade Level</th>
                                <th>Strand (SHS)</th>
                                <th>Type</th>
                                <th style="min-width: 150px;">Enrollment</th>
                                <th style="min-width: 220px;" title="Online applications are placed in the section whose range holds their verified Report Card General Average">General Average range</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($sections && count($sections) > 0): ?>
                                <?php foreach ($sections as $section):
                                    $cap = max(1, (int)$section['capacity']);
                                    $enrolled = (int)$section['current_enrollment'];
                                    $pct = min(100, round(($enrolled / $cap) * 100));
                                    $fill_class = $pct >= 100 ? 'is-full' : ($pct >= 80 ? 'is-near' : '');
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($section['section_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($section['grade_level']); ?></td>
                                        <td>
                                            <?php if ($section['is_senior_high']): ?>
                                                <span
                                                    class="badge bg-info"><?php echo htmlspecialchars(($section['strand'] ?? '') === 'ASSH' ? 'ASSH Cluster' : ($section['strand'] ?? 'N/A')); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span
                                                class="badge bg-<?php echo $section['is_senior_high'] ? 'warning' : 'secondary'; ?>">
                                                <?php echo $section['is_senior_high'] ? 'SHS' : 'JHS'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex justify-content-between small mb-1">
                                                <span class="mono-figure"><?php echo $enrolled; ?> / <?php echo $cap; ?></span>
                                                <span class="text-muted"><?php echo $pct; ?>%</span>
                                            </div>
                                            <div class="capacity-track">
                                                <div class="capacity-fill <?php echo $fill_class; ?>" style="width: <?php echo $pct; ?>%;"></div>
                                            </div>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-flex align-items-center gap-1">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="set_section_range">
                                                <input type="hidden" name="section_id" value="<?php echo (int) $section['section_id']; ?>">
                                                <input type="number" name="min_average" class="form-control form-control-sm" style="width:72px" min="60" max="100" step="0.01" placeholder="from"
                                                       value="<?php echo $section['min_average'] !== null ? htmlspecialchars(rtrim(rtrim((string) $section['min_average'], '0'), '.')) : ''; ?>">
                                                <span class="text-muted">–</span>
                                                <input type="number" name="max_average" class="form-control form-control-sm" style="width:72px" min="60" max="100" step="0.01" placeholder="to"
                                                       value="<?php echo $section['max_average'] !== null ? htmlspecialchars(rtrim(rtrim((string) $section['max_average'], '0'), '.')) : ''; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary" title="Save range"><i class="fas fa-check"></i></button>
                                            </form>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $section['is_active'] ? 'success' : 'danger'; ?>">
                                                <?php echo $section['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button class="btn btn-outline-danger" title="Delete section/strand"
                                                    onclick="confirmDeleteSection(<?php echo $section['section_id']; ?>, '<?php echo htmlspecialchars(addslashes($section['section_name'])); ?>', <?php echo $enrolled; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="fas fa-layer-group fa-2x d-block mb-2"></i>
                                        No sections or strands found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- School Years Management -->
    <div class="tab-pane fade <?php echo isset($_GET['tab']) && $_GET['tab'] === 'school_years' ? 'show active' : ''; ?>"
        id="school_years" role="tabpanel">
        <div class="card shadow-sm rounded-3">
            <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-calendar text-primary me-2"></i>School Years Management</h5>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSYModal">
                    <i class="fas fa-plus me-1"></i>Add School Year
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>School Year</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($school_years && count($school_years) > 0): ?>
                                <?php foreach ($school_years as $sy): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($sy['sy_name']); ?></strong></td>
                                        <td><?php echo date('M d, Y', strtotime($sy['start_date'])); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($sy['end_date'])); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $sy['is_active'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $sy['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <?php if (!$sy['is_active']): ?>
                                                    <button class="btn btn-outline-success"
                                                        onclick="activateSY(<?php echo $sy['sy_id']; ?>)">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-outline-danger"
                                                    onclick="confirmDeleteSY(<?php echo $sy['sy_id']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">
                                        <i class="fas fa-calendar fa-2x d-block mb-2"></i>
                                        No school years found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Section / Strand Modal -->
<div class="modal fade" id="addSectionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus text-primary me-2"></i>Add Section / Strand</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?tab=sections">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add_section">

                    <div class="mb-3">
                        <label class="form-label">Section/Strand Name</label>
                        <input type="text" name="section_name" class="form-control" placeholder="e.g., 7-A or 11-GAS A"
                            required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Grade Level</label>
                        <select name="grade_level" class="form-select" required id="gradeLevelSelect">
                            <option value="">Select Grade Level</option>
                            <?php
                            $grades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
                            foreach ($grades as $grade):
                                ?>
                                <option value="<?php echo $grade; ?>"><?php echo $grade; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="strandField" style="display: none;">
                        <label class="form-label">Strand (for Grade 11-12)</label>
                        <select name="strand" class="form-select">
                            <option value="">Select Strand</option>
                            <?php foreach ($valid_strands as $strand_code): ?>
                                <option value="<?php echo $strand_code; ?>"><?php echo htmlspecialchars($strand_labels[$strand_code]); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Only GAS and ASSH are offered at this school.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Academic Year</label>
                        <input type="text" name="academic_year" class="form-control" placeholder="e.g., 2025-2026"
                            value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Capacity</label>
                        <input type="number" name="capacity" class="form-control" value="45" min="1" max="60">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Section / Strand</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add School Year Modal -->
<div class="modal fade" id="addSYModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-calendar-plus text-primary me-2"></i>Add School Year</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="school_years.php?action=add_sy">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add_sy">

                    <div class="mb-3">
                        <label class="form-label">School Year</label>
                        <input type="text" name="sy_name" class="form-control" placeholder="e.g., 2025-2026" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" required>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_active" class="form-check-input" value="1" checked>
                        <label class="form-check-label">Set as active school year</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add School Year</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Show/hide and require the strand field based on grade level.
    const gradeLevelSelect = document.getElementById('gradeLevelSelect');
    const strandField = document.getElementById('strandField');
    const strandSelect = strandField.querySelector('select[name="strand"]');

    function syncStrandField() {
        const isSeniorHigh = gradeLevelSelect.value === 'Grade 11' || gradeLevelSelect.value === 'Grade 12';
        strandField.style.display = isSeniorHigh ? 'block' : 'none';
        strandSelect.required = isSeniorHigh;
        if (!isSeniorHigh) strandSelect.value = '';
    }

    gradeLevelSelect.addEventListener('change', syncStrandField);
    syncStrandField();

    <?php if (!empty($errors) && ($_POST['action'] ?? '') === 'add_section'): ?>
    bootstrap.Modal.getOrCreateInstance(document.getElementById('addSectionModal')).show();
    <?php endif; ?>

    function confirmDeleteSection(sectionId, sectionName, enrolled) {
        if (enrolled > 0) {
            alert('"' + sectionName + '" still has ' + enrolled + ' enrolled student(s). Move or unenroll them before deleting this section/strand.');
            return;
        }
        if (confirm('Delete "' + sectionName + '"? This permanently removes it from the database.')) {
            window.location.href = '?tab=sections&action=delete_section&id=' + sectionId;
        }
    }

    function confirmDeleteSY(syId) {
        if (confirm('Are you sure you want to delete this school year?')) {
            window.location.href = 'school_years.php?action=delete&id=' + syId;
        }
    }

    function activateSY(syId) {
        if (confirm('Set this as the active school year? This will deactivate all others.')) {
            window.location.href = 'school_years.php?action=activate&id=' + syId;
        }
    }
</script>

<?php include '../../includes/footer.php'; ?>