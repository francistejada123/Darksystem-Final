<?php
// includes/enrollment_lrn_card.php
// Online Enrollment -- Form 138 / Report Card (doc key "form138").
//
// The Report Card is uploaded as 2-3 pages under ONE requirement:
//   Page 1 / Front (required), Page 2 / Back / Grades (required), Page 3 (optional).
// All pages are read TOGETHER by the AI (Gemini, one request) as one document:
// student name, LRN, grade level, subject final grades, General Average and
// promotion remarks. Then:
//   verified   -> the LRN matches Step 2 AND the General Average was found
//   mismatch   -> "Wrong LRN number. The LRN entered in Personal Information does not match ..."
//   unreadable -> no clearly readable 12-digit LRN
//   incomplete -> "Complete Report Card grades could not be detected ..."
//   error      -> the reader is unavailable right now (try again)
// Nothing is accepted automatically. The detected LRN is normalized (spaces /
// dashes removed) and must be exactly 12 digits. The verified General Average
// is saved in the application and used for section assignment.
//
// The same pages are checked twice (live in Step 3, then again on submit); a
// successful read is kept in the session by the pages' SHA-1, so the second
// check is instant and cannot be bypassed with other files or another LRN.

require_once __DIR__ . '/gemini_service.php'; // callGeminiAPI(), extractGeminiRawText()

const OE_LRN_CARD_DOC_KEY = 'form138';
const OE_LRN_MSG_MISMATCH = 'Wrong LRN number. The LRN entered in Personal Information does not match the LRN found on the Report Card.';
const OE_LRN_MSG_UNREADABLE = 'LRN could not be verified. Please upload a clear and readable Report Card.';
const OE_CARD_MSG_INCOMPLETE = 'Complete Report Card grades could not be detected. Please upload the front and back/pages of your Form 138 clearly.';

/** The pages of the Report Card requirement: form field suffix => label, required. */
function oeReportCardPages()
{
    return [
        ''    => ['label' => 'Page 1 / Front', 'required' => true],
        '_p2' => ['label' => 'Page 2 / Back / Grades', 'required' => true],
        '_p3' => ['label' => 'Page 3', 'required' => false],
    ];
}

/** Digits only; '' unless exactly 12 digits remain. */
function oeNormalizeLrn($value)
{
    $digits = preg_replace('/\D+/', '', (string) $value);
    return strlen($digits) === 12 ? $digits : '';
}

/** MIME type of an uploaded page, or null when it is not an allowed kind. */
function oeReportCardMime($path)
{
    if (!is_file($path)) return null;
    $mime = function_exists('mime_content_type') ? (string) @mime_content_type($path) : '';
    return in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true) ? $mime : null;
}

