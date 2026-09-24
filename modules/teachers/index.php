<?php
// modules/teachers/index.php
// Teacher management - Grade Level -> Strand (SHS) -> Sections -> Subjects

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'Teacher Management';
$page_subtitle = 'Manage and organize teachers by grade level and sections.';
$current_page = 'teachers';
$base_url = '../../';

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
$selected_subject = isset($_GET['subject']) ? sanitize($_GET['subject']) : '';

$active_sy = getRecord("SELECT sy_id, sy_name FROM school_years WHERE is_active = 1");
$school_year_id = $active_sy ? $active_sy['sy_id'] : 0;

// SHS-only: which grading term's subjects to show for the selected section.
// Grades 7-10 subjects run all three terms already (subjects.terms_required
// = 3) and are never filtered by this.
//
// The term is no longer picked by hand -- it is worked out automatically
// from the school-wide Term Start/End Dates (term_windows) that Manage
// Assessment Items already uses to show Upcoming/Ongoing/Ended status.
// The page-level "Term Dates" button next to "Add Teacher" edits those
// SAME dates (reachable without drilling into any grade first, since the
// school's term dates are the same for every grade) -- so a date set here
// or in Manage Assessment Items shows up in both places. Whichever term is
// currently "ongoing" is the one shown; if none is (dates not set up yet,
// or today falls in a gap between terms), Senior High subjects aren't
// shown until the dates are configured.
$valid_terms = gh_threeTerms();
$term_windows_by_term = [];
foreach ($valid_terms as $t) {
    $term_windows_by_term[$t] = $school_year_id ? gh_getTermWindow($school_year_id, $t) : null;
}
$selected_term = gh_getCurrentTerm($school_year_id) ?? '';

