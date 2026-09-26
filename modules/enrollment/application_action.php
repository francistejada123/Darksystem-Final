<?php
// modules/enrollment/application_action.php
// ADMIN — approve an online enrollment application or mark it Incomplete Requirements.
// Approving converts the application into a real student + enrollment record.
// The enrollment is created already 'Approved' (student 'Enrolled') -- approving the
// application is the approval -- so it shows as Approved under Recently Enrolled and
// the student's LRN becomes their QR-code link. (sections.current_enrollment is
// incremented here, once; the separate approve.php step is not needed.)

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/enrollment_helpers_online.php';

if (file_exists(__DIR__ . '/../../config/email.php')) {
    require_once __DIR__ . '/../../config/email.php';
}

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Registrar', 'Enrollment Officer'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['error_message'] = 'Invalid request.';
    header('Location: applications.php');
    exit;
}

$application_id = intval($_POST['application_id'] ?? 0);
$action = $_POST['action'] ?? '';
$remarks = sanitize($_POST['remarks'] ?? '');

oeEnsureCorrectionStatus();
$app = getRecord("SELECT * FROM online_applications WHERE application_id = ?", [$application_id]);
if (!$app) {
    $_SESSION['error_message'] = 'Application not found.';
    header('Location: applications.php');
    exit;
}

if (!in_array($app['status'], ['Pending', 'Under Review', 'For Correction', OE_INCOMPLETE], true)) {
    $_SESSION['error_message'] = 'This application was already processed.';
    header('Location: applications.php');
    exit;
}

// ============================================================
// INCOMPLETE REQUIREMENTS (the only decision besides Approve; replaces Reject / For Correction)
// ============================================================
if (in_array($action, ['incomplete', 'reject', 'correction'], true)) {
    // The only decision besides Approve: something the applicant must still submit / fix.
    $missing = oeMissingRequirements($application_id, $app['application_type']);
    if (trim($remarks) === '') {
        $remarks = $missing ? 'Missing requirement: ' . implode(', ', $missing) . '.' : 'Incomplete requirements. Please contact the registrar.';
    }
    executeQuery(
        "UPDATE online_applications SET status = ?, remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE application_id = ?",
        [OE_INCOMPLETE, $remarks, $_SESSION['user_id'], $application_id]
    );

    logActivity($_SESSION['user_id'], 'online_enrollment', 'Online application marked Incomplete Requirements', "Ref: {$app['reference_no']}");

    if (!empty($app['email']) && function_exists('sendEnrollmentNotification')) {
        try {
            sendEnrollmentNotification($app['email'], $app['first_name'] . ' ' . $app['last_name'], OE_INCOMPLETE, $app['grade_level'], $app['strand'], '', $app['lrn']);
        } catch (Exception $e) {
            error_log('Online enrollment incomplete-requirements email failed: ' . $e->getMessage());
        }
    }

    $_SESSION['success_message'] = 'Application ' . $app['reference_no'] . ' is marked Incomplete Requirements. The applicant sees what is missing on the tracking page.';
    header('Location: applications.php');
    exit;
}

// ============================================================
// APPROVE
// ============================================================
if ($action !== 'approve') {
    header('Location: applications.php');
    exit;
}

$school_year_id = $app['school_year_id'];
if (!$school_year_id) {
    $sy = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
    $school_year_id = $sy['sy_id'] ?? null;
}
if (!$school_year_id) {
    $_SESSION['error_message'] = 'No active school year is set. Please set one in Settings first.';
    header('Location: view_application.php?id=' . $application_id);
    exit;
}

$db->beginTransaction();

