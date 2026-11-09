<?php
// modules/reports/report_functions.php
// Shared report-rendering functions. Each function streams a real,
// branded PDF (via includes/pdf_helpers.php / Dompdf) and ends the
// request — this is a direct 1-click download, not a browser print
// dialog. Included by generate.php, which is the ONLY page that calls
// these.
//
// What each report is for (also shown on the Reports page):
//   SF1  School Register          -- official list of enrolled learners per section (start of the year, LIS)
//   SF5  Promotion & Learning Progress -- end-of-year Completed / Promoted or Not Promoted per section
//   SF9  Learner's Progress Report Card -- given to the parent every term and at year end
//   Grade Summary                 -- per-term monitoring of a section's averages (adviser / principal)
//   At-Risk Student Report        -- one learner's weak subjects + recommended interventions
//
// The DepEd forms follow the SF layouts: DepEd header (School ID, Region,
// Division, District -- from Settings > General), males first then females
// with counts, and signature lines. Grade descriptors come from
// getGradeDescriptor() and the promotion status is the official finalized
// year status (student_year_status: Completed / Promoted or Not Promoted),
// i.e. exactly what Grade Management and the Admin screens show.

require_once __DIR__ . '/../../includes/pdf_helpers.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';

// Look up a student by EITHER their internal Student ID or their LRN.
// The "Student" field on the Generate Report form (and the LRN column
// everyone actually sees in the UI, e.g. the At-Risk list) shows the
// LRN — not the internal auto-increment student_id — so most people
// naturally type the LRN in. Matching only on student_id silently
// failed for that case (blank report), so this checks both.
function findStudentByIdentifier($identifier) {
    $identifier = trim((string)$identifier);
    if ($identifier === '') {
        return null;
    }
    return getRecord(
        "SELECT * FROM students WHERE is_active = 1 AND (student_id = ? OR lrn = ?) LIMIT 1",
        [$identifier, $identifier]
    );
}

// A small, plain (non-PDF) page for "nothing to show" states — e.g. an
// unknown student identifier. There's no report data to brand/paginate,
// so this stays a simple inline HTML message rather than a download.
function reportNotFoundPage($message) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Report Unavailable</title>';
    echo '<style>body{font-family:Arial,sans-serif;padding:40px;color:#333;font-size:16px;} a{color:#5B5FEF;}</style></head><body>';
    echo '<p>' . htmlspecialchars($message) . '</p>';
    echo '<p><a href="index.php">&larr; Back to Reports</a></p>';
    echo '</body></html>';
}

// Bold, light-background summary strip used under a report's main table
// (row counts, pass rates, etc.) — a lighter-weight alternative to a
// <tfoot> that stays readable no matter how the table paginates.
function pdfSummaryLine($text) {
    return '<div style="background:#F3F6F9; border:1px solid #DCE3EC; border-radius:6px; padding:8px 14px; font-size:12.5px; font-weight:bold; color:#14213D; margin-top:6px;">' . $text . '</div>';
}

function currentSchoolYearLabel() {
    $sy = getRecord("SELECT sy_name FROM school_years WHERE is_active = 1");
    return $sy['sy_name'] ?? (date('Y') . '-' . (date('Y') + 1));
}

// ============================================================
// Shared pieces for the DepEd forms
// ============================================================

function rpActiveSy() {
    return getRecord("SELECT * FROM school_years WHERE is_active = 1") ?: ['sy_id' => 0, 'sy_name' => currentSchoolYearLabel(), 'start_date' => null, 'end_date' => null];
}

/** School details for the form headers (Settings > General). */
function rpSchoolInfo() {
    static $info = null;
    if ($info !== null) return $info;
    $info = ['school_name' => getSchoolName(), 'school_id' => '', 'school_region' => '', 'school_division' => '',
             'school_district' => '', 'school_head' => '', 'school_address' => ''];
    foreach (getRecords("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('school_name','school_id','school_region','school_division','school_district','school_head','school_address')") as $r) {
        $info[$r['setting_key']] = trim(html_entity_decode((string)$r['setting_value'], ENT_QUOTES, 'UTF-8'));   // Settings saves values HTML-escaped
    }
    return $info;
}

/** A value for the form, or a blank line when it is not set yet. */
function rpVal($v) {
    $v = trim((string)$v);
    return $v !== '' ? htmlspecialchars($v) : '<span style="color:#98A2B3">________________</span>';
}

