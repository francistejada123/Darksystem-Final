<?php
// modules/grading/index.php
// ============================================================
// >>> THIS FILE GOES IN:  modules/grading/index.php  <<<
// ============================================================
// The BIG step-by-step gradebook (~1,384 lines).
// Look for "STEP 5: ENCODE GRADES" further down — it must be there.
// ============================================================
// Grade Management - Grade Level → Sections → My Subjects → Students → Encode Grades

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';
require_once __DIR__ . '/../../includes/attendance_qr_helpers.php'; // attQrLrnLink() -- approved students' LRN opens their QR code
require_once __DIR__ . '/../../includes/risk_helpers.php'; // syncStudentRiskAnalysis() -- refreshes At-Risk right after Finalize

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Teacher'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Grade Management';
$current_page = 'grading';
$base_url = BASE_URL;

// Get active school year
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");

if (!$active_sy) {
    include '../../includes/header.php';
    echo '<div class="alert alert-warning mt-4">⚠️ No active school year found.</div>';
    include '../../includes/footer.php';
    exit;
}

// ============================================================
// ADMINISTRATOR: view-only Grade Management (Grade Level → Section →
// Students → View Student). Admins are not assigned to teacher_subjects,
// so they get their own drill-down instead of the teacher encoding flow below.
// ============================================================
if (hasRole('Administrator')) {
    require_once __DIR__ . '/admin_view.php';
    exit;
}

$teacher_id = $_SESSION['user_id'];
$step = isset($_GET['step']) ? intval($_GET['step']) : 1;
$selected_grade = isset($_GET['grade']) ? sanitize($_GET['grade']) : '';

// ---- Encode mode ------------------------------------------------------
// 'single' encodes ONE learner; 'all' keeps the original behaviour of
// encoding every learner in the section on one page. Anything else (and
// no mode at all) falls back to 'all', so every existing link and
// bookmark keeps working exactly as before.
$encode_mode       = (isset($_GET['mode']) && $_GET['mode'] === 'single') ? 'single' : 'all';
$selected_student  = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$single_student    = null;   // the validated learner row, or null
$single_error      = '';     // why a requested learner was rejected
$selected_section = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;
$selected_subject = isset($_GET['subject_code']) ? sanitize($_GET['subject_code']) : '';

// ---- Automatic term detection -----------------------------------------
// There is no manual Term picker any more. The active term is whichever
// one's Start/End Date window (set from "Manage Assessment Items" or
// Teacher Management's "Term Dates" button -- both write the same
// term_windows table) covers today, per gh_getCurrentTerm(). Any old
// ?term=... link is ignored on purpose: term now always follows today's
// date, the same way Assign Subject to Teacher does.
//
// When no term's window covers today (dates not set yet, or today falls
// in a gap between two terms), $term still needs a valid value for the
// rest of this page's queries -- getDefaultTerm() is used for that. Dates
// provide status context only and do not block score saving or finalization.
$current_term_live = gh_getCurrentTerm($active_sy['sy_id']);
$no_active_term = ($current_term_live === null);
$term = $current_term_live ?? getDefaultTerm();
// SERVER-SIDE grading-period validation. Only Term 1, Term 2 and Term 3 are
// accepted. The browser is never trusted, and the `chk_grades_term` CHECK
// constraint rejects anything else again at the database layer.
if (!isValidTerm($term)) {
    $term = getDefaultTerm();
}
$is_teacher = hasRole('Teacher');
$debug_subjects = [];

// ============================================================
// GET ALL SECTIONS ASSIGNED TO THIS TEACHER GROUPED BY GRADE
// ============================================================
$my_sections = [];
$sections_by_grade = [];

