<?php
// modules/grading/remove_item_material.php
//
// Removes one Learning Material from an assessment_items row. Deletes the
// row and, if it was an uploaded file, the file on disk.

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$teacher_id = $_SESSION['user_id'];
$material_id = intval($_POST['material_id'] ?? 0);

$material = aim_getMaterial($material_id);
if (!$material) {
    header('Location: index.php');
    exit;
}

// Ownership check via the parent item -- same teacher_id boundary as every
// other write in this module.
$item = getRecord("SELECT * FROM assessment_items WHERE item_id = ? AND teacher_id = ?", [$material['item_id'], $teacher_id]);
if (!$item) {
    header('Location: index.php');
    exit;
}

$section_id = (int) $item['section_id'];
$subject_code = $item['subject_code'];
$term = $item['term'];
$redirect = 'items.php?section_id=' . $section_id . '&subject_code=' . urlencode($subject_code) . '&term=' . urlencode($term);

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['error_message'] = 'Invalid security token.';
    header('Location: ' . $redirect);
    exit;
}

$section_term_state = gh_syncSectionTerms($section_id, $item['school_year_id'], $teacher_id);
if (in_array($term, $section_term_state['completed'], true)) {
    $_SESSION['error_message'] = htmlspecialchars($term) . ' is already Completed for this section, so its assessment items are locked (read-only). Nothing was changed.';
    header('Location: ' . $redirect);
    exit;
}

aim_deleteMaterial($material);
logActivity($teacher_id, 'grading', 'Removed learning material', "Removed a material from '{$item['item_name']}' ($subject_code / section $section_id / $term)");
$_SESSION['success_message'] = 'Learning material removed.';
header('Location: ' . $redirect);
exit;
