<?php
// modules/subjects/remove_from_section.php
// Remove subject from section


require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ../../index.php');
    exit;
}

$subject_code = isset($_GET['subject']) ? sanitize($_GET['subject']) : '';
$section_id = isset($_GET['section']) ? intval($_GET['section']) : 0;
$grade_level = isset($_GET['grade']) ? sanitize($_GET['grade']) : '';
$strand = isset($_GET['strand']) ? sanitize($_GET['strand']) : '';

if (empty($subject_code) || empty($section_id)) {
    header('Location: index.php');
    exit;
}

// Get active school year
$active_sy = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
$school_year_id = $active_sy ? $active_sy['sy_id'] : 0;

if ($school_year_id <= 0) {
    $_SESSION['error_message'] = 'No active school year found.';
    header('Location: index.php?grade=' . urlencode($grade_level));
    exit;
}

// Get assignment details before deleting
$assignment = getRecord("
    SELECT ts.teacher_id, u.first_name, u.last_name 
    FROM teacher_subjects ts 
    JOIN users u ON ts.teacher_id = u.user_id
    WHERE ts.subject_code = ? AND ts.section_id = ? AND ts.school_year_id = ?
", [$subject_code, $section_id, $school_year_id]);

$teacher_name = $assignment ? $assignment['first_name'] . ' ' . $assignment['last_name'] : 'Unknown';

// Delete assignment
$scheduleDeleted = executeQuery(
    "DELETE FROM teacher_class_schedule WHERE teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ?",
    [$assignment['teacher_id'] ?? 0, $subject_code, $section_id, $school_year_id]
);
$sql = "DELETE FROM teacher_subjects 
        WHERE subject_code = ? AND section_id = ? AND school_year_id = ?";
$result = executeQuery($sql, [$subject_code, $section_id, $school_year_id]);

if ($result) {
    logActivity($_SESSION['user_id'], 'Unassigned subject from teacher', 'teachers', 
        "Unassigned $subject_code from $teacher_name for section $section_id");
    $_SESSION['success_message'] = "Unassigned $subject_code from $teacher_name successfully!";
} else {
    $_SESSION['error_message'] = 'Failed to unassign subject.';
}

header('Location: index.php?grade=' . urlencode($grade_level) . '&strand=' . urlencode($strand) . '&section=' . $section_id);
exit;
?>