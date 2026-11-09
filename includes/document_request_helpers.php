<?php
// includes/document_request_helpers.php
// Official Document Requests (e.g. Certificate of Enrollment for 4Ps,
// scholarship, financial assistance). SEPARATE from Enrollment Registration:
// a student asks for a document with a purpose; staff process it
// (Pending -> Processing -> Ready for Release -> Released, or Cancelled).
//
// Only one new table, `document_requests`, created on first use (same
// schemaIsReady()/schemaMarkReady() pattern used elsewhere). No existing
// table is changed. Student / enrollment data are read from the existing
// students / enrollments / sections / school_years tables; the grade level,
// strand and section at the time of the request are copied onto the request
// so the record stays readable later.

require_once __DIR__ . '/../config/database.php';

const DR_PENDING = 'Pending';
const DR_PROCESSING = 'Processing';
const DR_READY = 'Ready for Release';
const DR_RELEASED = 'Released';
const DR_CANCELLED = 'Cancelled';

// Document types (labels shown to students and staff). Older requests may
// still carry the earlier label 'Certificate of Enrollment' -- treated as COE.
const DR_DOC_COE = 'Certificate of Enrollment (COE)';
const DR_DOC_COM = 'Certificate of Matriculation (COM)';
const DR_DOC_GRADES = 'Certificate of Grades';
const DR_DOC_OTHER = 'Other Document';

function drEnsureSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('document_requests_v3')) return;
    executeQuery(
        "CREATE TABLE IF NOT EXISTS document_requests (
            request_id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            enrollment_id INT NULL,
            school_year_id INT NULL,
            grade_level VARCHAR(20) NULL COMMENT 'at the time of the request',
            strand VARCHAR(50) NULL COMMENT 'at the time of the request (SHS)',
            section_id INT NULL COMMENT 'at the time of the request',
            document_type VARCHAR(100) NOT NULL,
            document_other VARCHAR(255) NULL COMMENT 'name of the document when document_type = Other Document',
            purpose VARCHAR(50) NOT NULL,
            purpose_other VARCHAR(255) NULL,
            note TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Pending',
            requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processing_at DATETIME NULL,
            ready_at DATETIME NULL,
            released_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            cancelled_by_student TINYINT(1) NOT NULL DEFAULT 0,
            claiming_date DATE NULL COMMENT 'scheduled claiming date (set when marked Ready for Release)',
            claiming_time_from TIME NULL,
            claiming_time_until TIME NULL,
            claiming_location VARCHAR(150) NULL,
            claiming_instructions TEXT NULL,
            processed_by INT NULL COMMENT 'users.user_id of the last staff action',
            released_by INT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_dr_student (student_id),
            KEY idx_dr_enrollment (enrollment_id),
            KEY idx_dr_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    // Older tables get the newer columns (additive only; every existing request is kept).
    $add = [
        'document_other' => "VARCHAR(255) NULL COMMENT 'name of the document when document_type = Other Document' AFTER document_type",
        'claiming_date' => "DATE NULL COMMENT 'scheduled claiming date (set when marked Ready for Release)' AFTER cancelled_by_student",
        'claiming_time_from' => "TIME NULL AFTER claiming_date",
        'claiming_time_until' => "TIME NULL AFTER claiming_time_from",
        'claiming_location' => "VARCHAR(150) NULL AFTER claiming_time_until",
        'claiming_instructions' => "TEXT NULL AFTER claiming_location",
    ];
    foreach ($add as $colName => $def) {
        $col = getRecord("SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests' AND COLUMN_NAME = ?", [$colName]);
        if ((int) ($col['n'] ?? 0) === 0) {
            executeQuery("ALTER TABLE document_requests ADD COLUMN $colName $def");
        }
    }
    schemaMarkReady('document_requests_v1');
    schemaMarkReady('document_requests_v2');
    schemaMarkReady('document_requests_v3');
}

