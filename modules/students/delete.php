<?php
// modules/students/delete.php
// Delete student (soft delete) - UPDATE SECTION COUNT

require_once __DIR__ . '/../../config/database.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';

if (!hasAnyRole(['Administrator', 'Registrar'])) {
    header('Location: ../../index.php');
    exit;
}

$student_id = intval($_GET['id'] ?? 0);
if ($student_id <= 0) {
    header('Location: index.php');
    exit;
}

if (!verifyCSRFToken($_GET['csrf_token'] ?? '')) {
    $_SESSION['error_message'] = 'Invalid or expired request. Please try the delete action again.';
    header('Location: index.php');
    exit;
}

// Get student info including section_id
$student = getRecord("SELECT first_name, last_name, lrn, section_id FROM students WHERE student_id = ? AND is_active = 1", [$student_id]);
if (!$student) {
    header('Location: index.php');
    exit;
}

$section_id = $student['section_id'];

// Start transaction
$db->beginTransaction();

try {
    // Soft delete the student
    $sql = "UPDATE students SET is_active = 0 WHERE student_id = ?";
    $result = executeQuery($sql, [$student_id]);

    if ($result) {
        // ✅ UPDATE SECTION ENROLLMENT COUNT - DECREMENT
        if ($section_id) {
            // Get current section count first
            $section = getRecord("SELECT current_enrollment FROM sections WHERE section_id = ?", [$section_id]);
            if ($section && $section['current_enrollment'] > 0) {
                $update_section = "UPDATE sections SET current_enrollment = current_enrollment - 1 WHERE section_id = ?";
                executeQuery($update_section, [$section_id]);
            }
        }
        
        logActivity($_SESSION['user_id'], 'Deleted student', 'students', "Deleted student: {$student['last_name']}, {$student['first_name']} (LRN: {$student['lrn']})");
        $db->commit();
        header('Location: index.php?deleted=1');
        exit;
    } else {
        $db->rollBack();
        $_SESSION['error_message'] = 'Failed to delete student.';
        header('Location: index.php');
        exit;
    }
} catch (Exception $e) {
    $db->rollBack();
    error_log('Student delete failed for ID ' . $student_id . ': ' . $e->getMessage());
    $_SESSION['error_message'] = 'Failed to delete student. Please try again.';
    header('Location: index.php');
    exit;
}