try {
    $section = assignSectionForApplication($app['grade_level'], (float) $app['average_grade'], $app['strand'], $school_year_id);
    $section_id = $section['section_id'] ?? null;

    // LRN: use the submitted one, otherwise generate a temporary placeholder
    $lrn = $app['lrn'];
    if (empty($lrn)) {
        $lrn = 'T' . str_pad((string) $application_id, 11, '0', STR_PAD_LEFT);
    }

    $existing_student = getRecord("SELECT student_id FROM students WHERE lrn = ?", [$lrn]);

    if ($existing_student) {
        $student_id = $existing_student['student_id'];
        executeQuery(
            "UPDATE students SET grade_level = ?, strand = ?, section_id = ?, school_year_id = ?, enrollment_status = 'Enrolled', is_active = 1 WHERE student_id = ?",
            [$app['grade_level'], $app['strand'], $section_id, $school_year_id, $student_id]
        );

        // Continuing / Returning reuse the saved student record (never a duplicate).
        // Only the details the learner actually changed on the form are written back:
        //   Continuing - contact, email, address, guardian (identity is locked on the form)
        //   Returning  - the same plus name and sex (they had to review everything)
        if (in_array($app['application_type'], ['Continuing', 'Returning'], true)) {
            $current = getRecord("SELECT * FROM students WHERE student_id = ?", [$student_id]);
            $editable = ['contact_number', 'email', 'address', 'guardian_name', 'guardian_contact'];
            if ($app['application_type'] === 'Returning') {
                $editable = array_merge($editable, ['first_name', 'last_name', 'middle_name', 'gender']);
            }
            $norm = function ($v) { return trim(html_entity_decode((string) $v, ENT_QUOTES, 'UTF-8')); };
            $sets = [];
            $vals = [];
            foreach ($editable as $col) {
                $new = trim((string) ($app[$col] ?? ''));
                if ($new !== '' && $current && $norm($new) !== $norm($current[$col] ?? '')) {
                    $sets[] = "$col = ?";
                    $vals[] = $new;
                }
            }
            if ($sets) {
                $vals[] = $student_id;
                executeQuery("UPDATE students SET " . implode(', ', $sets) . " WHERE student_id = ?", $vals);
            }
        }
    } else {
        $student_id = insertRecord(
            "INSERT INTO students (lrn, first_name, last_name, middle_name, birth_date, gender, contact_number, email, address,
                                   guardian_name, guardian_contact, grade_level, strand, section_id, school_year_id, enrollment_status, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Enrolled',1)",
            [
                $lrn, $app['first_name'], $app['last_name'], $app['middle_name'], $app['birth_date'], $app['gender'],
                $app['contact_number'], $app['email'], $app['address'], $app['guardian_name'], $app['guardian_contact'],
                $app['grade_level'], $app['strand'], $section_id, $school_year_id,
            ]
        );
        if (!$student_id) {
            throw new Exception('Failed to create the student record.');
        }
    }

    // Enrollment record (same table used by walk-in enrollment)
    $existing_enrollment = getRecord("SELECT enrollment_id FROM enrollments WHERE student_id = ? AND school_year_id = ?", [$student_id, $school_year_id]);
    if ($existing_enrollment) {
        $enrollment_id = $existing_enrollment['enrollment_id'];
        // The admin's approval of the application IS the approval of the enrollment.
        executeQuery(
            "UPDATE enrollments SET enrollment_status = 'Approved', processed_by = ? WHERE enrollment_id = ? AND enrollment_status = 'Pending'",
            [$_SESSION['user_id'], $enrollment_id]
        );
    } else {
        $enrollment_id = insertRecord(
            "INSERT INTO enrollments (student_id, school_year_id, grade_level, strand, section_id, enrollment_date, enrollment_status, processed_by, remarks)
             VALUES (?,?,?,?,?, NOW(), 'Approved', ?, ?)",
            [$student_id, $school_year_id, $app['grade_level'], $app['strand'], $section_id, $_SESSION['user_id'], 'Online application ' . $app['reference_no']]
        );
        if (!$enrollment_id) {
            throw new Exception('Failed to create the enrollment record.');
        }
        executeQuery("INSERT INTO enrollment_requirements (enrollment_id, submitted_at) VALUES (?, NOW())", [$enrollment_id]);
    }

    if ($section_id) {
        executeQuery(
            "UPDATE sections SET current_enrollment = current_enrollment + 1 WHERE section_id = ?",
            [$section_id]
        );
    }

    executeQuery(
        "UPDATE online_applications SET status = 'Approved', remarks = ?, reviewed_by = ?, reviewed_at = NOW(), student_id = ?, enrollment_id = ? WHERE application_id = ?",
        [$remarks, $_SESSION['user_id'], $student_id, $enrollment_id, $application_id]
    );

    logActivity($_SESSION['user_id'], 'online_enrollment', 'Approved online application',
        "Ref: {$app['reference_no']} — " . ($existing_student ? 'reused existing' : 'created') . " student ID $student_id, enrollment ID $enrollment_id");

    $db->commit();

    $section_name = $section['section_name'] ?? 'Pending assignment';

    if (!empty($app['email']) && function_exists('sendEnrollmentNotification')) {
        try {
            sendEnrollmentNotification($app['email'], $app['first_name'] . ' ' . $app['last_name'], 'Approved', $app['grade_level'], $app['strand'], $section_name, $lrn);
        } catch (Exception $e) {
            error_log('Online enrollment approval email failed: ' . $e->getMessage());
        }
    }

    $_SESSION['success_message'] = 'Application ' . $app['reference_no'] . ' approved. ' . ($existing_student ? 'Existing student record (LRN ' . $lrn . ') updated' : 'Student record created') . ' and assigned to section ' . $section_name . '.';
    header('Location: index.php');
    exit;
} catch (Exception $e) {
    $db->rollBack();
    error_log('Enrollment approval failed for application ' . $application_id . ': ' . $e->getMessage());
    $_SESSION['error_message'] = 'Failed to approve the application. Please try again.';
    header('Location: view_application.php?id=' . $application_id);
    exit;
}
