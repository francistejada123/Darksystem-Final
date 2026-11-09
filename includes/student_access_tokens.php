<?php
// includes/student_access_tokens.php
// "Go to Student Portal" link in the enrollment approval email: a one-time,
// expiring access token that opens THAT student's own portal.
//
//   sptIssue($studentId)     -> the raw token (only ever in the email link). Only
//                               its SHA-256 hash is stored; issuing a new link
//                               revokes the student's older unused links.
//   sptConsume($rawToken)    -> the student it belongs to, once: the link must be
//                               unused, not revoked, not expired, and the student
//                               active with an Approved enrollment.
//   sptStartStudentSession() -> the same session student_login.php creates.
// The URL carries only the random token -- never the LRN, student ID or anything
// personal -- so changing it can't reach another student's account.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mail_config.php'; // appUrl()

const SPT_VALID_DAYS = 7;

function sptEnsureSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('student_portal_tokens_v1')) return;
    executeQuery(
        "CREATE TABLE IF NOT EXISTS student_portal_tokens (
            token_id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL COMMENT 'sha256 of the token in the email link (the token itself is never stored)',
            purpose VARCHAR(30) NOT NULL DEFAULT 'approval',
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            revoked_at DATETIME NULL,
            used_ip VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_spt_hash (token_hash),
            KEY idx_spt_student (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    schemaMarkReady('student_portal_tokens_v1');
}

/** New access token for one student (older unused ones are revoked). @return string raw token */
function sptIssue($studentId, $purpose = 'approval')
{
    sptEnsureSchema();
    $studentId = (int) $studentId;
    executeQuery("UPDATE student_portal_tokens SET revoked_at = NOW() WHERE student_id = ? AND used_at IS NULL AND revoked_at IS NULL", [$studentId]);
    $raw = bin2hex(random_bytes(32)); // 64 hex characters
    executeQuery(
        "INSERT INTO student_portal_tokens (student_id, token_hash, purpose, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL " . (int) SPT_VALID_DAYS . " DAY))",
        [$studentId, hash('sha256', $raw), mb_substr((string) $purpose, 0, 30)]
    );
    return $raw;
}

/** The full link for the email: APP_URL/student/access.php?token=... */
function sptAccessUrl($rawToken)
{
    return appUrl('student/access.php?token=' . rawurlencode($rawToken));
}

/** Issue a token for the active student with this LRN and return the link, or null. */
function sptAccessUrlForLrn($lrn)
{
    $lrn = trim((string) $lrn);
    if ($lrn === '') return null;
    $st = getRecord("SELECT student_id FROM students WHERE lrn = ? AND is_active = 1", [$lrn]);
    return $st ? sptAccessUrl(sptIssue((int) $st['student_id'])) : null;
}

/**
 * Check a token from a link and use it up.
 * @return array|null the student row (with enrollment + section) or null when invalid / expired / used
 */
function sptConsume($rawToken)
{
    sptEnsureSchema();
    $rawToken = (string) $rawToken;
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) return null;
    $t = getRecord("SELECT * FROM student_portal_tokens WHERE token_hash = ?", [hash('sha256', $rawToken)]);
    if (!$t || $t['used_at'] !== null || $t['revoked_at'] !== null || strtotime($t['expires_at']) < time()) return null;
    $student = getRecord(
        "SELECT s.*, e.enrollment_status, e.section_id, sec.section_name, sec.grade_level
           FROM students s
           JOIN enrollments e ON e.student_id = s.student_id
                AND e.school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1)
                AND e.enrollment_status IN ('Approved', 'Incomplete Requirements')
           LEFT JOIN sections sec ON sec.section_id = e.section_id
          WHERE s.student_id = ? AND s.is_active = 1",
        [(int) $t['student_id']]
    );
    if (!$student) return null;
    // one-time: the UPDATE only succeeds for the first click (no race)
    $stmt = executeQuery(
        "UPDATE student_portal_tokens SET used_at = NOW(), used_ip = ? WHERE token_id = ? AND used_at IS NULL AND revoked_at IS NULL",
        [mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), (int) $t['token_id']]
    );
    return ($stmt && $stmt->rowCount() === 1) ? $student : null;
}

/** Sign the student in exactly like student_login.php does. */
function sptStartStudentSession(array $student)
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION = [];                  // nobody else's session carries over
    session_regenerate_id(true);
    $_SESSION['student_id'] = $student['student_id'];
    $_SESSION['student_lrn'] = $student['lrn'];
    $_SESSION['student_name'] = $student['first_name'] . ' ' . $student['last_name'];
    $_SESSION['student_grade'] = $student['grade_level'];
    $_SESSION['student_section'] = $student['section_name'] ?? 'Not Assigned';
    $_SESSION['enrollment_status'] = $student['enrollment_status'] ?? 'Not Enrolled';
    $_SESSION['role'] = 'Student';
    $_SESSION['is_student_portal'] = true;
    $_SESSION['last_activity'] = time();
    $_SESSION['login_success_message'] = 'Welcome to your EGradePro Student Portal!';
}
