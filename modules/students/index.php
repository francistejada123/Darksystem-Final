<?php
// modules/students/index.php
// Student management - Grade Level → Sections → Students

require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/attendance_qr_helpers.php'; // attQrLrnLink()
require_once '../../includes/grading_helpers.php'; // gh_* (end-of-year Completed / Promoted status)

// ASSH Cluster = the school's Arts, Social Sciences and Humanities cluster. Older
// records (sections created before the cluster was renamed) still carry the
// retired label 'HUMSS'. Treat both as the same cluster so those sections and
// their students stay visible -- nothing in the database is rewritten.
if (!function_exists('normalizeStrandKey')) {
    function normalizeStrandKey($strand) {
        $clean = trim((string)$strand);
        if (in_array(strtoupper($clean), ['ASSH', 'HUMSS', 'ASSH CLUSTER'], true)) {
            return 'ASSH';
        }
        return $clean;
    }
}

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Registrar'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Student Management';
$current_page = 'students';
$base_url = BASE_URL;

// SY 2026-2027 offers only these two Senior High strands. Validating
// $selected_strand against this allow-list (rather than accepting whatever
// is on file) keeps a retired label like 'HUMSS' -- still present on
// prior-year sections that are intentionally left untouched -- from ever
// being selectable here, including via a stale bookmarked URL.
$active_shs_strands = ['GAS', 'ASSH'];

$selected_grade = isset($_GET['grade']) ? sanitize($_GET['grade']) : '';
$selected_strand_raw = isset($_GET['strand']) ? sanitize($_GET['strand']) : '';
$selected_strand = in_array($selected_strand_raw, $active_shs_strands, true) ? $selected_strand_raw : '';
$selected_section = isset($_GET['section']) ? intval($_GET['section']) : 0;

// Get active school year
$active_sy = getRecord("SELECT sy_id, sy_name FROM school_years WHERE is_active = 1");
$school_year_id = $active_sy ? $active_sy['sy_id'] : 0;
syncSectionEnrollmentCounts($school_year_id); // section cards show the students actually enrolled

// End-of-school-year status overlay (Completed / Promoted). The table is
// auto-created if missing, and the sweep catches up any section whose Term 3
// was already Completed. enrollments / grades are never modified.
$year_promoted_label = gh_yearStatusPromotedLabel();
gh_ensureStudentYearStatusTable();
if ($school_year_id) { gh_sweepYearStatuses((int)$school_year_id); }

