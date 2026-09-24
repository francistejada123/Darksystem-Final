<?php
// modules/students/delete_selected.php
// Delete multiple students - UPDATE SECTION COUNT

require_once __DIR__ . '/../../config/database.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';

if (!hasAnyRole(['Administrator', 'Registrar'])) {
    header('Location: ../../index.php');
    exit;
}

$ids = $_GET['ids'] ?? '';
if (empty($ids)) {
    header('Location: index.php');
    exit;
}

$id_array = explode(',', $ids);
$deleted = 0;
$errors = 0;

// Start transaction
$db->beginTransaction();

try {
    foreach ($id_array as $id) {
        $id = intval(trim($id));
        if ($id > 0) {
            // Get student info including section_id
            $student = getRecord("SELECT first_name, last_name, lrn, section_id FROM students WHERE student_id = ? AND is_active = 1", [$id]);
            if ($student) {
                $section_id = $student['section_id'];
                
                $result = executeQuery("UPDATE students SET is_active = 0 WHERE student_id = ?", [$id]);
                if ($result) {
                    // ✅ UPDATE SECTION ENROLLMENT COUNT - DECREMENT
                    if ($section_id) {
                        $section = getRecord("SELECT current_enrollment FROM sections WHERE section_id = ?", [$section_id]);
                        if ($section && $section['current_enrollment'] > 0) {
                            executeQuery("UPDATE sections SET current_enrollment = current_enrollment - 1 WHERE section_id = ?", [$section_id]);
                        }
                    }
                    $deleted++;
                    logActivity($_SESSION['user_id'], 'Deleted student', 'students', "Deleted student: {$student['last_name']}, {$student['first_name']} (LRN: {$student['lrn']})");
                } else {
                    $errors++;
                }
            }
        }
    }
    
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('Bulk student delete failed: ' . $e->getMessage());
    $_SESSION['error_message'] = 'Failed to process. Please try again.';
    header('Location: index.php');
    exit;
}

if ($deleted > 0) {
    $_SESSION['success_message'] = $deleted . ' student(s) deleted successfully!';
}
if ($errors > 0) {
    $_SESSION['error_message'] = $errors . ' student(s) failed to delete.';
}

header('Location: index.php');
exit;
?>