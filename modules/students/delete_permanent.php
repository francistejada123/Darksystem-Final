<?php
// modules/students/delete_permanent.php
// PERMANENT DELETE - HARD DELETE (IRREVERSIBLE!) - UPDATE SECTION COUNT

require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Only Administrators can permanently delete
if (!hasRole('Administrator')) {
    header('Location: ../../index.php');
    exit;
}

// Where to send the user back to. The Recycle Bin is the default because
// that is where this action normally lives; index.php passes ?from=index.
$return_page = (($_GET['from'] ?? '') === 'index') ? 'index.php' : 'deleted.php';

$student_id = intval($_GET['id'] ?? 0);
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';

if ($student_id <= 0) {
    header('Location: ' . $return_page);
    exit;
}

if (!verifyCSRFToken($_GET['csrf_token'] ?? '')) {
    $_SESSION['error_message'] = 'Invalid or expired request. Please try the delete action again.';
    header('Location: ' . $return_page);
    exit;
}

// Get student info
$student = getRecord("SELECT * FROM students WHERE student_id = ?", [$student_id]);
if (!$student) {
    $_SESSION['error_message'] = 'Student not found.';
    header('Location: ' . $return_page);
    exit;
}

// Nothing may be deleted without an explicit confirmation. This used to
// fall through to a blank page; send the user back instead.
if ($confirm !== 'yes') {
    $_SESSION['error_message'] = 'Permanent delete was not confirmed. Nothing was deleted.';
    header('Location: ' . $return_page);
    exit;
}

// ============================================================
// PERMANENT DELETE - HARD DELETE
// ============================================================

// Start transaction
$db->beginTransaction();

try {
    // Get section_id before deleting
    $section_id = $student['section_id'];
    
    // Get all records to delete
    $student_name = $student['first_name'] . ' ' . $student['last_name'];
    $student_lrn = $student['lrn'];
    
    // 1. Delete AI Analysis
    executeQuery("DELETE FROM ai_analysis WHERE student_id = ?", [$student_id]);
    
    // 2. Delete Grades
    executeQuery("DELETE FROM grades WHERE student_id = ?", [$student_id]);
    
    // 3. Delete Enrollments
    executeQuery("DELETE FROM enrollments WHERE student_id = ?", [$student_id]);
    
    // 4. Delete Attendance (if exists)
    executeQuery("DELETE FROM attendance WHERE student_id = ?", [$student_id]);
    
    // 5. Delete Requirements (if exists)
    $reqs = getRecords("SELECT requirement_id FROM enrollment_requirements er 
                        JOIN enrollments e ON er.enrollment_id = e.enrollment_id 
                        WHERE e.student_id = ?", [$student_id]);
    foreach ($reqs as $req) {
        executeQuery("DELETE FROM enrollment_requirements WHERE requirement_id = ?", [$req['requirement_id']]);
    }
    
    // 6. Delete Notifications belonging to this student's LOGIN ACCOUNT.
    //    notifications.user_id points at users.user_id, NOT students.student_id
    //    — matching on student_id would wipe an unrelated user's notifications
    //    whose user_id happened to be the same number.
    if (!empty($student['user_id'])) {
        executeQuery("DELETE FROM notifications WHERE user_id = ?", [$student['user_id']]);
    }
    
    // 7. Delete the Student
    executeQuery("DELETE FROM students WHERE student_id = ?", [$student_id]);
    
    // 8. ✅ UPDATE SECTION ENROLLMENT COUNT - DECREMENT
    if ($section_id) {
        $section = getRecord("SELECT current_enrollment FROM sections WHERE section_id = ?", [$section_id]);
        if ($section && $section['current_enrollment'] > 0) {
            executeQuery("UPDATE sections SET current_enrollment = current_enrollment - 1 WHERE section_id = ?", [$section_id]);
        }
    }
    
    // 9. Log the activity
    logActivity($_SESSION['user_id'], 'PERMANENT DELETE', 'students', 
        "Permanently deleted student: $student_name (LRN: $student_lrn)");
    
    // Commit transaction
    $db->commit();
    
    header('Location: ' . $return_page . '?permanent_deleted=1');
    exit;
    
} catch (Exception $e) {
    // Rollback on error
    $db->rollBack();
    error_log('Permanent student delete failed: ' . $e->getMessage());
    $_SESSION['error_message'] = 'Failed to delete student. Please try again.';
    header('Location: ' . $return_page);
    exit;
}
?>