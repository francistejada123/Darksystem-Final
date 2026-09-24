<?php
// modules/ai/sync_quiz_scores.php
// AJAX endpoint: called quietly (fire-and-forget from JS, no loading spinner
// needed) whenever a teacher opens the Ask AI chat for a student. Polls
// Google Forms for every Google-Form quiz THIS teacher has sent to THIS
// student, records any new submissions, and returns each quiz's latest
// score so the chat UI can announce "X answered — score Y/Z" the moment the
// teacher looks, without the teacher doing anything else. This is the
// "automatic" half of the score flow described in the feature request —
// the other half (aiQuizSyncFormResponses() also runs when the student
// opens their own Messages page) is in
// modules/students/portal/messages.php.
//
// Read-only from the teacher's point of view (no message is sent, no
// resource is created) — safe to call on every chat-open with no
// confirmation step, same as syncTutorialPlanItems()/aiQuizSyncFormResponses()
// being safe to call repeatedly.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ai_quiz_gform_helpers.php';

header('Content-Type: application/json');

if (!hasRole('Teacher')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token.']);
    exit;
}

$teacher_id = (int) ($_SESSION['user_id'] ?? 0);
$student_id = (int) ($_POST['student_id'] ?? 0);

if (!$teacher_id || !$student_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing student.']);
    exit;
}

// Only quizzes THIS teacher actually sent to THIS student — never any other
// teacher's, and never one still sitting as a Draft/Generated-but-unsent
// Form the student has no link to yet.
$quiz_resources = getRecords(
    "SELECT resource_id, title, quiz_max_score, quiz_one_attempt, sent_at
     FROM ai_generated_resources
     WHERE teacher_id = ? AND student_id = ? AND output_format = 'gform' AND status = 'Sent'
     ORDER BY sent_at DESC",
    [$teacher_id, $student_id]
);

$new_count = 0;
$quizzes = [];

foreach ($quiz_resources as $qr) {
    $result = aiQuizSyncFormResponses((int) $qr['resource_id']);
    $found_new = is_int($result) ? $result : 0;
    $new_count += $found_new;

    $responses = aiQuizGetResponses((int) $qr['resource_id']);
    $latest = $responses[0] ?? null;

    $quizzes[] = [
        'resource_id' => (int) $qr['resource_id'],
        'title' => $qr['title'],
        'max_score' => (int) $qr['quiz_max_score'],
        'one_attempt' => (bool) $qr['quiz_one_attempt'],
        'sent_at' => $qr['sent_at'],
        'attempt_count' => count($responses),
        'latest_score' => $latest ? (float) $latest['score'] : null,
        'latest_submitted_at' => $latest ? $latest['submitted_at'] : null,
        'new_this_check' => $found_new > 0,
    ];
}

echo json_encode([
    'success' => true,
    'new_count' => $new_count,
    'quizzes' => $quizzes,
]);
