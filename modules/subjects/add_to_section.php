<?php
// modules/subjects/add_to_section.php
// RETIRED: teacher assignment is no longer done from Subject Management.
// Subject Management only manages the curriculum (which subjects belong to a
// grade level / strand / term). Assigning and unassigning a teacher to a
// Subject + Section + School Year is done ONLY in Teacher Management
// (modules/teachers/index.php). This route changes nothing and sends the
// administrator there; no teacher_subjects row is created or deleted here.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!hasRole('Administrator')) {
    header('Location: ../../index.php');
    exit;
}

$grade = isset($_REQUEST['grade']) ? sanitize($_REQUEST['grade']) : (isset($_REQUEST['grade_level']) ? sanitize($_REQUEST['grade_level']) : '');
$strand = isset($_REQUEST['strand']) ? sanitize($_REQUEST['strand']) : '';
$section = isset($_REQUEST['section']) ? intval($_REQUEST['section']) : intval($_REQUEST['section_id'] ?? 0);

$_SESSION['error_message'] = 'Assigning a teacher is done in Teacher Management. Nothing was changed.';
header('Location: ../teachers/index.php?grade=' . urlencode($grade) . '&strand=' . urlencode($strand) . '&section=' . $section);
exit;