// Get all sections grouped by grade level
$all_sections = getRecords("
    SELECT s.section_id, s.section_name, s.grade_level, s.strand, s.capacity, s.current_enrollment,
           CONCAT(adv.last_name, ', ', adv.first_name) AS adviser_name
    FROM sections s
    LEFT JOIN users adv ON s.adviser_id = adv.user_id
    WHERE s.is_active = 1 
    ORDER BY s.grade_level, s.section_name
");

// Legacy 'HUMSS' sections belong to the ASSH Cluster (see normalizeStrandKey).
foreach ($all_sections as &$sec) {
    $sec['strand'] = normalizeStrandKey($sec['strand']);
}
unset($sec);

// Group sections by grade level
$sections_by_grade = [];
foreach ($all_sections as $sec) {
    $sections_by_grade[$sec['grade_level']][] = $sec;
}

// Check if selected grade is SHS
$is_shs_grade = ($selected_grade === 'Grade 11' || $selected_grade === 'Grade 12');

// Both strands are always offered for a Senior High grade, even before any
// section has been created under one of them yet (e.g. Grade 12 ASSH
// Cluster with no section on file yet) -- restricted to the active
// allow-list above so a retired strand (e.g. HUMSS) never appears as a
// selectable option even though its old sections are still on file. This
// is NOT derived from which sections happen to already exist, precisely
// so a still-empty strand isn't hidden.
$available_strands = ($is_shs_grade && $selected_grade) ? $active_shs_strands : [];

// Get sections based on selected grade and strand
$filtered_sections = [];
if ($selected_grade) {
    if ($is_shs_grade && $selected_strand) {
        // SHS: filter by strand
        foreach (($sections_by_grade[$selected_grade] ?? []) as $sec) {
            if ($sec['strand'] === $selected_strand) {
                $filtered_sections[] = $sec;
            }
        }
    } elseif (!$is_shs_grade) {
        // JHS: show all sections
        $filtered_sections = $sections_by_grade[$selected_grade] ?? [];
    } else {
        // SHS but no strand selected yet - show all sections (or empty)
        $filtered_sections = [];
    }
}

// Get students for selected section - Only show ACTIVE students (is_active = 1)
$section_students = [];
$section_info = null;

if ($selected_section > 0) {
    $section_info = getRecord("
        SELECT sec.*, CONCAT(adv.last_name, ', ', adv.first_name) AS adviser_name
        FROM sections sec
        LEFT JOIN users adv ON sec.adviser_id = adv.user_id
        WHERE sec.section_id = ?
    ", [$selected_section]);
    if ($section_info) {
        $section_students = getRecords("
            SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.middle_name,
                   s.grade_level, s.strand, s.enrollment_status, s.email, s.contact_number,
                   e.enrollment_date
            FROM students s
            JOIN enrollments e ON s.student_id = e.student_id
            WHERE e.section_id = ? 
            AND e.school_year_id = ?
            AND e.enrollment_status = 'Approved'
            AND s.is_active = 1
            ORDER BY s.last_name, s.first_name
        ", [$selected_section, $school_year_id]);
    }
}


// ============================================================
// OVERVIEW DATA (shown when no grade is selected yet)
// ============================================================
$search_q = isset($_GET['q']) ? trim(sanitize($_GET['q'])) : '';
// Real, working filters for the search/filter panel — NOT decorative. Each
// one narrows the same $search_results query below with a genuine WHERE
// clause; none of them replace the existing Grade → Section drill-down
// above, which stays exactly as it was.
$filter_grade = isset($_GET['filter_grade']) ? sanitize($_GET['filter_grade']) : '';
$filter_section_id = isset($_GET['filter_section']) ? intval($_GET['filter_section']) : 0;
$filter_status = isset($_GET['filter_status']) ? sanitize($_GET['filter_status']) : '';
$overview = [];
$recent_enrollments = [];
$search_results = [];
$grade_totals = [];

if (!$selected_grade) {
    $overview['total_students'] = (int)(getRecord("SELECT COUNT(*) AS c FROM students WHERE is_active = 1")['c'] ?? 0);
    $overview['enrolled'] = (int)(getRecord("SELECT COUNT(*) AS c FROM enrollments WHERE school_year_id = ? AND enrollment_status = 'Approved'", [$school_year_id])['c'] ?? 0);
    $overview['pending'] = (int)(getRecord("SELECT COUNT(*) AS c FROM enrollments WHERE school_year_id = ? AND enrollment_status = 'Pending'", [$school_year_id])['c'] ?? 0);
    $overview['sections'] = count($all_sections);
    $overview['capacity'] = 0;
    foreach ($all_sections as $sec) { $overview['capacity'] += (int)$sec['capacity']; }

    // "With Issues" = real data-integrity gap: an Approved enrollment that
    // never got assigned to a section. Not a fabricated metric — it's a
    // straight COUNT the registrar can act on.
    $overview['with_issues'] = (int)(getRecord("
        SELECT COUNT(*) AS c
        FROM enrollments e
        JOIN students s ON s.student_id = e.student_id
        WHERE e.school_year_id = ? AND e.enrollment_status = 'Approved'
          AND e.section_id IS NULL AND s.is_active = 1
    ", [$school_year_id])['c'] ?? 0);

    // "Graduating" = real Grade 12 approved enrollees for the active SY.
    $overview['graduating'] = (int)(getRecord("
        SELECT COUNT(*) AS c
        FROM enrollments e
        JOIN students s ON s.student_id = e.student_id
        WHERE e.school_year_id = ? AND e.enrollment_status = 'Approved'
          AND s.grade_level = 'Grade 12' AND s.is_active = 1
    ", [$school_year_id])['c'] ?? 0);

    $grade_totals = getRecords("
        SELECT s.grade_level, COUNT(*) AS total
        FROM enrollments e
        JOIN students s ON s.student_id = e.student_id
        WHERE e.school_year_id = ? AND e.enrollment_status = 'Approved' AND s.is_active = 1
        GROUP BY s.grade_level
    ", [$school_year_id]);
    $grade_totals_map = [];
    foreach ($grade_totals as $g) { $grade_totals_map[$g['grade_level']] = (int)$g['total']; }

    $recent_enrollments = getRecords("
        SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.grade_level,
               e.enrollment_status, e.enrollment_date, sec.section_name,
               ys.status AS year_status, ys.general_average AS year_average
        FROM enrollments e
        JOIN students s ON s.student_id = e.student_id
        LEFT JOIN sections sec ON sec.section_id = e.section_id
        LEFT JOIN student_year_status ys ON ys.student_id = e.student_id
             AND ys.school_year_id = e.school_year_id AND ys.grade_level = e.grade_level
             AND ys.status = " . $db->quote($year_promoted_label) . "
        WHERE e.school_year_id = ?
        ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
        LIMIT 8
    ", [$school_year_id]);

    $has_search_filters = ($search_q !== '' || $filter_grade !== '' || $filter_section_id > 0 || $filter_status !== '');
    if ($has_search_filters) {
        $where = ['s.is_active = 1'];
        $params = [$school_year_id];
        if ($search_q !== '') {
            $like = '%' . $search_q . '%';
            $where[] = "(s.lrn LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name,' ',s.last_name) LIKE ?)";
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($filter_grade !== '') {
            $where[] = 's.grade_level = ?';
            $params[] = $filter_grade;
        }
        if ($filter_section_id > 0) {
            $where[] = 'sec.section_id = ?';
            $params[] = $filter_section_id;
        }
        if ($filter_status === $year_promoted_label) {
            $where[] = "e.enrollment_status = 'Approved' AND ys.status_id IS NOT NULL";
        } elseif ($filter_status !== '') {
            $where[] = 'e.enrollment_status = ?';
            $params[] = $filter_status;
        }
        $search_results = getRecords("
            SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.middle_name,
                   s.grade_level, s.strand, s.enrollment_status, sec.section_name,
                   e.enrollment_status AS enr_status,
                   ys.status AS year_status, ys.general_average AS year_average
            FROM students s
            LEFT JOIN enrollments e ON e.student_id = s.student_id AND e.school_year_id = ?
            LEFT JOIN sections sec ON sec.section_id = e.section_id
            LEFT JOIN student_year_status ys ON ys.student_id = e.student_id
                 AND ys.school_year_id = e.school_year_id AND ys.grade_level = e.grade_level
                 AND ys.status = " . $db->quote($year_promoted_label) . "
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.last_name, s.first_name
            LIMIT 50
        ", $params);
    }
}
if (!isset($grade_totals_map)) { $grade_totals_map = []; }
if (!isset($has_search_filters)) { $has_search_filters = false; }

include '../../includes/header.php';
?>

<style>
    /* Grade/section/strand selector cards now come from the shared global
       .grade-card / .section-card / .strand-card / .grade-selected-pill
       components in static/css/style.css (same component used by Teacher
       Management, Subject Management and Class Records), so this page no
       longer redefines its own — that was the source of the radius/shadow
       drift between modules. Each card still gets its colour from the
       inline --accent/--accent-soft custom properties set in the PHP loop
       below, which the shared component reads. */
    /* Table text across the students page, matched to the enrollment and
       teachers pages so the whole system reads at one size. The padding
       selector mirrors Bootstrap's own `.table > :not(caption) > * > *`
       so table-sm and friends cannot quietly shrink a table back down.
       Scoped under .students-page so it never leaks onto other pages. */
    .students-page .table > :not(caption) > * > * {
        padding: 14px 16px;
    }
    .students-page .table thead th {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        font-weight: 600;
        vertical-align: middle;
    }
    .students-page .table tbody td {
        font-size: 15px;
        vertical-align: middle;
    }
    .students-page .card-header h5 {
        font-size: 19px;
    }
    /* Status badges in the student tables — matched to the enrollment
       pages so a Pending/Approved chip is the same size system-wide.
       Scoped to table cells so the small counter badges next to the
       section headings keep their current size. */
    .students-page td .badge {
        padding: 5px 14px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
    }

    /* ============================================================
       PAGE HEADER — icon box + title/subtitle + illustration
       ============================================================ */
    .students-page .sp-header {
        background: var(--surface);
        border: 1px solid var(--border, #E5E7EB);
        border-radius: 20px;
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(16,24,40,0.06));
        padding: 26px 28px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 20px;
    }
    .students-page .sp-header-main {
        display: flex;
        align-items: flex-start;
        gap: 16px;
    }
    .students-page .sp-header-icon {
        flex: 0 0 auto;
        width: 54px;
        height: 54px;
        border-radius: 16px;
        background: var(--primary-soft);
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
    }
    .students-page .sp-header h2 {
        font-size: 24px;
        font-weight: 700;
        color: var(--ink);
        margin: 4px 0 4px 0;
    }
    .students-page .sp-header p {
        font-size: 14px;
        color: var(--text-muted);
        margin: 0;
        max-width: 480px;
    }
    .students-page .sp-illustration {
        flex: 0 0 auto;
        width: 150px;
        height: 110px;
    }
    @media (max-width: 991.98px) {
        .students-page .sp-illustration { display: none; }
    }
    @media (max-width: 575.98px) {
        .students-page .sp-header { flex-direction: column; align-items: stretch; }
    }

    /* ============================================================
       STAT CARDS (5-up)
       ============================================================ */
    .students-page .sp-stat-card {
        background: var(--surface);
        border: 1px solid var(--border, #E5E7EB);
        border-radius: 16px;
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(16,24,40,0.06));
        padding: 18px 18px;
        height: 100%;
        transition: box-shadow 0.2s ease, transform 0.2s ease;
    }
    .students-page .sp-stat-card:hover {
        box-shadow: var(--shadow-md, 0 6px 18px rgba(16,24,40,0.10));
        transform: translateY(-2px);
    }
    .students-page .sp-stat-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 17px;
        margin-bottom: 12px;
    }
    .students-page .sp-stat-label {
        font-size: 12.5px;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        font-weight: 600;
        margin-bottom: 2px;
    }
    .students-page .sp-stat-value {
        font-size: 26px;
        font-weight: 700;
        color: var(--ink);
        line-height: 1.2;
    }
    .students-page .sp-stat-note {
        font-size: 12px;
        color: var(--text-muted-2);
        margin-top: 2px;
    }

    /* ============================================================
       FILTER / SEARCH PANEL
       ============================================================ */
    .students-page .sp-filter-card {
        background: var(--surface);
        border: 1px solid var(--border, #E5E7EB);
        border-radius: 16px;
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(16,24,40,0.06));
        padding: 20px 22px;
        margin-bottom: 20px;
    }
    .students-page .sp-filter-label {
        font-size: 12px;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin-bottom: 6px;
        display: block;
    }
    .students-page .sp-btn-gradient {
        background: var(--primary);
        border: 1px solid var(--primary);
        color: #fff;
        font-weight: 600;
        border-radius: 10px;
        padding: 10px 20px;
        white-space: nowrap;
        box-shadow: 0 1px 2px rgba(16,24,40,.04);
    }
    .students-page .sp-btn-gradient:hover {
        color: #fff;
        background: #4A4EDB;
        border-color: #4A4EDB;
    }

    /* ============================================================
       QUICK ACTIONS
       ============================================================ */
    .students-page .sp-qa-btn {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
        border: none;
        border-radius: 14px;
        padding: 14px 16px;
        font-size: 13.5px;
        font-weight: 600;
        text-align: left;
        text-decoration: none;
        width: 100%;
        height: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .students-page .sp-qa-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(16,24,40,0.12);
    }
    .students-page .sp-qa-btn i {
        font-size: 18px;
    }
    .students-page .sp-qa-blue   { background: #EAF1FE; color: #1957C2; }
    .students-page .sp-qa-green  { background: #E9F7EF; color: #1E7B45; }
    .students-page .sp-qa-purple { background: #F1EBFE; color: #6A3FC2; }
    .students-page .sp-qa-red    { background: #FDECEF; color: #C23A57; }
</style>

<div class="students-page">

<div class="sp-header">
    <div class="sp-header-main">
        <div class="sp-header-icon"><i class="fas fa-user-graduate"></i></div>
        <div>
            <h2>Student Management</h2>
            <p>Manage and monitor student records, enrollment, and academic progress.</p>
            <?php if (hasAnyRole(['Administrator', 'Registrar'])): ?>
            <div class="d-flex gap-2 mt-3">
                <a href="<?php echo BASE_URL; ?>modules/students/deleted.php" class="btn btn-outline-danger btn-sm">
                    <i class="fas fa-trash-can me-2"></i>Recycle Bin
                </a>
                <a href="<?php echo BASE_URL; ?>modules/students/bulk_import.php" class="btn btn-outline-dark btn-sm">
                    <i class="fas fa-file-import me-2"></i>Bulk Import
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- Hand-built illustration (no external asset) — two students in school
         uniform with books, a simple school-building silhouette behind them. -->
    <div class="sp-illustration" aria-hidden="true">
        <svg viewBox="0 0 200 150" width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
            <!-- school building silhouette -->
            <rect x="10" y="55" width="180" height="60" rx="4" fill="#EEF2FB"/>
            <polygon points="10,55 100,25 190,55" fill="#E3E9FA"/>
            <rect x="92" y="35" width="16" height="16" fill="#D7E0F7"/>
            <rect x="30" y="70" width="14" height="16" fill="#D7E0F7"/>
            <rect x="56" y="70" width="14" height="16" fill="#D7E0F7"/>
            <rect x="130" y="70" width="14" height="16" fill="#D7E0F7"/>
            <rect x="156" y="70" width="14" height="16" fill="#D7E0F7"/>
            <rect x="92" y="90" width="16" height="25" fill="#C9D5F2"/>
            <!-- student 1 -->
            <circle cx="70" cy="90" r="11" fill="#F3C9A0"/>
            <path d="M70,79 a11,11 0 0 1 11,11 l-22,0 a11,11 0 0 1 11,-11 z" fill="#2B3A63"/>
            <rect x="55" y="101" width="30" height="34" rx="8" fill="#356EA8"/>
            <rect x="60" y="105" width="20" height="10" rx="2" fill="#fff" opacity="0.85"/>
            <rect x="47" y="112" width="10" height="18" rx="4" fill="#F3C9A0"/>
            <!-- student 2 -->
            <circle cx="128" cy="86" r="11" fill="#C97D5A"/>
            <path d="M128,75 a11,11 0 0 1 11,11 l-22,0 a11,11 0 0 1 11,-11 z" fill="#1E2A4A"/>
            <rect x="113" y="97" width="30" height="38" rx="8" fill="#6A3FC2"/>
            <rect x="118" y="101" width="20" height="10" rx="2" fill="#fff" opacity="0.85"/>
            <rect x="140" y="108" width="10" height="20" rx="4" fill="#C97D5A"/>
            <rect x="137" y="103" width="16" height="12" rx="2" fill="#F5B84E"/>
        </svg>
    </div>
</div>

<!-- ============================================================ -->
<!-- STEP 1: GRADE LEVEL CARDS -->
<!-- ============================================================ -->
<div class="row g-3 mb-4">
    <?php 
    // One place that defines the colour of each grade level. The grade
    // cards, the "Students per Grade Level" bars and the hover states all
    // read from this, so a grade is the same colour everywhere on the page.
    $grade_palette = [
        'Grade 7'  => ['accent' => '#2E7D67', 'soft' => '#F1F8F5'],
        'Grade 8'  => ['accent' => '#356EA8', 'soft' => '#F2F6FA'],
        'Grade 9'  => ['accent' => '#A06A1B', 'soft' => '#FBF7EE'],
        'Grade 10' => ['accent' => '#5B5EA6', 'soft' => '#F4F3FA'],
        'Grade 11' => ['accent' => '#994C52', 'soft' => '#FAF2F3'],
        'Grade 12' => ['accent' => '#287A78', 'soft' => '#F0F8F7'],
    ];

    $grade_levels = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
    foreach ($grade_levels as $grade): 
        $grade_sections = $sections_by_grade[$grade] ?? [];
        $has_sections = count($grade_sections) > 0;
        $is_active = ($selected_grade === $grade);
        $color = $grade_palette[$grade]['accent'] ?? '#667085';
        $soft  = $grade_palette[$grade]['soft'] ?? '#f8f9fb';
        $display = str_replace('Grade ', '', $grade);
    ?>
        <div class="col-md-4 col-lg-2">
            <div class="card grade-card <?php echo $is_active ? 'active' : 'border-0 shadow-sm'; ?>"
                 style="--accent: <?php echo $color; ?>; --accent-soft: <?php echo $soft; ?>;"
                 role="button" tabindex="0"
                 aria-label="Show <?php echo htmlspecialchars($grade); ?>"
                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
                 onclick="window.location.href='?grade=<?php echo urlencode($grade); ?>&strand=&section=0'">
                <div class="card-body text-center">
                    <div class="grade-icon">
                        <span class="grade-num"><?php echo $display; ?></span>
                    </div>
                    <div class="grade-label"><?php echo $grade; ?></div>
                    <div class="section-count">
                        <?php echo $has_sections ? count($grade_sections) . ' sections' : 'No sections'; ?>
                    </div>
                    <?php if ($is_active): ?>
                        <span class="grade-selected-pill mt-2">Selected</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ============================================================ -->
<!-- OVERVIEW PANEL (fills the page when no grade is selected) -->
<!-- ============================================================ -->
<?php if (!$selected_grade): ?>
<div class="row row-cols-2 row-cols-md-3 row-cols-lg-5 g-3 mb-4">
    <div class="col">
        <div class="sp-stat-card">
            <div class="sp-stat-icon" style="background:#EAF1FE;color:#1957C2;"><i class="fas fa-user-graduate"></i></div>
            <div class="sp-stat-label">Active Students</div>
            <div class="sp-stat-value"><?php echo $overview['total_students']; ?></div>
        </div>
    </div>
    <div class="col">
        <div class="sp-stat-card">
            <div class="sp-stat-icon" style="background:#E9F7EF;color:#1E7B45;"><i class="fas fa-check-circle"></i></div>
            <div class="sp-stat-label">Enrolled (<?php echo $active_sy ? htmlspecialchars($active_sy['sy_name']) : 'No SY'; ?>)</div>
            <div class="sp-stat-value"><?php echo $overview['enrolled']; ?></div>
        </div>
    </div>
    <div class="col">
        <div class="sp-stat-card">
            <div class="sp-stat-icon" style="background:#FFF6E5;color:#B7791F;"><i class="fas fa-hourglass-half"></i></div>
            <div class="sp-stat-label">Pending Approval</div>
            <div class="sp-stat-value"><?php echo $overview['pending']; ?></div>
        </div>
    </div>
    <div class="col">
        <div class="sp-stat-card">
            <div class="sp-stat-icon" style="background:#FDECEF;color:#C23A57;"><i class="fas fa-triangle-exclamation"></i></div>
            <div class="sp-stat-label">With Issues</div>
            <div class="sp-stat-value"><?php echo $overview['with_issues']; ?></div>
            <div class="sp-stat-note">Approved, no section yet</div>
        </div>
    </div>
    <div class="col">
        <div class="sp-stat-card">
            <div class="sp-stat-icon" style="background:#F1EBFE;color:#6A3FC2;"><i class="fas fa-graduation-cap"></i></div>
            <div class="sp-stat-label">Graduating (Grade 12)</div>
            <div class="sp-stat-value"><?php echo $overview['graduating']; ?></div>
        </div>
    </div>
</div>

<div class="sp-filter-card">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-md-3 col-6">
            <label class="sp-filter-label">Grade Level</label>
            <select name="filter_grade" class="form-select">
                <option value="">All Grades</option>
                <?php foreach ($grade_levels as $g): ?>
                    <option value="<?php echo htmlspecialchars($g); ?>" <?php echo $filter_grade === $g ? 'selected' : ''; ?>><?php echo htmlspecialchars($g); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 col-6">
            <label class="sp-filter-label">Section</label>
            <select name="filter_section" class="form-select">
                <option value="0">All Sections</option>
                <?php foreach ($all_sections as $sec): ?>
                    <option value="<?php echo (int)$sec['section_id']; ?>" <?php echo $filter_section_id === (int)$sec['section_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($sec['section_name'] . ' (' . $sec['grade_level'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="sp-filter-label">Status</label>
            <select name="filter_status" class="form-select">
                <option value="">All Status</option>
                <?php foreach (['Pending', 'Approved', $year_promoted_label, 'Rejected', 'Transferred', 'Graduated'] as $stopt): ?>
                    <option value="<?php echo $stopt; ?>" <?php echo $filter_status === $stopt ? 'selected' : ''; ?>><?php echo $stopt; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 col-6">
            <label class="sp-filter-label">Search</label>
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                <input type="text" name="q" class="form-control border-start-0"
                       value="<?php echo htmlspecialchars($search_q); ?>"
                       placeholder="Search students by name, LRN, or grade...">
                <button type="submit" class="btn sp-btn-gradient"><i class="fas fa-search me-1"></i> Search</button>
            </div>
        </div>
        <?php if ($has_search_filters): ?>
        <div class="col-12 d-flex gap-2">
            <a href="?" class="btn btn-outline-secondary">Clear</a>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php if ($has_search_filters): ?>
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white border-0 pt-3">
        <h5 class="mb-0"><i class="fas fa-list text-primary me-2"></i>Search Results
            <span class="badge bg-secondary ms-2"><?php echo count($search_results); ?></span></h5>
    </div>
    <div class="card-body pt-0">
        <?php if (empty($search_results)): ?>
            <div class="text-center text-muted py-4">
                <i class="fas fa-user-slash fa-2x mb-2 d-block"></i>
                <?php if ($search_q !== ''): ?>
                    No student matched "<?php echo htmlspecialchars($search_q); ?>".
                <?php else: ?>
                    No student matched the selected filters.
                <?php endif; ?>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="text-uppercase text-muted">
                    <tr><th>LRN</th><th>Student Name</th><th>Grade</th><th>Section</th><th>Status</th><th class="text-end">Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($search_results as $st): ?>
                    <tr>
                        <td class="text-muted"><?php echo attQrLrnLink($st['lrn']); ?></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($st['last_name'] . ', ' . $st['first_name'] . ' ' . ($st['middle_name'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars($st['grade_level']); ?><?php echo !empty($st['strand']) ? ' - ' . htmlspecialchars(normalizeStrandKey($st['strand']) === 'ASSH' ? 'ASSH Cluster' : $st['strand']) : ''; ?></td>
                        <td><?php echo $st['section_name'] ? htmlspecialchars($st['section_name']) : '<span class="text-muted">Unassigned</span>'; ?></td>
                        <td>
                            <?php if (($st['enr_status'] ?? '') === 'Approved' && !empty($st['year_status'])): ?>
                                <span class="badge bg-success" title="<?php echo $st['year_average'] !== null ? 'General Average ' . htmlspecialchars(number_format((float)$st['year_average'], 2)) : 'Completed all 3 terms'; ?>"><i class="fas fa-graduation-cap me-1"></i><?php echo htmlspecialchars($st['year_status']); ?></span>
                            <?php else: ?>
                                <span class="badge bg-<?php echo $st['enrollment_status'] === 'Enrolled' || $st['enrollment_status'] === 'Approved' ? 'success' : 'warning'; ?>"><?php echo htmlspecialchars($st['enrollment_status']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="edit.php?id=<?php echo (int)$st['student_id']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-edit"></i>
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
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-clock text-primary me-2"></i>Recent Enrollment Activity</h5>
            </div>
            <div class="card-body pt-0">
                <?php if (empty($recent_enrollments)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                        Wala pang enrollment record para sa active school year.
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="text-uppercase text-muted">
                            <tr><th>Student</th><th>Grade</th><th>Section</th><th>Status</th><th>Date Enrolled</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent_enrollments as $r): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($r['last_name'] . ', ' . $r['first_name']); ?></td>
                                <td><?php echo htmlspecialchars($r['grade_level']); ?></td>
                                <td><?php echo $r['section_name'] ? htmlspecialchars($r['section_name']) : '<span class="text-muted">-</span>'; ?></td>
                                <td>
                                    <?php $st = $r['enrollment_status']; ?>
                                    <?php if ($st === 'Approved' && !empty($r['year_status'])): ?>
                                        <span class="badge bg-success" title="<?php echo $r['year_average'] !== null ? 'General Average ' . htmlspecialchars(number_format((float)$r['year_average'], 2)) : 'Completed all 3 terms'; ?>"><i class="fas fa-graduation-cap me-1"></i><?php echo htmlspecialchars($r['year_status']); ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-<?php echo $st === 'Approved' ? 'success' : ($st === 'Pending' ? 'warning' : 'secondary'); ?>"><?php echo htmlspecialchars($st); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted" style="white-space:nowrap;"><?php echo $r['enrollment_date'] ? date('M d, Y', strtotime($r['enrollment_date'])) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-white border-0 text-center pb-3">
                <a href="<?php echo BASE_URL; ?>modules/enrollment/index.php" class="text-decoration-none fw-semibold" style="font-size:13.5px;">
                    View All Enrollments <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>
    <div class="col-lg-5 d-flex flex-column gap-3">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white border-0 pt-3">
                <h5 class="mb-0"><i class="fas fa-chart-simple text-primary me-2"></i>Student Distribution by Grade Level</h5>
            </div>
            <div class="card-body pt-0">
                <?php
                $max_total = 1;
                foreach ($grade_levels_preview = ['Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12'] as $g) {
                    $max_total = max($max_total, $grade_totals_map[$g] ?? 0);
                }
                foreach ($grade_levels_preview as $g):
                    $val = $grade_totals_map[$g] ?? 0;
                    $pct = $max_total > 0 ? round(($val / $max_total) * 100) : 0;
                    // Same palette as the grade cards above, so a bar and its
                    // card are recognisably the same grade.
                    $bar_color = $grade_palette[$g]['accent'] ?? '#356EA8';
                ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1" style="font-size:14px;">
                            <span class="fw-semibold"><?php echo $g; ?></span>
                            <span class="text-muted"><?php echo $val; ?> student<?php echo $val == 1 ? '' : 's'; ?></span>
                        </div>
                        <div class="progress" style="height:10px;border-radius:5px;">
                            <div class="progress-bar" role="progressbar"
                                 style="width: <?php echo $pct; ?>%; background: <?php echo $bar_color; ?>;"
                                 aria-valuenow="<?php echo $val; ?>" aria-valuemin="0" aria-valuemax="<?php echo $max_total; ?>"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
            </div>
        </div>
        <?php if (hasAnyRole(['Administrator', 'Registrar'])): ?>
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white border-0 pt-3">
                <h5 class="mb-0"><i class="fas fa-bolt text-primary me-2"></i>Quick Actions</h5>
            </div>
            <div class="card-body pt-0">
                <div class="row g-2">
                    <div class="col-6">
                        <a href="<?php echo BASE_URL; ?>modules/enrollment/new.php" class="sp-qa-btn sp-qa-blue">
                            <i class="fas fa-user-plus"></i> Add New Student
                        </a>
                    </div>
                    <div class="col-6">
                        <a href="<?php echo BASE_URL; ?>modules/students/bulk_import.php" class="sp-qa-btn sp-qa-green">
                            <i class="fas fa-file-import"></i> Import Students
                        </a>
                    </div>
                    <div class="col-6">
                        <a href="<?php echo BASE_URL; ?>modules/settings/index.php" class="sp-qa-btn sp-qa-purple">
                            <i class="fas fa-layer-group"></i> Manage Sections
                        </a>
                    </div>
                    <div class="col-6">
                        <a href="<?php echo BASE_URL; ?>modules/reports/index.php" class="sp-qa-btn sp-qa-red">
                            <i class="fas fa-chart-column"></i> Generate Reports
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- STEP 2: STRAND CARDS (SHS ONLY - Grade 11 & 12) -->
<!-- ============================================================ -->
<?php if ($is_shs_grade && $selected_grade && !$selected_strand && !empty($available_strands)): ?>
    <div class="card shadow-sm rounded-3 mb-4">
        <div class="card-header bg-white border-0 pt-3">
            <h5 class="mb-0">
                <i class="fas fa-layer-group text-primary me-2"></i>
                Select Strand for <?php echo $selected_grade; ?>
            </h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($available_strands as $strand): 
                    $strand_count = 0;
                    foreach ($sections_by_grade[$selected_grade] as $sec) {
                        if ($sec['strand'] === $strand) {
                            $strand_count++;
                        }
                    }
                ?>
                    <div class="col-md-3">
                        <div class="card strand-card border-0 shadow-sm" 
                             onclick="window.location.href='?grade=<?php echo urlencode($selected_grade); ?>&strand=<?php echo $strand; ?>&section=0'">
                            <div class="card-body text-center">
                                <div class="strand-icon <?php echo $strand === 'GAS' ? 'text-info' : 'text-primary'; ?>">
                                    <i class="fas <?php echo $strand === 'GAS' ? 'fa-flask' : 'fa-users'; ?>"></i>
                                </div>
                                <div class="strand-label"><?php echo $strand === 'ASSH' ? 'ASSH Cluster' : $strand; ?></div>
                                <div class="strand-sub"><?php echo $strand_count; ?> sections</div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- STEP 3: SECTIONS UNDER SELECTED GRADE / STRAND -->
<!-- ============================================================ -->
<?php if ($is_shs_grade && $selected_grade && $selected_strand && empty($filtered_sections)): ?>
    <div class="card shadow-sm rounded-3 mb-4">
        <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-layer-group text-primary me-2"></i>
                Sections in <?php echo $selected_grade; ?> -
                <?php echo $selected_strand === 'ASSH' ? 'ASSH Cluster' : $selected_strand; ?>
                <span class="badge bg-secondary ms-2">0</span>
            </h5>
            <div>
                <a href="?grade=<?php echo urlencode($selected_grade); ?>&strand=&section=0" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Strands
                </a>
            </div>
        </div>
        <div class="card-body text-center text-muted py-4">
            No sections have been created yet for <?php echo $selected_grade; ?> -
            <?php echo $selected_strand === 'ASSH' ? 'ASSH Cluster' : $selected_strand; ?>.
            Add one from Settings first.
        </div>
    </div>
<?php endif; ?>

<?php if ($selected_grade && !empty($filtered_sections)): ?>
    <div class="card shadow-sm rounded-3 mb-4">
        <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-layer-group text-primary me-2"></i>
                Sections in <?php echo $selected_grade; ?>
                <?php if ($selected_strand): ?>
                    - <?php echo $selected_strand === 'ASSH' ? 'ASSH Cluster' : $selected_strand; ?>
                <?php endif; ?>
                <span class="badge bg-secondary ms-2"><?php echo count($filtered_sections); ?></span>
            </h5>
            <div>
                <?php if ($is_shs_grade && $selected_strand): ?>
                    <a href="?grade=<?php echo urlencode($selected_grade); ?>&strand=&section=0" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Strands
                    </a>
                <?php endif; ?>
                <a href="?grade=0&strand=&section=0" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Clear
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-2">
                <?php foreach ($filtered_sections as $sec): 
                    $is_selected = ($selected_section == $sec['section_id']);
                    $is_shs = ($selected_grade === 'Grade 11' || $selected_grade === 'Grade 12');
                ?>
                    <div class="col-md-3 col-lg-2">
                        <a href="?grade=<?php echo urlencode($selected_grade); ?>&strand=<?php echo urlencode($selected_strand); ?>&section=<?php echo $sec['section_id']; ?>#students-section" 
                           class="text-decoration-none">
                            <div class="card section-card <?php echo $is_selected ? 'active' : 'border-0 shadow-sm'; ?>">
                                <div class="card-body text-center py-3">
                                    <div class="section-name <?php echo $is_selected ? 'text-primary' : 'text-dark'; ?>">
                                        <?php echo htmlspecialchars($sec['section_name']); ?>
                                    </div>
                                    <?php if ($is_shs && !empty($sec['strand'])): ?>
                                        <div class="section-strand">
                                            <span class="badge bg-info"><?php echo htmlspecialchars($sec['strand'] === 'ASSH' ? 'ASSH Cluster' : $sec['strand']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="mt-1">
                                        <span class="badge bg-secondary">
                                            <?php echo $sec['current_enrollment'] ?? 0; ?>/<?php echo $sec['capacity'] ?? 45; ?>
                                        </span>
                                    </div>
                                    <div class="text-muted small mt-1">
                                        <i class="fas fa-chalkboard-teacher me-1"></i><?php echo htmlspecialchars($sec['adviser_name'] ?? 'No adviser'); ?>
                                    </div>
                                    <?php if ($is_selected): ?>
                                        <span class="badge bg-primary mt-1">Viewing</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- STEP 4: STUDENTS IN SELECTED SECTION -->
<!-- ============================================================ -->
<?php if ($selected_section > 0 && $section_info): 
    // ✅ Check if this is SHS (Grade 11-12) to show strand column
    $show_strand = ($section_info['grade_level'] === 'Grade 11' || $section_info['grade_level'] === 'Grade 12');
?>
    <div class="card shadow-sm rounded-3" id="students-section" tabindex="-1" style="scroll-margin-top: 20px;">
        <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-users text-primary me-2"></i>
                Students in <?php echo htmlspecialchars($section_info['section_name']); ?>
                <span class="badge bg-secondary ms-2"><?php echo count($section_students); ?> students</span>
                <div class="mt-1" style="font-size: 13px; font-weight: 400; color: #888;">
                    <i class="fas fa-chalkboard-teacher me-1"></i> Adviser:
                    <?php echo htmlspecialchars($section_info['adviser_name'] ?? 'Not yet assigned'); ?>
                </div>
            </h5>
            <div>
                <a href="?grade=<?php echo urlencode($selected_grade); ?>&strand=<?php echo urlencode($selected_strand); ?>&section=0" 
                   class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Sections
                </a>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (count($section_students) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>LRN</th>
                                <th>Student Name</th>
                                <th>Grade Level</th>
                                <?php if ($show_strand): ?>
                                    <!-- ✅ STRAND COLUMN - ONLY FOR SHS (Grade 11-12) -->
                                    <th>Strand</th>
                                <?php endif; ?>
                                <th>Email</th>
                                <th>Contact</th>
                                <th>Enrollment Date</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; ?>
                            <?php foreach ($section_students as $student): ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><strong><?php echo attQrLrnLink($student['lrn']); ?></strong></td>
                                    <td>
                                        <?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?>
                                        <?php if (!empty($student['middle_name'])): ?>
                                            <?php echo htmlspecialchars($student['middle_name']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['grade_level']); ?></td>
                                    <?php if ($show_strand): ?>
                                        <!-- ✅ STRAND COLUMN - ONLY FOR SHS -->
                                        <td>
                                            <?php if (!empty($student['strand'])): ?>
                                                <span class="badge bg-info"><?php echo htmlspecialchars(normalizeStrandKey($student['strand']) === 'ASSH' ? 'ASSH Cluster' : $student['strand']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($student['contact_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($student['enrollment_date'])); ?></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-outline-primary student-view-btn" title="View"
                                                    data-student-view-url="view.php?id=<?php echo (int)$student['student_id']; ?>&embed=1"
                                                    data-student-name="<?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name'], ENT_QUOTES); ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-success student-edit-btn" title="Edit"
                                                    data-student-edit-url="edit.php?id=<?php echo (int)$student['student_id']; ?>&embed=1"
                                                    data-student-name="<?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name'], ENT_QUOTES); ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if (hasRole('Administrator')): ?>
                                            <button type="button" class="btn btn-outline-danger" title="Delete" 
                                                    onclick="showDeleteOptions(<?php echo $student['student_id']; ?>, '<?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php else: ?>
                                            <button type="button" class="btn btn-outline-danger" title="Delete" 
                                                    onclick="showDeleteModal(<?php echo $student['student_id']; ?>, '<?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-users fa-3x d-block mb-3"></i>
                    <h5>No students enrolled in this section</h5>
                    <p>Students will appear here after enrollment approval.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

</div><!-- /.students-page -->

<!-- STUDENT DETAILS MODAL -->
<div class="modal fade student-details-modal" id="studentDetailsModal" tabindex="-1" aria-labelledby="studentDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="studentDetailsModalLabel"><i class="fas fa-user-graduate me-2"></i>Student Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="student-details-loading" id="studentDetailsLoading">
                    <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Loading student details...
                </div>
                <iframe id="studentDetailsFrame" title="Student details" loading="lazy"></iframe>
            </div>
        </div>
    </div>
</div>

<!-- EDIT STUDENT MODAL -->
<div class="modal fade student-edit-modal" id="studentEditModal" tabindex="-1" aria-labelledby="studentEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="studentEditModalLabel"><i class="fas fa-user-edit me-2"></i>Edit Student</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="student-edit-loading" id="studentEditLoading">
                    <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Loading student form...
                </div>
                <iframe id="studentEditFrame" title="Edit student" loading="lazy"></iframe>
            </div>
        </div>
    </div>
</div>

<style>
    .student-details-modal .modal-dialog { width: min(1120px, calc(100vw - 32px)); max-width: 1120px; margin: 1.5rem auto; }
    .student-details-modal .modal-content { height: min(650px, calc(100vh - 42px)); overflow: hidden; border: 0; border-radius: 16px; box-shadow: 0 24px 70px rgba(15, 26, 60, .28); }
    .student-details-modal .modal-header { flex: 0 0 auto; padding: 14px 20px; border-bottom: 1px solid #e5eaf2; background: #fff; color: #14213d; }
    .student-details-modal .modal-title { font-size: 16px; font-weight: 700; }
    .student-details-modal .modal-body { position: relative; min-height: 0; overflow: hidden; background: #f3f6fa; }
    .student-details-modal iframe { display: block; width: 100%; height: 100%; min-height: 580px; border: 0; opacity: 0; transition: opacity .2s ease; }
    .student-details-modal iframe.is-loaded { opacity: 1; }
    .student-details-loading { position: absolute; inset: 0; z-index: 1; display: grid; place-items: center; color: #607795; font-size: 13px; background: #f3f6fa; transition: opacity .2s ease; }
    .student-details-loading.is-hidden { opacity: 0; pointer-events: none; }
    @media (max-width: 600px) {
        .student-details-modal .modal-dialog { width: calc(100vw - 12px); margin: .375rem auto; }
        .student-details-modal .modal-content { height: calc(100vh - 12px); border-radius: 12px; }
        .student-details-modal iframe { min-height: 0; }
    }
</style>

<style>
    .student-edit-modal .modal-dialog { width:min(980px, calc(100vw - 32px)); max-width:980px; margin:1.5rem auto; }
    .student-edit-modal .modal-content { height:min(700px, calc(100vh - 42px)); overflow:hidden; border:0; border-radius:16px; box-shadow:0 24px 70px rgba(15,26,60,.28); }
    .student-edit-modal .modal-header { flex:0 0 auto; padding:14px 20px; border-bottom:1px solid #e5eaf2; background:#fff; color:#14213d; }
    .student-edit-modal .modal-title { font-size:16px; font-weight:700; }
    .student-edit-modal .modal-body { position:relative; min-height:0; overflow:hidden; background:#f3f6fa; }
    .student-edit-modal iframe { display:block; width:100%; height:100%; min-height:620px; border:0; opacity:0; transition:opacity .2s ease; }
    .student-edit-modal iframe.is-loaded { opacity:1; }
    .student-edit-loading { position:absolute; inset:0; z-index:1; display:grid; place-items:center; color:#607795; font-size:13px; background:#f3f6fa; transition:opacity .2s ease; }
    .student-edit-loading.is-hidden { opacity:0; pointer-events:none; }
    @media (max-width:600px) {
        .student-edit-modal .modal-dialog { width:calc(100vw - 12px); margin:.375rem auto; }
        .student-edit-modal .modal-content { height:calc(100vh - 12px); border-radius:12px; }
        .student-edit-modal iframe { min-height:0; }
    }
</style>

<script>
(function () {
    function initStudentEditModal() {
        var frame = document.getElementById('studentEditFrame');
        var loading = document.getElementById('studentEditLoading');
        var title = document.getElementById('studentEditModalLabel');
        var modalElement = document.getElementById('studentEditModal');
        if (!frame || !loading || !modalElement || !window.bootstrap) return;
        var modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        document.querySelectorAll('.student-edit-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                frame.classList.remove('is-loaded');
                loading.classList.remove('is-hidden');
                title.innerHTML = '<i class="fas fa-user-edit me-2"></i>Edit ' + (button.dataset.studentName || 'Student');
                frame.src = button.dataset.studentEditUrl;
                modal.show();
            });
        });
        frame.addEventListener('load', function () {
            frame.classList.add('is-loaded');
            loading.classList.add('is-hidden');
        });
        modalElement.addEventListener('hidden.bs.modal', function () {
            frame.removeAttribute('src');
            frame.classList.remove('is-loaded');
            loading.classList.remove('is-hidden');
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initStudentEditModal);
    else initStudentEditModal();
})();
</script>

<script>
(function () {
    function initStudentDetailsModal() {
        var frame = document.getElementById('studentDetailsFrame');
        var loading = document.getElementById('studentDetailsLoading');
        var title = document.getElementById('studentDetailsModalLabel');
        var modalElement = document.getElementById('studentDetailsModal');
        if (!frame || !loading || !modalElement || !window.bootstrap) return;
        var modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        document.querySelectorAll('.student-view-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                frame.classList.remove('is-loaded');
                loading.classList.remove('is-hidden');
                title.innerHTML = '<i class="fas fa-user-graduate me-2"></i>' + (button.dataset.studentName || 'Student Details');
                frame.src = button.dataset.studentViewUrl;
                modal.show();
            });
        });
        frame.addEventListener('load', function () {
            frame.classList.add('is-loaded');
            loading.classList.add('is-hidden');
        });
        modalElement.addEventListener('hidden.bs.modal', function () {
            frame.removeAttribute('src');
            frame.classList.remove('is-loaded');
            loading.classList.remove('is-hidden');
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initStudentDetailsModal);
    } else {
        initStudentDetailsModal();
    }
})();
</script>

<!-- ============================================================ -->
<!-- DELETE MODALS -->
<!-- ============================================================ -->

<!-- SOFT DELETE MODAL -->
<style>
    #deleteModal .modal-content, #permanentDeleteModal .modal-content, #chooseDeleteModal .modal-content {
        border: 1px solid #e5eaf2; border-radius: 18px; overflow: hidden;
        box-shadow: 0 24px 70px rgba(15,26,60,0.22);
    }
    #deleteModal .modal-dialog, #permanentDeleteModal .modal-dialog, #chooseDeleteModal .modal-dialog {
        width: min(590px, calc(100vw - 32px)); max-width: 590px;
    }
    #deleteModal .modal-header, #permanentDeleteModal .modal-header, #chooseDeleteModal .modal-header {
        background: #fff; color: #14213d; border: none; padding: 24px 28px 0;
    }
    #deleteModal .modal-title, #permanentDeleteModal .modal-title, #chooseDeleteModal .modal-title {
        display: none;
    }
    #deleteModal .btn-close, #permanentDeleteModal .btn-close, #chooseDeleteModal .btn-close {
        opacity: .55; margin: -8px -8px 0 auto;
    }
    .del-icon-circle {
        width: 64px; height: 64px; border-radius: 16px; display: flex; align-items: center;
        justify-content: center; margin: 0 0 16px; font-size: 24px;
    }
    #deleteModal .del-icon-circle { background: #FFF3E0; color: #B3261E; }
    #permanentDeleteModal .del-icon-circle { background: #FDEEEE; color: #B3261E; }
    #chooseDeleteModal .del-icon-circle { background: #edf4ff; color: #416b9f; }
    .del-modal-title { font-family: var(--font-primary, 'Inter', sans-serif); font-weight: 700; font-size: 25px; line-height: 1.15; color: var(--ink, #0F1A3C); margin: 0; }
    .del-modal-sub { color: #71829d; font-size: 15px; margin: 7px 0 0; }
    #deleteModal .modal-body, #permanentDeleteModal .modal-body, #chooseDeleteModal .modal-body {
        padding: 0 28px 20px !important;
    }
    #deleteModal .modal-footer, #permanentDeleteModal .modal-footer, #chooseDeleteModal .modal-footer {
        border-top: 1px solid #edf1f6; padding: 18px 28px 24px; gap: 10px;
    }
    .del-btn-cancel {
        background: #eef2f8; color: #71829d; border: 0;
        border-radius: 11px; padding: 11px 22px; font-weight: 700; font-size: 13.5px;
    }
    .del-btn-cancel:hover { background: #e5ebf4; color: #526783; }
    .del-btn-soft {
        background: #263650; border-color: #263650; color: #fff;
        border-radius: 11px; padding: 11px 22px; font-weight: 700; font-size: 13.5px;
    }
    .del-btn-soft:hover { background: #1b2840; color: #fff; }
    .del-btn-danger {
        background: #B3261E; border-color: #B3261E; color: #fff;
        border-radius: 11px; padding: 11px 22px; font-weight: 700; font-size: 13.5px;
    }
    .del-btn-danger:hover { background: #93201A; color: #fff; }
    .del-warning-box {
        background: #FDEEEE; border: 1px solid #F5C6C6; border-radius: 10px; padding: 12px 14px; margin-top: 14px;
    }
    .del-warning-box ul { list-style: none; padding-left: 0; margin: 8px 0 0 0; }
    .del-warning-box li { font-size: 13px; color: var(--ink, #0F1A3C); padding: 2px 0; }
    .delete-choice-list { display:grid; gap:12px; margin-top:24px; }
    .delete-choice {
        width:100%; display:flex; align-items:center; gap:14px; text-align:left;
        padding:15px 16px; border:1px solid #dfe7f3; border-radius:13px; background:#fff;
        color:#253754; transition:background .18s ease, border-color .18s ease, box-shadow .18s ease;
    }
    .delete-choice:hover, .delete-choice:focus-visible {
        border-color:#b9d1f7; background:#f7faff; box-shadow:0 5px 16px rgba(58,104,166,.08); outline:0;
    }
    .delete-choice-icon { width:44px; height:44px; flex:0 0 44px; display:grid; place-items:center; border-radius:13px; background:#edf4ff; color:#416b9f; font-size:19px; }
    .delete-choice.danger .delete-choice-icon { background:#fff0f0; color:#e3343f; }
    .delete-choice-copy { flex:1; min-width:0; }
    .delete-choice-title { display:block; font-size:15px; font-weight:700; margin-bottom:3px; }
    .delete-choice-description { display:block; color:#71829d; font-size:12.5px; line-height:1.45; max-width:390px; }
    .delete-choice-arrow { color:#8495ae; font-size:17px; }
    .delete-choice-radio { width:20px; height:20px; flex:0 0 20px; accent-color:#1976ff; }
    @media (max-width:600px) {
        #deleteModal .modal-dialog, #permanentDeleteModal .modal-dialog, #chooseDeleteModal .modal-dialog { width:calc(100vw - 20px); }
        #deleteModal .modal-header, #permanentDeleteModal .modal-header, #chooseDeleteModal .modal-header { padding-left:20px; padding-right:20px; }
        #deleteModal .modal-body, #permanentDeleteModal .modal-body, #chooseDeleteModal .modal-body { padding-left:20px !important; padding-right:20px !important; }
        #deleteModal .modal-footer, #permanentDeleteModal .modal-footer, #chooseDeleteModal .modal-footer { padding-left:20px; padding-right:20px; }
        .del-modal-title { font-size:22px; }
    }
</style>

<!-- CHOOSE DELETE TYPE MODAL (replaces the generic SweetAlert2 popup) -->
<div class="modal fade" id="chooseDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-xmark me-2"></i>Delete Student</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="del-icon-circle"><i class="fas fa-trash-can"></i></div>
                <p class="del-modal-title">Choose delete type</p>
                <p class="del-modal-sub">for <span id="chooseDeleteName">this student</span></p>
                <div class="delete-choice-list">
                    <button type="button" class="delete-choice" id="chooseSoftDeleteBtn">
                        <input class="delete-choice-radio" type="radio" name="deleteType" checked aria-label="Soft Delete">
                        <span class="delete-choice-icon"><i class="fas fa-trash-can"></i></span>
                        <span class="delete-choice-copy">
                            <span class="delete-choice-title">Soft Delete</span>
                            <span class="delete-choice-description">Can be restored later. The student record will be moved to trash.</span>
                        </span>
                        <i class="fas fa-chevron-right delete-choice-arrow" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="delete-choice danger" id="choosePermanentDeleteBtn">
                        <input class="delete-choice-radio" type="radio" name="deleteType" aria-label="Permanent Delete">
                        <span class="delete-choice-icon"><i class="fas fa-skull-crossbones"></i></span>
                        <span class="delete-choice-copy">
                            <span class="delete-choice-title">Permanent Delete</span>
                            <span class="delete-choice-description">This action cannot be undone. The student record will be permanently removed.</span>
                        </span>
                        <i class="fas fa-chevron-right delete-choice-arrow" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="del-btn-cancel flex-fill" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="del-btn-soft flex-fill" id="continueDeleteBtn">Continue</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash-can me-2"></i>Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 28px 24px 10px 24px;">
                <div class="del-icon-circle"><i class="fas fa-trash-can"></i></div>
                <p class="del-modal-title" id="deleteMessage">Are you sure you want to delete this student?</p>
                <p class="del-modal-sub" id="deleteSubMessage">This can be restored later.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="del-btn-cancel" data-bs-dismiss="modal" style="border:none;">Cancel</button>
                <a href="#" id="confirmDeleteBtn" class="del-btn-soft">
                    <i class="fas fa-trash-can me-1"></i> Delete
                </a>
            </div>
        </div>
    </div>
</div>

<!-- PERMANENT DELETE MODAL -->
<div class="modal fade" id="permanentDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-triangle-exclamation me-2"></i>Permanent Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 28px 24px 10px 24px;">
                <div class="del-icon-circle"><i class="fas fa-triangle-exclamation"></i></div>
                <p class="del-modal-title" id="permanentDeleteMessage">This action cannot be undone!</p>
                <div class="del-warning-box">
                    <span class="small fw-semibold" style="color:var(--ink);"><i class="fas fa-circle-info me-1"></i>This will permanently delete:</span>
                    <ul>
                        <li><i class="fas fa-xmark text-danger me-2"></i>Student record</li>
                        <li><i class="fas fa-xmark text-danger me-2"></i>All grades</li>
                        <li><i class="fas fa-xmark text-danger me-2"></i>All enrollments</li>
                        <li><i class="fas fa-xmark text-danger me-2"></i>All related records</li>
                    </ul>
                </div>
                <p class="del-modal-sub mt-2 mb-0"><i class="fas fa-circle-info me-1"></i>This action is logged for security.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="del-btn-cancel" data-bs-dismiss="modal" style="border:none;">Cancel</button>
                <a href="#" id="confirmPermanentDeleteBtn" class="del-btn-danger">
                    <i class="fas fa-trash-can me-1"></i> Delete Permanently
                </a>
            </div>
        </div>
    </div>
</div>

<!-- SweetAlert2 CDN -->
<script>
var csrfToken = <?php echo json_encode(generateCSRFToken()); ?>;
var pendingDeleteId = null;
var pendingDeleteName = null;
var pendingDeleteType = 'soft';

function showDeleteOptions(id, name) {
    pendingDeleteId = id;
    pendingDeleteName = name;
    document.getElementById('chooseDeleteName').textContent = name;
    pendingDeleteType = 'soft';
    document.querySelector('#chooseSoftDeleteBtn .delete-choice-radio').checked = true;
    document.querySelector('#choosePermanentDeleteBtn .delete-choice-radio').checked = false;
    var modal = new bootstrap.Modal(document.getElementById('chooseDeleteModal'));
    modal.show();
}

document.getElementById('chooseSoftDeleteBtn').addEventListener('click', function (event) {
    if (event.target.tagName !== 'INPUT') {
        this.querySelector('.delete-choice-radio').checked = true;
    }
    pendingDeleteType = 'soft';
});

document.getElementById('choosePermanentDeleteBtn').addEventListener('click', function (event) {
    if (event.target.tagName !== 'INPUT') {
        this.querySelector('.delete-choice-radio').checked = true;
    }
    pendingDeleteType = 'permanent';
});

document.getElementById('continueDeleteBtn').addEventListener('click', function () {
    bootstrap.Modal.getInstance(document.getElementById('chooseDeleteModal')).hide();
    if (pendingDeleteType === 'permanent') {
        document.getElementById('permanentDeleteMessage').innerHTML = 'Are you sure you want to <strong>PERMANENTLY DELETE</strong> <strong>' + pendingDeleteName + '</strong>?<br><small class="text-muted">This action cannot be undone.</small>';
        document.getElementById('confirmPermanentDeleteBtn').href = 'delete_permanent.php?id=' + pendingDeleteId + '&confirm=yes&from=index&csrf_token=' + encodeURIComponent(csrfToken);
    } else {
        document.getElementById('deleteMessage').innerHTML = 'Are you sure you want to delete <strong>' + pendingDeleteName + '</strong>?';
        document.getElementById('deleteSubMessage').innerHTML = 'This can be restored later.';
        document.getElementById('confirmDeleteBtn').href = 'delete.php?id=' + pendingDeleteId + '&from=index&csrf_token=' + encodeURIComponent(csrfToken);
    }
    setTimeout(function () {
        new bootstrap.Modal(document.getElementById(pendingDeleteType === 'permanent' ? 'permanentDeleteModal' : 'deleteModal')).show();
    }, 250);
});

function showDeleteModal(id, name) {
    document.getElementById('deleteMessage').innerHTML = 'Are you sure you want to delete <strong>' + name + '</strong>?';
    document.getElementById('deleteSubMessage').innerHTML = 'This can be restored later.';
    document.getElementById('confirmDeleteBtn').href = 'delete.php?id=' + id + '&from=index&csrf_token=' + encodeURIComponent(csrfToken);
    var modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}
</script>

<?php include '../../includes/footer.php'; ?>