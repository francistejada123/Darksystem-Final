<?php
// modules/grading/autosave_scores.php
// Auto-save for the Class Score Sheet (Encode Grades): the changed raw scores
// are saved as a DRAFT while the teacher types -- the same assessment_scores
// rows and the same checks as the Save / Finalize submit in index.php. Nothing
// is computed or finalized here, and students never see raw item scores: their
// grades appear only after "Finalize <term>".
//
// POST (JSON response): csrf_token, section_id, subject_code, term,
//   scores = {"<item_id>": {"<student_id>": "<raw score or blank>"}}
// A blank value means "No Score" (stored as NULL); 0 is a real score.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isLoggedIn() || !hasRole('Teacher')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}
if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your session expired. Refresh the page; your unsaved scores are still on screen.']);
    exit;
}
$teacher_id = (int) ($_SESSION['user_id'] ?? 0);
session_write_close();

$section_id = (int) ($_POST['section_id'] ?? 0);
$subject_code = trim((string) ($_POST['subject_code'] ?? ''));
$term = trim((string) ($_POST['term'] ?? ''));
$scores = json_decode((string) ($_POST['scores'] ?? '{}'), true);
$sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
if (!$sy || !$section_id || $subject_code === '' || !isValidTerm($term) || !is_array($scores)) {
    echo json_encode(['success' => false, 'error' => 'Missing class, subject or term.']);
    exit;
}
$sy_id = (int) $sy['sy_id'];

// Same ownership rule as Encode Grades: this teacher's subject in this section for this term.
$own = getRecord(
    "SELECT 1 FROM teacher_subjects ts JOIN subjects sub ON sub.subject_code = ts.subject_code
     WHERE ts.teacher_id = ? AND ts.section_id = ? AND ts.subject_code = ? AND sub.is_active = 1 AND (sub.term IS NULL OR sub.term = ?)",
    [$teacher_id, $section_id, $subject_code, $term]
);
if (!$own) {
    echo json_encode(['success' => false, 'error' => 'You are not assigned to this class/subject for the selected term.']);
    exit;
}
if (gh_isSectionTermCompleted($section_id, $sy_id, $term)) {
    echo json_encode(['success' => false, 'error' => $term . ' is already Completed for this section, so its scores are locked.']);
    exit;
}

$items = [];
foreach (getRecords(
    "SELECT item_id, item_name, highest_score FROM assessment_items
     WHERE teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ? AND is_active = 1",
    [$teacher_id, $subject_code, $section_id, $sy_id, $term]
) as $it) {
    $items[(int) $it['item_id']] = $it;
}
$roster = array_flip(array_map('intval', array_column(getRecords(
    "SELECT e.student_id FROM enrollments e JOIN students s ON s.student_id = e.student_id AND s.is_active = 1
     WHERE e.section_id = ? AND e.school_year_id = ? AND e.enrollment_status = 'Approved'",
    [$section_id, $sy_id]
), 'student_id')));

$saved = 0;
$errors = [];
foreach ($scores as $iid => $by_student) {
    $iid = (int) $iid;
    if (!isset($items[$iid]) || !is_array($by_student)) continue;
    foreach ($by_student as $sid => $raw) {
        $sid = (int) $sid;
        if (!isset($roster[$sid])) continue;
        $check = validateRawScore(is_scalar($raw) ? trim((string) $raw) : null, $items[$iid]['highest_score']);
        if (!$check['valid']) {
            $errors[] = ['item_id' => $iid, 'student_id' => $sid, 'error' => $check['error']];
            continue;
        }
        $existing = getRecord("SELECT score_id FROM assessment_scores WHERE item_id = ? AND student_id = ?", [$iid, $sid]);
        if ($existing) {
            executeQuery("UPDATE assessment_scores SET raw_score = ?, updated_at = NOW() WHERE score_id = ?", [$check['value'], $existing['score_id']]);
        } else {
            executeQuery("INSERT INTO assessment_scores (item_id, student_id, raw_score) VALUES (?, ?, ?)", [$iid, $sid, $check['value']]);
        }
        $saved++;
    }
}

echo json_encode(['success' => true, 'saved' => $saved, 'errors' => $errors, 'saved_at' => date('g:i A')]);