/** Documents a student may request (Enrollment Registration is NOT here: it is always available). */
function drDocumentTypes()
{
    return [DR_DOC_COE, DR_DOC_COM, DR_DOC_GRADES, DR_DOC_OTHER];
}

/** coe | com | grades | other -- what the document is, independent of the label stored. */
function drDocKind($documentType)
{
    $t = trim((string) $documentType);
    if ($t === DR_DOC_COE || $t === 'Certificate of Enrollment') return 'coe';
    if ($t === DR_DOC_COM) return 'com';
    if ($t === DR_DOC_GRADES) return 'grades';
    return 'other';
}

/** Document as shown to people: the specified name for an Other Document. */
function drDocumentLabel(array $r)
{
    if (drDocKind($r['document_type']) === 'other' && trim((string) ($r['document_other'] ?? '')) !== '') {
        return trim($r['document_other']);
    }
    return $r['document_type'];
}

function drPurposes()
{
    return ['4Ps Requirement', 'Scholarship', 'Financial Assistance', 'School Requirement',
            'Transfer / Admission Requirement', 'Government Requirement', 'Personal Copy', 'Other'];
}

function drActiveStatuses()
{
    return [DR_PENDING, DR_PROCESSING, DR_READY];
}

/** Purpose as shown to people ("Other: <text>" becomes just the text). */
function drPurposeLabel(array $r)
{
    return ($r['purpose'] === 'Other' && trim((string) ($r['purpose_other'] ?? '')) !== '') ? trim($r['purpose_other']) : $r['purpose'];
}

function drStatusBadgeClass($status)
{
    switch ($status) {
        case DR_PENDING: return 'warning';
        case DR_PROCESSING: return 'info';
        case DR_READY: return 'primary';
        case DR_RELEASED: return 'success';
        default: return 'secondary';
    }
}

/** Allowed staff transitions: status => [next status => button label]. Nothing goes backwards. */
function drStaffTransitions()
{
    return [
        DR_PENDING => [DR_PROCESSING => 'Start Processing', DR_CANCELLED => 'Cancel Request'],
        DR_PROCESSING => [DR_READY => 'Mark Ready for Release', DR_CANCELLED => 'Cancel Request'],
        DR_READY => [DR_RELEASED => 'Mark as Released'],
    ];
}

/** The student's current enrollment that can be certified (active school year, Approved / Incomplete Requirements). */
function drCurrentEnrollment($studentId)
{
    return getRecord(
        "SELECT e.enrollment_id, e.school_year_id, e.grade_level, e.strand, e.section_id, e.enrollment_status,
                sec.section_name, sec.strand AS section_strand, sy.sy_name
           FROM enrollments e
           JOIN school_years sy ON sy.sy_id = e.school_year_id AND sy.is_active = 1
           LEFT JOIN sections sec ON sec.section_id = e.section_id
          WHERE e.student_id = ? AND e.enrollment_status IN ('Approved', 'Incomplete Requirements')
          ORDER BY e.enrollment_id DESC LIMIT 1",
        [(int) $studentId]
    );
}

/**
 * Student submits a request. Everything about the student/enrollment is taken
 * from the database, never from the form.
 * @return array{ok:bool, message:string, request_id?:int}
 */