$all_sections = getRecords("
    SELECT section_id, section_name, grade_level, strand, capacity, current_enrollment
    FROM sections 
    WHERE is_active = 1 
    ORDER BY grade_level, section_name
");

$sections_by_grade = [];
foreach ($all_sections as $sec) {
    $sections_by_grade[$sec['grade_level']][] = $sec;
}

$is_shs_grade = ($selected_grade === 'Grade 11' || $selected_grade === 'Grade 12');

// Both strands are always offered for a Senior High grade, even before any
// section has been created under one of them yet (e.g. Grade 12 ASSH
// Cluster with no section on file yet) -- this list is NOT derived from
// which sections happen to already exist, precisely so a still-empty
// strand isn't hidden.
$available_strands = ($is_shs_grade && $selected_grade) ? $active_shs_strands : [];

$filtered_sections = [];
if ($selected_grade) {
    if ($is_shs_grade && $selected_strand) {
        foreach ($sections_by_grade[$selected_grade] as $sec) {
            if ($sec['strand'] === $selected_strand) {
                $filtered_sections[] = $sec;
            }
        }
    } elseif (!$is_shs_grade) {
        $filtered_sections = $sections_by_grade[$selected_grade] ?? [];
    }
}

$section_subjects = [];
$section_info = null;

if ($selected_section > 0) {
    $section_info = getRecord("SELECT * FROM sections WHERE section_id = ?", [$selected_section]);
    if ($section_info) {
        $current_adviser = null;
        if (!empty($section_info['adviser_id'])) {
            $current_adviser = getRecord("SELECT user_id, first_name, last_name FROM users WHERE user_id = ?", [$section_info['adviser_id']]);
        }

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

        $is_shs = ($section_info['grade_level'] === 'Grade 11' || $section_info['grade_level'] === 'Grade 12');
        if ($is_shs) {
            if (!empty($section_info['strand'])) {
                $sql .= " AND s.strand = ?";
                $params[] = $section_info['strand'];
            }
            // Grade Level + Strand/Cluster + Term + School Year: a Senior
            // High subject list is never shown unless a Term is currently
            // active (auto-detected from term_windows), so subjects from
            // another term can never appear mixed in.
            if ($selected_term !== '') {
                $sql .= " AND s.term = ?";
                $params[] = $selected_term;
            } else {
                $sql .= " AND 1 = 0";
            }
        } else {
            $sql .= " AND (s.strand IS NULL OR s.strand = '')";
        }

        $sql .= " ORDER BY s.subject_name";
        $section_subjects = getRecords($sql, $params);
    }
}

$teachers = [];
$grade_section_ids = [];

if ($selected_grade) {
    $grade_section_ids = array_column($sections_by_grade[$selected_grade] ?? [], 'section_id');

    if (!empty($grade_section_ids)) {
        $placeholders = implode(',', array_fill(0, count($grade_section_ids), '?'));
        $teachers = getRecords("
            SELECT DISTINCT u.user_id, u.username, u.email, u.first_name, u.last_name, 
                   u.specialization, u.is_active, u.prc_license_number, u.years_experience,
                   u.qualification_status, r.role_name
            FROM users u
            JOIN teacher_subjects ts ON u.user_id = ts.teacher_id
            JOIN roles r ON u.role_id = r.role_id
            WHERE u.role_id = 3 
            AND u.is_active = 1
            AND ts.section_id IN ($placeholders)
            AND ts.school_year_id = ?
            ORDER BY u.last_name, u.first_name
        ", array_merge($grade_section_ids, [$school_year_id]));
    }
} else {
    // Real search/specialization/status filters — genuine SQL WHERE
    // conditions, not decorative markup. Empty params = original,
    // unfiltered "All Teachers" list (existing default behaviour
    // unchanged: active teachers only).
    $search_q = isset($_GET['q']) ? trim(sanitize($_GET['q'])) : '';
    $filter_spec = isset($_GET['spec']) ? sanitize($_GET['spec']) : '';
    $filter_status = isset($_GET['status']) ? sanitize($_GET['status']) : '';

    $name_where = [];
    $name_params = [];
    if ($search_q !== '') {
        $like = '%' . $search_q . '%';
        $name_where[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
        array_push($name_params, $like, $like, $like, $like);
    }
    if ($filter_spec !== '') {
        $name_where[] = "u.specialization = ?";
        $name_params[] = $filter_spec;
    }
    $name_where_sql = $name_where ? (' AND ' . implode(' AND ', $name_where)) : '';

    $status_condition = 'AND u.is_active = 1'; // default matches the original hard-coded behaviour
    if ($filter_status === 'all') {
        $status_condition = '';
    } elseif ($filter_status === 'inactive') {
        $status_condition = 'AND u.is_active = 0';
    } elseif ($filter_status === 'active') {
        $status_condition = 'AND u.is_active = 1';
    }

    $teachers = getRecords("
        SELECT u.*, r.role_name
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        WHERE u.role_id = 3 $status_condition
        $name_where_sql
        ORDER BY u.last_name, u.first_name
    ", $name_params);
}
if (!isset($search_q)) { $search_q = ''; }
if (!isset($filter_spec)) { $filter_spec = ''; }
if (!isset($filter_status)) { $filter_status = ''; }

// Real, no-fabricated-value dropdown options for the Specialization filter.
$specialization_options = getRecords("
    SELECT DISTINCT specialization FROM users
    WHERE role_id = 3 AND specialization IS NOT NULL AND specialization <> ''
    ORDER BY specialization
");

// Simple in-PHP pagination over the already-filtered $teachers list — this
// page never had SQL-level LIMIT/OFFSET, so paging here (rather than
// rewriting both queries' placeholder counts) keeps the existing queries
// completely intact while still giving a real, working Previous/Next.
$teachers_total = count($teachers);
$per_page = 10;
$total_pages = max(1, (int)ceil($teachers_total / $per_page));
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
if ($page > $total_pages) { $page = $total_pages; }
$teachers_page = array_slice($teachers, ($page - 1) * $per_page, $per_page);

// Deterministic colour for a specialization badge — same specialization
// always gets the same colour, picked from a fixed palette (not invented
// per-value, just consistently assigned so badges are scannable).
$spec_palette = [
    ['bg' => '#EAF1FE', 'fg' => '#1957C2'],
    ['bg' => '#E9F7EF', 'fg' => '#1E7B45'],
    ['bg' => '#FFF6E5', 'fg' => '#B7791F'],
    ['bg' => '#F1EBFE', 'fg' => '#6A3FC2'],
    ['bg' => '#FDECEF', 'fg' => '#C23A57'],
    ['bg' => '#E6F7F6', 'fg' => '#287A78'],
];
function spec_badge_color($specialization, $palette) {
    if (empty($specialization)) return $palette[0];
    $idx = crc32($specialization) % count($palette);
    return $palette[$idx];
}

$all_teachers = getRecords("
    SELECT user_id, first_name, last_name, specialization 
    FROM users 
    WHERE role_id = 3 AND is_active = 1 
    ORDER BY last_name, first_name
");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_subject') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token.';
    } else {
        $teacher_id = intval($_POST['teacher_id'] ?? 0);
        $section_id = intval($_POST['section_id'] ?? 0);
        // The modal now submits a list of checkboxes, so several subjects can
        // be handed to one teacher in a single action.
        $subject_codes = $_POST['subject_codes'] ?? [];
        if (!is_array($subject_codes)) {
            $subject_codes = [$subject_codes];
        }
        $subject_codes = array_values(array_unique(array_filter(array_map('sanitize', $subject_codes), 'strlen')));

        if (empty($teacher_id) || empty($subject_codes) || empty($section_id)) {
            $_SESSION['error_message'] = 'Please select a teacher and at least one subject.';
        } else {
            $teacher = getRecord("SELECT first_name, last_name FROM users WHERE user_id = ?", [$teacher_id]);
            $sec = getRecord("SELECT section_name FROM sections WHERE section_id = ?", [$section_id]);

            $assigned_names = [];
            $skipped = [];

            foreach ($subject_codes as $subject_code) {
                // One teacher per subject per section. This used to check for
                // the SAME teacher only, so a second teacher could be added to
                // a subject that already had one — which then showed the
                // subject twice in the table (the LEFT JOIN matched both).
                $check = getRecord("
                    SELECT ts.ts_id, u.first_name, u.last_name
                    FROM teacher_subjects ts
                    LEFT JOIN users u ON ts.teacher_id = u.user_id
                    WHERE ts.subject_code = ? AND ts.section_id = ? AND ts.school_year_id = ?
                ", [$subject_code, $section_id, $school_year_id]);

                if ($check) {
                    $skipped[] = $subject_code . ' (' . trim(($check['first_name'] ?? '') . ' ' . ($check['last_name'] ?? '')) . ')';
                    continue;
                }

                $result = executeQuery(
                    "INSERT INTO teacher_subjects (teacher_id, subject_code, section_id, school_year_id)
                     VALUES (?, ?, ?, ?)",
                    [$teacher_id, $subject_code, $section_id, $school_year_id]
                );

                if ($result) {
                    $subj = getRecord("SELECT subject_name FROM subjects WHERE subject_code = ?", [$subject_code]);
                    $assigned_names[] = $subj['subject_name'] ?? $subject_code;
                } else {
                    $skipped[] = $subject_code . ' (failed to save)';
                }
            }

            if (!empty($assigned_names)) {
                logActivity(
                    $_SESSION['user_id'],
                    'Assigned subjects to teacher',
                    'teachers',
                    'Assigned ' . implode(', ', $subject_codes) . " to {$teacher['first_name']} {$teacher['last_name']}"
                    . ' (section: ' . ($sec['section_name'] ?? $section_id) . ')'
                );

                // One notification for the whole batch rather than one per
                // subject, so a teacher given six subjects is not spammed.
                $count = count($assigned_names);
                createNotification(
                    $teacher_id,
                    $count === 1 ? 'New Subject Assigned' : 'New Subjects Assigned',
                    'You have been assigned to teach ' . implode(', ', $assigned_names)
                    . ' for section ' . ($sec['section_name'] ?? $section_id) . '.',
                    'success',
                    'modules/teachers/my_sections.php'
                );

                $_SESSION['success_message'] = $count === 1
                    ? 'Assigned ' . $assigned_names[0] . '.'
                    : 'Assigned ' . $count . ' subjects.';
            }

            if (!empty($skipped)) {
                $_SESSION['error_message'] = 'Skipped (already handled): ' . implode(', ', $skipped)
                    . '. Unassign the current teacher first to change them.';
            }
        }
    }
    header('Location: index.php?grade=' . urlencode($selected_grade) . '&strand=' . urlencode($selected_strand) . '&section=' . $selected_section . '&subject=' . urlencode($selected_subject));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unassign_subject') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token.';
    } else {
        $ts_id = intval($_POST['ts_id'] ?? 0);

        if (empty($ts_id)) {
            $_SESSION['error_message'] = 'No assignment selected.';
        } else {
            // Read the row first so the log and the notification can name the
            // teacher and subject, and so a stale button cannot delete a row
            // that has already been removed by someone else.
            $existing = getRecord("
                SELECT ts.ts_id, ts.teacher_id, ts.subject_code, ts.section_id,
                       u.first_name, u.last_name,
                       s.subject_name, sec.section_name
                FROM teacher_subjects ts
                LEFT JOIN users u ON ts.teacher_id = u.user_id
                LEFT JOIN subjects s ON s.subject_code = ts.subject_code
                LEFT JOIN sections sec ON sec.section_id = ts.section_id
                WHERE ts.ts_id = ? AND ts.school_year_id = ?
            ", [$ts_id, $school_year_id]);

            if (!$existing) {
                $_SESSION['error_message'] = 'That assignment no longer exists.';
            } else {
                $result = executeQuery("DELETE FROM teacher_subjects WHERE ts_id = ?", [$ts_id]);

                if ($result) {
                    logActivity(
                        $_SESSION['user_id'],
                        'Unassigned subject from teacher',
                        'teachers',
                        "Unassigned {$existing['subject_code']} from {$existing['first_name']} {$existing['last_name']}"
                        . " (section: {$existing['section_name']})"
                    );

                    createNotification(
                        $existing['teacher_id'],
                        'Subject Assignment Removed',
                        'You are no longer assigned to teach ' . ($existing['subject_name'] ?? $existing['subject_code'])
                        . ' for section ' . ($existing['section_name'] ?? $existing['section_id']) . '.',
                        'warning',
                        'modules/teachers/my_sections.php'
                    );

                    $_SESSION['success_message'] = 'Teacher unassigned from ' . ($existing['subject_name'] ?? $existing['subject_code']) . '.';
                } else {
                    $_SESSION['error_message'] = 'Failed to unassign subject.';
                }
            }
        }
    }
    header('Location: index.php?grade=' . urlencode($selected_grade) . '&strand=' . urlencode($selected_strand) . '&section=' . $selected_section . '&subject=' . urlencode($selected_subject));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_adviser') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token.';
    } else {
        $adviser_section_id = intval($_POST['section_id'] ?? 0);
        $adviser_id = intval($_POST['adviser_id'] ?? 0);

        if (empty($adviser_section_id)) {
            $_SESSION['error_message'] = 'Invalid section.';
        } else {
            $new_adviser_id = $adviser_id > 0 ? $adviser_id : null;
            $result = executeQuery("UPDATE sections SET adviser_id = ? WHERE section_id = ?", [$new_adviser_id, $adviser_section_id]);

            if ($result) {
                $sec_name = getRecord("SELECT section_name FROM sections WHERE section_id = ?", [$adviser_section_id]);
                if ($new_adviser_id) {
                    $adv = getRecord("SELECT first_name, last_name FROM users WHERE user_id = ?", [$new_adviser_id]);
                    logActivity(
                        $_SESSION['user_id'],
                        'Assigned section adviser',
                        'teachers',
                        "Set {$adv['first_name']} {$adv['last_name']} as adviser of " . ($sec_name['section_name'] ?? $adviser_section_id)
                    );
                    createNotification(
                        $new_adviser_id,
                        'New Adviser Assignment',
                        'You have been set as the adviser of section ' . ($sec_name['section_name'] ?? $adviser_section_id) . '.',
                        'success',
                        'modules/teachers/my_sections.php'
                    );
                    $_SESSION['success_message'] = 'Adviser assigned successfully!';
                } else {
                    logActivity(
                        $_SESSION['user_id'],
                        'Removed section adviser',
                        'teachers',
                        "Removed adviser from " . ($sec_name['section_name'] ?? $adviser_section_id)
                    );
                    $_SESSION['success_message'] = 'Adviser removed.';
                }
            } else {
                $_SESSION['error_message'] = 'Failed to update adviser.';
            }
        }
    }
    header('Location: index.php?grade=' . urlencode($selected_grade) . '&strand=' . urlencode($selected_strand) . '&section=' . $selected_section . '&subject=' . urlencode($selected_subject));
    exit;
}

// ---- Term Dates auto-save (one term at a time, called by the modal as soon as
// both of that term's dates are filled in). Same table / same rules as the
// "Save Term Dates" button below (term_windows via gh_setTermWindow), so
// Encode Grades, Manage Assessment Items and the dashboards pick it up.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_term_schedule_one') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $td_reply = function ($ok, $message, $extra = []) {
        echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
        exit;
    };
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) $td_reply(false, 'Your session expired. Please reload the page.');
    if (empty($school_year_id)) $td_reply(false, 'No active school year is set.');
    $td_term = (string) ($_POST['term'] ?? '');
    $td_start = trim((string) ($_POST['start'] ?? ''));
    $td_end = trim((string) ($_POST['end'] ?? ''));
    if (!in_array($td_term, $valid_terms, true)) $td_reply(false, 'Unknown term.');
    if (!DateTime::createFromFormat('Y-m-d', $td_start) || !DateTime::createFromFormat('Y-m-d', $td_end)) $td_reply(false, 'Please enter a valid start and end date.');
    if ($td_start > $td_end) $td_reply(false, 'Start date must be on or before the end date.');
    foreach ($valid_terms as $other) {
        if ($other === $td_term) continue;
        $ow = gh_getTermWindow($school_year_id, $other);
        if ($ow && !empty($ow['start_date']) && !empty($ow['end_date']) && $td_start <= $ow['end_date'] && $ow['start_date'] <= $td_end) {
            $td_reply(false, 'Overlaps with ' . $other . ' (' . date('M j, Y', strtotime($ow['start_date'])) . ' – ' . date('M j, Y', strtotime($ow['end_date'])) . '). Term dates cannot overlap.');
        }
    }
    if (!gh_setTermWindow($school_year_id, $td_term, $td_start, $td_end, $_SESSION['user_id'])) {
        $td_reply(false, 'Could not save: ' . ($GLOBALS['gh_term_window_error'] ?: 'database error') . '');
    }
    logActivity($_SESSION['user_id'], 'teachers', 'Updated term dates', "Auto-saved $td_term: $td_start to $td_end (" . ($active_sy['sy_name'] ?? $school_year_id) . ')');
    $td_status = gh_termWindowStatus(gh_getTermWindow($school_year_id, $td_term));
    $td_reply(true, 'Saved', ['term' => $td_term, 'start' => $td_start, 'end' => $td_end, 'status' => $td_status['label']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_term_schedule') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token.';
    } elseif (empty($school_year_id)) {
        $_SESSION['error_message'] = 'No active school year is set.';
    } else {
        $starts = $_POST['term_start'] ?? [];
        $ends = $_POST['term_end'] ?? [];
        $errors = [];
        $to_save = [];

        foreach ($valid_terms as $t) {
            $start = trim($starts[$t] ?? '');
            $end = trim($ends[$t] ?? '');

            // A term left completely blank is simply skipped -- it's fine
            // to save just one or two terms' dates now and add the rest
            // later, rather than requiring all three at once.
            if ($start === '' && $end === '') {
                continue;
            }
            if ($start === '' || $end === '') {
                $errors[] = "$t: needs both a start and end date (or leave both blank to skip it for now).";
                continue;
            }
            if (!DateTime::createFromFormat('Y-m-d', $start) || !DateTime::createFromFormat('Y-m-d', $end)) {
                $errors[] = "$t: invalid date.";
                continue;
            }
            if ($start > $end) {
                $errors[] = "$t: start date must be on or before the end date.";
                continue;
            }
            $to_save[$t] = ['start' => $start, 'end' => $end];
        }

        if (empty($errors) && empty($to_save)) {
            $errors[] = 'Enter at least one term\'s start and end date.';
        }

        // Reject overlapping ranges so at most one term is ever "current" on
        // any given day -- otherwise which term's subjects show up would be
        // ambiguous.
        if (empty($errors)) {
            $ranges = array_values($to_save);
            for ($i = 0; $i < count($ranges); $i++) {
                for ($j = $i + 1; $j < count($ranges); $j++) {
                    if ($ranges[$i]['start'] <= $ranges[$j]['end'] && $ranges[$j]['start'] <= $ranges[$i]['end']) {
                        $errors[] = 'Term date ranges cannot overlap.';
                        break 2;
                    }
                }
            }
        }

        if (!empty($errors)) {
            $_SESSION['error_message'] = 'Could not save term dates: ' . implode(' ', $errors);
        } else {
            // Writes to the SAME term_windows table Manage Assessment Items
            // uses, so a date set from either place shows up in both.
            $save_failed = '';
            foreach ($to_save as $t => $range) {
                if (!gh_setTermWindow($school_year_id, $t, $range['start'], $range['end'], $_SESSION['user_id'])) {
                    $save_failed = "$t: " . ($GLOBALS['gh_term_window_error'] ?: 'database error');
                    break;
                }
            }
            if ($save_failed !== '') {
                $_SESSION['error_message'] = 'Could not save term dates (' . $save_failed . ').';
                header('Location: index.php?grade=' . urlencode($selected_grade) . '&strand=' . urlencode($selected_strand) . '&section=' . $selected_section . '&subject=' . urlencode($selected_subject));
                exit;
            }
            logActivity(
                $_SESSION['user_id'],
                'Updated term dates',
                'teachers',
                'Set Term date ranges for ' . ($active_sy['sy_name'] ?? $school_year_id) . ' (shared with Manage Assessment Items)'
            );
            $_SESSION['success_message'] = 'Term dates saved. Subjects will now switch terms automatically on those dates.';
        }
    }
    header('Location: index.php?grade=' . urlencode($selected_grade) . '&strand=' . urlencode($selected_strand) . '&section=' . $selected_section . '&subject=' . urlencode($selected_subject));
    exit;
}

$all_teachers_for_adviser = getRecords("
    SELECT u.user_id, u.first_name, u.last_name
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    WHERE r.role_name = 'Teacher' AND u.is_active = 1
    ORDER BY u.last_name, u.first_name
");

include '../../includes/header.php';
?>
<style>
    .tm-page {
        background: var(--bg, #F6F7F9);
    }

    .tm-page .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
    }

    /* Page title sized/weighted to match the "Student Management" heading
   (a plain Bootstrap h2, which the shared stylesheet renders at 32px/600
   navy) so both pages read at the same title scale. */
    .tm-page .page-header h4 {
        font-size: 32px;
        font-weight: 600;
        color: var(--ink);
        margin: 0;
    }

    /* Same button proportions as the shared .btn class Student Management's
   Bootstrap buttons use (border-radius 7px, 13px/600 text, 9px 16px
   padding) so "Add Teacher" reads like "Recycle Bin" / "Bulk Import".
   Gradient matches the "Add Student" button on the Students page, so the
   two primary "create a record" actions look like the same family. */
    .tm-page .btn-primary-action {
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
        box-shadow: 0 1px 2px rgba(16,24,40,.04);
        transition: background 0.15s, border-color 0.15s;
    }

    .tm-page .btn-primary-action:hover {
        filter: brightness(1.05);
        color: #fff;
    }

    /* Page header icon box, to match the Students page header treatment. */
    .tm-page .page-header-icon {
        width: 54px;
        height: 54px;
        border-radius: 16px;
        background: var(--primary-soft);
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        flex: 0 0 auto;
    }
    .tm-page .page-header-main {
        display: flex;
        align-items: flex-start;
        gap: 16px;
    }
    .tm-page .page-header p {
        font-size: 14px;
        color: var(--text-muted);
        margin: 4px 0 0 0;
    }

    .tm-page .section-title {
        font-size: 14px;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .tm-page .section-title i {
        font-size: 13px;
    }

    .tm-page .grade-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 16px;
        margin-bottom: 24px;
    }

    /* Grade Level cards (and the strand cards below, which reuse the same
       .grade-card class) now come from the shared global .grade-card /
       .grade-selected-pill component in static/css/style.css — the exact
       same component used by Student Management, Subject Management and
       Class Records, so this page no longer redefines its own radius/
       shadow/typography for it. Colour still comes from the inline
       --accent/--accent-soft custom properties set in the PHP loops below. */

    .tm-page .content-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 8px;
        overflow: hidden;
        margin-bottom: 20px;
    }

    .tm-page .card-header-bar {
        padding: 16px 20px;
        border-bottom: 1px solid var(--border-soft);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .tm-page .card-header-bar h5 {
        font-size: 16px;
        font-weight: 600;
        color: var(--ink);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .tm-page .card-header-bar h5 i {
        font-size: 15px;
        color: var(--ink);
    }

    .tm-page .card-header-actions {
        display: flex;
        gap: 6px;
    }

    .tm-page .btn-sm-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        border-radius: 5px;
        font-size: 13px;
        font-weight: 500;
        color: var(--text-muted);
        background: var(--surface-hover);
        border: 1px solid var(--border);
        text-decoration: none;
        transition: all 0.15s;
    }

    .tm-page .btn-sm-link:hover {
        background: #14213D;
        color: #fff;
        border-color: var(--ink);
    }

    .tm-page .badge-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 26px;
        height: 26px;
        border-radius: 13px;
        background: var(--surface-hover);
        font-size: 13px;
        font-weight: 600;
        color: var(--text-muted);
        padding: 0 8px;
    }

    .tm-page .strand-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
    }

    .tm-page .section-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
        gap: 10px;
    }

    /* .section-card also now comes from the shared global component in
       static/css/style.css — the same one Student Management and Subject
       Management use — so Teacher and Student section selectors are the
       exact same component (border/radius/hover/active all match). */

    .tm-page .adviser-bar {
        padding: 16px 20px;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 12px;
    }

    .tm-page .adviser-bar .current-label {
        font-size: 14px;
        color: var(--text-muted);
    }

    .tm-page .adviser-bar .current-name {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 500;
    }

    .tm-page .adviser-bar .current-name.has {
        background: #EAF7F1;
        color: #238863;
    }

    .tm-page .adviser-bar .current-name.none {
        background: var(--surface-hover);
        color: var(--text-muted);
    }

    .tm-page .adviser-form {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .tm-page .adviser-form select {
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: 7px 12px;
        font-size: 13px;
        color: var(--ink);
        background: var(--surface);
        width: 240px;
    }

    .tm-page .adviser-form select:focus {
        outline: none;
        border-color: var(--ink);
        box-shadow: 0 0 0 2px rgba(20, 33, 61, 0.08);
    }

    .tm-page .btn-save {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 7px 14px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        color: #fff;
        background: #14213D;
        border: none;
        cursor: pointer;
        transition: all 0.15s;
        white-space: nowrap;
    }

    .tm-page .btn-save:hover {
        filter: brightness(0.9);
    }

    /* Modal footer buttons — sized to be comfortably clickable and clearly
   weighted: the primary action is filled, Cancel is a quiet outline. */
    .btn-modal-primary {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 12px 26px;
        border-radius: 6px;
        font-size: 15px;
        font-weight: 600;
        color: #fff;
        background: #14213D;
        border: 1px solid #14213D;
        cursor: pointer;
        transition: all 0.15s;
        white-space: nowrap;
    }

    .btn-modal-primary:hover:not(:disabled) {
        filter: brightness(0.9);
    }

    .btn-modal-primary:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .btn-modal-primary:focus-visible {
        outline: 2px solid #14213D;
        outline-offset: 2px;
    }

    .btn-modal-cancel {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 12px 26px;
        border-radius: 6px;
        font-size: 15px;
        font-weight: 500;
        color: var(--text-muted);
        background: var(--surface);
        border: 1px solid var(--border);
        cursor: pointer;
        transition: all 0.15s;
        white-space: nowrap;
    }

    .btn-modal-cancel:hover {
        background: var(--surface-hover);
        color: var(--ink);
    }

    .btn-modal-cancel:focus-visible {
        outline: 2px solid #667085;
        outline-offset: 2px;
    }

    /* Checkbox list of subjects inside the assign modal. */
    .assign-subject-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 11px 14px;
        font-size: 15px;
        color: var(--ink);
        border-bottom: 1px solid var(--border-soft);
        cursor: pointer;
        margin: 0;
    }

    .assign-subject-row:last-child {
        border-bottom: none;
    }

    .assign-subject-row:hover {
        background: var(--surface-hover);
    }

    .assign-subject-row input[type="checkbox"] {
        width: 17px;
        height: 17px;
        flex-shrink: 0;
        cursor: pointer;
    }

    .assign-subject-row.is-taken {
        color: var(--text-muted-2);
        cursor: not-allowed;
        background: var(--surface-hover);
    }

    .assign-subject-row.is-taken:hover {
        background: var(--surface-hover);
    }

    .assign-subject-row.is-taken input[type="checkbox"] {
        cursor: not-allowed;
    }

    .assign-taken-note {
        margin-left: auto;
        font-size: 13px;
        color: var(--text-muted-2);
        white-space: nowrap;
    }

    /* Search/specialization/status filter panel above the teacher table. */
    .tm-page .tm-filter-bar {
        background: var(--surface-hover);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 14px 16px;
        margin: 16px 20px 0 20px;
    }
    .tm-page .tm-filter-label {
        font-size: 11.5px;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin-bottom: 4px;
        display: block;
    }

    /* Teacher name avatar initials circle. */
    .tm-page .tm-avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        font-size: 12px;
        font-weight: 700;
        margin-right: 8px;
        vertical-align: middle;
    }

    /* Pagination footer for the teacher table. */
    .tm-page .tm-pagination {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
        padding: 14px 20px;
        border-top: 1px solid #EEF0F3;
    }
    .tm-page .tm-pagination-info {
        font-size: 13px;
        color: var(--text-muted);
    }
    .tm-page .tm-pagination-btns {
        display: flex;
        gap: 4px;
    }
    .tm-page .tm-page-btn {
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
    .tm-page .tm-page-btn:hover {
        background: var(--surface-hover);
        color: var(--ink);
    }
    .tm-page .tm-page-btn.active {
        background: var(--primary);
        border-color: var(--primary);
        color: #fff;
    }
    .tm-page .tm-page-btn.disabled {
        opacity: 0.4;
        pointer-events: none;
    }

    .tm-page .data-table {
        width: 100%;
        border-collapse: collapse;
    }

    .tm-page .data-table thead th {
        background: var(--surface-hover);
        border-bottom: 1px solid var(--border);
        padding: 14px 16px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-muted);
        text-align: left;
    }

    .tm-page .data-table tbody td {
        padding: 14px 16px;
        font-size: 15px;
        color: var(--ink);
        border-bottom: 1px solid var(--border-soft);
        vertical-align: middle;
    }

    .tm-page .data-table tbody tr:hover {
        background: var(--surface-hover);
    }

    .tm-page .data-table tbody tr:last-child td {
        border-bottom: none;
    }

    .tm-page .data-table tbody tr.row-assigned {
        background: var(--success-tint);
    }

    .tm-page .data-table tbody tr.row-unassigned {
        background: var(--warning-tint);
    }

    .tm-page .teacher-tag {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 5px 14px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 600;
    }

    .tm-page .teacher-tag.has {
        background: var(--success-tint);
        color: var(--success);
    }

    .tm-page .teacher-tag.no {
        background: var(--danger-tint);
        color: var(--danger);
    }

    .tm-page .spec-tag {
        display: inline-block;
        padding: 5px 12px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        background: #F2F4F7;
        color: #344054;
        border: 1px solid #E4E7EC;
    }

    /* Pill-shaped like the status badges in the Student Management tables
   (td .badge there is a 20px/999px-radius pill), rather than the boxier
   6px-radius chip this used before. */
    .tm-page .status-tag {
        display: inline-block;
        padding: 5px 14px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 600;
    }

    .tm-page .status-tag.assigned {
        background: var(--success-tint);
        color: var(--success);
    }

    .tm-page .status-tag.unassigned {
        background: var(--warning-tint);
        color: var(--warning);
    }

    .tm-page .btn-assign-sm {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        color: var(--ink);
        background: var(--surface);
        border: 1px solid var(--border);
        cursor: pointer;
        transition: all 0.15s;
        white-space: nowrap;
    }

    .tm-page .btn-assign-sm:hover {
        background: #14213D;
        color: #fff;
        border-color: var(--ink);
    }

    .tm-page .btn-assign-sm:focus-visible {
        outline: 2px solid #14213D;
        outline-offset: 2px;
    }

    .tm-page .btn-unassign-sm {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        color: #C94A4A;
        background: var(--surface);
        border: 1px solid var(--border);
        cursor: pointer;
        transition: all 0.15s;
        white-space: nowrap;
    }

    .tm-page .btn-unassign-sm:hover {
        background: #C94A4A;
        color: #fff;
        border-color: #C94A4A;
    }

    .tm-page .btn-unassign-sm:focus-visible {
        outline: 2px solid #C94A4A;
        outline-offset: 2px;
    }

    .tm-page .empty-state {
        text-align: center;
        padding: 48px 20px;
        color: var(--text-muted);
    }

    .tm-page .empty-state i {
        font-size: 36px;
        margin-bottom: 12px;
        opacity: 0.4;
        display: block;
    }

    .tm-page .empty-state h5 {
        font-size: 17px;
        margin: 0 0 6px 0;
        color: var(--ink);
    }

    .tm-page .empty-state p {
        font-size: 15px;
        margin: 0;
    }

    .tm-page .btn-actions {
        display: flex;
        gap: 6px;
        align-items: center;
        justify-content: center;
        white-space: nowrap;
    }

    .tm-page .btn-action-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--text-muted);
        text-decoration: none;
        font-size: 15px;
        transition: all 0.15s;
    }

    .tm-page .btn-action-icon:hover {
        background: var(--surface-hover);
        color: var(--ink);
    }

    /* Visible keyboard focus — the icon buttons carry no text label, so
   without this a keyboard user cannot tell which one is selected. */
    .tm-page .btn-action-icon:focus-visible {
        outline: 2px solid #14213D;
        outline-offset: 2px;
    }

    .tm-page .btn-action-icon.danger:hover {
        background: #fdecea;
        color: #C94A4A;
        border-color: #C94A4A;
    }

    .tm-page .btn-action-icon.view:hover {
        background: #14213D;
        color: #fff;
        border-color: var(--ink);
    }

    .tm-page .active-tag {
        display: inline-block;
        padding: 5px 14px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 600;
    }

    .tm-page .active-tag.active {
        background: var(--success-tint);
        color: var(--success);
    }

    .tm-page .active-tag.inactive {
        background: #F2F4F7;
        color: var(--text-muted);
    }

    .tm-page .section-tag {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        background: var(--surface-hover);
        color: var(--ink);
        border: 1px solid var(--border);
        margin: 2px 2px;
    }

    @media (max-width: 768px) {
        .tm-page .grade-grid {
            grid-template-columns: repeat(3, 1fr);
        }

        .tm-page .strand-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .tm-page .adviser-bar {
            flex-direction: column;
            align-items: flex-start;
        }

        .tm-page .adviser-form {
            flex-direction: column;
            width: 100%;
        }

        .tm-page .adviser-form select {
            width: 100%;
        }
    }
