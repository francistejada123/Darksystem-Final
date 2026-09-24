<?php
// modules/ai/remediation.php
// JSON endpoint: the teacher clicked ONE missing assessment of an at-risk
// student (or group) in AI Analytics. POST + CSRF, Teacher role only.
//
//   action=generate  item_id, student_ids (JSON), teacher_note (optional)
//                    -> the make-up that matches the original assessment
//                       (quiz / activity / performance task). Generation only:
//                       nothing is saved and nothing is sent. The teacher
//                       reviews/edits it, then sends it:
//                         quiz     -> modules/ai/catchup.php (action=send)
//                         activity -> generate_resource.php (PDF + Word) + send_to_student.php
//                         task     -> generate_resource.php (PDF) + send_to_student.php
//
// See includes/ai_remediation.php. Everything is re-validated here.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ai_remediation.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isLoggedIn() || !hasRole('Teacher')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}
if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token. Please refresh the page and try again.']);
    exit;
}
if (($_POST['action'] ?? '') !== 'generate') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown request.']);
    exit;
}

@set_time_limit(240);
session_write_close(); // the AI call can take a while; do not lock the rest of the page

$teacherId = (int) ($_SESSION['user_id'] ?? 0);
$studentIds = json_decode((string) ($_POST['student_ids'] ?? '[]'), true);
$note = (string) ($_POST['teacher_note'] ?? '');

$practice = (($_POST['purpose'] ?? '') === 'practice'); // a review of a LOW-scored item's topic -- not graded
$r = aiRemediationResolve($teacherId, (int) ($_POST['item_id'] ?? 0), is_array($studentIds) ? $studentIds : [], $practice);
if (empty($r['success'])) {
    echo json_encode(['success' => false, 'error' => $r['error']]);
    exit;
}
$item = $r['item'];
$mode = $practice ? 'activity' : aiRemediationMode($item);
$labels = aiAssessmentCategoryLabels();
$itemName = html_entity_decode((string) $item['item_name'], ENT_QUOTES, 'UTF-8');
$record = [
    'item_id'        => (int) $item['item_id'],
    'item_name'      => $itemName,
    'label'          => $itemName . ' (' . ($labels[$item['category']] ?? $item['category']) . ')', // catch-up quiz label
    'category'       => $item['category'],
    'category_label' => $labels[$item['category']] ?? $item['category'],
    'term'           => (string) $item['term'],
    'hps'            => (float) $item['highest_score'],
    'topic'          => $r['topic'],
    'subject_code'   => (string) $item['subject_code'],
    'kind_label'     => aim_kindMeta(aim_assessmentKind($item))['label'],
];
$lesson = aiRemediationLesson($item);
$base = [
    'success'      => true,
    'mode'         => $mode,
    'record'       => $record,
    'eligible_ids' => array_map('intval', $r['ok']),
    'skipped'      => $r['skipped'],
    'lesson_used'  => !empty($lesson['names']),
    'practice'     => $practice,
];

if ($mode === 'quiz') {
    $quiz = aiCatchupGenerate($r['grade_level'], $r['subject_name'], $item['term'], $itemName, $record['category_label'], $note, $r['topic'], $lesson['text'] ?? '');
    if (empty($quiz['success'])) {
        echo json_encode(['success' => false, 'error' => $quiz['error']]);
        exit;
    }
    echo json_encode($base + [
        'title'        => $quiz['title'],
        'message'      => $quiz['message'],
        'questions'    => $quiz['questions'],
        'total_points' => $quiz['total_points'],
        'delivery'     => (function_exists('googleFormsIsConfigured') && googleFormsIsConfigured()) ? 'gform' : 'portal',
    ]);
    exit;
}

$doc = aiRemediationGenerateDoc($mode, $item, $r['topic'], $lesson, $note, $practice);
if (empty($doc['success'])) {
    echo json_encode(['success' => false, 'error' => $doc['error']]);
    exit;
}
echo json_encode($base + [
    'title'              => $doc['title'],
    'student_content'    => $doc['student_content'],
    'teacher_answer_key' => $doc['teacher_answer_key'],
    'message'            => $doc['message'],
    'resource_type'      => $practice ? 'practice_exercise' : ($mode === 'task' ? 'performance_task' : 'remedial_activity'),
    'formats'            => $mode === 'task' ? ['pdf'] : ['pdf', 'docx'],
]);