function drCreateRequest($studentId, $documentType, $purpose, $purposeOther, $note, $documentOther = '')
{
    drEnsureSchema();
    $documentType = trim((string) $documentType);
    $documentOther = trim(preg_replace('/\s+/', ' ', (string) $documentOther));
    $purpose = trim((string) $purpose);
    $purposeOther = trim((string) $purposeOther);
    $note = trim((string) $note);

    if (!in_array($documentType, drDocumentTypes(), true)) return ['ok' => false, 'message' => 'Please select a document type.'];
    $kind = drDocKind($documentType);
    if ($kind === 'other' && $documentOther === '') return ['ok' => false, 'message' => 'Please specify the document you need.'];
    if (mb_strlen($documentOther) > 255) return ['ok' => false, 'message' => 'The document name is too long (255 characters at most).'];
    if ($kind !== 'other') $documentOther = '';
    if (!in_array($purpose, drPurposes(), true)) return ['ok' => false, 'message' => 'Please select a purpose.'];
    if ($purpose === 'Other' && $purposeOther === '') return ['ok' => false, 'message' => 'Please specify the purpose.'];
    if (mb_strlen($purposeOther) > 255) return ['ok' => false, 'message' => 'The specified purpose is too long (255 characters at most).'];
    if (mb_strlen($note) > 1000) return ['ok' => false, 'message' => 'The note is too long (1000 characters at most).'];

    $en = drCurrentEnrollment($studentId);
    if (!$en) return ['ok' => false, 'message' => 'You need an approved enrollment for the current school year to request this document.'];

    // Same student + enrollment + document (COE also matches the older 'Certificate of Enrollment'
    // label; an Other Document matches on its specified name, ignoring case and spacing).
    if ($kind === 'other') {
        $dup = getRecord(
            "SELECT request_id FROM document_requests
              WHERE student_id = ? AND enrollment_id = ? AND document_type = ? AND LOWER(TRIM(COALESCE(document_other, ''))) = ?
                AND status IN ('Pending', 'Processing', 'Ready for Release') LIMIT 1",
            [(int) $studentId, (int) $en['enrollment_id'], DR_DOC_OTHER, mb_strtolower($documentOther)]
        );
    } else {
        $same = $kind === 'coe' ? [DR_DOC_COE, 'Certificate of Enrollment'] : [$documentType];
        $dup = getRecord(
            "SELECT request_id FROM document_requests
              WHERE student_id = ? AND enrollment_id = ? AND document_type IN (" . implode(',', array_fill(0, count($same), '?')) . ")
                AND status IN ('Pending', 'Processing', 'Ready for Release') LIMIT 1",
            array_merge([(int) $studentId, (int) $en['enrollment_id']], $same)
        );
    }
    if ($dup) return ['ok' => false, 'message' => 'You already have an active request for this document.'];

    $id = insertRecord(
        "INSERT INTO document_requests (student_id, enrollment_id, school_year_id, grade_level, strand, section_id,
                                        document_type, document_other, purpose, purpose_other, note, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')",
        [(int) $studentId, (int) $en['enrollment_id'], (int) $en['school_year_id'], $en['grade_level'],
         ($en['strand'] ?: $en['section_strand']) ?: null, $en['section_id'] ?: null,
         $documentType, $documentOther !== '' ? $documentOther : null, $purpose, $purpose === 'Other' ? $purposeOther : null, $note !== '' ? $note : null]
    );
    if (!$id) return ['ok' => false, 'message' => 'The request could not be saved. Please try again.'];

    // Tell the enrollment staff (same pattern as new online applications).
    $st = getRecord("SELECT first_name, last_name FROM students WHERE student_id = ?", [(int) $studentId]);
    $name = trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? ''));
    foreach (getRecords("SELECT u.user_id FROM users u JOIN roles r ON u.role_id = r.role_id
                          WHERE r.role_name IN ('Administrator', 'Registrar') AND u.is_active = 1") ?: [] as $s) {
        executeQuery(
            "INSERT INTO notifications (user_id, title, message, type, link, is_read) VALUES (?, ?, ?, 'info', ?, 0)",
            [$s['user_id'], 'New Document Request',
             $name . ' requested: ' . ($documentOther !== '' ? $documentOther . ' (Other Document)' : $documentType) . ' -- ' . ($purpose === 'Other' ? $purposeOther : $purpose) . '.',
             'modules/enrollment/document_requests.php?id=' . (int) $id]
        );
    }
    return ['ok' => true, 'message' => 'Your request was submitted. You can track its status below.', 'request_id' => (int) $id];
}

