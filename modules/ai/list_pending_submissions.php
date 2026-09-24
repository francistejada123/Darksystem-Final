<?php
// modules/ai/list_pending_submissions.php
// AJAX endpoint: lists this teacher's still-pending (not yet Reviewed)
// manual-check submissions for ONE student in ONE subject -- the "Ask AI"
// chat (modules/ai/index.php) calls this quietly the moment a subject's
// chat opens, so a teacher checking in on an at-risk student sees right
// there in the conversation that an activity is waiting to be checked,
// instead of only finding out on the separate Student Submissions page.
//
// Read-only -- never checks or records anything itself. See
// check_submission.php (AI check) and mark_submission_reviewed.php
// (confirm + record grade) for the actions this list's card offers.

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
$student_id = (int) ($_POST['student_id'] ?? 0);
$subject_code = trim((string) ($_POST['subject_code'] ?? ''));

if (!$teacher_id || !$student_id || $subject_code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing student or subject.']);
    exit;
}

$rows = aiResourceGetPendingSubmissionsForSubject($teacher_id, $student_id, $subject_code);

// Mirrors modules/ai/resources.php's own label tables exactly -- kept as a
// separate copy there and here on purpose (same convention
// link_resource_item.php already uses), so each small endpoint stays fully
// self-contained rather than depending on a shared "UI labels" file for a
// handful of strings.
$type_labels = [
    'quiz' => 'Quiz', 'practice_quiz' => 'Practice Quiz', 'reviewer' => 'Reviewer',
    'remedial_activity' => 'Remedial Activity', 'worksheet' => 'Worksheet', 'practice_exercise' => 'Practice Exercise',
    'performance_task' => 'Performance Task', 'written_work' => 'Written Work', 'question_bank' => 'Question Bank',
    'study_guide' => 'Study Guide', 'study_plan' => 'Study Plan', 'flashcards' => 'Flashcards',
    'short_assessment' => 'Short Assessment', 'multiple_choice' => 'Multiple Choice Questions',
    'true_false' => 'True/False Questions', 'identification' => 'Identification Questions', 'essay' => 'Essay Questions',
    'reflection_activity' => 'Reflection Activity', 'reading_activity' => 'Reading Activity',
    'intervention_activity' => 'Intervention Activity', 'learning_activity' => 'Learning Activity', 'rubric' => 'Rubric',
    'assignment' => 'Assignment', 'practice_test' => 'Practice Test', 'other' => 'Learning Resource',
];
$cat_labels = ['WW' => 'Written Works', 'PT' => 'Performance Tasks', 'QA' => 'Examinations'];

$out = [];
foreach ($rows as $s) {
    $ext = strtolower(pathinfo((string) ($s['answer_stored_filename'] ?? ''), PATHINFO_EXTENSION));
    $out[] = [
        'submission_id' => (int) $s['submission_id'],
        'resource_id' => (int) $s['resource_id'],
        'title' => $s['resource_title'],
        'type_label' => $type_labels[$s['resource_type']] ?? 'Learning Resource',
        'status' => $s['status'],
        'score' => $s['score'] !== null ? (float) $s['score'] : null,
        'max_score' => $s['max_score'] !== null ? (float) $s['max_score'] : null,
        'feedback' => $s['teacher_feedback'],
        'answer_source_type' => $s['answer_source_type'],
        'answer_original_filename' => $s['answer_original_filename'],
        'ai_checkable' => $ext !== '' && $ext !== 'doc',
        'submitted_at' => $s['submitted_at'],
        'is_linked' => !empty($s['link_item_id']),
        'link_category_label' => !empty($s['link_category']) ? ($cat_labels[$s['link_category']] ?? $s['link_category']) : null,
        'link_term' => $s['link_term'],
    ];
}

echo json_encode(['success' => true, 'submissions' => $out]);
