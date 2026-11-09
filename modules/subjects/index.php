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
$page_subtitle = 'Manage the curriculum: which subjects belong to each grade level, strand and term. Teachers are assigned in Teacher Management.';
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
// Senior High subjects run per term: the section view shows one term at a time (Term 1 by default).
$selected_term = (isset($_GET['term']) && in_array((string)$_GET['term'], ['1', '2', '3'], true)) ? (string)$_GET['term'] : '1';
$term_counts = ['1' => 0, '2' => 0, '3' => 0];

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

// The old HUMSS sections (11/12-HUMSS A-C) are no longer offered: Grade 11/12
// list only the ASSH Cluster and GAS sections. They stay in the database
// (historical records); they are just not listed or assignable here.
if (!function_exists('isRetiredHumssSection')) {
    function isRetiredHumssSection($sec) {
        return strtoupper(trim((string)($sec['strand'] ?? ''))) === 'HUMSS'
            || stripos((string)($sec['section_name'] ?? ''), 'HUMSS') !== false;
    }
}
$all_sections = array_values(array_filter($all_sections, function ($sec) { return !isRetiredHumssSection($sec); }));

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
    if ($section_info && isRetiredHumssSection($section_info)) $section_info = null;
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
                // Curriculum view: active subjects only (assignments are listed in Teacher Management).
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
        // SHS: only the subjects of the chosen term. A subject with no term on file
        // (older records) is shown in every term so its assignment is never hidden.
        if ($section_info['grade_level'] === 'Grade 11' || $section_info['grade_level'] === 'Grade 12') {
            foreach ($section_subjects as $row) {
                if (preg_match('/([123])/', (string)($row['term'] ?? ''), $tm)) $term_counts[$tm[1]]++;
            }
            $section_subjects = array_values(array_filter($section_subjects, function ($row) use ($selected_term) {
                $t = trim((string)($row['term'] ?? ''));
                return $t === '' || (preg_match('/([123])/', $t, $tm) && $tm[1] === $selected_term);
            }));
        }
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

// Subjects per grade -- the grade cards show it whether or not a grade is selected.
foreach (getRecords("SELECT grade_level, COUNT(*) AS total FROM subjects WHERE is_active = 1 GROUP BY grade_level") as $r) {
    $subjects_by_grade_count[$r['grade_level']] = (int)$r['total'];
}

