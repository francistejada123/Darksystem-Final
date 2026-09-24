<?php
// modules/ai/catchup.php
// JSON endpoint behind the "Ask AI" catch-up session of the grouped At-Risk
// Students view (modules/ai/index.php). POST + CSRF, Teacher role only.
//
//   action=generate  the AI writes ONE make-up quiz for the whole group of
//                    students who are missing the same assessment. Read-only:
//                    nothing is saved and nothing is sent.
//   action=send      the teacher-approved quiz is delivered to the selected
//                    students; each copy is linked to the item they are missing,
//                    so their actual score reaches Manage Assessment Items and
//                    Grade Management as soon as they submit.
//
// All rules live in includes/ai_catchup.php. Nothing the browser posts is trusted:
// every student and the missing item are re-validated on every call.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ai_catchup.php';

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

@set_time_limit(300); // one Gemini call (plus a retry), or one Google Form per student

$teacherId = (int) ($_SESSION['user_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$studentIds = json_decode((string) ($_POST['student_ids'] ?? '[]'), true);
if (!is_array($studentIds)) {
    $studentIds = [];
}

switch ($action) {
    case 'generate':
        $resolved = aiCatchupResolveGroup($teacherId, $_POST['subject_code'] ?? '', $_POST['term'] ?? '', $_POST['item'] ?? '', $studentIds);
        if (empty($resolved['success'])) {
            echo json_encode(['success' => false, 'error' => $resolved['error']]);
            break;
        }
        $labels = aiAssessmentCategoryLabels();
        if (!gh_isAiAssistedCategory($resolved['category'])) {
            // A Performance Task cannot be replaced by a quiz -- the client falls back to a message.
            echo json_encode(['success' => false, 'error_code' => 'not_quizzable',
                'error' => 'Performance Tasks are scored by the teacher, so a quiz cannot replace them.']);
            break;
        }
        $quiz = aiCatchupGenerate(
            $resolved['grade_level'], $resolved['subject_name'], (string) $_POST['term'],
            $resolved['name'], $labels[$resolved['category']] ?? '', (string) ($_POST['teacher_note'] ?? '')
        );
        if (empty($quiz['success'])) {
            error_log('Catch-up quiz generation failed for teacher ' . $teacherId . ': ' . ($quiz['error'] ?? ''));
            echo json_encode(['success' => false, 'error' => $quiz['error']]);
            break;
        }
        echo json_encode([
            'success'      => true,
            'title'        => $quiz['title'],
            'message'      => $quiz['message'],
            'questions'    => $quiz['questions'],
            'total_points' => $quiz['total_points'],
            'delivery'     => (function_exists('googleFormsIsConfigured') && googleFormsIsConfigured()) ? 'gform' : 'portal',
            'eligible_ids' => array_map('intval', array_keys($resolved['ok'])),
            'skipped'      => $resolved['skipped'],
            'record'       => [
                'item_name'      => html_entity_decode($resolved['name'], ENT_QUOTES, 'UTF-8'),
                'category'       => $resolved['category'],
                'category_label' => $labels[$resolved['category']] ?? $resolved['category'],
                'term'           => (string) $_POST['term'],
                'hps'            => $resolved['hps'],
            ],
        ]);
        break;

    case 'send':
        $questions = json_decode((string) ($_POST['questions_json'] ?? '[]'), true);
        $result = aiCatchupSend($teacherId, [
            'subject_code' => $_POST['subject_code'] ?? '',
            'term'         => $_POST['term'] ?? '',
            'item'         => $_POST['item'] ?? '',
            'student_ids'  => $studentIds,
            'title'        => $_POST['title'] ?? '',
            'message'      => $_POST['message'] ?? '',
            'questions'    => is_array($questions) ? $questions : [],
        ]);
        if (empty($result['success'])) {
            http_response_code(422);
        }
        echo json_encode($result);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown request.']);
}