</style>
<div class="tm-page" style="padding: 24px;">

    <div class="page-header">
        <div class="page-header-main">
            <div class="page-header-icon"><i class="fas fa-chalkboard-user"></i></div>
            <div>
                <h4>Teacher Management</h4>
                <p><?php echo htmlspecialchars($page_subtitle); ?></p>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <button type="button" class="btn-sm-link" style="padding: 10px 16px; font-size: 14px;" data-bs-toggle="modal" data-bs-target="#editTermDatesModal">
                <i class="fas fa-calendar-alt"></i> Term Dates
            </button>
            <a href="add.php" class="btn-primary-action">
                <i class="fas fa-user-plus"></i> Add Teacher
            </a>
        </div>
    </div>

    <div class="section-title"><i class="fas fa-layer-group"></i> Select Grade Level</div>
    <div class="grade-grid">
        <?php
        // Same per-grade colours as the Students page, so a grade is
        // recognisably the same colour wherever it appears in the system.
        $grade_palette = [
            'Grade 7' => ['accent' => '#2E7D67', 'soft' => '#F1F8F5'],
            'Grade 8' => ['accent' => '#356EA8', 'soft' => '#F2F6FA'],
            'Grade 9' => ['accent' => '#A06A1B', 'soft' => '#FBF7EE'],
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
            $soft = $grade_palette[$grade]['soft'] ?? '#f8f9fb';
            $display = str_replace('Grade ', '', $grade);
            ?>
            <a href="?grade=<?php echo urlencode($grade); ?>&strand=&section=0&subject=0"
                class="grade-card <?php echo $is_active ? 'active' : ''; ?>"
                style="--accent: <?php echo $color; ?>; --accent-soft: <?php echo $soft; ?>;"
                aria-label="Show <?php echo htmlspecialchars($grade); ?>">
                <div class="card-body text-center">
                    <div class="grade-icon">
                        <span class="grade-num"><?php echo $display; ?></span>
                    </div>
                    <div class="grade-label"><?php echo $grade; ?></div>
                    <div class="section-count"><?php echo $has_sections ? count($grade_sections) . ' sections' : 'No sections'; ?>
                    </div>
                    <?php if ($is_active): ?>
                        <span class="grade-selected-pill">Selected</span>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($is_shs_grade && $selected_grade && !$selected_strand && !empty($available_strands)): ?>
        <div class="content-card">
            <div class="card-header-bar">
                <h5><i class="fas fa-layer-group"></i> Select Strand for <?php echo $selected_grade; ?></h5>
            </div>
            <div style="padding: 16px 20px;">
                <div class="strand-grid">
                    <?php foreach ($available_strands as $strand):
                        $strand_count = 0;
                        foreach ($sections_by_grade[$selected_grade] as $sec) {
                            if ($sec['strand'] === $strand) {
                                $strand_count++;
                            }
                        }
                        ?>
                        <a href="?grade=<?php echo urlencode($selected_grade); ?>&strand=<?php echo $strand; ?>&section=0&subject=0"
                            class="grade-card"
                            style="text-align: center; --accent: <?php echo $strand === 'GAS' ? '#0F766E' : '#6D28D9'; ?>; --accent-soft: <?php echo $strand === 'GAS' ? '#EFFAF8' : '#F6F3FE'; ?>;">
                            <div class="card-body text-center">
                                <div class="grade-num" style="font-size: 24px;"><i
                                        class="fas <?php echo $strand === 'GAS' ? 'fa-flask' : 'fa-users'; ?>"></i></div>
                                <div class="grade-label"><?php echo $strand === 'ASSH' ? 'ASSH Cluster' : $strand; ?></div>
                                <div class="section-count"><?php echo $strand_count; ?> sections</div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($is_shs_grade && $selected_grade && $selected_strand && empty($filtered_sections)): ?>
        <div class="content-card">
            <div class="card-header-bar">
                <h5>
                    <i class="fas fa-list"></i>
                    Sections in <?php echo $selected_grade; ?> -
                    <?php echo $selected_strand === 'ASSH' ? 'ASSH Cluster' : $selected_strand; ?>
                    <span class="badge-count">0</span>
                </h5>
                <div class="card-header-actions">
                    <a href="?grade=<?php echo urlencode($selected_grade); ?>&strand=&section=0&subject=0"
                        class="btn-sm-link"><i class="fas fa-arrow-left"></i> Strands</a>
                </div>
            </div>
            <div style="padding: 24px; text-align: center; color: var(--text-muted, #888);">
                No sections have been created yet for <?php echo $selected_grade; ?> -
                <?php echo $selected_strand === 'ASSH' ? 'ASSH Cluster' : $selected_strand; ?>.
                Add one from Settings first.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($selected_grade && !empty($filtered_sections)): ?>
        <div class="content-card">
            <div class="card-header-bar">
                <h5>
                    <i class="fas fa-list"></i>
                    Sections in <?php echo $selected_grade; ?><?php if ($selected_strand): ?> -
                        <?php echo $selected_strand === 'ASSH' ? 'ASSH Cluster' : $selected_strand; ?>    <?php endif; ?>
                    <span class="badge-count"><?php echo count($filtered_sections); ?></span>
                </h5>
                <div class="card-header-actions">
                    <?php if ($is_shs_grade && $selected_strand): ?>
                        <a href="?grade=<?php echo urlencode($selected_grade); ?>&strand=&section=0&subject=0"
                            class="btn-sm-link"><i class="fas fa-arrow-left"></i> Strands</a>
                    <?php endif; ?>
                    <a href="?grade=0&strand=&section=0&subject=0" class="btn-sm-link"><i class="fas fa-times"></i>
                        Clear</a>
                </div>
            </div>
            <div style="padding: 16px 20px;">
                <div class="section-grid">
                    <?php foreach ($filtered_sections as $sec):
                        $is_selected = ($selected_section == $sec['section_id']);
                        ?>
                        <a href="?grade=<?php echo urlencode($selected_grade); ?>&strand=<?php echo urlencode($selected_strand); ?>&section=<?php echo $sec['section_id']; ?>&subject=0#selected-section-details"
                            class="section-card <?php echo $is_selected ? 'active' : ''; ?>">
                            <div class="sec-name"><?php echo htmlspecialchars($sec['section_name']); ?></div>
                            <div class="sec-meta">
                                <?php if ($is_shs_grade && !empty($sec['strand'])): ?>
                                    <span class="spec-tag"><?php echo htmlspecialchars($sec['strand'] === 'ASSH' ? 'ASSH Cluster' : $sec['strand']); ?></span><br>
                                <?php endif; ?>
                                <?php echo $sec['current_enrollment'] ?? 0; ?>/<?php echo $sec['capacity'] ?? 45; ?> enrolled
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($selected_section > 0 && $section_info): ?>
        <div class="content-card" id="selected-section-details" tabindex="-1" style="scroll-margin-top: 20px;">
            <div class="card-header-bar">
                <h5><i class="fas fa-chalkboard-teacher"></i> Section Adviser</h5>
            </div>
            <?php if (isset($_SESSION['success_message'])): ?>
                <div
                    style="padding: 12px 20px; background: #EAF7F1; color: #238863; font-size: 13px; border-bottom: 1px solid var(--border-soft);">
                    <i class="fas fa-check-circle" style="margin-right: 4px;"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_message'])): ?>
                <div
                    style="padding: 12px 20px; background: #FDEEEE; color: #C94A4A; font-size: 13px; border-bottom: 1px solid var(--border-soft);">
                    <i class="fas fa-exclamation-circle" style="margin-right: 4px;"></i>
                    <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>
            <div class="adviser-bar">
                <div>
                    <div class="current-label">Currently assigned</div>
                    <?php if ($current_adviser): ?>
                        <span class="current-name has"><i class="fas fa-user-check"></i>
                            <?php echo htmlspecialchars($current_adviser['last_name'] . ', ' . $current_adviser['first_name']); ?></span>
                    <?php else: ?>
                        <span class="current-name none">No adviser assigned</span>
                    <?php endif; ?>
                </div>
                <form method="POST" action="" class="adviser-form">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="set_adviser">
                    <input type="hidden" name="section_id" value="<?php echo $selected_section; ?>">
                    <select name="adviser_id">
                        <option value="0">-- No adviser --</option>
                        <?php foreach ($all_teachers_for_adviser as $t): ?>
                            <option value="<?php echo $t['user_id']; ?>" <?php echo ($current_adviser && $current_adviser['user_id'] == $t['user_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['last_name'] . ', ' . $t['first_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($selected_section > 0 && $section_info): ?>
        <div class="content-card">
            <div class="card-header-bar">
                <h5>
                    <i class="fas fa-book"></i>
                    Subjects in <?php echo htmlspecialchars($section_info['section_name']); ?>
                    <span class="badge-count"><?php echo count($section_subjects); ?></span>
                </h5>
                <div class="card-header-actions">
                    <a href="?grade=<?php echo urlencode($selected_grade); ?>&strand=<?php echo urlencode($selected_strand); ?>&section=0&subject=0"
                        class="btn-sm-link"><i class="fas fa-arrow-left"></i> Sections</a>
                    <?php $term_ready = !$is_shs || $selected_term !== ''; ?>
                    <button class="btn-primary-action" style="padding: 8px 16px; font-size: 14px;"
                        <?php echo $term_ready ? 'data-bs-toggle="modal" data-bs-target="#assignSubjectModal"' : 'disabled title="No term is active today -- set up Term Dates first"'; ?>><i
                            class="fas fa-plus"></i> Assign</button>
                </div>
            </div>
            <?php if ($is_shs): ?>
                <div style="padding: 14px 20px; border-bottom: 1px solid var(--border-soft, #eee); display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <?php if ($selected_term !== '' && isset($term_windows_by_term[$selected_term])): ?>
                        <span style="display:inline-flex; align-items:center; gap:6px; padding: 6px 14px; border-radius: 8px; background:#0F766E; color:#fff; font-weight:600; font-size: 13px;">
                            <i class="fas fa-calendar-check"></i> Now showing: <?php echo htmlspecialchars($selected_term); ?>
                        </span>
                        <span style="font-size: 12px; color: var(--text-muted);">
                            (<?php echo formatDate($term_windows_by_term[$selected_term]['start_date']); ?> &ndash; <?php echo formatDate($term_windows_by_term[$selected_term]['end_date']); ?>) &mdash; switches automatically
                        </span>
                    <?php else: ?>
                        <span style="display:inline-flex; align-items:center; gap:6px; padding: 6px 14px; border-radius: 8px; background:#FDECEA; color:#B3261E; font-weight:600; font-size: 13px;">
                            <i class="fas fa-calendar-times"></i> No term is active today
                        </span>
                        <span style="font-size: 12px; color: var(--text-muted);">
                            Set the Term 1/2/3 date ranges using "Term Dates" above.
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (count($section_subjects) > 0): ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Subject Code</th>
                                <th>Subject Name</th>
                                <th>Assigned Teacher</th>
                                <th>Specialization</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; ?>
                            <?php foreach ($section_subjects as $subject):
                                $has_teacher = !empty($subject['teacher_id']);
                                $teacher_name = $has_teacher ? $subject['teacher_first'] . ' ' . $subject['teacher_last'] : 'Not Assigned';
                                $specialization = $subject['specialization'] ?? 'N/A';
                                ?>
                                <tr class="<?php echo $has_teacher ? 'row-assigned' : 'row-unassigned'; ?>">
                                    <td><?php echo $counter++; ?></td>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                    <td>
                                        <span class="teacher-tag <?php echo $has_teacher ? 'has' : 'no'; ?>">
                                            <i class="fas <?php echo $has_teacher ? 'fa-user-check' : 'fa-user-times'; ?>"></i>
                                            <?php echo htmlspecialchars($teacher_name); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($has_teacher && $specialization != 'N/A'): ?>
                                            <span class="spec-tag"><?php echo htmlspecialchars($specialization); ?></span>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($has_teacher): ?>
                                            <span class="status-tag assigned">Assigned</span>
                                        <?php else: ?>
                                            <span class="status-tag unassigned">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space: nowrap;">
                                        <div class="btn-actions">
                                            <?php if ($has_teacher): ?>
                                                <?php
                                                $unassign_msg = 'Remove ' . $teacher_name . ' from '
                                                    . $subject['subject_name'] . ' in ' . $section_info['section_name']
                                                    . '? The subject will have no teacher until you assign one.';
                                                ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="action" value="unassign_subject">
                                                    <input type="hidden" name="ts_id" value="<?php echo (int) $subject['ts_id']; ?>">
                                                    <button type="submit" class="btn-unassign-sm"
                                                        aria-label="Unassign <?php echo htmlspecialchars($teacher_name); ?> from <?php echo htmlspecialchars($subject['subject_name']); ?>"
                                                        onclick="return appConfirm(this, <?php echo htmlspecialchars(json_encode($unassign_msg), ENT_QUOTES); ?>, {danger: true, okText: 'Unassign', title: 'Unassign Teacher'})">
                                                        <i class="fas fa-user-minus"></i> Unassign
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn-assign-sm"
                                                    onclick="openAssignModal(<?php echo htmlspecialchars(json_encode($subject['subject_code']), ENT_QUOTES); ?>)"
                                                    aria-label="Assign a teacher to <?php echo htmlspecialchars($subject['subject_name']); ?>">
                                                    <i class="fas fa-user-plus"></i> Assign
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($is_shs && $selected_term === ''): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <h5>No term is active today</h5>
                    <p>Set the Term 1, Term 2 and Term 3 date ranges using the "Term Dates" button above -- subjects will then switch automatically as each term's dates arrive.</p>
                </div>
            <?php elseif ($is_shs): ?>
                <div class="empty-state">
                    <i class="fas fa-book"></i>
                    <h5>No <?php echo htmlspecialchars($selected_term); ?> subjects found</h5>
                    <p>No <?php echo htmlspecialchars($section_info['grade_level'] . ($section_info['strand'] ? ' ' . ($section_info['strand'] === 'ASSH' ? 'ASSH Cluster' : $section_info['strand']) : '')); ?> subjects are configured for <?php echo htmlspecialchars($selected_term); ?>.</p>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-book"></i>
                    <h5>No subjects found for this section</h5>
                    <p>Please ensure subjects are added to the system for <?php echo $section_info['grade_level']; ?>.</p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="content-card">
        <div class="card-header-bar">
            <h5>
                <i class="fas fa-users"></i>
                <?php if ($selected_grade): ?>
                    Teachers handling <?php echo $selected_grade; ?><?php if ($selected_strand): ?> -
                        <?php echo $selected_strand === 'ASSH' ? 'ASSH Cluster' : $selected_strand; ?>    <?php endif; ?>
                <?php else: ?>
                    All Teachers
                <?php endif; ?>
                <span class="badge-count"><?php echo count($teachers); ?></span>
            </h5>
            <?php if ($selected_grade): ?>
                <a href="?grade=0&strand=&section=0&subject=0" class="btn-sm-link"><i class="fas fa-times"></i> Clear
                    Filter</a>
            <?php endif; ?>
        </div>
        <?php if (!$selected_grade): ?>
        <!-- Real search + filters — genuine GET params wired to the SQL WHERE
             clause above (see index.php's query section), not decoration.
             Only shown for the "All Teachers" view: when a grade is picked,
             the list is already scoped by that drill-down and its own
             "Clear Filter" link (existing behaviour, unchanged). -->
        <div class="tm-filter-bar">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-4 col-6">
                    <label class="tm-filter-label">Search</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="q" class="form-control border-start-0"
                               value="<?php echo htmlspecialchars($search_q); ?>"
                               placeholder="Search teacher...">
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <label class="tm-filter-label">Specialization</label>
                    <select name="spec" class="form-select">
                        <option value="">All Specializations</option>
                        <?php foreach ($specialization_options as $so): ?>
                            <option value="<?php echo htmlspecialchars($so['specialization']); ?>" <?php echo $filter_spec === $so['specialization'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($so['specialization']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-6">
                    <label class="tm-filter-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-2 col-6 d-flex gap-2">
                    <button class="btn btn-outline-secondary flex-grow-1" title="Apply filters"><i class="fas fa-filter"></i></button>
                    <?php if ($search_q !== '' || $filter_spec !== '' || $filter_status !== ''): ?>
                        <a href="?" class="btn btn-outline-secondary" title="Clear"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php endif; ?>
        <?php if (count($teachers) > 0): ?>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Teacher Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Specialization</th>
                            <th>Sections</th>
                            <th>Status</th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = ($page - 1) * $per_page + 1; ?>
                        <?php foreach ($teachers_page as $teacher): ?>
                            <?php
                            $assigned_sections = getRecords("
                                SELECT DISTINCT s.section_name, s.grade_level, s.strand
                                FROM teacher_subjects ts
                                JOIN sections s ON ts.section_id = s.section_id
                                WHERE ts.teacher_id = ?
                                AND ts.school_year_id = ?
                                AND s.grade_level = ?
                                ORDER BY s.section_name
                            ", [$teacher['user_id'], $school_year_id, $selected_grade ?: 'Grade 7']);

                            $section_names = [];
                            foreach ($assigned_sections as $sec) {
                                $section_names[] = $sec['section_name'];
                            }

                            $initials = strtoupper(substr($teacher['first_name'], 0, 1) . substr($teacher['last_name'], 0, 1));
                            $spec_color = spec_badge_color($teacher['specialization'] ?? '', $spec_palette);
                            ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td style="font-weight: 600;">
                                    <span class="tm-avatar" style="background:<?php echo $spec_color['bg']; ?>;color:<?php echo $spec_color['fg']; ?>;"><?php echo htmlspecialchars($initials); ?></span>
                                    <?php echo htmlspecialchars($teacher['last_name'] . ', ' . $teacher['first_name']); ?></td>
                                <td><?php echo htmlspecialchars($teacher['username']); ?></td>
                                <td><?php echo htmlspecialchars($teacher['email']); ?></td>
                                <td>
                                    <?php if (!empty($teacher['specialization'])): ?>
                                        <span class="spec-tag" style="background:<?php echo $spec_color['bg']; ?>;color:<?php echo $spec_color['fg']; ?>;border-color:transparent;"><?php echo htmlspecialchars($teacher['specialization']); ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (count($section_names) > 0): ?>
                                        <?php foreach ($section_names as $name): ?>
                                            <span class="section-tag"><?php echo $name; ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">No sections</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="active-tag <?php echo $teacher['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $teacher['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td style="white-space: nowrap;">
                                    <div class="btn-actions">
                                        <a href="view.php?id=<?php echo $teacher['user_id']; ?>" class="btn-action-icon view"
                                            title="View Profile" aria-label="View profile"><i class="fas fa-eye"></i></a>
                                        <a href="edit.php?id=<?php echo $teacher['user_id']; ?>" class="btn-action-icon"
                                            title="Edit" aria-label="Edit teacher"><i class="fas fa-edit"></i></a>
                                        <a href="assign_students.php?id=<?php echo $teacher['user_id']; ?>&grade=<?php echo urlencode($selected_grade); ?>"
                                            class="btn-action-icon" title="Assign Students" aria-label="Assign students"><i
                                                class="fas fa-user-graduate"></i></a>
                                        <a href="delete.php?id=<?php echo $teacher['user_id']; ?>"
                                            class="btn-action-icon danger" title="Delete" aria-label="Delete teacher"
                                            onclick="return appConfirm(this, <?php echo htmlspecialchars(json_encode('Are you sure you want to delete teacher: ' . $teacher['last_name'] . ', ' . $teacher['first_name'] . '? This action cannot be undone.'), ENT_QUOTES); ?>, {danger: true, okText: 'Delete'})"><i
                                                class="fas fa-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_pages > 1): ?>
            <div class="tm-pagination">
                <span class="tm-pagination-info">Showing <?php echo count($teachers_page); ?> of <?php echo $teachers_total; ?> teachers</span>
                <div class="tm-pagination-btns">
                    <?php
                    $qs = $_GET;
                    $qs['page'] = max(1, $page - 1);
                    ?>
                    <a class="tm-page-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?<?php echo http_build_query($qs); ?>"><i class="fas fa-chevron-left"></i></a>
                    <?php for ($p = 1; $p <= $total_pages; $p++): $qs['page'] = $p; ?>
                        <a class="tm-page-btn <?php echo $p === $page ? 'active' : ''; ?>" href="?<?php echo http_build_query($qs); ?>"><?php echo $p; ?></a>
                    <?php endfor; ?>
                    <?php $qs['page'] = min($total_pages, $page + 1); ?>
                    <a class="tm-page-btn <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="?<?php echo http_build_query($qs); ?>"><i class="fas fa-chevron-right"></i></a>
                </div>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-chalkboard-teacher"></i>
                <h5><?php echo $selected_grade ? 'No teachers assigned to ' . $selected_grade : 'No teachers found'; ?></h5>
                <p><?php echo $selected_grade ? 'Please assign teachers to sections first.' : 'Click "Add Teacher" to create a new teacher.'; ?>
                </p>
            </div>
        <?php endif; ?>
    </div>

</div>

<div class="modal fade" id="editTermDatesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: 8px; border: 1px solid var(--border);">
            <div class="modal-header" style="border-bottom: 1px solid var(--border-soft); padding: 18px 24px;">
                <h5 class="modal-title" style="font-size: 19px; font-weight: 600; color: var(--ink);">
                    <i class="fas fa-calendar-alt" style="margin-right: 8px;"></i>Term Dates<?php echo $active_sy ? ' &mdash; ' . htmlspecialchars($active_sy['sy_name']) : ''; ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body" style="padding: 24px;">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="save_term_schedule">
                    <p style="font-size: 14px; color: var(--text-muted); margin-bottom: 20px;">
                        Set when each term starts and ends for Grade 11 and Grade 12. Subjects under
                        "Assign Subject to Teacher" will switch automatically once today falls inside a
                        term's range -- there is no need to pick a term by hand.
                    </p>
                    <p style="font-size: 12px; color: var(--text-muted); margin-top: -12px; margin-bottom: 20px;">
                        It's fine to fill in just one term for now and leave the rest blank -- add the others later.
                    </p>
                    <p style="font-size: 12px; color: var(--text-muted); margin-top: -12px; margin-bottom: 20px;">
                        <i class="fas fa-bolt" style="color:#0F7B4E;"></i> Dates save automatically as soon as a term's start and end are both filled in,
                        and they show up right away in Encode Grades and Manage Assessment Items for teachers.
                    </p>
                    <?php foreach ($valid_terms as $t): $ts = $term_windows_by_term[$t] ?? null; ?>
                        <div style="margin-bottom: 18px;">
                            <label style="display:block; font-size: 14px; font-weight: 600; color: var(--ink); margin-bottom: 8px;"><?php echo htmlspecialchars($t); ?></label>
                            <div style="display:flex; gap: 10px; align-items:center; flex-wrap: wrap;">
                                <input type="date" name="term_start[<?php echo htmlspecialchars($t); ?>]" data-term="<?php echo htmlspecialchars($t); ?>" class="form-control td-date" style="font-size: 14px; max-width: 170px;" value="<?php echo htmlspecialchars($ts['start_date'] ?? ''); ?>">
                                <span style="color: var(--text-muted);">to</span>
                                <input type="date" name="term_end[<?php echo htmlspecialchars($t); ?>]" data-term="<?php echo htmlspecialchars($t); ?>" class="form-control td-date" style="font-size: 14px; max-width: 170px;" value="<?php echo htmlspecialchars($ts['end_date'] ?? ''); ?>">
                            </div>
                            <div class="td-status" data-term="<?php echo htmlspecialchars($t); ?>" role="status" aria-live="polite"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-soft); padding: 18px 24px; gap: 10px;">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-modal-primary"><i class="fas fa-save"></i> Save Term Dates</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .td-status { font-size: 12px; font-weight: 600; margin-top: 6px; min-height: 0; }
    .td-status:empty { display: none; }
    .td-status.ok { color: #0F7B4E; }
    .td-status.err { color: #B3261E; }
    .td-status.busy, .td-status.hint { color: var(--text-muted); font-weight: 500; }
</style>
<script>
// Term Dates: save each term automatically once both of its dates are set.
(function () {
    var modal = document.getElementById('editTermDatesModal');
    if (!modal) return;
    var csrf = modal.querySelector('input[name="csrf_token"]').value;
    var timers = {}, seq = {}, savedSomething = false;

    function setStatus(term, kind, msg) {
        var el = modal.querySelector('.td-status[data-term="' + term + '"]');
        if (!el) return;
        el.className = 'td-status ' + kind;
        el.textContent = msg;
    }
    function autosave(term) {
        var s = modal.querySelector('input[name="term_start[' + term + ']"]').value;
        var e = modal.querySelector('input[name="term_end[' + term + ']"]').value;
        if (!s && !e) { setStatus(term, '', ''); return; }
        if (!s || !e) { setStatus(term, 'hint', 'Pick both the start and end date to save this term.'); return; }
        if (s > e) { setStatus(term, 'err', 'Start date must be on or before the end date.'); return; }
        var id = seq[term] = (seq[term] || 0) + 1;
        setStatus(term, 'busy', 'Saving\u2026');
        var fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'save_term_schedule_one');
        fd.append('term', term);
        fd.append('start', s);
        fd.append('end', e);
        fetch(window.location.href.split('#')[0], { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (id !== seq[term]) return;
                if (j && j.ok) { savedSomething = true; setStatus(term, 'ok', '\u2713 Saved \u2014 ' + j.status); }
                else setStatus(term, 'err', (j && j.message) || 'Could not save these dates.');
            })
            .catch(function () { if (id === seq[term]) setStatus(term, 'err', 'Could not reach the server. Please try again.'); });
    }
    modal.addEventListener('change', function (ev) {
        var t = ev.target;
        if (!t || !t.classList || !t.classList.contains('td-date')) return;
        var term = t.getAttribute('data-term');
        clearTimeout(timers[term]);
        timers[term] = setTimeout(function () { autosave(term); }, 250);
    });
    // Reload once the window closes so the page (e.g. "Assign Subject", term status) reflects the saved dates.
    modal.addEventListener('hidden.bs.modal', function () { if (savedSomething) window.location.reload(); });
})();
</script>

<div class="modal fade" id="assignSubjectModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 8px; border: 1px solid var(--border);">
            <div class="modal-header" style="border-bottom: 1px solid var(--border-soft); padding: 18px 24px;">
                <h5 class="modal-title" style="font-size: 19px; font-weight: 600; color: var(--ink);"><i
                        class="fas fa-user-plus" style="margin-right: 8px;"></i>Assign Subject to Teacher</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body" style="padding: 24px;">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="assign_subject">
                    <input type="hidden" name="grade_level" value="<?php echo htmlspecialchars($selected_grade); ?>">
                    <input type="hidden" name="strand" value="<?php echo htmlspecialchars($selected_strand); ?>">
                    <input type="hidden" name="section_id" value="<?php echo $selected_section; ?>">

                    <div style="margin-bottom: 20px;">
                        <label for="assignTeacherSelect"
                            style="display: block; font-size: 15px; font-weight: 600; color: var(--text-muted); margin-bottom: 8px;">Select
                            Teacher</label>
                        <select name="teacher_id" id="assignTeacherSelect" class="form-select"
                            style="font-size: 15px; padding: 10px 12px;" required>
                            <option value="">-- Select Teacher --</option>
                            <?php foreach ($all_teachers as $teacher): ?>
                                <option value="<?php echo $teacher['user_id']; ?>">
                                    <?php echo htmlspecialchars($teacher['last_name'] . ', ' . $teacher['first_name']); ?>
                                    <?php if (!empty($teacher['specialization'])): ?>
                                        (<?php echo htmlspecialchars($teacher['specialization']); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php
                    // Subjects that still have no teacher in this section.
                    // Only these can be checked; the rest are listed as
                    // taken so the picture stays complete.
                    $available_subjects = array_filter($section_subjects, function ($s) {
                        return empty($s['teacher_id']); });
                    $taken_subjects = array_filter($section_subjects, function ($s) {
                        return !empty($s['teacher_id']); });
                    ?>

                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                        <label style="font-size: 15px; font-weight: 600; color: var(--text-muted); margin: 0;">Select Subjects</label>
                        <?php if (!empty($available_subjects)): ?>
                            <label
                                style="display: inline-flex; align-items: center; gap: 8px; font-size: 14px; color: var(--ink); cursor: pointer; margin: 0;">
                                <input type="checkbox" id="assignSelectAll"
                                    style="width: 17px; height: 17px; cursor: pointer;">
                                Select all available (<?php echo count($available_subjects); ?>)
                            </label>
                        <?php endif; ?>
                    </div>

                    <div id="assignSubjectList"
                        style="border: 1px solid var(--border); border-radius: 6px; max-height: 260px; overflow-y: auto;">
                        <?php if (empty($available_subjects) && empty($taken_subjects)): ?>
                            <div style="padding: 16px; font-size: 15px; color: var(--text-muted);">No subjects found for this section.
                            </div>
                        <?php endif; ?>

                        <?php foreach ($available_subjects as $subject): ?>
                            <label class="assign-subject-row">
                                <input type="checkbox" name="subject_codes[]"
                                    value="<?php echo htmlspecialchars($subject['subject_code']); ?>"
                                    class="assign-subject-check">
                                <span
                                    style="font-weight: 600; min-width: 92px;"><?php echo htmlspecialchars($subject['subject_code']); ?></span>
                                <span><?php echo htmlspecialchars($subject['subject_name']); ?></span>
                            </label>
                        <?php endforeach; ?>

                        <?php foreach ($taken_subjects as $subject): ?>
                            <label class="assign-subject-row is-taken"
                                title="Unassign the current teacher first to change this">
                                <input type="checkbox" disabled>
                                <span
                                    style="font-weight: 600; min-width: 92px;"><?php echo htmlspecialchars($subject['subject_code']); ?></span>
                                <span><?php echo htmlspecialchars($subject['subject_name']); ?></span>
                                <span class="assign-taken-note">taken by
                                    <?php echo htmlspecialchars($subject['teacher_first'] . ' ' . $subject['teacher_last']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div
                        style="margin-top: 20px; padding: 14px 16px; background: var(--surface-hover); border-radius: 6px; font-size: 15px; color: var(--ink); border: 1px solid #F2F4F7;">
                        <i class="fas fa-info-circle" style="margin-right: 6px;"></i>
                        Assigning to section:
                        <strong><?php echo htmlspecialchars($section_info['section_name'] ?? ''); ?></strong>
                        <?php if ($is_shs && $selected_term !== ''): ?>
                            &mdash; <strong><?php echo htmlspecialchars($selected_term); ?></strong>
                        <?php endif; ?>
                        &mdash; <span id="assignSelectedCount">0 subjects selected</span>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-soft); padding: 18px 24px; gap: 10px;">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-modal-primary" id="assignSubmitBtn">
                        <i class="fas fa-save"></i> Assign Subjects
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    (function () {
        var modalEl = document.getElementById('assignSubjectModal');
        if (!modalEl) return;

        var selectAll = document.getElementById('assignSelectAll');
        var countLabel = document.getElementById('assignSelectedCount');
        var submitBtn = document.getElementById('assignSubmitBtn');

        function boxes() {
            return Array.prototype.slice.call(modalEl.querySelectorAll('.assign-subject-check'));
        }

        function refresh() {
            var all = boxes();
            var checked = all.filter(function (b) { return b.checked; });
            if (countLabel) {
                countLabel.textContent = checked.length === 1
                    ? '1 subject selected'
                    : checked.length + ' subjects selected';
            }
            // Nothing checked means nothing to submit, so the button says so
            // instead of letting the form fail server-side.
            if (submitBtn) {
                submitBtn.disabled = checked.length === 0;
            }
            if (selectAll) {
                selectAll.checked = all.length > 0 && checked.length === all.length;
                selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                boxes().forEach(function (b) { b.checked = selectAll.checked; });
                refresh();
            });
        }
        modalEl.addEventListener('change', function (e) {
            if (e.target.classList.contains('assign-subject-check')) refresh();
        });

        // Clicking "Assign" in a row opens the modal with just that subject
        // ticked; the header button opens it with nothing ticked.
        window.openAssignModal = function (subjectCode) {
            boxes().forEach(function (b) { b.checked = (b.value === subjectCode); });
            refresh();
            modalEl.addEventListener('shown.bs.modal', function onShown() {
                document.getElementById('assignTeacherSelect').focus();
                modalEl.removeEventListener('shown.bs.modal', onShown);
            });
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        };

        modalEl.addEventListener('hidden.bs.modal', function () {
            boxes().forEach(function (b) { b.checked = false; });
            refresh();
        });

        refresh();
    })();
</script>

<?php include '../../includes/footer.php'; ?>