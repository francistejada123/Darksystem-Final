<?php
// modules/ai/list_assessment_items.php
// AJAX endpoint for the "Create Google Form Quiz" picker in the Ask AI
// chat: given the student/subject already selected in that conversation
// and a Term, list this teacher's EXISTING assessment_items for that
// subject/section/term — each flagged with whether THIS student already
// has a score for it.
//
// WHY THIS EXISTS: an AI-generated quiz for an At-Risk student is a
// make-up / recovery assessment for something the student already missed
// (e.g. the class's "Quiz 3"), not a brand-new item that only this one
// student ends up with. The teacher picks the EXISTING item here; the
// quiz's score then gets saved onto THAT item (see create_quiz_form.php
// and includes/ai_quiz_grade_sync.php) so Manage Assessment Items and the
// Term Grade computation both see it as a normal, preserved score.
//
// Read-only. Same ownership re-validation as create_quiz_form.php — never
// trust the browser's own notion of which subjects/sections a teacher owns.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/gemini_service.php'; // validateTeacherSubjectForStudent()
require_once __DIR__ . '/../../includes/grading_helpers.php'; // getAssessmentItems(), isValidTerm()

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
$term = trim((string) ($_POST['term'] ?? ''));

if (!$teacher_id || !$student_id || $subject_code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing student or subject.']);
    exit;
}
if (!isValidTerm($term)) {
    echo json_encode(['success' => false, 'error' => 'Invalid term.']);
    exit;
}

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
if (!$active_sy) {
    echo json_encode(['success' => false, 'error' => 'No active school year is set.']);
    exit;
}

$check = validateTeacherSubjectForStudent($teacher_id, $student_id, $subject_code, $active_sy['sy_id']);
if (!$check['success']) {
    echo json_encode(['success' => false, 'error' => $check['error']]);
    exit;
}

$student = getRecord("SELECT student_id, section_id FROM students WHERE student_id = ? AND is_active = 1", [$student_id]);
if (!$student || empty($student['section_id'])) {
    echo json_encode(['success' => false, 'error' => 'Student not found.']);
    exit;
}

$teacher_subject = getRecord(
    "SELECT ts.* FROM teacher_subjects ts WHERE ts.teacher_id = ? AND ts.subject_code = ? AND ts.section_id = ? AND ts.school_year_id = ?",
    [$teacher_id, $subject_code, $student['section_id'], $active_sy['sy_id']]
);
if (!$teacher_subject) {
    echo json_encode(['success' => false, 'error' => 'You are not assigned to teach this subject for this student\'s current section.']);
    exit;
}
$section_id = (int) $teacher_subject['section_id'];

$grouped = getAssessmentItems($teacher_id, $subject_code, $section_id, $active_sy['sy_id'], $term);

// Which items THIS student already has a score for, so the picker can flag
// "Missing" vs "Current: x/y" — never hidden, just labeled, since a
// legitimate retake of an already-scored item is still the teacher's call.
$all_ids = array_merge(
    array_column($grouped['WW'], 'item_id'),
    array_column($grouped['PT'], 'item_id'),
    array_column($grouped['QA'], 'item_id')
);
$scores_map = getAssessmentScoresMap($all_ids);

$category_labels = ['WW' => 'Written Works', 'PT' => 'Performance Tasks', 'QA' => gh_examComponentLabel($active_sy)];
$out = [];
foreach (['WW', 'PT', 'QA'] as $cat) {
    // Performance Tasks are encoded and computed manually by the teacher —
    // the AI may only feed Written Works and Examinations, so PT items are
    // never offered as a link target.
    if (!gh_isAiAssistedCategory($cat)) continue;
    foreach ($grouped[$cat] as $item) {
        $raw = $scores_map[$item['item_id']][$student_id] ?? null;
        $out[] = [
            'item_id' => (int) $item['item_id'],
            'category' => $cat,
            'category_label' => $category_labels[$cat],
            'item_name' => $item['item_name'],
            'assessment_subtype' => $item['assessment_subtype'] ?? null,
            'highest_score' => (float) $item['highest_score'],
            'has_score' => $raw !== null,
            'current_score' => $raw !== null ? (float) $raw : null,
        ];
    }
}

echo json_encode(['success' => true, 'items' => $out, 'section_id' => $section_id]);
