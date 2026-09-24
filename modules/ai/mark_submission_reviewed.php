<?php
// modules/ai/mark_submission_reviewed.php
// AJAX twin of modules/ai/resources.php's "Confirm & Record Grade" form-post
// handler -- calls the exact same aiResourceMarkReviewed() (see
// includes/ai_resource_submission_helpers.php), just returning JSON instead
// of redirecting, so the "Ask AI" chat (modules/ai/index.php) can record a
// submission's final score right inside the conversation instead of
// sending the teacher to the separate Student Submissions page.
//
// Same contract as resources.php's handler: score/max_score are optional
// (blank = feedback-only review, no numeric grade); when both are given AND
// the resource is linked to a real gradebook item (link_item_id), the score
// is also synced into Grade Management and the activity clears from
// Missing Assessments -- see aiResourceMarkReviewed()'s own docblock for
// the full contract. Ownership is re-validated from scratch inside that
// function, exactly as resources.php already relies on -- never trusted
// just because a submission_id came from a card this teacher was looking
// at a moment ago.

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

$feedback = (string) ($_POST['teacher_feedback'] ?? '');
$score_raw = trim((string) ($_POST['score'] ?? ''));
$max_score_raw = trim((string) ($_POST['max_score'] ?? ''));
$score = ($score_raw !== '' && is_numeric($score_raw)) ? (float) $score_raw : null;
$max_score = ($max_score_raw !== '' && is_numeric($max_score_raw)) ? (float) $max_score_raw : null;

$result = aiResourceMarkReviewed($submission_id, $teacher_id, $feedback, $score, $max_score);

echo json_encode($result);
