<?php
// modules/subjects/index.php
// Subject management - Grade Level Boxes UI

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';

// ASSH Cluster = the school's Arts, Social Sciences and Humanities cluster. Older
// records (sections created before the cluster was renamed) still carry the
// retired label 'HUMSS'. Treat both as the same cluster so those sections and
// their subjects stay visible -- nothing in the database is rewritten.
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

if (!hasRole('Administrator')) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'Subject Management';
$page_subtitle = 'Manage all subjects, track teacher assignments, and organize subjects by grade level.';
$current_page = 'subjects';
$base_url = '../../';

// SY 2026-2027 offers only these two Senior High strands. Validating
// $selected_strand against this allow-list (rather than accepting whatever
// is on file) keeps a retired label like 'HUMSS' -- still present on
// prior-year sections that are intentionally left untouched -- from ever
// being reachable here, including via a hand-edited or stale bookmarked URL.
$active_shs_strands = ['GAS', 'ASSH'];

$selected_grade = isset($_GET['grade']) ? sanitize($_GET['grade']) : '';
$selected_strand_raw = isset($_GET['strand']) ? sanitize($_GET['strand']) : '';
$selected_strand = in_array($selected_strand_raw, $active_shs_strands, true) ? $selected_strand_raw : '';
$selected_section = isset($_GET['section']) ? sanitize($_GET['section']) : '';

// Get active school year
$active_sy = getRecord("SELECT sy_id, sy_name FROM school_years WHERE is_active = 1");
$school_year_id = $active_sy ? $active_sy['sy_id'] : 0;

