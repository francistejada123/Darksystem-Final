<?php
// modules/students/portal/submit_resource_answer.php
// POST handler: a student answers an AI-generated resource attached to a
// message on that subject's own page (modules/students/portal/subject.php;
// see includes/ai_resource_submission_helpers.php). Two shapes of
// submission arrive here, told apart by which fields are posted:
//   - quiz_answers[<index>] = <chosen choice index>       -> auto-graded
//   - answer_file (an uploaded file) + optional answer_note
//     -> the student downloaded the activity, answered it in their own
//        copy, and is attaching that file back for the teacher to check
//        manually. Nothing here ever scores a manual-check submission.
// Ownership is re-validated completely from scratch inside the helper
// functions this file calls, the same way every other AI-resource endpoint
// in this app does (never trust that an earlier page already checked it).
//
// Classic form POST + redirect back to subject.php?subject=<code>, matching
// this app's dominant style (no fetch/AJAX needed for a page the student is
// about to see refresh anyway). The manual-check form posts as
// multipart/form-data (it carries a file), so this file must be reached
// through that form -- never assume $_POST alone is the whole payload.
//
// The redirect target's subject code is looked up from the resource itself
// (never trusted from $_POST) so this always lands back on the correct
// subject page regardless of what the browser sends -- falling back to
// dashboard.php only if the resource can't be found at all (e.g. a stale
// resource_id), since there is no subject to redirect back to in that case.

session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/ai_resource_submission_helpers.php';
require_once __DIR__ . '/../../../includes/assessment_material_helpers.php'; // aim_taskWindowError() -- Start/Deadline set in Manage Assessment Items

if (!isset($_SESSION['student_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../../../student_login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$student_id = (int) $_SESSION['student_id'];
$resource_id = (int) ($_POST['resource_id'] ?? 0);

// Resolve where to send the student back to BEFORE doing anything else, so
// both the success and every error path below land on the right subject
// page. A resource that doesn't belong to this student simply resolves to
// "no known subject" -- redirectBack() then falls back to dashboard.php;
// the actual submit functions below independently re-validate ownership
// from scratch anyway, so nothing here is a security check.
$resource_subject = $resource_id ? getRecord(
    "SELECT subject_code FROM ai_generated_resources WHERE resource_id = ? AND student_id = ? AND audience = 'student'",
    [$resource_id, $student_id]
) : null;
$subject_code = $resource_subject['subject_code'] ?? null;

/**
 * Redirect back to this resource's subject page (or dashboard.php if the
 * subject couldn't be resolved), with optional extra query params and an
 * optional #resource-<id> fragment so the page can scroll straight to the
 * activity the student just acted on. $resourceId is kept as its own
 * parameter (not folded into $extraParams) precisely so it never becomes a
 * query param by mistake -- a URL fragment is not something
 * http_build_query() can build.
 */
function redirectBack($subjectCode, $extraParams = [], $resourceId = null)
{
    // Turned in from an activity's own page (Classwork): go back to it.
    $returnAct = (int) ($_POST['return_act'] ?? 0);
    if ($returnAct > 0) {
        $extraParams['act'] = $returnAct;
    }
    $base = !empty($subjectCode)
        ? ('subject.php?' . http_build_query($extraParams + ['subject' => $subjectCode]))
        : 'dashboard.php';
    $anchor = $resourceId ? ('#resource-' . (int) $resourceId) : '';
    header('Location: ' . $base . $anchor);
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    redirectBack($subject_code, ['submit_error' => 'Invalid security token. Please try again.'], $resource_id);
}

if (!$resource_id) {
    redirectBack($subject_code, ['submit_error' => 'Missing activity.']);
}

// An online assessment made from "Send to <item> AI" can only be answered
// between its Start Date & Time and its Deadline (after it, the student is
// marked Missing automatically).
$linked_item = getRecord("SELECT link_item_id FROM ai_generated_resources WHERE resource_id = ? AND student_id = ?", [$resource_id, $student_id]);
$window_error = aim_taskWindowError((int) ($linked_item['link_item_id'] ?? 0));
if ($window_error !== null) {
    redirectBack($subject_code, ['submit_error' => $window_error], $resource_id);
}

// quiz_form marks the multiple-choice modal, so a quiz sent with NO answer
// at all is still checked as a quiz (and rejected as incomplete) instead of
// falling through to the free-response path.
if ((isset($_POST['quiz_answers']) && is_array($_POST['quiz_answers'])) || !empty($_POST['quiz_form'])) {
    $result = aiResourceSubmitQuizAnswers($resource_id, $student_id, is_array($_POST['quiz_answers'] ?? null) ? $_POST['quiz_answers'] : []);
} else {
    $result = aiResourceSubmitManualAnswer(
        $resource_id,
        $student_id,
        $_POST['answer_note'] ?? '',
        $_FILES['answer_file'] ?? null
    );
}

if (!$result['success']) {
    redirectBack($subject_code, ['submit_error' => $result['error'] ?? 'Could not save your answer.'], $resource_id);
}

redirectBack($subject_code, ['submitted' => 1], $resource_id);
