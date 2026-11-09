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
require_once __DIR__ . '/../../includes/eo_scope.php';   // Enrollment Officer: own grade level only
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
eoGuard($app['grade_level'], 'applications.php');

if (!empty($app['deleted_at'])) {
    $_SESSION['error_message'] = 'This application is in the Recycle Bin. Restore it first.';
    header('Location: applications_deleted.php');
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

    // Incomplete Requirements is NOT a rejection: the learner keeps an active student + enrollment
    // record (status 'Incomplete Requirements', no seat counted yet) so it shows in Recent
    // Enrollment Activity and the Parent Portal pop-up can be set up while the missing document
    // is submitted later. Approving afterwards turns this same record into 'Approved'.
    try {
        $inc_sy = $app['school_year_id'];
        if (!$inc_sy) {
            $inc_sy_row = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
            $inc_sy = $inc_sy_row['sy_id'] ?? null;
        }
        if ($inc_sy) {
            $db->beginTransaction();
            $inc_lrn = $app['lrn'];
            if (empty($inc_lrn)) {
                $inc_lrn = 'T' . str_pad((string) $application_id, 11, '0', STR_PAD_LEFT);
            }
            $inc_student = getRecord("SELECT student_id FROM students WHERE lrn = ?", [$inc_lrn]);
            if ($inc_student) {
                $inc_student_id = (int) $inc_student['student_id'];   // existing learner: student row left untouched
            } else {
                $inc_email = $app['email'];
                if ($inc_email !== '' && $inc_email !== null && diEmailOwner($inc_email)) {
                    $inc_email = null;   // one email = one person
                }
                $inc_student_id = (int) insertRecord(
                    "INSERT INTO students (lrn, first_name, last_name, middle_name, birth_date, gender, contact_number, email, address,
                                           guardian_name, guardian_contact, grade_level, strand, section_id, school_year_id, enrollment_status, is_active)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,?,'Pending',1)",
                    [
                        $inc_lrn, $app['first_name'], $app['last_name'], $app['middle_name'], $app['birth_date'], $app['gender'],
                        $app['contact_number'], $inc_email, $app['address'], $app['guardian_name'], $app['guardian_contact'],
                        $app['grade_level'], $app['strand'], $inc_sy,
                    ]
                );
            }
            if (!$inc_student_id) {
                throw new Exception('Failed to create the student record.');
            }
            $inc_enr = getRecord("SELECT enrollment_id FROM enrollments WHERE student_id = ? AND school_year_id = ?", [$inc_student_id, $inc_sy]);
            if ($inc_enr) {
                $inc_enrollment_id = (int) $inc_enr['enrollment_id'];
            } else {
                $inc_section = assignSectionForApplication($app['grade_level'], (float) $app['average_grade'], $app['strand'], $inc_sy);
                $inc_enrollment_id = (int) insertRecord(
                    "INSERT INTO enrollments (student_id, school_year_id, grade_level, strand, section_id, enrollment_date, enrollment_status, processed_by, remarks)
                     VALUES (?,?,?,?,?, NOW(), 'Incomplete Requirements', ?, ?)",
                    [$inc_student_id, $inc_sy, $app['grade_level'], $app['strand'], $inc_section['section_id'] ?? null, $_SESSION['user_id'], 'Online application ' . $app['reference_no']]
                );
                if (!$inc_enrollment_id) {
                    throw new Exception('Failed to create the enrollment record.');
                }
                executeQuery("INSERT INTO enrollment_requirements (enrollment_id, submitted_at) VALUES (?, NOW())", [$inc_enrollment_id]);
            }
            executeQuery("UPDATE online_applications SET student_id = ?, enrollment_id = ? WHERE application_id = ?", [$inc_student_id, $inc_enrollment_id, $application_id]);
            $db->commit();
        }
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Incomplete-requirements enrollment record failed for application ' . $application_id . ': ' . $e->getMessage());
    }

    if (!empty($app['email']) && function_exists('sendEnrollmentNotification')) {
        try {
            // $app was read before the student record existed: re-read it so the email gets the real student.
            $inc_app = getRecord("SELECT student_id FROM online_applications WHERE application_id = ?", [$application_id]);
            $inc_st  = !empty($inc_app['student_id']) ? getRecord("SELECT lrn FROM students WHERE student_id = ?", [(int) $inc_app['student_id']]) : null;
            sendEnrollmentNotification($app['email'], $app['first_name'] . ' ' . $app['last_name'], OE_INCOMPLETE, $app['grade_level'], $app['strand'], '', $inc_st['lrn'] ?? (string) $app['lrn'], $app['reference_no'], $missing);
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

// FINAL SERVER-SIDE GUARD: the backend is the last word, whatever the button on the page said.
// BLOCKERS (Form 138 missing, invalid LRN, Form 138 grade mismatch) -> nothing is approved, created or counted.
// A required document that is only NOT UPLOADED YET, and Form 137 "To Follow", do not block: the Admin may
// approve with incomplete requirements (explicit confirmation) and the Requirements Status stays
// "Incomplete Requirements" -- it is separate from the application status.
$split = oeSplitMissing($application_id, $app['application_type']);
$approval_blockers = $split['blockers'];
if ($approval_blockers) {
    $_SESSION['approval_blocked'] = ['application_id' => $application_id, 'missing' => $approval_blockers];
    logActivity($_SESSION['user_id'], 'online_enrollment', 'Approval blocked: incomplete required requirements',
        "Ref: {$app['reference_no']} - missing: " . implode(', ', $approval_blockers));
    header('Location: view_application.php?id=' . $application_id);
    exit;
}
$pending_at_approval = oeRequirementsPreview($application_id, $app['application_type']);
if ($pending_at_approval && ($_POST['confirm_incomplete'] ?? '') !== '1') {
    $_SESSION['error_message'] = 'Approve With Incomplete Requirements needs confirmation. Pending: ' . implode(', ', array_column($pending_at_approval, 'label')) . '.';
    header('Location: view_application.php?id=' . $application_id);
    exit;
}

$school_year_id = $app['school_year_id'];
if (!$school_year_id) {
    $sy = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
    $school_year_id = $sy['sy_id'] ?? null;
}
$clash = lrnApprovedApplication($app['lrn'] ?? '', $school_year_id, $application_id);
if ($clash) {
    $_SESSION['error_message'] = 'Cannot approve: LRN ' . $app['lrn'] . ' already has an approved application (Ref. ' . $clash['reference_no'] . ') for this school year. Delete the duplicate application instead.';
    header('Location: view_application.php?id=' . $application_id);
    exit;
}

if (!$school_year_id) {
    $_SESSION['error_message'] = 'No active school year is set. Please set one in Settings first.';
    header('Location: view_application.php?id=' . $application_id);
    exit;
}

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

$email_note = '';
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
                if ($col === 'email' && $new !== '' && diEmailOwner($new, $student_id)) {
                    $email_note = 'The new email on the application was not saved: it is already used by another account.';
                    continue;
                }
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
        $new_email = $app['email'];
        if ($new_email !== '' && $new_email !== null && ($email_owner = diEmailOwner($new_email))) {
            $new_email = null;   // one email = one person (the database enforces it too)
            $email_note = 'The student was saved without an email: ' . $app['email'] . ' is already used by ' . $email_owner . '.';
        }
        $student_id = insertRecord(
            "INSERT INTO students (lrn, first_name, last_name, middle_name, birth_date, gender, contact_number, email, address,
                                   guardian_name, guardian_contact, grade_level, strand, section_id, school_year_id, enrollment_status, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Enrolled',1)",
            [
                $lrn, $app['first_name'], $app['last_name'], $app['middle_name'], $app['birth_date'], $app['gender'],
                $app['contact_number'], $new_email, $app['address'], $app['guardian_name'], $app['guardian_contact'],
                $app['grade_level'], $app['strand'], $section_id, $school_year_id,
            ]
        );
        if (!$student_id) {
            throw new Exception('Failed to create the student record.');
        }
    }

    // Enrollment record (same table used by walk-in enrollment)
    $existing_enrollment = getRecord("SELECT * FROM enrollments WHERE student_id = ? AND school_year_id = ?", [$student_id, $school_year_id]);
    $prior_enr_status = $existing_enrollment ? $existing_enrollment['enrollment_status'] : null;
    if ($existing_enrollment) {
        $enrollment_id = $existing_enrollment['enrollment_id'];
        // The admin's approval of the application IS the approval of the enrollment.
        executeQuery(
            "UPDATE enrollments SET enrollment_status = 'Approved', processed_by = ? WHERE enrollment_id = ? AND enrollment_status = 'Pending'",
            [$_SESSION['user_id'], $enrollment_id]
        );
        // Grade 11/12: one section for the enrollment AND the student record. A Pending enrollment keeps
        // the section it already holds when that section is still an offered one; if it has
        // none (or an inactive / retired HUMSS one) it takes the section chosen above.
        if ($prior_enr_status === 'Pending' && in_array($app['grade_level'], ['Grade 11', 'Grade 12'], true)) {
            $held = !empty($existing_enrollment['section_id'])
                ? getRecord("SELECT * FROM sections WHERE section_id = ? AND is_active = 1 AND UPPER(COALESCE(strand, '')) <> 'HUMSS'", [$existing_enrollment['section_id']])
                : null;
            if ($held) {
                $section = $held;
                $section_id = (int) $held['section_id'];
            } elseif ($section_id) {
                executeQuery("UPDATE enrollments SET section_id = ? WHERE enrollment_id = ?", [$section_id, $enrollment_id]);
            }
            if ($section_id) {
                executeQuery("UPDATE students SET section_id = ? WHERE student_id = ?", [$section_id, $student_id]);
            }
        }
        // Was marked Incomplete Requirements earlier: the missing document is settled, so it becomes Approved.
        executeQuery(
            "UPDATE enrollments SET enrollment_status = 'Approved', section_id = ?, processed_by = ? WHERE enrollment_id = ? AND enrollment_status = 'Incomplete Requirements'",
            [$section_id, $_SESSION['user_id'], $enrollment_id]
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

    // Section capacity: counted ONCE, and only when the enrollment was not
    // already counted as Approved. 'Incomplete Requirements' is a provisional
    // section (no seat), so its approval is the first and only increment.
    // Form 137 "To Follow" is not a missing requirement, so it never blocks this.
    if ($section_id && $prior_enr_status !== 'Approved') {
        executeQuery(
            "UPDATE sections SET current_enrollment = current_enrollment + 1 WHERE section_id = ?",
            [$section_id]
        );
    }

    executeQuery(
        "UPDATE online_applications SET status = 'Approved', remarks = ?, reviewed_by = ?, reviewed_at = NOW(), student_id = ?, enrollment_id = ? WHERE application_id = ?",
        [$remarks, $_SESSION['user_id'], $student_id, $enrollment_id, $application_id]
    );

    // Documents approved as "To Follow": kept as pending requirement records (Form 137 keeps its own status).
    oeEnsureRequirementsSchema();
    foreach ($split['followups'] as $fu) {
        executeQuery(
            "INSERT INTO online_app_requirements (application_id, doc_key, label, status, updated_by) VALUES (?, ?, ?, 'To Follow', ?)
             ON DUPLICATE KEY UPDATE label = VALUES(label)",
            [$application_id, $fu['key'], $fu['label'], $_SESSION['user_id']]
        );
    }

    logActivity($_SESSION['user_id'], 'online_enrollment', 'Approved online application',
        "Ref: {$app['reference_no']} — " . ($existing_student ? 'reused existing' : 'created') . " student ID $student_id, enrollment ID $enrollment_id");

    $db->commit();

    $req_status = oeSyncRequirementsStatus($application_id);   // Approved does NOT mean Complete: it follows the pending list

    $section_name = $section['section_name'] ?? 'Pending assignment';
    $form137_state = oeForm137Status($application_id, $app['application_type']);

    if (!empty($app['email']) && function_exists('sendEnrollmentNotification')) {
        try {
            sendEnrollmentNotification($app['email'], $app['first_name'] . ' ' . $app['last_name'], 'Approved', $app['grade_level'], $app['strand'], $section_name, $lrn);
        } catch (Exception $e) {
            error_log('Online enrollment approval email failed: ' . $e->getMessage());
        }
    }

    $_SESSION['success_message'] = 'Application ' . $app['reference_no'] . ' approved' . ($req_status === OE_INCOMPLETE ? ' with Incomplete Requirements (' . implode(', ', array_column($pending_at_approval, 'label')) . ' still to be submitted)' : '') . '. ' . ($existing_student ? 'Existing student record (LRN ' . $lrn . ') updated' : 'Student record created') . ' and assigned to section ' . $section_name . '.' . ($form137_state === 'To Follow' ? ' Form 137 is To Follow (the learner submits the physical copy to the school; update its status in the application review page).' : '') . ($email_note !== '' ? ' ' . $email_note : '') . ppApprovalNote($student_id);
    header('Location: index.php');
    exit;
} catch (Exception $e) {
    $db->rollBack();
    error_log('Enrollment approval failed for application ' . $application_id . ': ' . $e->getMessage());
    $_SESSION['error_message'] = 'Failed to approve the application. Please try again.';
    header('Location: view_application.php?id=' . $application_id);
    exit;
}