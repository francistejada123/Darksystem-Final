<?php
// modules/ai/chatbot.php
// AJAX endpoint for the "Ask AI" chat modal in modules/ai/index.php.
//
// UPGRADED: this used to be a plain free-text chat (getGeminiChatReply()).
// It now goes through getGeminiResourceReply() (includes/gemini_service.php)
// instead, which asks Gemini for a structured reply so this endpoint can
// tell "just answer a question" (Mode A) apart from "actually generate an
// academic resource" (Mode B) server-side, rather than guessing from
// keywords in JavaScript. When a resource is generated AND the teacher's
// message asked for a specific file format, this endpoint also calls
// includes/ai_document_generator.php to physically create that PDF/DOCX
// and save it as a Draft->Generated ai_generated_resources row, all in the
// same request — nothing is ever sent to a student from here; that still
// only ever happens from modules/ai/send_to_student.php, on the teacher's
// own explicit click.
//
// Every request is independently re-validated server-side inside
// getGeminiResourceReply() (teacher role, real currently-failing subject,
// teacher actually assigned to it) — the browser's student_id/subject_code
// are never trusted on their own, even though the same pair may have
// already been checked on an earlier turn of the same conversation.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/gemini_service.php';
require_once __DIR__ . '/../../includes/ai_document_generator.php';
require_once __DIR__ . '/../../includes/ai_chat_outputs.php'; // typed PDF / Word / Google Form commands
require_once __DIR__ . '/../../includes/ai_chat_attachment.php'; // optional "+" file (image / PDF / Word / text)

header('Content-Type: application/json');

// Teacher-only — this is a teacher-owned academic support tool, not a
// general Administrator utility (matches the rest of the Gemini feature).
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
$message = trim((string) ($_POST['message'] ?? ''));
// Language selector in the Ask AI chat: auto (from the subject) | english | filipino.
$language_pref = strtolower(trim((string) ($_POST['language'] ?? 'auto')));
if (!in_array($language_pref, ['auto', 'english', 'filipino'], true)) {
    $language_pref = 'auto';
}

if (!$teacher_id || !$student_id || $subject_code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing student or subject.']);
    exit;
}

// Optional file attached with the "+" button (multipart field "attachment").
// Validated by its own bytes and used for this one request only -- never stored.
$attachment_read = aiChatReadAttachment();
if (!$attachment_read['ok']) {
    echo json_encode(['success' => false, 'error' => $attachment_read['error']]);
    exit;
}
$attachment = $attachment_read['attachment'];
if ($attachment && $message === '') {
    $message = 'Please review the attached file.';
}

// The client sends back the short transcript it's already showing on
// screen so the AI can respond in context. Trusted only as prompt text —
// never as authorization (getGeminiResourceReply() re-derives that from
// the database on every call).
$history = [];
$history_raw = $_POST['history'] ?? '[]';
$decoded_history = json_decode((string) $history_raw, true);
if (is_array($decoded_history)) {
    foreach ($decoded_history as $turn) {
        if (!is_array($turn)) continue;
        $role = ($turn['role'] ?? '') === 'ai' ? 'ai' : 'teacher';
        $text = trim((string) ($turn['text'] ?? ''));
        if ($text === '') continue;
        $history[] = ['role' => $role, 'text' => $text];
    }
}

// The single most-recently generated resource in THIS conversation, if
// any — kept client-side (see modules/ai/index.php) and resent each turn
// so a follow-up like "make it easier" edits the real current content.
// Content only, never trusted for authorization or as a file path.
$current_draft = null;
$draft_raw = $_POST['current_draft'] ?? '';
if ($draft_raw !== '') {
    $decoded_draft = json_decode((string) $draft_raw, true);
    if (is_array($decoded_draft) && trim((string) ($decoded_draft['student_content'] ?? '')) !== '') {
        $current_draft = [
            'resource_type' => (string) ($decoded_draft['resource_type'] ?? ''),
            'title' => (string) ($decoded_draft['title'] ?? ''),
            'student_content' => (string) $decoded_draft['student_content'],
            'teacher_answer_key' => (string) ($decoded_draft['teacher_answer_key'] ?? ''),
            // Structured questions travel with the draft so "Create this as
            // Google Form" can build the Form without asking Gemini again.
            // Re-sanitized here -- never trusted just because they round-
            // tripped through the browser.
            'quiz_questions' => aiSanitizeQuizQuestions(is_array($decoded_draft['quiz_questions'] ?? null) ? $decoded_draft['quiz_questions'] : []),
        ];
    }
}

