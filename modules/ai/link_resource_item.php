<?php
// modules/ai/link_resource_item.php
// AJAX endpoint: link an ALREADY-GENERATED PDF/Word resource (one the
// teacher made before this feature existed, or made without picking a
// link at the time) to a real, EXISTING assessment_items row — the same
// link_category/link_term/link_item_id columns
// database/migrations/2026_ai_quiz_assessment_link.sql added for the
// Google Form quiz path, reused here as-is. Without this link, a
// confirmed score on the student's submission has nowhere in Grade
// Management to land, so the activity never clears from Missing
// Assessments no matter how it's checked — this is the backfill path for
// that (see modules/ai/resources.php's review modal), alongside
// generate_resource.php now offering the same choice up front for new
// activities.
//
// EXISTING ITEM ONLY, same as create_quiz_form.php's linking block —
// deliberately duplicated validation rather than shared, so each endpoint
// stays fully self-contained and easy to audit on its own. Never creates a
// brand-new assessment_items row.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php'; // isValidTerm()

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
$resource_id = (int) ($_POST['resource_id'] ?? 0);
$assessment_item_id = (int) ($_POST['assessment_item_id'] ?? 0);
$assessment_term = trim((string) ($_POST['assessment_term'] ?? ''));

if (!$teacher_id || !$resource_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing activity.']);
    exit;
}
if (!isValidTerm($assessment_term)) {
    echo json_encode(['success' => false, 'error' => 'Please select a valid grading term.']);
    exit;
}
if ($assessment_item_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Please select which existing assessment this activity recovers.']);
    exit;
}

// ---- Re-validate everything from scratch — never trust the browser's own
// notion of which resource/section/item this teacher can touch. ----
$resource = getRecord(
    "SELECT * FROM ai_generated_resources WHERE resource_id = ? AND teacher_id = ? AND audience = 'student'",
    [$resource_id, $teacher_id]
);
if (!$resource) {
    echo json_encode(['success' => false, 'error' => 'Activity not found, or does not belong to you.']);
    exit;
}

$student = getRecord("SELECT student_id, section_id FROM students WHERE student_id = ? AND is_active = 1", [(int) $resource['student_id']]);
if (!$student || empty($student['section_id'])) {
    echo json_encode(['success' => false, 'error' => 'Student not found.']);
    exit;
}

$teacher_subject = getRecord(
    "SELECT ts.* FROM teacher_subjects ts WHERE ts.teacher_id = ? AND ts.subject_code = ? AND ts.section_id = ? AND ts.school_year_id = ?",
    [$teacher_id, $resource['subject_code'], $student['section_id'], $resource['school_year_id']]
);
if (!$teacher_subject) {
    echo json_encode(['success' => false, 'error' => 'You are not assigned to teach this subject for this student\'s current section.']);
    exit;
}
$section_id = (int) $teacher_subject['section_id'];

$existing_item = getRecord(
    "SELECT * FROM assessment_items WHERE item_id = ? AND teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND is_active = 1",
    [$assessment_item_id, $teacher_id, $resource['subject_code'], $section_id, $resource['school_year_id']]
);
if (!$existing_item) {
    echo json_encode(['success' => false, 'error' => 'That assessment item could not be found, or does not belong to you. Please refresh and try again.']);
    exit;
}
if ($existing_item['term'] !== $assessment_term) {
    echo json_encode(['success' => false, 'error' => 'That assessment item belongs to a different term. Please refresh and try again.']);
    exit;
}
// The item's own category is authoritative — never anything the browser posted.
$assessment_category = $existing_item['category'];
// Performance Tasks are encoded and computed manually by the teacher; AI
// activities may only be linked to Written Works or Examinations items.
if (!gh_isAiAssistedCategory($assessment_category)) {
    echo json_encode(['success' => false, 'error' => 'Performance Tasks are encoded manually by the teacher, so an AI activity cannot be linked to one. Pick a Written Works or Examinations item instead.']);
    exit;
}

executeQuery(
    "UPDATE ai_generated_resources
     SET link_category = ?, link_term = ?, link_item_id = ?, grade_sync_status = NULL
     WHERE resource_id = ?",
    [$assessment_category, $assessment_term, (int) $existing_item['item_id'], $resource_id]
);

logActivity(
    $teacher_id,
    'AI Resource',
    'Linked activity to existing assessment item',
    "Resource #$resource_id ({$resource['title']}) linked to Assessment Item #{$existing_item['item_id']} ($assessment_category, $assessment_term)."
);

echo json_encode([
    'success' => true,
    'link_category' => $assessment_category,
    'link_term' => $assessment_term,
    'link_item_id' => (int) $existing_item['item_id'],
    'item_name' => $existing_item['item_name'],
    'highest_score' => (float) $existing_item['highest_score'],
]);