/** Student cancels their OWN request, only while it is still Pending. */
function drStudentCancel($studentId, $requestId)
{
    drEnsureSchema();
    $r = getRecord("SELECT request_id, status FROM document_requests WHERE request_id = ? AND student_id = ?", [(int) $requestId, (int) $studentId]);
    if (!$r) return ['ok' => false, 'message' => 'Request not found.'];
    if ($r['status'] !== DR_PENDING) return ['ok' => false, 'message' => 'Only a Pending request can be cancelled.'];
    executeQuery(
        "UPDATE document_requests SET status = 'Cancelled', cancelled_at = NOW(), cancelled_by_student = 1
          WHERE request_id = ? AND student_id = ? AND status = 'Pending'",
        [(int) $requestId, (int) $studentId]
    );
    return ['ok' => true, 'message' => 'Your request was cancelled.'];
}

/** A student's own requests, newest first. */
function drStudentRequests($studentId)
{
    drEnsureSchema();
    return getRecords("SELECT * FROM document_requests WHERE student_id = ? ORDER BY requested_at DESC, request_id DESC", [(int) $studentId]) ?: [];
}

/** Staff moves a request one step forward (or cancels it) -- only the transitions in drStaffTransitions(). */
/**
 * Claiming details entered when a request is marked Ready for Release.
 * Required: date (today or later) and location. Optional: Available From /
 * Until (Until alone is not allowed; with both, Until must be later) and
 * instructions. @return array{ok:bool, message?:string, data?:array}
 */
function drValidateClaiming(array $in)
{
    $date = trim((string) ($in['claiming_date'] ?? ''));
    $from = trim((string) ($in['claiming_time_from'] ?? ''));
    $until = trim((string) ($in['claiming_time_until'] ?? ''));
    $loc = trim(preg_replace('/\s+/', ' ', (string) ($in['claiming_location'] ?? '')));
    $ins = trim((string) ($in['claiming_instructions'] ?? ''));
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    if ($date === '' || !$d || $d->format('Y-m-d') !== $date) return ['ok' => false, 'message' => 'Please enter the Claiming Date.'];
    if ($date < date('Y-m-d')) return ['ok' => false, 'message' => 'The Claiming Date cannot be in the past.'];
    if ($loc === '') return ['ok' => false, 'message' => 'Please enter the Claiming Location.'];
    if (mb_strlen($loc) > 150) return ['ok' => false, 'message' => 'The Claiming Location is too long (150 characters at most).'];
    if (mb_strlen($ins) > 1000) return ['ok' => false, 'message' => 'The instructions are too long (1000 characters at most).'];
    foreach (['from' => $from, 'until' => $until] as $k => $t) {
        if ($t !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t)) return ['ok' => false, 'message' => 'Please enter a valid time.'];
    }
    if ($from === '' && $until !== '') return ['ok' => false, 'message' => 'Enter "Available From" as well, or leave both times empty.'];
    if ($from !== '' && $until !== '' && substr($until, 0, 5) <= substr($from, 0, 5)) return ['ok' => false, 'message' => '"Available Until" must be later than "Available From".'];
    return ['ok' => true, 'data' => [
        'claiming_date' => $date, 'claiming_time_from' => $from !== '' ? substr($from, 0, 5) : null,
        'claiming_time_until' => $until !== '' ? substr($until, 0, 5) : null,
        'claiming_location' => $loc, 'claiming_instructions' => $ins !== '' ? $ins : null,
    ]];
}

/** "9:00 AM – 3:00 PM", "From 9:00 AM", or '' when no time was given (never a made-up time). */
function drClaimTimeText(array $r)
{
    $f = !empty($r['claiming_time_from']) ? date('g:i A', strtotime($r['claiming_time_from'])) : '';
    $u = !empty($r['claiming_time_until']) ? date('g:i A', strtotime($r['claiming_time_until'])) : '';
    if ($f !== '' && $u !== '') return $f . ' – ' . $u;
    return $f !== '' ? 'From ' . $f : '';
}