if (!$selected_grade) {
    $subj_overview['total'] = (int)(getRecord("SELECT COUNT(*) AS c FROM subjects WHERE is_active = 1")['c'] ?? 0);
    $subj_overview['jhs'] = (int)(getRecord("SELECT COUNT(*) AS c FROM subjects WHERE is_active = 1 AND grade_level IN ('Grade 7','Grade 8','Grade 9','Grade 10')")['c'] ?? 0);
    $subj_overview['shs'] = (int)(getRecord("SELECT COUNT(*) AS c FROM subjects WHERE is_active = 1 AND grade_level IN ('Grade 11','Grade 12')")['c'] ?? 0);
    $subj_overview['assigned'] = (int)(getRecord("SELECT COUNT(*) AS c FROM teacher_subjects WHERE school_year_id = ?", [$school_year_id])['c'] ?? 0);

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
        font-size: 13.5px;
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
        font-size: 14.5px;
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
        font-size: 12.5px;
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
        font-size: 15px;
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
        font-size: 15px;
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
        font-size: 14px;
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
        font-size: 13px;
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
        font-size: 12.5px;
        font-weight: 700;
        margin-right: 6px;
        vertical-align: middle;
        flex: 0 0 auto;
    }
    .subj-page .sp-teacher-row {
        display: flex;
        align-items: center;
        gap: 0;
        font-size: 15px;
        margin-bottom: 3px;
    }
    .subj-page .sp-teacher-row:last-child { margin-bottom: 0; }
    /* Teacher name on one line; a long name ends in "…" (full name on hover). */
    .subj-page .sp-teacher-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 190px; }
    /* "+N more": the other teachers open in a card to the right, so the next row is not covered. */
    .subj-page .sp-more-teachers { position: relative; display: inline-block; margin: 4px 0 0 32px; outline: none; }
    .subj-page .sp-more-link {
        display: inline-flex; align-items: center; height: 22px; padding: 0 10px; border-radius: 999px;
        font-size: 13px; font-weight: 600; color: var(--primary); background: var(--primary-soft, #EAF1FE);
        border: 1px solid transparent; cursor: default; transition: background 0.15s, border-color 0.15s;
    }
    .subj-page .sp-more-teachers:hover .sp-more-link,
    .subj-page .sp-more-teachers:focus-within .sp-more-link { border-color: var(--primary); background: var(--surface); }
    .subj-page .sp-more-pop {
        display: none; position: absolute; left: var(--pop-left, calc(100% + 12px)); top: 50%; transform: translateY(-50%); z-index: 30;
        min-width: 230px; max-width: 280px; padding: 10px 12px; background: var(--surface); border: 1px solid var(--border);
        border-radius: 10px; box-shadow: 0 10px 24px rgba(16, 24, 40, 0.14);
    }
    .subj-page .sp-more-pop::before {   /* small arrow pointing at "+N more" */
        content: ''; position: absolute; left: -6px; top: 50%; width: 10px; height: 10px; margin-top: -5px;
        background: var(--surface); border-left: 1px solid var(--border); border-bottom: 1px solid var(--border); transform: rotate(45deg);
    }
    .subj-page .sp-more-teachers::after { content: ''; position: absolute; top: 0; bottom: 0; left: 100%; width: var(--bridge, 14px); }   /* hover bridge */
    .subj-page .sp-more-teachers:hover .sp-more-pop,
    .subj-page .sp-more-teachers:focus-within .sp-more-pop { display: block; }
    .subj-page .sp-more-title { font-size: 12.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--text-muted); margin-bottom: 8px; }
    .subj-page .sp-more-pop .sp-teacher-row { margin-bottom: 6px; }
    .subj-page .sp-more-pop .sp-teacher-name { max-width: 210px; }
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
        font-size: 14.5px;
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
        font-size: 14.5px;
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


    /* ---- Bigger, easier-to-read text ---- */
    .subj-page .sp-header h2 { font-size: 30px !important; }
    .subj-page .sp-header p { font-size: 16px !important; }
    .subj-page .sp-btn-gradient { font-size: 15.5px !important; padding: 11px 20px !important; }
    .subj-page .grade-card .grade-num { font-size: 36px !important; }
    .subj-page .grade-card .grade-label { font-size: 18px !important; }
    .subj-page .grade-card .section-count { font-size: 15px !important; }
    .subj-page .grade-card .grade-selected-pill { font-size: 14.5px !important; padding: 4px 14px !important; }
    .subj-page .card-header h5 { font-size: 19px !important; }
    .subj-page .card-header .badge { font-size: 15px; }
    .subj-page .card-header .btn-sm { font-size: 15.5px; padding: 7px 14px; }
    .subj-page .section-card .section-name { font-size: 19px !important; font-weight: 700; }
    .subj-page .section-card .badge { font-size: 14.5px; }
    .subj-page .section-card .card-body { padding-top: 22px !important; padding-bottom: 22px !important; }
    .subj-page .sp-filter-label { font-size: 14.5px !important; }
    .subj-page .sp-filter-bar .form-control, .subj-page .sp-filter-bar .form-select { font-size: 15px; min-height: 44px; }
    .subj-page .table > :not(caption) > * > th { font-size: 14px; }
    .subj-page .table > :not(caption) > * > td { font-size: 15px; }
    .subj-page .sp-teacher-row { font-size: 15px; }
    .subj-page .table .badge { font-size: 14.5px; }
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
                    <div class="text-muted text-uppercase" style="font-size:14.5px;letter-spacing:0.04em;"><?php echo $t[0]; ?></div>
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
                    <div class="d-flex justify-content-between mb-1" style="font-size:15px;">
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
                        <select name="ut_grade" class="form-select form-select-sm" style="font-size:15px;padding:6px 30px 6px 10px;width:auto;" onchange="this.form.submit();" aria-label="Filter subjects without teacher by grade level">
                            <option value="">All Grades</option>
                            <?php foreach ($ut_grade_options as $g_opt): ?>
                            <option value="<?php echo htmlspecialchars($g_opt); ?>" <?php echo $ut_grade === $g_opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($g_opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button></noscript>
                    </form>
                    <span class="badge bg-secondary" style="font-size:14.5px;padding:6px 12px;"><?php echo $unassigned_total; ?></span>
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
                    <div class="text-muted text-center mt-2" style="font-size:15px;">
                        Showing <?php echo count($unassigned); ?> of <?php echo $unassigned_total; ?>. Filter by grade to narrow the list.
                    </div>
                <?php endif; ?>
                <?php endif; ?>
                <div class="alert alert-light border mt-3 mb-0 small">
                    <i class="fas fa-circle-info text-primary me-1"></i>
                    Read-only list. Teachers are assigned per section in
                    <a href="../teachers/index.php" class="fw-semibold">Teacher Management</a>.
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
                                <?php $tname = $tt['last_name'] . ', ' . $tt['first_name']; ?>
                                <div class="sp-teacher-row">
                                    <span class="sp-avatar" style="background:<?php echo $tcolor['bg']; ?>;color:<?php echo $tcolor['fg']; ?>;"><?php echo htmlspecialchars($tinit); ?></span>
                                    <span class="sp-teacher-name" title="<?php echo htmlspecialchars($tname); ?>"><?php echo htmlspecialchars($tname); ?></span>
                                </div>
                                <?php if (count($asub['teachers']) > 1): $others = array_slice($asub['teachers'], 1); ?>
                                    <div class="sp-more-teachers" tabindex="0" aria-label="Show <?php echo count($others); ?> more teacher(s)">
                                        <span class="sp-more-link">+<?php echo count($others); ?> more</span>
                                        <div class="sp-more-pop" role="tooltip">
                                            <div class="sp-more-title">Other teachers (<?php echo count($others); ?>)</div>
                                            <?php foreach ($others as $tt):
                                                $tinit = strtoupper(substr($tt['first_name'], 0, 1) . substr($tt['last_name'], 0, 1));
                                                $tname = $tt['last_name'] . ', ' . $tt['first_name'];
                                            ?>
                                            <div class="sp-teacher-row">
                                                <span class="sp-avatar" style="background:<?php echo $tcolor['bg']; ?>;color:<?php echo $tcolor['fg']; ?>;"><?php echo htmlspecialchars($tinit); ?></span>
                                                <span class="sp-teacher-name" title="<?php echo htmlspecialchars($tname); ?>"><?php echo htmlspecialchars($tname); ?></span>
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

<script>
// "+N more": open the card just past the Teacher column, so it never covers
// the teacher names in the rows above or below.
document.querySelectorAll('.subj-page .sp-more-teachers').forEach(function (m) {
    function place() {
        var gap = m.closest('td').getBoundingClientRect().right - m.getBoundingClientRect().left + 10;
        m.style.setProperty('--pop-left', gap + 'px');
        m.style.setProperty('--bridge', (gap - m.offsetWidth) + 'px');
    }
    m.addEventListener('mouseenter', place);
    m.addEventListener('focus', place);
});
</script>

<!-- Subject detail view — populated client-side from the row's own data,
     since no separate "view subject" page exists in this system. -->
<div class="modal fade" id="subjectViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-book me-2"></i>Subject Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="subjectViewBody" style="font-size:15px;">
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
                Subjects in <?php echo htmlspecialchars($section_info['section_name']); ?><?php echo $is_shs ? ' &middot; Term ' . $selected_term : ''; ?>
                <span class="badge bg-secondary ms-2"><?php echo count($section_subjects); ?></span>
            </h5>
            <?php if ($is_shs): ?>
                <form method="GET" class="d-flex align-items-center gap-2 ms-auto me-2" action="#subjects-section">
                    <input type="hidden" name="grade" value="<?php echo htmlspecialchars($selected_grade); ?>">
                    <input type="hidden" name="strand" value="<?php echo htmlspecialchars($selected_strand); ?>">
                    <input type="hidden" name="section" value="<?php echo htmlspecialchars($selected_section); ?>">
                    <label for="termSelect" class="small fw-semibold text-muted mb-0"><i class="fas fa-calendar-alt me-1"></i>Term</label>
                    <select id="termSelect" name="term" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                        <?php foreach (['1', '2', '3'] as $tn): ?>
                            <option value="<?php echo $tn; ?>" <?php echo $selected_term === $tn ? 'selected' : ''; ?>>Term <?php echo $tn; ?> (<?php echo (int)$term_counts[$tn]; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
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
                <a href="../teachers/index.php?grade=<?php echo urlencode($selected_grade); ?>&strand=<?php echo urlencode($selected_strand); ?>&section=<?php echo (int)$selected_section; ?>"
                   class="btn btn-sm btn-outline-primary" title="Teacher assignment for this section is done in Teacher Management">
                    <i class="fas fa-chalkboard-teacher me-1"></i> Manage Teachers
                </a>
            </div>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3"><i class="fas fa-circle-info me-1"></i>These are the curriculum subjects of this section's grade level<?php echo $is_shs ? ', strand and term' : ''; ?>. Every enrolled student of the section has them automatically. Teachers are assigned in Teacher Management.</p>
            <?php if (count($section_subjects) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Subject Code</th>
                                <th>Subject Name</th>
                                <th>Grade</th>
                                <?php if ($is_shs): ?>
                                    <th>Strand</th>
                                    <th>Term</th>
                                <?php endif; ?>
                                <th>Type</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; ?>
                            <?php foreach ($section_subjects as $subject): ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($subject['subject_code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                    <td><?php echo htmlspecialchars($subject['grade_level']); ?></td>
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
                                        <td><?php echo !empty($subject['term']) ? htmlspecialchars($subject['term']) : '<span class="text-muted">All terms</span>'; ?></td>
                                    <?php endif; ?>
                                    <td><?php echo htmlspecialchars($subject['subject_type'] ?? ''); ?></td>
                                    <td>
                                        <span class="badge <?php echo !empty($subject['is_active']) ? 'bg-success' : 'bg-secondary'; ?>"><?php echo !empty($subject['is_active']) ? 'Active' : 'Inactive'; ?></span>
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

<?php endif; ?>

<!-- Teacher assignment and class schedules are managed in Teacher Management (modules/teachers/index.php). -->

</div><!-- /.subj-page -->

<?php include '../../includes/footer.php'; ?>