<?php
// includes/enrollment_lrn_card.php
// Online Enrollment: the LRN typed in Step 2 must match the LRN printed on the
// uploaded Report Card (Form 138 / SF9, doc key "form138").
//
// The card (PDF / JPG / PNG / WEBP) is read by the AI (Gemini, same service
// the rest of the system uses) which returns ONLY the LRN it can clearly see.
// The detected number is normalized (spaces / dashes removed) and must be
// exactly 12 digits. Nothing is accepted automatically:
//   verified   -> the numbers match
//   mismatch   -> "Wrong LRN number ..."
//   unreadable -> "LRN could not be verified. Please upload a clear and readable Report Card."
//   error      -> the reader is unavailable right now (try again) -- also not accepted
//
// The same file is checked twice (live on upload, then again when the form is
// submitted); the result of a successful read is kept in the session by the
// file's SHA-1, so the second check is instant and cannot be bypassed by
// posting a different file or a different LRN.

require_once __DIR__ . '/gemini_service.php'; // callGeminiAPI(), extractGeminiRawText()

const OE_LRN_CARD_DOC_KEY = 'form138';
const OE_LRN_MSG_MISMATCH = 'Wrong LRN number. The LRN entered does not match the LRN on the uploaded Report Card.';
const OE_LRN_MSG_UNREADABLE = 'LRN could not be verified. Please upload a clear and readable Report Card.';

/** Digits only; '' unless exactly 12 digits remain. */
function oeNormalizeLrn($value)
{
    $digits = preg_replace('/\D+/', '', (string) $value);
    return strlen($digits) === 12 ? $digits : '';
}

/** MIME type of an uploaded Report Card, or null when it is not an allowed kind. */
function oeReportCardMime($path)
{
    if (!is_file($path)) return null;
    $mime = function_exists('mime_content_type') ? (string) @mime_content_type($path) : '';
    $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    return in_array($mime, $allowed, true) ? $mime : null;
}

/**
 * Read the LRN printed on the card (cached per file in the session).
 * @return array{ok:bool, lrn:string, error?:string}  ok=false only when the reader could not run
 */
function oeReportCardDetectLrn($path)
{
    $mime = oeReportCardMime($path);
    if ($mime === null) {
        return ['ok' => true, 'lrn' => ''];           // not a readable image/PDF -> unreadable
    }
    $hash = sha1_file($path);
    if (isset($_SESSION['oe_card_lrn'][$hash])) {
        return ['ok' => true, 'lrn' => (string) $_SESSION['oe_card_lrn'][$hash]];
    }

    $schema = [
        'type' => 'object',
        'properties' => [
            'lrn' => ['type' => 'string'],
            'clearly_readable' => ['type' => 'boolean'],
        ],
        'required' => ['lrn', 'clearly_readable'],
    ];
    $system = 'You read an uploaded Philippine DepEd learner Report Card (Form 138 / SF9) or a similar school record. '
        . 'Find the learner\'s LRN (Learner Reference Number): a 12-digit number, usually labeled "LRN". '
        . 'Copy it EXACTLY as printed, digit by digit. Never guess, complete, correct or invent digits. '
        . 'If there is no LRN, or any digit is unclear, blurred, cut off or covered, return lrn as an empty string and clearly_readable false. '
        . 'Ignore every other number (school ID, dates, grades, phone numbers). Reply with ONLY the JSON object {"lrn": string, "clearly_readable": boolean}.';
    $part = ['type' => $mime === 'application/pdf' ? 'document' : 'image', 'data' => base64_encode(file_get_contents($path)), 'mime_type' => $mime];

    // The AI call can take a few seconds: do not keep the session locked meanwhile.
    $hadSession = session_status() === PHP_SESSION_ACTIVE;
    if ($hadSession) session_write_close();
    $api = callGeminiAPI($system, 'Read the LRN on this Report Card.', ['type' => 'text', 'mime_type' => 'application/json', 'schema' => $schema], 45, $part);
    if ($hadSession) @session_start();

    if (empty($api['success'])) {
        return ['ok' => false, 'lrn' => '', 'error' => (string) ($api['error'] ?? 'The reader is unavailable.')];
    }
    $text = extractGeminiRawText($api['response'] ?? null);
    $text = trim(preg_replace('/\s*```$/', '', preg_replace('/^```(?:json)?\s*/i', '', trim((string) $text))));
    $data = json_decode($text, true);
    $lrn = (is_array($data) && !empty($data['clearly_readable'])) ? oeNormalizeLrn($data['lrn'] ?? '') : '';

    $_SESSION['oe_card_lrn'][$hash] = $lrn;
    if (count($_SESSION['oe_card_lrn']) > 20) {
        $_SESSION['oe_card_lrn'] = array_slice($_SESSION['oe_card_lrn'], -20, null, true);
    }
    return ['ok' => true, 'lrn' => $lrn];
}

/**
 * Compare the entered LRN with the card.
 * @return array{status:string, message:string, detected:string}
 */
function oeReportCardCheck($path, $enteredLrn)
{
    $entered = oeNormalizeLrn($enteredLrn);
    $read = oeReportCardDetectLrn($path);
    if (!$read['ok']) {
        return ['status' => 'error', 'message' => 'The Report Card could not be checked right now. Please try again in a moment.', 'detected' => ''];
    }
    if ($read['lrn'] === '') {
        return ['status' => 'unreadable', 'message' => OE_LRN_MSG_UNREADABLE, 'detected' => ''];
    }
    if ($entered === '') {
        return ['status' => 'mismatch', 'message' => 'Enter your 12-digit LRN in Personal Information first.', 'detected' => $read['lrn']];
    }
    if (!hash_equals($read['lrn'], $entered)) {
        return ['status' => 'mismatch', 'message' => OE_LRN_MSG_MISMATCH, 'detected' => $read['lrn']];
    }
    return ['status' => 'verified', 'message' => 'LRN Verified', 'detected' => $read['lrn']];
}
