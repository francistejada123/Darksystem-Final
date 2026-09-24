<?php
// modules/ai/check_submission.php
// AJAX endpoint: the teacher clicks "Check with AI" on one manual-check
// submission in modules/ai/resources.php. Runs aiResourceRunAiCheck()
// (includes/ai_resource_submission_helpers.php), which reads the student's
// attached answer file (Word text extraction, or a photo/PDF sent to
// Gemini directly) and asks Gemini for a suggested score + feedback.
//
// This is ONLY ever a draft: it saves the suggestion as an editable
// starting point (status = 'AI-Checked') and returns it here so the
// browser can pre-fill the score/feedback fields — nothing here ever marks
// a submission Reviewed or touches the gradebook. That only happens when
// the teacher explicitly submits the review form (see resources.php's
// mark_reviewed handler -> aiResourceMarkReviewed()).
//
// Ownership is re-validated from scratch inside aiResourceRunAiCheck()
// itself (the submission's resource must belong to THIS teacher) — never
// trusted just because the row appeared in this teacher's own review page.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ai_resource_submission_helpers.php';

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
$submission_id = (int) ($_POST['submission_id'] ?? 0);

if (!$teacher_id || !$submission_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing submission.']);
    exit;
}

$result = aiResourceRunAiCheck($submission_id, $teacher_id);

echo json_encode($result);
