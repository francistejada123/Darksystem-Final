<?php
// modules/enrollment/reject.php
// Reject enrollment with email notification

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once '../../config/email.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Registrar', 'Enrollment Officer'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$enrollment_id = intval($_GET['id'] ?? 0);
if ($enrollment_id <= 0) {
    header('Location: index.php');
    exit;
}

// Get enrollment details with student email
$enrollment = getRecord("
    SELECT e.*, s.first_name, s.last_name, s.lrn, s.email as student_email,
           sec.section_name, sec.strand as section_strand
    FROM enrollments e 
    JOIN students s ON e.student_id = s.student_id 
    LEFT JOIN sections sec ON e.section_id = sec.section_id
    WHERE e.enrollment_id = ?", 
    [$enrollment_id]
);

if (!$enrollment) {
    header('Location: index.php');
    exit;
}

// Start transaction
$db->beginTransaction();

try {
    // Update enrollment status
    $sql = "UPDATE enrollments SET enrollment_status = 'Rejected', processed_by = ? WHERE enrollment_id = ?";
    $result = executeQuery($sql, [$_SESSION['user_id'], $enrollment_id]);

    if ($result) {
        $strand = $enrollment['strand'] ?? $enrollment['section_strand'] ?? '';
        $student_name = $enrollment['first_name'] . ' ' . $enrollment['last_name'];
        $email = $enrollment['student_email'];
        $grade_level = $enrollment['grade_level'];
        $section_id = $enrollment['section_id'];
        
        // ✅ UPDATE SECTION ENROLLMENT COUNT - DECREMENT (only if student was approved before)
        if ($section_id) {
            // Check if student has an approved enrollment in this section
            $check = getRecord("
                SELECT enrollment_id FROM enrollments 
                WHERE student_id = ? AND section_id = ? AND enrollment_status = 'Approved'
            ", [$enrollment['student_id'], $section_id]);
            
            if ($check) {
                $section = getRecord("SELECT current_enrollment FROM sections WHERE section_id = ?", [$section_id]);
                if ($section && $section['current_enrollment'] > 0) {
                    $update_section = "UPDATE sections SET current_enrollment = current_enrollment - 1 WHERE section_id = ?";
                    executeQuery($update_section, [$section_id]);
                }
            }
        }
        
        // SEND EMAIL NOTIFICATION
        if (!empty($email)) {
            try {
                $email_result = sendEnrollmentNotification(
                    $email,
                    $student_name,
                    'Rejected',
                    $grade_level,
                    $strand,
                    '',
                    ''
                );
                
                if ($email_result['success']) {
                    logActivity($_SESSION['user_id'], 'Email sent', 'enrollment', "Rejection email sent to: $email");
                } else {
                    logActivity($_SESSION['user_id'], 'Email failed', 'enrollment', "Failed to send email to: $email - " . $email_result['message']);
                }
            } catch (Exception $e) {
                logActivity($_SESSION['user_id'], 'Email error', 'enrollment', "Email error: " . $e->getMessage());
            }
        }
        
        logActivity($_SESSION['user_id'], 'Rejected enrollment', 'enrollment', 
            "Rejected enrollment ID: $enrollment_id for student: {$enrollment['last_name']}, {$enrollment['first_name']}");
        
        $db->commit();
        $_SESSION['success_message'] = 'Enrollment rejected.';
    } else {
        $db->rollBack();
        $_SESSION['error_message'] = 'Failed to reject enrollment.';
    }
} catch (Exception $e) {
    $db->rollBack();
    error_log('Enrollment rejection failed for ID ' . $enrollment_id . ': ' . $e->getMessage());
    $_SESSION['error_message'] = 'Failed to process rejection. Please try again.';
}

header('Location: index.php');
exit;
?>