<?php
// modules/subjects/add_to_section.php
// Add subject to section


require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ../../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token.';
        header('Location: index.php?grade=' . urlencode($_POST['grade_level'] ?? ''));
        exit;
    }
    
    $section_id = intval($_POST['section_id'] ?? 0);
    $subject_code = sanitize($_POST['subject_code'] ?? '');
    $teacher_id = intval($_POST['teacher_id'] ?? 0);
    $grade_level = sanitize($_POST['grade_level'] ?? '');
    $strand = sanitize($_POST['strand'] ?? '');
    
    if (empty($section_id) || empty($subject_code) || empty($teacher_id)) {
        $_SESSION['error_message'] = 'Please select a subject and a teacher.';
        header('Location: index.php?grade=' . urlencode($grade_level) . '&strand=' . urlencode($strand) . '&section=' . $section_id);
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
    
    // Check if already assigned
    $check = getRecord("
        SELECT ts_id FROM teacher_subjects 
        WHERE subject_code = ? AND section_id = ? AND school_year_id = ?
    ", [$subject_code, $section_id, $school_year_id]);
    
    if ($check) {
        $_SESSION['error_message'] = 'Subject is already assigned to this section.';
        header('Location: index.php?grade=' . urlencode($grade_level) . '&strand=' . urlencode($strand) . '&section=' . $section_id);
        exit;
    }
    
    // Verify teacher exists
    $teacher = getRecord("SELECT user_id, first_name, last_name FROM users WHERE user_id = ? AND role_id = 3 AND is_active = 1", [$teacher_id]);
    if (!$teacher) {
        $_SESSION['error_message'] = 'Selected teacher not found.';
        header('Location: index.php?grade=' . urlencode($grade_level) . '&strand=' . urlencode($strand) . '&section=' . $section_id);
        exit;
    }
    
    // Insert assignment
    $sql = "INSERT INTO teacher_subjects (teacher_id, subject_code, section_id, school_year_id) 
            VALUES (?, ?, ?, ?)";
    $result = executeQuery($sql, [$teacher_id, $subject_code, $section_id, $school_year_id]);
    
    if ($result) {
        $teacher_name = $teacher['first_name'] . ' ' . $teacher['last_name'];
        logActivity($_SESSION['user_id'], 'Assigned subject to teacher', 'teachers', 
            "Assigned $subject_code to $teacher_name for section $section_id");
        $_SESSION['success_message'] = "Assigned $subject_code to $teacher_name successfully!";
    } else {
        $_SESSION['error_message'] = 'Failed to assign subject.';
    }
    
    header('Location: index.php?grade=' . urlencode($grade_level) . '&strand=' . urlencode($strand) . '&section=' . $section_id);
    exit;
}

header('Location: index.php');
exit;
?>