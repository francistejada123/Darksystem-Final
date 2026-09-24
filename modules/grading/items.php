<?php
// ============================================================
// >>> THIS FILE GOES IN:  modules/grading/items.php  <<<
// ============================================================
// modules/grading/items.php
// Manage Assessment Items — the "Excel-style" list of quizzes, written and
// oral activities, performance tasks and exams that feed the auto-computed
// Written/Oral Works / Performance Tasks / Examinations percentages.
// (Attendance is NOT an assessment item — see gh_componentGuide().)

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';
require_once __DIR__ . '/../../includes/assessment_material_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Teacher'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Manage Assessment Items';
$current_page = 'grading';
$base_url = BASE_URL;

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
if (!$active_sy) {
    include '../../includes/header.php';
    echo '<div class="alert alert-warning mt-4">⚠️ No active school year found.</div>';
    include '../../includes/footer.php';
    exit;
}

$teacher_id = $_SESSION['user_id'];
// Term Calendar (Start/End Date) is now set from Teacher Management's
// "Term Dates" button (Administrator-only), which writes the same
// term_windows table this page reads via gh_getTermWindow()/gh_setTermWindow().
// Teachers here only VIEW those dates -- see the save_term_window POST
// handler and the Term Calendar card below, both gated on $is_admin.
$is_admin = hasRole('Administrator');
$section_id = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;
$subject_code = isset($_GET['subject_code']) ? sanitize($_GET['subject_code']) : '';
$term = isset($_GET['term']) ? sanitize($_GET['term']) : getDefaultTerm();
// SERVER-SIDE grading-period validation. Only Term 1, Term 2 and Term 3 are
// accepted; anything else falls back to the default term and is never stored.
if (!isValidTerm($term)) {
    $term = getDefaultTerm();
}
$terms = getActiveTerms();

// 'QA' is the INTERNAL storage code for the third component (see the storage
// notes in grading_helpers.php). The user-facing label is always
// "Examinations", and it comes from gh_examComponentLabel().
$exam_label = gh_examComponentLabel($active_sy);
$categories = ['WW' => 'Written/Oral Works', 'PT' => 'Performance Tasks', 'QA' => $exam_label];