// ---- Which file/Form output does the teacher want? ----
// Either a Regenerate button on a PDF / Word / Google Form card (posts
// output_format + regenerate=1) or plain words in the message ("Generate
// this as PDF", "Make this a Word file", "Create this as Google Form").
// Decided here, server-side, from the words themselves -- not left to the
// model's guess -- so the requested output is actually produced.
$posted_format = (string) ($_POST['output_format'] ?? '');
$posted_format = in_array($posted_format, ['pdf', 'docx', 'gform'], true) ? $posted_format : null;
$regenerate = (string) ($_POST['regenerate'] ?? '') === '1';
$typed_format = aiDetectOutputCommand($message);
$target_format = $posted_format ?? $typed_format;
$is_conversion = !$regenerate && $current_draft !== null && $posted_format === null && $typed_format !== null && aiIsConversionCommand($message);

if ($is_conversion) {
    // "Generate this as PDF" and friends: convert the draft already on
    // screen (including any edits the teacher made to it) instead of asking
    // Gemini to write it all over again. With no draft on screen, the same
    // words fall through to Gemini below, which builds the file from the
    // conversation so far and the Student Context.
    $ctx = geminiResolveStudentContext($teacher_id, $student_id, $subject_code);
    if (!$ctx['success']) {
        echo json_encode(['success' => false, 'error' => $ctx['error']]);
        exit;
    }
    $is_filipino = geminiAskAiLanguage((string) ($ctx['context']['subject_name'] ?? ''), $language_pref, $message)['language'] === 'Filipino';
    $intro = $is_filipino
        ? ['pdf' => 'Handa na ang PDF.', 'docx' => 'Handa na ang Word file.', 'gform' => 'Handa na ang Google Form.'][$target_format]
        : ['pdf' => 'Your PDF is ready.', 'docx' => 'Your Word file is ready.', 'gform' => 'Your Google Form is ready.'][$target_format];

    $result = [
        'success' => true,
        'response_type' => 'resource',
        'reply_text' => $intro,
        'resource_type' => array_key_exists($current_draft['resource_type'], AI_RESOURCE_TYPES) ? $current_draft['resource_type'] : 'other',
        'title' => $current_draft['title'] !== '' ? $current_draft['title'] : 'Learning Resource',
        'student_content' => $current_draft['student_content'],
        'teacher_answer_key' => $current_draft['teacher_answer_key'] !== '' ? $current_draft['teacher_answer_key'] : null,
        'output_format' => $target_format,
        'context' => $ctx['context'],
    ];
    if (!empty($current_draft['quiz_questions'])) {
        $result['quiz_questions'] = $current_draft['quiz_questions'];
    }
} else {
    $result = getGeminiResourceReply($teacher_id, $student_id, $subject_code, $history, $message, $current_draft, $target_format, $attachment, $language_pref);

    if (!$result['success']) {
        echo json_encode(['success' => false, 'error' => $result['error']]);
        exit;
    }

    // The teacher's own words / the card button decide the file format --
    // never left to whatever the model guessed.
    if ($result['response_type'] === 'resource' && $target_format !== null) {
        $result['output_format'] = $target_format;
    }
}

// A Google Form needs structured, gradable questions. If the content has
// none (a worksheet, a reviewer, or a quiz the model returned as plain text
// only), have Gemini turn THAT content into multiple-choice items rather
// than telling the teacher it cannot be done.
if ($result['response_type'] === 'resource' && ($result['output_format'] ?? null) === 'gform' && empty($result['quiz_questions']) && googleFormsIsConfigured()) {
    $converted = geminiConvertDraftToQuizQuestions($result['context'], [
        'title' => $result['title'],
        'student_content' => $result['student_content'],
        'teacher_answer_key' => $result['teacher_answer_key'],
    ]);
    if (!empty($converted)) {
        $result['quiz_questions'] = $converted;
        if (!in_array($result['resource_type'], AI_GFORM_ELIGIBLE_TYPES, true)) {
            $result['resource_type'] = 'quiz';
        }
    }
}

