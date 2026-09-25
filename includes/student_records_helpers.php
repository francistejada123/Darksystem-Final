<?php
// includes/student_records_helpers.php
// Two small student-record features:
//
//  1. Portal access without Online Enrollment (walk-in / older records).
//     Normally the Student Portal only opens for an LRN with an APPROVED
//     online application. The Administrator/Registrar can switch on
//     students.portal_access for one student (Student Management > Edit),
//     so that student can sign in with their LRN even without one.
//
//  2. Correction requests for personal / guardian information.
//     Personal information is view-only in Student Management. A student
//     (My Profile) or the Administrator/Registrar (Edit Student) files a
//     request -- one field, the new value and a reason -- and the Registrar
//     (or Administrator) approves or rejects it. Only an APPROVED request
//     changes the students row; every step is kept and logged.

require_once __DIR__ . '/../config/database.php';

/** Fields that can be corrected, with their labels. */
function srCorrectionFields()
{
    return [
        'first_name'       => 'First Name',
        'middle_name'      => 'Middle Name',
        'last_name'        => 'Last Name',
        'birth_date'       => 'Birth Date',
        'gender'           => 'Gender',
        'contact_number'   => 'Contact Number',
        'email'            => 'Email',
        'address'          => 'Address',
        'guardian_name'    => 'Guardian Name',
        'guardian_contact' => 'Guardian Contact',
    ];
}

function srEnsureSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('student_records_v1')) return;

    $col = getRecord(
        "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'portal_access'"
    );
    if (!$col || (int) $col['n'] === 0) {
        executeQuery("ALTER TABLE students ADD COLUMN portal_access TINYINT(1) NOT NULL DEFAULT 0
                      COMMENT '1 = may sign in to the Student Portal without an approved Online Enrollment application'");
    }
    executeQuery(
        "CREATE TABLE IF NOT EXISTS student_info_corrections (
            request_id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            field_name VARCHAR(40) NOT NULL,
            old_value TEXT NULL,
            new_value TEXT NOT NULL,
            reason VARCHAR(500) NOT NULL,
            requested_by_role VARCHAR(20) NOT NULL COMMENT 'Student or the staff role',
            requested_by_id INT NOT NULL,
            status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            review_note VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sic_student (student_id),
            KEY idx_sic_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $ok = getRecord(
        "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'portal_access'"
    );
    if ($ok && (int) $ok['n'] > 0) {
        schemaMarkReady('student_records_v1');
    }
}

/** Validate a new value for a field. @return string error ('' = ok) */
function srValidateValue($field, $value)
{
    $value = trim((string) $value);
    if ($value === '' && !in_array($field, ['middle_name', 'contact_number', 'email', 'address', 'guardian_contact'], true)) {
        return 'Enter the corrected value.';
    }
    if ($field === 'birth_date' && ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || strtotime($value) === false || $value > date('Y-m-d'))) {
        return 'Enter a valid birth date.';
    }
    if ($field === 'gender' && !in_array($value, ['Male', 'Female'], true)) {
        return 'Choose Male or Female.';
    }
    if ($field === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return 'Enter a valid email address.';
    }
    if (in_array($field, ['contact_number', 'guardian_contact'], true) && $value !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $value)) {
        return 'Enter a valid contact number.';
    }
    if (mb_strlen($value) > 255) {
        return 'The value is too long.';
    }
    return '';
}

/**
 * File a correction request.
 * @return array{success:bool,error?:string}
 */
function srRequestCorrection($student_id, $field, $new_value, $reason, $by_role, $by_id)
{
    srEnsureSchema();
    $fields = srCorrectionFields();
    if (!isset($fields[$field])) return ['success' => false, 'error' => 'Choose which information to correct.'];
    $student = getRecord("SELECT * FROM students WHERE student_id = ? AND is_active = 1", [(int) $student_id]);
    if (!$student) return ['success' => false, 'error' => 'Student not found.'];
    $new_value = trim((string) $new_value);
    $reason = trim((string) $reason);
    if (($err = srValidateValue($field, $new_value)) !== '') return ['success' => false, 'error' => $err];
    if ($reason === '') return ['success' => false, 'error' => 'Give a short reason (e.g. "Misspelled on enrollment; see PSA birth certificate").'];
    if ((string) ($student[$field] ?? '') === $new_value) return ['success' => false, 'error' => 'That is already the saved value.'];
    $dup = getRecord(
        "SELECT request_id FROM student_info_corrections WHERE student_id = ? AND field_name = ? AND status = 'Pending'",
        [(int) $student_id, $field]
    );
    if ($dup) return ['success' => false, 'error' => 'There is already a pending request for ' . $fields[$field] . '. Wait for the Registrar to review it.'];

    $id = insertRecord(
        "INSERT INTO student_info_corrections (student_id, field_name, old_value, new_value, reason, requested_by_role, requested_by_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [(int) $student_id, $field, $student[$field] ?? null, mb_substr($new_value, 0, 255), mb_substr($reason, 0, 500), $by_role, (int) $by_id]
    );
    if (!$id) return ['success' => false, 'error' => 'Could not save the request. Please try again.'];
    logActivity($by_role === 'Student' ? null : (int) $by_id, 'students', 'Correction requested',
        "Request #$id for student #$student_id: {$fields[$field]} -> " . mb_substr($new_value, 0, 80));
    return ['success' => true];
}

/**
 * Approve (apply) or reject a pending request. Registrar / Administrator only (checked by the caller).
 * @return array{success:bool,error?:string}
 */
function srReviewCorrection($request_id, $approve, $reviewer_id, $note = '')
{
    srEnsureSchema();
    $req = getRecord("SELECT * FROM student_info_corrections WHERE request_id = ? AND status = 'Pending'", [(int) $request_id]);
    if (!$req) return ['success' => false, 'error' => 'That request was already reviewed or no longer exists.'];
    $fields = srCorrectionFields();
    if (!isset($fields[$req['field_name']])) return ['success' => false, 'error' => 'Unknown field.'];

    if ($approve) {
        if (($err = srValidateValue($req['field_name'], $req['new_value'])) !== '') return ['success' => false, 'error' => $err];
        // Column name comes from the fixed whitelist above, never from input.
        executeQuery("UPDATE students SET `" . $req['field_name'] . "` = ? WHERE student_id = ?", [$req['new_value'], (int) $req['student_id']]);
    }
    executeQuery(
        "UPDATE student_info_corrections SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE request_id = ?",
        [$approve ? 'Approved' : 'Rejected', (int) $reviewer_id, mb_substr(trim((string) $note), 0, 500), (int) $request_id]
    );
    logActivity((int) $reviewer_id, 'students', $approve ? 'Correction approved' : 'Correction rejected',
        "Request #{$req['request_id']} for student #{$req['student_id']}: {$fields[$req['field_name']]}"
        . ($approve ? " changed from '" . mb_substr((string) $req['old_value'], 0, 80) . "' to '" . mb_substr($req['new_value'], 0, 80) . "'" : ''));
    return ['success' => true];
}

/** Requests of one student (newest first). */
function srStudentRequests($student_id)
{
    srEnsureSchema();
    return getRecords("SELECT * FROM student_info_corrections WHERE student_id = ? ORDER BY request_id DESC LIMIT 20", [(int) $student_id]);
}

/** Pending requests count (dashboard / list badge). */
function srPendingCount()
{
    srEnsureSchema();
    $r = getRecord("SELECT COUNT(*) AS n FROM student_info_corrections WHERE status = 'Pending'");
    return (int) ($r['n'] ?? 0);
}
