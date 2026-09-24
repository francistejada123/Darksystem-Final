<?php
// modules/ai/group_recommendation.php
// AJAX endpoint for the grouped At-Risk Students view (modules/ai/index.php):
// returns ONE recommendation / remedial activity for a whole group of
// students -- same Grade Level + Subject + shared problem.
//
// Read-only: nothing is saved and nothing is sent to any student from here.
// Sending still happens only through modules/ai/send_to_student.php, one
// student at a time, on the teacher's own click.
//
// The browser's student list is never trusted on its own. For a subject
// group, every student is re-checked with validateTeacherSubjectForStudent()
// (a real at-risk subject that THIS teacher is assigned to); students that
// fail the check are dropped. No student names or grades are sent to Gemini
// (see includes/ai_group_recommendation.php).

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/gemini_service.php';
require_once __DIR__ . '/../../includes/ai_group_recommendation.php';

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
$kind = (string) ($_POST['kind'] ?? '');
if (!in_array($kind, ['missing', 'incomplete', 'below', 'attendance'], true)) {
    echo json_encode(['success' => false, 'error' => 'Unknown group type.']);
    exit;
}

$subject_code = trim((string) ($_POST['subject_code'] ?? ''));
$grade_level = aiGroupCleanLabel($_POST['grade_level'] ?? '', 40);
$term = aiGroupCleanLabel($_POST['term'] ?? '', 40);
$item = aiGroupCleanLabel($_POST['item'] ?? '', 150);
$note = aiGroupCleanLabel($_POST['teacher_note'] ?? '', 300); // optional: what the teacher wants changed/added
$concern = (string) ($_POST['concern'] ?? '');
if (!in_array($concern, ['consecutive', 'absences', 'late'], true)) $concern = '';

$raw_ids = json_decode((string) ($_POST['student_ids'] ?? '[]'), true);
$ids = [];
if (is_array($raw_ids)) {
    foreach ($raw_ids as $v) {
        $v = (int) $v;
        if ($v > 0) $ids[$v] = $v;
        if (count($ids) >= 60) break;
    }
}
$ids = array_values($ids);
if (!$teacher_id || empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'No students in this group.']);
    exit;
}
if ($kind === 'missing' && $item === '') {
    echo json_encode(['success' => false, 'error' => 'Missing task name.']);
    exit;
}

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
if (!$active_sy) {
    echo json_encode(['success' => false, 'error' => 'No active school year is set.']);
    exit;
}

$subject_name = '';
$grade_min = null;
$grade_max = null;
$count = 0;

if ($kind !== 'attendance') {
    if ($subject_code === '') {
        echo json_encode(['success' => false, 'error' => 'Missing subject.']);
        exit;
    }
    // Keep only students with a real at-risk subject that this teacher teaches.
    foreach ($ids as $sid) {
        $check = validateTeacherSubjectForStudent($teacher_id, $sid, $subject_code, $active_sy['sy_id']);
        if (!$check['success']) continue;
        $count++;
        $subject_name = $subject_name !== '' ? $subject_name : (string) $check['subject']['subject_name'];
        $fg = (float) ($check['subject']['final_grade'] ?? 0);
        $grade_min = $grade_min === null ? $fg : min($grade_min, $fg);
        $grade_max = $grade_max === null ? $fg : max($grade_max, $fg);
    }
    if ($count === 0) {
        echo json_encode(['success' => false, 'error' => 'You can only get a recommendation for a subject you teach.']);
        exit;
    }
} else {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $row = getRecord("SELECT COUNT(*) AS c FROM students WHERE is_active = 1 AND student_id IN ($placeholders)", $ids);
    $count = (int) ($row['c'] ?? 0);
    if ($count === 0) {
        echo json_encode(['success' => false, 'error' => 'No students found for this group.']);
        exit;
    }
}

$result = aiBuildGroupRecommendation([
    'kind' => $kind,
    'grade_level' => $grade_level,
    'subject_name' => $subject_name,
    'term' => $term,
    'item' => $item,
    'concern' => $concern,
    'note' => $note,
    'count' => $count,
    'grade_min' => $grade_min,
    'grade_max' => $grade_max,
]);

echo json_encode([
    'success' => true,
    'source' => $result['source'],
    'count' => $count,
    'recommendation' => $result['recommendation'],
]);
