<?php
// modules/support/generate_draft.php
// TEACHER — AJAX endpoint: teacher clicked "Generate Draft" on
// modules/support/index.php. Every real validation (teacher assignment,
// student, at-risk subject match, data minimization, system instruction)
// happens inside generateStudentSupportDraft() — this file is just the
// thin JSON wrapper the browser talks to. The browser never talks to
// Gemini directly and never sees the API key.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/gemini_service.php';

header('Content-Type: application/json');

if (!hasRole('Teacher')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request. Please refresh the page and try again.']);
    exit;
}

$teacher_id = (int) $_SESSION['user_id'];
$student_id = intval($_POST['student_id'] ?? 0);
$subject_code = sanitize($_POST['subject_code'] ?? '');
$support_type = sanitize($_POST['support_type'] ?? '');
$teacher_instruction = sanitize($_POST['teacher_instruction'] ?? '');

$result = generateStudentSupportDraft($teacher_id, $student_id, $subject_code, $support_type, $teacher_instruction);

if (!$result['success']) {
    // These are all "this request doesn't make sense" cases (wrong
    // student/subject/teacher) rather than a Gemini outage — 422 so the
    // browser console distinguishes them from a 200, but the JSON body is
    // what the UI actually reads either way.
    $client_error_codes = ['bad_support_type', 'instruction_too_long', 'not_a_teacher', 'no_active_sy', 'no_student', 'not_at_risk_subject', 'not_assigned'];
    if (in_array($result['error_code'] ?? '', $client_error_codes, true)) {
        http_response_code(422);
    }
    echo json_encode(['success' => false, 'error' => $result['error']]);
    exit;
}

echo json_encode(['success' => true, 'draft' => $result['draft']]);
