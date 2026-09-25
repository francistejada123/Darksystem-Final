<?php
// modules/teachers/classwork_answer_key.php
// Teacher-only Answer Key PDF for one classwork (e.g. a Google Form quiz):
// every question with its choices and the correct answer. Made on the fly,
// never stored, and only for the teacher who posted the classwork.
//
// ?id=<assignment_id>

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/classwork_helpers.php';
require_once __DIR__ . '/../../includes/ai_document_generator.php'; // aiRenderResourcePdf()

if (!hasRole('Teacher')) {
    http_response_code(403);
    exit('Unauthorized.');
}
$teacher_id = (int) ($_SESSION['user_id'] ?? 0);
$a = getRecord(
    "SELECT a.*, s.subject_name, sec.section_name, sec.grade_level
       FROM classwork_assignments a
       LEFT JOIN subjects s ON s.subject_code = a.subject_code
       LEFT JOIN sections sec ON sec.section_id = a.section_id
      WHERE a.assignment_id = ? AND a.teacher_id = ?",
    [(int) ($_GET['id'] ?? 0), $teacher_id]
);
if (!$a) {
    http_response_code(404);
    exit('Answer key not found.');
}

$title = html_entity_decode((string) $a['title'], ENT_QUOTES, 'UTF-8');
$questions = json_decode((string) ($a['quiz_questions_json'] ?? ''), true) ?: [];
$lines = [];
if ($questions) {
    $total = 0;
    foreach (array_values($questions) as $i => $q) {
        $ci = (int) ($q['correct_index'] ?? 0);
        $pts = max(1, (int) ($q['points'] ?? 1));
        $total += $pts;
        $lines[] = ($i + 1) . '. ' . trim((string) ($q['question'] ?? ''));
        foreach (($q['choices'] ?? []) as $k => $c) {
            $lines[] = chr(65 + $k) . '. ' . $c;
        }
        $lines[] = 'Answer: ' . chr(65 + $ci) . '. ' . ($q['choices'][$ci] ?? '') . ' (' . $pts . ' pt' . ($pts > 1 ? 's' : '') . ')';
        $lines[] = '';
    }
    $lines[] = 'Total: ' . $total . ' points';
} else {
    $lines[] = trim((string) ($a['answer_key'] ?? '')) !== '' ? (string) $a['answer_key'] : 'No answer key was saved for this classwork.';
}

$teacher = getRecord("SELECT first_name, last_name FROM users WHERE user_id = ?", [$teacher_id]);
try {
    $pdf = aiRenderResourcePdf(
        ['title' => 'Answer Key – ' . $title, 'resource_type' => 'quiz', 'student_content' => implode("\n", $lines), 'teacher_answer_key' => ''],
        [
            'student_name' => trim(($a['section_name'] ?? '') . ' (teacher copy — do not distribute)'),
            'grade_level' => (string) ($a['grade_level'] ?? ''),
            'subject_name' => (string) ($a['subject_name'] ?? $a['subject_code']),
            'teacher_name' => $teacher ? trim($teacher['first_name'] . ' ' . $teacher['last_name']) : '',
            'date' => date('F j, Y'),
        ],
        'student'
    );
} catch (\Throwable $e) {
    error_log('Answer key PDF failed for classwork #' . (int) $a['assignment_id'] . ': ' . $e->getMessage());
    http_response_code(500);
    exit('The answer key PDF could not be made right now. Please try again.');
}

$name = 'Answer Key - ' . preg_replace('/[^A-Za-z0-9 _.-]+/', '', $title) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
