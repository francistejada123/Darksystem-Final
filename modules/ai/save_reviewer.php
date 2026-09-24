<?php
// modules/ai/save_reviewer.php
// AJAX endpoint: the teacher reviews/edits an AI-generated reviewer (or
// their own manual edits to one already saved) inside the Ask AI modal
// and clicks "Save Draft" or "Publish".
//
// This is the ONLY place a reviewer is written to the database or made
// visible to a student — modules/ai/chatbot.php's generate_reviewer
// action only ever returns a draft string, never saves anything.
// Ownership/eligibility is re-validated here from scratch, the same way
// send_to_student.php and chatbot.php do it, rather than trusting that an
// earlier generate call already checked it.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/gemini_service.php'; // validateTeacherSubjectForStudent()

header('Content-Type: application/json');

if (!hasRole('Teacher')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token. Please refresh the page and try again.']);
    exit;
}

$teacher_id = (int) ($_SESSION['user_id'] ?? 0);
$student_id = (int) ($_POST['student_id'] ?? 0);
$subject_code = trim((string) ($_POST['subject_code'] ?? ''));
$content = trim((string) ($_POST['content'] ?? ''));
$status = ($_POST['status'] ?? '') === 'Published' ? 'Published' : 'Draft';
$reviewer_id = (int) ($_POST['reviewer_id'] ?? 0);

if (!$teacher_id || !$student_id || $subject_code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing student or subject.']);
    exit;
}
if ($content === '') {
    echo json_encode(['success' => false, 'error' => 'Reviewer content cannot be empty.']);
    exit;
}
if (mb_strlen($content) > 8000) {
    echo json_encode(['success' => false, 'error' => 'Reviewer content is too long.']);
    exit;
}

// ---- Re-validate everything from scratch — never trust that an earlier
// generate call, or the reviewer_id itself, already checked this. ----
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
if (!$active_sy) {
    echo json_encode(['success' => false, 'error' => 'No active school year is set.']);
    exit;
}

$check = validateTeacherSubjectForStudent($teacher_id, $student_id, $subject_code, $active_sy['sy_id']);
if (!$check['success']) {
    echo json_encode(['success' => false, 'error' => $check['error']]);
    exit;
}

$published_at_sql = $status === 'Published' ? 'NOW()' : 'NULL';

if ($reviewer_id > 0) {
    // Only ever update a reviewer that is actually this teacher's own draft
    // for this exact student+subject — never trust the posted ID alone.
    $existing = getRecord(
        "SELECT reviewer_id FROM ai_reviewers WHERE reviewer_id = ? AND teacher_id = ? AND student_id = ? AND subject_code = ?",
        [$reviewer_id, $teacher_id, $student_id, $subject_code]
    );
    if (!$existing) {
        echo json_encode(['success' => false, 'error' => 'Reviewer not found.']);
        exit;
    }
    $ok = updateRecord(
        "UPDATE ai_reviewers SET content = ?, status = ?, published_at = " . $published_at_sql . " WHERE reviewer_id = ?",
        [$content, $status, $reviewer_id]
    );
    if ($ok === false) {
        echo json_encode(['success' => false, 'error' => 'Could not save the reviewer. Please try again.']);
        exit;
    }
} else {
    $reviewer_id = insertRecord(
        "INSERT INTO ai_reviewers (student_id, teacher_id, subject_code, school_year_id, content, status, published_at)
         VALUES (?, ?, ?, ?, ?, ?, " . $published_at_sql . ")",
        [$student_id, $teacher_id, $subject_code, $active_sy['sy_id'], $content, $status]
    );
    if (!$reviewer_id) {
        echo json_encode(['success' => false, 'error' => 'Could not save the reviewer. Please try again.']);
        exit;
    }
}

logActivity(
    $teacher_id,
    'AI Reviewer',
    $status === 'Published' ? 'Published reviewer' : 'Saved reviewer draft',
    "Reviewer #$reviewer_id for student #$student_id, subject $subject_code"
);

echo json_encode([
    'success' => true,
    'reviewer_id' => (int) $reviewer_id,
    'status' => $status,
]);
