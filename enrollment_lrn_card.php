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
        $h = sha1_file($p);
        if (in_array($h, $hashes, true)) continue;   // the same photo in two page slots is read once
        $parts[] = ['type' => $mime === 'application/pdf' ? 'document' : 'image', 'data' => base64_encode(file_get_contents($p)), 'mime_type' => $mime];
        $hashes[] = $h;
    }
    $empty = ['name' => '', 'lrn' => '', 'grade_level' => '', 'subjects' => [], 'general_average' => null, 'remarks' => ''];
    if (!$parts) {
        return ['ok' => true, 'data' => $empty];
    }
    $key = 'v3:' . sha1(implode('|', $hashes)); // v3: grade_level now means the last grade completed -- older cached results are read again
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
    $system = 'You read the pages of ONE Philippine DepEd learner Report Card (Form 138 / SF9). The pages are given together and may be '
        . 'phone photos (tilted, rotated, shadowed) or scans; one photo may show both the front and the back side by side. '
        . 'The front usually has the name and LRN, the inside/back the grades. Read ALL pages as one document and report: '
        . 'student_name; lrn = the 12-digit Learner Reference Number (printed, typed or HANDWRITTEN, often in 12 small boxes or after "LRN:"), '
        . 'copied digit by digit -- never guess, complete or invent a digit; '
        . 'lrn_clearly_readable = true when you can read all 12 digits with confidence, false if there is no LRN or a digit is truly unreadable; '
        . 'grade_level = the LAST GRADE COMPLETED: the grade the learner was enrolled in for the school year of THIS card, as printed '
        . 'next to the learner name / section (e.g. "Grade 9"). It is NOT the "Eligible for admission to", "Promoted to" or "Admitted to" grade, '
        . 'and not the school year or the section; '
        . 'subjects = each learning area with its FINAL rating for the year; general_average = the General Average exactly as printed '
        . '(0 if not shown); general_average_clearly_readable = false if it is missing or unclear; remarks = promotion remarks such as '
        . '"Promoted" (empty if none). Ignore school ID, dates, phone numbers and attendance. Reply with ONLY the JSON object.';

    $first = array_shift($parts);
    $first['extra'] = $parts;   // every other page in the SAME request
    $format = ['type' => 'text', 'mime_type' => 'application/json', 'schema' => $schema];
    $input = 'Read this Report Card (' . (count($parts) + 1) . ' image/file(s)).';
    $hadSession = session_status() === PHP_SESSION_ACTIVE;
    if ($hadSession) session_write_close();
    $d = null;
    $lastError = 'The reader is unavailable.';
    foreach ([null, 'gemini-2.5-flash'] as $model) {
        $api = callGeminiAPI($system, $input, $format, 90, $first, $model);
        if (empty($api['success'])) { $lastError = (string) ($api['error'] ?? $lastError); continue; }
        $text = extractGeminiRawText($api['response'] ?? null);
        $text = trim(preg_replace('/\s*```$/', '', preg_replace('/^```(?:json)?\s*/i', '', trim((string) $text))));
        $try = json_decode($text, true);
        if (!is_array($try)) continue;
        $tryLrn = !empty($try['lrn_clearly_readable']) && oeNormalizeLrn($try['lrn'] ?? '') !== '';
        $tryGa = !empty($try['general_average_clearly_readable']) && is_numeric($try['general_average'] ?? null) && $try['general_average'] >= 60;
        if ($d === null) {
            $d = $try;
        } else {
            // Keep what each read found: the LRN from one, the grades from the other.
            if ($tryLrn) { $d['lrn'] = $try['lrn']; $d['lrn_clearly_readable'] = true; if (trim((string) ($try['student_name'] ?? '')) !== '') $d['student_name'] = $try['student_name']; }
            if ($tryGa) {
                foreach (['general_average', 'general_average_clearly_readable', 'subjects', 'remarks', 'grade_level'] as $k) { if (isset($try[$k])) $d[$k] = $try[$k]; }
            }
        }
        $gotLrn = !empty($d['lrn_clearly_readable']) && oeNormalizeLrn($d['lrn'] ?? '') !== '';
        $gotGa = !empty($d['general_average_clearly_readable']) && is_numeric($d['general_average'] ?? null) && $d['general_average'] >= 60;
        if ($gotLrn && $gotGa) break;
        // diagnostic only (no image, no name): what was missing on this read
        error_log('Report Card read (' . ($model ?: 'default model') . '): lrn ' . ($gotLrn ? 'ok' : 'NOT read (' . strlen(preg_replace('/\D/', '', (string) ($try['lrn'] ?? ''))) . ' digits)')
            . ', general average ' . ($gotGa ? 'ok' : 'NOT read') . ', pages ' . (count($parts) + 1));
    }
    if ($hadSession) @session_start();
    if (!is_array($d)) {
        return ['ok' => false, 'error' => $lastError];
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
 * A handwritten LRN on a tilted phone photo is often misread by one digit
 * (6 read as 4, 5 as 6...). When the first read does not match, the LRN alone
 * is read again on each page, turned upright in the other directions, until
 * one read EXACTLY matches the entered LRN. That is still safe: a misread can
 * practically never produce, digit for digit, the other 12-digit number the
 * applicant typed -- a wrong LRN stays "Wrong LRN number".
 * @return array{matched:bool, reads:string[]}
 */
function oeReportCardLrnRecheck(array $paths, $entered)
{
    $entered = oeNormalizeLrn($entered);
    if ($entered === '') return ['matched' => false, 'reads' => []];
    $key = 'lrnre:' . sha1(implode('|', array_map('sha1_file', array_filter($paths, 'is_file')))) . ':' . $entered;
    if (isset($_SESSION['oe_card_read'][$key])) return $_SESSION['oe_card_read'][$key];

    $schema = ['type' => 'object', 'properties' => ['lrn' => ['type' => 'string'], 'clearly_readable' => ['type' => 'boolean']], 'required' => ['lrn', 'clearly_readable']];
    $format = ['type' => 'text', 'mime_type' => 'application/json', 'schema' => $schema];
    $system = 'Find the LRN (Learner Reference Number: 12 digits, printed or HANDWRITTEN, usually after the label "LRN", often at the top right) '
        . 'on this Philippine report card image. Read every handwritten digit carefully (tell 6 from 4, 1 from 7, 5 from 6, 0 from 9). '
        . 'Reply with ONLY the JSON {"lrn": the digits you see, "clearly_readable": bool}.';

    // Candidate images: each page upright in 3 directions (images), or the PDF as it is.
    $tries = [];
    $seen = [];
    foreach ($paths as $p) {
        $mime = oeReportCardMime($p);
        if ($mime === null) continue;
        $h = sha1_file($p);
        if (isset($seen[$h])) continue;
        $seen[$h] = true;
        if ($mime === 'application/pdf' || !function_exists('imagecreatefromstring')) {
            $tries[] = ['type' => $mime === 'application/pdf' ? 'document' : 'image', 'data' => base64_encode(file_get_contents($p)), 'mime_type' => $mime];
            continue;
        }
        $img = @imagecreatefromstring(file_get_contents($p));
        if (!$img) continue;
        // keep the upload light: longest side at most 2000 px
        $w = imagesx($img); $hgt = imagesy($img); $scale = min(1, 2000 / max($w, $hgt));
        if ($scale < 1) { $img = imagescale($img, (int) round($w * $scale), (int) round($hgt * $scale)); }
        foreach ([90, 270, 0] as $deg) {
            $r = $deg ? imagerotate($img, $deg, 0) : $img;
            ob_start(); imagejpeg($r, null, 90); $bytes = ob_get_clean();
            $tries[] = ['type' => 'image', 'data' => base64_encode($bytes), 'mime_type' => 'image/jpeg'];
        }
    }
    $hadSession = session_status() === PHP_SESSION_ACTIVE;
    if ($hadSession) session_write_close();
    $reads = [];
    $matched = false;
    foreach (array_slice($tries, 0, 6) as $i => $part) {
        $api = callGeminiAPI($system, 'Read the LRN.', $format, 45, $part, $i >= 3 ? 'gemini-2.5-flash' : null);
        if (empty($api['success'])) continue;
        $d = json_decode(trim(preg_replace('/\s*```$/', '', preg_replace('/^```(?:json)?\s*/i', '', trim((string) extractGeminiRawText($api['response'] ?? null))))), true);
        $lrn = is_array($d) ? oeNormalizeLrn($d['lrn'] ?? '') : '';
        if ($lrn === '') continue;
        $reads[] = $lrn;
        if (hash_equals($lrn, $entered)) { $matched = true; break; }
    }
    if ($hadSession) @session_start();
    $res = ['matched' => $matched, 'reads' => $reads];
    $_SESSION['oe_card_read'][$key] = $res;
    return $res;
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
    // First read did not match (or found no LRN): read the LRN alone again, upright, before deciding.
    if ($entered !== '' && $d['lrn'] !== $entered) {
        $re = oeReportCardLrnRecheck($paths, $entered);
        if ($re['matched']) {
            $d['lrn'] = $entered;
        } elseif ($d['lrn'] === '' && !empty($re['reads'])) {
            $d['lrn'] = $re['reads'][0];
        }
    }
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

// ---------------------------------------------------------------------------
// Grade level check: the grade on the Form 138 vs the grade selected in Step 1.
// Normal progression is one grade up (Grade 6 -> 7, ... Grade 11 -> 12); a
// Report Card marked Retained / Not Promoted stays in the same grade.
// The selected grade is never changed for the applicant -- they correct it.
// ---------------------------------------------------------------------------

const OE_GC_VALIDATED = 'Validated';
const OE_GC_MISMATCH = 'Grade Level Mismatch';
const OE_GC_MANUAL = 'For Manual Verification';

/** 10 from "Grade 10", "G10", "Grade Ten", "Grade X", "Baitang 10"; null when not readable. */
function oeCardGradeNumber($text)
{
    $t = strtolower(trim((string) $text));
    if ($t === '') return null;
    if (preg_match('/(?<!\d)(\d{1,2})(?!\d)/', $t, $m)) {
        $n = (int) $m[1];
        return ($n >= 1 && $n <= 12) ? $n : null;
    }
    $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8,
              'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12];
    foreach ($words as $w => $n) {
        if (preg_match('/\b' . $w . '\b/', $t)) return $n;
    }
    $roman = ['xii' => 12, 'xi' => 11, 'x' => 10, 'ix' => 9, 'viii' => 8, 'vii' => 7, 'vi' => 6, 'v' => 5, 'iv' => 4, 'iii' => 3, 'ii' => 2, 'i' => 1];
    foreach ($roman as $r => $n) {
        if (preg_match('/\b(grade|baitang|g)\s*' . $r . '\b/', $t)) return $n;
    }
    return null;
}

/**
 * @param array  $card     what was read from the Report Card (grade_level, remarks)
 * @param string $selected "Grade 11" (Step 1)
 * @return array{status:string, card_grade:string, expected:string, retained:bool, message:string}
 *   status: Validated | Grade Level Mismatch | For Manual Verification
 */
function oeGradeLevelCheck(array $card, $selected)
{
    $n = oeCardGradeNumber($card['grade_level'] ?? '');
    if ($n === null) {
        return ['status' => OE_GC_MANUAL, 'card_grade' => '', 'expected' => '', 'retained' => false,
                'message' => 'The grade level on your Form 138 could not be read. The registrar will check it (For Manual Verification).'];
    }
    $cardLabel = 'Grade ' . $n;
    $retained = (bool) preg_match('/retain|not\s+promoted|repeat/i', (string) ($card['remarks'] ?? ''));
    $expectedN = $retained ? $n : $n + 1;
    $expected = ($expectedN >= 7 && $expectedN <= 12) ? 'Grade ' . $expectedN : '';
    if ($expected === '') {
        $msg = $n >= 12
            ? "Your uploaded Form 138 shows Grade 12. A Grade 12 completer cannot enroll in Grades 7 to 12. Please check your uploaded Form 138 or contact the registrar."
            : "Your uploaded Form 138 shows $cardLabel. Online Enrollment is for Grades 7 to 12 only. Please check your uploaded Form 138 or contact the registrar.";
        return ['status' => OE_GC_MISMATCH, 'card_grade' => $cardLabel, 'expected' => '', 'retained' => $retained, 'message' => $msg];
    }
    if ($selected === $expected) {
        return ['status' => OE_GC_VALIDATED, 'card_grade' => $cardLabel, 'expected' => $expected, 'retained' => $retained,
                'message' => "Form 138 shows $cardLabel" . ($retained ? ' (retained)' : '') . ". $expected is correct."];
    }
    return ['status' => OE_GC_MISMATCH, 'card_grade' => $cardLabel, 'expected' => $expected, 'retained' => $retained,
            'message' => "Your Form 138 shows $cardLabel" . ($retained ? ' (Retained)' : ' completed') . ", so the correct enrollment level is $expected."];
}

/** online_applications.grade_level_check (+ note) for the registrar. */
function oeEnsureGradeCheckSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('oe_grade_check_v1')) return;
    if (!getRecord("SHOW COLUMNS FROM online_applications LIKE 'grade_level_check'")) {
        executeQuery("ALTER TABLE online_applications ADD COLUMN grade_level_check VARCHAR(30) NULL COMMENT 'Validated / For Manual Verification (Form 138 grade vs selected grade)'");
    }
    if (!getRecord("SHOW COLUMNS FROM online_applications LIKE 'grade_level_check_note'")) {
        executeQuery("ALTER TABLE online_applications ADD COLUMN grade_level_check_note VARCHAR(255) NULL");
    }
    schemaMarkReady('oe_grade_check_v1');
}
