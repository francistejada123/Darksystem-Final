<?php
// modules/grading/save_item_material.php
//
// The Learning Materials modal of Manage Assessment Items. The item itself
// (name, category, HPS, subject, section) is the source -- nothing about the
// assessment is typed again here. For one assessment_items row it:
//   1. saves the lesson file (PDF/Word/PowerPoint) and/or a link -- several
//      files, each with its own topic, for an Examination's coverage,
//   2. stores the lesson Topic on the item (auto-detected from the file in
//      the modal, editable) -- what Student Monitoring groups scores by,
//   3. "Send to <item> AI": saves the Start/Deadline window and opens Teacher
//      AI with that item, which generates the right assessment type from
//      the lesson. Students are added when that is sent to the class.
//      "Save only" keeps it manual (scores encoded in Grade Management).
//      Examinations are always manual.
// Mirrors the authorization and validation already used by
// modules/tutorial/save_material.php, scoped to assessment_items.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';
require_once __DIR__ . '/../../includes/assessment_material_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

function saveItemMaterialFail($section_id, $subject_code, $term, $message)
{
    $_SESSION['error_message'] = $message;
    header('Location: items.php?section_id=' . intval($section_id) . '&subject_code=' . urlencode($subject_code) . '&term=' . urlencode($term));
    exit;
}