// Verify teacher owns this subject/section
$section_info = getRecord("SELECT * FROM sections WHERE section_id = ?", [$section_id]);
$subject_info = getRecord("
    SELECT sub.*, ts.teacher_id FROM subjects sub
    INNER JOIN teacher_subjects ts ON ts.subject_code = sub.subject_code
    WHERE sub.subject_code = ? AND ts.section_id = ? AND ts.teacher_id = ? AND ts.school_year_id = ?
", [$subject_code, $section_id, $teacher_id, $active_sy['sy_id']]);

if (!$section_info || !$subject_info) {
    header('Location: ' . BASE_URL . 'modules/grading/index.php');
    exit;
}

// ---- Term transition (see gh_syncSectionTerms() in grading_helpers.php) ----
// The class is on its CURRENT term (decided by completion, not by the
// calendar). A Completed term can still be opened here, but only as locked,
// read-only history; a term that has not been enabled yet cannot be opened.
$section_term_state = gh_syncSectionTerms($section_id, $active_sy['sy_id'], $teacher_id);
$term = $section_term_state['current'];
$requested_term = isset($_GET['term']) ? (string)$_GET['term'] : '';
if ($requested_term !== '' && in_array($requested_term, $section_term_state['completed'], true)) {
    $term = $requested_term;
}
$term_readonly = in_array($term, $section_term_state['completed'], true);

$csrf_token = generateCSRFToken();
gh_ensureAssessmentItemsClarity(); // adds assessment_items.topic (Topic/Lesson/Competency) if not there yet
$comp_guide = gh_componentGuide();   // what goes under Written/Oral Works, Performance Tasks, Examinations

// Grading rule for this subject/section, from the central helper. It tells us
// which examination subtypes are required and whether this learning area needs
// the GMRC / Values Education domain breakdown.
$section_curriculum = getLearnerCurriculumType(null, $section_info['grade_level'] ?? null, $section_id);
$weights    = getSubjectWeights($subject_info, $section_info['grade_level'] ?? null, $active_sy, $section_curriculum);
$exam_mode  = $weights['exam_mode'];
$uses_domains = false;
$subtypes   = gh_examSubtypes();
// Components of a multi-component learning area (MAPEH: Music, Arts,
// Physical Education, Health). Empty for ordinary subjects.
$components = gh_subjectComponents($subject_info, $weights['category'] ?? null);
$is_multi   = count($components) > 1;
$domains    = gh_domainOptions();

// Which examination subtypes this category actually needs.
$required_subtypes = [];
if ($exam_mode === 'standard') {
    $required_subtypes = ['ST1', 'ST2', 'TE'];
} elseif ($exam_mode === 'term_exam_only') {
    $required_subtypes = ['TE'];
}

// ============================================================
// HANDLE ADD / EDIT / DELETE ITEM
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token.';
    } elseif ($term_readonly) {
        // Completed term = locked history: no item or plan may be added, edited or removed.
        $_SESSION['error_message'] = htmlspecialchars($term) . ' is already Completed for this section, so its assessment items are locked (read-only). Nothing was changed.';
    } else {
        if (isset($_POST['add_item'])) {
            $item_name = trim(sanitize($_POST['item_name'] ?? ''));
            $category = sanitize($_POST['category'] ?? '');
            $highest_score = floatval($_POST['highest_score'] ?? 0);
            // Optional Topic / Lesson / Competency tag -- display-only metadata,
            // never read by grade computation. Blank stays NULL.
            $topic = trim(sanitize($_POST['topic'] ?? ''));
            if ($topic === '') {
                $topic = null;
            } elseif (function_exists('mb_substr')) {
                $topic = mb_substr($topic, 0, 150);
            } else {
                $topic = substr($topic, 0, 150);
            }

            // ST1 / ST2 / TE tag — only meaningful on the examinations category.
            $subtype = sanitize($_POST['assessment_subtype'] ?? '');
            if ($category !== 'QA' || !array_key_exists($subtype, $subtypes)) {
                $subtype = null;
            }

            // Cognitive / Affective / Behavioral — GMRC and Values Education only.
            $domain = sanitize($_POST['assessment_domain'] ?? '');
            if (!$uses_domains || $category === 'QA' || !array_key_exists($domain, $domains)) {
                $domain = null;
            }

            // Component tag — required on every item of a multi-component
            // learning area, so scores are never pooled across components.
            $component = sanitize($_POST['component'] ?? '');
            if (!$is_multi || !in_array($component, $components, true)) {
                $component = null;
            }

            if ($item_name === '' || !array_key_exists($category, $categories) || $highest_score <= 0) {
                $_SESSION['error_message'] = 'Please provide a valid item name, category, and highest possible score (HPS must be greater than zero).';
            } elseif ($category === 'QA' && !empty($required_subtypes) && $subtype === null) {
                $_SESSION['error_message'] = 'Select which examination this is (' . implode(' / ', $required_subtypes) . '). '
                    . 'Each one is converted to its own percentage score before they are combined, so the tag is required.';
            } elseif ($is_multi && $component === null) {
                $_SESSION['error_message'] = 'This is a multi-component learning area. Select which component this '
                    . 'assessment belongs to (' . implode(' / ', $components) . '). Each component is graded '
                    . 'separately, so an untagged item cannot be counted.';
            } elseif (($name_hint = gh_activityNameHint($category, $item_name)) && $name_hint['type'] === 'attendance') {
                // Attendance is never a grading component (DO 015, s. 2026).
                $_SESSION['error_message'] = 'Not added. ' . $name_hint['message'];
            } else {
                $order = getRecord("SELECT COALESCE(MAX(item_order), 0) as m FROM assessment_items WHERE teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ? AND category = ?",
                    [$teacher_id, $subject_code, $section_id, $active_sy['sy_id'], $term, $category]);
                $next_order = ($order['m'] ?? 0) + 1;

                executeQuery("INSERT INTO assessment_items (teacher_id, subject_code, section_id, school_year_id, term, category, item_name, topic, highest_score, item_order, assessment_subtype, assessment_domain, component)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$teacher_id, $subject_code, $section_id, $active_sy['sy_id'], $term, $category, $item_name, $topic, $highest_score, $next_order, $subtype, $domain, $component]);

                $new_item_id = (int) $db->lastInsertId();
                logActivity($teacher_id, 'Added assessment item', 'grading', "Added '$item_name' ($category) for $subject_code / section $section_id / $term");
                $_SESSION['success_message'] = 'Item added successfully!';

                // Optional "Attach file" in the Add form: saved as this item's
                // Learning Material -- same validation as the paperclip modal.
                $add_file = $_FILES['material_file'] ?? null;
                if ($new_item_id && is_array($add_file) && ($add_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $af_ext = strtolower(pathinfo($add_file['name'] ?? '', PATHINFO_EXTENSION));
                    $af_type = tutorialMaterialTypeForExtension($af_ext);
                    $af_check = $af_type ? tutorialValidateUploadedMaterial($add_file, $af_type) : ['ok' => false, 'error' => 'Please attach a PDF, Word, or PowerPoint file.'];
                    if (!$af_check['ok']) {
                        $_SESSION['success_message'] .= ' The attachment was not saved: ' . htmlspecialchars($af_check['error']) . ' You can attach it with the paperclip button.';
                    } else {
                        $af_stored = tutorialGenerateStoredFilename($add_file['name']);
                        $af_path = aim_materialStorageDir() . '/' . $af_stored;
                        if (move_uploaded_file($add_file['tmp_name'], $af_path)
                            && aim_addFileMaterial($new_item_id, $teacher_id, $af_type, $af_stored, tutorialSafeDisplayFilename($add_file['name']), $add_file['size'], $af_check['mime'], null, $topic) !== false) {
                            $_SESSION['success_message'] .= ' Attachment saved.';
                        } else {
                            @unlink($af_path);
                            $_SESSION['success_message'] .= ' The attachment could not be saved; attach it with the paperclip button.';
                        }
                    }
                }
                // Soft check only — the item is saved either way; this just points out a likely wrong component.
                if ($name_hint && $name_hint['type'] === 'misfiled') {
                    $_SESSION['success_message'] .= ' Note: ' . $name_hint['message']
                        . ' If that is a mistake, delete it and add it under the right component.';
                }
            }
        } elseif (isset($_POST['edit_item'])) {
            $item_id = intval($_POST['item_id'] ?? 0);
            $item_name = trim(sanitize($_POST['item_name'] ?? ''));
            $highest_score = floatval($_POST['highest_score'] ?? 0);
            $topic = trim(sanitize($_POST['topic'] ?? ''));
            if ($topic === '') {
                $topic = null;
            } elseif (function_exists('mb_substr')) {
                $topic = mb_substr($topic, 0, 150);
            } else {
                $topic = substr($topic, 0, 150);
            }

            $existing_item = getRecord("SELECT * FROM assessment_items WHERE item_id = ? AND teacher_id = ?", [$item_id, $teacher_id]);

            $subtype = sanitize($_POST['assessment_subtype'] ?? '');
            if (!$existing_item || $existing_item['category'] !== 'QA' || !array_key_exists($subtype, $subtypes)) {
                $subtype = null;
            }

            $domain = sanitize($_POST['assessment_domain'] ?? '');
            if (!$uses_domains || !$existing_item || $existing_item['category'] === 'QA' || !array_key_exists($domain, $domains)) {
                $domain = null;
            }

            $component = sanitize($_POST['component'] ?? '');
            if (!$is_multi || !in_array($component, $components, true)) {
                $component = $is_multi ? ($existing_item['component'] ?? null) : null;
            }

            if ($item_name === '' || $highest_score <= 0) {
                $_SESSION['error_message'] = 'Please provide a valid item name and highest possible score (HPS must be greater than zero).';
            } elseif ($existing_item && $existing_item['item_name'] !== $item_name
                      && ($edit_hint = gh_activityNameHint($existing_item['category'], $item_name)) && $edit_hint['type'] === 'attendance') {
                // Renaming to an attendance item is refused; an already-saved item is left as it is.
                $_SESSION['error_message'] = 'Not renamed. ' . $edit_hint['message'];
            } else {
                executeQuery("UPDATE assessment_items SET item_name = ?, topic = ?, highest_score = ?, assessment_subtype = ?, assessment_domain = ?, component = ? WHERE item_id = ? AND teacher_id = ?",
                    [$item_name, $topic, $highest_score, $subtype, $domain, $component, $item_id, $teacher_id]);
                $_SESSION['success_message'] = 'Item updated successfully!';
            }
        } elseif (isset($_POST['delete_item'])) {
            $item_id = intval($_POST['item_id'] ?? 0);
            executeQuery("DELETE FROM assessment_items WHERE item_id = ? AND teacher_id = ?", [$item_id, $teacher_id]);
            $_SESSION['success_message'] = 'Item removed.';
        } elseif (isset($_POST['save_term_window'])) {
            // Term calendar dates -- school-wide (one row per school year +
            // term). Now set only from Teacher Management's "Term Dates"
            // button (Administrator-only); a Teacher here can view the
            // dates but no longer edit them, so reject this even if a
            // crafted/replayed POST reaches this handler.
            if (!$is_admin) {
                $_SESSION['error_message'] = 'Only an administrator can change term dates. Ask them to use the "Term Dates" button in Teacher Management.';
            } else {
            $sw_start = trim((string) ($_POST['window_start_date'] ?? ''));
            $sw_end   = trim((string) ($_POST['window_end_date'] ?? ''));
            $date_re  = '/^\d{4}-\d{2}-\d{2}$/';
            $sw_start = ($sw_start !== '' && preg_match($date_re, $sw_start)) ? $sw_start : null;
            $sw_end   = ($sw_end !== '' && preg_match($date_re, $sw_end)) ? $sw_end : null;

            if ($sw_start !== null && $sw_end !== null && $sw_end < $sw_start) {
                $_SESSION['error_message'] = 'End Date cannot be earlier than Start Date.';
            } else {
                if (!gh_setTermWindow($active_sy['sy_id'], $term, $sw_start, $sw_end, $teacher_id)) {
                    $_SESSION['error_message'] = 'Could not save ' . htmlspecialchars($term) . ' dates: ' . htmlspecialchars($GLOBALS['gh_term_window_error'] ?: 'database error');
                } else {
                logActivity($teacher_id, 'Set term window', 'grading', "Set $term dates for school year {$active_sy['sy_name']}: "
                    . ($sw_start ?: '(no start)') . ' to ' . ($sw_end ?: '(no end)'));
                $_SESSION['success_message'] = htmlspecialchars($term) . ' dates saved.';
                }
            }
            }
        } elseif (isset($_POST['save_plan_counts'])) {
            // Configurable assessment plan -- purely informational (an "X of
            // N added" progress badge). Never limits how many items can
            // actually be added, and never read by any grade computation.
            foreach (['WW', 'PT', 'QA'] as $plan_cat) {
                $planned = intval($_POST['planned_' . strtolower($plan_cat)] ?? 0);
                gh_setAssessmentPlanCount($teacher_id, $subject_code, $section_id, $active_sy['sy_id'], $term, $plan_cat, $planned);
            }
            $_SESSION['success_message'] = 'Assessment plan updated.';
        }
    }

    header("Location: items.php?section_id=$section_id&subject_code=" . urlencode($subject_code) . "&term=" . urlencode($term));
    exit;
}

$items = getAssessmentItems($teacher_id, $subject_code, $section_id, $active_sy['sy_id'], $term);

// Learning Materials (PDF / Word / link) attached to each item above, one
// query for the whole page -- keyed by item_id, JS-friendly shape so each
// row's material button can just read its own slice via a data attribute.
$all_item_ids = array_merge(
    array_column($items['WW'], 'item_id'),
    array_column($items['PT'], 'item_id'),
    array_column($items['QA'], 'item_id')
);
$materials_by_item_raw = aim_getMaterialsForItems($all_item_ids);
$materials_by_item_js = [];
foreach ($materials_by_item_raw as $mi_item_id => $mi_materials) {
    $materials_by_item_js[$mi_item_id] = array_map(function ($m) {
        $meta = aim_materialMeta($m['resource_type']);
        $is_file = in_array($m['resource_type'], ['pdf', 'word', 'powerpoint'], true);
        return [
            'id'    => (int) $m['material_id'],
            'type'  => $m['resource_type'],
            'label' => $meta['label'],
            'icon'  => $meta['icon'],
            'title' => aim_materialDisplayTitle($m),
            'topic' => (string) ($m['topic'] ?? ''),
            'url'   => $is_file ? (BASE_URL . 'modules/grading/view_item_material.php?id=' . $m['material_id']) : $m['resource_url'],
        ];
    }, $mi_materials);
}

// Start/Deadline window of each item that was sent through "Send to <item> AI"
// (with per-status counts once it reaches the students).
$tasks_by_item = aim_getTasksForItems($all_item_ids);
$grade_section_label = aim_gradeSectionLabel($section_info['grade_level'] ?? '', $section_info['section_name']);
$category_labels_plain = ['WW' => 'Written/Oral Works', 'PT' => 'Performance Tasks', 'QA' => 'Examinations'];

// Came back from "Send to <item> AI": open that item's Teacher AI right away.
$open_ai_item = null;
if (!empty($_GET['open_ai'])) {
    $open_ctx = aim_itemContext((int) $_GET['open_ai'], $teacher_id);
    if ($open_ctx && (int) $open_ctx['item']['section_id'] === $section_id && $open_ctx['item']['subject_code'] === $subject_code) {
        $open_ai_item = ['item_id' => (int) $open_ctx['item']['item_id'], 'label' => $open_ctx['item']['item_name'] . ' AI – ' . $open_ctx['grade_section'] . ' ' . $open_ctx['item']['subject_name']];
    }
}

// Term calendar window (Start/End Date) and its real-time status -- never
// cached, computed fresh against today's date on every load.
$term_window = gh_getTermWindow($active_sy['sy_id'], $term);
$window_status = gh_termWindowStatus($term_window);

// Configurable assessment plan -- "X of N added" progress per category.
$assessment_plan = gh_getAssessmentPlan($teacher_id, $subject_code, $section_id, $active_sy['sy_id'], $term);

include '../../includes/header.php';
?>

<style>
    .cat-panel { border-radius: 8px; border: 1px solid var(--border); background: var(--surface); }
    .cat-panel .cat-head { padding: 14px 18px; border-bottom: 1px solid var(--border); display:flex; justify-content: space-between; align-items:center; }
    .cat-panel .cat-head h6 { margin:0; font-weight:600; color: var(--ink); font-size:14px; }
    .item-row { display:flex; align-items:center; justify-content: space-between; padding: 10px 18px; border-bottom: 1px solid var(--border-soft); }
    .item-row:last-child { border-bottom: none; }
    .item-row .item-name { font-weight: 600; color: var(--ink); font-size:13px; }
    .item-row .item-hps { font-family: 'IBM Plex Mono', monospace; color: var(--text-muted); font-size: 13px; }
    .add-item-form { padding: 12px 18px; background: var(--surface-hover); border-top: 1px solid var(--border); }
    .empty-items { padding: 20px 18px; text-align:center; color: var(--text-muted); font-size: 13px; }
    /* ===== Manage Assessment Items (layout) ===== */
    .mai-page { --mai-line: var(--border, #e3e8ef); --mai-card: var(--surface, #fff); --mai-soft: var(--surface-hover, #f6f8fb); --mai-ink: var(--ink, #14213d); --mai-muted: var(--text-muted, #667085); --mai-teal: #0f766e; padding-top: 6px; }
    .mai-crumbs { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; font-size: 13px; color: var(--mai-muted); margin: 6px 0 8px; }
    .mai-crumbs a { color: var(--mai-muted); text-decoration: none; }
    .mai-crumbs a:hover { color: var(--mai-ink); }
    .mai-crumbs i { font-size: 9px; opacity: .7; }
    .mai-crumbs span { color: var(--mai-ink); font-weight: 600; }
    .mai-title-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
    .mai-title-row h1 { font-size: 30px; font-weight: 800; color: var(--mai-ink); margin: 0 0 4px; letter-spacing: -.01em; }
    .mai-sub { font-size: 16px; color: var(--mai-muted); }
    .mai-sub span { margin: 0 6px; }
    .mai-head-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .mai-pill { display: inline-flex; align-items: center; gap: 10px; height: 44px; padding: 0 18px; border: 1px solid var(--mai-line); border-radius: 10px; background: var(--mai-card); font-size: 15px; font-weight: 600; color: var(--mai-ink); text-decoration: none; }
    .mai-dates { color: #1d6fd6; cursor: pointer; }
    span.mai-dates { cursor: default; }
    .mai-term { min-width: 150px; font-weight: 500; appearance: auto; }
    .mai-back:hover, .mai-dates:hover { background: var(--mai-soft); color: var(--mai-ink); }

    .mai-info { display: flex; align-items: flex-start; gap: 16px; background: #eef5fd; border: 1px solid #d7e6f8; border-radius: 12px; padding: 16px 20px; margin-bottom: 18px; }
    .mai-info-ic { flex-shrink: 0; width: 42px; height: 42px; border-radius: 50%; background: #2f7fe0; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .mai-info-text { flex: 1; min-width: 0; }
    .mai-info-text strong { font-size: 17px; color: var(--mai-ink); }
    .mai-info-text p { margin: 4px 0 0; font-size: 14.5px; color: #475569; line-height: 1.55; }
    .mai-info-text a { color: #1d6fd6; }
    .mai-learn { flex-shrink: 0; align-self: center; display: inline-flex; align-items: center; gap: 8px; border: 1px solid #b9d3f3; background: var(--mai-card); color: #1d6fd6; border-radius: 10px; padding: 8px 14px; font-size: 14px; font-weight: 700; }

    .mai-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; align-items: start; }
    @media (max-width: 1200px) { .mai-grid { grid-template-columns: 1fr; } }
    .mai-col { background: var(--mai-card); border: 1px solid var(--mai-line); border-radius: 14px; padding: 16px; box-shadow: 0 1px 3px rgba(16,24,40,.04); }
    .mai-col-head { display: flex; gap: 14px; align-items: flex-start; padding: 2px 2px 14px; }
    .mai-col-head h2 { font-size: 21px; font-weight: 800; color: var(--mai-ink); margin: 2px 0 4px; display: flex; align-items: center; gap: 10px; }
    .mai-col-head p { margin: 0; font-size: 14px; color: var(--mai-muted); line-height: 1.45; }
    .mai-count { display: inline-flex; align-items: center; justify-content: center; min-width: 30px; height: 24px; padding: 0 8px; border-radius: 6px; background: #6b7280; color: #fff; font-size: 13px; font-weight: 700; }
    .mai-col-ic { flex-shrink: 0; width: 54px; height: 54px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 22px; }
    .mai-ww { background: #e7f0fc; color: #2f7fe0; }
    .mai-pt { background: #e4f5ec; color: #1f9d55; }
    .mai-qa { background: #fde8ea; color: #e0405a; }

    .mai-items { margin-bottom: 14px; }
    .mai-empty { display: flex; flex-direction: column; align-items: center; gap: 4px; text-align: center; border: 1px solid var(--mai-line); border-radius: 12px; background: var(--mai-soft); padding: 18px 14px; }
    .mai-empty-ic { width: 52px; height: 52px; border-radius: 50%; border: 1px solid var(--mai-line); background: var(--mai-card); display: flex; align-items: center; justify-content: center; color: var(--mai-ink); font-size: 20px; margin-bottom: 6px; }
    .mai-empty strong { font-size: 15px; color: var(--mai-ink); }
    .mai-empty span { font-size: 14px; color: var(--mai-muted); }

    .mai-col .item-row { display: flex; gap: 10px; align-items: flex-start; border: 1px solid var(--mai-line); border-radius: 12px; padding: 10px 12px; margin-bottom: 8px; background: var(--mai-card); }
    .mai-col .item-row:hover { border-color: #b9d3f3; }
    .mai-row-form { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
    .mai-row-top, .mai-row-bottom { display: flex; gap: 6px; align-items: center; }
    .mai-in { width: 100%; height: 40px; border: 1px solid var(--mai-line); border-radius: 8px; padding: 0 12px; font-size: 14px; color: var(--mai-ink); background: var(--mai-card); }
    .mai-in:focus { outline: none; border-color: #2f7fe0; box-shadow: 0 0 0 3px rgba(47,127,224,.12); }
    .mai-in::placeholder { color: #98a2b3; }
    .mai-row-form .mai-in { height: 34px; font-size: 13.5px; }
    .mai-in-name { font-weight: 700; }
    .mai-in-topic { color: var(--mai-muted); }
    .mai-in-small { width: auto; flex-shrink: 0; }
    .mai-hps-in { display: flex; align-items: center; gap: 4px; flex-shrink: 0; margin: 0; }
    .mai-hps-in .mai-in { width: 72px; text-align: right; font-family: 'IBM Plex Mono', monospace; }
    .mai-hps-in span { font-size: 12px; color: var(--mai-muted); }
    .mai-row-actions { display: flex; flex-direction: column; gap: 6px; }
    .mai-icon-btn { width: 34px; height: 34px; border-radius: 8px; border: 1px solid var(--mai-line); background: var(--mai-card); color: var(--mai-muted); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; position: relative; }
    .mai-icon-btn:hover { background: var(--mai-soft); color: var(--mai-ink); }
    .mai-save { color: #1f9d55; border-color: #bfe5cf; }
    .mai-del:hover { color: #d92d20; border-color: #f4c7c3; }

    .mai-add { background: #f3f7fc; border: 1px solid #e1eaf5; border-radius: 12px; padding: 16px; }
    .mai-add h3 { font-size: 16.5px; font-weight: 700; color: var(--mai-ink); margin: 0 0 12px; }
    .mai-field { margin-bottom: 12px; }
    .mai-field > label { display: block; font-size: 14px; font-weight: 500; color: var(--mai-ink); margin-bottom: 6px; }
    .mai-field > label b { color: #e0405a; font-weight: 700; }
    .mai-field > label span { color: var(--mai-muted); }
    .mai-inline { display: grid; grid-template-columns: 96px 1fr; align-items: center; gap: 10px; }
    .mai-inline > label { margin: 0; }
    .mai-two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .mai-attach { display: flex !important; align-items: center; justify-content: center; gap: 10px; height: 40px; border: 1px solid var(--mai-line); border-radius: 8px; background: var(--mai-card); color: var(--mai-ink); font-size: 14px; font-weight: 500; cursor: pointer; margin: 0 !important; overflow: hidden; padding: 0 10px; }
    .mai-attach:hover { background: var(--mai-soft); }
    .mai-attach-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .mai-note { font-size: 12.5px; color: var(--mai-muted); margin: -2px 0 10px; }
    .mai-add-btn { width: 100%; height: 46px; border: 0; border-radius: 8px; background: var(--mai-teal); color: #fff; font-size: 16px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 10px; }
    .mai-add-btn:hover { filter: brightness(1.08); }
    .mai-foot { margin-top: 16px; background: #eef5fd; border: 1px solid #d7e6f8; border-radius: 10px; padding: 10px 16px; font-size: 14px; color: #475569; }
    .mai-foot i { color: #2f7fe0; margin-right: 6px; }
    .mai-foot a { color: var(--mai-ink); font-weight: 600; text-decoration: underline; }

    html[data-theme="dark"] .mai-info, html[data-theme="dark"] .mai-foot { background: rgba(47,127,224,.10); border-color: rgba(47,127,224,.25); }
    html[data-theme="dark"] .mai-info-text p, html[data-theme="dark"] .mai-foot { color: #c7d2e6; }
    html[data-theme="dark"] .mai-add { background: rgba(255,255,255,.03); border-color: var(--mai-line); }
    html[data-theme="dark"] .mai-ww { background: rgba(47,127,224,.18); color: #8ab4f8; }
    html[data-theme="dark"] .mai-pt { background: rgba(31,157,85,.18); color: #81c995; }
    html[data-theme="dark"] .mai-qa { background: rgba(224,64,90,.18); color: #f28b9a; }
    html[data-theme="dark"] .mai-learn { background: transparent; border-color: rgba(138,180,248,.35); color: #8ab4f8; }
    html[data-theme="dark"] .mai-dates { color: #8ab4f8; }


    /* Learning Materials icon button + badge (item-row, between Save and Delete) */
    .aim-material-btn { position:relative; border:1px solid var(--border); color:#14213D; background:transparent; }
    .aim-material-btn .aim-badge {
        position:absolute; top:-6px; right:-6px; min-width:16px; height:16px; padding:0 3px;
        border-radius:8px; background:#14213D; color:#fff; font-size:10px; font-weight:700;
        line-height:16px; text-align:center;
    }
    /* Learning Materials modal */
    .aim-modal-tabs { display:flex; gap:8px; margin-bottom:16px; }
    .aim-modal-tabs button {
        flex:1; padding:8px 12px; border-radius:6px; border:1px solid var(--border); background: var(--surface);
        color: var(--text-muted); font-size:13px; font-weight:600; cursor:pointer;
    }
    .aim-modal-tabs button.active { border-color:#14213D; background:#EDF4FB; color:#14213D; }
    .aim-modal-form label { font-weight:600; font-size:13px; color: var(--ink); margin-bottom:4px; display:block; }
    .aim-modal-form .form-control, .aim-modal-form .form-select { margin-bottom:14px; font-size:13px; }
    .aim-materials-list { margin-bottom:16px; }
    .aim-materials-list .aim-empty { color: var(--text-muted); font-size:13px; padding:8px 0; }
    .aim-material-item {
        display:flex; align-items:center; gap:10px; padding:8px 10px; border:1px solid var(--border-soft);
        border-radius:8px; margin-bottom:8px; font-size:13px;
    }
    .aim-material-item i.aim-material-icon { color:#14213D; width:16px; text-align:center; }
    .aim-material-item a { color: var(--ink); font-weight:600; text-decoration:none; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .aim-material-item a:hover { text-decoration:underline; }
    .aim-material-item .aim-material-type { color: var(--text-muted); font-size:11px; white-space:nowrap; }
    .aim-material-item .aim-remove-btn {
        border:none; background:transparent; color:#C94A4A; font-size:16px; line-height:1; cursor:pointer; padding:0 2px;
    }
    .aim-locked-note { font-size:12px; color: var(--text-muted); background: var(--surface-hover); border-radius:6px; padding:8px 10px; }

    .aim-material-item .aim-material-topic { display:block; color: var(--text-muted); font-size:11px; font-weight:400; }
    .aim-material-item .aim-material-main { flex:1; min-width:0; }
    .aim-material-item .aim-material-main a { display:block; }
    .aim-material-btn.aim-sent { border-color:#238863; color:#238863; }

    /* Lesson material setup */
    .aim-class-line { font-size:12.5px; color: var(--text-muted); background: var(--surface-hover); border-radius:6px; padding:7px 10px; margin-bottom:14px; }
    .aim-class-line strong { color: var(--ink); }
    .aim-task-status { font-size:12px; border:1px solid var(--border-soft); border-radius:8px; padding:8px 10px; margin-bottom:14px; }
    .aim-task-status .aim-pill { display:inline-block; border-radius:99px; padding:1px 8px; margin:2px 4px 2px 0; font-weight:600; font-size:11px; }
    .aim-pill.upcoming { background:#EAF2FE; color:#1D4ED8; }
    .aim-pill.active { background:#FEF3E6; color:#B54708; }
    .aim-pill.completed { background:#E9F9F1; color:#0F7B4E; }
    .aim-pill.missing { background:#FDEEEE; color:#B42318; }
    .aim-help { font-size:11.5px; color: var(--text-muted); margin:-10px 0 12px; }
    .aim-file-row { display:flex; gap:8px; align-items:flex-start; }
    .aim-file-row .form-control { margin-bottom:8px; }
    .aim-file-row .aim-file-topic { max-width:45%; }
    .aim-file-row .aim-row-remove { border:none; background:transparent; color:#C94A4A; font-size:18px; line-height:1; padding:6px 2px; }
    .aim-add-file-btn { border:1px dashed var(--border); background:transparent; color:#14213D; font-size:12px; font-weight:600; border-radius:6px; padding:5px 10px; margin-bottom:12px; }
    .aim-info { display:grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap:6px 12px; background: var(--surface-hover); border-radius:8px; padding:10px 12px; margin-bottom:14px; }
    .aim-info span { display:block; font-size:10.5px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color: var(--text-muted); }
    .aim-info strong { font-size:13px; color: var(--ink); }
    .aim-detect-btn { border:1px solid var(--border); color:#5B5FEF; background:transparent; height:38px; }
    .aim-open-ai-btn { margin-top:6px; border:1px solid #14213D; background:transparent; color:#14213D; border-radius:6px; padding:3px 10px; font-size:12px; font-weight:600; }
    .aim-open-ai-btn:hover { background:#14213D; color:#fff; }
    .aim-section-label { font-size:11px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color: var(--text-muted); margin:4px 0 8px; }
</style>

<?php
// Header values (display only)
$window_badge_colors = [
    'secondary' => ['#F2F4F7', '#475467'],
    'info'      => ['#EAF2FE', '#1D4ED8'],
    'success'   => ['#E9F9F1', '#0F7B4E'],
    'warning'   => ['#FEF3E6', '#B54708'],
];
list($wb_bg, $wb_fg) = $window_badge_colors[$window_status['badge']] ?? $window_badge_colors['secondary'];
$date_range_text = ($term_window && !empty($term_window['start_date']) && !empty($term_window['end_date']))
    ? formatDate($term_window['start_date']) . ' – ' . formatDate($term_window['end_date'])
    : 'Dates not set';
$encode_url = 'index.php?step=5&section_id=' . $section_id . '&subject_code=' . urlencode($subject_code) . '&term=' . urlencode($term);
?>
<div class="mai-page">
<nav class="mai-crumbs" aria-label="Breadcrumb">
    <a href="<?php echo BASE_URL; ?>modules/teachers/my_sections.php">My Classes</a><i class="fas fa-chevron-right"></i>
    <a href="<?php echo $encode_url; ?>"><?php echo htmlspecialchars($section_info['section_name']); ?></a><i class="fas fa-chevron-right"></i>
    <a href="<?php echo $encode_url; ?>"><?php echo htmlspecialchars($subject_info['subject_name']); ?></a><i class="fas fa-chevron-right"></i>
    <span>Manage Assessment Items</span>
</nav>

<div class="mai-title-row">
    <div>
        <h1>Manage Assessment Items</h1>
        <div class="mai-sub"><?php echo htmlspecialchars($section_info['section_name']); ?> <span>•</span> <?php echo htmlspecialchars($subject_info['subject_name']); ?> <span>•</span> <?php echo htmlspecialchars($term); ?></div>
    </div>
    <div class="mai-head-actions">
        <?php if ($is_admin): ?>
            <button type="button" class="mai-pill mai-dates" data-bs-toggle="modal" data-bs-target="#editTermWindowModal" title="<?php echo htmlspecialchars($window_status['label']); ?> -- click to edit" style="color:<?php echo $wb_fg; ?>;">
                <i class="far fa-calendar"></i><?php echo htmlspecialchars($date_range_text); ?>
            </button>
        <?php else: ?>
            <span class="mai-pill mai-dates" title="<?php echo htmlspecialchars($window_status['label']); ?>" style="color:<?php echo $wb_fg; ?>;">
                <i class="far fa-calendar"></i><?php echo htmlspecialchars($date_range_text); ?>
            </span>
        <?php endif; ?>
        <select class="mai-pill mai-term" aria-label="Term" onchange="location.href='?section_id=<?php echo $section_id; ?>&subject_code=<?php echo urlencode($subject_code); ?>&term=' + encodeURIComponent(this.value)">
            <?php foreach ($terms as $t):
                $t_state = $section_term_state['statuses'][$t] ?? 'current';
            ?>
                <option value="<?php echo $t; ?>" <?php echo $term == $t ? 'selected' : ''; ?> <?php echo $t_state === 'locked' ? 'disabled' : ''; ?>><?php
                    echo $t . ($t_state === 'completed' ? ' (Completed)' : ($t_state === 'locked' ? ' (Locked)' : ''));
                ?></option>
            <?php endforeach; ?>
        </select>
        <a href="<?php echo $encode_url; ?>" class="mai-pill mai-back"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($term_readonly): ?>
    <div class="alert" style="border-radius:10px;font-size:13px;background:#EEF2F7;color:#26364F;border:1px solid #D3DBE7;">
        <i class="fas fa-lock me-1"></i><strong><?php echo htmlspecialchars($term); ?> is Completed</strong> for this section. Its assessment items and scores are kept as locked, read-only records and cannot be added, edited or removed.
        <?php if (!$section_term_state['all_completed']): ?>This class is now on <strong><?php echo htmlspecialchars($section_term_state['current']); ?></strong>.<?php endif; ?>
    </div>
<?php endif; ?>

<div class="mai-info">
    <div class="mai-info-ic"><i class="fas fa-info"></i></div>
    <div class="mai-info-text">
        <strong>How assessment items work</strong>
        <p>Add quizzes, written or oral activities, performance tasks, and examinations here, with their Highest Possible Score (HPS). Items are grouped by category and shown in Encode Grades after you finalize <?php echo htmlspecialchars($term); ?>. Attendance is recorded separately and is not an item.</p>
        <p class="mai-info-more" id="maiInfoMore" hidden>This is where every raw score for <?php echo htmlspecialchars($term); ?> is stored, all term long. The <strong>% Score</strong> for each category (Total Raw Score / Total HPS × 100) is computed automatically, but the Initial Grade, <?php echo htmlspecialchars(gh_periodGradeLabel($active_sy)); ?> and Remarks are only generated in <a href="<?php echo $encode_url; ?>">Encode Grades</a> once you click <strong>Finalize <?php echo htmlspecialchars($term); ?></strong> there. Attendance never changes a grade.</p>
    </div>
    <button type="button" class="mai-learn" onclick="var m=document.getElementById('maiInfoMore');m.hidden=!m.hidden;this.firstChild.textContent=m.hidden?'Learn more ':'Show less ';">Learn more <i class="fas fa-up-right-from-square"></i></button>
</div>

<?php if ($is_admin): ?>
<!-- Term Calendar editing moved into this compact modal (opened from the
     date-range button in the header above) so it no longer takes up a big
     card in the middle of the page. -->
<div class="modal fade" id="editTermWindowModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius: 8px;">
      <div class="modal-header">
        <h5 class="modal-title" style="font-size:15px;font-weight:600;"><?php echo htmlspecialchars($term); ?> Calendar</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
          <input type="hidden" name="save_term_window" value="1">
          <div class="d-flex flex-wrap gap-2 mb-2">
            <div>
              <label style="font-size:11px;color: var(--text-muted);display:block;margin-bottom:3px;">Start Date</label>
              <input type="date" name="window_start_date" class="form-control form-control-sm" style="font-size:13px;border: 1px solid var(--border);border-radius:6px;" value="<?php echo htmlspecialchars($term_window['start_date'] ?? ''); ?>">
            </div>
            <div>
              <label style="font-size:11px;color: var(--text-muted);display:block;margin-bottom:3px;">End Date</label>
              <input type="date" name="window_end_date" class="form-control form-control-sm" style="font-size:13px;border: 1px solid var(--border);border-radius:6px;" value="<?php echo htmlspecialchars($term_window['end_date'] ?? ''); ?>">
            </div>
          </div>
          <div style="font-size:11.5px;color: var(--text-muted);">
            These dates apply to <?php echo htmlspecialchars($term); ?> for the whole school. Once the End Date passes, this term shows "Ready for Finalization" here and in Encode Grades — you still click Finalize yourself when you're ready; scores keep saving normally either way.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm" style="border:1px solid var(--border);color:var(--ink);background:var(--surface);" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm" style="background:#14213D;color:#fff;border:none;">Save Dates</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$mai_meta = [
    'WW' => ['icon' => 'fa-file-lines', 'tone' => 'ww', 'desc' => 'Quizzes, written activities, oral activities (recitation), short reports, and other oral responses.', 'add' => 'Add Written / Oral Work', 'empty' => 'Add your quizzes, written or oral activities here.', 'ph' => 'e.g. Quiz 1, Recitation on Chapter 1'],
    'PT' => ['icon' => 'fa-folder', 'tone' => 'pt', 'desc' => 'Projects, demonstrations, presentations, portfolios, and other performance-based tasks.', 'add' => 'Add Performance Task', 'empty' => 'Add your performance tasks here.', 'ph' => 'e.g. PETA 1, Project, Demonstration'],
    'QA' => ['icon' => 'fa-file-signature', 'tone' => 'qa', 'desc' => 'Summative Test 1, Summative Test 2, and Term Examination.', 'add' => 'Add Examination', 'empty' => 'Add your examinations here.', 'ph' => 'e.g. Summative Test 1'],
];
?>
<div class="mai-grid">
    <?php foreach ($categories as $cat_key => $cat_label):
        $mm = $mai_meta[$cat_key];
        $added = count($items[$cat_key]);
        $planned = $assessment_plan[$cat_key];
    ?>
        <section class="mai-col cat-panel">
            <header class="mai-col-head">
                <span class="mai-col-ic mai-<?php echo $mm['tone']; ?>"><i class="far <?php echo $mm['icon']; ?>"></i></span>
                <div>
                    <h2><?php echo $cat_key === 'WW' ? 'Written / Oral Works' : htmlspecialchars($cat_label); ?> <span class="mai-count"><?php echo $planned > 0 ? ($added . ' of ' . $planned) : $added; ?></span></h2>
                    <p><?php echo htmlspecialchars($mm['desc']); ?></p>
                </div>
            </header>

            <div class="mai-items">
            <?php if (empty($items[$cat_key])): ?>
                <div class="mai-empty">
                    <span class="mai-empty-ic"><i class="far <?php echo $mm['icon']; ?>"></i></span>
                    <strong>No items yet.</strong>
                    <span><?php echo htmlspecialchars($mm['empty']); ?></span>
                </div>
            <?php else: ?>
                <?php foreach ($items[$cat_key] as $item): ?>
                    <div class="item-row">
                        <form method="POST" class="mai-row-form" onsubmit="return true;">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="edit_item" value="1">
                            <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                            <div class="mai-row-top">
                                <input type="text" name="item_name" class="mai-in mai-in-name" value="<?php echo htmlspecialchars($item['item_name']); ?>" aria-label="Title">
                                <label class="mai-hps-in"><input type="number" name="highest_score" class="mai-in" value="<?php echo rtrim(rtrim($item['highest_score'], '0'), '.'); ?>" step="0.01" min="0.01" aria-label="HPS"><span>pts</span></label>
                            </div>
                            <div class="mai-row-bottom">
                                <input type="text" name="topic" class="mai-in mai-in-topic" value="<?php echo htmlspecialchars($item['topic'] ?? ''); ?>" placeholder="Topic / Lesson / Competency" title="Topic / Lesson / Competency (optional)" maxlength="150">
                                <?php if ($is_multi): ?>
                                    <select name="component" class="mai-in mai-in-small">
                                        <?php if (!empty($item['component']) && !in_array($item['component'], $components, true)): ?>
                                            <option value="<?php echo htmlspecialchars($item['component']); ?>" selected><?php echo htmlspecialchars($item['component']); ?> (old)</option>
                                        <?php endif; ?>
                                        <?php foreach ($components as $comp): ?>
                                            <option value="<?php echo htmlspecialchars($comp); ?>" <?php echo (($item['component'] ?? '') === $comp) ? 'selected' : ''; ?>><?php echo htmlspecialchars($comp); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <?php if ($item['category'] === 'QA' && !empty($required_subtypes)): ?>
                                    <select name="assessment_subtype" class="mai-in mai-in-small">
                                        <?php foreach ($required_subtypes as $st): ?>
                                            <option value="<?php echo $st; ?>" <?php echo (($item['assessment_subtype'] ?? '') === $st) ? 'selected' : ''; ?>><?php echo $st; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <button type="submit" class="mai-icon-btn mai-save" title="Save changes"><i class="fas fa-check"></i></button>
                            </div>
                        </form>
                        <?php
                            $item_materials = $materials_by_item_js[$item['item_id']] ?? [];
                            $item_material_count = count($item_materials);
                            $item_task = $tasks_by_item[(int) $item['item_id']] ?? null;
                            $item_kind = aim_assessmentKind($item);
                            $item_task_js = $item_task ? [
                                'start_at'  => date('Y-m-d\TH:i', strtotime($item_task['start_at'])),
                                'end_at'    => date('Y-m-d\TH:i', strtotime($item_task['end_at'])),
                                'window'    => aim_formatDateTime($item_task['start_at']) . ' – ' . aim_formatDateTime($item_task['end_at']),
                                'counts'    => $item_task['counts'],
                            ] : null;
                        ?>
                        <div class="mai-row-actions">
                            <button type="button" class="mai-icon-btn aim-material-btn<?php echo ($item_task && $item_task['counts']['total'] > 0) ? ' aim-sent' : ''; ?>"
                                    title="<?php echo ($item_task && $item_task['counts']['total'] > 0) ? 'Learning Materials (sent to students)' : 'Learning Materials'; ?>" aria-label="Learning Materials"
                                    data-item-id="<?php echo (int) $item['item_id']; ?>"
                                    data-item-name="<?php echo htmlspecialchars($item['item_name']); ?>"
                                    data-item-topic="<?php echo htmlspecialchars($item_kind === 'exam' ? '' : ($item['topic'] ?? '')); ?>"
                                    data-kind="<?php echo $item_kind; ?>"
                                    data-kind-label="<?php echo htmlspecialchars(aim_kindMeta($item_kind)['label']); ?>"
                                    data-category-label="<?php echo htmlspecialchars($category_labels_plain[$item['category']] ?? $item['category']); ?>"
                                    data-hps="<?php echo htmlspecialchars(rtrim(rtrim($item['highest_score'], '0'), '.')); ?>"
                                    data-button-label="<?php echo htmlspecialchars('Send to ' . $item['item_name'] . ' AI – ' . $grade_section_label . ' ' . $subject_info['subject_name']); ?>"
                                    data-ai-label="<?php echo htmlspecialchars($item['item_name'] . ' AI – ' . $grade_section_label . ' ' . $subject_info['subject_name']); ?>"
                                    data-task='<?php echo htmlspecialchars(json_encode($item_task_js), ENT_QUOTES); ?>'
                                    data-materials='<?php echo htmlspecialchars(json_encode($item_materials), ENT_QUOTES); ?>'>
                                <i class="fas fa-paperclip"></i>
                                <?php if ($item_material_count > 0): ?><span class="aim-badge"><?php echo $item_material_count; ?></span><?php endif; ?>
                            </button>
                            <form method="POST" onsubmit="return confirm('Remove this item? Associated scores will also be deleted.');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="delete_item" value="1">
                                <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                                <button type="submit" class="mai-icon-btn mai-del" title="Delete"><i class="far fa-trash-can"></i></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>

            <form method="POST" class="add-item-form mai-add" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="add_item" value="1">
                <input type="hidden" name="category" value="<?php echo $cat_key; ?>">
                <h3><?php echo htmlspecialchars($mm['add']); ?></h3>

                <?php if ($cat_key === 'WW'): ?>
                    <datalist id="wwItemSuggestions">
                        <?php foreach (gh_writtenWorkSuggestions() as $ww_hint): ?><option value="<?php echo htmlspecialchars($ww_hint); ?>"></option><?php endforeach; ?>
                    </datalist>
                    <?php
                        // "Type" only helps fill in the title (e.g. "Quiz 3").
                        $ww_next = [];
                        foreach (['Quiz', 'Written Activity', 'Recitation', 'Oral Activity', 'Short Report', 'Seatwork'] as $wt) {
                            $n = 1;
                            foreach ($items['WW'] as $it) { if (preg_match('/^' . preg_quote($wt, '/') . '\s*(\d+)/i', $it['item_name'], $mt)) $n = max($n, (int) $mt[1] + 1); }
                            $ww_next[$wt] = $wt . ' ' . $n;
                        }
                    ?>
                    <div class="mai-field mai-inline">
                        <label>Type <b>*</b></label>
                        <select class="mai-in" data-title-fill='<?php echo htmlspecialchars(json_encode($ww_next), ENT_QUOTES); ?>'>
                            <?php foreach (array_keys($ww_next) as $wt): ?><option><?php echo $wt; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                <?php elseif ($cat_key === 'PT'): ?>
                    <datalist id="ptItemSuggestions">
                        <?php foreach (gh_performanceTaskSuggestions() as $pt_hint): ?><option value="<?php echo htmlspecialchars($pt_hint); ?>"></option><?php endforeach; ?>
                    </datalist>
                <?php elseif ($cat_key === 'QA' && !empty($required_subtypes)): ?>
                    <!-- ST1 / ST2 / TE. Each is converted to its own percentage score before they are combined 30% / 30% / 40%. -->
                    <div class="mai-field mai-inline">
                        <label>Exam Type <b>*</b></label>
                        <select name="assessment_subtype" class="mai-in" required data-title-from-label="1">
                            <?php foreach ($required_subtypes as $i_st => $st): ?>
                                <option value="<?php echo $st; ?>" <?php echo $i_st === 0 ? 'selected' : ''; ?>><?php echo htmlspecialchars(preg_replace('/^\w+\s*[–-]\s*/', '', $subtypes[$st]['label'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="mai-field <?php echo $cat_key === 'PT' ? '' : 'mai-inline'; ?>">
                    <label>Title <b>*</b></label>
                    <input type="text" name="item_name" class="mai-in" placeholder="<?php echo htmlspecialchars($mm['ph']); ?>"
                           <?php echo $cat_key === 'PT' ? 'list="ptItemSuggestions"' : ($cat_key === 'WW' ? 'list="wwItemSuggestions"' : ''); ?> required>
                </div>

                <div class="mai-field">
                    <label>Topic / Lesson / Competency <span>(optional)</span></label>
                    <input type="text" name="topic" class="mai-in" placeholder="e.g. Key Concepts / MELC Code" maxlength="150">
                </div>

                <?php if ($is_multi): ?>
                    <!-- Multi-component learning area (e.g. MAPEH): every item says which component it belongs to. -->
                    <div class="mai-field">
                        <label>Component <b>*</b></label>
                        <select name="component" class="mai-in" required>
                            <option value="">Select...</option>
                            <?php foreach ($components as $comp): ?><option value="<?php echo htmlspecialchars($comp); ?>"><?php echo htmlspecialchars($comp); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="mai-two">
                    <div class="mai-field">
                        <label>Highest Possible Score (HPS) <b>*</b></label>
                        <input type="number" name="highest_score" class="mai-in" value="100" step="0.01" min="0.01" required>
                    </div>
                    <div class="mai-field">
                        <label>Attachment <span>(optional)</span></label>
                        <label class="mai-attach"><i class="fas fa-paperclip"></i><span class="mai-attach-name">Attach file</span>
                            <input type="file" name="material_file" accept=".pdf,.doc,.docx,.ppt,.pptx" hidden onchange="this.parentNode.querySelector('.mai-attach-name').textContent = this.files[0] ? this.files[0].name : 'Attach file';">
                        </label>
                    </div>
                </div>

                <?php if ($cat_key === 'PT'): ?>
                    <p class="mai-note"><i class="fas fa-hand-pointer me-1"></i>Teacher-encoded: scored by a rubric. AI does not compute Performance Tasks.</p>
                <?php endif; ?>

                <button type="submit" class="mai-add-btn"><i class="fas fa-plus"></i> Add Item</button>
            </form>
        </section>
    <?php endforeach; ?>
</div>

<div class="mai-foot"><i class="fas fa-circle-info"></i> Scores and grade computation appear in <a href="<?php echo $encode_url; ?>">Encode Grades</a> after <?php echo htmlspecialchars($term); ?> is finalized.</div>
</div><!-- /.mai-page -->

<script>
// "Type" / "Exam Type" fill in the title for you (still editable).
document.querySelectorAll('.mai-add').forEach(function (f) {
    var title = f.querySelector('[name="item_name"]');
    var auto = true;
    title.addEventListener('input', function () { auto = title.value === ''; });
    var typeSel = f.querySelector('[data-title-fill]');
    if (typeSel) {
        var map = JSON.parse(typeSel.getAttribute('data-title-fill'));
        var fill = function () { if (auto) title.value = map[typeSel.value] || ''; };
        typeSel.addEventListener('change', fill);
        fill();
    }
    var exSel = f.querySelector('[data-title-from-label]');
    if (exSel) {
        var fillEx = function () { if (auto) title.value = exSel.options[exSel.selectedIndex].text; };
        exSel.addEventListener('change', fillEx);
        fillEx();
    }
});
</script>

<!-- Add Learning Material modal -- one shared modal for every item row;
     JS fills it in per-item when a row's paperclip button is clicked. -->
<div class="modal fade" id="aimMaterialModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:12px;border:none;">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="aimMaterialModalTitle" style="font-size:15px;">Learning Materials</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="aimTaskStatus" class="aim-task-status" style="display:none;"></div>
        <div id="aimMaterialsList" class="aim-materials-list"></div>

        <?php if ($term_readonly): ?>
            <div class="aim-locked-note"><i class="fas fa-lock me-1"></i><?php echo htmlspecialchars($term); ?> is Completed for this section -- materials cannot be added or removed.</div>
        <?php else: ?>
        <form method="POST" action="save_item_material.php" enctype="multipart/form-data" id="aimTaskForm" class="aim-modal-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="item_id" id="aimTaskItemId" value="">
            <input type="hidden" name="save_mode" id="aimSaveMode" value="send_ai">

            <!-- Everything here comes from Manage Assessment Items -- read only. -->
            <div class="aim-info">
                <div><span>Assessment</span><strong id="aimInfoItem"></strong></div>
                <div><span>Subject</span><strong><?php echo htmlspecialchars($subject_info['subject_name']); ?></strong></div>
                <div><span>Grade/Section</span><strong><?php echo htmlspecialchars($grade_section_label); ?></strong></div>
                <div><span>Category</span><strong id="aimInfoCategory"></strong></div>
                <div><span>HPS</span><strong id="aimInfoHps"></strong></div>
            </div>

            <div id="aimExamNote" class="aim-class-line" style="display:none;"><i class="fas fa-clipboard-check me-1"></i>Examinations are <strong>manual only</strong>: conducted in class and encoded in Grade Management. Attach the lesson files this examination covers, one topic per file.</div>

            <div class="aim-section-label">Lesson material</div>
            <div class="aim-modal-tabs">
                <button type="button" id="aimTabUploadBtn" class="active" onclick="aimSwitchTab('upload')">
                    <i class="fas fa-upload me-1"></i> Upload File
                </button>
                <button type="button" id="aimTabLinkBtn" onclick="aimSwitchTab('link')">
                    <i class="fas fa-link me-1"></i> Link <span class="fw-normal">(optional)</span>
                </button>
            </div>

            <div id="aimUploadPane">
                <label id="aimFilesLabel">Upload PDF / Word / PowerPoint</label>
                <div id="aimFileRows"></div>
                <button type="button" class="aim-add-file-btn" id="aimAddFileBtn"><i class="fas fa-plus me-1"></i>Add another file</button>
                <div class="form-text mb-2">Accepted: PDF, Word (.doc/.docx), PowerPoint (.ppt/.pptx). Max size 15MB each.</div>
            </div>

            <div id="aimLinkPane" style="display:none;">
                <label for="aimLinkTitle">Link Title <span class="text-muted fw-normal">(optional)</span></label>
                <input type="text" name="link_title" id="aimLinkTitle" class="form-control" placeholder="e.g. Pros and Cons Explained">
                <label for="aimLinkUrl">Link (YouTube video, Google Docs, Google Slides, ...)</label>
                <input type="url" name="material_url" id="aimLinkUrl" class="form-control" placeholder="https://www.youtube.com/watch?v=... or https://docs.google.com/...">
                <div id="aimLinkTopicWrap">
                    <label for="aimLinkTopic">Topic of this link <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" name="link_topic" id="aimLinkTopic" class="form-control" maxlength="150" placeholder="e.g. Fact vs Opinion">
                </div>
            </div>

            <div id="aimLessonFields">
                <label for="aimTaskTopic">Topic <span class="text-muted fw-normal" id="aimTopicState">(detected from the lesson file — you can edit it)</span></label>
                <div class="d-flex gap-2">
                    <input type="text" name="task_topic" id="aimTaskTopic" class="form-control" maxlength="150" placeholder="Detected automatically when you choose the lesson file">
                    <button type="button" class="btn btn-sm aim-detect-btn" id="aimDetectBtn" title="Read the topic from the attached lesson"><i class="fas fa-wand-magic-sparkles"></i></button>
                </div>
                <div class="aim-help" id="aimTopicHelp">Saved with this assessment. Student Monitoring and AI Recommendation use it for low scores.</div>

                <div class="row g-2">
                    <div class="col-6">
                        <label for="aimStartAt">Start Date &amp; Time</label>
                        <input type="datetime-local" name="start_at" id="aimStartAt" class="form-control">
                    </div>
                    <div class="col-6">
                        <label for="aimEndAt">Deadline / End Date &amp; Time</label>
                        <input type="datetime-local" name="end_at" id="aimEndAt" class="form-control">
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end flex-wrap gap-2 mt-1">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-outline-secondary" id="aimSaveManualBtn" data-mode="manual" title="Conducted in class: save the lesson and topic only, then encode scores in Grade Management">Save only (manual)</button>
                <button type="submit" class="btn" id="aimSendAiBtn" data-mode="send_ai" style="background:#14213D;color:#fff;font-weight:600;"><i class="fas fa-robot me-1"></i><span id="aimSendAiLabel">Send to AI</span></button>
                <button type="submit" class="btn" id="aimSaveExamBtn" data-mode="exam" style="background:#14213D;color:#fff;font-weight:600;display:none;"><i class="fas fa-floppy-disk me-1"></i>Save Examination Coverage</button>
            </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Hidden form used to submit a Remove action from the materials list above -->
<form method="POST" action="remove_item_material.php" id="aimRemoveForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
    <input type="hidden" name="material_id" id="aimRemoveMaterialId" value="">
</form>

<script>
var AIM_LOCKED = <?php echo $term_readonly ? 'true' : 'false'; ?>;
var AIM_CSRF = <?php echo json_encode($csrf_token); ?>;
var AIM_OPEN_AI = <?php echo json_encode($open_ai_item); ?>;
var aimCurrent = { itemId: 0, kind: '', itemName: '', label: '', topicTouched: false };

function aimSwitchTab(which) {
    var uploadPane = document.getElementById('aimUploadPane');
    var linkPane = document.getElementById('aimLinkPane');
    var uploadBtn = document.getElementById('aimTabUploadBtn');
    var linkBtn = document.getElementById('aimTabLinkBtn');
    if (!uploadPane || !linkPane) return;
    uploadPane.style.display = which === 'upload' ? '' : 'none';
    linkPane.style.display = which === 'upload' ? 'none' : '';
    uploadBtn.classList.toggle('active', which === 'upload');
    linkBtn.classList.toggle('active', which !== 'upload');
}

function aimIsExam() { return aimCurrent.kind === 'exam'; }

// Read the topic of a lesson file (just chosen, or already attached) and put
// it in the Topic field. The teacher can still edit it.
function aimDetectTopic(opts) {
    var state = document.getElementById('aimTopicState');
    var target = opts.target || document.getElementById('aimTaskTopic');
    var body = new FormData();
    body.append('csrf_token', AIM_CSRF);
    body.append('item_id', aimCurrent.itemId);
    if (opts.file) body.append('material_file', opts.file);
    if (opts.materialId) body.append('material_id', opts.materialId);
    var original = target.placeholder;
    target.placeholder = 'Reading the lesson…';
    if (state && !opts.target) state.textContent = '(reading the lesson file…)';
    return fetch('detect_material_topic.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            target.placeholder = original;
            if (data && data.success && data.topic) {
                target.value = data.topic;
                if (state && !opts.target) state.textContent = '(detected from the lesson file — you can edit it)';
            } else if (state && !opts.target) {
                state.textContent = '(' + ((data && data.error) || 'could not detect — please type it') + ')';
            }
        })
        .catch(function () {
            target.placeholder = original;
            if (state && !opts.target) state.textContent = '(could not detect — please type it)';
        });
}

// One row = one file input (+ its own topic for an Examination). The file
// and topic inputs are posted as parallel arrays, so every row has both.
function aimAddFileRow() {
    var rows = document.getElementById('aimFileRows');
    var row = document.createElement('div');
    row.className = 'aim-file-row';
    row.innerHTML = '<input type="file" name="material_files[]" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx">'
        + '<input type="text" name="material_topics[]" class="form-control aim-file-topic" maxlength="150" placeholder="Topic of this file">'
        + '<button type="button" class="aim-row-remove" title="Remove this file">&times;</button>';
    var fileEl = row.querySelector('input[type=file]');
    var topicEl = row.querySelector('.aim-file-topic');
    fileEl.addEventListener('change', function () {
        if (!fileEl.files || !fileEl.files[0]) return;
        if (aimIsExam()) {
            if (!topicEl.value) aimDetectTopic({ file: fileEl.files[0], target: topicEl });
        } else if (!aimCurrent.topicTouched) {
            aimDetectTopic({ file: fileEl.files[0] });
        }
    });
    row.querySelector('.aim-row-remove').addEventListener('click', function () {
        if (rows.children.length > 1) { row.remove(); } else { fileEl.value = ''; topicEl.value = ''; }
    });
    rows.appendChild(row);
    aimApplyKind();
}

// Examination: several files, a topic per file, manual only.
// Everything else: one lesson file, one detected topic, a Start/Deadline,
// and "Send to <item> AI" (or Save only for a manual activity).
function aimApplyKind() {
    var exam = aimIsExam();
    document.getElementById('aimExamNote').style.display = exam ? '' : 'none';
    document.getElementById('aimLessonFields').style.display = exam ? 'none' : '';
    document.getElementById('aimAddFileBtn').style.display = exam ? '' : 'none';
    document.getElementById('aimLinkTopicWrap').style.display = exam ? '' : 'none';
    document.getElementById('aimFilesLabel').textContent = exam ? 'Examination coverage — PDF / Word / PowerPoint, one per topic' : 'Upload PDF / Word / PowerPoint';
    document.getElementById('aimSendAiBtn').style.display = exam ? 'none' : '';
    document.getElementById('aimSaveManualBtn').style.display = exam ? 'none' : '';
    document.getElementById('aimSaveExamBtn').style.display = exam ? '' : 'none';
    var rows = document.getElementById('aimFileRows');
    if (!exam) {
        while (rows.children.length > 1) rows.lastChild.remove();
    }
    rows.querySelectorAll('.aim-file-topic').forEach(function (el) { el.style.display = exam ? '' : 'none'; });
    rows.querySelectorAll('.aim-row-remove').forEach(function (el) { el.style.display = exam ? '' : 'none'; });
}

function aimOpenAi(itemId, label) {
    if (window.TeacherAI && typeof window.TeacherAI.startAssessment === 'function') {
        window.TeacherAI.startAssessment(itemId, label);
    } else {
        alert('Teacher AI is not available on this page.');
    }
}

function aimRenderTaskStatus(task) {
    var el = document.getElementById('aimTaskStatus');
    if (!el) return;
    if (!task) { el.style.display = 'none'; el.innerHTML = ''; return; }
    var c = task.counts || {};
    el.innerHTML = '';
    var head = document.createElement('div');
    head.style.marginBottom = '4px';
    head.innerHTML = '<i class="far fa-clock me-1" style="color:#238863;"></i><strong></strong> <span></span>';
    head.querySelector('strong').textContent = c.total ? 'Sent to ' + c.total + ' student(s)' : 'Not sent to students yet';
    head.querySelector('span').textContent = '· ' + task.window;
    el.appendChild(head);
    if (c.total) {
        [['upcoming', 'Upcoming'], ['active', 'Active'], ['completed', 'Completed'], ['missing', 'Missing']].forEach(function (p) {
            var pill = document.createElement('span');
            pill.className = 'aim-pill ' + p[0];
            pill.textContent = p[1] + ': ' + (c[p[1]] || 0);
            el.appendChild(pill);
        });
    }
    var open = document.createElement('button');
    open.type = 'button';
    open.className = 'aim-open-ai-btn';
    open.innerHTML = '<i class="fas fa-robot"></i> ';
    open.appendChild(document.createTextNode('Open ' + aimCurrent.itemName + ' AI'));
    open.addEventListener('click', function () {
        var m = document.getElementById('aimMaterialModal');
        if (m && window.bootstrap) bootstrap.Modal.getOrCreateInstance(m).hide();
        aimOpenAi(aimCurrent.itemId, aimCurrent.label);
    });
    el.appendChild(document.createElement('br'));
    el.appendChild(open);
    el.style.display = '';
}

function aimRenderMaterials(materials) {
    var listEl = document.getElementById('aimMaterialsList');
    listEl.innerHTML = '';
    if (!materials.length) {
        var empty = document.createElement('div');
        empty.className = 'aim-empty';
        empty.textContent = 'No lesson material yet.';
        listEl.appendChild(empty);
        return;
    }
    materials.forEach(function (m) {
        var row = document.createElement('div');
        row.className = 'aim-material-item';

        var icon = document.createElement('i');
        icon.className = 'fas ' + m.icon + ' aim-material-icon';
        row.appendChild(icon);

        var main = document.createElement('div');
        main.className = 'aim-material-main';
        var link = document.createElement('a');
        link.href = m.url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = m.title || m.label;
        main.appendChild(link);
        if (m.topic) {
            var topic = document.createElement('span');
            topic.className = 'aim-material-topic';
            topic.textContent = 'Topic: ' + m.topic;
            main.appendChild(topic);
        }
        row.appendChild(main);

        var typeBadge = document.createElement('span');
        typeBadge.className = 'aim-material-type';
        typeBadge.textContent = m.label;
        row.appendChild(typeBadge);

        if (!AIM_LOCKED) {
            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'aim-remove-btn';
            removeBtn.title = 'Remove';
            removeBtn.innerHTML = '&times;';
            removeBtn.addEventListener('click', function () {
                if (!confirm('Remove "' + (m.title || m.label) + '"?')) return;
                document.getElementById('aimRemoveMaterialId').value = m.id;
                document.getElementById('aimRemoveForm').submit();
            });
            row.appendChild(removeBtn);
        }

        listEl.appendChild(row);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    // bootstrap.bundle.min.js is loaded lower down (includes/footer.php) --
    // waiting for DOMContentLoaded guarantees it has already run.
    if (typeof bootstrap === 'undefined') return;

    var modalEl = document.getElementById('aimMaterialModal');
    var modal = modalEl ? new bootstrap.Modal(modalEl) : null;
    var lastMaterials = [];

    var addFileBtn = document.getElementById('aimAddFileBtn');
    if (addFileBtn) addFileBtn.addEventListener('click', aimAddFileRow);
    var topicEl = document.getElementById('aimTaskTopic');
    if (topicEl) topicEl.addEventListener('input', function () { aimCurrent.topicTouched = topicEl.value !== ''; });
    var detectBtn = document.getElementById('aimDetectBtn');
    if (detectBtn) detectBtn.addEventListener('click', function () {
        var fileEl = document.querySelector('#aimFileRows input[type=file]');
        if (fileEl && fileEl.files && fileEl.files[0]) { aimDetectTopic({ file: fileEl.files[0] }); return; }
        var readable = lastMaterials.filter(function (m) { return m.type !== 'link'; });
        if (readable.length) { aimDetectTopic({ materialId: readable[readable.length - 1].id }); return; }
        alert('Choose the lesson file first.');
    });

    var taskForm = document.getElementById('aimTaskForm');
    if (taskForm) {
        taskForm.querySelectorAll('button[type=submit][data-mode]').forEach(function (b) {
            b.addEventListener('click', function () { document.getElementById('aimSaveMode').value = b.getAttribute('data-mode'); });
        });
        taskForm.addEventListener('submit', function (e) {
            var mode = document.getElementById('aimSaveMode').value;
            if (mode !== 'send_ai') return;
            var start = document.getElementById('aimStartAt').value, end = document.getElementById('aimEndAt').value;
            if (!start || !end) {
                e.preventDefault();
                alert('Set the Start Date & Time and the Deadline first.');
            } else if (end <= start) {
                e.preventDefault();
                alert('The Deadline must be later than the Start Date & Time.');
            }
        });
    }

    document.querySelectorAll('.aim-material-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var itemId = btn.getAttribute('data-item-id');
            var itemName = btn.getAttribute('data-item-name') || '';
            var materials = [];
            try { materials = JSON.parse(btn.getAttribute('data-materials') || '[]'); } catch (e) { materials = []; }
            lastMaterials = materials;
            var task = null;
            try { task = JSON.parse(btn.getAttribute('data-task') || 'null'); } catch (e) { task = null; }

            aimCurrent = {
                itemId: itemId,
                kind: btn.getAttribute('data-kind') || 'written_work',
                itemName: itemName,
                label: btn.getAttribute('data-ai-label') || itemName,
                topicTouched: false
            };

            document.getElementById('aimMaterialModalTitle').textContent =
                'Learning Materials' + (itemName ? ' — ' + itemName : '');

            var form = document.getElementById('aimTaskForm');
            if (form) {
                form.reset();
                document.getElementById('aimTaskItemId').value = itemId;
                document.getElementById('aimInfoItem').textContent = itemName + ' (' + (btn.getAttribute('data-kind-label') || '') + ')';
                document.getElementById('aimInfoCategory').textContent = btn.getAttribute('data-category-label') || '';
                document.getElementById('aimInfoHps').textContent = btn.getAttribute('data-hps') || '';
                document.getElementById('aimSendAiLabel').textContent = btn.getAttribute('data-button-label') || 'Send to AI';
                document.getElementById('aimTaskTopic').value = btn.getAttribute('data-item-topic') || '';
                aimCurrent.topicTouched = !!document.getElementById('aimTaskTopic').value;
                document.getElementById('aimTopicState').textContent = '(detected from the lesson file — you can edit it)';
                document.getElementById('aimStartAt').value = task ? task.start_at : '';
                document.getElementById('aimEndAt').value = task ? task.end_at : '';
                document.getElementById('aimFileRows').innerHTML = '';
                aimAddFileRow();
                aimApplyKind();
                aimSwitchTab('upload');
            }

            aimRenderTaskStatus(task);
            aimRenderMaterials(materials);
            if (modal) { modal.show(); }
        });
    });

    // Just clicked "Send to <item> AI": open that item's AI right away.
    if (AIM_OPEN_AI) {
        try { history.replaceState(null, '', location.pathname + location.search.replace(/([?&])open_ai=\d+&?/, '$1').replace(/[?&]$/, '')); } catch (e) {}
        aimOpenAi(AIM_OPEN_AI.item_id, AIM_OPEN_AI.label);
    }
});
</script>

<?php if ($term_readonly): ?>
<script>
// Completed term: read-only history. The server rejects add/edit/delete for it as well.
document.querySelectorAll('.cat-panel form').forEach(function (f) {
    if (f.classList.contains('add-item-form')) { f.style.display = 'none'; return; }
    f.querySelectorAll('input:not([type=hidden]), select').forEach(function (el) { el.readOnly = true; el.disabled = true; });
    f.querySelectorAll('button[type=submit]').forEach(function (b) { b.style.display = 'none'; });
});
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>