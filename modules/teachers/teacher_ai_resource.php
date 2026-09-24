<?php
// modules/teachers/teacher_ai_resource.php
// JSON endpoint behind the Teacher AI "generate -> create file/Form -> send to
// students" flow. Actions (all POST + CSRF, Teacher role only):
//   classes        the Grade Level / Section / Subject / Term choices -- ONLY
//                  classes this teacher is assigned to
//   roster         the enrolled students of one validated class
//   create_output  turn a generated draft into a PDF / Word / PowerPoint file or
//                  a Google Form (existing Forms integration)
//   send           deliver a created file/Form to selected students or the whole
//                  section
// All the rules live in includes/teacher_ai_resources.php; this file only
// authenticates, dispatches and shapes JSON. Nothing is trusted from the browser:
// class, subject, term and roster are re-validated on every call.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/teacher_ai_resources.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isLoggedIn() || !hasRole('Teacher')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Teacher AI is available only to authenticated teachers.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}
$csrfToken = (string) ($payload['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your session token expired. Refresh the page and try again.']);
    exit;
}

// A file/Form build can include a Gemini call (PowerPoint slides, quiz conversion).
@set_time_limit(180);

$teacherId = (int) ($_SESSION['user_id'] ?? 0);
$action = (string) ($payload['action'] ?? '');

switch ($action) {
    case 'classes':
        echo json_encode(teacherAiClassOptions($teacherId));
        break;

    case 'roster':
        $check = teacherAiValidateClass(
            $teacherId,
            (int) ($payload['section_id'] ?? 0),
            (string) ($payload['subject_code'] ?? ''),
            (string) ($payload['term'] ?? ''),
            $payload['grade_level'] ?? null
        );
        if (empty($check['success'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => $check['error']]);
            break;
        }
        $students = [];
        foreach (teacherAiClassRoster((int) $payload['section_id'], (int) $check['sy']['sy_id']) as $s) {
            $students[] = ['student_id' => (int) $s['student_id'], 'name' => teacherAiStudentDisplayName($s)];
        }
        echo json_encode(['success' => true, 'students' => $students, 'total' => count($students)]);
        break;

    case 'record_meta':
        // What "Record in Grade Management" needs for the chosen class: whether it
        // has an Examinations component, which ST1/ST2/TE tags it uses, and the
        // components of a multi-component learning area (MAPEH).
        $check = teacherAiValidateClass(
            $teacherId,
            (int) ($payload['section_id'] ?? 0),
            (string) ($payload['subject_code'] ?? ''),
            (string) ($payload['term'] ?? ''),
            $payload['grade_level'] ?? null
        );
        if (empty($check['success'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => $check['error']]);
            break;
        }
        $meta = aiAssessmentClassMeta($teacherId, (int) $payload['section_id'], (string) $payload['subject_code'], (int) $check['sy']['sy_id']);
        if (!$meta) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You can only record assessments for classes that are assigned to you.']);
            break;
        }
        echo json_encode([
            'success' => true,
            'has_exam' => (bool) $meta['has_exam'],
            'is_multi' => (bool) $meta['is_multi'],
            'components' => $meta['components'],
            'required_subtypes' => $meta['required_subtypes'],
        ]);
        break;

    case 'create_output':
        $result = teacherAiCreateOutput($teacherId, (int) ($payload['resource_id'] ?? 0), (string) ($payload['format'] ?? ''));
        if (empty($result['success'])) {
            error_log('Teacher AI create_output failed for teacher ' . $teacherId . ': ' . ($result['error'] ?? ''));
        }
        echo json_encode($result);
        break;

    case 'send':
        $result = teacherAiSendResource($teacherId, $payload);
        if (empty($result['success'])) {
            http_response_code(422);
        }
        echo json_encode($result);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown request.']);
}
