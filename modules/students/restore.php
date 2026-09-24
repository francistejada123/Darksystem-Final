<?php
// modules/students/restore.php
// Restore soft-deleted student - UPDATE SECTION COUNT

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
    $_SESSION['error_message'] = 'Invalid or expired request. Please try the restore action again.';
    header('Location: index.php');
    exit;
}

$student = getRecord("SELECT first_name, last_name, lrn, section_id FROM students WHERE student_id = ? AND is_active = 0", [$student_id]);
if (!$student) {
    $_SESSION['error_message'] = 'Student not found or already active.';
    header('Location: index.php');
    exit;
}

$section_id = $student['section_id'];

// Start transaction
$db->beginTransaction();

try {
    // Restore the student
    $sql = "UPDATE students SET is_active = 1 WHERE student_id = ?";
    $result = executeQuery($sql, [$student_id]);

    if ($result) {
        // ✅ UPDATE SECTION ENROLLMENT COUNT - INCREMENT
        if ($section_id) {
            // Check if student has an approved enrollment in this section
            $check_enrollment = getRecord("
                SELECT enrollment_id FROM enrollments 
                WHERE student_id = ? AND section_id = ? AND enrollment_status = 'Approved'
            ", [$student_id, $section_id]);
            
            if ($check_enrollment) {
                $update_section = "UPDATE sections SET current_enrollment = current_enrollment + 1 WHERE section_id = ?";
                executeQuery($update_section, [$section_id]);
            }
        }
        
        logActivity($_SESSION['user_id'], 'Restored student', 'students', "Restored student: {$student['last_name']}, {$student['first_name']} (LRN: {$student['lrn']})");
        $db->commit();
        // Back to the Recycle Bin, which is where this action is launched
        // from and the only page that renders the ?restored message.
        header('Location: deleted.php?restored=1');
        exit;
    } else {
        $db->rollBack();
        $_SESSION['error_message'] = 'Failed to restore student.';
        header('Location: deleted.php');
        exit;
    }
} catch (Exception $e) {
    $db->rollBack();
    error_log('Student restore failed: ' . $e->getMessage());
    $_SESSION['error_message'] = 'Failed to restore student. Please try again.';
    header('Location: deleted.php');
    exit;
}
?>