if (!hasAnyRole(['Administrator', 'Teacher'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$teacher_id = $_SESSION['user_id'];
$item_id = intval($_POST['item_id'] ?? 0);

// Ownership check, scoped to the SAME teacher_id the rest of this module
// already requires -- an item_id can't be used to touch another teacher's item.
$item = getRecord("SELECT * FROM assessment_items WHERE item_id = ? AND teacher_id = ?", [$item_id, $teacher_id]);
if (!$item) {
    $_SESSION['error_message'] = 'That assessment item could not be found.';
    header('Location: index.php');
    exit;
}

$section_id = (int) $item['section_id'];
$subject_code = $item['subject_code'];
$term = $item['term'];

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    saveItemMaterialFail($section_id, $subject_code, $term, 'Invalid security token.');
}

// A Completed term is locked, read-only history -- same rule items.php
// already enforces for the item itself, applied here to its materials too.
$section_term_state = gh_syncSectionTerms($section_id, $item['school_year_id'], $teacher_id);
if (in_array($term, $section_term_state['completed'], true)) {
    saveItemMaterialFail($section_id, $subject_code, $term, htmlspecialchars($term) . ' is already Completed for this section, so its assessment items are locked (read-only). Nothing was changed.');
}

$clip = function ($value, $max) {
    $value = trim((string) $value);
    return $value === '' ? null : mb_substr($value, 0, $max);
};

// ---- Everything about the assessment comes from Manage Assessment Items ----
$ctx = aim_itemContext($item_id, $teacher_id);
if (!$ctx) {
    saveItemMaterialFail($section_id, $subject_code, $term, 'That assessment item could not be found.');
}
$is_exam = $ctx['kind'] === 'exam';
// send_ai  = "Send to <item> AI": save + set the window, then open Teacher AI
// manual   = save lesson + topic only (conducted in class, scores encoded by hand)
// exam     = Examination coverage (manual only -- never answered online)
$mode = $is_exam ? 'exam' : (($_POST['save_mode'] ?? '') === 'manual' ? 'manual' : 'send_ai');
$task_topic = $is_exam ? null : $clip($_POST['task_topic'] ?? '', 150);

$start_at = $end_at = null;
if ($mode === 'send_ai') {
    $parse_dt = function ($value) {
        $value = trim((string) $value);
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value) ?: DateTime::createFromFormat('Y-m-d H:i:s', $value);
        return $dt ? $dt->format('Y-m-d H:i:s') : null;
    };
    $start_at = $parse_dt($_POST['start_at'] ?? '');
    $end_at = $parse_dt($_POST['end_at'] ?? '');
    if ($start_at === null || $end_at === null) {
        saveItemMaterialFail($section_id, $subject_code, $term, 'Please set both the Start Date & Time and the Deadline before sending to ' . htmlspecialchars($item['item_name']) . ' AI.');
    }
    if ($end_at <= $start_at) {
        saveItemMaterialFail($section_id, $subject_code, $term, 'The Deadline must be later than the Start Date & Time.');
    }
}

// ---- New files (all validated first; nothing is written until every one is OK) ----
$files = [];
if (isset($_FILES['material_files']) && is_array($_FILES['material_files']['name'] ?? null)) {
    $posted_topics = (array) ($_POST['material_topics'] ?? []);
    foreach ($_FILES['material_files']['name'] as $i => $name) {
        if (($_FILES['material_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || $name === '') {
            continue;
        }
        $files[] = [
            'file' => [
                'name'     => $name,
                'type'     => $_FILES['material_files']['type'][$i] ?? '',
                'tmp_name' => $_FILES['material_files']['tmp_name'][$i] ?? '',
                'error'    => $_FILES['material_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $_FILES['material_files']['size'][$i] ?? 0,
            ],
            'topic' => $is_exam ? $clip($posted_topics[$i] ?? '', 150) : null,
        ];
    }
}
if (!$is_exam && count($files) > 1) {
    saveItemMaterialFail($section_id, $subject_code, $term, 'Attach one lesson file at a time for ' . htmlspecialchars($item['item_name']) . '. (Examinations can take several files.)');
}
foreach ($files as $k => $f) {
    $ext = strtolower(pathinfo($f['file']['name'], PATHINFO_EXTENSION));
    $targetType = tutorialMaterialTypeForExtension($ext);
    if ($targetType === null) {
        saveItemMaterialFail($section_id, $subject_code, $term, '"' . tutorialSafeDisplayFilename($f['file']['name']) . '" is not supported. Please upload a PDF, Word, or PowerPoint file.');
    }
    $validation = tutorialValidateUploadedMaterial($f['file'], $targetType);
    if (!$validation['ok']) {
        saveItemMaterialFail($section_id, $subject_code, $term, tutorialSafeDisplayFilename($f['file']['name']) . ': ' . $validation['error']);
    }
    $files[$k]['type'] = $targetType;
    $files[$k]['ext'] = $ext;
    $files[$k]['mime'] = $validation['mime'];
}

// ---- Optional link ----
$link_url = trim((string) ($_POST['material_url'] ?? ''));
if ($link_url !== '' && (!preg_match('#^https?://#i', $link_url) || mb_strlen($link_url) > 500)) {
    saveItemMaterialFail($section_id, $subject_code, $term, 'Please enter a valid link (starting with http:// or https://).');
}

$existing_materials = aim_getMaterialsForItems([$item_id])[$item_id] ?? [];
if (!$files && $link_url === '' && !$existing_materials) {
    saveItemMaterialFail($section_id, $subject_code, $term, 'Please attach the lesson material (PDF, Word, or PowerPoint file).');
}

// ---- Topic: normally already detected in the modal. If the teacher left it
//      blank, read it from the lesson file here instead of asking again. ----
if (!$is_exam && $task_topic === null) {
    $in = null;
    if ($files) {
        $in = aim_readFileForAi($files[0]['file']['tmp_name'], $files[0]['type'], $files[0]['ext']);
    } else {
        foreach ($existing_materials as $m) {
            $try = aim_materialAiInput($m);
            if ($try['ok']) { $in = $try; break; }
        }
    }
    if ($in) {
        @set_time_limit(120);
        $detected = aim_detectTopic($in, 'Assessment: ' . $item['item_name'] . ' | Subject: ' . $ctx['item']['subject_name'] . ' | ' . $ctx['item']['grade_level']);
        if ($detected['ok']) {
            $task_topic = $detected['topic'];
        }
    }
    if ($task_topic === null && !empty($item['topic'])) {
        $task_topic = $item['topic'];
    }
    if ($task_topic === null) {
        saveItemMaterialFail($section_id, $subject_code, $term, 'The topic could not be read from the lesson file. Please type the Topic (e.g. Pros and Cons – Seeing Both Sides).');
    }
}

// ---- Save the materials ----
$file_title = $clip($_POST['material_title'] ?? '', 255);
$saved = 0;
foreach ($files as $f) {
    $storedName = tutorialGenerateStoredFilename($f['file']['name']);
    $destPath = aim_materialStorageDir() . '/' . $storedName;
    if (!move_uploaded_file($f['file']['tmp_name'], $destPath)) {
        saveItemMaterialFail($section_id, $subject_code, $term, 'A file could not be saved on the server. Please try again.' . ($saved ? " ($saved file(s) were already saved.)" : ''));
    }
    $originalName = tutorialSafeDisplayFilename($f['file']['name']);
    // An examination file is titled by its own topic; a lesson file may use the optional title.
    $title = $is_exam ? $f['topic'] : $file_title;
    if (aim_addFileMaterial($item_id, $teacher_id, $f['type'], $storedName, $originalName, $f['file']['size'], $f['mime'], $title, $is_exam ? $f['topic'] : $task_topic) === false) {
        @unlink($destPath); // already moved onto disk -- don't leave an orphaned file behind
        saveItemMaterialFail($section_id, $subject_code, $term, 'A file could not be saved (a database error occurred). Please try again.');
    }
    $saved++;
}
if ($link_url !== '') {
    $link_topic = $is_exam ? $clip($_POST['link_topic'] ?? '', 150) : $task_topic;
    if (aim_addLinkMaterial($item_id, $teacher_id, $link_url, $clip($_POST['link_title'] ?? '', 255), $link_topic) === false) {
        saveItemMaterialFail($section_id, $subject_code, $term, 'The link could not be saved (a database error occurred). Please try again.');
    }
    $saved++;
}

$redirect = 'items.php?section_id=' . $section_id . '&subject_code=' . urlencode($subject_code) . '&term=' . urlencode($term);

if ($is_exam) {
    // Examination: manual only. The files describe its coverage; the item's
    // own Topic is left alone so Student Monitoring shows the exam as an
    // overall score, never as a guessed weak topic.
    logActivity($teacher_id, 'grading', 'Saved examination coverage', "'{$item['item_name']}' ($subject_code / section $section_id / $term): $saved new file(s)");
    $_SESSION['success_message'] = htmlspecialchars($item['item_name']) . ' coverage saved (' . $saved . ' new file(s)). Examinations are conducted in class; encode the scores in Grade Management.';
    header('Location: ' . $redirect);
    exit;
}

// ---- Topic on the item itself: Student Monitoring groups scores by it ----
gh_ensureAssessmentItemsClarity();
executeQuery("UPDATE assessment_items SET topic = ? WHERE item_id = ? AND teacher_id = ?", [$task_topic, $item_id, $teacher_id]);

if ($mode === 'manual') {
    logActivity($teacher_id, 'grading', 'Saved learning material', "'{$item['item_name']}' ($subject_code / section $section_id / $term): topic '$task_topic', $saved new material(s), conducted manually");
    $_SESSION['success_message'] = htmlspecialchars($item['item_name']) . ' saved with topic "' . htmlspecialchars($task_topic) . '". Conduct it in class and encode the scores in Grade Management.';
    header('Location: ' . $redirect);
    exit;
}

// ---- send_ai: keep the Start/Deadline window (students are added when the
//      generated assessment is sent to the class from Teacher AI) ----
$task_id = aim_saveTask($item, $teacher_id, [
    'assessment_type' => 'Quiz',
    'exam_term'       => null,
    'title'           => $item['item_name'],
    'topic'           => $task_topic,
    'start_at'        => $start_at,
    'end_at'          => $end_at,
]);
if ($task_id === false) {
    saveItemMaterialFail($section_id, $subject_code, $term, 'The lesson was saved, but the Start/Deadline could not be saved (a database error occurred). Please try again.');
}
// Already sent before? Its students keep the updated window automatically.
logActivity($teacher_id, 'grading', 'Sent learning material to Teacher AI', "'{$item['item_name']}' ($subject_code / section $section_id / $term): topic '$task_topic', $saved new material(s)");
$_SESSION['success_message'] = 'Lesson saved for ' . htmlspecialchars($item['item_name']) . ' (topic: "' . htmlspecialchars($task_topic) . '"). ' . htmlspecialchars($item['item_name']) . ' AI is preparing it now.';
header('Location: ' . $redirect . '&open_ai=' . $item_id);
exit;