// ---- Mode A: ordinary chat — unchanged shape from before the upgrade. ----
if ($result['response_type'] !== 'resource') {
    echo json_encode(['success' => true, 'response_type' => 'text', 'reply' => $result['reply_text']]);
    exit;
}

// ---- Mode B: a resource was generated. ----
$resource = [
    'resource_type' => $result['resource_type'],
    'title' => $result['title'],
    'student_content' => $result['student_content'],
    'teacher_answer_key' => $result['teacher_answer_key'],
];

$response = [
    'success' => true,
    'response_type' => 'resource',
    'reply' => $result['reply_text'],
    'resource_type' => $resource['resource_type'],
    'resource_type_label' => aiResourceTypeLabel($resource['resource_type']),
    'title' => $resource['title'],
    'student_content' => $resource['student_content'],
    'teacher_answer_key' => $resource['teacher_answer_key'],
    'output_format' => $result['output_format'], // 'pdf' | 'docx' | null — what the teacher asked for, decided by Gemini, never by the browser
];

// A structured, auto-gradable question set — only present for quiz-like
// resource_types (AI_GFORM_ELIGIBLE_TYPES) when Gemini actually returned
// one. Lets the chat UI offer "Create Google Form Quiz" in addition to
// the normal PDF/Word buttons, without changing student_content/
// teacher_answer_key or anything about the existing file-generation path.
if (!empty($result['quiz_questions'])) {
    $response['quiz_questions'] = $result['quiz_questions'];
}

// A file/Form was explicitly requested (typed command, card button, or the
// model recognised it) — generate it now, in the same round trip (one
// message -> straight to a file card, not a giant text bubble first).
if ($result['output_format'] !== null) {
    $teacher_row = getRecord("SELECT first_name, last_name FROM users WHERE user_id = ?", [$teacher_id]);
    $doc_context = [
        'student_name' => $result['context']['display_name'] ?? '',
        'grade_level' => $result['context']['grade_level'] ?? '',
        'subject_name' => $result['context']['subject_name'] ?? '',
        'teacher_name' => $teacher_row ? trim($teacher_row['first_name'] . ' ' . $teacher_row['last_name']) : '',
        'date' => date('F j, Y'),
    ];

    if ($result['output_format'] === 'gform') {
        $form_result = aiChatCreateGoogleForm(
            $teacher_id,
            $student_id,
            $subject_code,
            $resource,
            $result['quiz_questions'] ?? [],
            $message
        );

        if ($form_result['success']) {
            $response['output_format'] = 'gform';
            $response['resource_id'] = $form_result['resource_id'];
            $response['gform'] = $form_result;
        } else {
            // The content is still returned so nothing is lost — the UI
            // shows the normal resource card (with the Term / assessment
            // pickers when that is what was missing) plus this message.
            $response['output_format'] = null;
            $response['file_error'] = $form_result['error'];
            if (!empty($form_result['needs_link'])) {
                $response['needs_link'] = true;
            }
        }
    } else {
        $file_result = aiChatSaveFileResource($teacher_id, $student_id, $subject_code, $resource, $doc_context, $result['output_format'], $message);

        if ($file_result['success']) {
            $response['resource_id'] = $file_result['resource_id'];
            $response['original_filename'] = $file_result['original_filename'];
            $response['file_size'] = $file_result['file_size'];
        } else {
            // Content generation succeeded; only the physical file failed
            // (or could not be recorded). Never lose the generated content —
            // just report the file error alongside it so the UI can offer
            // Try Again / the other format.
            $response['output_format'] = null;
            $format_label = $result['output_format'] === 'docx' ? 'Word file' : 'PDF';
            $response['file_error'] = "The content was created, but the {$format_label} could not be generated. Please try again.";
        }
    }
}

// A conversion command answers "Your PDF is ready." up front; if the file /
// Form could not actually be built, drop that line so the card's own error
// note is the only thing the teacher reads.
if ($is_conversion && ($response['output_format'] ?? null) === null) {
    $response['reply'] = '';
}

echo json_encode($response);