/**
 * $extra: for Ready for Release, the claiming details (validated here);
 * for Released, 'confirm_release' => 1 (the confirmation step).
 */
function drStaffSetStatus($requestId, $newStatus, $userId, array $extra = [])
{
    drEnsureSchema();
    $r = getRecord("SELECT * FROM document_requests WHERE request_id = ?", [(int) $requestId]);
    if (!$r) return ['ok' => false, 'message' => 'Request not found.'];
    $allowed = drStaffTransitions()[$r['status']] ?? [];
    if (!isset($allowed[$newStatus])) {
        return ['ok' => false, 'message' => 'This request is ' . $r['status'] . ' and cannot be changed to ' . $newStatus . '.'];
    }
    $set = '';
    $params = [$newStatus, (int) $userId];
    if ($newStatus === DR_READY) {
        $cv = drValidateClaiming($extra);
        if (!$cv['ok']) return ['ok' => false, 'message' => $cv['message']];
        $set = ', claiming_date = ?, claiming_time_from = ?, claiming_time_until = ?, claiming_location = ?, claiming_instructions = ?';
        $params = array_merge($params, array_values($cv['data']));
    }
    if ($newStatus === DR_RELEASED) {
        if (empty($extra['confirm_release'])) return ['ok' => false, 'message' => 'Please confirm that the document has been claimed/released.'];
        $set = ', released_by = ?';
        $params[] = (int) $userId;
    }
    $stamp = [DR_PROCESSING => 'processing_at', DR_READY => 'ready_at', DR_RELEASED => 'released_at', DR_CANCELLED => 'cancelled_at'][$newStatus];
    $params[] = (int) $requestId;
    $params[] = $r['status'];
    executeQuery(
        "UPDATE document_requests SET status = ?, $stamp = NOW(), processed_by = ?$set WHERE request_id = ? AND status = ?",
        $params
    );
    if (function_exists('logActivity')) {
        logActivity($userId, 'enrollment', 'Document request', 'Request #' . (int) $requestId . ': ' . $r['status'] . ' -> ' . $newStatus);
    }
    return ['ok' => true, 'message' => 'Request #' . (int) $requestId . ' is now ' . $newStatus . '.'];
}

/** Pending requests (sidebar badge); Enrollment Officers count only their grade level. */
function drPendingCount()
{
    try {
        drEnsureSchema();
        require_once __DIR__ . '/eo_scope.php';
        [$w, $p] = eoSql('grade_level');
        $row = getRecord("SELECT COUNT(*) AS c FROM document_requests WHERE status = 'Pending'" . $w, $p);
        return (int) ($row['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Certificate of Grades: OFFICIAL grades only -- grade rows that are valid
 * (gh_validTermSql rule: not Incomplete / Draft / Not configured) AND whose
 * subject-term has been finalized by the teacher (term_finalizations). Values
 * are read as stored; nothing is computed here.
 * @return array rows: subject_code, subject_name, term, final_grade, remarks
 */
function drOfficialGrades($studentId, $schoolYearId)
{
    $t = getRecord("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'term_finalizations'");
    if ((int) ($t['n'] ?? 0) === 0) return [];
    return getRecords(
        "SELECT g.subject_code, sub.subject_name, g.term, g.final_grade, g.remarks
           FROM grades g
           JOIN subjects sub ON sub.subject_code = g.subject_code
          WHERE g.student_id = ? AND g.school_year_id = ?
            AND g.term IN ('Term 1', 'Term 2', 'Term 3')
            AND (g.remarks IS NULL OR g.remarks NOT IN ('Incomplete', 'Not configured', 'Draft'))
            AND g.transmuted_grade >= 60
            AND EXISTS (SELECT 1 FROM term_finalizations f
                         WHERE f.subject_code = g.subject_code AND f.section_id = g.section_id
                           AND f.school_year_id = g.school_year_id AND f.term = g.term)
          ORDER BY sub.subject_name, g.term",
        [(int) $studentId, (int) $schoolYearId]
    ) ?: [];
}