/** Columns that keep what was read from the card (additive, once). */
function oeEnsureReportCardSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('report_card_ga_v1')) return;
    $cols = function ($t) {
        return array_column(getRecords("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t]), 'COLUMN_NAME');
    };
    $a = $cols('online_applications');
    if (!in_array('report_card_average', $a, true)) {
        executeQuery("ALTER TABLE online_applications ADD COLUMN report_card_average DECIMAL(5,2) NULL COMMENT 'General Average read from the verified Report Card'");
    }
    if (!in_array('report_card_data', $a, true)) {
        executeQuery("ALTER TABLE online_applications ADD COLUMN report_card_data TEXT NULL COMMENT 'JSON: what the AI read on the Report Card (name, LRN, grade, subjects, average, remarks)'");
    }
    $s = $cols('sections');
    if (!in_array('min_average', $s, true)) {
        executeQuery("ALTER TABLE sections ADD COLUMN min_average DECIMAL(5,2) NULL COMMENT 'Section assignment: lowest General Average for this section (NULL = not set)'");
    }
    if (!in_array('max_average', $s, true)) {
        executeQuery("ALTER TABLE sections ADD COLUMN max_average DECIMAL(5,2) NULL COMMENT 'Section assignment: highest General Average for this section (NULL = not set)'");
    }
    $a = $cols('online_applications');
    $s = $cols('sections');
    if (in_array('report_card_average', $a, true) && in_array('min_average', $s, true)) {
        schemaMarkReady('report_card_ga_v1');
    }
}

/**
 * Read the whole Report Card (all pages at once; cached per set of pages).
 * @param string[] $paths uploaded page files, in page order
 * @return array{ok:bool, data?:array, error?:string}
 *   data: name, lrn ('' unless 12 clear digits), grade_level, subjects[{subject,grade}], general_average (float|null), remarks
 */
function oeReportCardAnalyze(array $paths)
{
    $parts = [];
    $hashes = [];
    foreach ($paths as $p) {
        $mime = oeReportCardMime($p);
        if ($mime === null) continue;
        $parts[] = ['type' => $mime === 'application/pdf' ? 'document' : 'image', 'data' => base64_encode(file_get_contents($p)), 'mime_type' => $mime];
        $hashes[] = sha1_file($p);
    }
    $empty = ['name' => '', 'lrn' => '', 'grade_level' => '', 'subjects' => [], 'general_average' => null, 'remarks' => ''];
    if (!$parts) {
        return ['ok' => true, 'data' => $empty];
    }
    $key = sha1(implode('|', $hashes));
    if (isset($_SESSION['oe_card_read'][$key])) {
        return ['ok' => true, 'data' => $_SESSION['oe_card_read'][$key]];
    }

    $schema = [
        'type' => 'object',
        'properties' => [
            'student_name' => ['type' => 'string'],
            'lrn' => ['type' => 'string'],
            'lrn_clearly_readable' => ['type' => 'boolean'],
            'grade_level' => ['type' => 'string'],
            'subjects' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'subject' => ['type' => 'string'], 'final_grade' => ['type' => 'number'],
            ], 'required' => ['subject', 'final_grade']]],
            'general_average' => ['type' => 'number'],
            'general_average_clearly_readable' => ['type' => 'boolean'],
            'remarks' => ['type' => 'string'],
        ],
        'required' => ['student_name', 'lrn', 'lrn_clearly_readable', 'grade_level', 'subjects', 'general_average', 'general_average_clearly_readable', 'remarks'],
    ];
    $system = 'You read the pages of ONE Philippine DepEd learner Report Card (Form 138 / SF9). The pages are given together; '
        . 'the front usually has the name and LRN, the back/other page the grades. Read ALL pages as one document and report: '
        . 'student_name; lrn = the 12-digit Learner Reference Number exactly as printed (never guess, complete or invent digits); '
        . 'lrn_clearly_readable = false if there is no LRN or any digit is unclear; grade_level (e.g. "Grade 6"); '
        . 'subjects = each learning area with its FINAL rating for the year; general_average = the General Average exactly as printed '
        . '(0 if not shown); general_average_clearly_readable = false if it is missing or unclear; remarks = promotion remarks such as '
        . '"Promoted" (empty if none). Ignore school ID, dates, phone numbers and attendance. Reply with ONLY the JSON object.';

    $first = array_shift($parts);
    $first['extra'] = $parts;   // every other page in the SAME request
    $hadSession = session_status() === PHP_SESSION_ACTIVE;
    if ($hadSession) session_write_close();
    $api = callGeminiAPI($system, 'Read this Report Card (' . (count($parts) + 1) . ' page(s)).',
        ['type' => 'text', 'mime_type' => 'application/json', 'schema' => $schema], 75, $first);
    if ($hadSession) @session_start();

    if (empty($api['success'])) {
        return ['ok' => false, 'error' => (string) ($api['error'] ?? 'The reader is unavailable.')];
    }
    $text = extractGeminiRawText($api['response'] ?? null);
    $text = trim(preg_replace('/\s*```$/', '', preg_replace('/^```(?:json)?\s*/i', '', trim((string) $text))));
    $d = json_decode($text, true);
    if (!is_array($d)) {
        return ['ok' => false, 'error' => 'The Report Card could not be read.'];
    }
    $ga = (!empty($d['general_average_clearly_readable']) && is_numeric($d['general_average'] ?? null)) ? round((float) $d['general_average'], 2) : null;
    if ($ga !== null && ($ga < 60 || $ga > 100)) $ga = null;
    $subjects = [];
    foreach ((array) ($d['subjects'] ?? []) as $sj) {
        $g = $sj['final_grade'] ?? null;
        if (is_numeric($g) && $g >= 60 && $g <= 100 && trim((string) ($sj['subject'] ?? '')) !== '') {
            $subjects[] = ['subject' => mb_substr(trim((string) $sj['subject']), 0, 80), 'grade' => round((float) $g, 2)];
        }
    }
    $data = [
        'name' => mb_substr(trim((string) ($d['student_name'] ?? '')), 0, 150),
        'lrn' => !empty($d['lrn_clearly_readable']) ? oeNormalizeLrn($d['lrn'] ?? '') : '',
        'grade_level' => mb_substr(trim((string) ($d['grade_level'] ?? '')), 0, 30),
        'subjects' => array_slice($subjects, 0, 20),
        'general_average' => $ga,
        'remarks' => mb_substr(trim((string) ($d['remarks'] ?? '')), 0, 80),
    ];
    $_SESSION['oe_card_read'][$key] = $data;
    if (count($_SESSION['oe_card_read']) > 20) {
        $_SESSION['oe_card_read'] = array_slice($_SESSION['oe_card_read'], -20, null, true);
    }
    return ['ok' => true, 'data' => $data];
}

/**
 * Check the whole Report Card against the LRN from Step 2.
 * @param string[] $paths
 * @return array{status:string, message:string, detected:string, data:array}
 */
function oeReportCardCheck(array $paths, $enteredLrn)
{
    $entered = oeNormalizeLrn($enteredLrn);
    $read = oeReportCardAnalyze($paths);
    if (!$read['ok']) {
        return ['status' => 'error', 'message' => 'The Report Card could not be checked right now. Please try again in a moment.', 'detected' => '', 'data' => []];
    }
    $d = $read['data'];
    if ($d['lrn'] === '') {
        return ['status' => 'unreadable', 'message' => OE_LRN_MSG_UNREADABLE, 'detected' => '', 'data' => $d];
    }
    if ($entered === '') {
        return ['status' => 'mismatch', 'message' => 'Enter your 12-digit LRN in Personal Information first.', 'detected' => $d['lrn'], 'data' => $d];
    }
    if (!hash_equals($d['lrn'], $entered)) {
        return ['status' => 'mismatch', 'message' => OE_LRN_MSG_MISMATCH, 'detected' => $d['lrn'], 'data' => $d];
    }
    if ($d['general_average'] === null) {
        return ['status' => 'incomplete', 'message' => OE_CARD_MSG_INCOMPLETE, 'detected' => $d['lrn'], 'data' => $d];
    }
    return ['status' => 'verified', 'message' => 'LRN Verified', 'detected' => $d['lrn'], 'data' => $d];
}
