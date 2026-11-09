<?php
// modules/enrollment/approve.php
// Approve enrollment with email notification

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/eo_scope.php';   // Enrollment Officer: own grade level only
require_once '../../config/email.php';

// Parent Portal: text the guardian number a setup link (or keep it for staff to send).
function ppApprovalNote($studentId)
{
    try {
        require_once __DIR__ . '/../../includes/parent_portal.php';
        $w = ppWelcomeOnApproval((int) $studentId, (int) ($_SESSION['user_id'] ?? 0));
        if ($w['sent']) return ' Parent Portal SMS sent to ' . smsMask($w['number']) . '.';
        if ($w['number'] !== '' && $w['text'] !== '') return ' Parent Portal SMS not sent (' . rtrim($w['error'] ?: 'no SMS sender', '.') . ') - use "Send SMS" or "Print Slip" in the student record.';
    } catch (\Throwable $e) {
        error_log('ppApprovalNote: ' . $e->getMessage());
    }
    return '';
}

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
           sec.section_name, sec.strand as section_strand,
           sec.grade_level as section_grade_level
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
eoGuard($enrollment['grade_level']);

// Enrollment created from an online application: the same final required-requirements check
// as Review Application (application_action.php). Blocked = nothing is approved or counted.
require_once __DIR__ . '/../../includes/enrollment_helpers_online.php';
$linked_app = getRecord("SELECT application_id, application_type, status FROM online_applications WHERE enrollment_id = ? AND deleted_at IS NULL ORDER BY application_id DESC LIMIT 1", [$enrollment_id]);
if ($linked_app && $linked_app['status'] !== 'Approved') {
    $blockers = oeMissingRequirements((int) $linked_app['application_id'], $linked_app['application_type']);
    if ($blockers) {
        $_SESSION['approval_blocked'] = ['application_id' => (int) $linked_app['application_id'], 'missing' => $blockers];
        header('Location: view_application.php?id=' . (int) $linked_app['application_id']);
        exit;
    }
}

// Start transaction
$db->beginTransaction();

try {
    // Update enrollment status
    $sql = "UPDATE enrollments SET enrollment_status = 'Approved', processed_by = ? WHERE enrollment_id = ?";
    $result = executeQuery($sql, [$_SESSION['user_id'], $enrollment_id]);

    if ($result) {
        $strand = $enrollment['strand'] ?? $enrollment['section_strand'] ?? '';
        $student_name = $enrollment['first_name'] . ' ' . $enrollment['last_name'];
        $section_name = $enrollment['section_name'] ?? 'Not Assigned';
        $lrn = $enrollment['lrn'];
        $email = $enrollment['student_email'];
        $grade_level = $enrollment['grade_level'];
        $section_id = $enrollment['section_id'];
        $section_grade_level = $enrollment['section_grade_level'] ?? '';
        $student_id = $enrollment['student_id'];
        
        // UPDATE THE STUDENT
        $update_student = "UPDATE students SET 
                            enrollment_status = 'Enrolled',
                            section_id = ?,
                            strand = ?,
                            grade_level = ?
                            WHERE student_id = ?";
        executeQuery($update_student, [
            $section_id,
            $strand,
            $grade_level,
            $student_id
        ]);
        
        // ✅ UPDATE SECTION ENROLLMENT COUNT - INCREMENT (FIXED!)
        if ($section_id) {
            // Check if section exists first
            $section_check = getRecord("SELECT section_id, current_enrollment FROM sections WHERE section_id = ?", [$section_id]);
            if ($section_check) {
                // Always increment, regardless of grade level
                $update_section = "UPDATE sections SET current_enrollment = current_enrollment + 1 WHERE section_id = ?";
                $section_result = executeQuery($update_section, [$section_id]);
                
                // Log the update
                error_log("Section count updated for section_id: $section_id. Current enrollment: " . ($section_check['current_enrollment'] + 1));
            } else {
                error_log("WARNING: Section ID $section_id not found in sections table!");
            }
        }
        
        // SEND EMAIL NOTIFICATION
        if (!empty($email)) {
            try {
                $email_result = sendEnrollmentNotification(
                    $email,
                    $student_name,
                    'Approved',
                    $grade_level,
                    $strand,
                    $section_name,
                    $lrn
                );
                
                if ($email_result['success']) {
                    logActivity($_SESSION['user_id'], 'Email sent', 'enrollment', "Approval email sent to: $email");
                } else {
                    logActivity($_SESSION['user_id'], 'Email failed', 'enrollment', "Failed to send email to: $email - " . $email_result['message']);
                }
            } catch (Exception $e) {
                logActivity($_SESSION['user_id'], 'Email error', 'enrollment', "Email error: " . $e->getMessage());
            }
        }
        
        // If this enrollment came from an online application marked Incomplete Requirements, approve that too.
        executeQuery("UPDATE online_applications SET status = 'Approved', reviewed_by = ?, reviewed_at = NOW() WHERE enrollment_id = ? AND status = 'Incomplete Requirements'", [$_SESSION['user_id'], $enrollment_id]);
        if ($linked_app) oeSyncRequirementsStatus((int) $linked_app['application_id']);   // Requirements Status follows the pending list, not the approval

        logActivity($_SESSION['user_id'], 'Approved enrollment', 'enrollment', 
            "Approved enrollment ID: $enrollment_id for student: {$enrollment['last_name']}, {$enrollment['first_name']}");
        
        $db->commit();
        $_SESSION['success_message'] = 'Enrollment approved successfully! Email sent to student.' . ppApprovalNote($student_id);
    } else {
        $db->rollBack();
        $_SESSION['error_message'] = 'Failed to approve enrollment.';
    }
} catch (Exception $e) {
    $db->rollBack();
    error_log('Enrollment approval failed for ID ' . $enrollment_id . ': ' . $e->getMessage());
    $_SESSION['error_message'] = 'Failed to process enrollment. Please try again.';
}

header('Location: index.php');
exit;
?>