if ($is_teacher) {
    $my_sections = getRecords("
        SELECT 
            s.section_id,
            s.section_name,
            s.grade_level,
            s.strand,
            s.capacity,
            COUNT(DISTINCT ts.subject_code) as subject_count,
            (
                SELECT COUNT(*) FROM enrollments e 
                WHERE e.section_id = s.section_id 
                AND e.school_year_id = ? 
                AND e.enrollment_status = 'Approved'
            ) as student_count
        FROM sections s
        INNER JOIN teacher_subjects ts ON s.section_id = ts.section_id
        WHERE ts.teacher_id = ? 
        AND ts.school_year_id = ?
        AND s.is_active = 1
        GROUP BY s.section_id, s.section_name, s.grade_level, s.strand, s.capacity
        ORDER BY s.grade_level, s.section_name
    ", [$active_sy['sy_id'], $teacher_id, $active_sy['sy_id']]);
    
    foreach ($my_sections as $sec) {
        $sections_by_grade[$sec['grade_level']][] = $sec;
    }
}

// ============================================================
// SECTION TERM TRANSITION (Term 1 -> Term 2 -> Term 3)
// ============================================================
// Which term a class is on is decided by COMPLETION, not by the calendar:
// once every subject of the section is finalized for a term and every
// learner's grade in each one is complete, gh_syncSectionTerms() marks the
// term Completed and the next term is enabled for that class. A Completed
// term stays visible but read-only ($term_readonly) -- its grades are
// history and are never deleted or overwritten. The Start/End Dates from
// Term Dates remain informational only. No grade computation is touched.
$section_term_state = null;      // gh_syncSectionTerms() result for the selected section
$term_readonly = false;          // the term being viewed is Completed -> locked
$term_transition_notice = '';
$section_term_badges = [];       // section_id => state, for the Step 2 cards
$my_section_ids = array_map('intval', array_column($my_sections, 'section_id'));

if ($is_teacher && $selected_section > 0 && in_array($selected_section, $my_section_ids, true)) {
    $section_term_state = gh_syncSectionTerms($selected_section, $active_sy['sy_id'], $teacher_id);
    $term = $section_term_state['current'];
    // A Completed term can still be opened (?term=Term 1) -- read-only history.
    // Any other ?term= value is ignored: the class is on its current term.
    $req_term = isset($_GET['term']) ? (string)$_GET['term'] : '';
    if ($req_term !== '' && $req_term !== $term && in_array($req_term, $section_term_state['completed'], true)) {
        $term = $req_term;
    }
    $term_readonly = in_array($term, $section_term_state['completed'], true);
    // "No term is active today" is a calendar notion; the class now follows completion.
    $no_active_term = false;
    if (!empty($section_term_state['newly'])) {
        $term_transition_notice = implode(' and ', $section_term_state['newly']) . ' is now Completed for this section'
            . ($section_term_state['all_completed']
                ? ' -- all three terms are done.'
                : ' and ' . $section_term_state['current'] . ' is enabled.');
        if (!empty($section_term_state['year']['created'])) {
            $term_transition_notice .= ' ' . (int)$section_term_state['year']['promoted'] . ' learner(s) marked ' . gh_yearStatusPromotedLabel() . '.';
        }
    }
} elseif ($is_teacher && $step === 2 && $selected_grade !== '') {
    foreach (($sections_by_grade[$selected_grade] ?? []) as $sec_row) {
        $section_term_badges[(int)$sec_row['section_id']] = gh_syncSectionTerms((int)$sec_row['section_id'], $active_sy['sy_id'], $teacher_id);
    }
}

// ============================================================
// GET SUBJECTS FOR SELECTED SECTION (MY SUBJECTS)
// ============================================================
$section_subjects = [];
$section_info = null;

if ($selected_section > 0) {
    $section_info = getRecord("SELECT * FROM sections WHERE section_id = ?", [$selected_section]);
    
    if ($section_info) {
        // ✅ Get subjects assigned to this teacher for this section, for the
        // TERM CURRENTLY BEING VIEWED ($term -- auto-detected above, same
        // source Assign Subject to Teacher uses). A Junior High subject has
        // no term value in subjects.term and is always included; a Senior
        // High subject is included only when it belongs to $term, so an
        // assignment that exists for a different term never leaks into this
        // one. This is also the ownership list every subject request below
        // is checked against -- see $subject_authorized.
        $section_subjects = getRecords("
            SELECT
                ts.subject_code,
                ts.teacher_id,
                sub.subject_name,
                sub.subject_type,
                sub.subject_category,
                sub.components,
                sub.grade_level,
                sub.strand,
                sub.term as subject_term
            FROM teacher_subjects ts
            INNER JOIN subjects sub ON ts.subject_code = sub.subject_code
            WHERE ts.teacher_id = ?
            AND ts.section_id = ?
            AND sub.is_active = 1
            AND (sub.term IS NULL OR sub.term = ?)
            ORDER BY sub.subject_name
        ", [$teacher_id, $selected_section, $term]);

        // ✅ DEBUG: If no subjects found, check if there are records
        if (empty($section_subjects)) {
            $debug_subjects = getRecords("
                SELECT * FROM teacher_subjects
                WHERE teacher_id = ? AND section_id = ?
            ", [$teacher_id, $selected_section]);
        }
    }
}

// ============================================================
// SUBJECT OWNERSHIP CHECK -- server-side, not just a hidden button
// ============================================================
// $selected_subject came straight from the URL (?subject_code=...). It is
// only ever allowed to proceed if it is one of THIS teacher's own subjects
// for THIS section AND the term currently being viewed, per the list just
// built above. Anything else -- a subject that belongs to another teacher,
// or one of this teacher's own subjects but for a different term -- is
// rejected here, before any assessment items, scores or grades are read or
// written for it. This is deliberately independent of whatever the UI does
// or does not show, since the UI can always be bypassed by editing the URL.
$subject_authorized = true;
$subject_error = '';
if (!empty($selected_subject)) {
    $subject_authorized = false;
    foreach ($section_subjects as $ss) {
        if ($ss['subject_code'] === $selected_subject) {
            $subject_authorized = true;
            break;
        }
    }
    if (!$subject_authorized) {
        $subject_error = 'You are not assigned to this class/subject for the selected term.';
        $selected_subject = '';
    }
}

// ============================================================
// GET STUDENTS FOR SELECTED SECTION AND SUBJECT
// ============================================================
$section_students = [];
$grade_data = [];

if ($selected_section > 0 && !empty($selected_subject)) {
    $section_students = getRecords("
        SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.middle_name
        FROM students s
        INNER JOIN enrollments e ON s.student_id = e.student_id
        WHERE e.section_id = ? 
        AND e.school_year_id = ? 
        AND e.enrollment_status = 'Approved'
        AND s.is_active = 1
        ORDER BY s.last_name, s.first_name
    ", [$selected_section, $active_sy['sy_id']]);

    // ---- SINGLE-STUDENT MODE ------------------------------------------
    // The learner is never trusted from the URL. They must appear in the
    // list the query above already built, which means they are active AND
    // approved-enrolled in THIS section for the ACTIVE school year. A
    // learner from another section cannot be reached by editing the URL.
    if ($encode_mode === 'single') {
        if ($selected_student > 0) {
            foreach ($section_students as $s) {
                if ((int)$s['student_id'] === $selected_student) {
                    $single_student = $s;
                    break;
                }
            }
        }

        if ($single_student === null) {
            $single_error = 'That learner is not in this section for the active school year, '
                          . 'so their grades cannot be encoded here. Pick a learner from the list.';
            $section_students = [];
        } else {
            // Everything downstream — the grade prefill query, the table,
            // and the save loop — works off this list, so narrowing it here
            // is all that single-student mode needs.
            $section_students = [$single_student];
        }
    }

    if (!empty($section_students)) {
        $student_ids = array_column($section_students, 'student_id');
        $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
        $grade_data_raw = getRecords("
            SELECT * FROM grades 
            WHERE student_id IN ($placeholders) 
            AND subject_code = ? 
            AND section_id = ? 
            AND school_year_id = ? 
            AND term = ?
        ", array_merge($student_ids, [$selected_subject, $selected_section, $active_sy['sy_id'], $term]));
        
        $grade_data = [];
        foreach ($grade_data_raw as $g) {
            $grade_data[$g['student_id']] = $g;
        }
    }
}

// ============================================================
// GET ASSESSMENT ITEMS (AUTO-COMPUTATION) FOR THIS SUBJECT/SECTION/TERM
// ============================================================
$assessment_items = ['WW' => [], 'PT' => [], 'QA' => []];
$assessment_scores_map = [];

if ($selected_section > 0 && !empty($selected_subject)) {
    $assessment_items = getAssessmentItems($teacher_id, $selected_subject, $selected_section, $active_sy['sy_id'], $term);
    $all_item_ids = array_merge(
        array_column($assessment_items['WW'], 'item_id'),
        array_column($assessment_items['PT'], 'item_id'),
        array_column($assessment_items['QA'], 'item_id')
    );
    $assessment_scores_map = getAssessmentScoresMap($all_item_ids);
}

// ============================================================
// TERM FINALIZATION STATUS
// ============================================================
// Manage Assessment Items (assessment_items + assessment_scores) is the
// system of record for the whole term. `grades` -- Initial Grade / Term
// Grade / Remarks -- is only computed and written when the teacher clicks
// "Finalize <term>" below. gh_getTermFinalization() is the single source
// of truth for whether that has happened yet for this class; the Encode
// Grades table only shows real numbers once it has.
$finalization = ($selected_section > 0 && !empty($selected_subject))
    ? gh_getTermFinalization($teacher_id, $selected_subject, $selected_section, $active_sy['sy_id'], $term)
    : null;
$is_finalized = ($finalization !== null);

// Term calendar window (Start/End Date), if the teacher/admin has set one in
// Manage Assessment Items. Drives the real-time status banner below, and
// -- now that term is auto-detected instead of manually picked -- $term
// above is only ever the currently-ongoing term (or the fallback default
// when none is ongoing). Save and Finalize remain available even when dates
// have not been configured.
$term_window = gh_getTermWindow($active_sy['sy_id'], $term);
$window_status = gh_termWindowStatus($term_window);

// Term Calendar (Term 1/2/3 Start-End dates set by the administrator in
// Teacher Management > Term Dates). Read fresh from term_windows on every
// load, so a date saved there shows up here right away. Display only.
$term_calendar = [];
foreach (gh_threeTerms() as $tc_term) {
    $tc_win = gh_getTermWindow($active_sy['sy_id'], $tc_term);
    $term_calendar[$tc_term] = [
        'range'  => ($tc_win && !empty($tc_win['start_date']) && !empty($tc_win['end_date']))
            ? date('M j', strtotime($tc_win['start_date'])) . ' – ' . date('M j, Y', strtotime($tc_win['end_date']))
            : 'Dates not set',
        'status' => gh_termWindowStatus($tc_win)['status'],
    ];
}

$ctx = 'section_id=' . $selected_section
     . '&subject_code=' . urlencode($selected_subject)
     . '&term=' . urlencode($term);
if ($selected_grade !== '') {
    $ctx .= '&grade=' . urlencode($selected_grade);
}

// ============================================================
// HANDLE GRADE SUBMISSION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_grades']) || isset($_POST['finalize_term']))) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token.';
    } elseif ($term_readonly) {
        // A Completed term is locked history for the whole class: no scores,
        // items or grades may be saved, and it cannot be re-finalized.
        $_SESSION['error_message'] = htmlspecialchars($term) . ' is already Completed for this section, so its grades are locked (read-only). Nothing was changed.';
    } elseif (!$subject_authorized) {
        // The subject in the URL is not one of this teacher's own subjects
        // for this section and term (see the ownership check above). Reject
        // the submit itself -- a hidden Save/Finalize button is not enough,
        // since the URL/POST can be edited directly.
        $_SESSION['error_message'] = 'You are not assigned to this class/subject for the selected term.';
    } else {
        $grades_data = $_POST['grades'] ?? [];

        // In single mode, discard any posted row that is not the learner
        // named in the URL. A crafted POST cannot reach another learner's
        // grades from this page.
        if ($encode_mode === 'single') {
            if ($single_student === null) {
                $grades_data = [];
            } else {
                $grades_data = array_intersect_key($grades_data, [$selected_student => true]);
            }
        }
        $item_scores_data = $_POST['items'] ?? []; // [item_id][student_id] => raw score
        $is_finalize_action = isset($_POST['finalize_term']);
        $success_count = 0;
$skipped_count = 0;   // learners with nothing encoded and no existing row
        $error_count = 0;
        $incomplete_count = 0;
        $score_errors = [];
        $finalized_student_ids = []; // filled in during Finalize, so At-Risk can be refreshed right after
        $transition_state = null;    // set after Finalize when the section's term transition was re-evaluated
        $incomplete_student_names = [];
        $student_name_by_id = [];
        foreach ($section_students as $section_student) {
            $student_name_by_id[$section_student['student_id']] = $section_student['last_name'] . ', ' . $section_student['first_name'];
        }

        // ---- Inline "Highest possible" setup from the encoding modals ------
        // The teacher sets the Highest Possible Score (HPS) right where the
        // scores are typed -- for Written/Oral Works, Performance Tasks (PETA,
        // projects, group output, demonstrations, ...) and each Examination
        // (ST1 / ST2 / Term Exam). Whole-class view only: an HPS is shared by
        // the whole class, so a single-learner request can never change it.
        // Everything below reuses the same assessment_items table Manage
        // Assessment Items writes, so no existing score or item is touched
        // unless its own HPS input was changed.
        $setup_changed = false;
        $inl_notices = [];   // soft "probably the wrong component" notes for items added below
        if ($encode_mode === 'all') {
            $inl_subject_row = null;
            foreach ($section_subjects as $__s) {
                if ($__s['subject_code'] === $selected_subject) { $inl_subject_row = $__s; break; }
            }
            $inl_grade_level = $section_info['grade_level'] ?? null;
            $inl_weights = getSubjectWeights(
                $inl_subject_row ?: $selected_subject, $inl_grade_level, $active_sy,
                getLearnerCurriculumType(null, $inl_grade_level, $selected_section)
            );
            $inl_exam_mode = $inl_weights['exam_mode'] ?? 'standard';
            $inl_required  = ($inl_exam_mode === 'standard') ? ['ST1', 'ST2', 'TE']
                           : (($inl_exam_mode === 'term_exam_only') ? ['TE'] : []);
            $inl_is_multi  = count(gh_subjectComponents($inl_subject_row ?: $selected_subject, $inl_weights['category'] ?? null)) > 1;
            $inl_can_add   = empty($inl_weights['blocked']) && !$inl_is_multi;
            $inl_subtypes  = gh_examSubtypes();

            // 1) Edited Highest Possible Score on existing items.
            // Locked: an existing item's Highest possible is set in Manage
            // Assessment Items only, so a posted hps[] is ignored here.
            $hps_posted = [];
            foreach ($assessment_items as $inl_cat => $inl_list) {
                foreach ($inl_list as $inl_idx => $inl_item) {
                    $iid = (int)$inl_item['item_id'];
                    if (!isset($hps_posted[$iid])) continue;
                    $hps_raw = trim((string)$hps_posted[$iid]);
                    if ($hps_raw === '') continue;

                    if (!is_numeric($hps_raw) || (float)$hps_raw <= 0 || (float)$hps_raw > 100000) {
                        $score_errors[] = htmlspecialchars($inl_item['item_name']) . ': Highest possible must be a number greater than zero.';
                        continue;
                    }
                    $new_hps = round((float)$hps_raw, 2);
                    if (abs($new_hps - (float)$inl_item['highest_score']) < 0.005) continue;

                    // The new HPS can never be lower than a score that would
                    // still be recorded (stored scores, overridden by whatever
                    // is being posted in this same save).
                    $by_student = [];
                    foreach (getRecords("SELECT student_id, raw_score FROM assessment_scores WHERE item_id = ?", [$iid]) as $r) {
                        if ($r['raw_score'] !== null && $r['raw_score'] !== '') $by_student[(int)$r['student_id']] = (float)$r['raw_score'];
                    }
                    foreach (($item_scores_data[$iid] ?? []) as $p_sid => $p_raw) {
                        $p_sid = (int)$p_sid;
                        if ($p_raw === '' || $p_raw === null) { unset($by_student[$p_sid]); }
                        elseif (is_numeric($p_raw)) { $by_student[$p_sid] = (float)$p_raw; }
                    }
                    $max_raw = empty($by_student) ? 0.0 : max($by_student);
                    if ($new_hps + 0.00001 < $max_raw) {
                        $score_errors[] = htmlspecialchars($inl_item['item_name']) . ': Highest possible cannot be lowered to '
                            . rtrim(rtrim(number_format($new_hps, 2), '0'), '.') . ' because a score of '
                            . rtrim(rtrim(number_format($max_raw, 2), '0'), '.') . ' is already recorded. Correct that score first.';
                        continue;
                    }

                    executeQuery("UPDATE assessment_items SET highest_score = ? WHERE item_id = ? AND teacher_id = ?", [$new_hps, $iid, $teacher_id]);
                    $assessment_items[$inl_cat][$inl_idx]['highest_score'] = $new_hps; // score validation below uses the new HPS
                    $setup_changed = true;
                    logActivity($teacher_id, 'Updated assessment item', 'grading', "Set Highest possible of '{$inl_item['item_name']}' to $new_hps for $selected_subject / section $selected_section / $term");
                }
            }

            // 2) New items typed into the modals.
            $new_items_posted = (isset($_POST['new_item']) && is_array($_POST['new_item'])) ? $_POST['new_item'] : [];
            $add_item = function ($cat, $name, $hps, $subtype) use ($teacher_id, $selected_subject, $selected_section, $active_sy, $term) {
                $order = getRecord("SELECT COALESCE(MAX(item_order), 0) AS m FROM assessment_items WHERE teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ? AND category = ?",
                    [$teacher_id, $selected_subject, $selected_section, $active_sy['sy_id'], $term, $cat]);
                executeQuery("INSERT INTO assessment_items (teacher_id, subject_code, section_id, school_year_id, term, category, item_name, highest_score, item_order, assessment_subtype, assessment_domain, component)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)",
                    [$teacher_id, $selected_subject, $selected_section, $active_sy['sy_id'], $term, $cat, $name, $hps, (int)($order['m'] ?? 0) + 1, $subtype]);
                logActivity($teacher_id, 'Added assessment item', 'grading', "Added '$name' ($cat) for $selected_subject / section $selected_section / $term");
            };

            foreach (['WW', 'PT'] as $inl_cat) {
                $n = (isset($new_items_posted[$inl_cat]) && is_array($new_items_posted[$inl_cat])) ? $new_items_posted[$inl_cat] : null;
                if ($n === null || !$inl_can_add) continue;
                $n_name = trim(sanitize($n['name'] ?? ''));
                $n_hps  = trim((string)($n['hps'] ?? ''));
                if ($n_name === '' && $n_hps === '') continue;          // nothing typed
                $cat_label = ($inl_cat === 'PT') ? 'Performance Tasks' : 'Written/Oral Works';
                if ($n_name === '') $n_name = ($inl_cat === 'PT') ? 'PT Total' : 'WW Total';
                if (!is_numeric($n_hps) || (float)$n_hps <= 0 || (float)$n_hps > 100000) {
                    $score_errors[] = $cat_label . ': enter the Highest possible (a number greater than zero) for "' . htmlspecialchars($n_name) . '".';
                    continue;
                }
                // Attendance is never a grading component; a name that points at another
                // component is only flagged (the item is still saved).
                $n_hint = gh_activityNameHint($inl_cat, $n_name);
                if ($n_hint && $n_hint['type'] === 'attendance') {
                    $score_errors[] = 'Not added (' . $cat_label . '): ' . $n_hint['message'];
                    continue;
                }
                $add_item($inl_cat, $n_name, round((float)$n_hps, 2), null);
                $setup_changed = true;
                if ($n_hint && $n_hint['type'] === 'misfiled') {
                    $inl_notices[] = 'Note: ' . $n_hint['message'];
                }
            }

            // Examinations: one Highest-possible box per required test that has no item yet.
            $n = (isset($new_items_posted['QA']) && is_array($new_items_posted['QA'])) ? $new_items_posted['QA'] : null;
            if ($n !== null && $inl_can_add && !empty($inl_required)) {
                foreach ($inl_required as $code) {
                    $n_hps = trim((string)($n[$code] ?? ''));
                    if ($n_hps === '') continue;
                    $has_one = false;
                    foreach ($assessment_items['QA'] as $qi) { if (($qi['assessment_subtype'] ?? null) === $code) { $has_one = true; break; } }
                    if ($has_one) continue;
                    if (!is_numeric($n_hps) || (float)$n_hps <= 0 || (float)$n_hps > 100000) {
                        $score_errors[] = $inl_subtypes[$code]['label'] . ': enter the Highest possible (a number greater than zero).';
                        continue;
                    }
                    $add_item('QA', $inl_subtypes[$code]['label'], round((float)$n_hps, 2), $code);
                    $setup_changed = true;
                }
            }

            if ($setup_changed) {
                // Reload so the score loop, the Finalize computation and the
                // page that is rendered next all see the new items / HPS.
                $assessment_items = getAssessmentItems($teacher_id, $selected_subject, $selected_section, $active_sy['sy_id'], $term);
            }
        }

        // ---- Save the raw per-item scores (auto-computation source data) ----
        foreach ($assessment_items as $cat_items) {
            foreach ($cat_items as $item) {
                $iid = $item['item_id'];
                if (!isset($item_scores_data[$iid])) continue;

                foreach ($item_scores_data[$iid] as $sid => $raw) {
                    $sid = intval($sid);
                    if (!isset($student_name_by_id[$sid])) continue; // not an approved learner of this section

                    // Server-side validation: HPS > 0 and 0 <= raw <= HPS.
                    // Impossible scores are REJECTED, never silently clamped.
                    // A blank stays NULL so "not encoded yet" is preserved as
                    // distinct from a real zero.
                    $check = validateRawScore($raw, $item['highest_score']);
                    if (!$check['valid']) {
                        $score_errors[] = htmlspecialchars($item['item_name']) . ': ' . $check['error'];
                        continue;
                    }
                    $raw = $check['value'];

                    $existing_score = getRecord("SELECT score_id FROM assessment_scores WHERE item_id = ? AND student_id = ?", [$iid, $sid]);
                    if ($existing_score) {
                        executeQuery("UPDATE assessment_scores SET raw_score = ?, updated_at = NOW() WHERE score_id = ?", [$raw, $existing_score['score_id']]);
                    } else {
                        executeQuery("INSERT INTO assessment_scores (item_id, student_id, raw_score) VALUES (?, ?, ?)", [$iid, $sid, $raw]);
                    }
                }
            }
        }

        // Refresh the score map so the % computed below reflects what was just saved
        $all_item_ids = array_merge(
            array_column($assessment_items['WW'], 'item_id'),
            array_column($assessment_items['PT'], 'item_id'),
            array_column($assessment_items['QA'], 'item_id')
        );
        $assessment_scores_map = getAssessmentScoresMap($all_item_ids);

        if (!$is_finalize_action) {
            // ---- ROUTINE SAVE ------------------------------------------
            // Manage Assessment Items (assessment_items + assessment_scores)
            // is the system of record for the whole term. Saving here NEVER
            // computes or writes the student's Initial Grade / Term Grade /
            // Remarks into `grades` -- that only happens when the teacher
            // clicks "Finalize <term>", below. Partial, in-progress scores
            // stay safely stored for as long as the term is ongoing, and
            // nothing here overwrites a grade that was already finalized.
            logActivity($teacher_id, 'Saved assessment scores', 'grading', "Saved scores for subject: $selected_subject, section: $selected_section, term: $term");

            // ---- DRAFT MANUAL PERCENTAGES ------------------------------
            // A category with NO assessment items yet is typed directly as
            // a 0-100 percentage instead of Excel-style raw scores. Store
            // what was typed here so it survives a page reload, exactly
            // like the per-item raw scores above already do -- this is the
            // ONLY thing this block does. It never runs once the class has
            // been finalized (that value only ever changes via "Re-finalize"
            // below), it never touches a category that HAS assessment items
            // (those are always computed from assessment_scores at Finalize
            // time, never from a typed percentage), and it never writes an
            // Initial Grade / Term Grade / Remarks -- the row is marked
            // remarks = 'Draft' so gh_validTermSql()/gh_isValidGradeRow()
            // and the student portal keep ignoring it, exactly like an
            // Incomplete record, until the teacher actually finalizes.
            if (!$is_finalized && empty($score_errors)) {
                $draft_fields = ['written_work' => 'WW', 'performance_task' => 'PT', 'examinations' => 'QA'];
                foreach ($grades_data as $draft_student_id => $draft_row) {
                    $draft_vals = [];
                    $has_manual = false;
                    foreach ($draft_fields as $field_name => $cat_key) {
                        // Item-driven category -- never store a manual value
                        // for it here, even if one was somehow posted.
                        if (!empty($assessment_items[$cat_key])) {
                            $draft_vals[$field_name] = null;
                            continue;
                        }
                        $raw_val = $draft_row[$field_name] ?? '';
                        if ($raw_val === '') {
                            $draft_vals[$field_name] = null;
                            continue;
                        }
                        $fv = floatval($raw_val);
                        if ($fv < 0 || $fv > 100) {
                            // Same 0-100 rule Finalize enforces -- an
                            // impossible value is simply not stored rather
                            // than fabricated into something valid.
                            continue;
                        }
                        $draft_vals[$field_name] = $fv;
                        $has_manual = true;
                    }
                    if (!$has_manual) continue;

                    $existing_draft = getRecord(
                        "SELECT grade_id, written_work, performance_task, examinations, remarks
                         FROM grades WHERE student_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ?",
                        [$draft_student_id, $selected_subject, $selected_section, $active_sy['sy_id'], $term]
                    );

                    if ($existing_draft) {
                        // Only ever touch a row that is still a Draft --
                        // an official (Finalized/Incomplete/etc.) row is
                        // NEVER modified here; existing scores are never
                        // overwritten outside of Finalize/Re-finalize.
                        if (($existing_draft['remarks'] ?? null) !== 'Draft') {
                            continue;
                        }
                        $merged_ww = array_key_exists('written_work', $draft_vals) && $draft_vals['written_work'] !== null ? $draft_vals['written_work'] : $existing_draft['written_work'];
                        $merged_pt = array_key_exists('performance_task', $draft_vals) && $draft_vals['performance_task'] !== null ? $draft_vals['performance_task'] : $existing_draft['performance_task'];
                        $merged_ex = array_key_exists('examinations', $draft_vals) && $draft_vals['examinations'] !== null ? $draft_vals['examinations'] : $existing_draft['examinations'];
                        executeQuery(
                            "UPDATE grades SET written_work = ?, performance_task = ?, examinations = ?, remarks = 'Draft', updated_at = NOW() WHERE grade_id = ?",
                            [$merged_ww, $merged_pt, $merged_ex, $existing_draft['grade_id']]
                        );
                    } else {
                        executeQuery(
                            "INSERT INTO grades (student_id, subject_code, teacher_id, section_id, school_year_id, term,
                                written_work, performance_task, examinations, initial_grade, transmuted_grade, final_grade, remarks, encoded_by)
                             VALUES (?,?,?,?,?,?,?,?,?,0,0,0,'Draft',?)",
                            [$draft_student_id, $selected_subject, $teacher_id, $selected_section, $active_sy['sy_id'], $term,
                             $draft_vals['written_work'], $draft_vals['performance_task'], $draft_vals['examinations'], $teacher_id]
                        );
                    }
                }
            }

            if (!empty($score_errors)) {
                $_SESSION['error_message'] = implode(' ', array_unique($score_errors));
            } else {
                $_SESSION['success_message'] = 'Scores saved to Manage Assessment Items. Click "Finalize '
                    . htmlspecialchars($term) . '" when the term is complete to compute the official grades.';
            }
            if (!empty($inl_notices)) {
                $_SESSION['success_message'] = trim(($_SESSION['success_message'] ?? '') . ' ' . implode(' ', $inl_notices));
            }

            if ($encode_mode === 'single') {
                header("Location: index.php?step=5&" . $ctx . "&student_id=" . $selected_student . "&mode=single");
            } else {
                // When an item / Highest possible was just set up from a modal,
                // reopen that same modal so the teacher can type the scores next.
                $reopen = '';
                $done_cat = (string)($_POST['save_grades'] ?? '');
                if ($setup_changed && in_array($done_cat, ['ww', 'pt', 'qa'], true)) {
                    $reopen = '&open=' . $done_cat;
                }
                header("Location: index.php?step=5&" . $ctx . $reopen);
            }
            exit;
        }

        // Finalizing computes and locks in the OFFICIAL Term Grade for every
        // learner in the class at once, so it is only offered -- and only
        // honoured -- from the whole-class view, never a single learner.
        if ($encode_mode === 'single') {
            $_SESSION['error_message'] = 'Finalize the term from the full class list, not a single learner.';
            header("Location: index.php?step=5&" . $ctx . "&student_id=" . $selected_student . "&mode=single");
            exit;
        }

        // Resolve the subject row once; the central weight helper needs the
        // configured category, not just the legacy subject_type string.
        $subject_row = null;
        foreach ($section_subjects as $subj) {
            if ($subj['subject_code'] == $selected_subject) {
                $subject_row = $subj;
                break;
            }
        }

        foreach ($grades_data as $student_id => $grade) {
            // Weights are resolved PER LEARNER so that a Grade 12 learner's
            // curriculum type (legacy vs Strengthened SHS) is honoured.
            $weights = getSubjectWeightsForStudent($subject_row ?: $selected_subject, $student_id, $active_sy);
            $weights['uses_domains'] = false;
            // Components of a multi-component learning area (MAPEH: Music,
            // Arts, Physical Education, Health). Empty for ordinary subjects.
            $enc_components = gh_subjectComponents($subject_row ?: $selected_subject, $weights['category'] ?? null);
            $enc_is_multi   = count($enc_components) > 1;

            // Never write a grade we were not able to determine the rule for.
            if (!empty($weights['blocked'])) {
                $error_count++;
                continue;
            }

            // Auto-compute each category % from the assessment items when items
            // are set up ("Excel style"); otherwise fall back to the manually
            // typed percentage. A blank stays NULL — it is not a zero.
            $manual_ww = ($grade['written_work'] ?? '') === '' ? null : floatval($grade['written_work']);
            $manual_pt = ($grade['performance_task'] ?? '') === '' ? null : floatval($grade['performance_task']);
            $manual_ex = ($grade['examinations'] ?? '') === '' ? null : floatval($grade['examinations']);

            // Manually entered COMPONENT PERCENTAGES must be 0-100. An
            // impossible value is REJECTED, never silently clamped — clamping
            // 123% down to 100% would fabricate a passing grade out of invalid
            // data. (Item-level raw scores are validated separately above by
            // validateRawScore().)
            $manual_invalid = [];
            foreach ([
                'Written/Oral Works' => $manual_ww,
                'Performance Tasks' => $manual_pt,
                gh_examComponentLabel($active_sy) => $manual_ex,
            ] as $label => $pct) {
                if ($pct !== null && ($pct < 0 || $pct > 100)) {
                    $manual_invalid[] = $label;
                }
            }
            if (!empty($manual_invalid)) {
                $score_errors['manual_range'] = 'Percentage scores must be between 0 and 100 — rejected: '
                    . implode(', ', $manual_invalid) . '. This learner was not saved.';
                $error_count++;
                continue;
            }

            $components = ['ww' => null, 'pt' => null, 'ex' => null];
            $exam_notice     = null;
            $exam_incomplete = false;   // a required ST1/ST2/TE has no score yet
            $exam_missing    = [];

            // ---- MULTI-COMPONENT LEARNING AREA (e.g. MAPEH) ----------------
            // Each component (Music, Arts, PE, Health) is computed and
            // transmuted on its own; the learning area grade is the AVERAGE of
            // the component grades. Component scores are never pooled, and a
            // single component below 75 does NOT fail the learning area.
            if ($enc_is_multi) {
                $mc = computeMultiComponentTermGrade(
                    $weights, $enc_components, $assessment_items,
                    $assessment_scores_map, $student_id, $active_sy
                );

                if ($mc['transmuted_grade'] === null) {
                    // Nothing encoded for any component yet.
                    $existing_check = getRecord("SELECT grade_id FROM grades WHERE student_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ?",
                        [$student_id, $selected_subject, $selected_section, $active_sy['sy_id'], $term]);
                    if (!$existing_check) {
                        $incomplete_count++;
                        $incomplete_student_names[] = $student_name_by_id[$student_id] ?? ('Student #' . $student_id);
                        continue;
                    }
                }

                // Preserve the INDIVIDUAL component grades.
                foreach ($mc['components'] as $comp_name => $cg) {
                    if ($cg['transmuted_grade'] === null) { continue; }
                    executeQuery(
                        "INSERT INTO grade_components
                            (student_id, subject_code, section_id, school_year_id, term, component,
                             written_work, performance_task, examinations,
                             initial_grade, transmuted_grade, remarks, encoded_by)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                            written_work=VALUES(written_work), performance_task=VALUES(performance_task),
                            examinations=VALUES(examinations), initial_grade=VALUES(initial_grade),
                            transmuted_grade=VALUES(transmuted_grade), remarks=VALUES(remarks),
                            encoded_by=VALUES(encoded_by)",
                        [$student_id, $selected_subject, $selected_section, $active_sy['sy_id'], $term, $comp_name,
                         $cg['ww'], $cg['pt'], $cg['ex'],
                         $cg['initial_grade'], $cg['transmuted_grade'], $cg['remarks'], $teacher_id]
                    );
                }

                // The overall learning-area grade goes in `grades` as usual, so
                // every existing report, risk and portal query keeps working.
                // Same rule as the single-component branch below: a component
                // still missing a score must NEVER produce a stored Initial
                // Grade / Term Grade, even though computeMultiComponentTermGrade()
                // still returns a renormalised preview number for it.
                $written_work         = null;
                $performance_task     = null;
                $examinations = null;
                $initial_grade        = $mc['complete'] ? $mc['initial_grade'] : 0;
                $transmuted_grade     = $mc['complete'] ? $mc['transmuted_grade'] : 0;
                $final_grade          = $transmuted_grade;
                $remarks              = $mc['complete'] ? $mc['remarks'] : 'Incomplete';

                if (!$mc['complete']) {
                    $incomplete_count++;
                    $incomplete_student_names[] = $student_name_by_id[$student_id] ?? ('Student #' . $student_id);
                    if (!empty($mc['missing'])) {
                        $score_errors['mc_missing'] = 'No scores encoded yet for: '
                            . implode(', ', $mc['missing']) . '. The '
                            . htmlspecialchars($weights['category_label']) . ' grade stays Incomplete '
                            . 'until every component is graded.';
                    }
                }

                $existing = getRecord("SELECT grade_id FROM grades WHERE student_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ?",
                    [$student_id, $selected_subject, $selected_section, $active_sy['sy_id'], $term]);
                if ($existing) {
                    $sql = "UPDATE grades SET written_work = ?, performance_task = ?, examinations = ?,
                            initial_grade = ?, transmuted_grade = ?, final_grade = ?, remarks = ?, updated_at = NOW()
                            WHERE grade_id = ?";
                    $params = [$written_work, $performance_task, $examinations, $initial_grade, $transmuted_grade, $final_grade, $remarks, $existing['grade_id']];
                } else {
                    $sql = "INSERT INTO grades (student_id, subject_code, teacher_id, section_id, school_year_id, term,
                            written_work, performance_task, examinations, initial_grade, transmuted_grade, final_grade, remarks, encoded_by)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                    $params = [$student_id, $selected_subject, $teacher_id, $selected_section, $active_sy['sy_id'], $term,
                               $written_work, $performance_task, $examinations, $initial_grade, $transmuted_grade, $final_grade, $remarks, $teacher_id];
                }
                if (executeQuery($sql, $params)) {
                    $success_count++;
                    $finalized_student_ids[] = $student_id;
                } else {
                    $score_errors['db_write'] = 'The database rejected the grade for at least one learner. '
                        . 'Nothing was saved for them. Check the server error log for the SQL error.';
                    $error_count++;
                }
                continue;
            }

            if (!empty($weights['uses_domains'])) {
                // GMRC / Values Education keep their required Cognitive,
                // Affective and Behavioral domains — the WW and PT scores are
                // never pooled into one undifferentiated category.
                $dom = computeDomainPercents(
                    $assessment_items['WW'], $assessment_items['PT'], $assessment_items['QA'],
                    $assessment_scores_map, $student_id, $weights['exam_mode']
                );
                $dp = $dom['percents'];

                // MANUAL MODE — exactly the same fallback the non-domain
                // branch below already uses. When a category has NO
                // assessment items, the percentage the teacher typed for
                // that category is used for the domains that make it up.
                //
                // This does NOT change the formula. The domain weights
                // already sum to their category weight (WW .10+.10 = .20,
                // PT .10+.10+.30 = .50, EX .30), so a single typed WW of
                // 80 contributes 80 x .20 — identical to a non-domain
                // subject. Item-based encoding is untouched: the fallback
                // only applies where there are no items to compute from.
                if (empty($assessment_items['WW'])) {
                    if ($dp['ww_cognitive'] === null) $dp['ww_cognitive'] = $manual_ww;
                    if ($dp['ww_affective'] === null) $dp['ww_affective'] = $manual_ww;
                }
                if (empty($assessment_items['PT'])) {
                    if ($dp['pt_cognitive']  === null) $dp['pt_cognitive']  = $manual_pt;
                    if ($dp['pt_affective']  === null) $dp['pt_affective']  = $manual_pt;
                    if ($dp['pt_behavioral'] === null) $dp['pt_behavioral'] = $manual_pt;
                }
                if ($weights['exam_mode'] !== 'none' && empty($assessment_items['QA'])) {
                    if ($dp['ex'] === null) $dp['ex'] = $manual_ex;
                }

                $components['domains'] = $dp;
                $written_work         = $dp['ww_cognitive'];
                $performance_task     = $dp['pt_behavioral'];
                $examinations         = $dp['ex'];
                $exam_notice          = $dom['exam']['notice'] ?? null;
            } else {
                $components['ww'] = !empty($assessment_items['WW'])
                    ? computeCategoryPercent($assessment_items['WW'], $assessment_scores_map, $student_id)
                    : $manual_ww;
                if ($components['ww'] === null && !empty($assessment_items['WW'])) $components['ww'] = $manual_ww;

                $components['pt'] = !empty($assessment_items['PT'])
                    ? computeCategoryPercent($assessment_items['PT'], $assessment_scores_map, $student_id)
                    : $manual_pt;
                if ($components['pt'] === null && !empty($assessment_items['PT'])) $components['pt'] = $manual_pt;

                // EXAMINATIONS (stored in the legacy examinations field).
                // ST1/ST2/TE are each converted to their own percentage score
                // and combined 30/30/40 — they are never pooled by raw score.
                if ($weights['exam_mode'] === 'none') {
                    $components['ex'] = null;
                } elseif (!empty($assessment_items['QA'])) {
                    $exam = computeExamPercent($assessment_items['QA'], $assessment_scores_map, $student_id, $weights['exam_mode']);
                    $components['ex'] = $exam['percent'];
                    $exam_notice = $exam['notice'];
                    // A required ST1/ST2/TE with no score yet leaves the EX
                    // component partial. computeExamPercent() still returns a
                    // renormalised percentage for preview, but the Term Grade
                    // must NOT be reported as complete on that basis.
                    if (!$exam['complete'] && !empty($exam['missing'])) {
                        $exam_incomplete = true;
                        $exam_missing    = $exam['missing'];
                    }
                    if ($components['ex'] === null) $components['ex'] = $manual_ex;
                } else {
                    $components['ex'] = $manual_ex;
                }

                $written_work         = $components['ww'];
                $performance_task     = $components['pt'];
                $examinations = $components['ex'];
            }

            if ($exam_notice) {
                $score_errors['exam_subtype'] = $exam_notice;
            }

            // Nothing encoded at all for this learner: don't create a row from
            // empty data (an existing row is still refreshed).
            $nothing_encoded = ($written_work === null && $performance_task === null && $examinations === null);
            if ($nothing_encoded) {
                $existing_check = getRecord("SELECT grade_id FROM grades WHERE student_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ?",
                    [$student_id, $selected_subject, $selected_section, $active_sy['sy_id'], $term]);
                if (!$existing_check) {
                    // Genuinely nothing typed and nothing to compute from.
                    // Counted, so a whole batch skipped this way can never
                    // again be reported as a bare "0 grades saved".
                    $skipped_count++;
                    continue;
                }
            }

            $computed = computeTermGrade($weights, $components, $active_sy);

            // A missing WW, PT, or a required Summative Test / Term
            // Examination must NEVER produce a stored Initial Grade or Term
            // Grade. computeTermGrade()/computeInitialGrade() still return a
            // renormalised NUMBER even when something required is missing
            // (that number is only meant as an on-screen preview while the
            // teacher is still encoding) -- so the persisted record must not
            // use it. Only a genuinely complete record (every WW/PT/EX
            // component present, and every required ST1/ST2/TE scored) is
            // ever written as a real Initial Grade / Term Grade; anything
            // else is stored as 0 with remarks = 'Incomplete', exactly like
            // a category that was never encoded at all.
            $is_fully_complete = $computed['complete'] && !$exam_incomplete;

            $initial_grade    = $is_fully_complete ? $computed['initial_grade'] : 0;
            $transmuted_grade = $is_fully_complete ? $computed['transmuted_grade'] : 0;
            $final_grade      = $transmuted_grade;
            $remarks          = $is_fully_complete ? $computed['remarks'] : 'Incomplete';
            if ($exam_incomplete) {
                $incomplete_student_names[] = $student_name_by_id[$student_id] ?? ('Student #' . $student_id);
            }
            if (!$is_fully_complete) {
                $incomplete_count++;
            }

            // Store 0 rather than NULL for components that are not encoded yet,
            // because these columns are NOT NULL DEFAULT 0.00 in the existing
            // schema; 'Incomplete' in remarks is what marks the record as unfinished.
            $written_work         = $written_work === null ? 0 : $written_work;
            $performance_task     = $performance_task === null ? 0 : $performance_task;
            $examinations = $examinations === null ? 0 : $examinations;
            
            $existing = getRecord("SELECT grade_id FROM grades WHERE student_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ?",
                [$student_id, $selected_subject, $selected_section, $active_sy['sy_id'], $term]);
            
            if ($existing) {
                $sql = "UPDATE grades SET 
                        written_work = ?, performance_task = ?, examinations = ?,
                        initial_grade = ?, transmuted_grade = ?, final_grade = ?, remarks = ?,
                        updated_at = NOW()
                        WHERE grade_id = ?";
                $params = [$written_work, $performance_task, $examinations, $initial_grade, $transmuted_grade, $final_grade, $remarks, $existing['grade_id']];
            } else {
                $sql = "INSERT INTO grades (student_id, subject_code, teacher_id, section_id, school_year_id, term,
                        written_work, performance_task, examinations, initial_grade, transmuted_grade, final_grade, remarks, encoded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $params = [
                    $student_id, $selected_subject, $teacher_id, $selected_section, $active_sy['sy_id'], $term,
                    $written_work, $performance_task, $examinations, $initial_grade, $transmuted_grade, $final_grade, $remarks, $teacher_id
                ];
            }
            
            if (executeQuery($sql, $params)) {
                $success_count++;
                $finalized_student_ids[] = $student_id;
            } else {
                $score_errors['db_write'] = 'The database rejected the grade for at least one learner. '
                    . 'Nothing was saved for them. Check the server error log for the SQL error.';
                $error_count++;
            }
        }

        logActivity($teacher_id, 'Finalized term grades', 'grading', "Finalized $term grades for subject: $selected_subject, section: $selected_section");
        
        if ($success_count > 0) {
            $complete_count = max($success_count - $incomplete_count, 0);
            $finalization_saved = gh_recordTermFinalization(
                $teacher_id, $selected_subject, $selected_section, $active_sy['sy_id'], $term,
                $teacher_id, count($section_students), $complete_count, $incomplete_count
            );

            // Refresh At-Risk Students immediately for everyone just finalized,
            // instead of waiting for someone to open Ask AI for them first.
            // syncStudentRiskAnalysis() recomputes from the CURRENT gh_validTermSql()
            // grades (which now include the Term Grade(s) just written) and quietly
            // skips a student who still has insufficient data elsewhere.
            foreach (array_unique($finalized_student_ids) as $finalized_sid) {
                syncStudentRiskAnalysis($finalized_sid, $active_sy['sy_id']);
            }

            $msg = 'Term ' . htmlspecialchars($term) . ' finalized. ' . $success_count
                 . ($success_count === 1 ? ' grade' : ' grades') . ' computed and now shown in Encode Grades.';
            if ($incomplete_count > 0) {
                $msg .= ' Completed: ' . max($success_count - $incomplete_count, 0) . '. Incomplete: ' . $incomplete_count . ' — a required component '
                      . '(Summative Test or Term Examination) has not been encoded yet for: '
                      . implode(', ', array_unique($incomplete_student_names)) . '. Correct the scores in '
                      . 'Manage Assessment Items and finalize again once they are complete.';
                $_SESSION['error_message'] = 'Incomplete students: ' . implode(', ', array_unique($incomplete_student_names))
                    . '. Their grades were saved as incomplete and can be finalized again after the missing scores are entered.';
            }
            $_SESSION['success_message'] = $msg;

            // Term transition: this Finalize may have been the last piece the
            // section needed. If every subject is now finalized and complete,
            // the term is marked Completed and the next term is enabled.
            $transition_state = gh_syncSectionTerms($selected_section, $active_sy['sy_id'], $teacher_id);
            if (!$finalization_saved) {
                // Never say "finalized" when the finalization record itself could not be
                // saved -- the class would not count as finalized anywhere (Encode Grades,
                // the Term 1 -> Term 2 checklist) and the teacher would be left guessing.
                $_SESSION['success_message'] = $success_count . ($success_count === 1 ? ' grade' : ' grades')
                    . ' computed, but ' . htmlspecialchars($term) . ' was NOT marked as finalized.';
                $_SESSION['error_message'] = trim(($_SESSION['error_message'] ?? '') . ' The finalization record could not be saved'
                    . (!empty($GLOBALS['gh_term_finalization_error']) ? ' (' . htmlspecialchars($GLOBALS['gh_term_finalization_error']) . ')' : '')
                    . '. Please tell the administrator.');
            } elseif (!empty($transition_state['newly'])) {
                $_SESSION['success_message'] .= ' ' . htmlspecialchars(implode(' and ', $transition_state['newly'])) . ' is now Completed for this section'
                    . ($transition_state['all_completed']
                        ? ' -- all three terms are done. Every grade is kept as a locked, read-only record.'
                        : ' and ' . htmlspecialchars($transition_state['current']) . ' is enabled. The earlier grades are kept as locked, read-only records.');
            }
            // End of the school year for this section: each learner's Final Grades were just
            // computed and their year status saved (Section 17c in grading_helpers.php).
            if ($finalization_saved && !empty($transition_state['year']['created'])) {
                $yr = $transition_state['year'];
                $_SESSION['success_message'] .= ' ' . (int)$yr['promoted'] . ' learner' . ((int)$yr['promoted'] === 1 ? '' : 's')
                    . ' marked ' . gh_yearStatusPromotedLabel()
                    . ((int)$yr['not_promoted'] > 0 ? ' (' . (int)$yr['not_promoted'] . ' not promoted -- a subject Final Grade is below the pass mark)' : '') . '.';
            }
        } else {
            $_SESSION['error_message'] = 'Nothing to finalize yet — no scores have been recorded in Manage Assessment Items for this class.';
        }

        $problems = [];
        if ($error_count > 0) {
            $problems[] = $error_count . ' grades could not be computed (see the configuration notice above).';
        }
        if ($skipped_count > 0) {
            // Never again report a batch that saved nothing as a success.
            $problems[] = $skipped_count . ' learner(s) were skipped because no score was entered for them. '
                        . 'Type a percentage in Written/Oral Works, Performance Tasks or '
                        . gh_examComponentLabel($active_sy) . ', or set up assessment items first.';
        }
        foreach ($score_errors as $se) { $problems[] = $se; }
        if (!empty($problems)) {
            $_SESSION['error_message'] = trim(($_SESSION['error_message'] ?? '') . ' ' . implode(' ', array_unique($problems)));
        }

        // Post/Redirect/Get so a browser refresh never re-finalizes.
        // When this Finalize just completed the term and enabled the next
        // one, land on My Subjects for the new term instead of the now-locked one.
        if (!empty($transition_state['newly']) && empty($transition_state['all_completed'])) {
            header("Location: index.php?step=3&grade=" . urlencode($selected_grade) . "&section_id=" . $selected_section
                . "&term=" . urlencode($transition_state['current']) . "&success=1");
            exit;
        }
        header("Location: index.php?step=5&" . $ctx . "&success=1");
        exit;
    }
}

$csrf_token = generateCSRFToken();

/**
 * Term Transition panel for the selected section: a "Completed / read-only"
 * banner when the term being viewed is locked, otherwise a checklist of what
 * is still needed before the class moves to the next term.
 */
function gradingTermTransitionPanel($state, $term, $readonly, $grade, $section_id) {
    if (!$state) return;
    $terms = gh_threeTerms();
    $idx = array_search($term, $terms, true);
    $next = ($idx !== false && isset($terms[$idx + 1])) ? $terms[$idx + 1] : null;
    $go = function ($t) use ($grade, $section_id) {
        return '?step=3&grade=' . urlencode($grade) . '&section_id=' . (int)$section_id . '&term=' . urlencode($t);
    };

    if ($readonly) {
        $when = $state['completed_at'][$term] ?? null;
        echo '<div class="alert d-flex justify-content-between align-items-center flex-wrap gap-2" style="border-radius:8px;font-size:14.5px;background:#EEF2F7;color:#26364F;border:1px solid #D3DBE7;">';
        echo '<div><i class="fas fa-lock me-1"></i><strong>' . htmlspecialchars($term) . ' is Completed</strong>'
           . ($when ? ' since ' . htmlspecialchars(date('M j, Y', strtotime($when))) : '')
           . ' for this section. Its grades are kept as locked, read-only records &mdash; they cannot be edited or re-finalized.';
        echo $state['all_completed']
            ? ' All three terms of this class are done.'
            : ' This class is now on <strong>' . htmlspecialchars($state['current']) . '</strong>.';
        echo '</div>';
        if (!$state['all_completed'] && $term !== $state['current']) {
            echo '<a href="' . htmlspecialchars($go($state['current'])) . '" class="btn btn-sm" style="background:#14213D;color:#fff;border:none;">Go to ' . htmlspecialchars($state['current']) . '</a>';
        }
        echo '</div>';
        return;
    }

    $p = $state['progress'];
    if (!$p) return;
    $total = (int)$p['subject_count'];
    $fin = (int)$p['finalized_count'];
    $ok_subjects = 0;
    foreach ($p['subjects'] as $sj) { if ($sj['problem'] === '') $ok_subjects++; }
    echo '<div class="alert mb-3" style="border-radius:8px;font-size:14.5px;background:#FFF8E6;color:#6B4E00;border:1px solid #F3DFA2;">';
    echo '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">';
    echo '<div><i class="fas fa-route me-1"></i><strong>' . htmlspecialchars($term) . ' &rarr; ' . ($next ? htmlspecialchars($next) : 'end of year') . '.</strong> ';
    echo $next
        ? 'This class moves to ' . htmlspecialchars($next) . ' automatically once every subject is finalized and every learner\'s grade is complete.'
        : htmlspecialchars($term) . ' is the last term; it is Completed once every subject is finalized and every learner\'s grade is complete.';
    echo '</div>';
    echo '<span class="badge" style="background:#B54708;" title="Complete = finalized AND every learner has a Passed/Failed grade">'
       . $ok_subjects . ' of ' . $total . ' subject' . ($total === 1 ? '' : 's') . ' complete'
       . ($fin !== $ok_subjects ? ' &middot; ' . $fin . ' finalized' : '') . '</span>';
    echo '</div>';
    foreach ($p['reasons'] as $r) {
        echo '<div class="mt-1"><i class="fas fa-circle-info me-1"></i>' . htmlspecialchars($r) . '</div>';
    }
    if (!empty($p['subjects'])) {
        echo '<details class="mt-2"><summary style="cursor:pointer;font-weight:600;">What is still needed</summary><ul class="mb-0 mt-2" style="padding-left:18px;">';
        foreach ($p['subjects'] as $sj) {
            echo '<li>' . htmlspecialchars($sj['subject_name']) . ' &mdash; ';
            if ($sj['problem'] === '') {
                echo '<span style="color:#0F7B4E;font-weight:600;">Finalized &amp; complete</span>';
            } else {
                echo '<span style="font-weight:600;">' . htmlspecialchars($sj['problem']) . '</span>';
                if (!empty($sj['pending_students']) && !empty($sj['finalized'])) {
                    $shown = array_slice($sj['pending_students'], 0, 6);
                    echo ' (' . htmlspecialchars(implode('; ', $shown)) . (count($sj['pending_students']) > 6 ? '; +' . (count($sj['pending_students']) - 6) . ' more' : '') . ')';
                }
            }
            echo '</li>';
        }
        echo '</ul></details>';
    }
    echo '</div>';
}

include '../../includes/header.php';
?>

<style>
    /* Grade Level cards (STEP 1) now use the shared global .grade-card
       component from static/css/style.css (same one used by Student/
       Teacher/Subject Management), so .step-card was renamed to
       .grade-card in the markup below and this page no longer redefines
       it. Everything below here is genuinely page-specific (step-wizard
       breadcrumb, grade-encoding table/inputs, save button, status
       badges) and stays scoped under .grading-page so it never leaks
       onto other pages the way it used to as an unscoped block. */

    /* !important on border/box-shadow: these cards render as
       class="card section-card border-0 shadow-sm" — Bootstrap's own
       .border-0/.shadow-sm utilities carry !important, so this rule needs
       it too to reliably win (same reason the shared global .grade-card/
       .section-card in style.css use !important). */
    .grading-page .section-card {
        cursor: pointer;
        border: 1px solid var(--border) !important;
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm) !important;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .grading-page .section-card:hover {
        border-color: var(--primary) !important;
        box-shadow: var(--shadow-md) !important;
    }
    .grading-page .section-card.active {
        border-color: var(--primary) !important;
        background: var(--primary-soft);
    }
    .grading-page .section-card .section-name {
        font-weight: 600;
        font-size: 15px;
        color: var(--ink);
    }

    .grading-page .subject-card {
        cursor: pointer;
        border: 1px solid var(--border) !important;
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm) !important;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .grading-page .subject-card:hover {
        border-color: var(--primary) !important;
        box-shadow: var(--shadow-md) !important;
    }
    .grading-page .subject-card.active {
        border-color: var(--primary) !important;
        background: var(--primary-soft);
    }

    .grading-page .grade-input {
        width: 100%;
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: 6px 8px;
        font-size: 14.5px;
        text-align: center;
        transition: border-color 0.2s;
    }
    .grading-page .grade-input:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 2px rgba(91,95,239,0.12);
    }

    .grading-page .breadcrumb-nav {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 10px 16px;
        margin-bottom: 16px;
    }
    .grading-page .breadcrumb-nav .step-breadcrumb {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 6px;
        font-weight: 500;
        font-size: 14.5px;
    }
    .grading-page .breadcrumb-nav .step-breadcrumb.active {
        background: var(--ink);
        color: white;
    }
    .grading-page .breadcrumb-nav .step-breadcrumb.done {
        background: #EAF7F1;
        color: #238863;
    }
    .grading-page .breadcrumb-nav .step-breadcrumb.pending {
        background: var(--bg);
        color: var(--text-muted);
    }
    .grading-page .breadcrumb-nav .breadcrumb-arrow {
        color: var(--border);
        margin: 0 4px;
    }
    .grading-page .breadcrumb-back {
        margin-left: auto;
        border: 1px solid var(--border);
        color: var(--text-muted);
        background: var(--surface);
        text-decoration: none;
    }

    .grading-page .grade-table thead th {
        background: var(--bg);
        font-weight: 600;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        padding: 10px 8px;
        border-bottom: 1px solid var(--border);
        text-align: center;
        color: var(--text-muted);
    }
    .grading-page .grade-table tbody td {
        vertical-align: middle;
        padding: 10px 8px;
        text-align: center;
        border-bottom: 1px solid var(--border-soft);
    }
    .grading-page .grade-table tbody tr:hover {
        background: var(--surface-hover);
    }

    /* The button is `class="btn btn-save"` with no Bootstrap colour
       variant. Bootstrap 5.3's `.btn:hover` sets
           background-color: var(--bs-btn-hover-bg)
       and that custom property is UNDEFINED here, so the background fell
       back to transparent on hover and the white label disappeared against
       the white card. Every interactive state now sets its own background,
       colour and border explicitly, so none of them can inherit an
       undefined Bootstrap variable. Colour switched from navy to the
       shared primary indigo so Save matches every other primary action. */
    .grading-page .btn-save,
    .grading-page .btn.btn-save {
        background-color: var(--primary);
        border: 1px solid var(--primary);
        padding: 10px 28px;
        font-weight: 600;
        border-radius: 8px;
        color: #ffffff;
        opacity: 1;
        transition: background-color 0.15s ease-in-out;
    }
    .grading-page .btn.btn-save:hover,
    .grading-page .btn.btn-save:focus,
    .grading-page .btn.btn-save:focus-visible,
    .grading-page .btn.btn-save:active,
    .grading-page .btn.btn-save:not(:disabled):not(.disabled):active {
        background-color: #4A4EDB;
        border-color: #4A4EDB;
        color: #ffffff;
        opacity: 1;
    }
    .grading-page .btn.btn-save:focus-visible {
        outline: 2px solid #4A4EDB;
        outline-offset: 2px;
        box-shadow: none;
    }
    .grading-page .btn.btn-save:disabled,
    .grading-page .btn.btn-save.disabled {
        background-color: var(--primary);
        border-color: var(--primary);
        color: #ffffff;
        opacity: 0.55;               /* dimmed, never invisible */
    }

    .grading-page .badge-status {
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 13.5px;
        font-weight: 600;
    }
    .grading-page .badge-status.enrolled {
        background: #EAF7F1;
        color: #238863;
    }
    .grading-page .badge-status.passed {
        background: #EAF7F1;
        color: #238863;
    }
    .grading-page .badge-status.failed {
        background: #FDEEEE;
        color: #C94A4A;
    }

    .grading-page .student-name {
        font-weight: 600;
        color: var(--ink);
    }
    .grading-page .student-lrn {
        font-size: 13.5px;
        color: var(--text-muted);
    }

    /* ============================================================
       ENCODE GRADES -- simplified summary table + per-category modals
       The main table now only shows Student / LRN / WW% / PT% / EX% /
       Initial Grade / Term Grade / Remarks, so it stays readable no
       matter how many assessment items a category ends up with. All the
       per-item raw-score inputs live inside a modal per category instead
       (see .grade-cat-card / .grade-item-modal below), opened by clicking
       that category's summary card.
       ============================================================ */
    .grading-page .grade-table-scroll {
        overflow-x: auto;
        scroll-behavior: smooth;
        border: 1px solid var(--border);
        border-radius: 10px;
        /* A visible, comfortable scrollbar -- this table is meant to be
           dragged/scrolled, not hidden behind an invisible one. */
        scrollbar-width: thin;
    }
    .grading-page .grade-table-scroll::-webkit-scrollbar {
        height: 10px;
    }
    .grading-page .grade-table-scroll::-webkit-scrollbar-thumb {
        background: var(--border);
        border-radius: 99px;
    }
    .grading-page .grade-table-scroll::-webkit-scrollbar-thumb:hover {
        background: var(--text-muted);
    }

    .grading-page .grade-table-encode {
        border-collapse: separate;
        border-spacing: 0;
        table-layout: auto;
        width: 100%;
    }
    .grading-page .grade-table-encode thead th {
        padding: 10px 10px;
        font-size: 13.5px;
        white-space: nowrap;
    }
    .grading-page .grade-table-encode td {
        overflow: hidden;
        text-overflow: ellipsis;
        padding: 10px 10px;
        white-space: nowrap;
    }
    .grading-page .grade-table-encode td.student-name-cell {
        white-space: normal;
        text-align: left;
    }
    .grading-page .grade-table-encode .initial-grade,
    .grading-page .grade-table-encode .final-grade,
    .grading-page .grade-table-encode .remarks {
        white-space: normal;
        overflow-wrap: break-word;
    }
    .grading-page .badge-status {
        white-space: nowrap;
    }

    /* ---- View Computation button + modal (Excel-style breakdown) ---- */
    .grading-page .btn-computation {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--text-muted);
        border-radius: 6px;
        padding: 4px 9px;
        font-size: 13.5px;
        font-weight: 600;
        line-height: 1.2;
        cursor: pointer;
        transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
    }
    .grading-page .btn-computation:hover,
    .grading-page .btn-computation:focus-visible {
        background: var(--surface-hover);
        color: var(--ink);
        border-color: var(--text-muted);
    }
    .grading-page .gc-info {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 10px 18px;
        padding: 12px 14px;
        margin-bottom: 4px;
        background: var(--surface-hover);
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 14.5px;
    }
    .grading-page .gc-info label {
        display: block;
        font-size: 12.5px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-muted);
        margin-bottom: 1px;
    }
    .grading-page .gc-info b { color: var(--ink); font-weight: 600; }
    .grading-page .gc-info-wide { grid-column: 1 / -1; }
    .grading-page .gc-info small { display: block; color: var(--text-muted); margin-top: 2px; }
    .grading-page .gc-section {
        --gc-accent: var(--text-muted);
        margin-top: 14px;
        border: 1px solid var(--border);
        border-left: 4px solid var(--gc-accent);
        border-radius: 8px;
        background: var(--surface);
        overflow: hidden;
    }
    .grading-page .gc-ww { --gc-accent: #1F9D6E; }
    .grading-page .gc-pt { --gc-accent: #1F6FEB; }
    .grading-page .gc-qa { --gc-accent: #C77D00; }
    .grading-page .gc-sum { --gc-accent: #14213D; }
    .grading-page .gc-section-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        padding: 9px 12px;
        font-weight: 700;
        font-size: 15px;
        color: var(--ink);
        background: var(--surface-hover);
        border-bottom: 1px solid var(--border);
    }
    .grading-page .gc-weight { font-size: 13.5px; font-weight: 600; color: var(--gc-accent); }
    .grading-page .gc-scroll { overflow-x: auto; }
    .grading-page .gc-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14.5px;
        font-variant-numeric: tabular-nums;
    }
    .grading-page .gc-table th,
    .grading-page .gc-table td {
        padding: 7px 10px;
        text-align: right;
        border-bottom: 1px solid var(--border-soft, var(--border));
        border-right: 1px solid var(--border-soft, var(--border));
        white-space: nowrap;
    }
    .grading-page .gc-table th:last-child,
    .grading-page .gc-table td:last-child { border-right: 0; }
    .grading-page .gc-table thead th {
        background: var(--bg);
        font-size: 13px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        color: var(--text-muted);
        text-align: right;
    }
    .grading-page .gc-table .gc-left { text-align: left; white-space: normal; }
    .grading-page .gc-table .gc-idx { width: 38px; text-align: center; color: var(--text-muted); }
    .grading-page .gc-table tr.gc-band td {
        text-align: left;
        font-weight: 600;
        font-size: 13.5px;
        background: var(--surface-hover);
        color: var(--ink);
    }
    .grading-page .gc-table tr.gc-subtotal td {
        font-weight: 700;
        background: var(--surface-hover);
        color: var(--ink);
        border-top: 1px solid var(--border);
    }
    .grading-page .gc-table tr.gc-total td {
        font-weight: 800;
        background: var(--surface-hover);
        color: var(--ink);
        border-top: 2px solid var(--border);
    }
    .grading-page .gc-table tr.gc-blank td { color: var(--text-muted); }
    .grading-page .gc-tag {
        display: inline-block;
        margin-left: 6px;
        padding: 1px 7px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        color: var(--text-muted);
        border: 1px solid var(--border);
    }
    .grading-page .gc-formula,
    .grading-page .gc-note {
        padding: 9px 12px;
        font-size: 14px;
        color: var(--text-muted);
        line-height: 1.5;
    }
    .grading-page .gc-formula { border-top: 1px solid var(--border-soft, var(--border)); }
    .grading-page .gc-formula b,
    .grading-page .gc-note b { color: var(--ink); }
    .grading-page .gc-warn { color: #B7791F; }
    .grading-page .gc-empty { padding: 14px 12px; font-size: 14.5px; color: var(--text-muted); }
    .grading-page .gc-pad { padding: 4px 0 6px; }
    .grading-page .gc-summary { margin: 0; }
    .grading-page .gc-result {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        padding: 8px 12px 4px;
    }
    .grading-page .gc-res-cell {
        flex: 1 1 150px;
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 10px 14px;
        background: var(--surface);
    }
    .grading-page .gc-res-cell > span:first-child {
        display: block;
        font-size: 12.5px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-muted);
        margin-bottom: 4px;
    }
    .grading-page .gc-res-cell b { font-size: 26px; font-weight: 800; color: var(--ink); line-height: 1.1; }
    .grading-page .gc-res-cell b.gc-good { color: #238863; }
    .grading-page .gc-res-cell b.gc-bad { color: #C94A4A; }
    .grading-page .gc-res-cell small { display: block; margin-top: 4px; color: var(--text-muted); font-size: 13.5px; }
    .grading-page .gc-comp-title {
        margin: 18px 0 -4px;
        font-size: 15px;
        font-weight: 800;
        color: var(--ink);
    }
    .grading-page .gc-legend {
        margin-top: 14px;
        padding: 10px 12px;
        font-size: 13.5px;
        line-height: 1.55;
        color: var(--text-muted);
        background: var(--surface-hover);
        border: 1px dashed var(--border);
        border-radius: 8px;
    }
    .grading-page .gc-legend b { color: var(--ink); }

    /* Category summary cards ("Written Works 20%" etc.) shown above the
       main table. Clicking one opens that category's item-entry modal. */
    .grading-page .grade-cat-cards {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 16px;
    }
    .grading-page .grade-cat-card {
        flex: 1 1 220px;
        min-width: 200px;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: var(--surface);
        padding: 14px 16px;
        text-align: left;
        cursor: pointer;
        transition: box-shadow 0.15s ease, transform 0.1s ease;
    }
    .grading-page .grade-cat-card:hover {
        box-shadow: 0 2px 10px rgba(15, 23, 42, 0.10);
        transform: translateY(-1px);
    }
    .grading-page .grade-cat-card .grade-cat-card-title {
        font-weight: 700;
        font-size: 15px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }
    .grading-page .grade-cat-card .grade-cat-card-sub {
        font-size: 13.5px;
        color: var(--text-muted);
        margin-top: 4px;
    }
    .grading-page .grade-cat-card.grade-cat-ww { border-left: 4px solid #1F9D6E; }
    .grading-page .grade-cat-card.grade-cat-pt { border-left: 4px solid #1F6FEB; }
    .grading-page .grade-cat-card.grade-cat-qa { border-left: 4px solid #C77D00; }
    .grading-page .grade-cat-card .grade-cat-card-title i.fa-chevron-right {
        font-size: 13.5px;
        color: var(--text-muted);
    }

    /* Item-entry tables inside each category modal. */
    .grading-page .grade-item-modal table {
        border-collapse: separate;
        border-spacing: 0;
        width: 100%;
        table-layout: auto;
    }
    .grading-page .grade-item-modal th {
        padding: 8px 6px;
        font-size: 13px;
        line-height: 1.35;
        white-space: normal;
        overflow-wrap: break-word;
        position: sticky;
        top: 0;
        background: var(--bg);
        z-index: 1;
    }
    .grading-page .grade-item-modal td {
        padding: 6px 4px;
        font-size: 14.5px;
    }
    .grading-page .grade-item-modal td.student-name-cell {
        text-align: left;
        white-space: normal;
    }
    .grading-page .grade-item-modal .grade-input {
        width: 100%;
        min-width: 0;
        max-width: 66px;
        padding: 6px 2px;
        font-size: 14.5px;
        text-align: center;
        -moz-appearance: textfield;
        appearance: textfield;
    }
    .grading-page .grade-item-modal .grade-input::-webkit-outer-spin-button,
    .grading-page .grade-item-modal .grade-input::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
    .grading-page .grade-item-modal .grade-subtotal-col {
        font-weight: 600;
    }
    .grading-page .grade-item-modal .grade-subtype-band {
        background: var(--surface-hover) !important;
    }
    /* Highest-possible row: stays in the normal flow under the item names
       (the item-name row is the sticky one) and is visually tied to them. */
    .grading-page .grade-item-modal .grade-hps-row th {
        position: static;
        font-weight: 600;
        color: var(--text-muted);
    }
    .grading-page .grade-item-modal .grade-hps-row .grade-input {
        max-width: 66px;
        font-weight: 600;
    }
    /* Quick "add item / set Highest possible" fields above the score table. */
    .grading-page .grade-item-modal .grade-setup-row .grade-input {
        max-width: none;
        text-align: left;
    }
    .grading-page .grade-item-modal-note {
        font-size: 14px;
        color: var(--text-muted);
        background: var(--surface-hover);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 10px 14px;
        margin-bottom: 12px;
    }

    /* Highest possible is fixed from Manage Assessment Items. */
    .grading-page .hps-locked { display: inline-flex; align-items: center; gap: 5px; font-weight: 700; color: var(--ink); }
    .grading-page .hps-locked::before { content: "\f023"; font-family: "Font Awesome 6 Free", "Font Awesome 5 Free"; font-weight: 900; font-size: 12px; color: var(--text-muted); }
    .grading-page .grade-input.score-over { border-color: #d92d20 !important; background: #fdecec !important; color: #b42318 !important; }
    .grading-page .score-over-msg { display: block; font-size: 12.5px; color: #b42318; font-weight: 600; margin-top: 2px; white-space: nowrap; }

    /* ===== Encode Grades (step 5) layout ===== */

    .grading-page .gm-card { border-radius: 14px !important; border: 1px solid var(--border) !important; }
    .grading-page .gm-head { background: #14213D; color: #fff; padding: 20px 24px 18px; }
    .grading-page .gm-head-top { display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap; }
    .grading-page .gm-head-title { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; min-width: 0; }
    .grading-page .gm-head-ic { width: 52px; height: 52px; border-radius: 12px; background: rgba(255,255,255,.12); display: flex; align-items: center; justify-content: center; font-size: 22px; color: #8ab4f8; flex-shrink: 0; }
    .grading-page .gm-head h2 { color: #fff; font-size: 28px; font-weight: 800; margin: 0; letter-spacing: -.01em; }
    .grading-page .gm-chip { background: rgba(255,255,255,.14); color: #fff; border-radius: 8px; padding: 5px 12px; font-size: 15px; font-weight: 600; }
    .grading-page .gm-sep { width: 1px; height: 22px; background: rgba(255,255,255,.3); }
    .grading-page .gm-count { color: rgba(255,255,255,.9); font-size: 15px; }
    .grading-page .gm-head-actions { display: flex; gap: 10px; flex-wrap: wrap; }
    .grading-page .gm-head-btn { display: inline-flex; align-items: center; gap: 8px; height: 42px; padding: 0 16px; border-radius: 8px; border: 0; background: rgba(255,255,255,.14); color: #fff; font-size: 15px; font-weight: 600; text-decoration: none; }
    .grading-page .gm-head-btn:hover { background: rgba(255,255,255,.22); color: #fff; }
    .grading-page .gm-terms { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
    .grading-page .gm-term { display: inline-flex; align-items: center; gap: 8px; padding: 7px 14px; border-radius: 8px; font-size: 15px; font-weight: 500; text-decoration: none; background: rgba(255,255,255,.1); color: rgba(255,255,255,.8); }
    .grading-page .gm-term.on { background: #fff; color: #14213D; font-weight: 700; }
    .grading-page a.gm-term:hover { background: rgba(255,255,255,.2); color: #fff; }

    .grading-page .gm-card .card-body > .alert { border-radius: 10px !important; font-size: 15px !important; padding: 12px 16px; }
    .grading-page .gm-card .alert-warning { background: #FFF8E6; border-color: #F3DFA2; color: #6B4E00; }
    .grading-page .gm-card .alert-warning > div > .fa-hourglass-half { color: #B54708; }
    .grading-page .gm-card .alert .badge { font-size: 14px; padding: 6px 10px; border-radius: 6px; }
    .grading-page .gm-card details > summary { list-style: none; font-size: 15.5px; }
    .grading-page .gm-card details > summary::-webkit-details-marker { display: none; }
    .grading-page .gm-card details > summary::before { content: "\25B8"; display: inline-block; margin-right: 8px; transition: transform .15s; }
    .grading-page .gm-card details[open] > summary::before { transform: rotate(90deg); }
    .grading-page .gm-card details ul { list-style: none; padding-left: 0 !important; display: grid; gap: 8px; }
    .grading-page .gm-card details li { display: flex; gap: 10px; align-items: flex-start; }
    .grading-page .gm-card details li::before { content: "\f15c"; font-family: "Font Awesome 6 Free", "Font Awesome 5 Free"; font-weight: 400; flex-shrink: 0; width: 30px; height: 30px; border-radius: 50%; background: #FCEBC4; color: #B54708; display: inline-flex; align-items: center; justify-content: center; font-size: 14.5px; }

    .grading-page .gm-card #subjectSelect { height: 44px; border-radius: 8px !important; font-size: 15px !important; }
    .grading-page .gm-card .form-label { font-size: 15px !important; color: var(--ink) !important; font-weight: 600; }
    .grading-page .gm-rule { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; background: var(--surface-hover); border: 1px solid var(--border); border-radius: 10px; padding: 11px 16px; margin-bottom: 14px; font-size: 15px; color: #475467; }
    .grading-page .gm-rule > i { color: #2f7fe0; }
    .grading-page .gm-note { display: flex; gap: 12px; align-items: flex-start; background: #EDF4FB; border: 1px solid #d7e6f8; border-radius: 10px; padding: 12px 16px; margin: 0 0 16px; font-size: 15px; color: #475569; }
    .grading-page .gm-note > i { color: #2f7fe0; margin-top: 3px; }

    .grading-page .gm-card .grade-cat-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; }
    .grading-page .gm-card .grade-cat-card { display: flex; align-items: center; gap: 16px; padding: 16px 18px; border-radius: 12px; border-left-width: 4px; }
    .grading-page .gm-cat-ic { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
    .grading-page .grade-cat-ww .gm-cat-ic { background: #E4F5EC; color: #1F9D6E; }
    .grading-page .grade-cat-pt .gm-cat-ic { background: #E7F0FC; color: #1F6FEB; }
    .grading-page .grade-cat-qa .gm-cat-ic { background: #FDF3E1; color: #C77D00; }
    .grading-page .gm-cat-body { flex: 1; min-width: 0; }
    .grading-page .gm-card .grade-cat-card .grade-cat-card-title { font-size: 15px; color: var(--ink); }
    .grading-page .gm-cat-count { font-weight: 700; font-size: 15px; color: var(--ink); margin-top: 2px; }
    .grading-page .gm-card .grade-cat-card > .fa-chevron-right { color: var(--text-muted); font-size: 14.5px; }

    .grading-page .gm-card .grade-table-scroll { border: 1px solid var(--border); border-radius: 12px; }
    .grading-page .gm-card .grade-table-encode { margin-bottom: 0; }
    .grading-page .gm-card .grade-table-encode tbody td { font-size: 15px; }
    .grading-page .gm-foot { display: flex; justify-content: flex-end; align-items: center; gap: 14px; flex-wrap: wrap; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border); }
    .grading-page .gm-foot-note { margin-right: auto; font-size: 15px; color: var(--text-muted); }
    .grading-page .gm-foot-note i { color: #2f7fe0; margin-right: 6px; }
    .grading-page .gm-foot .btn { height: 44px; padding: 0 22px; border-radius: 8px; font-weight: 600; display: inline-flex; align-items: center; }

    /* --- term strip --- */
    .grading-page .gm-terms { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0; margin: 0; background: var(--surface); border-bottom: 1px solid var(--border); }
    .grading-page .gm-term { display: flex; align-items: center; gap: 10px; padding: 14px 20px; border-radius: 0; background: transparent; color: var(--ink); font-size: 15.5px; font-weight: 400; text-decoration: none; border-right: 1px solid var(--border); border-bottom: 3px solid transparent; min-width: 0; }
    .grading-page .gm-term:last-child { border-right: 0; }
    .grading-page .gm-term b { font-weight: 700; }
    .grading-page .gm-term-txt { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .grading-page .gm-term-ic { font-size: 18px; color: #6b7a90; flex-shrink: 0; }
    .grading-page .gm-term-completed .gm-term-ic { color: #1f9d6b; }
    .grading-page .gm-term.on { background: #F2F7FE; color: var(--ink); font-weight: 400; border-bottom-color: #2f7fe0; }
    .grading-page .gm-term.on .gm-term-ic { color: #2f7fe0; }
    .grading-page .gm-term-tag { flex-shrink: 0; font-size: 14px; font-weight: 600; border-radius: 6px; padding: 3px 9px; background: #EEF1F5; color: #475467; }
    .grading-page .gm-term-completed .gm-term-tag { background: #E3F5EC; color: #16704b; }
    .grading-page .gm-term.on .gm-term-tag { background: #DCEAFB; color: #1d5fbf; }
    .grading-page .gm-term-go { margin-left: auto; color: var(--text-muted); font-size: 13.5px; }
    .grading-page a.gm-term:hover { background: var(--surface-hover); color: var(--ink); }

    /* --- notice + collapsible "What is still needed" --- */
    .grading-page .gm-notice-tail { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }
    .grading-page .gm-notice-badge { background: #C2410C; color: #fff; font-weight: 700; font-size: 14px; border-radius: 6px; padding: 5px 10px; }
    .grading-page .gm-needed-btn { border: 0; border-left: 1px solid rgba(107,78,0,.3); background: none; padding: 2px 0 2px 14px; color: #6B4E00; font-weight: 700; font-size: 15px; display: inline-flex; align-items: center; gap: 8px; }
    .grading-page .gm-needed-btn[aria-expanded="true"] i { transform: rotate(180deg); }
    .grading-page #gmNeeded .alert { margin-top: -6px; }

    /* --- subject + weights card --- */
    .grading-page .gm-subject-card { display: flex; align-items: flex-end; gap: 20px; flex-wrap: wrap; border: 1px solid var(--border); border-radius: 12px; background: var(--surface); padding: 16px 20px; margin-bottom: 14px; }
    .grading-page .gm-subject-pick { width: 320px; max-width: 100%; }
    .grading-page .gm-subject-pick .form-label { font-size: 15px !important; font-weight: 600; color: var(--ink) !important; margin-bottom: 6px; }
    .grading-page .gm-subject-pick .form-select { height: 44px; border-radius: 8px; font-size: 15px; }
    .grading-page .gm-weights { flex: 1; min-width: 280px; padding-left: 20px; border-left: 1px solid var(--border); }
    .grading-page .gm-weights-label { font-size: 15px; font-weight: 600; color: var(--ink); margin-bottom: 6px; }
    .grading-page .gm-weight-chips { display: flex; gap: 8px; flex-wrap: wrap; }
    .grading-page .gm-wchip { display: inline-flex; align-items: center; gap: 8px; height: 44px; border: 1px solid var(--border); border-left-width: 4px; border-radius: 8px; background: var(--surface); padding: 0 12px; font-size: 15px; color: var(--ink); white-space: nowrap; }
    @media (min-width: 992px) { .grading-page .gm-notice { flex-wrap: nowrap !important; } .grading-page .gm-notice > div:first-child { flex: 1; min-width: 0; } }
    .grading-page .gm-wchip:hover { background: var(--surface-hover); }
    .grading-page .gm-wchip span { color: var(--text-muted); font-size: 14.5px; }
    .grading-page .gm-wchip em { font-style: normal; font-weight: 700; color: #1d6fd6; font-size: 14.5px; }
    .grading-page .gm-wchip-ww { border-left-color: #1F9D6E; }
    .grading-page .gm-wchip-pt { border-left-color: #1F6FEB; }
    .grading-page .gm-wchip-qa { border-left-color: #C77D00; }
    .grading-page .gm-rule-btn { border: 0; background: none; color: #1d6fd6; font-size: 15px; display: inline-flex; align-items: center; gap: 8px; height: 44px; padding: 0 0 0 22px; border-left: 1px solid var(--border); }
    .grading-page .gm-rule-btn span { text-decoration: underline; }
    .grading-page .gm-rule-btn[aria-expanded="true"] .fa-chevron-down { transform: rotate(180deg); }

    /* --- footer --- */
    .grading-page .gm-foot-note { display: flex; gap: 12px; align-items: flex-start; }
    .grading-page .gm-foot-note .gm-foot-ic { color: #1f9d6b; font-size: 20px; margin: 1px 0 0; }
    .grading-page .gm-foot-main { font-size: 15px; color: var(--ink); }
    .grading-page .gm-foot-sub { font-size: 14.5px; color: var(--text-muted); margin-top: 2px; }
    .grading-page .gm-foot .btn { gap: 8px; }
    .grading-page .gm-foot .btn-save { min-width: 170px; justify-content: center; }
    .grading-page .gm-foot .ss-save { min-width: 150px; justify-content: center; }

    html[data-theme="dark"] .grading-page .gm-term.on { background: rgba(47,127,224,.12); }
    html[data-theme="dark"] .grading-page .gm-term-tag { background: rgba(255,255,255,.08); color: #c7d2e6; }
    html[data-theme="dark"] .grading-page .gm-needed-btn { color: #f2d49b; }
    html[data-theme="dark"] .grading-page .gm-rule-btn, html[data-theme="dark"] .grading-page .gm-wchip em { color: #8ab4f8; }
    @media (max-width: 900px) { .grading-page .gm-terms { grid-template-columns: 1fr; } .grading-page .gm-term { border-right: 0; } .grading-page .gm-weights, .grading-page .gm-rule-btn { border-left: 0; padding-left: 0; } }
    html[data-theme="dark"] .grading-page .gm-note { background: rgba(47,127,224,.10); border-color: rgba(47,127,224,.25); color: #c7d2e6; }
    html[data-theme="dark"] .grading-page .gm-rule { color: #c7d2e6; }
    html[data-theme="dark"] .grading-page .gm-card .alert-warning { background: rgba(224,138,0,.10); border-color: rgba(224,138,0,.3); color: #f2d49b; }
    html[data-theme="dark"] .grading-page .grade-cat-ww .gm-cat-ic { background: rgba(31,157,110,.18); }
    html[data-theme="dark"] .grading-page .grade-cat-pt .gm-cat-ic { background: rgba(31,111,235,.18); }
    html[data-theme="dark"] .grading-page .grade-cat-qa .gm-cat-ic { background: rgba(199,125,0,.18); }
    html[data-theme="dark"] .grading-page .gm-card details li::before { background: rgba(224,138,0,.18); }
    @media (max-width: 640px) { .grading-page .gm-head h2 { font-size: 22px; } .grading-page .gm-head-ic { width: 42px; height: 42px; font-size: 18px; } }
</style>

<div class="grading-page">

<!-- Success/Error Messages -->
<?php if (isset($_GET['success']) && isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i> <?php echo $_SESSION['success_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i> <?php echo $_SESSION['error_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<?php if ($term_transition_notice !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-flag-checkered me-2"></i> <?php echo htmlspecialchars($term_transition_notice); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0" style="color: var(--ink);font-weight:600;">Grade Management</h4>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- NO SECTIONS ASSIGNED -->
<!-- ============================================================ -->
<?php if (empty($my_sections)): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>No sections assigned!</strong>
        <p class="mb-0">You have not been assigned to any sections yet. Please contact the administrator.</p>
        <hr>
        <p class="mb-0 small text-muted">
            <strong>Debug:</strong> Teacher ID: <?php echo $teacher_id; ?>
        </p>
    </div>
<?php else: ?>

<!-- ============================================================ -->
<!-- BREADCRUMB NAVIGATION -->
<!-- ============================================================ -->
<div class="breadcrumb-nav">
    <?php
    $steps = [
        1 => ['label' => 'Grade Level', 'icon' => 'fa-layer-group'],
        2 => ['label' => 'Sections', 'icon' => 'fa-list'],
        3 => ['label' => 'My Subjects', 'icon' => 'fa-book'],
        4 => ['label' => 'Students', 'icon' => 'fa-users'],
        5 => ['label' => 'Encode Grades', 'icon' => 'fa-edit']
    ];
    
    foreach ($steps as $num => $step_info):
        $is_active = ($step == $num);
        $is_done = ($step > $num);
        $class = $is_active ? 'active' : ($is_done ? 'done' : 'pending');
    ?>
        <?php if ($num > 1): ?>
            <span class="breadcrumb-arrow"><i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
        <span class="step-breadcrumb <?php echo $class; ?>">
            <i class="fas <?php echo $step_info['icon']; ?> me-1"></i>
            <?php echo $step_info['label']; ?>
        </span>
    <?php endforeach; ?>
    <?php
    $back_url = '';
    if ($step === 2) {
        $back_url = '?step=1';
    } elseif ($step === 3 && $section_info) {
        $back_grade = $selected_grade !== '' ? $selected_grade : ($section_info['grade_level'] ?? '');
        $back_url = '?step=2&grade=' . urlencode($back_grade);
    } elseif ($step === 4 && $selected_section > 0) {
        $back_url = '?step=3&grade=' . urlencode($selected_grade) . '&section_id=' . $selected_section;
    } elseif ($step === 5 && $selected_section > 0 && $selected_subject !== '') {
        $back_url = '?step=4&grade=' . urlencode($selected_grade) . '&section_id=' . $selected_section
            . '&subject_code=' . urlencode($selected_subject) . '&term=' . urlencode($term);
    }
    ?>
    <?php if ($back_url !== ''): ?>
        <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-sm breadcrumb-back">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    <?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- STEP 1: GRADE LEVEL CARDS -->
<!-- ============================================================ -->
<?php if ($step == 1): ?>
    <div class="row g-3">
        <?php 
        $grade_levels = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
        foreach ($grade_levels as $grade): 
            $grade_sections = $sections_by_grade[$grade] ?? [];
            $has_sections = count($grade_sections) > 0;
            $color = '';
            $display = '';

            // Same Grade 7-12 accents used everywhere else in the system
            // (Subjects, Students, Teachers, Class Records admin view) —
            // this switch used to reuse the generic success/danger/warning/
            // ink tokens instead, which made Grade 8 and Grade 10 render as
            // the exact same navy color here.
            switch($grade) {
                case 'Grade 7': $color = '#2E7D67'; $display = '7'; break;
                case 'Grade 8': $color = '#356EA8'; $display = '8'; break;
                case 'Grade 9': $color = '#A06A1B'; $display = '9'; break;
                case 'Grade 10': $color = '#5B5EA6'; $display = '10'; break;
                case 'Grade 11': $color = '#994C52'; $display = '11'; break;
                case 'Grade 12': $color = '#287A78'; $display = '12'; break;
            }
            // Matching soft tints for the hover background, same values used
            // on the Student Management and Teacher Management grade cards.
            switch($grade) {
                case 'Grade 7': $soft = '#F1F8F5'; break;
                case 'Grade 8': $soft = '#F2F6FA'; break;
                case 'Grade 9': $soft = '#FBF7EE'; break;
                case 'Grade 10': $soft = '#F4F3FA'; break;
                case 'Grade 11': $soft = '#FAF2F3'; break;
                case 'Grade 12': $soft = '#F0F8F7'; break;
                default: $soft = '#f8f9fb';
            }

            if (!$has_sections) continue;
        ?>
            <div class="col-md-4 col-lg-2">
                <div class="card grade-card border-0 shadow-sm"
                     style="--accent: <?php echo $color; ?>; --accent-soft: <?php echo $soft; ?>;"
                     role="button" tabindex="0"
                     aria-label="Show <?php echo htmlspecialchars($grade); ?>"
                     onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
                     onclick="window.location.href='?step=2&grade=<?php echo urlencode($grade); ?>'">
                    <div class="card-body text-center">
                        <div class="grade-icon">
                            <span class="grade-num"><?php echo $display; ?></span>
                        </div>
                        <div class="grade-label"><?php echo $grade; ?></div>
                        <div class="section-count">
                            <?php echo count($grade_sections) . ' section' . (count($grade_sections) > 1 ? 's' : ''); ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- STEP 2: SECTIONS UNDER SELECTED GRADE -->
<!-- ============================================================ -->
<?php if ($step == 2 && !empty($selected_grade)): 
    $grade_sections = $sections_by_grade[$selected_grade] ?? [];
?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0" style="color: var(--ink);font-weight:600;">
            Sections in <?php echo $selected_grade; ?>
            <span class="badge" style="background: var(--surface-hover);color: var(--text-muted);font-weight:500;font-size:13.5px;margin-left:8px;"><?php echo count($grade_sections); ?></span>
        </h5>
    </div>
    
    <div class="row g-3">
        <?php foreach ($grade_sections as $section): ?>
            <div class="col-md-4 col-lg-3">
                 <div class="card section-card border-0 shadow-sm" role="link" tabindex="0"
                     onclick="window.location.href='?step=3&grade=<?php echo urlencode($selected_grade); ?>&section_id=<?php echo $section['section_id']; ?>'"
                     onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                    <div class="card-body text-center">
                        <div class="section-name">
                            <?php echo htmlspecialchars($section['section_name']); ?>
                        </div>
                        <div style="font-size:14.5px;color: var(--text-muted);margin-top:4px;">
                            <?php echo $section['subject_count'] ?? 0; ?> subjects · 
                            <?php echo $section['student_count'] ?? 0; ?> students
                        </div>
                        <?php $__sb = $section_term_badges[(int)$section['section_id']] ?? null; if ($__sb): ?>
                            <div style="margin-top:6px;">
                                <span class="badge" style="background:<?php echo $__sb['all_completed'] ? '#0F7B4E' : '#14213D'; ?>;font-weight:500;font-size:12.5px;"
                                      title="The class moves to the next term once every subject is finalized and complete.">
                                    <?php echo $__sb['all_completed'] ? 'All terms completed' : htmlspecialchars($__sb['current']) . ' · in progress'; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                                <a href="?step=3&grade=<?php echo urlencode($selected_grade); ?>&section_id=<?php echo $section['section_id']; ?>" 
                                    onclick="event.stopPropagation();"
                           class="btn btn-sm mt-3"
                           style="background:#14213D;color:#fff;border:none;padding:5px 16px;font-size:14.5px;">
                            View Subjects
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- STEP 3: MY SUBJECTS -->
<!-- ============================================================ -->
<?php if ($step == 3 && $selected_section > 0 && $section_info): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0" style="color: var(--ink);font-weight:600;">
            My Subjects in <?php echo htmlspecialchars($section_info['section_name']); ?>
            <span class="badge" style="background: var(--surface-hover);color: var(--text-muted);font-weight:500;font-size:13.5px;margin-left:8px;"><?php echo count($section_subjects); ?></span>
            <span class="badge" style="background:#14213D;color:#fff;font-weight:500;font-size:13.5px;margin-left:4px;"><?php echo htmlspecialchars($term); ?><?php echo $term_readonly ? ' · Completed' : ''; ?></span>
        </h5>
    </div>
    <?php gradingTermTransitionPanel($section_term_state, $term, $term_readonly, $selected_grade, $selected_section); ?>
    
    <?php if (empty($section_subjects)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No subjects assigned to you for this section.
            
            <?php if (!empty($debug_subjects)): ?>
                <hr>
                <p class="mb-0 small text-muted">
                    <strong>Debug Info:</strong><br>
                    Teacher ID: <?php echo $teacher_id; ?><br>
                    Section ID: <?php echo $selected_section; ?><br>
                    Found <?php echo count($debug_subjects); ?> records in teacher_subjects:
                </p>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <th>ts_id</th>
                                <th>teacher_id</th>
                                <th>subject_code</th>
                                <th>section_id</th>
                                <th>school_year_id</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($debug_subjects as $ds): ?>
                                <tr>
                                    <td><?php echo $ds['ts_id']; ?></td>
                                    <td><?php echo $ds['teacher_id']; ?></td>
                                    <td><strong><?php echo $ds['subject_code']; ?></strong></td>
                                    <td><?php echo $ds['section_id']; ?></td>
                                    <td><?php echo $ds['school_year_id']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <p class="mb-0 small text-muted mt-2">Please contact the administrator to assign subjects.</p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($section_subjects as $subject): ?>
                <div class="col-md-4 col-lg-3">
                    <div class="card subject-card border-0 shadow-sm">
                        <div class="card-body text-center">
                            <h6 class="mb-1" style="color: var(--ink);font-weight:600;"><?php echo htmlspecialchars($subject['subject_name']); ?></h6>
                            <small style="color: var(--text-muted);"><?php echo htmlspecialchars($subject['subject_code']); ?></small>
                            <div class="mt-2">
                                <span style="background: var(--surface-hover);color: var(--text-muted);padding:3px 8px;border-radius:4px;font-size:12.5px;font-weight:500;"><?php echo htmlspecialchars($subject['subject_type'] ?? 'General'); ?></span>
                            </div>
                            <div class="d-flex gap-2 justify-content-center mt-3">
                                <a href="?step=4&grade=<?php echo urlencode($selected_grade); ?>&section_id=<?php echo $selected_section; ?>&subject_code=<?php echo urlencode($subject['subject_code']); ?>&term=<?php echo urlencode($term); ?>"
                                   class="btn btn-sm"
                                   style="background:#14213D;color:#fff;border:none;padding:5px 16px;font-size:14.5px;">
                                    View Students
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- ============================================================ -->
<!-- STEP 4: LIST OF STUDENTS -->
<!-- ============================================================ -->
<?php if ($step == 4 && $selected_section > 0 && !empty($selected_subject)): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0" style="color: var(--ink);font-weight:600;">
            Students in <?php echo htmlspecialchars($section_info['section_name'] ?? ''); ?>
            <span class="badge" style="background: var(--surface-hover);color: var(--text-muted);font-weight:500;font-size:13.5px;margin-left:8px;"><?php echo count($section_students); ?></span>
        </h5>
        <div class="d-flex gap-2">
            <a href="?step=5&section_id=<?php echo $selected_section; ?>&subject_code=<?php echo urlencode($selected_subject); ?>&term=<?php echo urlencode($term); ?>&grade=<?php echo urlencode($selected_grade); ?>&mode=all" 
               class="btn btn-sm"
               style="background:#14213D;color:#fff;border:none;">
                <i class="fas fa-users me-1"></i> <?php echo $term_readonly ? 'View All Students' : 'Encode All Students'; ?>
            </a>
        </div>
    </div>
    <?php if ($term_readonly) { gradingTermTransitionPanel($section_term_state, $term, $term_readonly, $selected_grade, $selected_section); } ?>
    
    <?php if (empty($section_students)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            No students enrolled in this section yet.
        </div>
    <?php else: ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead style="background: var(--surface-hover);">
                            <tr>
                                <th style="font-size:13.5px;text-transform:uppercase;letter-spacing:0.4px;color: var(--text-muted);border-bottom: 1px solid var(--border);">#</th>
                                <th style="font-size:13.5px;text-transform:uppercase;letter-spacing:0.4px;color: var(--text-muted);border-bottom: 1px solid var(--border);">LRN</th>
                                <th style="font-size:13.5px;text-transform:uppercase;letter-spacing:0.4px;color: var(--text-muted);border-bottom: 1px solid var(--border);">Student Name</th>
                                <th style="font-size:13.5px;text-transform:uppercase;letter-spacing:0.4px;color: var(--text-muted);border-bottom: 1px solid var(--border);">Status</th>
                                <th style="font-size:13.5px;text-transform:uppercase;letter-spacing:0.4px;color: var(--text-muted);border-bottom: 1px solid var(--border);text-align:right;">Term</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; ?>
                            <?php foreach ($section_students as $student): ?>
                                <?php
                                    // The learner is passed by student_id, never by name —
                                    // two learners can share a name (as they do in 10-C).
                                    $encode_one_url = '?step=5'
                                        . '&section_id=' . $selected_section
                                        . '&subject_code=' . urlencode($selected_subject)
                                        . '&term=' . urlencode($term)
                                        . '&grade=' . urlencode($selected_grade)
                                        . '&student_id=' . (int)$student['student_id']
                                        . '&mode=single';
                                ?>
                                <tr style="cursor:pointer;" onclick="window.location='<?php echo $encode_one_url; ?>'">
                                    <td style="border-bottom: 1px solid var(--border-soft);"><?php echo $counter++; ?></td>
                                    <td style="border-bottom: 1px solid var(--border-soft);"><strong><?php echo attQrLrnLink($student['lrn']); ?></strong></td>
                                    <td style="border-bottom: 1px solid var(--border-soft);"><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></td>
                                    <td style="border-bottom: 1px solid var(--border-soft);"><span class="badge-status enrolled">Enrolled</span></td>
                                    <td style="border-bottom: 1px solid var(--border-soft);text-align:right;">
                                        <span class="badge-status" title="Selected grading term">
                                            <i class="fas fa-calendar-alt me-1"></i><?php echo htmlspecialchars($term); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
<?php endif; ?>

<?php if ($subject_error !== ''): ?>
    <div class="alert alert-danger" style="border-radius:8px;font-size:14.5px;">
        <i class="fas fa-ban me-1"></i><?php echo htmlspecialchars($subject_error); ?>
    </div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- STEP 5: ENCODE GRADES -->
<!-- ============================================================ -->
<?php if ($step == 5 && $selected_section > 0 && !empty($selected_subject)): ?>
    <div class="card border-0 shadow-sm gm-card" style="overflow:hidden;">
        <!-- Header -->
        <?php
            $subject_name = '';
            foreach ($section_subjects as $subj) {
                if ($subj['subject_code'] == $selected_subject) {
                    $subject_name = $subj['subject_name'];
                    break;
                }
            }
            // "Grade 7-C" when the section name already starts with the grade number.
            $gm_class_chip = 'Section';
            if ($section_info) {
                $gm_grade_no = preg_replace('/\D+/', '', (string) $section_info['grade_level']);
                $gm_class_chip = ($gm_grade_no !== '' && strpos((string) $section_info['section_name'], $gm_grade_no) === 0)
                    ? 'Grade ' . $section_info['section_name']
                    : $section_info['grade_level'] . ' - ' . $section_info['section_name'];
            }
        ?>
        <div class="gm-head">
            <div class="gm-head-top">
                <div class="gm-head-title">
                    <div class="gm-head-ic"><i class="fas fa-book-open"></i></div>
                    <h2><?php echo htmlspecialchars($subject_name !== '' ? $subject_name : $selected_subject); ?></h2>
                    <span class="gm-chip"><?php echo htmlspecialchars($gm_class_chip); ?></span>
                    <span class="gm-sep"></span>
                    <span class="gm-count"><?php
                        if ($encode_mode === 'single' && $single_student !== null) {
                            echo htmlspecialchars($single_student['last_name'] . ', ' . $single_student['first_name'])
                               . ' <span style="opacity:.7;">(LRN ' . htmlspecialchars($single_student['lrn']) . ')</span>';
                        } else {
                            $gm_n = count($section_students);
                            echo $gm_n . ' student' . ($gm_n === 1 ? '' : 's');
                        }
                    ?></span>
                </div>
                <div class="gm-head-actions">
                    <button type="button" id="termAttBtn" class="gm-head-btn" data-bs-toggle="modal" data-bs-target="#termAttModal"
                       title="See this class's attendance for the whole term and download it as Excel">
                        <i class="fas fa-users"></i>Attendance
                    </button>
                    <a class="gm-head-btn" href="items.php?section_id=<?php echo $selected_section; ?>&subject_code=<?php echo urlencode($selected_subject); ?>&term=<?php echo urlencode($term); ?>">
                        <i class="fas fa-gear"></i>Manage Items
                    </a>
                </div>
            </div>
        </div>
        <!-- Term Calendar: the dates saved in Teacher Management > Term Dates -->
        <div class="gm-terms" title="Term dates set by the administrator">
                <?php foreach ($term_calendar as $tc_name => $tc):
                    $tc_on = ($tc_name === $term);
                    $tc_state = $section_term_state['statuses'][$tc_name] ?? null;   // completed | current | locked
                    $tc_icon = $tc_state === 'completed' ? 'fa-circle-check' : ($tc_state === 'locked' ? 'fa-lock' : 'fa-calendar-alt');
                    $tc_tag  = $tc_state === 'completed' ? 'Completed' : ($tc_state === 'locked' ? 'Locked' : ($tc_state === 'current' ? 'Current' : ''));
                    $tc_tag_tip = $tc_state === 'locked' ? 'Opens automatically once the previous term is Completed for this class' : '';
                    // Completed (read-only) and Current terms open right here, same subject.
                    $tc_can_open = (($tc_state === 'completed' || $tc_state === 'current') && !$tc_on);
                    $tc_href = '?step=5&section_id=' . $selected_section . '&subject_code=' . urlencode($selected_subject)
                             . '&term=' . urlencode($tc_name) . ($selected_grade !== '' ? '&grade=' . urlencode($selected_grade) : '');
                ?>
                    <<?php echo $tc_can_open ? 'a href="' . htmlspecialchars($tc_href) . '" title="Open ' . htmlspecialchars($tc_name) . ($tc_state === 'completed' ? ' (read-only)' : '') . '"' : 'span' . ($tc_tag_tip !== '' ? ' title="' . htmlspecialchars($tc_tag_tip) . '"' : ''); ?> class="gm-term gm-term-<?php echo htmlspecialchars((string) $tc_state); ?><?php echo $tc_on ? ' on' : ''; ?>">
                        <i class="fas <?php echo $tc_icon; ?> gm-term-ic"></i>
                        <span class="gm-term-txt"><b><?php echo htmlspecialchars($tc_name); ?>:</b> <?php echo htmlspecialchars($tc['range']); ?></span>
                        <?php if ($tc_tag !== ''): ?><span class="gm-term-tag"><?php echo $tc_tag; ?></span><?php endif; ?>
                        <?php if ($tc_can_open): ?><i class="fas fa-chevron-right gm-term-go"></i><?php endif; ?>
                    </<?php echo $tc_can_open ? 'a' : 'span'; ?>>
                <?php endforeach; ?>
        </div>
        
        <!-- Whole-term attendance of this class (button in the header above) -->
        <div class="modal fade" id="termAttModal" tabindex="-1" aria-labelledby="termAttTitle" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
                <div class="modal-content" style="border-radius:14px;overflow:hidden;">
                    <div class="modal-header" style="background:#14213D;color:#fff;">
                        <div>
                            <h5 class="modal-title mb-0" id="termAttTitle" style="color:#fff;"><i class="fas fa-clipboard-user me-2"></i>Term Attendance</h5>
                            <small style="opacity:.8;">
                                <?php echo isset($section_info) && $section_info ? htmlspecialchars($section_info['grade_level'] . ' - ' . $section_info['section_name']) : ''; ?>
                                &middot; <?php echo htmlspecialchars($subject_name ?? ''); ?>
                            </small>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <label for="termAttTerm" class="mb-0 fw-semibold" style="font-size:14.5px;">Term</label>
                            <select id="termAttTerm" class="form-select form-select-sm" style="width:auto;">
                                <?php foreach (gh_threeTerms() as $t_opt): ?>
                                    <option value="<?php echo htmlspecialchars($t_opt); ?>" <?php echo $t_opt === $term ? 'selected' : ''; ?>><?php echo htmlspecialchars($t_opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span id="termAttPeriod" class="text-muted" style="font-size:14.5px;"></span>
                            <a id="termAttExcel" href="#" class="btn btn-success btn-sm ms-auto">
                                <i class="fas fa-file-excel me-1"></i>Download Excel
                            </a>
                        </div>
                        <div id="termAttNote" class="alert alert-warning py-2 mb-2" style="font-size:14px;display:none;"></div>
                        <div id="termAttTotals" class="d-flex flex-wrap gap-2 mb-2" style="font-size:14px;"></div>
                        <div id="termAttBody" class="text-muted py-4 text-center">Loading...</div>
                        <div class="text-muted mt-2" style="font-size:13px;">
                            <strong>P</strong> Present &middot; <strong>L</strong> Late &middot; <strong>A</strong> Absent &middot; <strong>E</strong> Excused &middot; <strong>&ndash;</strong> no record.
                            Attendance % counts a Late as half a day (Present 1, Late 0.5, Absent 0); Excused days are not counted.
                            Hover a letter to see the time in.
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <style>
            #termAttBody .ta-wrap { overflow:auto; max-height:56vh; border:1px solid #dfe5ee; border-radius:8px; }
            #termAttBody table { border-collapse:separate; border-spacing:0; font-size:14px; width:max-content; min-width:100%; }
            #termAttBody th, #termAttBody td { padding:5px 8px; border-bottom:1px solid #e8edf4; border-right:1px solid #eef1f6; white-space:nowrap; text-align:center; background:#fff; }
            #termAttBody thead th { position:sticky; top:0; z-index:3; background:#14213D; color:#fff; font-weight:600; }
            #termAttBody .ta-name { position:sticky; left:0; z-index:2; text-align:left; min-width:190px; font-weight:600; }
            #termAttBody thead .ta-name { z-index:4; }
            #termAttBody .ta-lrn { font-family:ui-monospace,Consolas,monospace; font-size:13px; color:#5b6b7f; }
            #termAttBody .ta-c { display:inline-block; min-width:22px; padding:1px 0; border-radius:5px; font-weight:700; }
            #termAttBody .ta-P { background:#ddf3e7; color:#0f7b4e; }
            #termAttBody .ta-L { background:#fff0cc; color:#9a6400; }
            #termAttBody .ta-A { background:#fbdde0; color:#c0303f; }
            #termAttBody .ta-E { background:#dceafb; color:#1f5fae; }
            #termAttBody .ta-none { color:#b4bfcc; }
            #termAttBody .ta-risk { color:#c0303f; font-weight:700; }
            #termAttBody .ta-chip { display:inline-block; padding:2px 9px; border-radius:99px; background:#f2f4f7; color:#3a4b63; }
            html[data-theme="dark"] #termAttBody th, html[data-theme="dark"] #termAttBody td { background:var(--surface, #1b2433); color:var(--ink, #e6ebf2); border-color:rgba(255,255,255,.08); }
            html[data-theme="dark"] #termAttBody thead th { background:#0b1424; color:#fff; }
            html[data-theme="dark"] #termAttBody .ta-chip { background:rgba(255,255,255,.08); color:inherit; }
        </style>
        <script>
        (function () {
            var modalEl = document.getElementById('termAttModal');
            if (!modalEl) return;
            document.body.appendChild(modalEl);   // keep it out of the card (overflow / stacking)
            var CFG = {
                url: 'attendance_term.php',
                subject: <?php echo json_encode((string) $selected_subject, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                section: <?php echo (int) $selected_section; ?>
            };
            var sel = document.getElementById('termAttTerm'), body = document.getElementById('termAttBody'),
                period = document.getElementById('termAttPeriod'), note = document.getElementById('termAttNote'),
                totals = document.getElementById('termAttTotals'), excel = document.getElementById('termAttExcel');
            var reqId = 0;

            function qs(fmt) {
                return CFG.url + '?format=' + fmt + '&subject_code=' + encodeURIComponent(CFG.subject)
                    + '&section_id=' + CFG.section + '&term=' + encodeURIComponent(sel.value);
            }
            function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; }
            function fmtDate(iso, withYear) {
                var p = iso.split('-'), m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][+p[1] - 1];
                return m + ' ' + (+p[2]) + (withYear ? ', ' + p[0] : '');
            }
            function viaText(v) { return v === 'qr' ? 'QR scan' : v === 'lrn' ? 'typed LRN' : v === 'session_close' ? 'session ended, no scan' : 'manual'; }

            function render(rep) {
                excel.href = qs('xlsx');
                period.textContent = fmtDate(rep.start, true) + ' to ' + fmtDate(rep.end, true);
                note.style.display = rep.note ? '' : 'none';
                note.textContent = rep.note || '';
                var t = rep.totals;
                totals.innerHTML = '<span class="ta-chip">Students: <strong>' + rep.students.length + '</strong></span>'
                    + '<span class="ta-chip">School days recorded: <strong>' + rep.dates.length + '</strong></span>'
                    + '<span class="ta-chip">Present: <strong>' + t.Present + '</strong></span>'
                    + '<span class="ta-chip">Late: <strong>' + t.Late + '</strong></span>'
                    + '<span class="ta-chip">Absent: <strong>' + t.Absent + '</strong></span>'
                    + '<span class="ta-chip">Excused: <strong>' + t.Excused + '</strong></span>'
                    + '<span class="ta-chip">At risk: <strong class="' + (t.AtRisk ? 'ta-risk' : '') + '">' + t.AtRisk + '</strong></span>';
                if (!rep.students.length) { body.innerHTML = '<div class="py-4 text-center text-muted">No students in this class.</div>'; return; }
                if (!rep.dates.length) { body.innerHTML = '<div class="py-4 text-center text-muted">No attendance has been recorded for this class in ' + esc(rep.term) + ' yet.</div>'; return; }
                var h = '<div class="ta-wrap"><table><thead><tr><th class="ta-name">Student</th><th>LRN</th><th>Present</th><th>Late</th><th>Absent</th><th>Excused</th><th>Attendance %</th><th>Status</th>';
                rep.dates.forEach(function (d) { h += '<th>' + fmtDate(d) + '</th>'; });
                h += '</tr></thead><tbody>';
                rep.students.forEach(function (s) {
                    h += '<tr><td class="ta-name">' + esc(s.name) + '</td><td class="ta-lrn">' + esc(s.lrn) + '</td>'
                        + '<td>' + s.present + '</td><td>' + s.late + '</td><td>' + s.absent + '</td><td>' + s.excused + '</td>'
                        + '<td><strong class="' + (s.at_risk ? 'ta-risk' : '') + '">' + (s.pct === null ? '&ndash;' : s.pct + '%') + '</strong></td>'
                        + '<td>' + (s.at_risk ? '<span class="ta-risk" title="' + esc(s.reason) + '">At risk</span>' : (s.pct === null ? '&ndash;' : 'OK')) + '</td>';
                    rep.dates.forEach(function (d) {
                        var c = s.cells[d];
                        h += c ? '<td><span class="ta-c ta-' + esc(c.s) + '" title="' + esc(c.status + (c.t ? ' - ' + c.t : '') + ' (' + viaText(c.v) + ')') + '">' + esc(c.s) + '</span></td>'
                               : '<td class="ta-none">&ndash;</td>';
                    });
                    h += '</tr>';
                });
                body.innerHTML = h + '</tbody></table></div>';
            }

            function load() {
                var id = ++reqId;
                body.innerHTML = 'Loading...'; body.className = 'text-muted py-4 text-center';
                excel.href = qs('xlsx');
                fetch(qs('json'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (id !== reqId) return;
                        body.className = '';
                        if (!j.success) { body.className = 'text-danger py-4 text-center'; body.textContent = j.error || 'Could not load the attendance.'; return; }
                        render(j.report);
                    })
                    .catch(function () { if (id !== reqId) return; body.className = 'text-danger py-4 text-center'; body.textContent = 'Could not load the attendance. Please try again.'; });
            }
            modalEl.addEventListener('show.bs.modal', load);
            sel.addEventListener('change', load);
        })();
        </script>

        <div class="card-body p-4">
            <?php
                $gm_prog = $section_term_state['progress'] ?? null;
                $gm_prog_total = $gm_prog ? (int) $gm_prog['subject_count'] : 0;
                $gm_prog_ok = 0;
                foreach (($gm_prog['subjects'] ?? []) as $__sj) { if ($__sj['problem'] === '') $gm_prog_ok++; }
                $gm_show_needed = !$term_readonly && $encode_mode === 'all' && $gm_prog;
                // Right side of the notice: progress badge + the collapsible panel toggle.
                $gm_notice_tail = function () use ($gm_show_needed, $gm_prog_ok, $gm_prog_total) {
                    if (!$gm_show_needed) return;
                    echo '<div class="gm-notice-tail"><span class="gm-notice-badge" title="Complete = finalized AND every learner has a Passed/Failed grade">'
                       . $gm_prog_ok . ' of ' . $gm_prog_total . ' subject' . ($gm_prog_total === 1 ? '' : 's') . ' complete</span>'
                       . '<button type="button" class="gm-needed-btn" aria-expanded="false" aria-controls="gmNeeded" onclick="var p=document.getElementById(\'gmNeeded\');p.hidden=!p.hidden;this.setAttribute(\'aria-expanded\',p.hidden?\'false\':\'true\');">'
                       . '<i class="fas fa-chevron-down"></i>What is still needed</button></div>';
                };
            ?>
            <?php if ($term_readonly): ?>
                <?php /* Completed term: the term tab above already says so (and links back to the current term). */ ?>
            <?php elseif ($is_finalized): ?>
                <div class="alert alert-success d-flex justify-content-between align-items-center flex-wrap gap-2" style="border-radius:8px;font-size:14.5px;">
                    <div>
                        <i class="fas fa-check-circle me-1"></i>
                        <strong><?php echo htmlspecialchars($term); ?> finalized</strong>
                        on <?php echo date('M j, Y g:i A', strtotime($finalization['finalized_at'])); ?>.
                        Editing scores below and clicking "Re-finalize <?php echo htmlspecialchars($term); ?>" will recompute these grades.
                    </div>
                    <?php $gm_notice_tail(); ?>
                </div>
            <?php elseif ($no_active_term && $encode_mode === 'all'): ?>
                <div class="alert d-flex justify-content-between align-items-center flex-wrap gap-2" style="border-radius:8px;font-size:14.5px;background:#FDECEA;color:#B3261E;border:1px solid #F5C2BE;">
                    <div>
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        <strong>No term is active today.</strong>
                        You can still save scores and finalize this term. Set the term dates when you want calendar status tracking.
                    </div>
                    <?php $gm_notice_tail(); ?>
                </div>
            <?php elseif ($encode_mode === 'all'): ?>
                <div class="alert alert-warning gm-notice d-flex justify-content-between align-items-center flex-wrap gap-2" style="border-radius:8px;font-size:14.5px;">
                    <div>
                        <i class="fas fa-hourglass-half me-1"></i>
                        <strong><?php echo htmlspecialchars($term); ?> is in progress.</strong>
                        Scores are saved as you go. Initial Grade / <?php echo htmlspecialchars(gh_periodGradeLabel($active_sy)); ?> /
                        Remarks are only computed once you click "Finalize <?php echo htmlspecialchars($term); ?>" below.
                        <?php if ($window_status['status'] !== 'not_set'): ?>
                            <span class="badge ms-1" style="background:<?php echo $window_status['status'] === 'ended' ? '#B54708' : '#0F7B4E'; ?>;">
                                <?php echo htmlspecialchars($window_status['label']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php $gm_notice_tail(); ?>
                </div>
            <?php endif; ?>
            <?php if (!$term_readonly && (!$term_window || empty($term_window['end_date']))): ?>
                <div class="alert d-flex align-items-start gap-2" style="border-radius:10px;font-size:15px;background:#FEF3E6;color:#8A4B00;border:1px solid #F5D3A6;">
                    <i class="fas fa-calendar-xmark mt-1"></i>
                    <div><strong><?php echo htmlspecialchars($term); ?> has no End Date yet.</strong>
                        The class moves to the next term only after the End Date set by the administrator, so ask them to set it in Teacher Management &rsaquo; Term Dates.
                        You can still encode and finalize.</div>
                </div>
            <?php endif; ?>
            <?php if (!$term_readonly && $encode_mode === 'all'): ?>
                <div id="gmNeeded" <?php echo $gm_show_needed ? 'hidden' : ''; ?>><?php gradingTermTransitionPanel($section_term_state, $term, $term_readonly, $selected_grade, $selected_section); ?></div>
            <?php endif; ?>
            <!-- Subject Selector (+ the subject's grading weights once they are known).
                 The term is detected automatically and shown in the term strip above. -->
            <?php
                $gm_subject_select = function ($with_weights) use ($section_subjects, $selected_subject, $term_readonly, &$ww_pct, &$pt_pct, &$qa_pct, &$weights, &$exam_label, &$assessment_items) {
            ?>
                <div class="gm-subject-card">
                    <div class="gm-subject-pick">
                        <label class="form-label" for="subjectSelect">Subject</label>
                        <select class="form-select" id="subjectSelect" onchange="changeSubject()">
                            <?php foreach ($section_subjects as $subj): ?>
                                <option value="<?php echo $subj['subject_code']; ?>"
                                        <?php echo ($selected_subject == $subj['subject_code']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subj['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($with_weights): ?>
                        <div class="gm-weights">
                            <div class="gm-weights-label">Grading weights for this subject</div>
                            <div class="gm-weight-chips">
                                <?php foreach (['WW' => ['ww', 'Written/Oral Works', $ww_pct], 'PT' => ['pt', 'Performance Tasks', $pt_pct], 'QA' => ['qa', $exam_label, $qa_pct]] as $ck => $cm):
                                    if ($ck === 'QA' && ($weights['exam_mode'] ?? '') === 'none') continue;
                                    $n_items = count($assessment_items[$ck] ?? []);
                                ?>
                                    <button type="button" class="gm-wchip gm-wchip-<?php echo $cm[0]; ?>" data-bs-toggle="modal" data-bs-target="#<?php echo $cm[0]; ?>ItemsModal"
                                            title="<?php echo $n_items ? 'Details of ' . htmlspecialchars($cm[1]) : 'Set up ' . htmlspecialchars($cm[1]) . ' items'; ?>">
                                        <b><?php echo htmlspecialchars($cm[1]); ?> <?php echo (int) $cm[2]; ?>%</b>
                                        <span><?php echo $n_items . ' item' . ($n_items === 1 ? '' : 's'); ?></span>
                                        <?php if (!$n_items && !$term_readonly): ?><em>Set up</em><?php endif; ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <button type="button" class="gm-rule-btn" aria-controls="gmRule" aria-expanded="false"
                                onclick="var r=document.getElementById('gmRule');r.hidden=!r.hidden;this.setAttribute('aria-expanded',r.hidden?'false':'true');">
                            <i class="fas fa-circle-info"></i><span>View full grading rule</span><i class="fas fa-chevron-down"></i>
                        </button>
                    <?php endif; ?>
                </div>
            <?php
                };
            ?>

            <!-- A learner requested by URL who is not in this section -->
            <?php if ($single_error !== ''): ?>
                <div class="p-3">
                    <div class="alert alert-danger mb-0" style="border-radius:8px;font-size:14.5px;">
                        <?php echo htmlspecialchars($single_error); ?>
                        <div class="mt-2">
                            <a href="?step=4&section_id=<?php echo $selected_section; ?>&subject_code=<?php echo urlencode($selected_subject); ?>&term=<?php echo urlencode($term); ?>&grade=<?php echo urlencode($selected_grade); ?>"
                               class="btn btn-sm" style="background:#14213D;color:#fff;border:none;">
                                Back to Students
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Grade Encoding Table -->
            <?php if (empty($section_students)): ?>
                <?php $gm_subject_select(false); ?>
                <?php if ($single_error === ''): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-users fa-3x d-block mb-3"></i>
                    <h5>No students enrolled</h5>
                    <p>Students will appear here after enrollment approval.</p>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <?php
                    $subject_row = null;
                    foreach ($section_subjects as $subj) {
                        if ($subj['subject_code'] == $selected_subject) {
                            $subject_row = $subj;
                            break;
                        }
                    }

                    // Weights come from the central helper. For a section the
                    // whole class shares a grade level, so the section's grade
                    // level drives the SHS curriculum branch.
                    $section_grade_level = $section_info['grade_level'] ?? null;
                    $section_curriculum  = getLearnerCurriculumType(null, $section_grade_level, $selected_section);
                    $weights = getSubjectWeights($subject_row ?: $selected_subject, $section_grade_level, $active_sy, $section_curriculum);
                    $weights['uses_domains'] = false;

                    $ww_pct = $weights['ww'] === null ? 0 : (int)round($weights['ww'] * 100);
                    $pt_pct = $weights['pt'] === null ? 0 : (int)round($weights['pt'] * 100);
                    $qa_pct = $weights['ex'] === null ? 0 : (int)round($weights['ex'] * 100);

                    // "Examinations" for a Three-Term SY; the storage key stays QA.
                    $exam_label   = gh_examComponentLabel($active_sy);
                    $period_label = gh_periodGradeLabel($active_sy);

                    $has_any_items = !empty($assessment_items['WW']) || !empty($assessment_items['PT']) || !empty($assessment_items['QA']);
                    $gh_notices    = gh_getConfigNotices();

                    // The teacher sets each item's Highest possible right inside the
                    // encoding modals. That is a whole-class action, so it is offered
                    // only in the whole-class view, and only where the grading rule is
                    // known. Multi-component learning areas (MAPEH) keep using Manage
                    // Items, because every item there must be tagged to a component.
                    $view_is_multi        = count(gh_subjectComponents($subject_row ?: $selected_subject, $weights['category'] ?? null)) > 1;
                    $view_can_edit_setup  = ($encode_mode === 'all') && empty($weights['blocked']);
                    $view_can_add         = $view_can_edit_setup && !$view_is_multi;
                    $view_required_subtypes = ($weights['exam_mode'] === 'standard') ? ['ST1', 'ST2', 'TE']
                                            : (($weights['exam_mode'] === 'term_exam_only') ? ['TE'] : []);
                    $__fmt = function ($n) { return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'); };
                ?>

                <?php if (!empty($gh_notices)): ?>
                    <div class="alert alert-warning" style="border-radius:8px;font-size:14.5px;">
                        <strong>Configuration required.</strong>
                        <ul style="margin:8px 0 0 0;padding-left:18px;">
                            <?php foreach ($gh_notices as $n): ?>
                                <li><?php echo htmlspecialchars($n); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($weights['blocked'])): ?>
                    <?php $gm_subject_select(false); ?>
                    <div class="alert alert-danger" style="border-radius:8px;font-size:14.5px;">
                        Grades cannot be computed for this subject until the configuration above is completed.
                        No grading rule is being guessed.
                    </div>
                <?php else: ?>
                    <?php $gm_subject_select(true); ?>
                    <div class="gm-rule" id="gmRule" hidden>
                        <i class="fas fa-circle-info"></i><strong>Grading rule:</strong> <?php echo htmlspecialchars($weights['category_label']); ?>
                        &nbsp;|&nbsp; WW <?php echo $ww_pct; ?>% &middot; PT <?php echo $pt_pct; ?>% &middot;
                        <?php echo htmlspecialchars($exam_label); ?> <?php echo $qa_pct; ?>%
                        <?php if ($weights['exam_mode'] === 'term_exam_only'): ?>
                            &nbsp;|&nbsp; Examinations = Term Examination only (no ST1 / ST2)
                        <?php elseif ($weights['exam_mode'] === 'standard'): ?>
                            &nbsp;|&nbsp; Examinations = ST1 30% + ST2 30% + TE 40%
                        <?php elseif ($weights['exam_mode'] === 'none'): ?>
                            &nbsp;|&nbsp; No Examinations component
                        <?php endif; ?>
                        <?php if (!empty($weights['rule_source'])): ?>
                            &nbsp;|&nbsp; <em><?php echo htmlspecialchars($weights['rule_source']); ?></em>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if (!$has_any_items): ?>
                    <div class="gm-note">
                        <i class="fas fa-circle-info"></i>
                        <div>
                            No assessment items set for this subject yet. The manual percentages below are only used if they are still filled in
                            at the moment you click "Finalize <?php echo htmlspecialchars($term); ?>" -- they are not saved separately. Set up items in
                            Manage Assessment Items for automatic computation from raw scores instead.
                        </div>
                    </div>
                <?php endif; ?>
                <form method="POST" action="" id="gradeForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <?php
                        // Category metadata shared by the summary cards, the modals, and
                        // (for the "no items yet" case) the manual-percentage fallback.
                        // 'QA' stays the storage key; its display label follows the school year.
                        $__cat_meta = [
                            'WW' => ['short' => 'ww', 'label' => 'Written/Oral Works', 'field' => 'written_work'],
                            'PT' => ['short' => 'pt', 'label' => 'Performance Tasks', 'field' => 'performance_task'],
                            'QA' => ['short' => 'qa', 'label' => $exam_label, 'field' => 'examinations'],
                        ];
                        $__exam_subtypes = gh_examSubtypes();
                    ?>

                    <!-- Class Score Sheet: the main score entry (cards + summary table replaced) -->
                    <?php include __DIR__ . '/score_sheet_embed.php'; ?>

                    <!-- Per-category item-entry modals. Nested inside #gradeForm so every
                        input submits with the same save/finalize POST. -->
                    <?php foreach ($__cat_meta as $cat_key => $meta):
                        if ($cat_key === 'QA' && $weights['exam_mode'] === 'none') continue;
                        $cat_items = $assessment_items[$cat_key];
                        $cat_pct = ${$meta['short'] . '_pct'};
                        $__cat_tagged = false;
                        if ($cat_key === 'QA') {
                            foreach ($cat_items as $__i) { if (!empty($__i['assessment_subtype'])) { $__cat_tagged = true; break; } }
                        }
                    ?>
                        <div class="modal fade" id="<?php echo $meta['short']; ?>ItemsModal" tabindex="-1" aria-labelledby="<?php echo $meta['short']; ?>ItemsModalLabel" aria-hidden="true">
                            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="<?php echo $meta['short']; ?>ItemsModalLabel">
                                            <?php echo htmlspecialchars($meta['label']); ?> &middot; <?php echo $cat_pct; ?>%
                                        </h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body grade-item-modal">
                                        <?php
                                            // Which required examinations still have no item (ST1 / ST2 / TE).
                                            $__missing_subtypes = [];
                                            if ($cat_key === 'QA' && $view_can_add && (empty($cat_items) || $__cat_tagged)) {
                                                foreach ($view_required_subtypes as $__c) {
                                                    $__has = false;
                                                    foreach ($cat_items as $__i) {
                                                        if (($__i['assessment_subtype'] ?? null) === $__c) { $__has = true; break; }
                                                    }
                                                    if (!$__has) $__missing_subtypes[] = $__c;
                                                }
                                            }
                                        ?>
                                        <!-- Percentage of the term grade, for THIS subject's grading rule -->
                                        <div class="grade-item-modal-note" style="margin-bottom:10px;">
                                            <strong><?php echo htmlspecialchars($meta['label']); ?> = <?php echo $cat_pct; ?>%</strong>
                                            of the <?php echo htmlspecialchars($term); ?> grade for <?php echo htmlspecialchars($subject_name); ?>
                                            (<?php echo htmlspecialchars($weights['category_label']); ?>):
                                            WW <?php echo $ww_pct; ?>% &middot; PT <?php echo $pt_pct; ?>%
                                            <?php if ($weights['exam_mode'] !== 'none'): ?>&middot; <?php echo htmlspecialchars($exam_label); ?> <?php echo $qa_pct; ?>%<?php endif; ?>
                                        </div>
                                        <?php $__guide = gh_componentGuide(); ?>
                                        <?php if ($cat_key === 'PT'): ?>
                                            <div class="grade-item-modal-note" style="margin-bottom:10px;">
                                                <strong>Teacher-encoded.</strong> Performance Tasks are outputs and performances scored by a
                                                rubric (<?php echo htmlspecialchars($__guide['PT']['examples']); ?>). They are recorded and computed
                                                manually from your own classroom observation. You set the <strong>Highest possible</strong>
                                                for each one; the AI does not compute them.
                                                <em>Recitation and quizzes go under Written/Oral Works.</em>
                                            </div>
                                        <?php elseif ($cat_key === 'WW'): ?>
                                            <div class="grade-item-modal-note" style="margin-bottom:10px;">
                                                <strong>Written/Oral Works:</strong> <?php echo htmlspecialchars($__guide['WW']['examples']); ?>.
                                                Recitation and other spoken responses are counted here. Attendance is not a
                                                grading component &mdash; it is recorded in the Attendance module.
                                            </div>
                                        <?php elseif ($cat_key === 'QA'): ?>
                                            <div class="grade-item-modal-note" style="margin-bottom:10px;">
                                                <strong>Examinations:</strong> <?php echo htmlspecialchars($__guide['QA']['examples']); ?>.
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($view_can_add && $cat_key !== 'QA'): ?>
                                            <!-- Add an item and set its Highest possible right here -->
                                            <div class="grade-setup-row" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:12px;">
                                                <div style="flex:1 1 200px;">
                                                    <label style="font-size:13.5px;display:block;margin-bottom:3px;">
                                                        <?php echo $cat_key === 'PT' ? 'Performance task name' : 'Item name'; ?>
                                                    </label>
                                                    <input type="text" name="new_item[<?php echo $cat_key; ?>][name]" class="grade-input" style="width:100%;text-align:left;"
                                                           maxlength="100" <?php echo $cat_key === 'PT' ? 'list="ptItemSuggestions"' : ($cat_key === 'WW' ? 'list="wwItemSuggestions"' : ''); ?>
                                                           placeholder="<?php echo $cat_key === 'PT' ? 'e.g. PETA 1, Project, Demonstration' : 'e.g. Quiz 1, Recitation'; ?>">
                                                </div>
                                                <div style="flex:0 0 160px;">
                                                    <label style="font-size:13.5px;display:block;margin-bottom:3px;">Highest possible</label>
                                                    <input type="number" name="new_item[<?php echo $cat_key; ?>][hps]" class="grade-input" style="width:100%;"
                                                           min="0.01" step="0.01" placeholder="e.g. 50">
                                                </div>
                                                <div style="font-size:13.5px;opacity:.75;flex:1 1 100%;">
                                                    Type the name and Highest possible, then press <strong>Done</strong> to add it &mdash; the score boxes appear right after.
                                                </div>
                                            </div>
                                            <?php if ($cat_key === 'PT'): ?>
                                                <datalist id="ptItemSuggestions">
                                                    <?php foreach (gh_performanceTaskSuggestions() as $__hint): ?>
                                                        <option value="<?php echo htmlspecialchars($__hint); ?>"></option>
                                                    <?php endforeach; ?>
                                                </datalist>
                                            <?php elseif ($cat_key === 'WW'): ?>
                                                <datalist id="wwItemSuggestions">
                                                    <?php foreach (gh_writtenWorkSuggestions() as $__hint): ?>
                                                        <option value="<?php echo htmlspecialchars($__hint); ?>"></option>
                                                    <?php endforeach; ?>
                                                </datalist>
                                            <?php endif; ?>
                                        <?php elseif ($cat_key === 'QA' && !empty($__missing_subtypes)): ?>
                                            <!-- Examinations: one Highest possible per required test -->
                                            <div class="grade-setup-row" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:12px;">
                                                <?php foreach ($__missing_subtypes as $__c): ?>
                                                    <div style="flex:1 1 200px;">
                                                        <label style="font-size:13.5px;display:block;margin-bottom:3px;">
                                                            <?php echo htmlspecialchars($__exam_subtypes[$__c]['label']); ?>
                                                            <?php if ($weights['exam_mode'] === 'standard'): ?>(<?php echo (int)round($__exam_subtypes[$__c]['weight'] * 100); ?>% of exam)<?php endif; ?>
                                                            &mdash; Highest possible
                                                        </label>
                                                        <input type="number" name="new_item[QA][<?php echo $__c; ?>]" class="grade-input" style="width:100%;"
                                                               min="0.01" step="0.01" placeholder="e.g. 60">
                                                    </div>
                                                <?php endforeach; ?>
                                                <div style="font-size:13.5px;opacity:.75;flex:1 1 100%;">
                                                    Type the Highest possible for each test, then press <strong>Done</strong> &mdash; the score boxes appear right after.
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (empty($cat_items)): ?>
                                            <div class="grade-item-modal-note">
                                                No <?php echo htmlspecialchars($meta['label']); ?> items yet.
                                                <?php if ($view_can_edit_setup && !$view_is_multi): ?>
                                                    Set the Highest possible above to start encoding scores.
                                                <?php else: ?>
                                                    Use the Manage Items button above to add an item before encoding scores.
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif (!empty($__sheet_owns_scores)): ?>
                                            <div class="grade-item-modal-note">
                                                <?php echo count($cat_items); ?> item<?php echo count($cat_items) === 1 ? '' : 's'; ?>:
                                                <?php echo htmlspecialchars(implode(', ', array_map(function ($i) { return $i['item_name'] . ' (/' . rtrim(rtrim($i['highest_score'], '0'), '.') . ')'; }, $cat_items))); ?>.
                                                Encode the scores in the <strong>Class Score Sheet</strong> on this page.
                                            </div>
                                        <?php elseif ($cat_key !== 'QA' || !$__cat_tagged): ?>
                                            <!-- Pooled layout: Total Raw Score / Total HPS / % Score (WW, PT, and an untagged QA set) -->
                                            <div class="grade-table-scroll">
                                                <table class="table">
                                                    <thead>
                                                        <tr>
                                                            <th style="text-align:left;">Student Name</th>
                                                            <th>LRN</th>
                                                            <?php foreach ($cat_items as $item): ?>
                                                                <th title="<?php echo htmlspecialchars($item['item_name']); ?>">
                                                                    <?php echo htmlspecialchars($item['item_name']); ?>
                                                                </th>
                                                            <?php endforeach; ?>
                                                            <th class="grade-subtotal-col">Total Raw Score</th>
                                                            <th class="grade-subtotal-col">Total HPS</th>
                                                            <th class="grade-subtotal-col">% Score</th>
                                                        </tr>
                                                        <!-- Highest possible score of each item -- set by the teacher -->
                                                        <tr class="grade-hps-row">
                                                            <th colspan="2" style="text-align:left;">Highest possible</th>
                                                            <?php foreach ($cat_items as $item): ?>
                                                                <th>
                                                                    <span class="hps-locked" title="Set in Manage Assessment Items"><?php echo $__fmt($item['highest_score']); ?></span>
                                                                </th>
                                                            <?php endforeach; ?>
                                                            <th colspan="3"></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($section_students as $student):
                                                            $sid = $student['student_id'];
                                                            $raw_total = 0.0; $hps_total = 0.0;
                                                            foreach ($cat_items as $item) {
                                                                $raw = $assessment_scores_map[$item['item_id']][$sid] ?? null;
                                                                if ($raw === null || $raw === '') continue;
                                                                $raw_total += (float)$raw;
                                                                $hps_total += (float)$item['highest_score'];
                                                            }
                                                            $pct = computeCategoryPercent($cat_items, $assessment_scores_map, $sid);
                                                        ?>
                                                            <tr>
                                                                <td class="student-name-cell"><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></td>
                                                                <td><?php echo htmlspecialchars($student['lrn']); ?></td>
                                                                <?php foreach ($cat_items as $item): $raw = $assessment_scores_map[$item['item_id']][$sid] ?? ''; ?>
                                                                    <td>
                                                                        <input type="number" name="items[<?php echo $item['item_id']; ?>][<?php echo $sid; ?>]"
                                                                               class="grade-input" value="<?php echo $raw; ?>"
                                                                               min="0" max="<?php echo $item['highest_score']; ?>" step="0.01"
                                                                               placeholder="/<?php echo rtrim(rtrim($item['highest_score'], '0'), '.'); ?>"
                                                                               oninput="calculateGrade('<?php echo $sid; ?>')">
                                                                    </td>
                                                                <?php endforeach; ?>
                                                                <td class="grade-subtotal-col"><span id="<?php echo $meta['short']; ?>-rawtotal-<?php echo $sid; ?>"><?php echo $hps_total > 0 ? rtrim(rtrim(number_format($raw_total, 2), '0'), '.') : '—'; ?></span></td>
                                                                <td class="grade-subtotal-col"><span id="<?php echo $meta['short']; ?>-hpstotal-<?php echo $sid; ?>"><?php echo $hps_total > 0 ? rtrim(rtrim(number_format($hps_total, 2), '0'), '.') : '—'; ?></span></td>
                                                                <td class="grade-subtotal-col"><span id="<?php echo $meta['short']; ?>-modalpct-<?php echo $sid; ?>"><?php echo $pct !== null ? number_format($pct, 2) . '%' : '—'; ?></span></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <!-- Examinations, tagged by subtype: ST1 / ST2 / TE each with their own
                                                 items + percentage, plus a final combined Examination %. -->
                                            <div class="grade-item-modal-note">
                                                Examinations = <?php echo $weights['exam_mode'] === 'term_exam_only' ? 'Term Examination only' : 'Summative Test 1 (30%) + Summative Test 2 (30%) + Term Examination (40%)'; ?>.
                                            </div>
                                            <div class="grade-table-scroll">
                                                <table class="table">
                                                    <thead>
                                                        <tr>
                                                            <th rowspan="3" style="text-align:left;">Student Name</th>
                                                            <th rowspan="3">LRN</th>
                                                            <?php foreach ($__exam_subtypes as $__code => $__sub):
                                                                if ($weights['exam_mode'] === 'term_exam_only' && $__code !== 'TE') continue;
                                                                $__sub_items = array_values(array_filter($cat_items, function ($i) use ($__code) { return ($i['assessment_subtype'] ?? null) === $__code; }));
                                                                if (empty($__sub_items)) continue;
                                                            ?>
                                                                <th colspan="<?php echo count($__sub_items) + 1; ?>" class="grade-subtype-band"><?php echo htmlspecialchars($__sub['label']); ?> (<?php echo (int)round($__sub['weight'] * 100); ?>%)</th>
                                                            <?php endforeach; ?>
                                                            <th rowspan="3" class="grade-subtotal-col">Examination %</th>
                                                        </tr>
                                                        <tr>
                                                            <?php foreach ($__exam_subtypes as $__code => $__sub):
                                                                if ($weights['exam_mode'] === 'term_exam_only' && $__code !== 'TE') continue;
                                                                $__sub_items = array_values(array_filter($cat_items, function ($i) use ($__code) { return ($i['assessment_subtype'] ?? null) === $__code; }));
                                                                if (empty($__sub_items)) continue;
                                                            ?>
                                                                <?php foreach ($__sub_items as $item): ?>
                                                                    <th class="grade-subtype-band" title="<?php echo htmlspecialchars($item['item_name']); ?>">
                                                                        <?php echo htmlspecialchars($item['item_name']); ?>
                                                                    </th>
                                                                <?php endforeach; ?>
                                                                <th class="grade-subtype-band grade-subtotal-col"><?php echo htmlspecialchars($__code); ?> %</th>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                        <!-- Highest possible score of each examination -- set by the teacher -->
                                                        <tr class="grade-hps-row">
                                                            <?php foreach ($__exam_subtypes as $__code => $__sub):
                                                                if ($weights['exam_mode'] === 'term_exam_only' && $__code !== 'TE') continue;
                                                                $__sub_items = array_values(array_filter($cat_items, function ($i) use ($__code) { return ($i['assessment_subtype'] ?? null) === $__code; }));
                                                                if (empty($__sub_items)) continue;
                                                            ?>
                                                                <?php foreach ($__sub_items as $item): ?>
                                                                    <th class="grade-subtype-band">
                                                                        <span class="hps-locked" title="Set in Manage Assessment Items"><?php echo $__fmt($item['highest_score']); ?></span>
                                                                    </th>
                                                                <?php endforeach; ?>
                                                                <th class="grade-subtype-band grade-subtotal-col">Highest possible</th>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($section_students as $student):
                                                            $sid = $student['student_id'];
                                                            $__exam_row = computeExamPercent($cat_items, $assessment_scores_map, $sid, $weights['exam_mode']);
                                                        ?>
                                                            <tr>
                                                                <td class="student-name-cell"><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></td>
                                                                <td><?php echo htmlspecialchars($student['lrn']); ?></td>
                                                                <?php foreach ($__exam_subtypes as $__code => $__sub):
                                                                    if ($weights['exam_mode'] === 'term_exam_only' && $__code !== 'TE') continue;
                                                                    $__sub_items = array_values(array_filter($cat_items, function ($i) use ($__code) { return ($i['assessment_subtype'] ?? null) === $__code; }));
                                                                    if (empty($__sub_items)) continue;
                                                                    $__sub_pct = $__exam_row['parts'][$__code] ?? null;
                                                                ?>
                                                                    <?php foreach ($__sub_items as $item): $raw = $assessment_scores_map[$item['item_id']][$sid] ?? ''; ?>
                                                                        <td>
                                                                            <input type="number" name="items[<?php echo $item['item_id']; ?>][<?php echo $sid; ?>]"
                                                                                   class="grade-input" value="<?php echo $raw; ?>"
                                                                                   min="0" max="<?php echo $item['highest_score']; ?>" step="0.01"
                                                                                   placeholder="/<?php echo rtrim(rtrim($item['highest_score'], '0'), '.'); ?>"
                                                                                   oninput="calculateGrade('<?php echo $sid; ?>')">
                                                                        </td>
                                                                    <?php endforeach; ?>
                                                                    <td class="grade-subtotal-col"><span id="qa-<?php echo htmlspecialchars($__code); ?>-pct-<?php echo $sid; ?>"><?php echo $__sub_pct !== null ? number_format($__sub_pct, 2) . '%' : '—'; ?></span></td>
                                                                <?php endforeach; ?>
                                                                <td class="grade-subtotal-col"><span id="qa-examtotal-pct-<?php echo $sid; ?>"><?php echo $__exam_row['percent'] !== null ? number_format($__exam_row['percent'], 2) . '%' . (!$__exam_row['complete'] ? ' *' : '') : '—'; ?></span></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="modal-footer">
                                        <?php if ($term_readonly): ?>
                                            <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--border);color:var(--ink);background:var(--surface);">Close</button>
                                        <?php else: ?>
                                            <button type="submit" name="save_grades" value="<?php echo $meta['short']; ?>" class="btn btn-sm" style="border:1px solid var(--border);color:var(--ink);background:var(--surface);">Done</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="gm-foot">
                        <?php if (!$term_readonly): ?>
                            <div class="gm-foot-note">
                                <i class="fas fa-circle-check gm-foot-ic"></i>
                                <div>
                                    <div class="gm-foot-main">Scores are <strong>saved automatically as a draft</strong>. Students see grades only after <?php echo $is_finalized ? 'Re-finalize' : 'Finalize'; ?>.</div>
                                    <?php if ($encode_mode === 'all'): ?><div class="gm-foot-sub"><?php echo $is_finalized ? 'Re-finalize after changing scores to recompute the grades.' : 'Finalize once every item and score is complete.'; ?></div><?php endif; ?>
                                    <div class="ss-status" id="ssStatus"></div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <a href="?step=4&section_id=<?php echo $selected_section; ?>&subject_code=<?php echo urlencode($selected_subject); ?>&term=<?php echo urlencode($term); ?>"
                           class="btn"
                           style="border: 1px solid var(--border);color: var(--text-muted);background: var(--surface);">
                            Cancel
                        </a>
                        <?php if ($encode_mode === 'single' && !$term_readonly): ?>
                            <!-- One learner: Finalize is whole-class only, so this view saves its scores directly. -->
                            <button type="submit" name="save_grades" value="sheet" class="btn ss-save"><i class="fas fa-floppy-disk"></i>Save now</button>
                        <?php endif; ?>
                        <?php if ($encode_mode === 'all' && !$term_readonly): ?>
                            <?php
                            // A heads-up, never a hard block: if the teacher set Term dates
                            // in Manage Assessment Items and the term hasn't reached its End
                            // Date yet, say so in the confirmation instead of silently
                            // letting an early Finalize look identical to a normal one.
                            $early_finalize_note = in_array($window_status['status'], ['upcoming', 'ongoing'], true)
                                ? ' Note: ' . addslashes($term) . ' has not ended yet (' . addslashes($window_status['label']) . ') -- you can still finalize now if you are sure every score is in.'
                                : '';
                            ?>
                            <button type="submit" name="finalize_term" value="1" class="btn btn-save"
                                    onclick="return appConfirm(this, '<?php echo $is_finalized
                                        ? 'This recomputes the Term Grade for the whole class from the scores currently in Manage Assessment Items.' . $early_finalize_note
                                        : 'This computes the official Initial Grade, ' . addslashes(gh_periodGradeLabel($active_sy)) . ' and Remarks for every student in this class from Manage Assessment Items.' . $early_finalize_note; ?>', {title: '<?php echo $is_finalized ? 'Re-finalize ' . addslashes($term) . '?' : 'Finalize ' . addslashes($term) . '?'; ?>', icon: 'fa-file-lines'})">
                                <?php echo $is_finalized ? 'Re-finalize ' . htmlspecialchars($term) : 'Finalize ' . htmlspecialchars($term); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if ($term_readonly): ?>
                    <script>
                    // Completed term: everything is read-only history. The server refuses
                    // any save/finalize for it as well; this only stops typing.
                    (function () {
                        var f = document.getElementById('gradeForm');
                        if (!f) return;
                        f.querySelectorAll('input:not([type=hidden]), select, textarea').forEach(function (el) {
                            el.readOnly = true; el.disabled = true;
                        });
                        f.querySelectorAll('button[type=submit]').forEach(function (b) { b.style.display = 'none'; });
                    })();
                    </script>
                <?php endif; ?>

                <!-- View Computation: read-only, Excel-style breakdown of one learner's grade
                     (filled in by openGradeComputation() in the script below). -->
                <div class="modal fade" id="gradeComputationModal" tabindex="-1" aria-labelledby="gcTitle" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="gcTitle">Grade Computation</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body" id="gcBody"></div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-sm" style="border:1px solid var(--border);color:var(--ink);background:var(--surface);" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
// All grading constants below are emitted by the central PHP helpers so the
// live preview can never drift from the authoritative server-side result.
var wwWeight = <?php echo json_encode(isset($weights) && $weights['ww'] !== null ? (float)$weights['ww'] : 0); ?>;
var ptWeight = <?php echo json_encode(isset($weights) && $weights['pt'] !== null ? (float)$weights['pt'] : 0); ?>;
var qaWeight = <?php echo json_encode(isset($weights) && $weights['ex'] !== null ? (float)$weights['ex'] : 0); ?>;
var examMode = <?php echo json_encode(isset($weights) ? $weights['exam_mode'] : 'standard'); ?>;
var passMark = <?php echo json_encode((float)getGradingPassMark()); ?>;

// School-year-aware transmutation table: [lower_bound, transmuted_grade],
// descending. Never hard-code a table in a page — this comes from
// getTransmutationTable() in includes/grading_helpers.php.
var transmutationTable = <?php echo gh_transmutationTableJson($active_sy); ?>;

// Examination subtype weights inside EX (ST1 30% / ST2 30% / TE 40%).
var examSubtypeWeights = <?php
    $__sub = [];
    foreach (gh_examSubtypes() as $__k => $__v) { $__sub[$__k] = $__v['weight']; }
    echo json_encode($__sub);
?>;

// Assessment items per category — used to auto-compute the % Score
// ("Excel style": Total Raw / Total HPS x 100) as the teacher types.
// 'sub' carries the ST1/ST2/TE tag so the browser preview applies the same
// 30/30/40 rule the server uses.
<?php
    $__mapItems = function ($items) {
        return array_map(function ($i) {
            return [
                'id'  => $i['item_id'],
                'hps' => (float)$i['highest_score'],
                'sub' => $i['assessment_subtype'] ?? null,
                'dom' => $i['assessment_domain'] ?? null,
                'name' => html_entity_decode((string)($i['item_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'comp' => $i['component'] ?? null,
            ];
        }, $items);
    };
?>
// Labels for the View Computation modal (the weights themselves are above).
var gcMeta = <?php
    $__gc_components = gh_effectiveComponents(gh_subjectComponents($subject_row ?? $selected_subject, $weights['category'] ?? null), $assessment_items);
    echo json_encode([
        'subject'    => html_entity_decode((string)($subject_name ?? ''), ENT_QUOTES, 'UTF-8'),
        'term'       => (string)$term,
        'sy'         => (string)($active_sy['sy_name'] ?? ''),
        'rule'       => (string)($weights['category_label'] ?? ''),
        'wwLabel'    => 'Written/Oral Works',
        'ptLabel'    => 'Performance Tasks',
        'examLabel'  => (string)gh_examComponentLabel($active_sy),
        'periodLabel'=> (string)gh_periodGradeLabel($active_sy),
        'components' => array_values($__gc_components),
    ]);
?>;
var wwItems = <?php echo json_encode($__mapItems($assessment_items['WW'])); ?>;
var ptItems = <?php echo json_encode($__mapItems($assessment_items['PT'])); ?>;
var qaItems = <?php echo json_encode($__mapItems($assessment_items['QA'])); ?>;

function changeSubject() {
    var subject = document.getElementById('subjectSelect').value;
    var url = new URL(window.location.href);
    url.searchParams.set('subject_code', subject);
    window.location.href = url.toString();
}

// A category percentage (and the Initial Grade) must never be displayed
// above 100% or below 0% -- mirrors gh_clampPercent() in
// includes/grading_helpers.php so the live browser preview can never show
// a number the server itself would refuse to store. A score typed larger
// than an item's own Highest Possible Score is still rejected server-side
// by validateRawScore() when the teacher saves -- this only keeps the
// on-screen preview sane while they are still typing.
function clampPct(pct) {
    if (pct === null || pct === undefined || isNaN(pct)) return pct;
    if (pct < 0) return 0;
    if (pct > 100) return 100;
    return pct;
}

// Auto-computes one category's % Score for a student, "Excel style":
// % Score = (Total Raw Score / Total Highest Possible Score) x 100.
// Falls back to the manually-typed percentage when no items are set up.
// Each percentage is displayed in TWO places -- the simplified main table
// (id="{prefix}-pct-{id}") and that category's own item-entry modal
// (id="{prefix}-modalpct-{id}", a DIFFERENT id so the two never collide) --
// so both stay in sync automatically as the teacher types, whether the
// modal is open or already closed.
function computeCategoryPercentJS(items, studentId, manualFieldName, pctElPrefix) {
    if (!items || items.length === 0) {
        var manualInput = document.querySelector('input[name="grades[' + studentId + '][' + manualFieldName + ']"]');
        return (manualInput && manualInput.value !== '') ? clampPct(parseFloat(manualInput.value) || 0) : null;
    }

    var rawTotal = 0, hpsTotal = 0;
    items.forEach(function (it) {
        var el = document.querySelector('input[name="items[' + it.id + '][' + studentId + ']"]');
        var v = el ? el.value : '';
        if (v === '' || v === null) return;
        rawTotal += parseFloat(v) || 0;
        hpsTotal += it.hps;
    });

    // Same as the server: 2-decimal percentage score, and "nothing encoded" is
    // null (not counted), never a zero.
    var pct = hpsTotal > 0 ? clampPct(gcRound2((rawTotal / hpsTotal) * 100)) : null;
    var display = hpsTotal > 0 ? pct.toFixed(2) + '%' : '—';

    var pctEl = document.getElementById(pctElPrefix + '-pct-' + studentId);
    if (pctEl) pctEl.textContent = display;
    var modalPctEl = document.getElementById(pctElPrefix + '-modalpct-' + studentId);
    if (modalPctEl) modalPctEl.textContent = display;
    var rawTotalEl = document.getElementById(pctElPrefix + '-rawtotal-' + studentId);
    if (rawTotalEl) rawTotalEl.textContent = hpsTotal > 0 ? (Math.round(rawTotal * 100) / 100) : '—';
    var hpsTotalEl = document.getElementById(pctElPrefix + '-hpstotal-' + studentId);
    if (hpsTotalEl) hpsTotalEl.textContent = hpsTotal > 0 ? hpsTotal : '—';

    return pct;
}

// Percentage score for one examination subtype (ST1 / ST2 / TE), also
// updating that subtype's own column inside the Examinations modal.
function examSubtypePercentJS(studentId, code) {
    var rawTotal = 0, hpsTotal = 0, seen = false;
    qaItems.forEach(function (it) {
        if (it.sub !== code) return;
        var el = document.querySelector('input[name="items[' + it.id + '][' + studentId + ']"]');
        var v = el ? el.value : '';
        if (v === '' || v === null) return;
        rawTotal += parseFloat(v) || 0;
        hpsTotal += it.hps;
        seen = true;
    });
    var pct = (!seen || hpsTotal <= 0) ? null : clampPct((rawTotal / hpsTotal) * 100);
    var subEl = document.getElementById('qa-' + code + '-pct-' + studentId);
    if (subEl) subEl.textContent = pct !== null ? pct.toFixed(2) + '%' : '—';
    return pct;
}

// EXAMINATIONS percentage score.
//   standard       -> ST1 x .30 + ST2 x .30 + TE x .40 (each its own %)
//   term_exam_only -> the Term Examination percentage only
//   none           -> no EX component
// Falls back to the pooled percentage only when no item carries a subtype.
function computeExamPercentJS(studentId) {
    if (examMode === 'none') return null;

    var tagged = qaItems.some(function (it) { return !!it.sub; });
    if (!tagged) {
        return computeCategoryPercentJS(qaItems, studentId, 'examinations', 'qa');
    }

    var required = (examMode === 'term_exam_only') ? ['TE'] : ['ST1', 'ST2', 'TE'];
    var total = 0, usedWeight = 0, missing = false;

    required.forEach(function (code) {
        var pct = examSubtypePercentJS(studentId, code);
        if (pct === null) { missing = true; return; }
        var w = (examMode === 'term_exam_only') ? 1.0 : (examSubtypeWeights[code] || 0);
        total += gcRound2(pct * w);
        usedWeight += w;
    });

    var pctEl = document.getElementById('qa-pct-' + studentId);
    var modalTotalEl = document.getElementById('qa-examtotal-pct-' + studentId);
    if (usedWeight <= 0) {
        if (pctEl) pctEl.textContent = '—';
        if (modalTotalEl) modalTotalEl.textContent = '—';
        return null;
    }

    var value = clampPct(gcRound2(missing ? (total / usedWeight) : total));
    var display = value.toFixed(2) + '%' + (missing ? ' *' : '');
    if (pctEl) pctEl.textContent = display;
    if (modalTotalEl) modalTotalEl.textContent = display;
    return missing ? null : value;
}

function calculateGrade(studentId) {
    var ww = computeCategoryPercentJS(wwItems, studentId, 'written_work', 'ww');
    var pt = computeCategoryPercentJS(ptItems, studentId, 'performance_task', 'pt');
    var qa = (qaItems.length > 0)
        ? computeExamPercentJS(studentId)
        : (examMode === 'none' ? null : computeCategoryPercentJS(qaItems, studentId, 'examinations', 'qa'));

    // Mirror the server: skip zero-weight components, and renormalise rather
    // than treating a not-yet-encoded component as a zero.
    var parts = [
        { pct: ww, w: wwWeight },
        { pct: pt, w: ptWeight },
        { pct: qa, w: qaWeight }
    ];
    var total = 0, usedWeight = 0, incomplete = false;
    parts.forEach(function (p) {
        if (!p.w || p.w <= 0) return;
        if (p.pct === null || p.pct === undefined || isNaN(p.pct)) { incomplete = true; return; }
        total += gcRound2(p.pct * p.w);
        usedWeight += p.w;
    });

    var initialEl = document.getElementById('initial-' + studentId);
    var finalEl = document.getElementById('final-' + studentId);
    var remarksEl = document.getElementById('remarks-' + studentId);

    if (usedWeight <= 0) {
        if (initialEl) initialEl.innerHTML = '<span class="fw-bold text-muted">—</span>';
        if (finalEl) finalEl.innerHTML = '<span class="fw-bold text-muted">—</span>';
        if (remarksEl) remarksEl.innerHTML = '<span class="badge-status">Incomplete</span>';
        return;
    }

    var initial = clampPct(gcRound2(incomplete ? (total / usedWeight) : total));
    var transmuted = transmuteGrade(initial);
    var remarks = incomplete ? 'Incomplete' : (transmuted >= passMark ? 'Passed' : 'Failed');

    if (initialEl) initialEl.innerHTML = '<span class="fw-bold ' + (initial < passMark ? 'text-danger' : 'text-success') + '">' + initial.toFixed(2) + '</span>';
    if (finalEl) finalEl.innerHTML = '<span class="fw-bold ' + (transmuted < passMark ? 'text-danger' : 'text-success') + '">' + transmuted + '</span>';

    if (remarksEl) {
        var badgeClass = remarks === 'Passed' ? 'badge-status passed'
                       : (remarks === 'Failed' ? 'badge-status failed' : 'badge-status');
        remarksEl.innerHTML = '<span class="' + badgeClass + '">' + remarks + '</span>';
    }
}

// Uses the school-year table emitted above. The Initial Grade is NOT rounded
// before the band lookup, so boundaries stay decimal-safe.
function transmuteGrade(grade) {
    var g = parseFloat(grade);
    if (isNaN(g)) return 0;
    if (g < 0) g = 0;
    if (g > 100) g = 100;
    for (var i = 0; i < transmutationTable.length; i++) {
        if (g + 0.0000001 >= transmutationTable[i][0]) {
            return transmutationTable[i][1];
        }
    }
    return transmutationTable[transmutationTable.length - 1][1];
}

// ============================================================
// VIEW COMPUTATION — Excel-style breakdown of one learner's grade.
// Reads the SAME live score inputs, weights, exam split and transmutation
// table as the class table above, and rounds at the same steps as the
// server (Percentage Score -> Weighted Score -> Initial Grade, each to
// 2 decimals), so this always agrees with the table and with Finalize.
// It never writes anything.
// ============================================================
function gcRound2(x) {
    return Math.round((x * 100) + 0.000001) / 100;
}
function gcClamp(x) { return x < 0 ? 0 : (x > 100 ? 100 : x); }
function gcEsc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function gcNum(n) { return (n === null || n === undefined || isNaN(n)) ? '—' : String(Math.round(n * 100) / 100); }
function gcTwo(n) { return (n === null || n === undefined || isNaN(n)) ? '—' : Number(n).toFixed(2); }
function gcPctTxt(n) { return (n === null || n === undefined || isNaN(n)) ? '—' : Number(n).toFixed(2) + '%'; }
function gcWeightTxt(w) { return (Math.round(w * 10000) / 100) + '%'; }

function gcReadRaw(itemId, studentId) {
    var el = document.querySelector('input[name="items[' + itemId + '][' + studentId + ']"]');
    if (!el || el.value === '' || el.value === null) return null;
    var v = parseFloat(el.value);
    return isNaN(v) ? null : v;
}

// One set of items -> raw / HPS / percentage score (blank = not encoded, never a zero).
function gcGroup(items, studentId) {
    var rows = [], rawT = 0, hpsT = 0, seen = false;
    items.forEach(function (it) {
        var raw = gcReadRaw(it.id, studentId);
        rows.push({ name: it.name, hps: it.hps, raw: raw });
        if (raw !== null) { rawT += raw; hpsT += it.hps; seen = true; }
    });
    var pct = (seen && hpsT > 0) ? gcClamp(gcRound2((rawT / hpsT) * 100)) : null;
    return { rows: rows, rawT: rawT, hpsT: hpsT, pct: pct, itemCount: items.length };
}

// Examinations: ST1 30% + ST2 30% + TE 40% (or Term Exam only), each test first
// turned into its own percentage. Mirrors computeExamPercentJS()/computeExamPercent().
function gcExam(items, studentId) {
    if (examMode === 'none') return null;
    var tagged = items.some(function (it) { return !!it.sub; });
    if (!tagged) {
        var g = gcGroup(items, studentId);
        return { tagged: false, pooled: g, groups: [], pct: g.pct, complete: g.pct !== null, missing: [] };
    }
    var required = (examMode === 'term_exam_only') ? ['TE'] : ['ST1', 'ST2', 'TE'];
    var labels = { ST1: 'Summative Test 1 (ST1)', ST2: 'Summative Test 2 (ST2)', TE: 'Term Examination (TE)' };
    var total = 0, used = 0, missing = [], groups = [];
    required.forEach(function (code) {
        var subset = items.filter(function (it) { return it.sub === code; });
        var g = subset.length ? gcGroup(subset, studentId) : null;
        var w = (examMode === 'term_exam_only') ? 1.0 : (examSubtypeWeights[code] || 0);
        var pct = g ? g.pct : null;
        var ws = pct === null ? null : gcRound2(pct * w);
        groups.push({ code: code, label: labels[code], weight: w, g: g, pct: pct, ws: ws });
        if (pct === null) { missing.push(code); return; }
        total += ws;
        used += w;
    });
    var pctAll = used > 0 ? gcClamp(gcRound2(missing.length ? (total / used) : total)) : null;
    return { tagged: true, groups: groups, pct: pctAll, complete: missing.length === 0, missing: missing };
}

function gcDescriptor(g) {
    if (g >= 90) return 'Advancing';
    if (g >= 80) return 'Benchmarking';
    if (g >= 75) return 'Connecting';
    if (g >= 65) return 'Developing';
    return 'Emerging';
}

// WW + PT + Examinations -> Initial Grade -> transmuted Term Grade -> remark.
function gcTerm(wwPct, ptPct, exPct) {
    var parts = [
        { code: 'WW', label: gcMeta.wwLabel, pct: wwPct, w: wwWeight },
        { code: 'PT', label: gcMeta.ptLabel, pct: ptPct, w: ptWeight },
        { code: 'EX', label: gcMeta.examLabel, pct: exPct, w: qaWeight }
    ].filter(function (p) { return p.w && p.w > 0; });
    var total = 0, used = 0, missing = [];
    parts.forEach(function (p) {
        if (p.pct === null || p.pct === undefined || isNaN(p.pct)) { p.ws = null; missing.push(p.label); return; }
        p.ws = gcRound2(p.pct * p.w);
        total += p.ws;
        used += p.w;
    });
    var out = { parts: parts, total: total, used: used, missing: missing, initial: null, transmuted: null, remarks: 'Incomplete', complete: missing.length === 0, band: null, descriptor: null };
    if (used <= 0) return out;
    out.initial = gcClamp(gcRound2(missing.length ? (total / used) : total));
    out.transmuted = transmuteGrade(out.initial);
    out.remarks = missing.length ? 'Incomplete' : (out.transmuted >= passMark ? 'Passed' : 'Failed');
    out.descriptor = gcDescriptor(out.transmuted);
    for (var i = 0; i < transmutationTable.length; i++) {
        if (out.initial + 0.0000001 >= transmutationTable[i][0]) {
            out.band = { lower: transmutationTable[i][0], upper: i > 0 ? transmutationTable[i - 1][0] - 0.01 : 100, grade: transmutationTable[i][1] };
            break;
        }
    }
    return out;
}

// ---- rendering --------------------------------------------------------
function gcItemRows(group, factor, startIdx, weightHeadNote) {
    var html = '';
    group.rows.forEach(function (r, i) {
        var enc = r.raw !== null;
        var pct = enc && r.hps > 0 ? gcClamp(gcRound2((r.raw / r.hps) * 100)) : null;
        var wsItem = (enc && group.hpsT > 0) ? (r.raw / group.hpsT) * 100 * factor : null;
        html += '<tr' + (enc ? '' : ' class="gc-blank"') + '>'
            + '<td class="gc-idx">' + (startIdx + i) + '</td>'
            + '<td class="gc-left">' + gcEsc(r.name) + (enc ? '' : ' <span class="gc-tag">not encoded &mdash; not counted</span>') + '</td>'
            + '<td>' + (enc ? gcNum(r.raw) : '—') + '</td>'
            + '<td>' + gcNum(r.hps) + '</td>'
            + '<td>' + gcPctTxt(pct) + '</td>'
            + '<td>' + gcTwo(wsItem) + '</td>'
            + '</tr>';
    });
    return html;
}

function gcTableHead() {
    return '<thead><tr><th class="gc-idx">#</th><th class="gc-left">Item</th><th>Raw Score</th><th>Highest Possible</th><th>% Score</th><th>Weighted Score</th></tr></thead>';
}

function gcSectionShell(cls, title, weightTxt, inner, footer) {
    return '<div class="gc-section ' + cls + '">'
        + '<div class="gc-section-head"><span>' + gcEsc(title) + '</span><span class="gc-weight">Weight ' + weightTxt + '</span></div>'
        + '<div class="gc-scroll">' + inner + '</div>'
        + (footer ? '<div class="gc-formula">' + footer + '</div>' : '')
        + '</div>';
}

// Written/Oral Works or Performance Tasks
function gcRenderSimple(cls, title, weight, items, group) {
    var inner;
    if (!items.length) {
        return gcSectionShell(cls, title, gcWeightTxt(weight),
            '<div class="gc-empty">No items have been set up for this component yet, so it is not counted.</div>', '');
    }
    var ws = group.pct === null ? null : gcRound2(group.pct * weight);
    inner = '<table class="gc-table">' + gcTableHead() + '<tbody>'
        + gcItemRows(group, weight, 1)
        + '<tr class="gc-subtotal"><td></td><td class="gc-left">Subtotal</td><td>' + (group.pct === null ? '—' : gcNum(group.rawT)) + '</td><td>'
        + (group.pct === null ? '—' : gcNum(group.hpsT)) + '</td><td>' + gcPctTxt(group.pct) + '</td><td>' + gcTwo(ws) + '</td></tr>'
        + '</tbody></table>';
    var foot = group.pct === null
        ? 'No score has been encoded yet &mdash; this component is not counted (a blank is never treated as zero).'
        : '<b>Percentage Score</b> = ' + gcNum(group.rawT) + ' &divide; ' + gcNum(group.hpsT) + ' &times; 100 = <b>' + gcPctTxt(group.pct) + '</b>'
          + ' &nbsp;&middot;&nbsp; <b>Weighted Score</b> = ' + gcTwo(group.pct) + ' &times; ' + gcWeightTxt(weight) + ' = <b>' + gcTwo(ws) + '</b>';
    return gcSectionShell(cls, title, gcWeightTxt(weight), inner, foot);
}

function gcRenderExam(items, exam) {
    var cls = 'gc-qa', title = gcMeta.examLabel;
    if (!exam) return '';
    if (!items.length) {
        return gcSectionShell(cls, title, gcWeightTxt(qaWeight),
            '<div class="gc-empty">No examination items have been set up yet, so this component is not counted.</div>', '');
    }
    var inner = '', foot = '';
    if (!exam.tagged) {
        // Legacy: examination items with no ST1 / ST2 / TE tag are pooled.
        var g = exam.pooled;
        var ws0 = exam.pct === null ? null : gcRound2(exam.pct * qaWeight);
        inner = '<table class="gc-table">' + gcTableHead() + '<tbody>' + gcItemRows(g, qaWeight, 1)
            + '<tr class="gc-subtotal"><td></td><td class="gc-left">Subtotal</td><td>' + (g.pct === null ? '—' : gcNum(g.rawT)) + '</td><td>'
            + (g.pct === null ? '—' : gcNum(g.hpsT)) + '</td><td>' + gcPctTxt(g.pct) + '</td><td>' + gcTwo(ws0) + '</td></tr></tbody></table>';
        foot = 'These examination items are not tagged as ST1 / ST2 / Term Exam, so the 30% / 30% / 40% split cannot be applied and the pooled percentage is used. Tag them in Manage Assessment Items.';
        return gcSectionShell(cls, title, gcWeightTxt(qaWeight), inner, foot);
    }
    var idx = 1, body = '', lines = [];
    exam.groups.forEach(function (gr) {
        var band = '<tr class="gc-band"><td colspan="6">' + gcEsc(gr.label) + ' &mdash; ' + gcWeightTxt(gr.weight) + ' of ' + gcEsc(gcMeta.examLabel) + '</td></tr>';
        body += band;
        if (!gr.g) {
            body += '<tr class="gc-blank"><td></td><td class="gc-left" colspan="5">No ' + gcEsc(gr.code) + ' item has been set up yet <span class="gc-tag">not counted</span></td></tr>';
            lines.push(gcEsc(gr.code) + ': not set up');
            return;
        }
        body += gcItemRows(gr.g, gr.weight * qaWeight, idx);
        idx += gr.g.rows.length;
        var wsPoints = gr.pct === null ? null : gr.pct * gr.weight * qaWeight;
        body += '<tr class="gc-subtotal"><td></td><td class="gc-left">' + gcEsc(gr.code) + ' subtotal</td><td>' + (gr.pct === null ? '—' : gcNum(gr.g.rawT))
            + '</td><td>' + (gr.pct === null ? '—' : gcNum(gr.g.hpsT)) + '</td><td>' + gcPctTxt(gr.pct) + '</td><td>' + gcTwo(wsPoints) + '</td></tr>';
        lines.push(gr.pct === null
            ? gcEsc(gr.code) + ': no score yet'
            : gcEsc(gr.code) + ' ' + gcTwo(gr.pct) + '% &times; ' + gcWeightTxt(gr.weight) + ' = <b>' + gcTwo(gr.ws) + '</b>');
    });
    inner = '<table class="gc-table">' + gcTableHead() + '<tbody>' + body + '</tbody></table>';
    var exWs = exam.pct === null ? null : gcRound2(exam.pct * qaWeight);
    if (examMode === 'term_exam_only') {
        foot = '<b>Examinations = Term Examination only.</b> ' + lines.join(' &nbsp;&middot;&nbsp; ');
    } else {
        foot = '<b>Examinations % Score</b> (ST1 30% + ST2 30% + TE 40%): ' + lines.join(' &nbsp;+&nbsp; ');
    }
    if (exam.complete) {
        foot += ' &nbsp;=&nbsp; <b>' + gcPctTxt(exam.pct) + '</b> &nbsp;&middot;&nbsp; <b>Weighted Score</b> = ' + gcTwo(exam.pct) + ' &times; ' + gcWeightTxt(qaWeight) + ' = <b>' + gcTwo(exWs) + '</b>';
    } else {
        foot += '<br><span class="gc-warn">Incomplete &mdash; ' + exam.missing.join(', ') + ' has no score yet, so Examinations is not counted.</span>';
    }
    return gcSectionShell(cls, title, gcWeightTxt(qaWeight), inner, foot);
}

function gcRenderSummary(term, exam) {
    var rows = '';
    term.parts.forEach(function (p) {
        var counted = p.ws !== null;
        rows += '<tr' + (counted ? '' : ' class="gc-blank"') + '><td class="gc-left">' + gcEsc(p.label)
            + (counted ? '' : ' <span class="gc-tag">' + (p.code === 'EX' && exam && exam.pct !== null ? 'incomplete &mdash; not counted' : 'no score &mdash; not counted') + '</span>') + '</td>'
            + '<td>' + gcPctTxt(counted ? p.pct : null) + '</td><td>' + gcWeightTxt(p.w) + '</td><td>' + gcTwo(p.ws) + '</td></tr>';
    });
    var totalRow = '<tr class="gc-total"><td class="gc-left">Initial Grade</td><td></td><td>' + (term.missing.length ? gcWeightTxt(term.used) : '100%') + '</td><td>' + gcTwo(term.missing.length ? term.total : term.initial) + '</td></tr>';
    var html = '<table class="gc-table gc-summary"><thead><tr><th class="gc-left">Component</th><th>% Score</th><th>Weight</th><th>Weighted Score</th></tr></thead><tbody>' + rows + totalRow + '</tbody></table>';
    var note = '';
    if (term.initial === null) {
        note = '<div class="gc-note gc-warn">No component has a score yet, so there is nothing to compute.</div>';
    } else if (term.missing.length) {
        note = '<div class="gc-note gc-warn">Incomplete &mdash; ' + gcEsc(term.missing.join(', '))
            + ' has no score yet. Preview re-scaled: ' + gcTwo(term.total) + ' &divide; ' + gcNum(term.used) + ' = <b>' + gcTwo(term.initial) + '</b></div>';
    }
    return html + note;
}

function gcRenderResult(term) {
    if (term.initial === null) {
        return '<div class="gc-result"><div class="gc-res-cell"><span>Initial Grade</span><b>—</b></div><div class="gc-res-cell"><span>Transmuted Term Grade</span><b>—</b></div><div class="gc-res-cell"><span>Remarks</span><span class="badge-status">Incomplete</span></div></div>';
    }
    var cls = term.remarks === 'Passed' ? 'badge-status passed' : (term.remarks === 'Failed' ? 'badge-status failed' : 'badge-status');
    var bandTxt = term.band ? 'Table band ' + gcTwo(term.band.lower) + ' &ndash; ' + gcTwo(term.band.upper) : '';
    return '<div class="gc-result">'
        + '<div class="gc-res-cell"><span>Initial Grade</span><b>' + gcTwo(term.initial) + '</b></div>'
        + '<div class="gc-res-cell"><span>Transmuted ' + gcEsc(gcMeta.periodLabel) + '</span><b class="' + (term.transmuted < passMark ? 'gc-bad' : 'gc-good') + '">' + term.transmuted + '</b>' + (bandTxt ? '<small>' + bandTxt + '</small>' : '') + '</div>'
        + '<div class="gc-res-cell"><span>Remarks</span><span class="' + cls + '">' + term.remarks + '</span>'
        + (term.descriptor ? '<small>' + gcEsc(term.descriptor) + '</small>' : '') + '</div></div>';
}

// One component's whole calculation (the whole subject, or one MAPEH component).
function gcRenderBlock(studentId, comp) {
    var pick = function (list) { return comp === null ? list : list.filter(function (it) { return it.comp === comp; }); };
    var wi = pick(wwItems), pi = pick(ptItems), qi = pick(qaItems);
    var wg = gcGroup(wi, studentId), pg = gcGroup(pi, studentId);
    var ex = gcExam(qi, studentId);
    var exForGrade = (ex && ex.complete) ? ex.pct : null;
    var term = gcTerm(wi.length ? wg.pct : null, pi.length ? pg.pct : null, ex ? exForGrade : null);
    var html = gcRenderSimple('gc-ww', gcMeta.wwLabel, wwWeight, wi, wg)
             + gcRenderSimple('gc-pt', gcMeta.ptLabel, ptWeight, pi, pg)
             + gcRenderExam(qi, ex)
             + '<div class="gc-section gc-sum"><div class="gc-section-head"><span>' + (comp === null ? 'Grade Computation' : gcEsc(comp) + ' &mdash; Computation') + '</span></div>'
             + '<div class="gc-pad">' + gcRenderSummary(term, ex) + gcRenderResult(term) + '</div></div>';
    return { html: html, term: term };
}

function openGradeComputation(studentId, name, lrn) {
    var body = document.getElementById('gcBody');
    if (!body) return;
    var comps = (gcMeta.components && gcMeta.components.length > 1) ? gcMeta.components : null;
    var html = '';

    html += '<div class="gc-info">'
        + '<div><label>Student</label><b>' + gcEsc(name) + '</b></div>'
        + '<div><label>LRN</label><b>' + gcEsc(lrn) + '</b></div>'
        + '<div><label>Subject</label><b>' + gcEsc(gcMeta.subject) + '</b></div>'
        + '<div><label>Term</label><b>' + gcEsc(gcMeta.term) + (gcMeta.sy ? ' &middot; SY ' + gcEsc(gcMeta.sy) : '') + '</b></div>'
        + '<div class="gc-info-wide"><label>Grading rule</label><b>' + gcEsc(gcMeta.rule) + '</b>'
        + ' &mdash; ' + gcEsc(gcMeta.wwLabel) + ' ' + gcWeightTxt(wwWeight) + ' &middot; ' + gcEsc(gcMeta.ptLabel) + ' ' + gcWeightTxt(ptWeight)
        + (examMode === 'none' ? ' &middot; no Examinations' : ' &middot; ' + gcEsc(gcMeta.examLabel) + ' ' + gcWeightTxt(qaWeight))
        + (examMode === 'standard' ? '<small>Examinations = ST1 30% + ST2 30% + Term Exam 40%</small>'
            : (examMode === 'term_exam_only' ? '<small>Examinations = Term Examination only</small>' : ''))
        + '</div></div>';

    if (!comps) {
        html += gcRenderBlock(studentId, null).html;
    } else {
        html += '<div class="gc-note">Components: ' + gcEsc(comps.join(', ')) + '. Each is computed on its own; the Term Grade is the <b>average</b> of the component grades.</div>';
        var grades = [], allComplete = true, initials = [];
        comps.forEach(function (c) {
            var b = gcRenderBlock(studentId, c);
            html += '<div class="gc-comp-title">' + gcEsc(c) + '</div>' + b.html;
            if (b.term.transmuted === null) { allComplete = false; return; }
            if (!b.term.complete) allComplete = false;
            grades.push(b.term.transmuted);
            initials.push(b.term.initial);
        });
        if (grades.length) {
            var avg = Math.round(grades.reduce(function (a, b) { return a + b; }, 0) / grades.length);
            var rem = allComplete ? (avg >= passMark ? 'Passed' : 'Failed') : 'Incomplete';
            var cls = rem === 'Passed' ? 'badge-status passed' : (rem === 'Failed' ? 'badge-status failed' : 'badge-status');
            html += '<div class="gc-section gc-sum"><div class="gc-section-head"><span>Overall Term Grade</span></div><div class="gc-pad">'
                + '<div class="gc-note">Average of the component grades: (' + grades.join(' + ') + ') &divide; ' + grades.length + ' = ' + (grades.reduce(function (a, b) { return a + b; }, 0) / grades.length).toFixed(2) + ' &rarr; <b>' + avg + '</b></div>'
                + '<div class="gc-result"><div class="gc-res-cell"><span>Overall Term Grade</span><b class="' + (avg < passMark ? 'gc-bad' : 'gc-good') + '">' + avg + '</b></div>'
                + '<div class="gc-res-cell"><span>Remarks</span><span class="' + cls + '">' + rem + '</span><small>' + gcDescriptor(avg) + '</small></div></div>'
                + (allComplete ? '' : '<div class="gc-note gc-warn">At least one component is incomplete, so the remark stays Incomplete.</div>') + '</div></div>';
        }
    }

    body.innerHTML = html;
    var t = document.getElementById('gcTitle');
    if (t) t.textContent = 'Grade Computation — ' + name;
    var el = document.getElementById('gradeComputationModal');
    if (el && window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(el).show();
    }
}

document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.js-view-computation') : null;
    if (!btn) return;
    e.preventDefault();
    openGradeComputation(btn.getAttribute('data-student-id'), btn.getAttribute('data-name') || '', btn.getAttribute('data-lrn') || '');
});

var gradeStudentIds = <?php echo json_encode(array_map('strval', array_column($section_students, 'student_id'))); ?>;

// The teacher can change an item's Highest possible right in the modal. Keep the
// live preview in step: update the item's HPS, the score boxes' limit, and
// recompute every learner. (The server re-validates everything on save.)
document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.classList || !t.classList.contains('hps-input')) return;
    var id = String(t.getAttribute('data-item-id'));
    var v = parseFloat(t.value);
    if (!(v > 0)) return;
    [wwItems, ptItems, qaItems].forEach(function (list) {
        list.forEach(function (it) { if (String(it.id) === id) it.hps = v; });
    });
    document.querySelectorAll('input[name^="items[' + id + ']["]').forEach(function (inp) {
        inp.max = v;
        inp.placeholder = '/' + v;
    });
    gradeStudentIds.forEach(function (sid) { calculateGrade(sid); });
});

// A raw score can never be higher than the item's Highest possible (or below 0).
// Flag it right away and block the save; the server rejects it too.
function gradeCheckScoreInput(inp) {
    var max = parseFloat(inp.max), v = inp.value === '' ? null : parseFloat(inp.value);
    var msg = '';
    if (v !== null && !isNaN(v)) {
        if (v < 0) msg = 'Min 0';
        else if (max > 0 && v > max + 0.00001) msg = 'Max ' + (+max.toFixed(2));
    }
    inp.setCustomValidity(msg ? 'The score must be from 0 to ' + (+max.toFixed(2)) + '.' : '');
    inp.classList.toggle('score-over', !!msg);
    if (inp.classList.contains('ss-cell')) return !msg;
    var note = inp.parentNode.querySelector('.score-over-msg');
    if (msg && !note) { note = document.createElement('span'); note.className = 'score-over-msg'; inp.parentNode.appendChild(note); }
    if (note) { if (msg) note.textContent = msg; else note.remove(); }
    return !msg;
}
document.addEventListener('input', function (e) {
    var t = e.target;
    if (t && t.name && t.name.indexOf('items[') === 0) gradeCheckScoreInput(t);
}, true);
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('gradeForm');
    if (!form) return;
    form.querySelectorAll('input[name^="items["]').forEach(gradeCheckScoreInput);
    form.addEventListener('submit', function (e) {
        var bad = null;
        form.querySelectorAll('input[name^="items["]').forEach(function (inp) {
            if (!gradeCheckScoreInput(inp) && !bad) bad = inp;
        });
        if (bad) {
            e.preventDefault();
            e.stopImmediatePropagation();
            var modal = bad.closest('.modal');
            if (modal && window.bootstrap && !modal.classList.contains('show')) bootstrap.Modal.getOrCreateInstance(modal).show();
            setTimeout(function () { bad.focus(); bad.reportValidity(); }, modal ? 350 : 0);
        }
    }, true);
});

// After an item / Highest possible was just set up, reopen that same modal so
// the teacher can type the scores straight away.
document.addEventListener('DOMContentLoaded', function () {
    var open = new URLSearchParams(window.location.search).get('open');
    if (['ww', 'pt', 'qa'].indexOf(open) === -1) return;
    var el = document.getElementById(open + 'ItemsModal');
    if (el && window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(el).show();
    }
});

// Rebuild the live grade preview from saved item scores after every reload.
document.addEventListener('DOMContentLoaded', function () {
    <?php foreach ($section_students as $student): ?>
    calculateGrade('<?php echo (int) $student['student_id']; ?>');
    <?php endforeach; ?>
});

</script>

<?php endif; ?>

</div><!-- /.grading-page -->

<?php include '../../includes/footer.php'; ?>