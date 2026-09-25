<?php
// tests/run.php -- quick automated checks for EGRADEPRO.
//
//   C:\xampp\php\php.exe tests\run.php          (logic checks only)
//   C:\xampp\php\php.exe tests\run.php --http   (also checks the running site at http://localhost/watdahel)
//
// Read-only: every check that needs to write does it inside a transaction
// that is rolled back, so real data is never changed. The tests/ folder is
// blocked from the web (.htaccess), so this only runs from the command line.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(__DIR__ . '/..');
require 'config/database.php';
require 'includes/grading_helpers.php';
require 'includes/risk_helpers.php';
require 'includes/ai_quiz_grade_sync.php';
require 'includes/student_records_helpers.php';

$pass = 0;
$fail = 0;
function check($name, $ok, $detail = '')
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}
function section($t) { echo "\n== $t ==\n"; }

section('Scores: 0 to HPS, blank = No Score');
$r = validateRawScore('', 10);   check('blank is No Score (NULL)', $r['valid'] && $r['value'] === null);
$r = validateRawScore('0', 10);  check('0 is a real score', $r['valid'] && $r['value'] === 0.0);
$r = validateRawScore('10', 10); check('score equal to HPS is valid', $r['valid']);
$r = validateRawScore('11', 10); check('score above HPS is rejected', !$r['valid']);
$r = validateRawScore('-1', 10); check('negative score is rejected', !$r['valid']);
$r = validateRawScore('abc', 10); check('text is rejected', !$r['valid']);

section('At-risk make-up credit');
$pct = aiMakeupCreditPercent();
check('make-up credit is 1-100%', $pct > 0 && $pct <= 100, (string) $pct);
check('catch-up quiz is a make-up', aiIsAtRiskMakeup(['source_prompt' => 'Catch-up session for missing Quiz 3']));
check('make-up activity is a make-up', aiIsAtRiskMakeup(['source_prompt' => 'Make-up for missing Activity 1']));
check('review activity is NOT a make-up', !aiIsAtRiskMakeup(['source_prompt' => 'Review activity for Quiz 2']));
check('ordinary Ask AI quiz is NOT a make-up', !aiIsAtRiskMakeup(['source_prompt' => 'Quiz from chat']));

section('MAPEH components');
$four = ['Music', 'Arts', 'Physical Education', 'Health'];
check('no old tags -> the four components', gh_effectiveComponents($four, ['WW' => [['component' => 'Music']]]) === $four);
check('only old tags -> the old two', gh_effectiveComponents($four, ['WW' => [['component' => 'Music and Arts']]]) === ['Music and Arts', 'PE and Health']);
check('mixed -> four + old tag in use', gh_effectiveComponents($four, ['WW' => [['component' => 'Music and Arts'], ['component' => 'Arts']]]) === array_merge($four, ['Music and Arts']));

section('Terms');
$sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
check('an active school year exists', (bool) $sy);
if ($sy) {
    $noEnd = gh_termsWithoutEndDate((int) $sy['sy_id']);
    echo '        terms without an End Date: ' . ($noEnd ? implode(', ', $noEnd) : 'none') . "\n";
    check('term list check runs', is_array($noEnd));
}

section('At-Risk detection (runs on real data, read-only)');
if ($sy) {
    $ok = true; $n = 0; $err = '';
    foreach (getRecords("SELECT DISTINCT student_id FROM enrollments WHERE school_year_id = ? AND enrollment_status = 'Approved' LIMIT 50", [(int) $sy['sy_id']]) as $s) {
        try {
            foreach (getAtRiskSubjectRecords((int) $s['student_id'], (int) $sy['sy_id']) as $rec) {
                $n++;
                if (empty($rec['risk_reasons'])) { $ok = false; $err = 'a record without a reason'; }
                if (count($rec['missing_details']) === 1 && count($rec['risk_reasons']) === 1 && $rec['risk_reasons'][0]['type'] === 'missing') {
                    $ok = false; $err = 'flagged for ONE missing item only';
                }
            }
        } catch (\Throwable $e) { $ok = false; $err = $e->getMessage(); }
    }
    check("every at-risk record has a reason, none for 1 missing only ($n records)", $ok, $err);
}

section('Rules that write (rolled back)');
$db->beginTransaction();
try {
    // A real teacher/subject/section (foreign keys) with a term name no real item uses.
    $ts = getRecord("SELECT teacher_id, subject_code, section_id, school_year_id FROM teacher_subjects LIMIT 1");
    $T = [(int) $ts['teacher_id'], $ts['subject_code'], (int) $ts['section_id'], (int) $ts['school_year_id'], 'ZZTerm'];
    executeQuery("INSERT INTO assessment_items (teacher_id, subject_code, section_id, school_year_id, term, category, item_name, highest_score, item_order, assessment_subtype, is_active)
                  VALUES (?, ?, ?, ?, ?, 'QA', 'zz ST1', 10, 1, 'ST1', 1)", $T);
    $src = file_get_contents('modules/grading/items.php');
    $a = strpos($src, 'function itemsDuplicateExamMessage');
    $b = strpos($src, '// ============================================================', $a);
    if ($a !== false && $b !== false && !function_exists('itemsDuplicateExamMessage')) {
        eval(substr($src, $a, $b - $a));
    }
    $msg = itemsDuplicateExamMessage($T[0], $T[1], $T[2], $T[3], $T[4], 'ST1', null, ['ST1', 'ST2', 'TE'], gh_examSubtypes());
    check('a 2nd ST1 while ST2/TE are missing is refused', $msg !== '', $msg);
    $msg = itemsDuplicateExamMessage($T[0], $T[1], $T[2], $T[3], $T[4], 'ST2', null, ['ST1', 'ST2', 'TE'], gh_examSubtypes());
    check('adding the missing ST2 is allowed', $msg === '', $msg);

    $st = getRecord("SELECT student_id FROM students WHERE is_active = 1 LIMIT 1");
    if ($st) {
        $r = srRequestCorrection((int) $st['student_id'], 'birth_date', '2999-01-01', 'test', 'Registrar', 0);
        check('a future birth date is rejected', !$r['success']);
        $r = srRequestCorrection((int) $st['student_id'], 'gender', 'Unknown', 'test', 'Registrar', 0);
        check('gender must be Male or Female', !$r['success']);
    }
} catch (\Throwable $e) {
    check('write checks ran', false, $e->getMessage());
}
$db->rollBack();
check('rolled back (no test item left)', !getRecord("SELECT 1 FROM assessment_items WHERE item_name = 'zz ST1'"));

section('Schema checks run once');
$flags = array_column(getRecords("SELECT setting_key FROM system_settings WHERE setting_key LIKE 'schema\\_ok\\_%'"), 'setting_key');
check('schema flags are recorded', count($flags) > 0, implode(', ', $flags));

if (in_array('--http', $argv, true)) {
    section('Website (http://localhost/watdahel)');
    $get = function ($path) {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
        @file_get_contents('http://localhost/watdahel/' . $path, false, $ctx);
        preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0] ?? '', $m);
        return (int) ($m[1] ?? 0);
    };
    foreach (['egradepro.sql', 'database/egradepro.sql', 'composer.json', 'vendor/autoload.php', 'tests/run.php', 'AGENTS.md'] as $blocked) {
        $c = $get($blocked);
        check("blocked: $blocked", in_array($c, [403, 404], true), (string) $c);
    }
    foreach (['login.php', 'student_login.php', 'online_enrollment.php', 'static/css/style.css'] as $open) {
        $c = $get($open);
        check("reachable: $open", $c === 200, (string) $c);
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