// Get all sections grouped by grade level
$all_sections = getRecords("
    SELECT section_id, section_name, grade_level, strand 
    FROM sections 
    WHERE is_active = 1 
    ORDER BY grade_level, section_name
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

// GET SECTIONS FOR SELECTED GRADE AND STRAND
$filtered_sections = [];
if ($selected_grade && $selected_strand) {
    // For SHS: filter by strand
    foreach (($sections_by_grade[$selected_grade] ?? []) as $sec) {
        if ($sec['strand'] === $selected_strand) {
            $filtered_sections[] = $sec;
        }
    }
} elseif ($selected_grade) {
    // For JHS: show all sections (no strand filter)
    $filtered_sections = $sections_by_grade[$selected_grade] ?? [];
}

// GET SUBJECTS FOR SELECTED SECTION WITH TEACHER INFO
$section_subjects = [];
$section_info = null;
$all_teachers = getRecords("SELECT user_id, first_name, last_name, specialization FROM users WHERE role_id = 3 AND is_active = 1 ORDER BY last_name, first_name");

if ($selected_section) {
    $section_info = getRecord("SELECT * FROM sections WHERE section_id = ?", [$selected_section]);
    if ($section_info) {
        $section_info['strand'] = normalizeStrandKey($section_info['strand']);
        // Get subjects with teacher assignments
        $sql = "SELECT s.*, 
                       ts.ts_id,
                       ts.teacher_id,
                       u.first_name as teacher_first,
                       u.last_name as teacher_last,
                       u.specialization
                FROM subjects s
                LEFT JOIN teacher_subjects ts ON s.subject_code = ts.subject_code 
                    AND ts.section_id = ? 
                    AND ts.school_year_id = ?
                LEFT JOIN users u ON ts.teacher_id = u.user_id
                WHERE s.is_active = 1 
                AND s.grade_level = ?";
        $params = [$selected_section, $school_year_id, $section_info['grade_level']];
        
        if ($section_info['grade_level'] === 'Grade 11' || $section_info['grade_level'] === 'Grade 12') {
            if ($section_info['strand'] === 'ASSH') {
                // ASSH Cluster: the current ASSH subjects, plus any older
                // HUMSS-labelled subject that already has a teacher assigned in
                // this section, so existing assignments are not lost from view.
                $sql = str_replace('WHERE s.is_active = 1', 'WHERE (s.is_active = 1 OR ts.ts_id IS NOT NULL)', $sql);
                $sql .= " AND s.strand IN ('ASSH', 'HUMSS')";
            } elseif (!empty($section_info['strand'])) {
                $sql .= " AND s.strand = ?";
                $params[] = $section_info['strand'];
            }
        } else {
            $sql .= " AND (s.strand IS NULL OR s.strand = '')";
        }
        
        $sql .= " ORDER BY s.subject_name";
        $section_subjects = getRecords($sql, $params);
        $section_schedule_map = [];
        if ($section_subjects) {
            $tsIds = array_values(array_filter(array_map('intval', array_column($section_subjects, 'ts_id'))));
            if ($tsIds) {
                $placeholders = implode(',', array_fill(0, count($tsIds), '?'));
                $scheduleRows = getRecords("SELECT ts.ts_id, tcs.day_of_week, TIME_FORMAT(tcs.start_time, '%H:%i') AS start_time, TIME_FORMAT(tcs.end_time, '%H:%i') AS end_time, tcs.shift FROM teacher_class_schedule tcs JOIN teacher_subjects ts ON ts.teacher_id=tcs.teacher_id AND ts.subject_code=tcs.subject_code AND ts.section_id=tcs.section_id AND ts.school_year_id=tcs.school_year_id WHERE ts.ts_id IN ($placeholders) AND tcs.is_active=1 ORDER BY tcs.day_of_week, tcs.start_time", $tsIds);
                foreach ($scheduleRows as $scheduleRow) {
                    foreach ($section_subjects as $sectionSubject) {
                        if ((int)$sectionSubject['ts_id'] > 0 && (int)$sectionSubject['teacher_id'] > 0) {
                            $match = getRecord("SELECT ts_id FROM teacher_subjects WHERE ts_id=? AND teacher_id=? AND subject_code=? AND section_id=? AND school_year_id=?", [(int)$sectionSubject['ts_id'], (int)$sectionSubject['teacher_id'], $sectionSubject['subject_code'], $selected_section, $school_year_id]);
                            if ($match && (int)$match['ts_id'] === (int)$scheduleRow['ts_id']) $section_schedule_map[(int)$scheduleRow['ts_id']][] = $scheduleRow;
                        }
                    }
                }
            }
        }
    }
}


// ============================================================
// OVERVIEW DATA (shown when no grade is selected yet)
// ============================================================
$subj_overview = [];
$subjects_by_grade_count = [];
$unassigned = [];
$unassigned_total = 0;
// Grade filter for the "Subjects Without Teacher" panel. Its own parameter
// name so it never collides with the ?grade= that selects a grade level.
$ut_grade_options = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
$ut_grade = sanitize($_GET['ut_grade'] ?? '');
if (!in_array($ut_grade, $ut_grade_options, true)) {
    $ut_grade = '';
}
$unassigned_limit = 10;

if (!$selected_grade) {
    $subj_overview['total'] = (int)(getRecord("SELECT COUNT(*) AS c FROM subjects WHERE is_active = 1")['c'] ?? 0);
    $subj_overview['jhs'] = (int)(getRecord("SELECT COUNT(*) AS c FROM subjects WHERE is_active = 1 AND grade_level IN ('Grade 7','Grade 8','Grade 9','Grade 10')")['c'] ?? 0);
    $subj_overview['shs'] = (int)(getRecord("SELECT COUNT(*) AS c FROM subjects WHERE is_active = 1 AND grade_level IN ('Grade 11','Grade 12')")['c'] ?? 0);
    $subj_overview['assigned'] = (int)(getRecord("SELECT COUNT(*) AS c FROM teacher_subjects WHERE school_year_id = ?", [$school_year_id])['c'] ?? 0);

    $rows = getRecords("
        SELECT grade_level, COUNT(*) AS total
        FROM subjects WHERE is_active = 1 GROUP BY grade_level
    ");
    foreach ($rows as $r) { $subjects_by_grade_count[$r['grade_level']] = (int)$r['total']; }

    // Shared WHERE for both the count and the list, so the badge can never
    // disagree with the rows underneath it.
    $ut_where = "s.is_active = 1
          AND NOT EXISTS (
              SELECT 1 FROM teacher_subjects ts
              WHERE ts.subject_code = s.subject_code AND ts.school_year_id = ?
          )";
    $ut_params = [$school_year_id];
    if ($ut_grade !== '') {
        $ut_where .= " AND s.grade_level = ?";
        $ut_params[] = $ut_grade;
    }

    // The real total, not the capped list length — the badge used to show
    // count($unassigned), which stopped at the LIMIT and understated it.
    $unassigned_total = (int)(getRecord(
        "SELECT COUNT(*) AS c FROM subjects s WHERE $ut_where", $ut_params
    )['c'] ?? 0);

    $unassigned = getRecords("
        SELECT s.subject_code, s.subject_name, s.grade_level, s.strand
        FROM subjects s
        WHERE $ut_where
        ORDER BY s.grade_level, s.subject_name
        LIMIT $unassigned_limit
    ", $ut_params);
}

// ============================================================
// "ALL SUBJECTS" TABLE (shown alongside the overview above, when no
// grade drill-down is active) — real search/grade/type/status filters,
// each a genuine SQL WHERE condition. This is additional to, and does
// not replace, the existing Grade -> Strand -> Section -> Subjects
// drill-down below, which is untouched.
// ============================================================
$subj_search_q = isset($_GET['q']) ? trim(sanitize($_GET['q'])) : '';
$subj_filter_grade = isset($_GET['fgrade']) ? sanitize($_GET['fgrade']) : '';
$subj_filter_type = isset($_GET['ftype']) ? sanitize($_GET['ftype']) : '';
$subj_filter_status = isset($_GET['fstatus']) ? sanitize($_GET['fstatus']) : '';
$all_subjects_list = [];

if (!$selected_grade) {
    $as_where = [];
    $as_params = [];

    // Default matches the rest of this page (active-only) unless the user
    // explicitly asks to see inactive or all subjects.
    if ($subj_filter_status === 'inactive') {
        $as_where[] = 's.is_active = 0';
    } elseif ($subj_filter_status === 'all') {
        // no condition — both active and inactive
    } else {
        $as_where[] = 's.is_active = 1';
    }
    if ($subj_search_q !== '') {
        $like = '%' . $subj_search_q . '%';
        $as_where[] = '(s.subject_code LIKE ? OR s.subject_name LIKE ?)';
        $as_params[] = $like;
        $as_params[] = $like;
    }
    if ($subj_filter_grade !== '') {
        $as_where[] = 's.grade_level = ?';
        $as_params[] = $subj_filter_grade;
    }
    if ($subj_filter_type !== '') {
        $as_where[] = 's.subject_type = ?';
        $as_params[] = $subj_filter_type;
    }
    $as_where_sql = $as_where ? ('WHERE ' . implode(' AND ', $as_where)) : '';

    $all_subjects_list = getRecords("
        SELECT s.subject_code, s.subject_name, s.grade_level, s.strand, s.subject_type, s.units, s.is_active
        FROM subjects s
        $as_where_sql
        ORDER BY s.grade_level, s.subject_name
    ", $as_params);

    // A subject's assigned teacher is per-section-per-school-year (see
    // teacher_subjects), so a subject can have more than one teacher across
    // different sections. For this flat list, pull every distinct teacher
    // name assigned to the subject for the active school year — real data,
    // just aggregated in PHP instead of inventing a single-teacher column.
    if (!empty($all_subjects_list)) {
        $codes = array_column($all_subjects_list, 'subject_code');
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $ts_rows = getRecords("
            SELECT DISTINCT ts.subject_code, u.user_id, u.first_name, u.last_name
            FROM teacher_subjects ts
            JOIN users u ON u.user_id = ts.teacher_id
            WHERE ts.subject_code IN ($placeholders) AND ts.school_year_id = ?
            ORDER BY u.last_name, u.first_name
        ", array_merge($codes, [$school_year_id]));
        $subject_teachers_map = [];
        foreach ($ts_rows as $tr) {
            $subject_teachers_map[$tr['subject_code']][] = $tr;
        }
        foreach ($all_subjects_list as &$asub) {
            $asub['teachers'] = $subject_teachers_map[$asub['subject_code']] ?? [];
        }
        unset($asub);
    }
}

// Simple in-PHP pagination over the already-filtered list — consistent
// with the same approach used on the Teachers page.
$as_per_page = 10;
$as_total = count($all_subjects_list);
$as_total_pages = max(1, (int)ceil($as_total / $as_per_page));
$as_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
if ($as_page > $as_total_pages) { $as_page = $as_total_pages; }
$all_subjects_page = array_slice($all_subjects_list, ($as_page - 1) * $as_per_page, $as_per_page);

// Deterministic colour per subject_type — same type always gets the same
// colour, picked from a fixed palette (mirrors the same pattern used for
// teacher specialization badges on the Teachers page).
$type_palette = [
    ['bg' => '#EAF1FE', 'fg' => '#1957C2'],
    ['bg' => '#E9F7EF', 'fg' => '#1E7B45'],
    ['bg' => '#FFF6E5', 'fg' => '#B7791F'],
    ['bg' => '#F1EBFE', 'fg' => '#6A3FC2'],
    ['bg' => '#FDECEF', 'fg' => '#C23A57'],
    ['bg' => '#E6F7F6', 'fg' => '#287A78'],
];
function type_badge_color($type, $palette) {
    if (empty($type)) return $palette[0];
    $idx = crc32($type) % count($palette);
    return $palette[$idx];
}

include '../../includes/header.php';
?>

<style>
    /* Grade/section/strand selector cards now come from the shared global
       .grade-card / .section-card / .strand-card / .grade-selected-pill
       components in static/css/style.css (same component used by Student
       Management, Teacher Management and Class Records) — this page no
       longer redefines its own. Colour still comes from the inline
       --accent/--accent-soft custom properties set in the PHP loop below. */
    /* Table text matched to the rest of the system. The padding selector
       mirrors Bootstrap's own so table-sm cannot shrink a table back.
       Scoped under .subj-page so it never leaks onto other pages. */
    .subj-page .table > :not(caption) > * > * {
        padding: 14px 16px;
    }
    .subj-page .table thead th {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        font-weight: 600;
        vertical-align: middle;
    }
    .subj-page .table tbody td {
        font-size: 15px;
        vertical-align: middle;
    }
    .subj-page .card-header h5 {
        font-size: 19px;
    }
    .subj-page td .badge {
        padding: 5px 14px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
    }
    /* .section-card and .strand-card also now come from the shared global
       components in static/css/style.css — removed the local redefinition
       here (it used different radius/hover/active colours than Students'
       and Teachers' cards) so this page renders the same section/strand
       selector as the rest of the system. */
    .no-subjects {
        padding: 30px;
        text-align: center;
        color: #999;
    }
    .teacher-badge {
        display: inline-block;
        padding: 2px 10px;
        border-radius: 20px;
        font-size: 11px;
        background: #e8f5e9;
        color: #1b5e20;
        border: 1px solid #a5d6a7;
    }
    .teacher-badge.no-teacher {
        background: #fff3e0;
        color: #e65100;
        border-color: #ffcc80;
    }

    /* ============================================================
       PAGE HEADER — icon box + title/subtitle + Add Subject button
       ============================================================ */
    .subj-page .sp-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 24px;
    }
    .subj-page .sp-header-main {
        display: flex;
        align-items: flex-start;
        gap: 16px;
    }
    .subj-page .sp-header-icon {
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
    .subj-page .sp-header h2 {
        font-size: 28px;
        font-weight: 700;
        color: var(--ink);
        margin: 4px 0 4px 0;
    }
    .subj-page .sp-header p {
        font-size: 14px;
        color: var(--text-muted);
        margin: 0;
        max-width: 520px;
    }
    .subj-page .sp-btn-gradient {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        border-radius: 10px;
        font-size: 13.5px;
        font-weight: 600;
        color: #fff;
        background: var(--primary);
        border: 1px solid var(--primary);
        text-decoration: none;
        white-space: nowrap;
        box-shadow: 0 1px 2px rgba(16,24,40,.04);
        transition: background 0.15s, border-color 0.15s;
    }
    .subj-page .sp-btn-gradient:hover {
        filter: brightness(1.05);
        color: #fff;
    }
    @media (max-width: 575.98px) {
        .subj-page .sp-header { flex-direction: column; }
    }

    /* ============================================================
       STAT TILES
       ============================================================ */
    .subj-page .sp-stat-card {
        background: var(--surface);
        border: 1px solid var(--border, #E5E7EB);
        border-radius: 16px;
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(16,24,40,0.06));
        padding: 18px;
        height: 100%;
        display: flex;
        align-items: center;
        gap: 14px;
        transition: box-shadow 0.2s ease, transform 0.2s ease;
    }
    .subj-page .sp-stat-card:hover {
        box-shadow: var(--shadow-md, 0 6px 18px rgba(16,24,40,0.10));
        transform: translateY(-2px);
    }
    .subj-page .sp-stat-icon {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        flex: 0 0 auto;
    }
    .subj-page .sp-stat-label {
        font-size: 12.5px;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        font-weight: 600;
        margin-bottom: 2px;
    }
    .subj-page .sp-stat-value {
        font-size: 26px;
        font-weight: 700;
        color: var(--ink);
        line-height: 1.2;
    }

    /* Cards lift slightly on hover, consistently across analytics cards. */
    .subj-page .sp-lift {
        transition: box-shadow 0.2s ease, transform 0.2s ease;
    }
    .subj-page .sp-lift:hover {
        box-shadow: var(--shadow-md, 0 6px 18px rgba(16,24,40,0.10));
        transform: translateY(-2px);
    }

    /* ============================================================
       ALL SUBJECTS — filter bar, avatar, badges, pagination
       ============================================================ */
    .subj-page .sp-filter-bar {
        background: var(--surface-hover);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 14px 16px;
        margin-bottom: 16px;
    }
    .subj-page .sp-filter-label {
        font-size: 11.5px;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin-bottom: 4px;
        display: block;
    }
    .subj-page .sp-avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 50%;
        font-size: 11px;
        font-weight: 700;
        margin-right: 6px;
        vertical-align: middle;
        flex: 0 0 auto;
    }
    .subj-page .sp-teacher-row {
        display: flex;
        align-items: center;
        gap: 0;
        font-size: 13.5px;
        margin-bottom: 3px;
    }
    .subj-page .sp-teacher-row:last-child { margin-bottom: 0; }
    .subj-page .sp-more-teachers { position: relative; display: inline-block; margin-top: 2px; outline: none; }
    .subj-page .sp-more-link { font-size: 12px; font-weight: 600; color: var(--primary); cursor: default; }
    .subj-page .sp-more-link i { font-size: 9px; margin-left: 2px; }
    .subj-page .sp-more-pop {
        display: none; position: absolute; left: 0; top: 100%; z-index: 30; min-width: 220px;
        margin-top: 4px; padding: 10px 12px; background: var(--surface); border: 1px solid var(--border);
        border-radius: 10px; box-shadow: 0 10px 24px rgba(16, 24, 40, 0.14);
    }
    .subj-page .sp-more-teachers::after { content: ''; position: absolute; left: 0; right: 0; top: 100%; height: 6px; }
    .subj-page .sp-more-teachers:hover .sp-more-pop,
    .subj-page .sp-more-teachers:focus-within .sp-more-pop { display: block; }
    .subj-page .sp-more-pop .sp-teacher-row { white-space: nowrap; margin-bottom: 6px; }
    /* Last rows of the table: open upward so the list is not cut off at the bottom. */
    .subj-page tbody tr:nth-last-child(-n+2) .sp-more-pop { top: auto; bottom: 100%; margin: 0 0 4px; }
    .subj-page tbody tr:nth-last-child(-n+2) .sp-more-teachers::after { top: auto; bottom: 100%; }
    .subj-page .sp-pagination {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
        padding: 14px 4px 0 4px;
        border-top: 1px solid #EEF0F3;
        margin-top: 10px;
    }
    .subj-page .sp-pagination-info {
        font-size: 13px;
        color: var(--text-muted);
    }
    .subj-page .sp-pagination-btns {
        display: flex;
        gap: 4px;
    }
    .subj-page .sp-page-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
        height: 32px;
        padding: 0 8px;
        border-radius: 8px;
        border: 1px solid var(--border);
        color: var(--ink);
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        background: var(--surface);
    }
    .subj-page .sp-page-btn:hover { background: var(--surface-hover); color: var(--ink); }
    .subj-page .sp-page-btn.active {
        background: var(--primary);
        border-color: var(--primary);
        color: #fff;
    }
    .subj-page .sp-page-btn.disabled { opacity: 0.4; pointer-events: none; }
    .subj-page .sp-action-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        font-size: 16px;
        border-radius: 10px;
        border: 1px solid var(--border);
        color: var(--text-muted);
        background: var(--surface);
        margin-right: 6px;
        transition: background 0.15s, color 0.15s;
    }
    .subj-page .sp-action-icon:hover { background: #F2F4F7; color: var(--ink); }
    .subj-page .sp-action-icon.danger:hover { background: #FDECEF; color: #C23A57; }
    .subj-page .sp-action-icon.view:hover { background: #EAF1FE; color: #1957C2; }

    /* Time-slot picker (Assign Teacher + Set Scheduling): shows the selected
       teacher's free / occupied class periods for the chosen day/s and shift. */
    .subj-page .slot-picker { margin-bottom: 12px; }
    .subj-page .slot-picker-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px 12px; margin-bottom: 6px; }
    .subj-page .slot-picker-title { font-size: 13px; font-weight: 600; color: var(--text); }
    .subj-page .slot-legend { display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 11px; color: var(--text-muted); }
    .subj-page .slot-legend span::before { content: ''; display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 4px; vertical-align: -1px; border: 1px solid var(--border); background: #fff; }
    .subj-page .slot-legend .lg-free::before { border-color: #9AD5BC; background: var(--success-tint); }
    .subj-page .slot-legend .lg-sel::before { border-color: var(--primary); background: var(--primary); }
    .subj-page .slot-legend .lg-occ::before { border-color: #E4B4B4; background: var(--danger-tint); }
    .subj-page .slot-view { display: inline-flex; gap: 4px; margin-bottom: 6px; }
    .subj-page .slot-view button { font-size: 12px; padding: 2px 10px; border-radius: 999px; border: 1px solid var(--border); background: #fff; color: var(--text-muted); }
    .subj-page .slot-view button.active { background: var(--primary-soft); border-color: var(--primary); color: var(--primary); font-weight: 600; }
    .subj-page .slot-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 6px; }
    .subj-page .slot-btn { display: flex; flex-direction: column; align-items: flex-start; gap: 1px; text-align: left; padding: 6px 9px; line-height: 1.25;
        border: 1px solid #9AD5BC; background: var(--success-tint); color: var(--text); border-radius: var(--radius-sm); cursor: pointer; transition: box-shadow .12s, background .12s; }
    .subj-page .slot-btn .slot-time { font-size: 12.5px; font-weight: 600; }
    .subj-page .slot-btn .slot-state { font-size: 11px; color: var(--success); font-weight: 500; }
    .subj-page .slot-btn:hover { box-shadow: 0 0 0 2px rgba(91,95,239,0.18); }
    .subj-page .slot-btn.is-selected { background: var(--primary); border-color: var(--primary); color: #fff; }
    .subj-page .slot-btn.is-selected .slot-state { color: #fff; }
    .subj-page .slot-btn.is-occupied, .subj-page .slot-btn.is-section { background: var(--danger-tint); border-color: #E4B4B4; color: var(--text-muted); cursor: not-allowed; }
    .subj-page .slot-btn.is-occupied .slot-time, .subj-page .slot-btn.is-section .slot-time { text-decoration: line-through; font-weight: 500; }
    .subj-page .slot-btn.is-occupied .slot-state, .subj-page .slot-btn.is-section .slot-state { color: var(--danger); font-weight: 600; }
    .subj-page .slot-btn.is-section { background: var(--warning-tint); border-color: #E8CF9B; }
    .subj-page .slot-btn.is-section .slot-state { color: var(--warning); }
    .subj-page .slot-btn.is-break, .subj-page .slot-btn.is-idle { background: var(--surface-muted); border-color: var(--border); color: var(--text-muted); cursor: not-allowed; }
    .subj-page .slot-btn.is-break .slot-state, .subj-page .slot-btn.is-idle .slot-state { color: var(--text-muted); font-weight: 400; }
    .subj-page .slot-btn.is-occupied:hover, .subj-page .slot-btn.is-section:hover, .subj-page .slot-btn.is-break:hover, .subj-page .slot-btn.is-idle:hover { box-shadow: none; }
    .subj-page .slot-msg { font-size: 12px; color: var(--text-muted); margin-top: 6px; }
    .subj-page .slot-msg.is-warn { color: var(--danger); }
</style>

<div class="subj-page">

<div class="sp-header">
    <div class="sp-header-main">
        <div class="sp-header-icon"><i class="fas fa-book"></i></div>
        <div>
            <h2>Subject Management</h2>
            <p><?php echo htmlspecialchars($page_subtitle); ?></p>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
        <a href="add.php" class="sp-btn-gradient">
            <i class="fas fa-plus"></i> Add Subject
        </a>
    </div>
</div>

<!-- ============================================================ -->
<!-- GRADE LEVEL CARDS -->
<!-- ============================================================ -->
<div class="row g-3 mb-4">
    <?php 
    // Same per-grade colours as the Students and Teachers pages, so a grade
    // is recognisably the same colour wherever it appears in the system.
    $grade_palette = [
        'Grade 7'  => ['accent' => '#2E7D67', 'soft' => '#F1F8F5'],
        'Grade 8'  => ['accent' => '#356EA8', 'soft' => '#F2F6FA'],
        'Grade 9'  => ['accent' => '#A06A1B', 'soft' => '#FBF7EE'],
        'Grade 10' => ['accent' => '#5B5EA6', 'soft' => '#F4F3FA'],
        'Grade 11' => ['accent' => '#994C52', 'soft' => '#FAF2F3'],
        'Grade 12' => ['accent' => '#287A78', 'soft' => '#F0F8F7'],
    ];

    // Per-grade subject counts, so the cards read "N subjects" — the same
    // real numbers already computed above for the progress bars, just
    // surfaced here too instead of the section count.
    $grade_levels = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
    foreach ($grade_levels as $grade):
        $grade_subject_count = $subjects_by_grade_count[$grade] ?? 0;
        $is_active = ($selected_grade === $grade);
        $color = $grade_palette[$grade]['accent'] ?? '#667085';
        $soft  = $grade_palette[$grade]['soft'] ?? '#f8f9fb';
        $display = str_replace('Grade ', '', $grade);
    ?>
        <div class="col-md-4 col-lg-2">
            <div class="card grade-card <?php echo $is_active ? 'active' : 'border-0 shadow-sm'; ?>"
                 style="--accent: <?php echo $color; ?>; --accent-soft: <?php echo $soft; ?>;"
                 role="button" tabindex="0"
                 aria-label="Show subjects for <?php echo htmlspecialchars($grade); ?>"
                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
                 onclick="window.location.href='?grade=<?php echo urlencode($grade); ?>&strand=&section='">
                <div class="card-body text-center">
                    <div class="grade-icon">
                        <span class="grade-num"><?php echo $display; ?></span>
                    </div>
                    <div class="grade-label"><?php echo $grade; ?></div>
                    <div class="section-count">
                        <?php echo $grade_subject_count; ?> <?php echo $grade_subject_count == 1 ? 'Subject' : 'Subjects'; ?>
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
<div class="row g-3 mb-4">
    <?php
    $tiles = [
        ['Total Subjects', $subj_overview['total'], 'fa-book', '#e3f2fd', '#1565c0'],
        ['Junior High Subjects', $subj_overview['jhs'], 'fa-school', '#e8f5e9', '#2e7d32'],
        ['Senior High Subjects', $subj_overview['shs'], 'fa-graduation-cap', '#fff8e1', '#ef6c00'],
        ['Teacher Assignments', $subj_overview['assigned'], 'fa-user-check', '#f3e5f5', '#6a1b9a'],
    ];
    foreach ($tiles as $t): ?>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-3 h-100 sp-lift">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle d-flex align-items-center justify-content-center me-3"
                     style="width:52px;height:52px;font-size:20px;background:<?php echo $t[3]; ?>;color:<?php echo $t[4]; ?>;">
                    <i class="fas <?php echo $t[2]; ?>"></i>
                </div>
                <div>
                    <div class="text-muted text-uppercase" style="font-size:13px;letter-spacing:0.04em;"><?php echo $t[0]; ?></div>
                    <div class="fw-bold" style="font-size:28px;"><?php echo $t[1]; ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-3 h-100 sp-lift">
            <div class="card-header bg-white border-0 pt-3">
                <h5 class="mb-0"><i class="fas fa-chart-simple text-primary me-2"></i>Subjects per Grade Level</h5>
            </div>
            <div class="card-body pt-0">
                <?php
                $glist = ['Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12'];
                $maxc = 1;
                foreach ($glist as $g) { $maxc = max($maxc, $subjects_by_grade_count[$g] ?? 0); }
                foreach ($glist as $g):
                    $val = $subjects_by_grade_count[$g] ?? 0;
                    $pct = round(($val / $maxc) * 100);
                    // Same palette as the grade cards above.
                    $bar_color = $grade_palette[$g]['accent'] ?? '#356EA8';
                ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1" style="font-size:14px;">
                        <span class="fw-semibold"><?php echo $g; ?></span>
                        <span class="text-muted"><?php echo $val; ?> subject<?php echo $val == 1 ? '' : 's'; ?></span>
                    </div>
                    <div class="progress" style="height:10px;border-radius:5px;">
                        <div class="progress-bar" role="progressbar"
                             style="width:<?php echo $pct; ?>%; background: <?php echo $bar_color; ?>;"
                             aria-valuenow="<?php echo $val; ?>" aria-valuemin="0" aria-valuemax="<?php echo $maxc; ?>"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-3 h-100 sp-lift">
            <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0"><i class="fas fa-triangle-exclamation text-warning me-2"></i>Subjects Without Teacher</h5>
                <div class="d-flex align-items-center gap-2">
                    <form method="get" class="m-0">
                        <select name="ut_grade" class="form-select form-select-sm" style="font-size:14px;padding:6px 30px 6px 10px;width:auto;" onchange="this.form.submit();" aria-label="Filter subjects without teacher by grade level">
                            <option value="">All Grades</option>
                            <?php foreach ($ut_grade_options as $g_opt): ?>
                            <option value="<?php echo htmlspecialchars($g_opt); ?>" <?php echo $ut_grade === $g_opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($g_opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button></noscript>
                    </form>
                    <span class="badge bg-secondary" style="font-size:13px;padding:6px 12px;"><?php echo $unassigned_total; ?></span>
                </div>
            </div>
            <div class="card-body pt-0">
                <?php if (empty($unassigned)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-circle-check fa-2x mb-2 d-block text-success"></i>
                        <?php if ($ut_grade !== ''): ?>
                            Lahat ng subject sa <?php echo htmlspecialchars($ut_grade); ?> ay may assigned teacher para sa active school year.
                        <?php else: ?>
                            Lahat ng subject ay may assigned teacher para sa active school year.
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="text-uppercase text-muted">
                            <tr><th>Code</th><th>Subject</th><th>Grade</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($unassigned as $u): ?>
                            <tr>
                                <td class="text-muted"><?php echo htmlspecialchars($u['subject_code']); ?></td>
                                <td class="fw-semibold"><?php echo htmlspecialchars($u['subject_name']); ?></td>
                                <td><?php echo htmlspecialchars($u['grade_level']); ?><?php echo !empty($u['strand']) ? ' - ' . htmlspecialchars($u['strand'] === 'ASSH' ? 'ASSH Cluster' : $u['strand']) : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($unassigned_total > count($unassigned)): ?>
                    <div class="text-muted text-center mt-2" style="font-size:14px;">
                        Showing <?php echo count($unassigned); ?> of <?php echo $unassigned_total; ?>. Filter by grade to narrow the list.
                    </div>
                <?php endif; ?>
                <?php endif; ?>
                <div class="alert alert-light border mt-3 mb-0 small">
                    <i class="fas fa-circle-info text-primary me-1"></i>
                    Pumili ng grade level sa taas para i-manage ang subjects at teacher assignments per section.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- ALL SUBJECTS (real search/grade/type/status filters + pagination) -->
<!-- ============================================================ -->
<div class="card border-0 shadow-sm rounded-3 mb-4 sp-lift">
    <div class="card-header bg-white border-0 pt-3">
        <h5 class="mb-0"><i class="fas fa-chart-simple text-primary me-2"></i>All Subjects
            <span class="badge bg-secondary ms-2"><?php echo $as_total; ?></span></h5>
    </div>
    <div class="card-body pt-0">
        <div class="sp-filter-bar">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-4 col-6">
                    <label class="sp-filter-label">Search</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="q" class="form-control border-start-0"
                               value="<?php echo htmlspecialchars($subj_search_q); ?>"
                               placeholder="Search subject...">
                    </div>
                </div>
                <div class="col-md-2 col-6">
                    <label class="sp-filter-label">Grade</label>
                    <select name="fgrade" class="form-select">
                        <option value="">All Grades</option>
                        <?php foreach ($grade_levels as $g): ?>
                            <option value="<?php echo htmlspecialchars($g); ?>" <?php echo $subj_filter_grade === $g ? 'selected' : ''; ?>><?php echo htmlspecialchars($g); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-6">
                    <label class="sp-filter-label">Type</label>
                    <select name="ftype" class="form-select">
                        <option value="">All Types</option>
                        <?php foreach (getSelectableSubjectTypes() as $stype): ?>
                            <option value="<?php echo htmlspecialchars($stype); ?>" <?php echo $subj_filter_type === $stype ? 'selected' : ''; ?>><?php echo htmlspecialchars($stype); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-6">
                    <label class="sp-filter-label">Status</label>
                    <select name="fstatus" class="form-select">
                        <option value="">Active</option>
                        <option value="inactive" <?php echo $subj_filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="all" <?php echo $subj_filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                    </select>
                </div>
                <div class="col-md-1 col-6 d-flex gap-2">
                    <button class="btn btn-outline-secondary flex-grow-1" title="Apply filters"><i class="fas fa-filter"></i></button>
                </div>
                <?php if ($subj_search_q !== '' || $subj_filter_grade !== '' || $subj_filter_type !== '' || $subj_filter_status !== ''): ?>
                <div class="col-12">
                    <a href="?" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times me-1"></i>Clear filters</a>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($all_subjects_page)): ?>
            <div class="text-center text-muted py-4">
                <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                No subjects matched your search/filters.
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="text-uppercase text-muted">
                    <tr>
                        <th>#</th><th>Code</th><th>Subject Name</th><th>Grade Level</th><th>Type</th>
                        <th>Units</th><th>Teacher</th><th>Status</th><th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php $as_counter = ($as_page - 1) * $as_per_page + 1; ?>
                <?php foreach ($all_subjects_page as $asub):
                    $tcolor = type_badge_color($asub['subject_type'], $type_palette);
                    $detail = json_encode([
                        'code' => $asub['subject_code'],
                        'name' => $asub['subject_name'],
                        'grade' => $asub['grade_level'],
                        'strand' => $asub['strand'],
                        'type' => $asub['subject_type'],
                        'units' => $asub['units'],
                        'status' => $asub['is_active'] ? 'Active' : 'Inactive',
                        'teachers' => array_map(function ($t) { return $t['last_name'] . ', ' . $t['first_name']; }, $asub['teachers']),
                    ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG);
                ?>
                    <tr>
                        <td><?php echo $as_counter++; ?></td>
                        <td class="text-muted"><?php echo htmlspecialchars($asub['subject_code']); ?></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($asub['subject_name']); ?></td>
                        <td><?php echo htmlspecialchars($asub['grade_level']); ?><?php echo !empty($asub['strand']) ? ' - ' . htmlspecialchars($asub['strand'] === 'ASSH' ? 'ASSH Cluster' : $asub['strand']) : ''; ?></td>
                        <td><span class="badge" style="background:<?php echo $tcolor['bg']; ?>;color:<?php echo $tcolor['fg']; ?>;"><?php echo htmlspecialchars($asub['subject_type']); ?></span></td>
                        <td><?php echo (int)$asub['units']; ?></td>
                        <td>
                            <?php if (!empty($asub['teachers'])): ?>
                                <?php
                                    // One teacher in the row; the rest appear when the pointer is over "See more".
                                    $tt = $asub['teachers'][0];
                                    $tinit = strtoupper(substr($tt['first_name'], 0, 1) . substr($tt['last_name'], 0, 1));
                                ?>
                                <div class="sp-teacher-row">
                                    <span class="sp-avatar" style="background:<?php echo $tcolor['bg']; ?>;color:<?php echo $tcolor['fg']; ?>;"><?php echo htmlspecialchars($tinit); ?></span>
                                    <?php echo htmlspecialchars($tt['last_name'] . ', ' . $tt['first_name']); ?>
                                </div>
                                <?php if (count($asub['teachers']) > 1): ?>
                                    <div class="sp-more-teachers" tabindex="0">
                                        <span class="sp-more-link">See more (+<?php echo count($asub['teachers']) - 1; ?>) <i class="fas fa-chevron-down"></i></span>
                                        <div class="sp-more-pop">
                                            <?php foreach (array_slice($asub['teachers'], 1) as $tt):
                                                $tinit = strtoupper(substr($tt['first_name'], 0, 1) . substr($tt['last_name'], 0, 1));
                                            ?>
                                            <div class="sp-teacher-row">
                                                <span class="sp-avatar" style="background:<?php echo $tcolor['bg']; ?>;color:<?php echo $tcolor['fg']; ?>;"><?php echo htmlspecialchars($tinit); ?></span>
                                                <?php echo htmlspecialchars($tt['last_name'] . ', ' . $tt['first_name']); ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">No teacher assigned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $asub['is_active'] ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $asub['is_active'] ? 'Active' : 'Inactive'; ?></span>
                        </td>
                        <td class="text-center" style="white-space:nowrap;">
                            <button type="button" class="sp-action-icon view" title="View" data-bs-toggle="modal" data-bs-target="#subjectViewModal"
                                    data-detail='<?php echo $detail; ?>'><i class="fas fa-eye"></i></button>
                            <a href="edit.php?code=<?php echo urlencode($asub['subject_code']); ?>" class="sp-action-icon" title="Edit"><i class="fas fa-edit"></i></a>
                            <a href="?grade=<?php echo urlencode($asub['grade_level']); ?>" class="sp-action-icon" title="Assign Teacher (open this grade's sections)"><i class="fas fa-user-plus"></i></a>
                            <a href="delete.php?code=<?php echo urlencode($asub['subject_code']); ?>" class="sp-action-icon danger" title="Delete"
                               onclick="return confirm('Delete subject <?php echo htmlspecialchars(addslashes($asub['subject_name'])); ?>? This cannot be undone.');"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($as_total_pages > 1): ?>
        <div class="sp-pagination">
            <span class="sp-pagination-info">Showing <?php echo count($all_subjects_page); ?> of <?php echo $as_total; ?> subjects</span>
            <div class="sp-pagination-btns">
                <?php $qs = $_GET; $qs['page'] = max(1, $as_page - 1); ?>
                <a class="sp-page-btn <?php echo $as_page <= 1 ? 'disabled' : ''; ?>" href="?<?php echo http_build_query($qs); ?>"><i class="fas fa-chevron-left"></i></a>
                <?php for ($p = 1; $p <= $as_total_pages; $p++): $qs['page'] = $p; ?>
                    <a class="sp-page-btn <?php echo $p === $as_page ? 'active' : ''; ?>" href="?<?php echo http_build_query($qs); ?>"><?php echo $p; ?></a>
                <?php endfor; ?>
                <?php $qs['page'] = min($as_total_pages, $as_page + 1); ?>
                <a class="sp-page-btn <?php echo $as_page >= $as_total_pages ? 'disabled' : ''; ?>" href="?<?php echo http_build_query($qs); ?>"><i class="fas fa-chevron-right"></i></a>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Subject detail view — populated client-side from the row's own data,
     since no separate "view subject" page exists in this system. -->
<div class="modal fade" id="subjectViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-book me-2"></i>Subject Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="subjectViewBody" style="font-size:14px;">
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var modalEl = document.getElementById('subjectViewModal');
    if (!modalEl) return;
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = (s === null || s === undefined) ? '' : String(s);
        return d.innerHTML;
    }
    modalEl.addEventListener('show.bs.modal', function (evt) {
        var btn = evt.relatedTarget;
        if (!btn) return;
        var detail = {};
        try { detail = JSON.parse(btn.getAttribute('data-detail') || '{}'); } catch (e) {}
        var teacherList = (detail.teachers && detail.teachers.length)
            ? detail.teachers.map(esc).join(', ')
            : 'No teacher assigned';
        var body = document.getElementById('subjectViewBody');
        body.innerHTML =
            '<div class="row g-2">' +
            '<div class="col-6"><strong>Code:</strong> ' + esc(detail.code) + '</div>' +
            '<div class="col-6"><strong>Status:</strong> ' + esc(detail.status) + '</div>' +
            '<div class="col-12"><strong>Name:</strong> ' + esc(detail.name) + '</div>' +
            '<div class="col-6"><strong>Grade:</strong> ' + esc(detail.grade) + (detail.strand ? ' - ' + esc(detail.strand) : '') + '</div>' +
            '<div class="col-6"><strong>Type:</strong> ' + esc(detail.type) + '</div>' +
            '<div class="col-6"><strong>Units:</strong> ' + esc(detail.units) + '</div>' +
            '<div class="col-12"><strong>Teacher(s):</strong> ' + teacherList + '</div>' +
            '</div>';
    });
})();
</script>
<?php endif; ?>


<!-- ============================================================ -->
<!-- STRAND FILTER FOR SHS (Grade 11 & 12) ONLY -->
<!-- ============================================================ -->
<?php if ($selected_grade && ($selected_grade === 'Grade 11' || $selected_grade === 'Grade 12') && !$selected_strand): ?>
    <div class="card shadow-sm rounded-3 mb-4">
        <div class="card-header bg-white border-0 pt-3">
            <h5 class="mb-0">
                <i class="fas fa-layer-group text-primary me-2"></i>
                Select Strand for <?php echo $selected_grade; ?>
            </h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php
                $strands = $active_shs_strands;
                foreach ($strands as $strand):
                    $strand_sections = 0;
                    foreach ($sections_by_grade[$selected_grade] ?? [] as $sec) {
                        if ($sec['strand'] === $strand) {
                            $strand_sections++;
                        }
                    }
                ?>
                    <div class="col-md-3">
                        <div class="card strand-card border-0 shadow-sm" 
                             onclick="window.location.href='?grade=<?php echo urlencode($selected_grade); ?>&strand=<?php echo $strand; ?>&section='">
                            <div class="card-body text-center">
                                <div class="strand-icon <?php echo $strand === 'GAS' ? 'text-info' : 'text-primary'; ?>">
                                    <i class="fas <?php echo $strand === 'GAS' ? 'fa-flask' : 'fa-users'; ?>"></i>
                                </div>
                                <div class="strand-label"><?php echo $strand === 'ASSH' ? 'ASSH Cluster' : $strand; ?></div>
                                <div class="strand-sub"><?php echo $strand_sections; ?> sections</div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- SECTIONS UNDER SELECTED GRADE & STRAND -->
<!-- ============================================================ -->
<?php if ($selected_grade && $selected_strand && !empty($filtered_sections)): ?>
    <div class="card shadow-sm rounded-3 mb-4">
        <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-layer-group text-primary me-2"></i>
                Sections under <?php echo $selected_grade; ?> - <?php echo $selected_strand === 'ASSH' ? 'ASSH Cluster' : $selected_strand; ?>
                <span class="badge bg-secondary ms-2"><?php echo count($filtered_sections); ?></span>
            </h5>
            <div>
                <a href="?grade=<?php echo urlencode($selected_grade); ?>&strand=&section=0" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Strands
                </a>
                <a href="?grade=0&strand=&section=0" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Clear All
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-2">
                <?php foreach ($filtered_sections as $sec): 
                    $is_selected = ($selected_section == $sec['section_id']);
                ?>
                    <div class="col-md-3 col-lg-2">
                        <a href="?grade=<?php echo urlencode($selected_grade); ?>&strand=<?php echo urlencode($selected_strand); ?>&section=<?php echo $sec['section_id']; ?>#subjects-section" 
                           class="text-decoration-none">
                            <div class="card section-card <?php echo $is_selected ? 'active' : 'border-0 shadow-sm'; ?>">
                                <div class="card-body text-center py-3">
                                    <div class="section-name <?php echo $is_selected ? 'text-primary' : 'text-dark'; ?>">
                                        <?php echo htmlspecialchars($sec['section_name']); ?>
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
<!-- SECTIONS FOR JHS (Grade 7-10) - NO STRAND DISPLAY -->
<!-- ============================================================ -->
<?php if ($selected_grade && !($selected_grade === 'Grade 11' || $selected_grade === 'Grade 12') && !empty($filtered_sections)): ?>
    <div class="card shadow-sm rounded-3 mb-4">
        <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-layer-group text-primary me-2"></i>
                Sections under <?php echo $selected_grade; ?>
                <span class="badge bg-secondary ms-2"><?php echo count($filtered_sections); ?></span>
            </h5>
            <div>
                <a href="?grade=0&strand=&section=0" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Clear
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-2">
                <?php foreach ($filtered_sections as $sec): 
                    $is_selected = ($selected_section == $sec['section_id']);
                ?>
                    <div class="col-md-3 col-lg-2">
                        <a href="?grade=<?php echo urlencode($selected_grade); ?>&strand=&section=<?php echo $sec['section_id']; ?>#subjects-section" 
                           class="text-decoration-none">
                            <div class="card section-card <?php echo $is_selected ? 'active' : 'border-0 shadow-sm'; ?>">
                                <div class="card-body text-center py-3">
                                    <div class="section-name <?php echo $is_selected ? 'text-primary' : 'text-dark'; ?>">
                                        <?php echo htmlspecialchars($sec['section_name']); ?>
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
<!-- SUBJECTS FOR SELECTED SECTION - STRAND COLUMN HIDDEN FOR JHS -->
<!-- ============================================================ -->
<?php if ($selected_section && $section_info): 
    $is_shs = ($section_info['grade_level'] === 'Grade 11' || $section_info['grade_level'] === 'Grade 12');
?>
    <div class="card shadow-sm rounded-3" id="subjects-section" tabindex="-1" style="scroll-margin-top: 20px;">
        <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-list text-primary me-2"></i>
                Subjects in <?php echo htmlspecialchars($section_info['section_name']); ?>
                <span class="badge bg-secondary ms-2"><?php echo count($section_subjects); ?></span>
            </h5>
            <div>
                <?php if ($selected_grade && ($selected_grade === 'Grade 11' || $selected_grade === 'Grade 12')): ?>
                    <a href="?grade=<?php echo urlencode($selected_grade); ?>&strand=<?php echo urlencode($selected_strand); ?>&section=0" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Sections
                    </a>
                <?php else: ?>
                    <a href="?grade=<?php echo urlencode($selected_grade); ?>&strand=&section=0" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Sections
                    </a>
                <?php endif; ?>
                <a href="?grade=0&strand=&section=0" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Clear
                </a>
            </div>
        </div>
        <div class="card-body">
            <?php if (count($section_subjects) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Subject Code</th>
                                <th>Subject Name</th>
                                <?php if ($is_shs): ?>
                                    <th>Strand</th>
                                <?php endif; ?>
                                <th>Assigned Teacher</th>
                                <th>Specialization</th>
                                <th>Status</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; ?>
                            <?php foreach ($section_subjects as $subject): 
                                $has_teacher = !empty($subject['teacher_id']);
                                $teacher_name = $has_teacher ? $subject['teacher_first'] . ' ' . $subject['teacher_last'] : 'Not Assigned';
                                $specialization = $subject['specialization'] ?? 'N/A';
                            ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($subject['subject_code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                    <?php if ($is_shs): ?>
                                        <td>
                                            <?php if (!empty($subject['strand'])): ?>
                                                <span class="badge <?php echo $subject['strand'] == 'GAS' ? 'bg-info' : 'bg-primary'; ?>">
                                                    <?php echo htmlspecialchars(normalizeStrandKey($subject['strand']) === 'ASSH' ? 'ASSH Cluster' : $subject['strand']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <?php if ($has_teacher): ?>
                                            <span class="teacher-badge">
                                                <i class="fas fa-user-check text-success me-1"></i>
                                                <?php echo htmlspecialchars($teacher_name); ?>
                                            </span>
                                            <?php $saved_schedule = $section_schedule_map[(int)$subject['ts_id']] ?? []; ?>
                                            <?php if ($saved_schedule): ?>
                                                <?php $day_labels = [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri']; ?>
                                                <div class="small text-muted mt-1">
                                                    <?php echo htmlspecialchars(implode('-', array_map(function ($row) use ($day_labels) { return $day_labels[(int)$row['day_of_week']] ?? ''; }, $saved_schedule))); ?>
                                                    &middot; <?php echo date('g:i A', strtotime($saved_schedule[0]['start_time'])); ?>–<?php echo date('g:i A', strtotime($saved_schedule[0]['end_time'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="teacher-badge no-teacher">
                                                <i class="fas fa-user-times text-danger me-1"></i>
                                                Not Assigned
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($has_teacher && $specialization != 'N/A'): ?>
                                            <span class="badge bg-light text-dark border">
                                                <?php echo htmlspecialchars($specialization); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($has_teacher): ?>
                                            <span class="badge bg-success">Assigned</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($has_teacher): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary me-1 js-open-assignment"
                                                    data-bs-toggle="modal" data-bs-target="#assignModal"
                                                    data-teacher-id="<?php echo (int)$subject['teacher_id']; ?>"
                                                    data-ts-id="<?php echo (int)$subject['ts_id']; ?>"
                                                    data-subject-code="<?php echo htmlspecialchars($subject['subject_code']); ?>"
                                                    data-subject-name="<?php echo htmlspecialchars($subject['subject_name']); ?>"
                                                    data-section-id="<?php echo $selected_section; ?>"
                                                    data-grade-level="<?php echo htmlspecialchars($selected_grade); ?>"
                                                    data-strand="<?php echo htmlspecialchars($selected_strand); ?>"
                                                    title="Edit Assignment">
                                                <i class="fas fa-pen"></i> Edit Assignment
                                            </button>
                                            <a href="remove_from_section.php?subject=<?php echo urlencode($subject['subject_code']); ?>&section=<?php echo $selected_section; ?>&grade=<?php echo urlencode($selected_grade); ?>&strand=<?php echo urlencode($selected_strand); ?>" 
                                               class="btn btn-sm btn-outline-danger"
                                               onclick="return confirm('Unassign <?php echo htmlspecialchars($subject['subject_name']); ?> from this section?');"
                                               title="Unassign Teacher">
                                                <i class="fas fa-user-minus"></i> Unassign
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-primary" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#assignModal"
                                                    data-subject-code="<?php echo htmlspecialchars($subject['subject_code']); ?>"
                                                    data-subject-name="<?php echo htmlspecialchars($subject['subject_name']); ?>"
                                                    data-section-id="<?php echo $selected_section; ?>"
                                                    data-grade-level="<?php echo htmlspecialchars($selected_grade); ?>"
                                                    data-strand="<?php echo htmlspecialchars($selected_strand); ?>">
                                                <i class="fas fa-user-plus"></i> Assign
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-subjects">
                    <i class="fas fa-book fa-3x d-block mb-3 text-muted"></i>
                    <h5>No subjects found for this section</h5>
                    <p class="text-muted">Please ensure subjects are added to the system for <?php echo $section_info['grade_level']; ?>.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Set Scheduling Modal: schedules existing teacher_subjects assignments only -->
    <div class="modal fade" id="scheduleModal" tabindex="-1" aria-labelledby="scheduleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="scheduleModalLabel"><i class="fas fa-calendar-alt text-primary me-2"></i>Set Scheduling</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border mb-3 py-2">
                        <strong>Flow:</strong> Assign Teacher first, then set the schedule here. Only existing teacher-subject-section assignments are listed.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="schedule_teacher_id">Teacher</label>
                            <select class="form-select" id="schedule_teacher_id">
                                <option value="">-- Select Teacher --</option>
                                <?php foreach ($all_teachers as $t): ?>
                                    <option value="<?php echo (int)$t['user_id']; ?>"><?php echo htmlspecialchars($t['last_name'] . ', ' . $t['first_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="schedule_assignment_id">Assigned Subject / Section</label>
                            <select class="form-select" id="schedule_assignment_id" disabled>
                                <option value="">-- Select teacher first --</option>
                            </select>
                        </div>
                    </div>

                    <div id="schedule_for_box" class="rounded-3 border bg-light p-3 mt-3 d-none">
                        <div class="small text-muted">Setting schedule for:</div>
                        <div class="fw-bold fs-5" id="schedule_for_text">—</div>
                        <div class="text-muted" id="schedule_subject_text">—</div>
                    </div>

                    <div id="schedule_fields" class="d-none mt-3">
                        <label class="form-label fw-semibold">Day/s</label>
                        <div class="d-flex flex-wrap gap-2 mb-3" id="schedule_days">
                            <?php foreach ([1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday'] as $dn=>$dayName): ?>
                                <label class="btn btn-outline-secondary btn-sm">
                                    <input type="checkbox" class="form-check-input me-1 schedule-day" value="<?php echo $dn; ?>"> <?php echo $dayName; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="slot-picker" id="schedule_slot_picker">
                            <div class="slot-picker-head">
                                <span class="slot-picker-title"><i class="fas fa-clock text-primary me-1"></i>Available time slots</span>
                                <span class="slot-legend"><span class="lg-free">Free</span><span class="lg-sel">Selected</span><span class="lg-occ">Occupied</span></span>
                            </div>
                            <div class="slot-view d-none"></div>
                            <div class="slot-grid"></div>
                            <div class="slot-msg"></div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="schedule_start_time">Start Time</label>
                                <input type="time" class="form-control" id="schedule_start_time">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="schedule_end_time">End Time</label>
                                <input type="time" class="form-control" id="schedule_end_time">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="schedule_shift">Shift</label>
                                <select class="form-select" id="schedule_shift">
                                    <option value="AM">AM Shift</option>
                                    <option value="PM">PM Shift</option>
                                    <option value="SHS">SHS</option>
                                </select>
                                <div class="form-text" id="schedule_shift_help"></div>
                            </div>
                        </div>
                        <div id="schedule_validation" class="alert mt-3 mb-0 d-none" role="alert"></div>
                        <div id="schedule_existing" class="mt-3 small text-muted"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="save_schedule_btn" disabled><i class="fas fa-save me-1"></i> Save Schedule</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Assign Teacher Modal -->
    <div class="modal fade" id="assignModal" tabindex="-1" aria-labelledby="assignModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="assignment_api.php" method="POST" id="assignmentForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" id="modal_assignment_action" value="assign">
                    <input type="hidden" name="ts_id" id="modal_ts_id" value="0">
                    <input type="hidden" name="subject_code" id="modal_subject_code">
                    <input type="hidden" name="section_id" id="modal_section_id">
                    <input type="hidden" name="grade_level" id="modal_grade_level">
                    <input type="hidden" name="strand" id="modal_strand">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-user-plus text-primary me-2"></i>Assign Teacher</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Assign a teacher to <strong id="modal_subject_display"></strong>:</p>
                        <div class="mb-3">
                            <label for="teacher_id" class="form-label">Select Teacher</label>
                            <select name="teacher_id" id="teacher_id" class="form-select" required>
                                <option value="">-- Choose a Teacher --</option>
                                <?php foreach ($all_teachers as $t): ?>
                                    <option value="<?php echo $t['user_id']; ?>">
                                        <?php echo htmlspecialchars($t['last_name'] . ', ' . $t['first_name']); ?>
                                        <?php if (!empty($t['specialization'])): ?>
                                            (<?php echo htmlspecialchars($t['specialization']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="border rounded-3 p-3 bg-light">
                            <div class="fw-semibold mb-2"><i class="fas fa-calendar-alt text-primary me-1"></i>Class Schedule</div>
                            <label class="form-label mb-1">Select Day/s</label>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <label class="btn btn-outline-secondary btn-sm"><input type="checkbox" class="form-check-input me-1 modal-day" name="days[]" value="1"> Monday</label>
                                <label class="btn btn-outline-secondary btn-sm"><input type="checkbox" class="form-check-input me-1 modal-day" name="days[]" value="2"> Tuesday</label>
                                <label class="btn btn-outline-secondary btn-sm"><input type="checkbox" class="form-check-input me-1 modal-day" name="days[]" value="3"> Wednesday</label>
                                <label class="btn btn-outline-secondary btn-sm"><input type="checkbox" class="form-check-input me-1 modal-day" name="days[]" value="4"> Thursday</label>
                                <label class="btn btn-outline-secondary btn-sm"><input type="checkbox" class="form-check-input me-1 modal-day" name="days[]" value="5"> Friday</label>
                                <label class="btn btn-outline-primary btn-sm"><input type="checkbox" class="form-check-input me-1" id="modal_weekdays"> Mon–Fri</label>
                            </div>
                            <div class="slot-picker" id="assign_slot_picker">
                                <div class="slot-picker-head">
                                    <span class="slot-picker-title"><i class="fas fa-clock text-primary me-1"></i>Available time slots</span>
                                    <span class="slot-legend"><span class="lg-free">Free</span><span class="lg-sel">Selected</span><span class="lg-occ">Occupied</span></span>
                                </div>
                                <div class="slot-view d-none"></div>
                                <div class="slot-grid"></div>
                                <div class="slot-msg"></div>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-4"><label class="form-label mb-1" for="modal_start_time">Start Time</label><input type="time" class="form-control" name="start_time" id="modal_start_time" required></div>
                                <div class="col-md-4"><label class="form-label mb-1" for="modal_end_time">End Time</label><input type="time" class="form-control" name="end_time" id="modal_end_time" required></div>
                                <div class="col-md-4"><label class="form-label mb-1" for="modal_shift">Shift</label><input type="text" class="form-control" id="modal_shift" name="shift" readonly></div>
                            </div>
                            <div class="form-text" id="modal_duration_help">Grades 7–10 use 45-minute class periods. End time is suggested automatically and remains editable.</div>
                            <div id="assignment_validation" class="alert d-none mt-3 mb-0 py-2"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="save_assignment_btn"><i class="fas fa-check me-1"></i> Save Assignment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // ---------------- Time-slot picker (shared by both modals) ----------------
    // Shows the selected teacher's class periods for the chosen day/s + shift.
    // Occupied periods (teacher already has a class, or the section is taken) are
    // marked and cannot be selected; clicking free periods fills Start/End Time.
    function createSlotPicker(cfg) {
        var root = cfg.root;
        if (!root) return { refresh: function () {}, sync: function () {}, reset: function () {} };
        var grid = root.querySelector('.slot-grid'), msg = root.querySelector('.slot-msg'), viewBox = root.querySelector('.slot-view');
        var slots = [], ready = false, seq = 0, timer = null, viewShift = '', shiftName = '';
        var SHIFT_LABEL = { AM: 'AM Shift', PM: 'PM Shift', SHS: 'SHS hours' };

        function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
        function toMin(hm) { if (!hm) return null; var p = String(hm).split(':'); return parseInt(p[0], 10) * 60 + parseInt(p[1] || '0', 10); }
        function fmt12(hm) { var m = toMin(hm), h = Math.floor(m / 60), mm = m % 60; return ((h % 12) || 12) + ':' + String(mm).padStart(2, '0') + ' ' + (h < 12 ? 'AM' : 'PM'); }
        function rangeLabel(a, b) { var x = fmt12(a), y = fmt12(b); if (x.slice(-2) === y.slice(-2)) x = x.slice(0, -3); return x + ' – ' + y; }
        function setMsg(text, warn) { msg.textContent = text || ''; msg.className = 'slot-msg' + (warn ? ' is-warn' : ''); }
        function freeSlot(i) { return slots[i] && slots[i].status === 'free'; }

        function overlapping() {
            var s = toMin(cfg.startEl.value), e = toMin(cfg.endEl.value), out = [];
            if (s === null || e === null || e <= s) return out;
            slots.forEach(function (sl, i) { if (sl.status !== 'break' && toMin(sl.start) < e && toMin(sl.end) > s) out.push(i); });
            return out;
        }
        function sync() {
            var cur = ready ? overlapping() : [], clash = [];
            var buttons = grid.querySelectorAll('.slot-btn');
            buttons.forEach(function (b, i) { b.classList.toggle('is-selected', cur.indexOf(i) >= 0 && freeSlot(i)); });
            cur.forEach(function (i) { if (!freeSlot(i)) clash.push(i); });
            if (!slots.length) return;
            if (!ready) { setMsg('Select a teacher and at least one day to see which time slots are free.'); return; }
            if (clash.length) { setMsg('The selected time overlaps an occupied slot (' + clash.map(function (i) { return slots[i].label; }).join(', ') + '). Choose a free slot.', true); return; }
            if (cur.length) { setMsg('Selected: ' + rangeLabel(cfg.startEl.value, cfg.endEl.value)); return; }
            if (!slots.some(function (sl) { return sl.status === 'free'; })) { setMsg('No free time slots left for this teacher on the selected day/s' + (shiftName ? ' in the ' + (SHIFT_LABEL[shiftName] || shiftName) : '') + '.', true); return; }
            setMsg('Click a free slot to set the time. Click the next free slot to make a longer class period.');
        }
        function render(data) {
            slots = data.slots || []; ready = !!data.ready; shiftName = data.shift || '';
            if (cfg.viewToggle && (data.shifts || []).length > 1) {
                viewShift = data.shift;
                viewBox.innerHTML = data.shifts.map(function (sh) { return '<button type="button" data-shift="' + sh + '" class="' + (sh === data.shift ? 'active' : '') + '">' + esc(SHIFT_LABEL[sh] || sh) + '</button>'; }).join('');
                viewBox.classList.remove('d-none');
            } else { viewBox.classList.add('d-none'); viewBox.innerHTML = ''; }
            grid.innerHTML = slots.map(function (sl, i) {
                var cls = '', state = 'Free', title = sl.detail || '';
                if (sl.status === 'break') { cls = 'is-break'; state = 'Break'; }
                else if (!ready) { cls = 'is-idle'; state = '—'; title = 'Select a teacher and day/s first'; }
                else if (sl.status === 'occupied') { cls = 'is-occupied'; state = 'Occupied'; }
                else if (sl.status === 'section') { cls = 'is-section'; state = 'Section busy'; }
                return '<button type="button" class="slot-btn ' + cls + '" data-i="' + i + '" title="' + esc(title) + '" aria-disabled="' + (cls ? 'true' : 'false') + '">'
                    + '<span class="slot-time">' + esc(sl.label) + '</span><span class="slot-state">' + state + '</span></button>';
            }).join('');
            sync();
        }
        function refresh() {
            clearTimeout(timer);
            timer = setTimeout(function () {
                var st = cfg.getState();
                if (!st || !st.sectionId) { grid.innerHTML = ''; viewBox.classList.add('d-none'); setMsg(''); slots = []; return; }
                var mine = ++seq;
                var url = 'schedule_api.php?action=availability&teacher_id=' + encodeURIComponent(st.teacherId || '') + '&section_id=' + encodeURIComponent(st.sectionId)
                    + '&ts_id=' + encodeURIComponent(st.tsId || '0') + '&days=' + encodeURIComponent((st.days || []).join(',')) + '&shift=' + encodeURIComponent(st.shift || '');
                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) { if (mine !== seq) return; if (!data.ok) { grid.innerHTML = ''; setMsg(data.message || 'Could not load time slots.', true); return; } render(data); })
                    .catch(function () { if (mine !== seq) return; setMsg('Could not load available time slots. You can still type the time; saving is validated on the server.', true); });
            }, 120);
        }
        function reset() { slots = []; ready = false; viewShift = ''; grid.innerHTML = ''; viewBox.classList.add('d-none'); viewBox.innerHTML = ''; setMsg(''); }
        function fire(el) { el.dispatchEvent(new Event('change', { bubbles: true })); }
        function setRange(a, b) { cfg.startEl.value = slots[a].start; cfg.endEl.value = slots[b].end; fire(cfg.startEl); fire(cfg.endEl); }

        grid.addEventListener('click', function (ev) {
            var b = ev.target.closest('.slot-btn');
            if (!b || !ready) return;
            var i = parseInt(b.getAttribute('data-i'), 10);
            if (!freeSlot(i)) return;                       // Occupied / Section busy / Break: not selectable
            var cur = overlapping();
            var curFree = cur.length && cur.every(freeSlot);
            if (curFree) {
                var first = cur[0], last = cur[cur.length - 1];
                if (cur.length === 1 && first === i) { cfg.startEl.value = ''; cfg.endEl.value = ''; fire(cfg.startEl); fire(cfg.endEl); return; }
                if (slots[i].start === slots[last].end) { setRange(first, i); return; }      // extend after
                if (slots[i].end === slots[first].start) { setRange(i, last); return; }      // extend before
            }
            setRange(i, i);
        });
        viewBox.addEventListener('click', function (ev) {
            var b = ev.target.closest('button[data-shift]');
            if (!b) return;
            viewShift = b.getAttribute('data-shift');
            cfg.startEl.value = ''; cfg.endEl.value = ''; fire(cfg.startEl); fire(cfg.endEl);
            refresh();
        });
        [cfg.startEl, cfg.endEl].forEach(function (el) { if (el) el.addEventListener('change', sync); });
        return { refresh: refresh, sync: sync, reset: reset, getViewShift: function () { return viewShift; } };
    }

    var assignModal = document.getElementById('assignModal');
    if (assignModal) {
        assignModal.addEventListener('show.bs.modal', function(event) {
            var button = event.relatedTarget;
            var subjectCode = button.getAttribute('data-subject-code');
            var subjectName = button.getAttribute('data-subject-name');
            var sectionId = button.getAttribute('data-section-id');
            var gradeLevel = button.getAttribute('data-grade-level');
            var strand = button.getAttribute('data-strand');
            var teacherId = button.getAttribute('data-teacher-id') || '';
            var tsId = button.getAttribute('data-ts-id') || '0';

            document.getElementById('modal_subject_code').value = subjectCode;
            document.getElementById('modal_section_id').value = sectionId;
            document.getElementById('modal_grade_level').value = gradeLevel;
            document.getElementById('modal_strand').value = strand;
            document.getElementById('modal_subject_display').textContent = subjectName + ' (' + subjectCode + ')';
            document.getElementById('modal_ts_id').value = tsId;
            document.getElementById('modal_assignment_action').value = tsId === '0' ? 'assign' : 'edit';
            document.getElementById('save_assignment_btn').innerHTML = tsId === '0' ? '<i class="fas fa-check me-1"></i> Assign + Save Schedule' : '<i class="fas fa-save me-1"></i> Save Assignment';
            document.querySelectorAll('.modal-day').forEach(function (day) { day.checked = false; });
            document.getElementById('modal_weekdays').checked = false;
            document.getElementById('modal_start_time').value = '';
            document.getElementById('modal_end_time').value = '';
            document.getElementById('modal_shift').value = ['Grade 7','Grade 8'].indexOf(gradeLevel) >= 0 ? 'AM Shift' : (['Grade 9','Grade 10'].indexOf(gradeLevel) >= 0 ? 'PM Shift' : 'SHS');
            // Editing: show the teacher that is currently assigned (was left on whatever was picked last).
            if (tsId !== '0' && teacherId) document.getElementById('teacher_id').value = teacherId;
            assignSlots.reset();
            assignSlots.refresh();
            if (tsId !== '0') {
                fetch('schedule_api.php?teacher_id=' + encodeURIComponent(teacherId), {headers:{'X-Requested-With':'XMLHttpRequest'}})
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var assignment = (data.assignments || []).find(function (item) { return String(item.ts_id) === String(tsId); });
                        if (!assignment || !assignment.schedules || !assignment.schedules.length) return;
                        assignment.schedules.forEach(function (row) { var day = document.querySelector('.modal-day[value="' + row.day_of_week + '"]'); if (day) day.checked = true; });
                        document.getElementById('modal_start_time').value = assignment.schedules[0].start_time || '';
                        document.getElementById('modal_end_time').value = assignment.schedules[0].end_time || '';
                        document.getElementById('modal_weekdays').checked = assignment.schedules.length === 5;
                        assignSlots.refresh();   // days were just filled in: re-check availability
                    });
            }
        });
    }

    var assignmentForm = document.getElementById('assignmentForm');
    var assignmentValidation = document.getElementById('assignment_validation');
    var assignmentStart = document.getElementById('modal_start_time');
    var assignmentEnd = document.getElementById('modal_end_time');
    var assignmentDays = document.querySelectorAll('.modal-day');
    var weekdays = document.getElementById('modal_weekdays');
    var assignmentTimer;
    // Time-slot picker: availability of the selected teacher for the selected day/s (+ shift for Senior High).
    var assignSlots = createSlotPicker({
        root: document.getElementById('assign_slot_picker'),
        startEl: assignmentStart, endEl: assignmentEnd, viewToggle: true,
        getState: function () {
            return {
                teacherId: document.getElementById('teacher_id').value,
                sectionId: document.getElementById('modal_section_id').value,
                tsId: document.getElementById('modal_ts_id').value,
                days: Array.from(assignmentDays).filter(function (d) { return d.checked; }).map(function (d) { return d.value; }),
                shift: assignSlots.getViewShift()
            };
        }
    });
    function assignmentPayload(validateOnly) {
        return {
            csrf_token: assignmentForm.querySelector('[name="csrf_token"]').value,
            action: document.getElementById('modal_assignment_action').value,
            ts_id: document.getElementById('modal_ts_id').value,
            teacher_id: document.getElementById('teacher_id').value,
            subject_code: document.getElementById('modal_subject_code').value,
            section_id: document.getElementById('modal_section_id').value,
            days: Array.from(assignmentDays).filter(function (day) { return day.checked; }).map(function (day) { return day.value; }),
            start_time: assignmentStart.value,
            end_time: assignmentEnd.value,
            shift: document.getElementById('modal_shift').value.replace(' Shift',''),
            validate_only: validateOnly ? 1 : 0
        };
    }
    function showAssignmentValidation(type, message) {
        assignmentValidation.className = 'alert alert-' + type + ' mt-3 mb-0 py-2';
        assignmentValidation.textContent = message;
        assignmentValidation.classList.remove('d-none');
    }
    function validateAssignmentLive() {
        var payload = assignmentPayload(true);
        if (!payload.teacher_id || !payload.days.length || !payload.start_time || !payload.end_time) { assignmentValidation.classList.add('d-none'); return; }
        fetch('assignment_api.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)})
            .then(function (r) { return r.json().then(function (data) { return {ok:r.ok && data.ok, data:data}; }); })
            .then(function (result) { showAssignmentValidation(result.ok ? 'success' : 'danger', result.data.message || (result.ok ? 'No schedule conflict detected.' : 'Schedule conflict.')); })
            .catch(function () { showAssignmentValidation('warning', 'Unable to validate right now. Server validation will still run before saving.'); });
    }
    function queueAssignmentValidation() { clearTimeout(assignmentTimer); assignmentTimer = setTimeout(validateAssignmentLive, 250); }
    assignmentDays.forEach(function (day) { day.addEventListener('change', function () { queueAssignmentValidation(); assignSlots.refresh(); }); });
    weekdays.addEventListener('change', function () { assignmentDays.forEach(function (day) { day.checked = weekdays.checked; }); queueAssignmentValidation(); assignSlots.refresh(); });
    // Changing the teacher changes whose schedule is checked: refresh the slots and re-validate.
    document.getElementById('teacher_id').addEventListener('change', function () { queueAssignmentValidation(); assignSlots.refresh(); });
    assignmentStart.addEventListener('change', function () {
        if (assignmentStart.value && !assignmentEnd.value) {
            var parts = assignmentStart.value.split(':'); var minutes = parseInt(parts[0],10) * 60 + parseInt(parts[1],10) + 45;
            assignmentEnd.value = String(Math.floor(minutes / 60)).padStart(2,'0') + ':' + String(minutes % 60).padStart(2,'0');
        }
        queueAssignmentValidation();
    });
    assignmentEnd.addEventListener('change', queueAssignmentValidation);
    assignmentForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var button = document.getElementById('save_assignment_btn');
        var payload = assignmentPayload(false);
        if (!payload.teacher_id || !payload.days.length || !payload.start_time || !payload.end_time) { showAssignmentValidation('danger', 'Select a teacher, at least one day, start time, and end time.'); return; }
        button.disabled = true;
        fetch('assignment_api.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)})
            .then(function (r) { return r.json().then(function (data) { return {ok:r.ok && data.ok, data:data}; }); })
            .then(function (result) { if (!result.ok) { showAssignmentValidation('danger', result.data.message || 'Unable to save assignment.'); return; } window.location.reload(); })
            .catch(function () { showAssignmentValidation('danger', 'Could not save the assignment. Please try again.'); })
            .finally(function () { button.disabled = false; });
    });

    // ---------------- Master Schedule modal ----------------
    var scheduleModal = document.getElementById('scheduleModal');
    var teacherSelect = document.getElementById('schedule_teacher_id');
    var assignmentSelect = document.getElementById('schedule_assignment_id');
    var fields = document.getElementById('schedule_fields');
    var forBox = document.getElementById('schedule_for_box');
    var forText = document.getElementById('schedule_for_text');
    var subjectText = document.getElementById('schedule_subject_text');
    var startInput = document.getElementById('schedule_start_time');
    var endInput = document.getElementById('schedule_end_time');
    var shiftSelect = document.getElementById('schedule_shift');
    var shiftHelp = document.getElementById('schedule_shift_help');
    var validation = document.getElementById('schedule_validation');
    var existing = document.getElementById('schedule_existing');
    var saveBtn = document.getElementById('save_schedule_btn');
    var assignments = [];
    var schedSlots = createSlotPicker({
        root: document.getElementById('schedule_slot_picker'),
        startEl: startInput, endEl: endInput, viewToggle: false,
        getState: function () {
            var a = currentAssignment();
            if (!a) return null;
            return { teacherId: teacherSelect.value, sectionId: a.section_id, tsId: a.ts_id, days: selectedDays(), shift: shiftSelect.value };
        }
    });
    var requestedTs = '';
    var validateTimer = null;
    var csrf = <?php echo json_encode(generateCSRFToken()); ?>;

    function selectedDays() {
        return Array.from(document.querySelectorAll('.schedule-day:checked')).map(function(el){ return parseInt(el.value,10); });
    }
    function showValidation(type, message) {
        validation.className = 'alert mt-3 mb-0 alert-' + type;
        validation.textContent = message;
        validation.classList.remove('d-none');
    }
    function clearValidation() { validation.classList.add('d-none'); validation.textContent=''; }
    function currentAssignment() {
        return assignments.find(function(a){ return String(a.ts_id) === String(assignmentSelect.value); }) || null;
    }
    function formatExisting(rows) {
        if (!rows || !rows.length) return '<i class="fas fa-circle-info me-1"></i>No schedule saved yet for this assignment.';
        var names={1:'Mon',2:'Tue',3:'Wed',4:'Thu',5:'Fri',6:'Sat',7:'Sun'};
        return '<strong>Current:</strong> ' + rows.map(function(r){ return names[r.day_of_week]+' '+r.start_time+'–'+r.end_time+(r.shift?' ('+r.shift+')':''); }).join(' · ');
    }
    function sectionLabel(a) {
        if (!a) return '';
        var gradeNum = String(a.grade_level || '').replace('Grade ','').trim();
        var sec = String(a.section_name || '').trim();
        if (gradeNum && sec.toLowerCase().indexOf(gradeNum.toLowerCase()) === 0) return 'Grade ' + sec;
        return (a.grade_level || '') + (sec ? ' ' + sec : '');
    }
    function applyAssignment(a) {
        if (!a) { fields.classList.add('d-none'); forBox.classList.add('d-none'); saveBtn.disabled=true; return; }
        forText.textContent = sectionLabel(a);
        subjectText.textContent = a.subject_name + ' (' + a.subject_code + ')';
        forBox.classList.remove('d-none'); fields.classList.remove('d-none');
        document.querySelectorAll('.schedule-day').forEach(function(c){ c.checked=false; });
        startInput.value=''; endInput.value='';
        if (a.schedules && a.schedules.length) {
            a.schedules.forEach(function(r){ var c=document.querySelector('.schedule-day[value="'+r.day_of_week+'"]'); if(c)c.checked=true; });
            startInput.value=a.schedules[0].start_time || '';
            endInput.value=a.schedules[0].end_time || '';
            if (a.schedules[0].shift) shiftSelect.value=a.schedules[0].shift;
        }
        if (a.default_shift) {
            shiftSelect.value=a.default_shift;
            shiftSelect.disabled=true;
            shiftHelp.textContent = a.grade_level + ' automatically uses ' + a.default_shift + ' Shift. Change the school shift times in Attendance Settings if needed.';
        } else {
            shiftSelect.disabled=false;
            shiftHelp.textContent = 'Senior High scheduling remains supported and is not restricted by the JHS AM/PM rule.';
        }
        existing.innerHTML=formatExisting(a.schedules);
        saveBtn.disabled=false;
        clearValidation();
        queueValidation();
        schedSlots.reset();
        schedSlots.refresh();
    }
    function loadAssignments(teacherId, preferredTs) {
        assignments=[]; assignmentSelect.disabled=true; fields.classList.add('d-none'); forBox.classList.add('d-none'); saveBtn.disabled=true;
        assignmentSelect.innerHTML='<option value="">Loading assignments…</option>';
        if (!teacherId) { assignmentSelect.innerHTML='<option value="">-- Select teacher first --</option>'; return; }
        fetch('schedule_api.php?teacher_id='+encodeURIComponent(teacherId), {headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function(r){return r.json();})
            .then(function(data){
                assignments=data.assignments || [];
                assignmentSelect.innerHTML='<option value="">-- Select assigned subject / section --</option>' + assignments.map(function(a){
                    return '<option value="'+a.ts_id+'">'+sectionLabel(a)+' — '+a.subject_name+'</option>';
                }).join('');
                assignmentSelect.disabled=false;
                if (preferredTs) { assignmentSelect.value=String(preferredTs); applyAssignment(currentAssignment()); requestedTs=''; }
            })
            .catch(function(){ assignmentSelect.innerHTML='<option value="">Could not load assignments</option>'; showValidation('danger','Could not load teacher assignments.'); });
    }
    function payload(validateOnly) {
        var a=currentAssignment();
        if(!a) return null;
        return {csrf_token:csrf, ts_id:a.ts_id, days:selectedDays(), start_time:startInput.value, end_time:endInput.value, shift:shiftSelect.value, validate_only:validateOnly?1:0};
    }
    function validateSchedule() {
        var p=payload(true);
        if(!p || !p.days.length || !p.start_time || !p.end_time) { clearValidation(); return; }
        fetch('schedule_api.php',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify(p)})
            .then(async function(r){ var d=await r.json(); return {ok:r.ok && d.ok,data:d}; })
            .then(function(res){ showValidation(res.ok?'success':'danger', res.data.message || (res.ok?'No schedule conflict detected.':'Schedule conflict.')); })
            .catch(function(){ showValidation('warning','Unable to validate right now. Saving will still run full server-side validation.'); });
    }
    function queueValidation(){ clearTimeout(validateTimer); validateTimer=setTimeout(validateSchedule,250); }

    if (teacherSelect) teacherSelect.addEventListener('change',function(){ loadAssignments(this.value,''); });
    if (assignmentSelect) assignmentSelect.addEventListener('change',function(){ applyAssignment(currentAssignment()); });
    document.querySelectorAll('.schedule-day').forEach(function(c){c.addEventListener('change',function(){ queueValidation(); schedSlots.refresh(); });});
    [startInput,endInput,shiftSelect].forEach(function(el){ if(el) el.addEventListener('change',queueValidation); });
    // A different shift shows a different set of periods.
    if (shiftSelect) shiftSelect.addEventListener('change', function(){ schedSlots.refresh(); });

    if (scheduleModal) scheduleModal.addEventListener('show.bs.modal',function(e){
        var btn=e.relatedTarget;
        var teacherId=btn && btn.getAttribute('data-teacher-id');
        var tsId=btn && btn.getAttribute('data-ts-id');
        if(teacherId){ teacherSelect.value=teacherId; requestedTs=tsId||''; loadAssignments(teacherId,requestedTs); }
    });
    document.querySelectorAll('.js-open-schedule').forEach(function(btn){ btn.addEventListener('click',function(){ requestedTs=this.getAttribute('data-ts-id')||''; }); });

    if (saveBtn) saveBtn.addEventListener('click',function(){
        var p=payload(false);
        if(!p || !p.days.length || !p.start_time || !p.end_time){ showValidation('danger','Select day/s, start time, and end time before saving.'); return; }
        saveBtn.disabled=true; saveBtn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Saving…';
        fetch('schedule_api.php',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify(p)})
            .then(async function(r){var d=await r.json();return {ok:r.ok&&d.ok,data:d};})
            .then(function(res){
                showValidation(res.ok?'success':'danger',res.data.message||'Unable to save schedule.');
                if(res.ok){ var a=currentAssignment(); loadAssignments(teacherSelect.value,a?a.ts_id:''); }
            })
            .catch(function(){showValidation('danger','Could not save the schedule. Please try again.');})
            .finally(function(){saveBtn.disabled=false;saveBtn.innerHTML='<i class="fas fa-save me-1"></i> Save Schedule';});
    });
});
</script>

</div><!-- /.subj-page -->

<?php include '../../includes/footer.php'; ?>