/** Section name, grade level and adviser. */
function rpSectionInfo($section_id) {
    if (!$section_id) return null;
    return getRecord(
        "SELECT sec.section_id, sec.section_name, sec.grade_level, sec.adviser_id,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS adviser_name
           FROM sections sec LEFT JOIN users u ON u.user_id = sec.adviser_id
          WHERE sec.section_id = ?", [(int)$section_id]);
}

/** Page-local styles for the DepEd forms (smaller type, grouped tables, signatures). */
function rpStyles() {
    return '<style>
        .rp-deped { text-align:center; font-size:11px; line-height:1.35; color:#344054; margin:0 0 2px; padding-top:0; }
        .rp-deped b { font-size:12.5px; color:#14213D; }
        .rp-form-title { text-align:center; font-size:14.5px; font-weight:bold; color:#14213D; margin:2px 0 8px; }
        .rp-form-title small { display:block; font-size:10.5px; font-weight:normal; color:#667085; }
        table.rp-meta { width:100%; border-collapse:collapse; margin-bottom:8px; }
        table.rp-meta td { border:1px solid #DCE3EC; padding:3px 7px; font-size:10.5px; }
        table.rp-meta td span { color:#667085; }
        table.rp-t { width:100%; border-collapse:collapse; margin-bottom:10px; }
        table.rp-t th { background:#EEF0FF; color:#14213D; font-size:9.5px; font-weight:bold; padding:5px 4px; border:1px solid #C9D1DE; text-transform:uppercase; }
        table.rp-t td { padding:3px 5px; border:1px solid #D7DEE8; font-size:10px; }
        table.rp-t tr.rp-group td { background:#14213D; color:#fff; font-weight:bold; font-size:10px; letter-spacing:1px; }
        table.rp-t tr.rp-sub td { background:#F3F6F9; font-weight:bold; }
        table.rp-t.rp-big th { font-size:10.5px; padding:5px; } table.rp-t.rp-big td { font-size:11px; padding:3px 7px; }
        table.rp-sign { width:100%; border-collapse:collapse; margin-top:10px; }
        table.rp-sign td { border:none; padding:0 14px; text-align:center; font-size:10.5px; vertical-align:bottom; }
        .rp-line { border-top:1px solid #172033; padding-top:3px; font-weight:bold; margin-top:24px; }
        .rp-note { font-size:9.5px; color:#667085; margin:4px 0 8px; }
        .rp-h { font-size:12px; font-weight:bold; color:#14213D; margin:8px 0 4px; border-bottom:2px solid #5B5FEF; padding-bottom:2px; }
    </style>';
}

/**
 * Styles for the SF9 Learner's Performance Report Card ONLY (no other form
 * is affected). Single-page A4 portrait reference layout: white background,
 * dark-blue section headers, light-blue table headings, thin blue borders.
 * Dompdf-safe: side-by-side blocks are tables (no flex/float), and no
 * border-radius is used on inline elements inside table cells.
 */
function rpStylesSf9() {
    return '<style>
        .sf9-frame { border:2px solid #1F4788; padding:10px 12px; }
        .sf9-hd { width:100%; border-collapse:collapse; }
        .sf9-hd td { border:none; vertical-align:middle; padding:0; }
        .sf9-deped-mark { font-size:26px; font-weight:bold; color:#1F4788; line-height:1; }
        .sf9-deped-mark span { color:#D32F2F; }
        .sf9-deped-sub { font-size:7px; color:#1F4788; letter-spacing:1px; }
        .sf9-hd-c { text-align:center; }
        .sf9-hd-c .l1 { font-size:10px; color:#172033; }
        .sf9-hd-c .l2 { font-size:11px; font-weight:bold; color:#172033; }
        .sf9-hd-c .l3 { font-size:9px; color:#172033; }
        .sf9-hd-c .l4 { font-size:9px; color:#172033; }
        .sf9-hd-c .school { font-size:17px; font-weight:bold; color:#1F4788; margin:1px 0; }
        .sf9-hd-c .city { font-size:9px; color:#172033; }
        .sf9-seal { width:78px; height:78px; }
        .sf9-rule { border:none; border-top:1.5px solid #1F4788; margin:6px 0 8px; }
        .sf9-banner { background:#1F4788; color:#fff; text-align:center; padding:7px 6px; margin:0 0 8px; }
        .sf9-banner .t { font-size:15px; font-weight:bold; letter-spacing:.5px; }
        .sf9-banner .s { font-size:10.5px; margin-top:2px; }
        .sf9-sec { background:#1F4788; color:#fff; font-size:10.5px; font-weight:bold; padding:4px 8px; margin:8px 0 0; letter-spacing:.3px; }
        table.sf9-box { width:100%; border-collapse:collapse; }
        table.sf9-box td, table.sf9-box th { border:1px solid #4A6FA5; }
        .sf9-info { font-size:9.5px; padding:3px 7px; color:#172033; vertical-align:middle; }
        .sf9-info .k { display:inline-block; width:118px; }
        .sf9-info .ln { display:inline-block; width:180px; border-bottom:1px solid #1F4788; min-height:11px; }
        .sf9-info .ln-sm { display:inline-block; width:120px; border-bottom:1px solid #1F4788; min-height:11px; }
        table.sf9-gr { width:100%; border-collapse:collapse; }
        table.sf9-gr th { background:#D9E2F3; color:#172033; font-size:9px; font-weight:bold; padding:4px 3px; border:1px solid #4A6FA5; }
        table.sf9-gr td { border:1px solid #4A6FA5; font-size:9.5px; padding:3px 5px; color:#172033; }
        table.sf9-gr td.c { text-align:center; }
        table.sf9-gr td.sub { padding-left:16px; }
        table.sf9-gr tr.sf9-ga td { background:#D9E2F3; font-weight:bold; }
        table.sf9-outer { width:100%; border-collapse:collapse; }
        table.sf9-outer > tbody > tr > td { border:none; vertical-align:top; padding:0; }
        .sf9-blank { border-bottom:1px solid #1F4788; display:inline-block; min-height:11px; }
        .sf9-lines { font-size:9.5px; color:#172033; line-height:2.1; }
        .sf9-cert { font-size:8.5px; color:#172033; line-height:1.5; }
        .sf9-sig { text-align:center; font-size:9px; color:#172033; margin-top:16px; }
        .sf9-sig .rule { border-top:1px solid #172033; margin:0 14px 2px; padding-top:2px; }
        .sf9-fail { color:#C0392B; font-weight:bold; }
    </style>';
}

/**
 * DepEd form header: Republic / DepEd lines, form title and the school
 * block (School ID, School Name, Region, Division, District, School Year),
 * plus any extra cells (Grade Level, Section, Adviser...).
 */
function rpFormHeader($formTitle, $formSubtitle, array $extra = []) {
    $s = rpSchoolInfo();
    $html = rpStyles();
    $html .= '<div class="rp-deped">Republic of the Philippines<br><b>Department of Education</b>'
          . ($s['school_region'] !== '' ? '<br>' . htmlspecialchars($s['school_region']) : '')
          . ($s['school_division'] !== '' ? ' &middot; ' . htmlspecialchars($s['school_division']) : '') . '</div>';
    $html .= '<div class="rp-form-title">' . $formTitle . ($formSubtitle !== '' ? '<small>' . $formSubtitle . '</small>' : '') . '</div>';
    $cells = [
        'School ID' => rpVal($s['school_id']), 'School Name' => rpVal($s['school_name']), 'School Year' => rpVal(currentSchoolYearLabel()),
        'Region' => rpVal($s['school_region']), 'Division' => rpVal($s['school_division']), 'District' => rpVal($s['school_district']),
    ];
    foreach ($extra as $k => $v) $cells[$k] = $v;
    $html .= '<table class="rp-meta"><tr>';
    $i = 0;
    foreach ($cells as $k => $v) {
        if ($i > 0 && $i % 3 === 0) $html .= '</tr><tr>';
        $html .= '<td><span>' . $k . ':</span> <b>' . $v . '</b></td>';
        $i++;
    }
    while ($i % 3 !== 0) { $html .= '<td></td>'; $i++; }
    return $html . '</tr></table>';
}

/** Signature lines: [[label, name, title], ...] */
function rpSignatures(array $cols) {
    $html = '<table class="rp-sign"><tr>';
    foreach ($cols as $c) {
        $html .= '<td><div style="text-align:left;color:#667085">' . $c[0] . '</div>'
              . '<div class="rp-line">' . ($c[1] !== '' ? htmlspecialchars(mb_strtoupper($c[1])) : '&nbsp;') . '</div>' . $c[2] . '</td>';
    }
    return $html . '</tr></table>';
}

/** A table with MALE / FEMALE group rows. $groups = ['MALE' => [rows], 'FEMALE' => [rows]] */
function rpGroupedTable(array $headers, array $groups, array $align = [], $emptyMessage = 'No learners found.', $class = 'rp-t') {
    $n = count($headers);
    $html = '<table class="' . $class . '"><thead><tr>';
    foreach ($headers as $i => $h) $html .= '<th style="text-align:' . ($align[$i] ?? 'left') . '">' . $h . '</th>';
    $html .= '</tr></thead><tbody>';
    $any = false;
    foreach ($groups as $label => $rows) {
        if (!$rows) continue;
        $any = true;
        if ($label !== '') $html .= '<tr class="rp-group"><td colspan="' . $n . '">' . $label . ' (' . count($rows) . ')</td></tr>';
        foreach ($rows as $row) {
            $cls = !empty($row['_class']) ? ' class="' . $row['_class'] . '"' : '';
            unset($row['_class']);
            $html .= '<tr' . $cls . '>';
            foreach (array_values($row) as $i => $cell) $html .= '<td style="text-align:' . ($align[$i] ?? 'left') . '">' . $cell . '</td>';
            $html .= '</tr>';
        }
    }
    if (!$any) $html .= '<tr><td colspan="' . $n . '" style="text-align:center;color:#98A2B3;padding:12px;font-style:italic">' . $emptyMessage . '</td></tr>';
    return $html . '</tbody></table>';
}

function rpSexKey($gender) {
    $g = strtolower(trim((string)$gender));
    return in_array($g, ['male', 'm'], true) ? 'MALE' : (in_array($g, ['female', 'f'], true) ? 'FEMALE' : 'NOT SET');
}

function rpFullName(array $s) {
    $mid = trim((string)($s['middle_name'] ?? ''));
    return trim($s['last_name'] . ', ' . $s['first_name'] . ($mid !== '' ? ' ' . $mid : ''));
}

/**
 * Approved learners of the active school year for a section (or a grade
 * level, or the whole school), males first then females, by name.
 */
function rpLearners($section_id, $grade_level) {
    $sy = rpActiveSy();
    $sql = "SELECT s.*, e.enrollment_id, e.section_id AS e_section_id, e.grade_level AS e_grade_level, sec.section_name
              FROM enrollments e
              JOIN students s ON s.student_id = e.student_id AND s.is_active = 1
              LEFT JOIN sections sec ON sec.section_id = e.section_id
             WHERE e.school_year_id = ? AND e.enrollment_status = 'Approved'";
    $params = [(int)$sy['sy_id']];
    if ($section_id) { $sql .= " AND e.section_id = ?"; $params[] = (int)$section_id; }
    if ($grade_level) { $sql .= " AND e.grade_level = ?"; $params[] = $grade_level; }
    $sql .= " ORDER BY CASE LOWER(s.gender) WHEN 'male' THEN 0 WHEN 'female' THEN 1 ELSE 2 END, s.last_name, s.first_name";
    return getRecords($sql, $params) ?: [];
}

/** Age in years on a date (DepEd: as of the start of the school year). */
function rpAge($birth, $onDate) {
    if (!$birth) return '';
    try {
        return (string)(new DateTime($birth))->diff(new DateTime($onDate ?: 'now'))->y;
    } catch (\Throwable $e) {
        return '';
    }
}

/** Descriptor of a (rounded) grade -- the centralized rule in grading_helpers.php (Advancing ... Emerging). */
function rpDescriptor($grade) {
    if ($grade === null || $grade === '') return '';
    return getGradeDescriptor((int)round((float)$grade));
}

/**
 * One learner's year: term grades, Final Grade and remark per learning area,
 * General Average, descriptor and the promotion status. Uses the same
 * rules as the grading module (computeSubjectFinalGrade, computeGeneralAverage,
 * getPassFailRemark, getGradeDescriptor). The status and General Average are
 * the OFFICIAL finalized ones from student_year_status (the same rows the
 * Admin screens read); a learner without one is "Incomplete" (not finalized yet).
 * Learning areas = the subjects assigned to the section this school year
 * (or, without a section, the subjects the learner has grades in).
 */
function rpYearResult($student_id, $section_id, $grade_level) {
    $sy = rpActiveSy();
    $sy_id = (int)$sy['sy_id'];
    // Same catch-up the Admin pages run, so a finished section is never missing its official status here.
    gh_ensureStudentYearStatusTable();
    gh_sweepYearStatuses($sy_id);
    $official = $grade_level ? getRecord(
        "SELECT status, general_average, failed_subjects FROM student_year_status
          WHERE student_id = ? AND school_year_id = ? AND grade_level = ? LIMIT 1",
        [(int)$student_id, $sy_id, $grade_level]) : null;
    $subjects = $section_id ? (getRecords(
        "SELECT DISTINCT sub.subject_code, sub.subject_name, sub.term, sub.terms_required, sub.units
           FROM teacher_subjects ts JOIN subjects sub ON sub.subject_code = ts.subject_code
          WHERE ts.section_id = ? AND ts.school_year_id = ? AND sub.is_active = 1
          ORDER BY sub.subject_name", [(int)$section_id, $sy_id]) ?: []) : [];
    if (!$subjects) {
        $subjects = getRecords(
            "SELECT DISTINCT sub.subject_code, sub.subject_name, sub.term, sub.terms_required, sub.units
               FROM grades g JOIN subjects sub ON sub.subject_code = g.subject_code
              WHERE g.student_id = ? AND g.school_year_id = ? ORDER BY sub.subject_name", [(int)$student_id, $sy_id]) ?: [];
    }
    $is_shs = $grade_level && gh_isSeniorHigh($grade_level);
    $rows = [];
    $finals = [];
    $failed = [];
    $incomplete = 0;
    foreach ($subjects as $sub) {
        $valid_terms = !empty($sub['term']) ? [$sub['term']] : gh_requiredTermsFor($sub);
        $grades = getRecords(
            "SELECT term, transmuted_grade, remarks FROM grades
              WHERE student_id = ? AND subject_code = ? AND school_year_id = ?" . ($section_id ? " AND section_id = ?" : "") . "
                AND " . gh_validTermSql(''),
            array_merge([(int)$student_id, $sub['subject_code'], $sy_id], $section_id ? [(int)$section_id] : [])) ?: [];
        $term_grades = [];
        foreach ($grades as $g) {
            if (!gh_isValidGradeRow($g)) continue;
            $term_grades[$g['term']] = (float)$g['transmuted_grade'];
        }
        $fg = computeSubjectFinalGrade($term_grades, $valid_terms, $sy_id, $sub);
        $complete = !empty($fg['is_complete']);
        $final = $complete ? $fg['final_grade'] : null;
        $passed = $complete && getPassFailRemark($final) === 'Passed';
        if (!$complete) $incomplete++;
        elseif (!$passed) $failed[] = $sub['subject_name'];
        if ($complete) $finals[] = ['final_grade' => $final, 'units' => $sub['units'] ?? null];
        $rows[] = ['code' => $sub['subject_code'], 'name' => $sub['subject_name'], 'terms' => $term_grades, 'valid_terms' => $valid_terms,
                   'final' => $final, 'complete' => $complete, 'passed' => $passed];
    }
    $ga = null;
    $ga_note = '';
    if ($rows && $incomplete === 0 && $finals) {
        $r = computeGeneralAverage($finals, $is_shs);
        $ga = $r['general_average'];
        if ($ga === null && !empty($r['message'])) $ga_note = $r['message'];
    }
    // Official finalized result wins, so reports and the Admin screens always agree.
    if ($official && $official['general_average'] !== null) $ga = (float)$official['general_average'];
    if (!$rows) $action = 'No grades';
    elseif ($official && in_array($official['status'], [gh_yearStatusPromotedLabel(), 'Not Promoted'], true)) {
        $action = $official['status'];
        if ($action === 'Not Promoted' && !$failed && !empty($official['failed_subjects'])) $failed[] = $official['failed_subjects'];
    }
    else $action = 'Incomplete';   // not finalized yet
    return ['subjects' => $rows, 'ga' => $ga, 'ga_note' => $ga_note, 'failed' => $failed, 'incomplete' => $incomplete,
            'action' => $action, 'descriptor' => $ga !== null ? rpDescriptor($ga) : '', 'is_shs' => $is_shs];
}

function rpActionBadge($action) {
    $v = [gh_yearStatusPromotedLabel() => 'success', 'Not Promoted' => 'danger', 'Incomplete' => 'gray'][$action] ?? 'gray';
    return pdfBadge($action, $v);
}

/** Teacher access: only their own advisory section / advisory learners. Admin and Registrar: everything. */
function rpCanSection($section_id) {
    if (hasAnyRole(['Administrator', 'Registrar'])) return true;
    return $section_id && (bool)getRecord("SELECT 1 FROM sections WHERE section_id = ? AND adviser_id = ?", [(int)$section_id, (int)($_SESSION['user_id'] ?? 0)]);
}

function rpCanStudent($student_id) {
    if (hasAnyRole(['Administrator', 'Registrar'])) return true;
    $sy = rpActiveSy();
    return (bool)getRecord(
        "SELECT 1 FROM enrollments e JOIN sections sec ON sec.section_id = e.section_id
          WHERE e.student_id = ? AND e.school_year_id = ? AND sec.adviser_id = ?",
        [(int)$student_id, (int)$sy['sy_id'], (int)($_SESSION['user_id'] ?? 0)]);
}

/** The learner's section this school year (approved enrollment). */
function rpStudentEnrollment($student_id) {
    $sy = rpActiveSy();
    return getRecord(
        "SELECT e.section_id, e.grade_level, sec.section_name FROM enrollments e LEFT JOIN sections sec ON sec.section_id = e.section_id
          WHERE e.student_id = ? AND e.school_year_id = ? AND e.enrollment_status = 'Approved' LIMIT 1",
        [(int)$student_id, (int)$sy['sy_id']]);
}

function rpScopeLabel($section, $grade_level) {
    if ($section) return htmlspecialchars($section['grade_level'] . ' - ' . $section['section_name']);
    if ($grade_level) return htmlspecialchars($grade_level) . ' (all sections)';
    return 'All grade levels';
}

// ============================================================
// SF1 — School Register
// ============================================================
function generateSF1($section_id, $grade_level) {
    $sy = rpActiveSy();
    $section = rpSectionInfo($section_id);
    if ($section) $grade_level = $section['grade_level'];
    $learners = rpLearners($section_id, $grade_level);

    $groups = ['MALE' => [], 'FEMALE' => [], 'NOT SET' => []];
    $count = ['MALE' => 0, 'FEMALE' => 0, 'NOT SET' => 0];
    foreach ($learners as $st) {
        $key = rpSexKey($st['gender']);
        $app = getRecord(
            "SELECT religion, father_name, mother_name, guardian_relationship FROM online_applications
              WHERE student_id = ? AND deleted_at IS NULL ORDER BY application_id DESC LIMIT 1", [(int)$st['student_id']]) ?: [];
        $count[$key]++;
        $guardian = trim((string)($st['guardian_name'] ?? ''));
        if ($guardian !== '' && !empty($app['guardian_relationship'])) $guardian .= ' (' . $app['guardian_relationship'] . ')';
        $groups[$key][] = [
            $count[$key],
            htmlspecialchars($st['lrn']),
            htmlspecialchars(rpFullName($st)) . (!$section ? '<br><span style="color:#667085">' . htmlspecialchars($st['e_grade_level'] . ' - ' . ($st['section_name'] ?? 'No section')) . '</span>' : ''),
            $key === 'MALE' ? 'M' : ($key === 'FEMALE' ? 'F' : ''),
            $st['birth_date'] ? date('m/d/Y', strtotime($st['birth_date'])) : '',
            rpAge($st['birth_date'], $sy['start_date'] ?? null),
            htmlspecialchars($app['religion'] ?? ''),
            htmlspecialchars($st['address'] ?? ''),
            htmlspecialchars($app['father_name'] ?? ''),
            htmlspecialchars($app['mother_name'] ?? ''),
            htmlspecialchars($guardian),
            htmlspecialchars($st['guardian_contact'] ?: ($st['contact_number'] ?? '')),
            '',
        ];
    }
    if (!$groups['NOT SET']) unset($groups['NOT SET']);

    $body = rpFormHeader('School Form 1 (SF1) School Register', 'Official list of learners enrolled this school year',
        ['Grade Level' => rpVal($grade_level ?: 'All'), 'Section' => $section ? rpVal($section['section_name']) : rpVal($grade_level ? 'All sections' : 'All'),
         'Adviser' => $section ? rpVal($section['adviser_name']) : rpVal('')]);
    $body .= rpGroupedTable(
        ['No.', 'LRN', "Learner's Name<br>(Last, First, Middle)", 'Sex', 'Birth Date', 'Age*', 'Religion', 'Address',
         "Father's Name", "Mother's Name", 'Guardian (Relationship)', 'Contact No.', 'Remarks'],
        $groups, [0 => 'center', 3 => 'center', 4 => 'center', 5 => 'center'], 'No enrolled learners for this selection.');
    $total = count($learners);
    $body .= pdfSummaryLine("Male: {$count['MALE']} &middot; Female: {$count['FEMALE']}" . ($count['NOT SET'] ? " &middot; Sex not set: {$count['NOT SET']}" : '') . " &middot; Total: {$total}");
    $body .= '<p class="rp-note">* Age as of the first day of the school year' . (!empty($sy['start_date']) ? ' (' . date('F j, Y', strtotime($sy['start_date'])) . ')' : '') . '. Only approved enrollments of SY ' . htmlspecialchars($sy['sy_name']) . ' are listed.</p>';
    $body .= rpSignatures([
        ['Prepared by:', $section['adviser_name'] ?? '', 'Class Adviser'],
        ['Certified correct:', rpSchoolInfo()['school_head'], 'School Head'],
    ]);

    $filename = 'SF1_School_Register_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $section ? $section['grade_level'] . '_' . $section['section_name'] : ($grade_level ?: 'All')) . '.pdf';
    streamReportPdf('SF1 - School Register', 'SY ' . $sy['sy_name'] . ' · ' . strip_tags(rpScopeLabel($section, $grade_level)), $body, $filename, 'A4', 'landscape');
}

// ============================================================
// SF5 — Report on Promotion and Level of Proficiency
// ============================================================
function generateSF5($section_id) {
    $sy = rpActiveSy();
    $section = rpSectionInfo($section_id);
    if (!$section) {
        reportNotFoundPage('SF5 is made per section. Please choose a Section, then generate again.');
        return;
    }
    $learners = rpLearners($section_id, '');

    $actions = [gh_yearStatusPromotedLabel(), 'Not Promoted', 'Incomplete'];
    $descriptors = ['Advancing' => '90-100', 'Benchmarking' => '80-89', 'Connecting' => '75-79', 'Developing' => '65-74', 'Emerging' => '0-64'];
    $sumAct = [];
    $sumDesc = [];
    $groups = ['MALE' => [], 'FEMALE' => [], 'NOT SET' => []];
    $count = ['MALE' => 0, 'FEMALE' => 0, 'NOT SET' => 0];
    $is_shs = gh_isSeniorHigh($section['grade_level']);
    foreach ($learners as $st) {
        $key = rpSexKey($st['gender']);
        $res = rpYearResult($st['student_id'], $section_id, $section['grade_level']);
        $act = $res['action'];
        if ($act === 'No grades') $act = 'Incomplete';
        $sumAct[$act][$key] = ($sumAct[$act][$key] ?? 0) + 1;
        if ($res['descriptor'] !== '') $sumDesc[$res['descriptor']][$key] = ($sumDesc[$res['descriptor']][$key] ?? 0) + 1;
        $count[$key]++;
        $groups[$key][] = [
            $count[$key],
            htmlspecialchars($st['lrn']),
            htmlspecialchars(rpFullName($st)),
            $res['ga'] !== null ? number_format($res['ga'], 2) : ($res['action'] === 'Incomplete' ? '<span style="color:#98A2B3">incomplete</span>' : '&mdash;'),
            htmlspecialchars($res['descriptor']),
            rpActionBadge($res['action']),
            htmlspecialchars(implode(', ', $res['failed'])) . ($res['incomplete'] ? '<span style="color:#98A2B3">' . ($res['failed'] ? '; ' : '') . $res['incomplete'] . ' subject(s) without a final grade yet</span>' : ''),
        ];
    }
    if (!$groups['NOT SET']) unset($groups['NOT SET']);

    $body = rpFormHeader('School Form 5 (SF5) Report on Promotion and Level of Proficiency', 'End-of-year result of every learner of the section',
        ['Grade Level' => rpVal($section['grade_level']), 'Section' => rpVal($section['section_name']), 'Adviser' => rpVal($section['adviser_name'])]);
    $body .= rpGroupedTable(
        ['No.', 'LRN', "Learner's Name", 'General Average', 'Descriptor', 'Action Taken', 'Learning Areas Not Passed (as of end of year)'],
        $groups, [0 => 'center', 3 => 'center', 4 => 'center', 5 => 'center'], 'No enrolled learners in this section.');

    // Summary tables (by sex)
    $sexCols = isset($groups['NOT SET']) ? ['MALE', 'FEMALE', 'NOT SET'] : ['MALE', 'FEMALE'];
    $mk = function ($title, $labels, $data) use ($sexCols) {
        $h = '<table class="rp-t" style="width:100%"><thead><tr><th>' . $title . '</th>';
        foreach ($sexCols as $c) $h .= '<th style="text-align:center">' . $c . '</th>';
        $h .= '<th style="text-align:center">TOTAL</th></tr></thead><tbody>';
        foreach ($labels as $l => $sub) {
            $h .= '<tr><td>' . htmlspecialchars(is_int($l) ? $sub : $l) . (is_int($l) ? '' : ' <span style="color:#667085">(' . $sub . ')</span>') . '</td>';
            $tot = 0;
            foreach ($sexCols as $c) { $v = $data[is_int($l) ? $sub : $l][$c] ?? 0; $tot += $v; $h .= '<td style="text-align:center">' . $v . '</td>'; }
            $h .= '<td style="text-align:center;font-weight:bold">' . $tot . '</td></tr>';
        }
        return $h . '</tbody></table>';
    };
    $body .= '<table style="width:100%;border-collapse:collapse"><tr>'
          . '<td style="width:48%;vertical-align:top;border:none;padding:0 8px 0 0"><div class="rp-h">Summary Table</div>' . $mk('Status', $actions, $sumAct) . '</td>'
          . '<td style="width:52%;vertical-align:top;border:none;padding:0 0 0 8px"><div class="rp-h">Level of Proficiency</div>' . $mk('Descriptor (Grading Scale)', $descriptors, $sumDesc) . '</td>'
          . '</tr></table>';
    $body .= '<p class="rp-note">' . gh_yearStatusPromotedLabel() . ': passed all learning areas. Not Promoted: at least one learning area failed or without a final grade when the year was finalized. '
          . 'Incomplete: the school year result is not finalized yet (all three terms must be finalized and graded). '
          . 'Status and General Average are the official finalized results shown in the Admin portal.</p>';
    $body .= rpSignatures([
        ['Prepared by:', $section['adviser_name'] ?? '', 'Class Adviser'],
        ['Certified correct &amp; submitted:', rpSchoolInfo()['school_head'], 'School Head'],
        ['Reviewed by:', '', 'Division Representative'],
    ]);

    $filename = 'SF5_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $section['grade_level'] . '_' . $section['section_name']) . '.pdf';
    streamReportPdf('SF5 - Promotion Report', 'SY ' . $sy['sy_name'] . ' · ' . $section['grade_level'] . ' - ' . $section['section_name'], $body, $filename, 'A4', 'landscape');
}

// ============================================================
// SF9 — Learner's Progress Report Card
// ============================================================

/** Per month of the school year: school days (days the section had attendance), present and absent days. */
function rpMonthlyAttendance($student_id, $section_id) {
    $sy = rpActiveSy();
    $start = !empty($sy['start_date']) ? $sy['start_date'] : date('Y') . '-06-01';
    $end = !empty($sy['end_date']) ? $sy['end_date'] : date('Y-m-d', strtotime($start . ' +10 months'));
    $months = [];
    $d = new DateTime(date('Y-m-01', strtotime($start)));
    $last = new DateTime(date('Y-m-01', strtotime($end)));
    while ($d <= $last && count($months) < 13) { $months[$d->format('Y-m')] = ['label' => $d->format('M'), 'days' => 0, 'present' => 0, 'absent' => 0]; $d->modify('+1 month'); }
    // School days = distinct dates with attendance for the section (or the learner when there is no section).
    $dates = $section_id
        ? getRecords("SELECT DISTINCT date FROM attendance WHERE section_id = ? AND date BETWEEN ? AND ?", [(int)$section_id, $start, $end])
        : getRecords("SELECT DISTINCT date FROM attendance WHERE student_id = ? AND date BETWEEN ? AND ?", [(int)$student_id, $start, $end]);
    foreach ($dates ?: [] as $r) { $k = substr($r['date'], 0, 7); if (isset($months[$k])) $months[$k]['days']++; }
    // A day counts as present when the learner was Present or Late in any class that day; absent when only Absent.
    foreach (getRecords(
        "SELECT date, SUM(status IN ('Present','Late')) AS p, SUM(status = 'Absent') AS a FROM attendance
          WHERE student_id = ? AND date BETWEEN ? AND ? GROUP BY date", [(int)$student_id, $start, $end]) ?: [] as $r) {
        $k = substr($r['date'], 0, 7);
        if (!isset($months[$k])) continue;
        if ((int)$r['p'] > 0) $months[$k]['present']++;
        elseif ((int)$r['a'] > 0) $months[$k]['absent']++;
    }
    return $months;
}

/**
 * The signature block: Class Adviser, School Head, Parent / Guardian.
 * Kept as its own helper so page 1 has room to push it to the bottom.
 */
function rpSf9Signatures($adviserName) {
    $head = rpSchoolInfo()['school_head'];
    $cell = function ($name, $title) {
        return '<div class="rule">&nbsp;</div>'
             . '<div class="nm">' . ($name !== '' ? htmlspecialchars(mb_strtoupper($name)) : '<span class="bl">&nbsp;</span>') . '</div>'
             . '<div class="tt">' . $title . '</div>';
    };
    return '<table class="rp-sign2"><tr>'
         . '<td>' . $cell($adviserName, 'Class Adviser') . '</td>'
         . '<td>' . $cell($head, 'School Head') . '</td>'
         . '<td><div class="rule">&nbsp;</div><div class="nm">&nbsp;</div><div class="tt">Parent / Guardian\'s Signature</div></td>'
         . '</tr></table>';
}

function generateSF9($student_id) {
    // Accepts either the internal Student ID or the LRN — the form
    // field and every place in the UI that shows a student actually
    // displays the LRN, so look up both instead of only student_id.
    $student = findStudentByIdentifier($student_id);
    if (!$student) {
        reportNotFoundPage('No active student found for that LRN/Student ID.');
        return;
    }
    $sy = rpActiveSy();
    list($body, $filename) = rpSf9CardBody($student, $sy);
    streamReportPdf('SF9 - Report Card', 'SY ' . $sy['sy_name'], $body, $filename, 'A4', 'portrait', false);
}

/**
 * MAPEH-style component rows for one learning area, read from the saved
 * grade_components records (same table Encode Grades writes). Component
 * finals use the existing computeSubjectFinalGrade() — nothing is computed
 * independently here. Returns [] when the subject has no saved components.
 */
function rpSf9SubjectComponents($student_id, $subject_code, $sy_id, $valid_terms) {
    $rows = getRecords(
        "SELECT component, term, transmuted_grade, remarks FROM grade_components
          WHERE student_id = ? AND subject_code = ? AND school_year_id = ?",
        [(int)$student_id, $subject_code, (int)$sy_id]) ?: [];
    $by_comp = [];
    foreach ($rows as $r) {
        if (!gh_isValidGradeRow($r)) continue;
        $by_comp[$r['component']][$r['term']] = (float)$r['transmuted_grade'];
    }
    if (!$by_comp) return [];
    $out = [];
    foreach ($by_comp as $comp => $cterms) {
        $fg = computeSubjectFinalGrade($cterms, $valid_terms, $sy_id);
        $out[] = ['name' => $comp, 'terms' => $cterms,
                  'final' => !empty($fg['is_complete']) ? $fg['final_grade'] : null,
                  'complete' => !empty($fg['is_complete']),
                  'passed' => !empty($fg['is_complete']) && getPassFailRemark($fg['final_grade']) === 'Passed'];
    }
    return $out;
}

/**
 * Builds the single-page SF9 reference card markup. Split out of
 * generateSF9() purely so it can be rendered/inspected on its own; it
 * changes no computation — every grade, average, descriptor and attendance
 * figure still comes from the same helpers as before (rpYearResult(),
 * computeSubjectFinalGrade(), computeGeneralAverage(), rpMonthlyAttendance()).
 *
 * @return array{0:string,1:string} [html, filename]
 */
function rpSf9CardBody(array $student, array $sy) {
    $enr = rpStudentEnrollment($student['student_id']);
    $section_id = $enr['section_id'] ?? null;
    $section = rpSectionInfo($section_id);
    $grade_level = $enr['grade_level'] ?? $student['grade_level'];
    $terms = getActiveTerms(); // Term 1, Term 2, Term 3 only
    $res = rpYearResult($student['student_id'], $section_id, $grade_level);
    $s = rpSchoolInfo();
    $strand = trim((string)($student['strand'] ?? ''));
    $sy_id = (int)$sy['sy_id'];
    $is_shs = gh_isSeniorHigh($grade_level);

    $css = rpStylesSf9();
    $h = $css . '<div class="sf9-frame">';

    // ---- 1. DepEd + school header (existing logo asset for the seal)
    $seal = pdfLogoDataUri();
    $h .= '<table class="sf9-hd"><tr>'
        . '<td style="width:17%"><div class="sf9-deped-mark">Dep<span>Ed</span></div><div class="sf9-deped-sub">DEPARTMENT OF EDUCATION</div></td>'
        . '<td style="width:66%" class="sf9-hd-c">'
        . '<div class="l1">Republic of the Philippines</div>'
        . '<div class="l2">Department of Education</div>'
        . '<div class="l3">NATIONAL CAPITAL REGION<br>SCHOOLS DIVISION OFFICE OF MALABON CITY</div>'
        . '<div class="school">' . htmlspecialchars(mb_strtoupper($s['school_name'])) . '</div>'
        . '<div class="city">Malabon City, National Capital Region</div>'
        . '</td>'
        . '<td style="width:17%;text-align:right">' . ($seal ? '<img src="' . $seal . '" class="sf9-seal" alt="">' : '') . '</td>'
        . '</tr></table><hr class="sf9-rule">';

    // ---- 2. Blue title banner (dynamic school year)
    $h .= '<div class="sf9-banner"><div class="t">LEARNER\'S PERFORMANCE REPORT</div>'
        . '<div class="s">School Year: ' . htmlspecialchars(str_replace('-', ' – ', $sy['sy_name'])) . '</div></div>';

    // ---- 3. Learner information (from existing student records)
    $h .= '<table class="sf9-box"><tr>'
        . '<td class="sf9-info" style="width:60%"><span class="k">Learner\'s Name</span>: '
        . '<span class="ln">' . htmlspecialchars(rpFullName($student)) . '</span></td>'
        . '<td class="sf9-info" style="width:40%"><span class="k">Grade Level</span>: '
        . '<span class="ln-sm">' . htmlspecialchars($grade_level) . '</span></td></tr>'
        . '<tr><td class="sf9-info"><span class="k">LRN</span>: '
        . '<span class="ln">' . htmlspecialchars($student['lrn']) . '</span></td>'
        . '<td class="sf9-info"><span class="k">Section</span>: '
        . '<span class="ln-sm">' . htmlspecialchars($section['section_name'] ?? '') . '</span></td></tr>'
        . '<tr><td class="sf9-info"><span class="k">Age</span>: '
        . '<span class="ln">' . htmlspecialchars(rpAge($student['birth_date'] ?? null, $sy['start_date'] ?? null)) . '</span></td>'
        . '<td class="sf9-info" rowspan="2" style="vertical-align:top"><span class="k">Track/Strand (SHS only)</span>: '
        . '<span class="ln-sm">' . ($is_shs ? htmlspecialchars($strand) : '') . '</span></td></tr>'
        . '<tr><td class="sf9-info"><span class="k">Sex</span>: '
        . '<span class="ln">' . htmlspecialchars($student['gender'] ?? '') . '</span></td></tr>'
        . '</table>';

    // ---- 4. Learning Progress and Achievement (saved records only)
    $h .= '<div class="sf9-sec">LEARNING PROGRESS AND ACHIEVEMENT</div>';
    $h .= '<table class="sf9-gr"><thead><tr><th style="width:34%">Learning Areas</th>';
    foreach ($terms as $t) $h .= '<th style="text-align:center">' . htmlspecialchars($t) . '</th>';
    $h .= '<th style="text-align:center">Final Grade</th><th style="text-align:center">Remarks</th></tr></thead><tbody>';
    foreach ($res['subjects'] as $sub) {
        $h .= '<tr><td><b>' . htmlspecialchars($sub['name']) . '</b></td>';
        foreach ($terms as $t) {
            $g = $sub['terms'][$t] ?? null;
            $h .= '<td class="c">' . ($g !== null ? (int)round($g) : (in_array($t, $sub['valid_terms'], true) ? '' : '<span style="color:#98A2B3">n/a</span>')) . '</td>';
        }
        $h .= '<td class="c"><b>' . ($sub['final'] !== null ? (int)round($sub['final']) : '') . '</b></td>'
            . '<td class="c">' . ($sub['complete'] ? ($sub['passed'] ? 'Passed' : '<span class="sf9-fail">Failed</span>') : '') . '</td></tr>';
        // MAPEH-style saved components as indented sub-rows (when on file).
        foreach (rpSf9SubjectComponents($student['student_id'], $sub['code'] ?? '', $sy_id, $sub['valid_terms']) as $comp) {
            $h .= '<tr><td class="sub">' . htmlspecialchars($comp['name']) . '</td>';
            foreach ($terms as $t) {
                $g = $comp['terms'][$t] ?? null;
                $h .= '<td class="c">' . ($g !== null ? (int)round($g) : '') . '</td>';
            }
            $h .= '<td class="c"><b>' . ($comp['final'] !== null ? (int)round($comp['final']) : '') . '</b></td>'
                . '<td class="c">' . ($comp['complete'] ? ($comp['passed'] ? 'Passed' : '<span class="sf9-fail">Failed</span>') : '') . '</td></tr>';
        }
    }
    if (!$res['subjects']) {
        $h .= '<tr><td colspan="' . (count($terms) + 3) . '" style="text-align:center;color:#98A2B3;padding:10px;font-style:italic">No learning areas or grades yet for this school year.</td></tr>';
    }
    $h .= '<tr class="sf9-ga"><td style="text-align:right"><b>General Average</b></td><td class="c"></td><td class="c"></td><td class="c"></td>'
        . '<td class="c"><b>' . ($res['ga'] !== null ? number_format($res['ga'], 2) : '') . '</b></td>'
        . '<td class="c">' . ($res['ga'] !== null ? (getPassFailRemark($res['ga']) === 'Passed' ? 'Passed' : '<span class="sf9-fail">Failed</span>') : '') . '</td></tr>';
    $h .= '</tbody></table>';

    // ---- 5. Performance Descriptors + Attendance Record, side by side.
    // Descriptor labels come from the centralized school policy rule
    // (getGradeDescriptor / DO 015, s. 2026); pass/fail per band from
    // getPassFailRemark. Attendance from saved records (rpMonthlyAttendance).
    $bands = [[90, '90 – 100'], [80, '80 – 89'], [75, '75 – 79'], [65, '65 – 74'], [0, '0 – 64']];
    $desc = '<div class="sf9-sec">PERFORMANCE DESCRIPTORS</div>'
          . '<table class="sf9-gr"><thead><tr><th>Percentage Range</th><th>Descriptor (Remarks)</th></tr></thead><tbody>';
    foreach ($bands as [$floor, $range]) {
        $desc .= '<tr><td class="c">' . $range . '</td><td class="c">' . htmlspecialchars(getGradeDescriptor($floor) . ' (' . getPassFailRemark($floor) . ')') . '</td></tr>';
    }
    $desc .= '</tbody></table>';
    $amonths = rpMonthlyAttendance($student['student_id'], $section_id);
    $by_num = [];
    foreach ($amonths as $k => $m) { $by_num[(int)substr($k, 5, 2)] = $m; }
    $att_cols = [[6, 'Jun'], [7, 'Jul'], [8, 'Aug'], [9, 'Sep'], [10, 'Oct'], [11, 'Nov'], [12, 'Dec'], [1, 'Jan'], [2, 'Feb'], [3, 'Mar'], [4, 'Apr']];
    $att = '<div class="sf9-sec">ATTENDANCE RECORD</div>'
         . '<table class="sf9-gr"><thead><tr><th>Month</th>';
    foreach ($att_cols as [, $ml]) $att .= '<th style="text-align:center">' . $ml . '</th>';
    $att .= '<th style="text-align:center">Total</th></tr></thead><tbody>';
    foreach (['days' => 'No. of Class Days', 'present' => 'No. of Days Present', 'absent' => 'No. of Days Absent'] as $k => $label) {
        $tot = 0;
        $att .= '<tr><td>' . $label . '</td>';
        foreach ($att_cols as [$mn]) {
            $v = isset($by_num[$mn]) ? (int)$by_num[$mn][$k] : 0;
            $tot += $v;
            $att .= '<td class="c">' . ($v ?: '') . '</td>';
        }
        $att .= '<td class="c"><b>' . ($tot ?: '') . '</b></td></tr>';
    }
    $att .= '</tbody></table>';
    $h .= '<table class="sf9-outer"><tbody><tr>'
        . '<td style="width:38%;padding-right:6px">' . $desc . '</td>'
        . '<td style="width:62%;padding-left:6px">' . $att . '</td>'
        . '</tr></tbody></table>';

    // ---- 6. Teacher's Comments + Parent/Guardian's Signature (printable blanks)
    $blank3 = '<div class="sf9-lines">Term 1&nbsp;&nbsp;: <span class="sf9-blank" style="width:78%">&nbsp;</span><br>'
            . 'Term 2&nbsp;&nbsp;: <span class="sf9-blank" style="width:78%">&nbsp;</span><br>'
            . 'Term 3&nbsp;&nbsp;: <span class="sf9-blank" style="width:78%">&nbsp;</span></div>';
    $h .= '<table class="sf9-outer"><tbody><tr>'
        . '<td style="width:50%;padding-right:6px"><div class="sf9-sec">TEACHER\'S COMMENTS / REMARKS</div>'
        . '<table class="sf9-box"><tr><td style="padding:6px 8px">' . $blank3 . '</td></tr></table></td>'
        . '<td style="width:50%;padding-left:6px"><div class="sf9-sec">PARENTS / GUARDIAN\'S SIGNATURE</div>'
        . '<table class="sf9-box"><tr><td style="padding:6px 8px">' . $blank3 . '</td></tr></table></td>'
        . '</tr></tbody></table>';

    // ---- 7. Certificate of Transfer + Cancellation (printable blanks)
    $h .= '<table class="sf9-outer"><tbody><tr>'
        . '<td style="width:50%;padding-right:6px"><div class="sf9-sec">CERTIFICATE OF TRANSFER</div>'
        . '<table class="sf9-box"><tr><td style="padding:6px 8px">'
        . '<div class="sf9-cert">Pinatutunayan na nakatupad ang mag-aaral na ito sa mga kinakailangang pamantayan para sa kasalukuyang baitang.</div>'
        . '<div class="sf9-lines">Admitted to Grade&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <span class="sf9-blank" style="width:46%">&nbsp;</span><br>'
        . 'Eligible for Admission to Grade&nbsp;&nbsp;: <span class="sf9-blank" style="width:46%">&nbsp;</span></div>'
        . '<table style="width:100%;border-collapse:collapse"><tr>'
        . '<td class="sf9-sig" style="width:50%"><div class="rule">&nbsp;</div>School Head</td>'
        . '<td class="sf9-sig" style="width:50%"><div class="rule">&nbsp;</div>Adviser</td>'
        . '</tr></table>'
        . '</td></tr></table></td>'
        . '<td style="width:50%;padding-left:6px"><div class="sf9-sec">CANCELLATION OF ELIGIBILITY TO TRANSFER</div>'
        . '<table class="sf9-box"><tr><td style="padding:6px 8px">'
        . '<div class="sf9-lines">Admitted in&nbsp;&nbsp;: <span class="sf9-blank" style="width:72%">&nbsp;</span><br>'
        . 'Date&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <span class="sf9-blank" style="width:72%">&nbsp;</span></div>'
        . '<div class="sf9-sig"><div class="rule">&nbsp;</div>School Head</div>'
        . '</td></tr></table></td>'
        . '</tr></tbody></table>';

    $h .= '</div>'; // .sf9-frame

    $filename = 'SF9_Report_Card_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $student['lrn']) . '.pdf';
    return [$h, $filename];
}

// ============================================================
// Grade Summary — per-term monitoring of a section
// ============================================================
function generateGradeSummary($section_id, $grade_level = '') {
    $sy = rpActiveSy();
    $section = rpSectionInfo($section_id);
    if ($section) $grade_level = $section['grade_level'];
    $learners = rpLearners($section_id, $grade_level);
    $terms = getActiveTerms();
    $pass = getGradingPassMark();

    $groups = ['MALE' => [], 'FEMALE' => [], 'NOT SET' => []];
    $count = ['MALE' => 0, 'FEMALE' => 0, 'NOT SET' => 0];
    $on_track = 0;
    $with_grades = 0;
    foreach ($learners as $st) {
        $key = rpSexKey($st['gender']);
        $rows = getRecords(
            "SELECT subject_code, term, transmuted_grade, remarks FROM grades
              WHERE student_id = ? AND school_year_id = ? AND " . gh_validTermSql(''), [(int)$st['student_id'], (int)$sy['sy_id']]) ?: [];
        $byTerm = [];
        $bySubject = [];
        foreach ($rows as $g) {
            if (!gh_isValidGradeRow($g)) continue;
            $byTerm[$g['term']][] = (float)$g['transmuted_grade'];
            $bySubject[$g['subject_code']][] = (float)$g['transmuted_grade'];
        }
        $termAvg = [];
        foreach ($terms as $t) $termAvg[$t] = !empty($byTerm[$t]) ? round(array_sum($byTerm[$t]) / count($byTerm[$t]), 2) : null;
        $filled = array_filter($termAvg, function ($v) { return $v !== null; });
        $current = $filled ? round(array_sum($filled) / count($filled), 2) : null;
        $below = 0;
        foreach ($bySubject as $gs) if (array_sum($gs) / count($gs) < $pass) $below++;
        $ok = $current !== null && $current >= $pass && $below === 0;
        if ($current !== null) { $with_grades++; if ($ok) $on_track++; }
        $count[$key]++;
        $row = [$count[$key], htmlspecialchars($st['lrn']),
                htmlspecialchars(rpFullName($st)) . (!$section ? '<br><span style="color:#667085">' . htmlspecialchars($st['e_grade_level'] . ' - ' . ($st['section_name'] ?? 'No section')) . '</span>' : '')];
        foreach ($terms as $t) $row[] = $termAvg[$t] !== null ? number_format($termAvg[$t], 2) : '&mdash;';
        $row[] = $current !== null ? '<b>' . number_format($current, 2) . '</b>' : '&mdash;';
        $row[] = $below ?: '';
        $row[] = $current === null ? '<span style="color:#98A2B3">No grades yet</span>' : pdfBadge($ok ? 'On track' : 'Needs attention', $ok ? 'success' : 'danger');
        $groups[$key][] = $row;
    }
    if (!$groups['NOT SET']) unset($groups['NOT SET']);

    $body = rpStyles();
    $body .= pdfInfoBox([
        'Scope' => rpScopeLabel($section, $grade_level),
        'Adviser' => $section ? rpVal($section['adviser_name']) : 'n/a',
        'Purpose' => 'Per-term monitoring: each learner\'s average per term and the subjects currently below ' . (int)$pass . '.',
    ]);
    $headers = array_merge(['No.', 'LRN', "Learner's Name"], array_map(function ($t) { return htmlspecialchars($t) . ' Avg'; }, $terms), ['Current Avg', 'Subjects Below ' . (int)$pass, 'Status']);
    $align = [0 => 'center'];
    for ($i = 3; $i < count($headers); $i++) $align[$i] = 'center';
    $body .= rpGroupedTable($headers, $groups, $align, 'No enrolled learners for this selection.', 'rp-t rp-big');
    $body .= pdfSummaryLine('Learners: ' . count($learners) . ' &middot; With grades: ' . $with_grades . ' &middot; On track: ' . $on_track
        . ' &middot; Needs attention: ' . ($with_grades - $on_track));
    $body .= '<p class="rp-note">Term averages are the mean of the learner\'s term grades. Official Final Grades and the General Average are in SF9 and SF5 (after all terms are graded).</p>';

    $filename = 'Grade_Summary_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $section ? $section['grade_level'] . '_' . $section['section_name'] : ($grade_level ?: 'All')) . '.pdf';
    streamReportPdf('Grade Summary', 'SY ' . $sy['sy_name'] . ' · ' . strip_tags(rpScopeLabel($section, $grade_level)), $body, $filename, 'A4', 'landscape');
}

// ============================================================
// At-Risk / Failing Student — single-student progress & intervention
// report with recommended actions.
// ============================================================
function generateAtRiskStudentReport($student_id) {
    // Accepts either the internal Student ID or the LRN (see
    // findStudentByIdentifier for why — same fix as SF9/Report Card).
    $student = findStudentByIdentifier($student_id);
    if (!$student) {
        reportNotFoundPage('No active student found for that ID/LRN.');
        return;
    }
    // Always use the real numeric primary key from here on, never the
    // raw form input (which may have been the LRN).
    $real_student_id = $student['student_id'];

    $active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
    $school_year_id = $active_sy['sy_id'] ?? 0;

    $pass_mark_row = getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'grading_pass_mark'");
    $pass_mark = floatval($pass_mark_row['setting_value'] ?? 75);
    $at_risk_low = $pass_mark - 5;

    $enrollment = getRecord("
        SELECT sec.section_name, sec.grade_level
        FROM enrollments e
        LEFT JOIN sections sec ON sec.section_id = e.section_id
        WHERE e.student_id = ? AND e.school_year_id = ? AND e.enrollment_status = 'Approved'
        LIMIT 1
    ", [$real_student_id, $school_year_id]);

    // Per-subject average (across whatever terms have been recorded so far
    // THIS school year -- older years are not mixed in)
    $subject_grades = getRecords("
        SELECT sub.subject_name, AVG(g.final_grade) AS subject_average
        FROM grades g
        JOIN subjects sub ON g.subject_code = sub.subject_code
        WHERE g.student_id = ? AND g.school_year_id = ?
          AND " . gh_validTermSql('g') . "
        GROUP BY sub.subject_code, sub.subject_name
        ORDER BY sub.subject_name
    ", [$real_student_id, $school_year_id]);

    $overall_average = 0;
    $weak_subjects = [];
    if ($subject_grades) {
        $sum = 0;
        foreach ($subject_grades as &$sg) {
            $sg['subject_average'] = round(floatval($sg['subject_average']), 2);
            $sum += $sg['subject_average'];
            if ($sg['subject_average'] < $pass_mark) {
                $weak_subjects[] = $sg['subject_name'];
            }
        }
        unset($sg);
        $overall_average = round($sum / count($subject_grades), 2);
    }

    if (!$subject_grades) {
        $status = 'No grades yet';
        $statusVariant = 'gray';
    } elseif ($overall_average < $at_risk_low) {
        $status = 'Failing';
        $statusVariant = 'danger';
    } elseif ($overall_average < $pass_mark || $weak_subjects) {
        $status = 'At Risk';
        $statusVariant = 'warning';
    } else {
        $status = 'Passing';
        $statusVariant = 'success';
    }

    // Recommended actions — a mix of general support steps and one
    // per weak subject, so the plan is specific to what's actually failing.
    $recommendations = [
        'Schedule a conference with the class adviser to review progress and set weekly check-ins.',
        'Refer to the guidance office for study-habits and time-management support.',
        'Coordinate with parent/guardian so progress can be monitored at home as well.',
    ];
    foreach ($weak_subjects as $subj) {
        $recommendations[] = 'Attend remedial or tutorial sessions for ' . htmlspecialchars($subj) . ' and complete any missing requirements.';
    }
    if (empty($weak_subjects) && $status !== 'Passing') {
        $recommendations[] = 'Review overall study load — the average is close to the passing mark across subjects, so a general study-skills check-in is recommended.';
    }

    $rows = [];
    if ($subject_grades) {
        foreach ($subject_grades as $sg) {
            $needsWork = $sg['subject_average'] < $pass_mark;
            $rows[] = [
                htmlspecialchars($sg['subject_name']),
                number_format($sg['subject_average'], 2),
                pdfBadge($needsWork ? 'Needs Improvement' : 'Passing', $needsWork ? 'danger' : 'success'),
            ];
        }
    }

    $body = pdfInfoBox([
        'Student Name' => htmlspecialchars($student['last_name'] . ', ' . $student['first_name']),
        'LRN' => htmlspecialchars($student['lrn']),
        'Grade Level' => htmlspecialchars($enrollment['grade_level'] ?? $student['grade_level'] ?? 'N/A'),
        'Section' => htmlspecialchars($enrollment['section_name'] ?? 'Unassigned'),
        'Overall Average' => ($subject_grades ? number_format($overall_average, 2) : '&mdash;') . '&nbsp;&nbsp;' . pdfBadge($status, $statusVariant),
    ]);

    $body .= pdfSectionTitle('Grades by Subject (SY ' . htmlspecialchars($active_sy['sy_name'] ?? '') . ')');
    $body .= pdfTable(
        ['Subject', 'Average', 'Standing'],
        $rows,
        [1 => 'center', 2 => 'center'],
        'No grades recorded yet this school year.'
    );

    $body .= pdfSectionTitle('Recommended Actions');
    if ($status === 'Passing') {
        $body .= '<p>This student is currently passing all subjects. No intervention needed at this time.</p>';
    } elseif ($status === 'No grades yet') {
        $body .= '<p>No grades have been recorded for this student this school year yet.</p>';
    } else {
        $body .= pdfListItems($recommendations);
    }

    $filename = 'Progress_Report_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $student['lrn']) . '.pdf';
    streamReportPdf('Student Progress & Intervention Report', 'SY ' . currentSchoolYearLabel(), $body, $filename);
}
