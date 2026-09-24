<?php
// modules/teachers/delete.php
// PERMANENT DELETE TEACHER - WITH FOREIGN KEY FIX

require_once __DIR__ . '/../../config/database.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';

// Check if user is Administrator
if (!hasRole('Administrator')) {
    header('Location: ../../index.php');
    exit;
}

// Get teacher ID from URL
$teacher_id = intval($_GET['id'] ?? 0);

// Validate teacher ID
if ($teacher_id <= 0) {
    $_SESSION['error_message'] = 'Invalid teacher ID.';
    header('Location: index.php');
    exit;
}

// Check if teacher exists and has role_id = 3 (Teacher)
$teacher = getRecord("SELECT * FROM users WHERE user_id = ? AND role_id = 3", [$teacher_id]);

if (!$teacher) {
    $_SESSION['error_message'] = 'Teacher not found.';
    header('Location: index.php');
    exit;
}

// ✅ SIMULAN ANG TRANSACTION
$db->beginTransaction();

try {
    // 1. I-DELETE MUNA ANG MGA RECORDS SA teacher_subjects
    $stmt1 = $db->prepare("DELETE FROM teacher_subjects WHERE teacher_id = ?");
    $stmt1->execute([$teacher_id]);
    
    // 2. I-DELETE ANG TEACHER
    $stmt2 = $db->prepare("DELETE FROM users WHERE user_id = ? AND role_id = 3");
    $stmt2->execute([$teacher_id]);
    
    // Check if deleted
    if ($stmt2->rowCount() > 0) {
        $db->commit();
        logActivity($_SESSION['user_id'], 'PERMANENT DELETE Teacher', 'teachers', 
            "Permanently deleted teacher: {$teacher['last_name']}, {$teacher['first_name']} (ID: $teacher_id)");
        $_SESSION['success_message'] = 'Teacher permanently deleted successfully!';
    } else {
        $db->rollBack();
        $_SESSION['error_message'] = 'Failed to delete teacher. Teacher may not exist.';
    }
} catch (Exception $e) {
    $db->rollBack();
    error_log('Teacher delete failed for ID ' . $teacher_id . ': ' . $e->getMessage());
    $_SESSION['error_message'] = 'Failed to delete teacher. Please try again.';
}

header('Location: index.php');
exit;
?>