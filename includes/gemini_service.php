<?php
// includes/gemini_service.php
// Central, only place in the app that talks to the Google Gemini API.
// Nothing else should build a Gemini request or hold the API key — the
// conversational "Ask AI" chat (modules/ai/chatbot.php) goes through
// getGeminiChatReply() below, and modules/ai/send_to_student.php re-uses
// validateTeacherSubjectForStudent() before saving anything the AI helped
// draft as an actual message to a student.
//
// Flow enforced here, server-side, regardless of what the browser sends —
// re-checked on EVERY chat turn, never cached from an earlier turn in the
// same conversation:
//   1. The requesting teacher must actually be assigned (teacher_subjects)
//      to the subject/section the student is enrolled in.
//   2. The subject must be one of THIS student's actual currently-failing
//      subjects (getWeakSubjectsForStudent(), includes/tutorial_helpers.php)
//      — a teacher cannot chat about an "at risk" subject the student
//      isn't really struggling in.
//   3. Only the minimum data needed for the conversation is sent to
//      Gemini (first/last name, grade level, section, subject, grade,
//      risk level/reason) — never address, contact numbers, email,
//      guardian info, or any account/credential data.
//   4. The system instruction is built here, server-side, from fixed
//      text (buildSystemInstruction()) — the teacher's messages are only
//      ever sent as conversation turns, never as something that can
//      override or replace the system instruction itself.
//   5. Every Gemini reply is only ever a chat reply / draft. Nothing in
//      this file saves it anywhere or marks it "sent" —
//      modules/ai/send_to_student.php is the only place a message
//      actually becomes visible to a student, and that always requires
//      the teacher's own explicit action plus its own independent
//      re-validation of steps 1-2 above.
//   6. Multi-turn "memory" is intentionally kept client-side: the browser
//      keeps the growing chat transcript and resends recent turns as
//      plain-text context on every call (see getGeminiChatReply()) rather
//      than relying on any server-side conversation-linking on Google's
//      side — simpler and avoids depending on behavior we can't verify
//      from here.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/tutorial_helpers.php'; // getWeakSubjectsForStudent(), gh_validTermSql() via grading_helpers.php
require_once __DIR__ . '/risk_helpers.php';      // getGradingPassMark()
require_once __DIR__ . '/ai_document_generator.php'; // AI_RESOURCE_TYPES — the fixed vocabulary getGeminiResourceReply()'s structured output is constrained to

// ============================================================
// GOOGLE FORM QUIZ ELIGIBILITY
// ============================================================
// resource_type values for which getGeminiResourceReply() also asks Gemini
// for a STRUCTURED question set (quiz_questions — question/choices/
// correct_index/points), in addition to the normal free-text
// student_content/teacher_answer_key it already always returns. This is
// what makes it possible to build a real auto-graded Google Form from a
// chatbot-generated quiz — free text alone (the only thing this endpoint
// produced before) has no machine-readable answer key.
//
// Purely additive: student_content/teacher_answer_key keep being generated
// and used exactly as before (PDF/Word, display, editing) for every
// resource type, including these — quiz_questions is extra data alongside
// them, never a replacement.
define('AI_GFORM_ELIGIBLE_TYPES', ['quiz', 'practice_quiz', 'multiple_choice', 'true_false', 'short_assessment', 'question_bank']);

$geminiConfig = __DIR__ . '/../config/gemini_config.php';
if (file_exists($geminiConfig)) {
    require_once $geminiConfig;
}

// Informal conversation-starter labels shown as quick-reply chips in the
// Ask AI chat UI — NOT a strict validated enum anymore (the chat accepts
// free-form messages), just suggested phrasings to tap instead of typing.
define('GEMINI_SUPPORT_TYPES', [
    'Academic Support',
    'Study Plan',
    'Encouragement',
    'Progress Follow-Up',
    'Learning Strategy',
]);

if (!defined('GEMINI_MAX_INSTRUCTION_LENGTH')) {
    define('GEMINI_MAX_INSTRUCTION_LENGTH', 200);
}
if (!defined('GEMINI_TIMEOUT_SECONDS')) {
    define('GEMINI_TIMEOUT_SECONDS', 20);
}
// A generated resource (a full quiz + answer key, a reviewer, etc.) is
// legitimately much longer than a chat reply — separate, larger caps so
// resource generation isn't held to the plain-chat-reply limits below.
if (!defined('GEMINI_MAX_RESOURCE_LENGTH')) {
    define('GEMINI_MAX_RESOURCE_LENGTH', 24000);
}
if (!defined('GEMINI_RESOURCE_TIMEOUT_SECONDS')) {
    // A full quiz/resource generation call (schema-constrained JSON, often
    // 10+ questions worth of content) legitimately needs more headroom than
    // a plain chat reply. 45s was too tight — teachers were hitting a hard
    // cURL timeout on real quiz-generation requests; Apache's own Timeout
    // (httpd-default.conf, 300s) has plenty of room for this to grow.
    define('GEMINI_RESOURCE_TIMEOUT_SECONDS', 75);
}
// Grading a submitted photo/PDF (aiGradeResourceSubmission()) is Gemini
// vision work on top of an upload that's meaningfully bigger than a plain
// text prompt — both take noticeably longer than a normal resource-
// generation call, so this gets its own, longer ceiling rather than
// sharing GEMINI_RESOURCE_TIMEOUT_SECONDS and risking the exact
// "temporarily unavailable (connection issue)" (cURL timeout) error a
// teacher can hit checking a larger scanned/photographed answer.
if (!defined('GEMINI_GRADING_TIMEOUT_SECONDS')) {
    define('GEMINI_GRADING_TIMEOUT_SECONDS', 90);
}

// ============================================================
// PUBLIC ENTRY POINT
// ============================================================

/**
 * The fixed, server-side-only system instruction for the Ask AI chat.
 * Never built from or overridable by anything the browser sends — the
 * teacher's chat messages are always sent as separate conversation turns,
 * never merged into this text.
 */
function buildSystemInstruction()
{
    return
        "You are the E-GRADEPRO Academic Support AI Assistant, embedded in a teacher's \"Ask AI\" chat.\n" .
        "You are having a real back-and-forth conversation with the teacher about ONE specific student and ONE specific at-risk subject, given below in STUDENT ACADEMIC CONTEXT.\n" .
        "Read CONVERSATION SO FAR (if present) and respond as a natural continuation of it — you may ask the teacher a clarifying question if that helps, and you should remember what was already discussed earlier in this same conversation.\n" .
        "Stay strictly within academic support for this student and this subject: study recommendations, encouragement strategies, progress follow-up ideas, learning strategies, and helping the teacher draft a short supportive message to send to the student.\n" .
        "Do not behave as a general-purpose chatbot and do not answer questions unrelated to this student's academic support for this subject — politely decline and steer the conversation back.\n" .
        "Do not invent grades, attendance, behavior, missing activities, personal information, diagnoses, or family circumstances beyond what is given in the context.\n" .
        "If the teacher asks you to draft a message to send to the student, keep it short, respectful, and student-friendly — about 2 to 5 sentences — and make clear it is only a DRAFT: the teacher must review it and explicitly choose to send it before the student ever sees it.\n" .
        "You never make final decisions — you do not decide a student's grade, risk status, enrollment, or attendance. You only advise and suggest.\n" .
        "Keep replies concise and conversational rather than long essays, unless the teacher explicitly asks for more detail.\n" .
        "LANGUAGE RULE: always reply in the language that matches the medium of instruction of the subject named in STUDENT ACADEMIC CONTEXT. If a 'Preferred response language' line is given in the context, follow it exactly. Otherwise, infer it yourself from the subject: subjects taught in Filipino (e.g. Filipino, Araling Panlipunan, Edukasyon sa Pagpapakatao/Values Education, or a Filipino-titled subject such as \"Komunikasyon at Pananaliksik\" or \"Pananaliksik sa Filipino\") should be answered fully in Filipino/Tagalog; subjects taught in English (e.g. English, Mathematics, Science, MAPEH, TLE, Research, or an English-titled subject) should be answered fully in English. Keep the entire reply — including any drafted student message — consistently in that one language; do not mix languages mid-reply.";
}

/**
 * The fixed, server-side-only system instruction for the UPGRADED Ask AI
 * chat — used by getGeminiResourceReply() below, which is what
 * modules/ai/chatbot.php actually calls. This supersedes
 * buildSystemInstruction() for the live chat flow (broader role,
 * structured JSON contract) without touching buildSystemInstruction()
 * itself or getGeminiChatReply() — both are left exactly as they were so
 * the original plain-chat contract keeps working unchanged for anything
 * still relying on it.
 *
 * Same hard boundaries as before (one student, one subject, teacher-
 * authorized, never invents data, never decides grades/risk/enrollment,
 * same LANGUAGE RULE) — "expanded" only means the assistant may now also
 * produce actual academic content (quizzes, reviewers, worksheets,
 * performance tasks, study guides, etc.) instead of only study
 * suggestions and message drafts.
 */
function buildResourceSystemInstruction()
{
    $typeList = implode(', ', array_keys(AI_RESOURCE_TYPES));

    return
        "You are the E-GRADEPRO Academic Content & Intervention Assistant, embedded in a teacher's \"Ask AI\" chat.\n" .
        "You are having a real back-and-forth conversation with the teacher about ONE specific student and ONE specific at-risk subject, given below in STUDENT ACADEMIC CONTEXT. Read CONVERSATION SO FAR and CURRENT DRAFT (if present) and respond as a natural continuation.\n" .
        "You may: explain concepts, recommend interventions, suggest study strategies, and — this is new — actually GENERATE academic content and resources: mini lessons, quizzes, practice quizzes, reviewers, remedial activities, worksheets, practice exercises, performance tasks, written work, question banks, study guides, study plans, flashcards, recovery assessments, supportive student messages, short assessments, multiple choice/true-false/identification/essay questions, reflection or reading activities, intervention/learning activities, rubrics, assignments, and practice tests.\n" .
        "Stay strictly within academic support for this one student and this one subject. Do not behave as a general-purpose chatbot and do not answer questions unrelated to this student's academic support for this subject — politely decline (response_type \"text\") and steer back.\n" .
        "Do not invent grades, attendance, behavior, missing activities, personal information, diagnoses, or family circumstances beyond what is given in the context.\n" .
        "You never make final decisions — you do not decide a student's grade, risk status, enrollment, or attendance, and you never automatically send anything to the student. A teacher must always explicitly click Send to Student afterward.\n" .
        "LANGUAGE RULE: always reply (including reply_text, title, student_content, teacher_answer_key, quiz_questions, and any drafted message to the student) in the language given on the 'Preferred response language' line in the context if present, otherwise in the language matching the subject's medium of instruction: Filipino for subjects like Filipino/Araling Panlipunan/EsP, English for English/Math/Science/MAPEH/TLE/Research. Exception: if the teacher's NEW message explicitly asks for a specific language (e.g. \"Explain this in Filipino\", \"in English please\", \"isalin sa Filipino\"), use that language for that reply. Keep the whole reply in one language. Changing the language never changes the student, subject, grade level, section, or assessment context.\n" .
        "\n" .
        "RESPOND WITH ONLY A SINGLE JSON OBJECT (no markdown fences, no extra prose outside the JSON) with these fields:\n" .
        "  response_type: \"text\" or \"resource\".\n" .
        "    Use \"text\" for ordinary questions/advice/conversation (e.g. \"why might this student be struggling\", \"how should I follow up\") — no resource is being created.\n" .
        "    Use \"resource\" whenever the teacher asks you to create/generate/make/gumawa any quiz, activity, reviewer, worksheet, performance task, PeTa, rubric, study guide/plan, flashcards, assessment, or set of questions — including a short one like \"create a 10-item quiz\" or \"make a reviewer\".\n" .
        "    GENERATE FIRST. The teacher may type any instruction (a quiz, activity, remediation task, reviewer, worksheet, study plan, message, or any other learning material) and you must act on it immediately using STUDENT ACADEMIC CONTEXT: the student's name, grade level, subject, at-risk term, risk reason/details, and missing assessments. When a detail is not stated (topic, number of items, difficulty, format, time limit), do NOT stop to ask — choose sensible defaults from the Student Context: aim the content at the missing assessments and weak areas named there, match the student's grade level, and use a short mix of items (about 10 for a quiz) at an accessible difficulty. Mention the assumptions you made in one short clause of reply_text (e.g. \"I focused on the missing Quiz 3 topics, 10 items, easy to medium.\") so the teacher can ask for changes. Use response_type \"text\" with a single short clarifying question ONLY when the request is too vague to act on at all (for example just \"make something\") — never for a merely under-specified quiz or activity\n" .
        "  reply_text: ALWAYS present. A short (1-3 sentence) natural chat reply. For response_type \"text\" this is your full answer. For response_type \"resource\" this is a brief friendly intro/summary (e.g. \"Here's a 15-item quiz on the causes of World War II.\" or \"I've made question 5 easier.\") — never the resource content itself. For longer text answers, keep the flow easy to scan: use a short heading when useful, blank lines between ideas, and \"- \" or numbered lines for steps/list items instead of one dense paragraph. Never append generic boilerplate reminders such as \"review and approve before sending\" or \"grades are never changed automatically\" — the interface itself already makes that clear (nothing is ever sent or recorded without the teacher's explicit action), so repeating it in reply_text only adds noise. Only mention that kind of caveat if the teacher specifically asks about it.\n" .
        "  resource_type: required when response_type is \"resource\" — exactly one of: {$typeList}. Pick the closest match to what the teacher asked for, in any phrasing or language (e.g. \"PeTa\" -> performance_task, \"gumawa ng reviewer\" -> reviewer, \"20 multiple choice\" -> multiple_choice).\n" .
        "  output_format: required when response_type is \"resource\" — \"pdf\" if the teacher's message clearly asks for a PDF/downloadable file, \"docx\" if they ask for Word/DOCX/Microsoft Word, \"gform\" if they ask for a Google Form / Google Forms quiz (then resource_type MUST be one of the quiz-like types and quiz_questions MUST be filled in with single-best-answer multiple-choice or true/false items), otherwise \"none\" (they haven't asked for a file yet — the content will be shown in the chat for review first). Words like \"Generate this as PDF\", \"Make this a Word file\" or \"Create this as Google Form\" refer to the CURRENT DRAFT (or, when there is none, to your most recent substantive answer in CONVERSATION SO FAR, or — if the conversation has nothing yet — to a fresh resource built from the Student Context): return that same content, unchanged, with the requested output_format.\n" .
        "  title: required when response_type is \"resource\" — a short, specific title (e.g. \"AP8 Remedial Quiz: Causes of WWII\").\n" .
        "  student_content: required when response_type is \"resource\" — the actual student-facing material (instructions + questions/activity). Plain text only, using this simple convention: blank line = paragraph break; a line starting with \"- \" = bullet item; a line starting with a number and \". \" (e.g. \"1. \") = numbered item (used for quiz/question numbering — include the choices as their own numbered/lettered lines right under each question, e.g. \"A. ...\"). NEVER mention the student's grade, percentage, \"at risk\", \"failing\", \"struggling\", or anything that could embarrass the student — the material should read like ordinary classwork.\n" .
        "  teacher_answer_key: only when the resource type has objectively correct answers (quizzes, identification, true/false, etc.) — the correct answers (e.g. \"1. B\") and brief explanations if useful, same plain-text convention as student_content. Leave empty (\"\") for resource types without a fixed answer (reviewer, rubric, essay/reflection prompts, study guide, etc.). For an intervention package or a request to include a rubric, place the teacher-only rubric/answer guidance in this field and clearly label it separately from student_content.\n" .
        "  quiz_questions: ONLY when resource_type is one of: " . implode(', ', AI_GFORM_ELIGIBLE_TYPES) . " — in ADDITION to (never instead of) student_content/teacher_answer_key above, which must still be filled in normally. An array with one object per question, in the SAME order as student_content: {question: the question text alone (no leading number), choices: an array of 2-6 short answer-option strings in order (for true_false always exactly [\"True\",\"False\"] or the equivalent in the reply language), correct_index: the 0-based index into choices of the single correct option, points: an integer, normally 1}. Every question in this resource must be a single-best-answer, objectively-gradable multiple-choice/true-false item to appear here — if even one question in the set is open-ended (essay, short answer, identification with no fixed option list), omit quiz_questions entirely for this reply rather than guessing choices for it. ITEM COUNT: if the teacher's message states a number of items/questions (e.g. \"20-item quiz\", \"gumawa ng 10 tanong\", \"quiz out of 20\"), quiz_questions must contain EXACTLY that many questions, matching student_content's count one-for-one — never fewer and never more, even if it means covering more sub-topics from the lesson to reach that count. If no count was stated, use your own best judgment as usual.\n" .
        "If a CURRENT DRAFT is given below and the teacher's new message asks to change, add to, simplify, or extend it (e.g. \"make it easier\", \"add 5 more questions\", \"change to 15 items\"), respond with response_type \"resource\" and return the FULL updated student_content/teacher_answer_key (and quiz_questions, if applicable) reflecting that change — not a diff, not just the new part.";
}

/**
 * The JSON Schema getGeminiResourceReply() passes as response_format so
 * the Interactions API constrains its own output to this shape (never
 * relied on as the ONLY safeguard — validateGeminiResourceResponse()
 * below still defensively re-validates every field before anything is
 * trusted).
 *
 * SPEED: quiz_questions is listed as REQUIRED here (not just described in
 * prose in buildResourceSystemInstruction()) so Gemini's own schema-
 * constrained decoding fills it in THIS same call for a gform/quiz request,
 * the same technique geminiConvertDraftToQuizQuestions()'s schema already
 * uses successfully. Without this, the model often leaves quiz_questions
 * out even when asked in prose, so modules/ai/chatbot.php's
 * "$result['quiz_questions'] empty -> call geminiConvertDraftToQuizQuestions()
 * again" fallback used to fire on nearly every Google-Form-quiz request —
 * a second full Gemini round trip stacked behind the same "AI is typing"
 * indicator, roughly doubling the wait. Harmless for every other resource
 * type: validateGeminiResourceResponse() below still only keeps
 * quiz_questions when resource_type is gform-eligible AND the array is
 * non-empty, so a mini_lesson/rubric/etc. reply is completely unaffected by
 * Gemini now also emitting an unused, empty quiz_questions: [].
 */
function buildResourceResponseSchema()
{
    return [
        'type' => 'object',
        'properties' => [
            'response_type' => ['type' => 'string', 'enum' => ['text', 'resource']],
            'reply_text' => ['type' => 'string'],
            'resource_type' => ['type' => 'string', 'enum' => array_keys(AI_RESOURCE_TYPES)],
            'output_format' => ['type' => 'string', 'enum' => ['none', 'pdf', 'docx', 'gform']],
            'title' => ['type' => 'string'],
            'student_content' => ['type' => 'string'],
            'teacher_answer_key' => ['type' => 'string'],
            'lesson_topic' => ['type' => 'string'],
            'quiz_questions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string'],
                        'choices' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'correct_index' => ['type' => 'integer'],
                        'points' => ['type' => 'integer'],
                    ],
                    'required' => ['question', 'choices', 'correct_index'],
                ],
            ],
        ],
        // Requiring only quiz_questions (and not student_content) pushed every
        // reply toward a quiz and let the model drop the actual material. All
        // fields are required now: a non-quiz reply sends quiz_questions: [],
        // and a text reply sends "" for the content fields.
        'required' => ['response_type', 'reply_text', 'resource_type', 'title', 'student_content', 'quiz_questions'],
    ];
}

/**
 * Shared by getGeminiResourceReply() and getGeminiClassResourceReply(): one
 * schema-constrained call to Gemini for a resource/text reply, with ONE
 * silent retry if the call itself succeeded (HTTP 200, valid JSON) but the
 * model's own output didn't actually satisfy the schema — e.g. it declared
 * response_type "resource" and then stopped before writing student_content.
 * That's a different failure mode from the HTTP-level 500/503 retry already
 * inside callGeminiAPI(): the request succeeded, the model just didn't
 * finish, and a second attempt is usually enough (same as a teacher simply
 * resending the same message would get). Only retries on that specific
 * "well-formed HTTP response but unusable content" case — a real
 * network/auth/quota failure from callGeminiAPI() is returned immediately,
 * unchanged.
 *
 * $maxReplyLength: reply_text cap. 1000 (the default) is right for the short chat
 * intros every existing caller expects; the Teacher AI passes a larger cap because
 * its plain-text answers (explanations, strategies) are the whole reply.
 *
 * $filePart: optional attached image / PDF sent alongside the text (same shape
 * callGeminiAPI() takes). When present, both attempts go through callGeminiAPI()
 * so the file is never dropped on the retry.
 *
 * @return array{success:bool,parsed?:array,error?:string,error_code?:string}
 */
function geminiGenerateResource($systemInstruction, $userInput, $timeoutSeconds, $maxReplyLength = 1000, $filePart = null)
{
    $responseFormat = [
        'type' => 'text',
        'mime_type' => 'application/json',
        'schema' => buildResourceResponseSchema(),
    ];

    $maxAttempts = 2;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        // First attempt: the normal path (Interactions API, with the
        // generateContent fallback inside callGeminiAPI()). If that came
        // back HTTP-200 but with output this parser could not use, the
        // retry goes straight to generateContent -- repeating the exact same
        // request would just reproduce the same unusable shape.
        $api_result = ($attempt === 1 || $filePart !== null)
            ? callGeminiAPI($systemInstruction, $userInput, $responseFormat, $timeoutSeconds, $filePart)
            : geminiCallGenerateContentOnly($systemInstruction, $userInput, $responseFormat, $timeoutSeconds);
        if (!$api_result['success']) {
            return ['success' => false, 'error' => $api_result['error'], 'error_code' => $api_result['error_code']];
        }

        $parsed = validateGeminiResourceResponse($api_result['response'], $maxReplyLength);
        if ($parsed !== null) {
            return ['success' => true, 'parsed' => $parsed];
        }

        error_log('Gemini: resource response received but no usable structured output found (attempt ' . $attempt . '/' . $maxAttempts . '). Raw: ' . substr(json_encode($api_result['response']), 0, 2000));
    }

    return ['success' => false, 'error' => handleGeminiError(0, null, null, 'empty_or_unusable_response'), 'error_code' => 'empty_response'];
}

/**
 * Best-effort, deterministic language hint from a subject's NAME (not just
 * its code — senior-high strand subjects like "Komunikasyon at Pananaliksik"
 * don't share a common code prefix with FIL7-FIL10, so code-prefix matching
 * alone would miss them). Only returns a hint for the two unambiguous cases
 * the subject name itself names outright ("Filipino" / "English"); every
 * other subject (Araling Panlipunan, Math, Science, MAPEH, TLE, and the
 * many differently-titled senior-high subjects) is left to the model's own
 * curriculum knowledge via the LANGUAGE RULE in buildSystemInstruction() —
 * far more reliable here than trying to hand-maintain an exhaustive table.
 *
 * @return string|null 'Filipino', 'English', or null (let the model infer)
 */
function geminiSuggestedLanguage($subjectName)
{
    $name = mb_strtolower((string) $subjectName);
    if (strpos($name, 'filipino') !== false) return 'Filipino';
    if (strpos($name, 'english') !== false) return 'English';
    return null;
}

/**
 * Ask AI (per-student, At-Risk Students) only: which language to answer in.
 * Order: (1) the teacher's NEW message explicitly asks for a language
 * ("Explain this in Filipino"), (2) the Language selector (English /
 * Filipino), (3) Auto -- from the subject. Other features keep using
 * geminiSuggestedLanguage() unchanged.
 *
 * @return array{language:?string, source:string} language 'English' |
 *   'Filipino' | null (Auto on a subject not in the table: let the model
 *   use the subject's medium of instruction); source 'message' |
 *   'selector' | 'auto'
 */
function geminiAskAiLanguage($subjectName, $preference = 'auto', $message = '')
{
    $msg = mb_strtolower((string) $message);
    $wantsFil = (bool) preg_match('/\b(?:in|into|to|using|use)\s+(?:filipino|tagalog)\b|\bsa\s+(?:filipino|tagalog|wikang\s+filipino)\b|\b(?:isalin|i-?translate|translate)\b.*\b(?:filipino|tagalog)\b|\bnaka-?(?:filipino|tagalog)\b/u', $msg);
    $wantsEng = (bool) preg_match('/\b(?:in|into|to|using|use)\s+english\b|\bsa\s+(?:english|ingles)\b|\b(?:isalin|i-?translate|translate)\b.*\b(?:english|ingles)\b|\bnaka-?(?:english|ingles)\b/u', $msg);
    if ($wantsFil !== $wantsEng) {
        return ['language' => $wantsFil ? 'Filipino' : 'English', 'source' => 'message'];
    }

    $preference = strtolower(trim((string) $preference));
    if ($preference === 'english' || $preference === 'filipino') {
        return ['language' => ucfirst($preference), 'source' => 'selector'];
    }
    return ['language' => geminiAskAiAutoLanguage($subjectName), 'source' => 'auto'];
}

/**
 * Auto language for an Ask AI subject. Filipino / Araling Panlipunan (and
 * Filipino-medium subjects such as EsP/GMRC/Values Education, Komunikasyon,
 * Pananaliksik) -> Filipino; English, Mathematics, Science, MAPEH, TLE/EPP
 * (and their components) -> English; anything else -> null (the model uses
 * the subject's medium of instruction). Mirrored in modules/ai/index.php
 * (askAiAutoLanguage()) for the greeting.
 */
function geminiAskAiAutoLanguage($subjectName)
{
    $name = mb_strtolower((string) $subjectName);
    if (preg_match('/filipino|araling\s+panlipunan|\bap\s*\d|edukasyon\s+sa\s+pagpapakatao|\besp\b|\bgmrc\b|values\s+education|komunikasyon|pananaliksik|panitikan|pilipino|\bwika\b/u', $name)) {
        return 'Filipino';
    }
    if (preg_match('/english|math|algebra|geometry|statistic|calculus|science|biology|chemistry|physics|mapeh|music|arts?\b|physical\s+education|\bp\.?e\.?\b|health|\btle\b|\bepp\b|technology\s+and\s+livelihood|livelihood|home\s+economics|\bict\b|agri|industrial\s+arts|entrepreneur|pantahanan|pangkabuhayan/u', $name)) {
        return 'English';
    }
    return null;
}

/** The context line getGeminiResourceReply() sends for geminiAskAiLanguage()'s result. */
function geminiAskAiLanguagePromptLine(array $lang, $subjectName)
{
    $language = $lang['language'] ?? null;
    $switch = " If the teacher's NEW message explicitly asks for a different language, use the language they ask for instead.";
    if ($language === null) {
        return "Preferred response language: Auto -- use the medium of instruction of " . $subjectName . " and keep the whole reply in that one language." . $switch;
    }
    $everything = " Write the entire reply in " . $language . ": reply_text, title, student_content, teacher_answer_key, quiz_questions (questions and choices), explanations, recommendations, and any message meant for the student.";
    if (($lang['source'] ?? '') === 'message') {
        return "Preferred response language: " . $language . " (the teacher's new message explicitly asks for " . $language . "; follow it for this reply even if the subject is usually taught in another language)." . $everything;
    }
    if (($lang['source'] ?? '') === 'selector') {
        return "Preferred response language: " . $language . " (chosen by the teacher with the Language selector; this overrides the subject's usual language)." . $everything . $switch;
    }
    return "Preferred response language: " . $language . " (Auto: " . $subjectName . " is taught in " . $language . ")." . $everything . $switch;
}

/**
 * Multi-turn conversational entry point used by modules/ai/chatbot.php.
 * Every call is independently re-validated against the real database — a
 * conversation "remembering" earlier turns is purely a prompt-context
 * trick (see file header note #6), never a reason to skip steps 1-3.
 *
 * @param array  $history     Prior turns of THIS conversation, oldest
 *   first, each like ['role' => 'teacher'|'ai', 'text' => '...']. Used
 *   only as prompt context — never trusted for authorization.
 * @param string $userMessage The teacher's new message. Empty string means
 *   "the chat was just opened" (used for the initial greeting).
 *
 * @return array{
 *   success:bool,
 *   reply?:string,
 *   context?:array,           // risk snapshot, in case the caller wants it
 *   error?:string,            // safe to show to the teacher
 *   error_code?:string        // for the caller/logs, not for display
 * }
 */
function getGeminiChatReply($teacherId, $studentId, $subjectCode, array $history, $userMessage)
{
    $teacherId = (int) $teacherId;
    $studentId = (int) $studentId;
    $subjectCode = trim((string) $subjectCode);
    $userMessage = trim((string) $userMessage);

    if (mb_strlen($userMessage) > 1000) {
        return ['success' => false, 'error' => 'Your message is too long — please shorten it.', 'error_code' => 'message_too_long'];
    }

    // ---- 1. Teacher must be a real, active Teacher (Administrator does
    // not get to chat here — this feature is teacher-owned per spec) ----
    $teacher = getRecord("SELECT u.user_id, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ? AND u.is_active = 1", [$teacherId]);
    if (!$teacher || $teacher['role_name'] !== 'Teacher') {
        return ['success' => false, 'error' => 'Only the assigned teacher can chat with the AI about this student.', 'error_code' => 'not_a_teacher'];
    }

    // ---- 2. Student must exist and be enrolled this school year ----
    $active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
    if (!$active_sy) {
        return ['success' => false, 'error' => 'No active school year is set. Please set one in Settings first.', 'error_code' => 'no_active_sy'];
    }
    $student = getRecord(
        "SELECT s.*, sec.section_name
         FROM students s
         LEFT JOIN sections sec ON s.section_id = sec.section_id
         WHERE s.student_id = ? AND s.is_active = 1",
        [$studentId]
    );
    if (!$student) {
        return ['success' => false, 'error' => 'Student not found.', 'error_code' => 'no_student'];
    }

    // ---- 3. Subject must be one of THIS student's real currently-failing
    // subjects, and the requesting teacher must be the one assigned to it.
    // Re-checked on every single turn — never cached from an earlier turn
    // in the same conversation. Shared with modules/ai/send_to_student.php
    // so both endpoints enforce the exact same rule instead of two
    // hand-written copies that could drift apart. ----
    $check = validateTeacherSubjectForStudent($teacherId, $studentId, $subjectCode, $active_sy['sy_id']);
    if (!$check['success']) {
        return $check;
    }
    $matched = $check['subject'];

    $subject_name = $matched['subject_name'];
    $current_grade = (float) $matched['final_grade'];
    $pass_mark = getGradingPassMark();

    // ---- 4. Build the minimum context Gemini actually needs ----
    $context = buildStudentRiskContext($student, $subject_name, $subjectCode, $current_grade, $pass_mark, $studentId, $active_sy['sy_id']);

    $system_instruction = buildSystemInstruction();

    $user_input = "STUDENT ACADEMIC CONTEXT\n" . formatRiskContextForPrompt($context);

    // Deterministic language hint for the two unambiguous cases — see
    // geminiSuggestedLanguage(). Every other subject (Araling Panlipunan,
    // Math, Science, MAPEH, TLE, senior-high strand subjects, etc.) is left
    // to the LANGUAGE RULE in buildSystemInstruction(), which the model
    // applies from the subject name/curriculum knowledge on its own.
    $suggested_language = geminiSuggestedLanguage($subject_name);
    if ($suggested_language !== null) {
        $user_input .= "\nPreferred response language: " . $suggested_language .
            " (this subject, " . $subject_name . ", is taught in " . $suggested_language . " — reply entirely in " . $suggested_language . ").";
    }

    $user_input .= "\n\n";

    // Fold recent conversation history into the prompt as plain-text turns
    // — kept client-side and resent each call (file header note #6), and
    // bounded here so the prompt can't grow unbounded in a long chat.
    $recent_history = array_slice($history, -12);
    $has_history = false;
    if (!empty($recent_history)) {
        $history_lines = [];
        foreach ($recent_history as $turn) {
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text === '') continue;
            $role = ($turn['role'] ?? '') === 'ai' ? 'AI' : 'Teacher';
            $history_lines[] = $role . ': ' . $text;
        }
        if (!empty($history_lines)) {
            $has_history = true;
            $user_input .= "CONVERSATION SO FAR\n" . implode("\n", $history_lines) . "\n\n";
        }
    }

    if ($userMessage === '') {
        $user_input .= $has_history
            ? "The teacher hasn't sent a new message yet. Briefly continue being helpful about this student's academic support for this subject."
            : "The teacher just opened this conversation about the student above. Greet them briefly by name for the student, and offer one concrete, specific piece of academic support guidance for this subject to start the conversation.";
    } else {
        $user_input .= "Teacher: " . $userMessage;
    }

    $api_result = callGeminiAPI($system_instruction, $user_input);
    if (!$api_result['success']) {
        return ['success' => false, 'error' => $api_result['error'], 'error_code' => $api_result['error_code']];
    }

    $reply = validateGeminiResponse($api_result['response']);
    if ($reply === null) {
        error_log('Gemini: chat response received but no usable text found. Raw: ' . substr(json_encode($api_result['response']), 0, 2000));
        return ['success' => false, 'error' => handleGeminiError(0, null, null, 'empty_or_unusable_response'), 'error_code' => 'empty_response'];
    }

    return ['success' => true, 'reply' => $reply, 'context' => $context];
}

/**
 * Upgraded multi-turn entry point used by modules/ai/chatbot.php — the
 * "ChatGPT-style academic content generator" behavior. Re-validates
 * everything getGeminiChatReply() does (same steps 1-3 above, same
 * validateTeacherSubjectForStudent() gate), then asks Gemini for a
 * structured JSON reply instead of free text, so the caller can tell
 * "just chat" apart from "actually generate a resource" without any
 * keyword-matching of its own.
 *
 * @param array      $history     Same shape as getGeminiChatReply() — kept
 *   short (role/text pairs), NOT the full text of any generated resource.
 * @param string     $userMessage The teacher's new message.
 * @param array|null $currentDraft The most recently generated resource IN
 *   THIS CONVERSATION, if any — ['resource_type','title','student_content',
 *   'teacher_answer_key'] — so a follow-up like "make it easier" edits the
 *   real current content instead of the model guessing. Sent as a single
 *   "CURRENT DRAFT" block, not accumulated into history, so a document
 *   isn't repeatedly re-sent turn after turn once superseded.
 *
 * @return array{
 *   success:bool,
 *   response_type?:'text'|'resource',
 *   reply_text?:string,
 *   resource_type?:string,
 *   output_format?:string|null,
 *   title?:string,
 *   student_content?:string,
 *   teacher_answer_key?:string|null,
 *   context?:array,
 *   error?:string,
 *   error_code?:string
 * }
 */
function getGeminiResourceReply($teacherId, $studentId, $subjectCode, array $history, $userMessage, $currentDraft = null, $outputHint = null, $attachment = null, $languagePref = 'auto')
{
    $teacherId = (int) $teacherId;
    $studentId = (int) $studentId;
    $subjectCode = trim((string) $subjectCode);
    $userMessage = trim((string) $userMessage);

    if (mb_strlen($userMessage) > 1000) {
        return ['success' => false, 'error' => 'Your message is too long — please shorten it.', 'error_code' => 'message_too_long'];
    }

    // ---- Same authorization chain as getGeminiChatReply() — re-checked
    // on every single turn, never cached from an earlier turn or from the
    // fact that a draft already exists client-side. ----
    $teacher = getRecord("SELECT u.user_id, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ? AND u.is_active = 1", [$teacherId]);
    if (!$teacher || $teacher['role_name'] !== 'Teacher') {
        return ['success' => false, 'error' => 'Only the assigned teacher can chat with the AI about this student.', 'error_code' => 'not_a_teacher'];
    }

    $active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
    if (!$active_sy) {
        return ['success' => false, 'error' => 'No active school year is set. Please set one in Settings first.', 'error_code' => 'no_active_sy'];
    }
    $student = getRecord(
        "SELECT s.*, sec.section_name
         FROM students s
         LEFT JOIN sections sec ON s.section_id = sec.section_id
         WHERE s.student_id = ? AND s.is_active = 1",
        [$studentId]
    );
    if (!$student) {
        return ['success' => false, 'error' => 'Student not found.', 'error_code' => 'no_student'];
    }

    $check = validateTeacherSubjectForStudent($teacherId, $studentId, $subjectCode, $active_sy['sy_id']);
    if (!$check['success']) {
        return $check;
    }
    $matched = $check['subject'];
    $subject_name = $matched['subject_name'];
    $current_grade = (float) $matched['final_grade'];
    $pass_mark = getGradingPassMark();

    $context = buildStudentRiskContext($student, $subject_name, $subjectCode, $current_grade, $pass_mark, $studentId, $active_sy['sy_id']);

    $system_instruction = buildResourceSystemInstruction();

    $user_input = "STUDENT ACADEMIC CONTEXT\n" . formatRiskContextForPrompt($context);

    // Ask AI Language selector (Auto / English / Filipino) + a plain-words
    // request in the teacher's new message -- see geminiAskAiLanguage().
    $user_input .= "\n" . geminiAskAiLanguagePromptLine(geminiAskAiLanguage($subject_name, $languagePref, $userMessage), $subject_name);
    $user_input .= "\n\n";

    // ---- Current draft (single latest version only — see docblock) ----
    if (is_array($currentDraft) && trim((string) ($currentDraft['student_content'] ?? '')) !== '') {
        $user_input .= "CURRENT DRAFT (the latest generated resource in this conversation — if the teacher's message asks to change, add to, or simplify it, edit THIS content and return the full updated version)\n";
        $user_input .= "Resource Type: " . (string) ($currentDraft['resource_type'] ?? '') . "\n";
        $user_input .= "Title: " . (string) ($currentDraft['title'] ?? '') . "\n";
        $user_input .= "Student Content:\n" . mb_substr((string) $currentDraft['student_content'], 0, 6000) . "\n";
        if (!empty($currentDraft['teacher_answer_key'])) {
            $user_input .= "Teacher Answer Key:\n" . mb_substr((string) $currentDraft['teacher_answer_key'], 0, 3000) . "\n";
        }
        $user_input .= "\n";
    }

    // Recent conversation as short text turns (never the full resource
    // content — that's CURRENT DRAFT above, sent once, not accumulated).
    $recent_history = array_slice($history, -10);
    $has_history = false;
    if (!empty($recent_history)) {
        $history_lines = [];
        foreach ($recent_history as $turn) {
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text === '') continue;
            $role = ($turn['role'] ?? '') === 'ai' ? 'AI' : 'Teacher';
            $history_lines[] = $role . ': ' . $text;
        }
        if (!empty($history_lines)) {
            $has_history = true;
            $user_input .= "CONVERSATION SO FAR\n" . implode("\n", $history_lines) . "\n\n";
        }
    }

    if ($userMessage === '') {
        $user_input .= $has_history
            ? "The teacher hasn't sent a new message yet. Briefly continue being helpful about this student's academic support for this subject."
            : "The teacher just opened this conversation about the student above. Greet them briefly by name for the student, and offer one concrete, specific piece of academic support guidance for this subject to start the conversation. response_type should be \"text\".";
    } else {
        $user_input .= "Teacher: " . $userMessage;
        // Output the teacher asked for through the chat UI (a Regenerate
        // button on a PDF / Word / Google Form card, or a typed command).
        // The server applies the requested file format itself afterwards;
        // this only makes sure the content is SHAPED for it.
        if ($outputHint === 'gform') {
            $user_input .= "\n\n(Delivery: this will be built as an auto-graded Google Form. Use response_type \"resource\", output_format \"gform\", a quiz-like resource_type, and fill quiz_questions with single-best-answer multiple-choice or true/false items only.)";
        } elseif ($outputHint === 'pdf' || $outputHint === 'docx') {
            $user_input .= "\n\n(Delivery: this will be delivered as a " . ($outputHint === 'pdf' ? 'PDF' : 'Word') . " file. Use response_type \"resource\" and output_format \"" . $outputHint . "\".)";
        }
    }

    // Optional file the teacher attached with the "+" button (see
    // includes/ai_chat_attachment.php). Reference material only.
    $filePart = null;
    if (is_array($attachment) && function_exists('aiChatAttachmentPrompt')) {
        $user_input .= aiChatAttachmentPrompt($attachment);
        $filePart = aiChatAttachmentFilePart($attachment);
    }

    $result = geminiGenerateResource($system_instruction, $user_input, GEMINI_RESOURCE_TIMEOUT_SECONDS, 1000, $filePart);
    if (!$result['success']) {
        return ['success' => false, 'error' => $result['error'], 'error_code' => $result['error_code']];
    }

    return array_merge(['success' => true], $result['parsed'], ['context' => $context]);
}

/**
 * Ask AI: the same authorization chain + Student Context that
 * getGeminiResourceReply() builds, WITHOUT calling Gemini. Used by
 * modules/ai/chatbot.php when a typed command ("Generate this as PDF",
 * "Create this as Google Form") only needs to convert the draft already on
 * screen into a file -- the teacher/student/subject are still re-validated
 * from the database on every call, exactly as everywhere else.
 *
 * @return array{success:bool,context?:array,student?:array,active_sy?:array,subject?:array,error?:string,error_code?:string}
 */
function geminiResolveStudentContext($teacherId, $studentId, $subjectCode)
{
    $teacherId = (int) $teacherId;
    $studentId = (int) $studentId;
    $subjectCode = trim((string) $subjectCode);

    $teacher = getRecord("SELECT u.user_id, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ? AND u.is_active = 1", [$teacherId]);
    if (!$teacher || $teacher['role_name'] !== 'Teacher') {
        return ['success' => false, 'error' => 'Only the assigned teacher can chat with the AI about this student.', 'error_code' => 'not_a_teacher'];
    }
    $active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
    if (!$active_sy) {
        return ['success' => false, 'error' => 'No active school year is set. Please set one in Settings first.', 'error_code' => 'no_active_sy'];
    }
    $student = getRecord(
        "SELECT s.*, sec.section_name
         FROM students s
         LEFT JOIN sections sec ON s.section_id = sec.section_id
         WHERE s.student_id = ? AND s.is_active = 1",
        [$studentId]
    );
    if (!$student) {
        return ['success' => false, 'error' => 'Student not found.', 'error_code' => 'no_student'];
    }
    $check = validateTeacherSubjectForStudent($teacherId, $studentId, $subjectCode, $active_sy['sy_id']);
    if (!$check['success']) {
        return $check;
    }
    $matched = $check['subject'];
    $context = buildStudentRiskContext($student, $matched['subject_name'], $subjectCode, (float) $matched['final_grade'], getGradingPassMark(), $studentId, $active_sy['sy_id']);

    return ['success' => true, 'context' => $context, 'student' => $student, 'active_sy' => $active_sy, 'subject' => $matched];
}

/**
 * Turn an already-generated draft (free text) into the structured,
 * auto-gradable question set a Google Form needs -- for the case where the
 * teacher says "Create this as Google Form" about a draft that was not made
 * with quiz_questions (e.g. a worksheet). Only single-best-answer
 * multiple-choice / true-false items are produced; open-ended items are
 * rewritten as multiple choice. Returns [] on any failure so the caller can
 * fall back to the normal resource card.
 *
 * @return array clean quiz_questions (see aiSanitizeQuizQuestions()) or []
 */
function geminiConvertDraftToQuizQuestions(array $context, array $draft, $expectedCount = null)
{
    $schema = [
        'type' => 'object',
        'properties' => [
            'quiz_questions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string'],
                        'choices' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'correct_index' => ['type' => 'integer'],
                        'points' => ['type' => 'integer'],
                    ],
                    'required' => ['question', 'choices', 'correct_index'],
                ],
            ],
        ],
        'required' => ['quiz_questions'],
    ];
    $responseFormat = ['type' => 'text', 'mime_type' => 'application/json', 'schema' => $schema];

    $system = "You convert classroom material into an auto-graded multiple-choice quiz for a Google Form.\n"
        . "RESPOND WITH ONLY A SINGLE JSON OBJECT: {\"quiz_questions\": [{\"question\": string (no leading number), \"choices\": [2-6 short answer strings], \"correct_index\": 0-based index of the single correct choice, \"points\": integer, normally 1}]}.\n"
        . "Keep the SAME topic, language and reading level as the material. Keep every existing multiple-choice / true-false question as it is; rewrite any open-ended item as a single-best-answer multiple-choice item. For true/false use exactly two choices. "
        . "Never mention the student's grade, risk, or that they are struggling.";

    $input = (isset($context['class_prompt']) ? "CLASS CONTEXT\n" . $context['class_prompt'] : "STUDENT ACADEMIC CONTEXT\n" . formatRiskContextForPrompt($context)) . "\n\n"
        . "MATERIAL TO CONVERT\nTitle: " . (string) ($draft['title'] ?? '') . "\n"
        . "Content:\n" . mb_substr((string) ($draft['student_content'] ?? ''), 0, 6000) . "\n";
    if (!empty($draft['teacher_answer_key'])) {
        $input .= "Answer key:\n" . mb_substr((string) $draft['teacher_answer_key'], 0, 3000) . "\n";
    }
    if ($expectedCount) {
        $input .= "\nProduce exactly " . (int) $expectedCount . " questions.";
    }

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $api = $attempt === 1
            ? callGeminiAPI($system, $input, $responseFormat, GEMINI_RESOURCE_TIMEOUT_SECONDS)
            : geminiCallGenerateContentOnly($system, $input, $responseFormat, GEMINI_RESOURCE_TIMEOUT_SECONDS);
        if (empty($api['success'])) {
            return [];
        }
        $text = extractGeminiRawText($api['response'] ?? null);
        if ($text === null) continue;
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = trim(preg_replace('/\s*```$/', '', $text));
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) continue;
        $questions = aiSanitizeQuizQuestions($decoded['quiz_questions'] ?? ($decoded[0] ?? []));
        if (!empty($questions)) {
            return $questions;
        }
    }
    return [];
}

/**
 * The one place that decides "is this teacher allowed to act on this
 * subject for this student" — used both before calling Gemini and again
 * before actually saving/sending a message, so a forged/stale form POST
 * can't skip the check just because a draft was already generated once.
 *
 * @return array{success:bool,subject?:array,error?:string,error_code?:string}
 */
function validateTeacherSubjectForStudent($teacherId, $studentId, $subjectCode, $syId)
{
    $weak_subjects = getWeakSubjectsForStudent($studentId, $syId);
    $matched = null;
    foreach ($weak_subjects as $ws) {
        if ($ws['subject_code'] === $subjectCode) { $matched = $ws; break; }
    }
    if (!$matched) {
        // Also at risk under the AI Analytics rules (2+ missing assessments or
        // repeatedly low scores) even with a passing, complete Term Grade.
        require_once __DIR__ . '/risk_helpers.php';
        foreach (getAtRiskSubjectRecords($studentId, $syId) as $ar) {
            if ($ar['subject_code'] === $subjectCode) {
                $matched = [
                    'subject_code' => $ar['subject_code'], 'subject_name' => $ar['subject_name'],
                    'final_grade' => $ar['final_grade'], 'remarks' => $ar['remarks'],
                    'term' => $ar['term'], 'teacher_id' => $ar['teacher_id'],
                ];
                break;
            }
        }
    }
    if (!$matched) {
        return ['success' => false, 'error' => 'This subject is not currently identified as an at-risk subject for this student.', 'error_code' => 'not_at_risk_subject'];
    }
    if ((int) ($matched['teacher_id'] ?? 0) !== (int) $teacherId) {
        return ['success' => false, 'error' => 'You are not the assigned teacher for this subject.', 'error_code' => 'not_assigned'];
    }
    return ['success' => true, 'subject' => $matched];
}

// ============================================================
// CONTEXT BUILDING
// ============================================================

/** Only the fields Gemini actually needs — see file header note #3. */
function buildStudentRiskContext($student, $subject_name, $subject_code, $current_grade, $pass_mark, $studentId, $syId)
{
    $risk_level = $current_grade < ($pass_mark - 5) ? 'High Risk' : 'Medium Risk';
    $risk_reason = 'Current ' . $subject_name . ' grade (' . number_format($current_grade, 2) . ') is below the school\'s passing mark of ' . number_format($pass_mark, 2) . '.';

    $context = [
        'display_name' => trim($student['first_name'] . ' ' . $student['last_name']),
        'grade_level'  => $student['grade_level'],
        'section'      => $student['section_name'] ?: 'Not yet assigned',
        'subject_name' => $subject_name,
        'subject_code' => $subject_code,
        'current_grade' => $current_grade,
        'risk_level'   => $risk_level,
        'risk_reason'  => $risk_reason,
    ];

    // Ask AI: richer, read-only Student Context so the AI knows WHICH term is
    // at risk and WHY -- the same per-subject-term records the At-Risk
    // Students table on this page already shows (getAtRiskSubjectRecords()).
    // Purely additive keys; nothing here is written anywhere.
    $context['terms'] = [];
    $context['risk_details'] = [];
    $context['missing_assessments'] = [];
    try {
        foreach (getAtRiskSubjectRecords($studentId, $syId) as $record) {
            if (($record['subject_code'] ?? '') !== $subject_code) continue;
            $termName = trim((string) ($record['term'] ?? ''));
            if ($termName !== '' && !in_array($termName, $context['terms'], true)) {
                $context['terms'][] = $termName;
            }
            $detail = trim((string) ($record['reason'] ?? ''));
            if ($detail !== '') {
                $context['risk_details'][] = ($termName !== '' ? $termName . ': ' : '') . $detail;
            }
            foreach (($record['missing_assessments'] ?? []) as $missingName) {
                $missingName = trim((string) $missingName);
                if ($missingName !== '' && !in_array($missingName, $context['missing_assessments'], true)) {
                    $context['missing_assessments'][] = $missingName;
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('Gemini: could not load at-risk term details: ' . $e->getMessage());
    }

    // Optional continuity context: the most recent PREVIOUS message already
    // sent for this exact student+subject, if any — lets the AI (and the
    // teacher reading the draft) see what was already tried.
    $prev = getRecord(
        "SELECT support_type, current_grade, sent_at FROM student_support_messages
         WHERE student_id = ? AND subject_code = ? AND status = 'Sent'
         ORDER BY sent_at DESC LIMIT 1",
        [$studentId, $subject_code]
    );
    if ($prev) {
        $context['previous_intervention'] = $prev['support_type'];
        $context['previous_grade'] = $prev['current_grade'] !== null ? (float) $prev['current_grade'] : null;
    }

    return $context;
}

function formatRiskContextForPrompt($context)
{
    $lines = [
        'Student: ' . $context['display_name'],
        'Grade Level: ' . $context['grade_level'],
        'Section: ' . $context['section'],
        'Subject: ' . $context['subject_name'],
        'Current Grade: ' . number_format($context['current_grade'], 2),
        'Risk Level: ' . $context['risk_level'],
        'Risk Reason: ' . $context['risk_reason'],
    ];
    if (!empty($context['terms'])) {
        $lines[] = 'At-Risk Term: ' . implode(', ', $context['terms']);
    }
    if (!empty($context['risk_details'])) {
        $lines[] = 'At-Risk Details: ' . implode(' | ', $context['risk_details']);
    }
    if (!empty($context['missing_assessments'])) {
        $lines[] = 'Missing Assessments: ' . implode(', ', $context['missing_assessments']);
    }
    if (!empty($context['previous_intervention'])) {
        $lines[] = 'Previous Intervention: ' . $context['previous_intervention'];
        if ($context['previous_grade'] !== null) {
            $lines[] = 'Previous Grade: ' . number_format($context['previous_grade'], 2);
            $lines[] = 'Current Grade (this draft): ' . number_format($context['current_grade'], 2);
        }
    }
    return implode("\n", $lines);
}

// ============================================================
// GEMINI HTTP CALL
// ============================================================

/**
 * @param array|null $responseFormat Optional Interactions API response_format
 *   block (see buildResourceResponseSchema()) to request schema-constrained
 *   JSON output instead of free text. Omitted entirely from the request
 *   body when null, so this is fully backward compatible with the plain
 *   chat call in getGeminiChatReply().
 * @param int|null $timeoutOverride Optional cURL timeout in seconds, for
 *   calls (resource generation) that legitimately need longer than the
 *   default GEMINI_TIMEOUT_SECONDS used for a plain chat reply.
 * @param array|null $filePart Optional extra input item sent ALONGSIDE the
 *   text prompt — ['type' => 'image'|'document', 'data' => <base64 string>,
 *   'mime_type' => '...'] — per the Interactions API's image/file
 *   understanding docs (image/jpeg, image/png, image/webp, or
 *   application/pdf as a 'document'). Omitted (null) by every existing
 *   caller, so this is fully backward compatible; only
 *   aiGradeResourceSubmission() (grading a student's attached photo/PDF
 *   answer) ever passes one.
 * @return array{success:bool,response?:array,error?:string,error_code?:string}
 */
function callGeminiAPI($systemInstruction, $inputText, $responseFormat = null, $timeoutOverride = null, $filePart = null, $modelOverride = null)
{
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '' || GEMINI_API_KEY === 'YOUR_KEY_HERE') {
        error_log('Gemini: GEMINI_API_KEY is not configured (config/gemini_config.php).');
        return ['success' => false, 'error' => handleGeminiError(0, null, null, 'missing_api_key'), 'error_code' => 'missing_api_key'];
    }
    if (!function_exists('curl_init')) {
        error_log('Gemini: PHP cURL extension is not available.');
        return ['success' => false, 'error' => handleGeminiError(0, null, null, 'no_curl'), 'error_code' => 'no_curl'];
    }

    // Google's own models occasionally return a 500/503 "currently
    // experiencing high demand ... try again later" response — a genuine,
    // temporary capacity issue on their side, not a bug here (confirmed
    // live: connection + SSL + API key all fine, Google itself returns
    // this). One short, silent retry smooths over that blip instead of
    // making the teacher notice and manually resend — never retried for
    // an actual connection failure, a bad key, or a bad request, where
    // trying again would just waste time for the same result.
    $maxAttempts = 2;
    $result = null;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $result = geminiHttpAttempt($systemInstruction, $inputText, $responseFormat, $timeoutOverride, $filePart, $modelOverride);
        if ($result['success'] || $attempt >= $maxAttempts || !geminiIsRetriableServerError($result)) {
            break;
        }
        $delay = min((int) ($result['retry_after'] ?? 2), 5);
        if ($delay > 0) {
            sleep($delay);
        }
    }

    // Pro models may be unavailable to free-tier keys or have no quota.
    // Keep Pro as the preferred model, but let the chatbot remain usable
    // with Flash when Google explicitly rejects the Pro request.
    $requestedModel = $modelOverride ?: GEMINI_MODEL;
    if (!$result['success']
        && in_array((int)($result['http_code'] ?? 0), [404, 429], true)
        && in_array($requestedModel, ['gemini-2.5-pro', 'gemini-3.1-pro-preview'], true)
    ) {
        error_log('Gemini: falling back from ' . $requestedModel . ' to gemini-2.5-flash (' . ($result['error_code'] ?? 'request_failed') . ').');
        $fallback = geminiHttpAttempt($systemInstruction, $inputText, $responseFormat, $timeoutOverride, $filePart, 'gemini-2.5-flash');
        if ($fallback['success']) {
            return $fallback;
        }
    }

    // Ask AI reactivation: the Interactions API above is still a beta
    // endpoint, and a request it rejects (400/404/405, an unparseable body,
    // a key it does not accept, a schema/model combination it does not
    // support) does NOT mean the same key + model would fail on Google's
    // long-stable generateContent endpoint. Try that endpoint once, with the
    // SAME key and SAME model already configured in config/gemini_config.php
    // -- nothing about the key/model is changed or guessed at. Quota (429)
    // and plain connection failures are not retried elsewhere, since another
    // endpoint would not change them.
    if (!$result['success'] && geminiShouldTryAlternateEndpoint($result)) {
        $timeout = $timeoutOverride !== null ? (int) $timeoutOverride : GEMINI_TIMEOUT_SECONDS;
        foreach (geminiAlternateEndpoints($filePart !== null) as $endpoint) {
            error_log('Gemini: primary request failed (' . ($result['error_code'] ?? 'request_failed') . ' HTTP ' . (int) ($result['http_code'] ?? 0) . '); retrying via ' . $endpoint . ' generateContent.');
            $alt = geminiHttpAttemptGenerateContent($systemInstruction, $inputText, $responseFormat, $timeout, $filePart, $modelOverride, $endpoint);
            if ($alt['success']) {
                return $alt;
            }
            // Keep the more specific configuration error (bad key/model) for
            // the message the teacher sees, if the alternate one has it.
            // The Vertex express attempt is only a guess for "AQ." keys, so its
            // error (usually "Vertex AI API not enabled") must never hide the
            // real reason the Gemini Developer API rejected the request.
            if ($endpoint !== 'vertex'
                && in_array((int) ($alt['http_code'] ?? 0), [400, 401, 403, 404], true)
                && !in_array((int) ($result['http_code'] ?? 0), [401, 403], true)) {
                $result = $alt;
            }
        }
    }
    return $result;
}

/**
 * Same key and model as callGeminiAPI(), but sent ONLY to generateContent
 * (AI Studio endpoint first, then Vertex express for an "AQ." key). Used when
 * the Interactions API answered but its output could not be used.
 *
 * @return array{success:bool,response?:array,error?:string,error_code?:string,http_code?:int}
 */
function geminiCallGenerateContentOnly($systemInstruction, $inputText, $responseFormat = null, $timeoutOverride = null, $modelOverride = null)
{
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '' || GEMINI_API_KEY === 'YOUR_KEY_HERE') {
        return ['success' => false, 'error' => handleGeminiError(0, null, null, 'missing_api_key'), 'error_code' => 'missing_api_key'];
    }
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => handleGeminiError(0, null, null, 'no_curl'), 'error_code' => 'no_curl'];
    }
    $timeout = $timeoutOverride !== null ? (int) $timeoutOverride : GEMINI_TIMEOUT_SECONDS;
    if (function_exists('set_time_limit')) {
        @set_time_limit($timeout + 15);
    }
    $endpoints = array_merge(['aistudio'], geminiAlternateEndpoints(true));
    $last = ['success' => false, 'error' => handleGeminiError(0, null, null, 'empty_or_unusable_response'), 'error_code' => 'empty_response'];
    foreach ($endpoints as $endpoint) {
        $attempt = geminiHttpAttemptGenerateContent($systemInstruction, $inputText, $responseFormat, $timeout, null, $modelOverride, $endpoint);
        if ($attempt['success']) {
            return $attempt;
        }
        // Keep the Gemini Developer API's error: the Vertex attempt is only a
        // guess for "AQ." keys and its error would hide the real reason.
        if ($endpoint !== 'vertex' || ($last['error_code'] ?? '') === 'empty_response') {
            $last = $attempt;
        }
    }
    return $last;
}

/**
 * True when a failed primary (Interactions API) call is worth one attempt on
 * generateContent: anything except quota exhaustion and a transport-level
 * connection failure.
 */
function geminiShouldTryAlternateEndpoint(array $result)
{
    if (($result['error_code'] ?? '') === 'missing_api_key' || ($result['error_code'] ?? '') === 'no_curl') {
        return false;
    }
    if (($result['error_code'] ?? '') === 'curl_error') {
        return false;
    }
    return (int) ($result['http_code'] ?? 0) !== 429;
}

/**
 * Which generateContent endpoints to try after the primary call failed.
 * 'aistudio' is the standard Gemini Developer API endpoint. 'vertex' is
 * Google's Vertex AI express-mode endpoint: keys issued there start with
 * "AQ." and are not accepted by the Developer API host, so it is only ever
 * tried for a key of that shape.
 *
 * @param bool $primaryWasGenerateContent True when the primary attempt already
 *   used the AI Studio generateContent endpoint (an attached-file grading
 *   call), so repeating it would be pointless.
 * @return string[]
 */
function geminiAlternateEndpoints($primaryWasGenerateContent = false)
{
    $endpoints = [];
    if (!$primaryWasGenerateContent) {
        $endpoints[] = 'aistudio';
    }
    if (defined('GEMINI_API_KEY') && strpos((string) GEMINI_API_KEY, 'AQ.') === 0) {
        $endpoints[] = 'vertex';
    }
    return $endpoints;
}

/**
 * The Gemini REST "responseSchema" field wants upper-case type names
 * ("OBJECT", "STRING", ...). The rest of this app writes lower-case JSON
 * Schema (which the Interactions API accepts), so convert on the way out.
 */
function geminiSchemaForGenerateContent($schema)
{
    if (!is_array($schema)) {
        return $schema;
    }
    $out = [];
    foreach ($schema as $key => $value) {
        if ($key === 'type' && is_string($value)) {
            $out[$key] = strtoupper($value);
        } elseif ($key === 'enum' || $key === 'required') {
            $out[$key] = $value; // enum VALUES / property NAMES are data, never upper-cased
        } elseif ($key === 'properties' && is_array($value)) {
            // Keys here are property NAMES (which could even be called
            // "type"); only each property's own schema is converted.
            $props = [];
            foreach ($value as $propName => $propSchema) {
                $props[$propName] = geminiSchemaForGenerateContent($propSchema);
            }
            $out[$key] = $props;
        } elseif (is_array($value)) {
            $out[$key] = geminiSchemaForGenerateContent($value);
        } else {
            $out[$key] = $value;
        }
    }
    return $out;
}

/**
 * True for a transient, safe-to-retry failure: Google's own 500/503
 * capacity errors, OR a cURL operation timeout (errno 28). That timeout
 * case was added after confirming live, from this exact dev environment,
 * that identical requests to this endpoint intermittently hang with zero
 * bytes received and then succeed in a few seconds on a fresh attempt —
 * a flaky connection, not a request Google is rejecting. Every OTHER
 * cURL error (DNS failure, connection refused, etc.) is left non-retried,
 * since those aren't things a quick retry from here fixes.
 */
function geminiIsRetriableServerError(array $result)
{
    if (($result['error_code'] ?? '') === 'curl_error') {
        return (int) ($result['curl_errno'] ?? 0) === 28; // CURLE_OPERATION_TIMEDOUT
    }
    $http = (int) ($result['http_code'] ?? 0);
    return $http === 500 || $http === 503;
}

/**
 * One raw HTTP attempt against the Interactions API. Never called directly
 * outside callGeminiAPI().
 *
 * $filePart, when given (an attached photo/PDF for AI grading), is now
 * handled by a SEPARATE function, geminiHttpAttemptGenerateContent() below,
 * against Google's older, long-stable, extremely well-documented
 * generateContent endpoint instead of this one. Reasoning: every plain-text
 * call in this app (the overwhelming majority) goes through the Interactions
 * API below and is proven working; but its documented multimodal `input`
 * array shape is still beta, and a teacher hit "AI Draft Assistant is
 * temporarily unavailable" checking a real submission the first time this
 * shape was used, with no way to verify the exact wire format against the
 * live API from this project's dev environment. generateContent's
 * contents[].parts[] + inline_data shape is Google's oldest and most
 * thoroughly documented multimodal request format, so grading calls (the
 * only calls that ever attach a file) use it specifically, while every
 * other call keeps using the Interactions API exactly as before — untouched
 * and unaffected by this.
 */
function geminiHttpAttempt($systemInstruction, $inputText, $responseFormat, $timeoutOverride, $filePart = null, $modelOverride = null)
{
    $timeout = $timeoutOverride !== null ? (int) $timeoutOverride : GEMINI_TIMEOUT_SECONDS;

    // Guard against PHP's OWN max_execution_time killing the request before
    // cURL's timeout above ever gets a chance to — e.g. a grading call
    // legitimately allowed 90 seconds (GEMINI_GRADING_TIMEOUT_SECONDS) is
    // pointless if Apache/PHP cuts the script off at its usual 30-second
    // default first. Harmless for a request that finishes well under this.
    if (function_exists('set_time_limit')) {
        @set_time_limit($timeout + 15);
    }

    if ($filePart !== null) {
        return geminiHttpAttemptGenerateContent($systemInstruction, $inputText, $responseFormat, $timeout, $filePart, $modelOverride);
    }

    $payload = [
        'model' => ($modelOverride ?: GEMINI_MODEL),
        'system_instruction' => $systemInstruction,
        'input' => ['type' => 'text', 'text' => $inputText],
    ];
    if ($responseFormat !== null) {
        $payload['response_format'] = $responseFormat;
    }
    $body = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE); // a stray non-UTF-8 byte (e.g. pasted from Word) must not empty the whole request

    $ch = curl_init(GEMINI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'x-goog-api-key: ' . GEMINI_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADER => true, // need the response headers too, to read Retry-After
    ]);

    $raw = curl_exec($ch);
    $curl_errno = curl_errno($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($curl_errno !== 0) {
        error_log("Gemini: cURL error ($curl_errno): $curl_error");
        return ['success' => false, 'error' => handleGeminiError(0, $raw, $curl_errno, $curl_error), 'error_code' => 'curl_error', 'curl_errno' => $curl_errno, 'http_code' => 0];
    }

    $raw_headers = $raw !== false ? substr($raw, 0, $header_size) : '';
    $raw_body = $raw !== false ? substr($raw, $header_size) : $raw;

    $retry_after = null;
    if ($raw_headers !== '' && preg_match('/^Retry-After:\s*(\d+)/mi', $raw_headers, $m)) {
        $retry_after = (int) $m[1];
    }

    $decoded = json_decode($raw_body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        error_log('Gemini: invalid JSON response. HTTP ' . $http_code . '. Raw: ' . substr((string) $raw_body, 0, 2000));
        return ['success' => false, 'error' => handleGeminiError($http_code, $raw_body, null, 'invalid_json'), 'error_code' => 'invalid_json', 'http_code' => $http_code];
    }

    if ($http_code < 200 || $http_code >= 300) {
        $msg = $decoded['error']['message'] ?? 'unknown error';
        error_log("Gemini: HTTP $http_code — $msg");
        return [
            'success' => false,
            'error' => handleGeminiError($http_code, $raw_body, null, $msg),
            'error_code' => 'http_' . $http_code,
            'http_code' => $http_code,
            'retry_after' => $retry_after,
        ];
    }

    return ['success' => true, 'response' => $decoded];
}

/**
 * One raw HTTP attempt against Google's generateContent endpoint — used
 * ONLY when a $filePart (an attached photo/PDF for AI grading) is present.
 * See the docblock on geminiHttpAttempt() above for why this call goes to a
 * different endpoint than every other call in this app.
 *
 * Request shape (Google's oldest, most thoroughly documented multimodal
 * format): system_instruction.parts[].text, contents[].parts[] mixing
 * {text} and {inline_data:{mime_type,data}} items, and generationConfig for
 * JSON-schema-constrained output — see
 * https://ai.google.dev/gemini-api/docs/image-understanding and
 * https://ai.google.dev/gemini-api/docs/structured-output.
 *
 * Response shape is candidates[0].content.parts[0].text, which
 * extractGeminiRawText() already knows how to read (it was originally added
 * there as a defensive fallback shape) — no changes needed on the parsing
 * side for this to work.
 *
 * @param string $systemInstruction
 * @param string $inputText
 * @param array|null $responseFormat Same shape as callGeminiAPI()'s param — only ['schema' => ...] is used here (mime_type is always forced to JSON when a schema is given, since that's the only reason this app ever passes one).
 * @param int $timeout Already resolved by the caller (geminiHttpAttempt()).
 * @param array|null $filePart ['type' => 'image'|'document', 'data' => <base64>, 'mime_type' => '...'], or null for a text-only call.
 * @param string $endpoint 'aistudio' (default, Gemini Developer API) or 'vertex' (Vertex AI express mode, keys starting with "AQ.") -- used by callGeminiAPI()'s fallback.
 * @return array{success:bool,response?:array,error?:string,error_code?:string,http_code?:int,retry_after?:int|null}
 */
function geminiHttpAttemptGenerateContent($systemInstruction, $inputText, $responseFormat, $timeout, $filePart, $modelOverride = null, $endpoint = 'aistudio')
{
    $parts = [['text' => (string) $inputText]];
    $mime = is_array($filePart) ? (string) ($filePart['mime_type'] ?? '') : '';
    $data = is_array($filePart) ? (string) ($filePart['data'] ?? '') : '';
    if ($mime !== '' && $data !== '') {
        $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => $data]];
    }
    // More pages of the same document (e.g. a 2-3 page Report Card), read together.
    foreach ((is_array($filePart) ? ($filePart['extra'] ?? []) : []) as $extra) {
        if (!empty($extra['mime_type']) && !empty($extra['data'])) {
            $parts[] = ['inline_data' => ['mime_type' => (string) $extra['mime_type'], 'data' => (string) $extra['data']]];
        }
    }

    $payload = [
        'system_instruction' => ['parts' => [['text' => (string) $systemInstruction]]],
        'contents' => [['role' => 'user', 'parts' => $parts]],
    ];

    if ($responseFormat !== null) {
        $generation_config = ['responseMimeType' => 'application/json'];
        if (!empty($responseFormat['schema'])) {
            $generation_config['responseSchema'] = geminiSchemaForGenerateContent($responseFormat['schema']);
        }
        $payload['generationConfig'] = $generation_config;
    }

    $body = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE); // a stray non-UTF-8 byte (e.g. pasted from Word) must not empty the whole request
    $model = $modelOverride ?: ((defined('GEMINI_MODEL') && GEMINI_MODEL !== '') ? GEMINI_MODEL : 'gemini-2.5-flash-lite');
    if ($endpoint === 'vertex') {
        // Vertex AI express mode (keys starting with "AQ.").
        $url = 'https://aiplatform.googleapis.com/v1/publishers/google/models/' . rawurlencode($model) . ':generateContent';
    } else {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'x-goog-api-key: ' . GEMINI_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADER => true, // need the response headers too, to read Retry-After
    ]);

    $raw = curl_exec($ch);
    $curl_errno = curl_errno($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($curl_errno !== 0) {
        error_log("Gemini(generateContent): cURL error ($curl_errno): $curl_error");
        geminiDebugLog('generatecontent_curl_error', ['errno' => $curl_errno, 'error' => $curl_error]);
        return ['success' => false, 'error' => handleGeminiError(0, $raw, $curl_errno, $curl_error), 'error_code' => 'curl_error', 'curl_errno' => $curl_errno, 'http_code' => 0];
    }

    $raw_headers = $raw !== false ? substr($raw, 0, $header_size) : '';
    $raw_body = $raw !== false ? substr($raw, $header_size) : $raw;

    $retry_after = null;
    if ($raw_headers !== '' && preg_match('/^Retry-After:\s*(\d+)/mi', $raw_headers, $m)) {
        $retry_after = (int) $m[1];
    }

    $decoded = json_decode($raw_body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        error_log('Gemini(generateContent): invalid JSON response. HTTP ' . $http_code . '. Raw: ' . substr((string) $raw_body, 0, 2000));
        geminiDebugLog('generatecontent_invalid_json', ['http_code' => $http_code, 'raw' => substr((string) $raw_body, 0, 2000)]);
        return ['success' => false, 'error' => handleGeminiError($http_code, $raw_body, null, 'invalid_json'), 'error_code' => 'invalid_json', 'http_code' => $http_code];
    }

    if ($http_code < 200 || $http_code >= 300) {
        $msg = $decoded['error']['message'] ?? 'unknown error';
        error_log("Gemini(generateContent): HTTP $http_code — $msg");
        geminiDebugLog('generatecontent_http_error', ['http_code' => $http_code, 'message' => $msg]);
        return [
            'success' => false,
            'error' => handleGeminiError($http_code, $raw_body, null, $msg),
            'error_code' => 'http_' . $http_code,
            'http_code' => $http_code,
            'retry_after' => $retry_after,
        ];
    }

    return ['success' => true, 'response' => $decoded];
}

// ============================================================
// RESPONSE VALIDATION
// ============================================================

/**
 * Defensively pull the generated text out of an Interactions API response,
 * regardless of exact response shape. Returns null (never throws) if
 * nothing usable is found, so a format change on Google's side degrades
 * to "temporarily unavailable" instead of a fatal error. Shared by both
 * validateGeminiResponse() (plain chat) and validateGeminiResourceResponse()
 * (structured JSON) below — one place that knows how to read a response,
 * two places that decide what to do with the text.
 */
function extractGeminiRawText($response)
{
    if (!is_array($response)) return null;

    $text = null;

    if (!empty($response['steps']) && is_array($response['steps'])) {
        foreach ($response['steps'] as $step) {
            if (($step['type'] ?? '') !== 'model_output') continue;
            if (empty($step['content']) || !is_array($step['content'])) continue;
            foreach ($step['content'] as $piece) {
                if (($piece['type'] ?? '') === 'text' && !empty($piece['text'])) {
                    $text = $piece['text'];
                    break 2;
                }
            }
        }
    }

    // Top-level convenience field some Interactions API responses carry
    // (notably for schema-constrained JSON output).
    if ($text === null && !empty($response['output_text'])) {
        $text = $response['output_text'];
    }

    // Interactions API responses may expose model text under `outputs`
    // instead of `steps`/`output_text`. Keep this parser tolerant so a
    // provider response-shape change does not turn a valid answer into null.
    foreach (['outputs', 'output'] as $outputKey) {
        if ($text !== null || empty($response[$outputKey]) || !is_array($response[$outputKey])) {
            continue;
        }
        foreach ($response[$outputKey] as $piece) {
            if (!is_array($piece)) continue;
            if (($piece['type'] ?? '') === 'text' && !empty($piece['text'])) {
                $text = $piece['text'];
                break;
            }
            if (isset($piece['content']) && is_string($piece['content']) && trim($piece['content']) !== '') {
                $text = $piece['content'];
                break;
            }
            if (isset($piece['content']) && is_array($piece['content'])) {
                foreach ($piece['content'] as $contentPiece) {
                    if (is_array($contentPiece) && !empty($contentPiece['text'])) {
                        $text = $contentPiece['text'];
                        break 2;
                    }
                }
            }
        }
    }

    // Fallback shape, in case a future/alternate response uses the older
    // candidates[].content.parts[].text shape instead.
    if ($text === null && !empty($response['candidates'][0]['content']['parts']) && is_array($response['candidates'][0]['content']['parts'])) {
        // generateContent: join the visible text parts (skip any "thought"
        // parts a thinking-capable model may include before its answer).
        $joined = '';
        foreach ($response['candidates'][0]['content']['parts'] as $part) {
            if (is_array($part) && !empty($part['text']) && empty($part['thought'])) {
                $joined .= $part['text'];
            }
        }
        if (trim($joined) !== '') {
            $text = $joined;
        }
    }

    if ($text === null) return null;
    $text = trim($text);
    return $text === '' ? null : $text;
}

/**
 * Defensively pull the generated text out of an Interactions API response.
 * Returns null (never throws) if the shape isn't what was expected, so a
 * format change on Google's side degrades to "temporarily unavailable"
 * instead of a fatal error.
 */
function validateGeminiResponse($response, $maxLength = 4000)
{
    $text = extractGeminiRawText($response);
    if ($text === null || mb_strlen($text) > $maxLength) return null;
    return $text;
}

/**
 * Same idea as validateGeminiResponse() but for getGeminiResourceReply():
 * pulls the raw text out, strips any accidental markdown code fences (a
 * schema-constrained response_format should prevent these, but this is
 * defense-in-depth, not the only safeguard), JSON-decodes it, and
 * strictly re-validates every field — never trusts the model's output
 * shape, length, or enum values just because response_format asked for
 * them. Returns null on anything unusable, same fail-safe contract as
 * validateGeminiResponse().
 *
 * @return array{response_type:string,reply_text:string,resource_type?:string,output_format?:string|null,title?:string,student_content?:string,teacher_answer_key?:string|null,quiz_questions?:array}|null
 */
function validateGeminiResourceResponse($response, $maxReplyLength = 1000)
{
    $text = extractGeminiRawText($response);
    if ($text === null || mb_strlen($text) > GEMINI_MAX_RESOURCE_LENGTH) return null;

    // Defense-in-depth: strip ```json ... ``` fences if the model added
    // them despite the JSON-only instruction and response_format.
    $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
    $text = preg_replace('/\s*```$/', '', $text);
    $text = trim($text);

    $decoded = json_decode($text, true);
    if (!is_array($decoded)) return null;

    $response_type = ($decoded['response_type'] ?? '') === 'resource' ? 'resource' : 'text';

    $reply_text = trim((string) ($decoded['reply_text'] ?? ''));
    if ($reply_text === '') return null;
    if (mb_strlen($reply_text) > $maxReplyLength) $reply_text = mb_substr($reply_text, 0, $maxReplyLength);

    $result = [
        'response_type' => $response_type,
        'reply_text' => $reply_text,
    ];

    if ($response_type === 'resource') {
        // The schema only requires quiz_questions, so the model sometimes
        // returns a complete quiz with no resource_type / title /
        // student_content. Rebuild those from the questions themselves
        // instead of throwing the whole quiz away.
        // Choices must not carry their own "A) " / "B. " label -- the letters
        // are added by the portal / Google Form / the content below.
        if (is_array($decoded['quiz_questions'] ?? null)) {
            foreach ($decoded['quiz_questions'] as &$rawQ) {
                if (is_array($rawQ) && is_array($rawQ['choices'] ?? null)) {
                    $rawQ['choices'] = array_map(function ($c) {
                        return is_string($c) ? preg_replace('/^\s*[A-Fa-f][\).:]\s+/', '', $c) : $c;
                    }, $rawQ['choices']);
                }
            }
            unset($rawQ);
        }
        $recovered_quiz = empty($decoded['student_content']) ? aiSanitizeQuizQuestions($decoded['quiz_questions'] ?? null) : [];

        $resource_type = (string) ($decoded['resource_type'] ?? 'other');
        if (!array_key_exists($resource_type, AI_RESOURCE_TYPES)) {
            $resource_type = $recovered_quiz ? 'quiz' : 'other';
        }
        if ($recovered_quiz) {
            $letters = range('A', 'Z');
            $lines = [];
            $key = [];
            foreach ($recovered_quiz as $i => $q) {
                $lines[] = ($i + 1) . '. ' . $q['question'];
                foreach ($q['choices'] as $ci => $choice) {
                    $lines[] = $letters[$ci] . '. ' . $choice;
                }
                $lines[] = '';
                $key[] = ($i + 1) . '. ' . $letters[$q['correct_index']] . ' - ' . $q['choices'][$q['correct_index']];
            }
            $decoded['student_content'] = trim(implode("\n", $lines));
            if (empty($decoded['teacher_answer_key'])) {
                $decoded['teacher_answer_key'] = implode("\n", $key);
            }
            if (empty($decoded['title'])) {
                $decoded['title'] = AI_RESOURCE_TYPES[$resource_type];
            }
        }

        $output_format = (string) ($decoded['output_format'] ?? 'none');
        $output_format = in_array($output_format, ['pdf', 'docx', 'gform'], true) ? $output_format : null;

        // The whole material sometimes lands in "title" instead of
        // student_content -- move it back rather than losing it.
        if (trim((string) ($decoded['student_content'] ?? '')) === '' && mb_strlen(trim((string) ($decoded['title'] ?? ''))) > 150) {
            $decoded['student_content'] = trim((string) $decoded['title']);
            $decoded['title'] = trim(preg_split('/\s+(?:-|–)\s+|\.\s|\n/u', $decoded['student_content'])[0]);
        }
        $title = trim((string) ($decoded['title'] ?? ''));
        if ($title === '') $title = ucwords(str_replace('_', ' ', $resource_type));
        if (mb_strlen($title) > 150) $title = mb_substr($title, 0, 150);

        $student_content = trim((string) ($decoded['student_content'] ?? ''));
        if ($student_content === '') return null; // a "resource" reply with no actual content is unusable — treat as a failed generation
        if (mb_strlen($student_content) > 12000) $student_content = mb_substr($student_content, 0, 12000);

        $teacher_answer_key = trim((string) ($decoded['teacher_answer_key'] ?? ''));
        if (mb_strlen($teacher_answer_key) > 8000) $teacher_answer_key = mb_substr($teacher_answer_key, 0, 8000);

        $result['resource_type'] = $resource_type;
        $result['output_format'] = $output_format;
        $result['title'] = $title;
        $result['student_content'] = $student_content;
        $result['teacher_answer_key'] = $teacher_answer_key !== '' ? $teacher_answer_key : null;
        $lesson_topic = trim((string) ($decoded['lesson_topic'] ?? ''));
        if ($lesson_topic !== '') $result['lesson_topic'] = mb_substr($lesson_topic, 0, 150);

        if (in_array($resource_type, AI_GFORM_ELIGIBLE_TYPES, true) && isset($decoded['quiz_questions'])) {
            $quiz_questions = aiSanitizeQuizQuestions($decoded['quiz_questions']);
            if (!empty($quiz_questions)) {
                $result['quiz_questions'] = $quiz_questions;
            }
        }
    }

    return $result;
}

/**
 * Defensively re-validate a raw quiz_questions value (from Gemini, or from
 * a client-resubmitted draft — never trusted just because it round-tripped
 * through the browser once already) into a clean array of
 * {question, choices[], correct_index, points}, dropping any malformed
 * item rather than failing the whole set. Returns [] if nothing usable
 * survives, never null, so callers can always safely empty()-check it.
 *
 * @param mixed $raw
 * @return array
 */
function aiSanitizeQuizQuestions($raw)
{
    if (!is_array($raw)) return [];

    $clean = [];
    foreach ($raw as $item) {
        if (!is_array($item)) continue;

        $question = trim((string) ($item['question'] ?? ''));
        if ($question === '' || mb_strlen($question) > 1000) continue;

        $choices_raw = is_array($item['choices'] ?? null) ? $item['choices'] : [];
        $choices = [];
        foreach ($choices_raw as $choice) {
            $choice = trim((string) $choice);
            if ($choice === '' || mb_strlen($choice) > 300) continue;
            $choices[] = $choice;
            if (count($choices) >= 6) break; // hard cap, matches the prompt's 2-6 instruction
        }
        if (count($choices) < 2) continue; // not a usable multiple-choice/true-false item

        $correct_index = (int) ($item['correct_index'] ?? -1);
        if ($correct_index < 0 || $correct_index >= count($choices)) continue;

        $points = (int) ($item['points'] ?? 1);
        if ($points < 1) $points = 1;
        if ($points > 20) $points = 20;

        $clean[] = [
            'question' => $question,
            'choices' => $choices,
            'correct_index' => $correct_index,
            'points' => $points,
        ];

        if (count($clean) >= 100) break; // sanity cap — a chatbot quiz is never realistically this long
    }

    return $clean;
}

// ============================================================
// SUBMISSION GRADING (AI first-pass check of a student's attached answer)
// ============================================================
// A student answers an AI-generated activity in their OWN copy (Word doc,
// printed-and-written-on, or a photo of their paper) and attaches that
// file back — see includes/ai_resource_submission_helpers.php. This is a
// FIRST PASS ONLY: the teacher always sees the suggested score + feedback
// as an editable draft and must explicitly confirm it before it becomes
// the final grade (never posted to the gradebook on its own) — see
// aiResourceMarkReviewed() and modules/ai/resources.php.

/**
 * Fixed, server-side-only system instruction for grading one submission.
 * Never built from anything the browser sends.
 */
function buildSubmissionGradingSystemInstruction()
{
    return 'You are an experienced, fair Philippine DepEd classroom teacher checking ONE student\'s '
        . 'answer to an activity you already assigned. You will be given the ORIGINAL ACTIVITY the '
        . 'student was asked to do, optionally a TEACHER ANSWER KEY / RUBRIC, and the STUDENT\'S SUBMITTED '
        . 'ANSWER (as text, or as an attached photo/PDF you can read directly). '
        . 'Grade generously but honestly: give partial credit for partially correct or partially complete '
        . 'work, do not penalize minor spelling/grammar slips unless the activity is specifically about '
        . 'spelling/grammar, and judge against what a real teacher would reasonably expect from a student '
        . 'at this level. If there is no answer key, judge the answer on its own merits against what the '
        . 'activity asked for. Score strictly out of the exact MAX_SCORE given to you — never a different '
        . 'total. If the submitted image/scan is partly illegible, still give your best-effort score and '
        . 'say plainly in the feedback which part you were unsure about, in Taglish is fine — write '
        . 'feedback the student can read directly, encouraging in tone, 1-3 short sentences, pointing out '
        . 'anything that was wrong or missing. Set is_confident to false whenever you had to guess at any '
        . 'part of the handwriting/scan, or the answer key did not clearly cover part of what was '
        . 'submitted — true only when you are sure of your reading and your score. This is only ever a '
        . 'DRAFT suggestion a human teacher reviews before it counts — never claim otherwise, and never '
        . 'refuse to score just because you are unsure; give your best honest attempt and flag the '
        . 'uncertainty instead.';
}

/** Interactions API response_format schema for buildSubmissionGradingSystemInstruction()'s task. */
function buildSubmissionGradingResponseSchema()
{
    return [
        'type' => 'object',
        'properties' => [
            'suggested_score' => ['type' => 'number'],
            'feedback' => ['type' => 'string'],
            'is_confident' => ['type' => 'boolean'],
            'is_related' => ['type' => 'boolean'],   // optional: only asked for by the Classroom classwork check
        ],
        'required' => ['suggested_score', 'feedback', 'is_confident'],
    ];
}

/**
 * Defensively parse a grading response — never trusts the model's output
 * range or shape just because response_format asked for it.
 *
 * @return array{suggested_score:float,feedback:string,is_confident:bool}|null
 */
function validateGeminiGradingResponse($response, $maxScore)
{
    $text = extractGeminiRawText($response);
    if ($text === null || mb_strlen($text) > 4000) return null;

    $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
    $text = preg_replace('/\s*```$/', '', $text);
    $text = trim($text);

    $decoded = json_decode($text, true);
    if (!is_array($decoded) || !isset($decoded['suggested_score'])) return null;

    $score = (float) $decoded['suggested_score'];
    if ($score < 0) $score = 0;
    if ($maxScore > 0 && $score > $maxScore) $score = $maxScore;

    $feedback = trim((string) ($decoded['feedback'] ?? ''));
    if ($feedback === '') $feedback = 'Checked by AI — no additional feedback given.';
    if (mb_strlen($feedback) > 1500) $feedback = mb_substr($feedback, 0, 1500);

    return [
        'suggested_score' => round($score, 2),
        'feedback' => $feedback,
        'is_confident' => !empty($decoded['is_confident']),
        // Missing = the model was not asked / did not say: treat as related (old behaviour).
        'is_related' => array_key_exists('is_related', $decoded) ? !empty($decoded['is_related']) : true,
    ];
}

/**
 * Ask Gemini for a first-pass suggested score + feedback on ONE student's
 * submitted answer to a resource. Exactly one of $answerText / $answerFile
 * is given by the caller (includes/ai_resource_submission_helpers.php
 * decides which, based on the attached file's extension):
 *   - $answerText: plain text already extracted from the student's file
 *     (currently: .docx, via docxExtractPlainText()).
 *   - $answerFile: ['bytes' => raw file contents, 'mime_type' => '...']
 *     for a photo (image/jpeg, image/png) or PDF (application/pdf) — sent
 *     to Gemini directly; Gemini reads/OCRs it itself, no local extraction
 *     needed.
 *
 * @param array $resource A row from ai_generated_resources — uses
 *   student_content (the original activity) and teacher_answer_key.
 * @param float $maxScore The exact total to grade out of (the linked
 *   assessment item's own highest_score when linked, otherwise a default
 *   the caller picks — e.g. 100).
 * @param string|null $answerText
 * @param array|null $answerFile
 * @return array{success:bool,score?:float,max_score?:float,feedback?:string,is_confident?:bool,error?:string,error_code?:string}
 */
function aiGradeResourceSubmission($resource, $maxScore, $answerText = null, $answerFile = null, $checkRelevance = false)
{
    $activity = trim((string) ($resource['student_content'] ?? ''));
    if ($activity === '') {
        return ['success' => false, 'error' => 'This activity has no recorded content to grade against.', 'error_code' => 'no_activity_content'];
    }

    $prompt = "MAX_SCORE: " . rtrim(rtrim(number_format((float) $maxScore, 2), '0'), '.') . "\n\n";
    $prompt .= "ORIGINAL ACTIVITY GIVEN TO THE STUDENT:\n" . mb_substr($activity, 0, 8000) . "\n\n";

    $answer_key = trim((string) ($resource['teacher_answer_key'] ?? ''));
    if ($answer_key !== '') {
        $prompt .= "TEACHER ANSWER KEY / RUBRIC:\n" . mb_substr($answer_key, 0, 4000) . "\n\n";
    } else {
        $prompt .= "TEACHER ANSWER KEY / RUBRIC: (none provided — judge the answer on its own merits against what the activity asked for)\n\n";
    }

    $filePart = null;
    if ($answerFile !== null && !empty($answerFile['bytes'])) {
        $mime = (string) ($answerFile['mime_type'] ?? '');
        $type = (strpos($mime, 'image/') === 0) ? 'image' : 'document';
        $filePart = [
            'type' => $type,
            'data' => base64_encode($answerFile['bytes']),
            'mime_type' => $mime,
        ];
        $prompt .= "STUDENT'S SUBMITTED ANSWER: attached as a " . ($type === 'image' ? 'photo' : 'file') . " below — read it directly.";
    } else {
        $text = trim((string) $answerText);
        if ($text === '') {
            return ['success' => false, 'error' => 'There is no readable answer content to grade.', 'error_code' => 'empty_answer'];
        }
        $prompt .= "STUDENT'S SUBMITTED ANSWER (extracted text):\n" . mb_substr($text, 0, 8000);
    }

    $response_format = [
        'type' => 'text',
        'mime_type' => 'application/json',
        'schema' => buildSubmissionGradingResponseSchema(),
    ];

    $sysInstruction = buildSubmissionGradingSystemInstruction();
    if ($checkRelevance) {
        // Classroom classwork: first decide if the file is even an answer to THIS activity.
        $sysInstruction .= ' RELEVANCE CHECK (do this first): set is_related to true only if the submitted '
            . 'answer is an actual attempt at THIS activity (same subject/topic as the activity and its answer key). '
            . 'Set is_related to false when the submission is about something else, an unrelated document, a '
            . 'random photo or screenshot, a blank/unfilled template, or has nothing to do with the activity. '
            . 'When is_related is false, suggested_score MUST be 0 and the feedback must say in Taglish, kindly '
            . 'but clearly, that the file does not match the activity and the student should turn in the right work.';
    }
    $api_result = callGeminiAPI(
        $sysInstruction,
        $prompt,
        $response_format,
        GEMINI_GRADING_TIMEOUT_SECONDS,
        $filePart
    );
    if (!$api_result['success']) {
        geminiDebugLog('grading_call_failed', $api_result);
        return ['success' => false, 'error' => $api_result['error'], 'error_code' => $api_result['error_code']];
    }

    $parsed = validateGeminiGradingResponse($api_result['response'], (float) $maxScore);
    if ($parsed === null) {
        error_log('Gemini: grading response received but no usable structured output found. Raw: ' . substr(json_encode($api_result['response']), 0, 2000));
        geminiDebugLog('grading_unparseable_response', $api_result);
        return ['success' => false, 'error' => handleGeminiError(0, null, null, 'empty_or_unusable_response'), 'error_code' => 'empty_response'];
    }

    return [
        'success' => true,
        'score' => $parsed['suggested_score'],
        'max_score' => (float) $maxScore,
        'feedback' => $parsed['feedback'],
        'is_confident' => $parsed['is_confident'],
        'is_related' => $parsed['is_related'],
    ];
}

// ============================================================
// ERROR HANDLING
// ============================================================

/**
 * TEMPORARY diagnostic aid for the new AI-grading feature
 * (aiGradeResourceSubmission()) — writes to a log file INSIDE this
 * project's own static/ folder rather than PHP's server-wide error log,
 * because that server-wide log usually isn't reachable from a plain file
 * read the way this project's own files are. Safe to delete this function
 * (and its two call sites in aiGradeResourceSubmission()) once grading is
 * confirmed reliable — it exists only to see exactly why a grading call
 * failed or returned something unparseable, without needing SSH/RDP
 * access to the server.
 *
 * Every write is wrapped in @-suppressed best-effort code: a logging
 * failure must never break the actual feature it's diagnosing.
 */
function geminiDebugLog($label, $data)
{
    $dir = __DIR__ . '/../static/logs/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . "] $label: " . substr(json_encode($data), 0, 4000) . "\n";
    @file_put_contents($dir . 'gemini_grading_debug.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * Google's own explanation for a rejected request (e.g. "API key not valid",
 * "Generative Language API has not been used in project ... or it is
 * disabled"), shortened and with the configured key removed, so whoever
 * configures the key can see WHY without digging through server logs.
 */
function geminiGoogleReasonSuffix($detail)
{
    $detail = trim((string) $detail);
    if ($detail === '' || $detail === 'unknown error' || $detail === 'invalid_json') {
        return '';
    }
    if (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') {
        $detail = str_replace((string) GEMINI_API_KEY, '[key hidden]', $detail);
    }
    $detail = preg_replace('/\s+/', ' ', $detail);
    if (function_exists('mb_substr')) {
        $detail = mb_substr($detail, 0, 260);
    } else {
        $detail = substr($detail, 0, 260);
    }
    return ' Google said: "' . $detail . '"';
}

/** Always returns a message safe to show a teacher — never internals. */
function handleGeminiError($httpCode, $rawBody, $curlErrno = null, $detail = null)
{
    if ($detail === 'missing_api_key') {
        return 'AI Draft Assistant is not set up yet (missing API key). You can still write and send a manual support message.';
    }
    if ($detail === 'no_curl') {
        return 'AI Draft Assistant is not available on this server. You can still write and send a manual support message.';
    }
    if ($detail === 'empty_or_unusable_response') {
        return 'AI Draft Assistant started generating that but didn\'t finish. Please try sending your message again — this usually goes through on a retry.';
    }
    if ((int) $httpCode === 400 && $detail !== null && (stripos((string) $detail, 'copyright') !== false || stripos((string) $detail, 'recitation') !== false)) {
        // Google's own content-safety filter, not a bug here — it can
        // trigger on ordinary factual/historical quiz content that happens
        // to closely match existing text. Rephrasing the topic (narrower,
        // different wording) usually gets past it on the next try.
        return 'AI Draft Assistant flagged that request as too close to existing copyrighted material and refused to generate it. Try rephrasing the topic (e.g. narrower or worded differently) and send it again.';
    }
    if ($curlErrno !== null) {
        // CURLE_OPERATION_TIMEDOUT — the request took longer than its own
        // timeout allowed. Common for a grading call on a large/blurry
        // photo or scanned PDF (Gemini vision + a bigger upload both take
        // longer than a plain text prompt) — worth telling the teacher
        // this specifically, since simply trying again (or attaching a
        // clearer/smaller photo) often just works.
        if ((int) $curlErrno === 28) {
            return 'AI Draft Assistant took too long to respond — this can happen when checking a large photo or scanned file. Please try again in a moment (or attach a smaller/clearer photo).';
        }
        return 'AI Draft Assistant is temporarily unavailable (connection issue). You can still write and send a manual support message.';
    }
    if (in_array($httpCode, [401, 403], true)) {
        return 'AI Draft Assistant is not configured correctly (Google rejected the API key, error ' . (int) $httpCode . '). Please ask the administrator to check the Gemini API key in config/gemini_config.php.' . geminiGoogleReasonSuffix($detail) . ' You can still write and send a manual support message.';
    }
    if (in_array((int) $httpCode, [400, 404], true)) {
        // A bad request or an unknown model name: almost always the
        // configured GEMINI_MODEL / GEMINI_API_KEY pair, not something the
        // teacher can fix. Say so plainly instead of the generic message.
        return 'AI Draft Assistant could not use the configured Gemini model or key (Google returned error ' . (int) $httpCode . '). Please ask the administrator to check GEMINI_MODEL and GEMINI_API_KEY in config/gemini_config.php.' . geminiGoogleReasonSuffix($detail) . ' You can still write and send a manual support message.';
    }
    if ($httpCode === 429) {
        return 'AI Draft Assistant has reached its usage limit for now — please try again in a bit. You can still write and send a manual support message.';
    }
    if (in_array($httpCode, [500, 502, 503, 504], true)) {
        return 'The AI model is receiving unusually high demand right now. Please try again in a moment. You can still write and send a manual support message.';
    }
    return 'AI Draft Assistant is temporarily unavailable. You can still write and send a manual support message.';
}

// ============================================================
// CLASS-WIDE (SECTION-SCOPED) RESOURCE GENERATION
// ============================================================
// Everything above this point is scoped to ONE teacher + ONE student + ONE
// at-risk subject (the "Ask AI" intervention chat). The Google-Classroom-
// style Teacher Portal's Classwork tab needs the same kind of natural-
// language content generation, but for a WHOLE CLASS (Teacher + Section +
// Subject + School Year + Term) via modules/teachers/classroom.php's "New
// Assignment + AI" composer — nothing here is about an individual at-risk
// student, so it deliberately does not call validateTeacherSubjectForStudent()
// or getWeakSubjectsForStudent(). Ownership instead comes from
// classroomValidateAccess() (includes/classroom_helpers.php), the same
// teacher_subjects-based check already used by modules/grading/index.php
// and modules/teachers/my_sections.php.
//
// Reuses, unchanged: callGeminiAPI(), buildResourceResponseSchema(),
// validateGeminiResourceResponse(), geminiSuggestedLanguage(),
// handleGeminiError(), AI_GFORM_ELIGIBLE_TYPES, AI_RESOURCE_TYPES. Nothing
// above this line was modified to add this section.

require_once __DIR__ . '/classroom_helpers.php'; // classroomValidateAccess()

/**
 * Class-wide system instruction for the Classwork "New Assignment + AI"
 * composer. Same hard boundaries as buildResourceSystemInstruction()
 * (teacher-authorized, one subject/section/term, never decides grades,
 * never auto-posts) except the content is meant for the WHOLE class, not
 * one at-risk student — so, unlike the intervention chat, this must never
 * reference any individual student's name, grade, or performance.
 */
function buildClassResourceSystemInstruction()
{
    $typeList = implode(', ', array_keys(AI_RESOURCE_TYPES));

    return
        "You are the E-GRADEPRO Classwork AI Assistant, embedded in a teacher's \"New Assignment\" composer for ONE specific class, given below in CLASS CONTEXT (grade level, section, subject, term).\n" .
        "You are having a real back-and-forth conversation with the teacher. Read CONVERSATION SO FAR and CURRENT DRAFT (if present) and respond as a natural continuation.\n" .
        "You may explain concepts, suggest classroom activities, and — most importantly — actually GENERATE academic content for this whole class to receive as one assignment: quizzes, practice quizzes, reviewers, remedial/practice activities, worksheets, performance tasks, written work, question banks, study guides, rubrics, assignments, and practice tests.\n" .
        "Stay strictly within academic content for this one subject/class. Do not behave as a general-purpose chatbot and do not answer questions unrelated to this subject/class — politely decline (response_type \"text\") and steer back.\n" .
        "Do not invent grades, attendance, behavior, or personal information about any specific student — this content goes to the whole class, not one individual, and must never reference or single out any student by name or performance.\n" .
        "You never make final decisions and never automatically post anything to the class — a teacher must always explicitly review and click Assign/Post to Class afterward.\n" .
        "LANGUAGE RULE: same as elsewhere in this app — reply (including reply_text, title, student_content, teacher_answer_key) in the language matching the subject's medium of instruction (see 'Preferred response language' below if present, otherwise infer: Filipino for Filipino/Araling Panlipunan/EsP-type subjects, English otherwise). Keep the whole reply in one language.\n" .
        "\n" .
        "RESPOND WITH ONLY A SINGLE JSON OBJECT (no markdown fences, no extra prose outside the JSON) with these fields:\n" .
        "  response_type: \"text\" or \"resource\". Use \"resource\" whenever the teacher asks you to create/generate/make/gumawa any quiz, activity, reviewer, worksheet, performance task, PeTa, rubric, study guide, assignment, or set of questions.\n" .
        "    Before generating, check whether the teacher gave the essential details (topic/coverage, format, item count/length, difficulty, language, time limit where relevant). If any are missing, never just ask an open-ended question — use response_type \"text\", generate no output, and in reply_text propose YOUR OWN concrete suggested values for each missing detail (a specific topic, a specific format, etc.), clearly labeled as suggestions, then explicitly ask the teacher to confirm before anything is generated. A vague or non-committal reply is not approval — generate only after an explicit yes/confirmation or the teacher's own replacement details.\n" .
        "  reply_text: ALWAYS present. A short (1-3 sentence) natural chat reply. Never append generic boilerplate reminders such as \"review and approve before sending\" or \"grades are never changed automatically\" — the interface already makes that clear, so repeating it only adds noise. Only mention that kind of caveat if the teacher specifically asks about it.\n" .
        "  resource_type: required when response_type is \"resource\" — exactly one of: {$typeList}.\n" .
        "  output_format: required when response_type is \"resource\" — \"pdf\" if the teacher's message clearly asks for a PDF/downloadable file, \"docx\" if they ask for Word/DOCX, otherwise \"none\".\n" .
        "  title: required when response_type is \"resource\" — a short, specific title.\n" .
        "  student_content: required when response_type is \"resource\" — the actual student-facing material (instructions + questions/activity). Plain text only: blank line = paragraph break; a line starting with \"- \" = bullet item; a line starting with a number and \". \" (e.g. \"1. \") = numbered item, with lettered choices under each question for quizzes. NEVER reference any individual student.\n" .
        "  teacher_answer_key: only when the resource type has objectively correct answers — leave \"\" otherwise.\n" .
        "  quiz_questions: ONLY when resource_type is one of: " . implode(', ', AI_GFORM_ELIGIBLE_TYPES) . " — array of {question, choices[], correct_index, points}, same order as student_content. Honor an explicit item count exactly if the teacher stated one; omit this field entirely if any question in the set is open-ended.\n" .
        "If a CURRENT DRAFT is given below and the teacher's new message asks to change, add to, simplify, or extend it, respond with response_type \"resource\" and return the FULL updated content — not a diff.";
}

/**
 * Class-wide (section-scoped) counterpart to getGeminiResourceReply() — the
 * engine behind the Classwork tab's "New Assignment + AI" composer. Every
 * call independently re-validates teacher/section/subject/term ownership
 * via classroomValidateAccess() (never trusts an earlier turn).
 *
 * @param array      $history     Prior turns, oldest first: ['role'=>'teacher'|'ai','text'=>'...']
 * @param string     $userMessage Empty string = chat was just opened (initial greeting)
 * @param array|null $currentDraft Same shape as getGeminiResourceReply()'s currentDraft
 *
 * @return array{success:bool,response_type?:string,reply_text?:string,resource_type?:string,output_format?:string,title?:string,student_content?:string,teacher_answer_key?:string,quiz_questions?:array,class?:array,error?:string,error_code?:string}
 */
function getGeminiClassResourceReply($teacherId, $sectionId, $subjectCode, $syId, $term, array $history, $userMessage, $currentDraft = null, $assignment = null)
{
    $teacherId = (int) $teacherId;
    $userMessage = trim((string) $userMessage);

    if (mb_strlen($userMessage) > 1000) {
        return ['success' => false, 'error' => 'Your message is too long — please shorten it.', 'error_code' => 'message_too_long'];
    }

    $teacher = getRecord("SELECT u.user_id, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ? AND u.is_active = 1", [$teacherId]);
    if (!$teacher || $teacher['role_name'] !== 'Teacher') {
        return ['success' => false, 'error' => 'Only the assigned teacher can use the AI assistant for this class.', 'error_code' => 'not_a_teacher'];
    }

    $check = classroomValidateAccess($teacherId, $sectionId, $subjectCode, $syId, $term);
    if (!$check['success']) {
        return ['success' => false, 'error' => $check['error'], 'error_code' => 'not_assigned'];
    }
    $class = $check['class'];

    $system_instruction = buildClassResourceSystemInstruction();

    $user_input = "CLASS CONTEXT\n";
    $user_input .= "Grade Level: " . ($class['section_grade_level'] ?: $class['grade_level']) . "\n";
    $strand = $class['section_strand'] ?: $class['strand'];
    if (!empty($strand)) {
        $user_input .= "Strand: " . $strand . "\n";
    }
    $user_input .= "Section: " . $class['section_name'] . "\n";
    $user_input .= "Subject: " . $class['subject_name'] . "\n";
    if (!empty($class['subject_term'])) {
        $user_input .= "Term: " . $class['subject_term'] . "\n";
    }

    $suggested_language = geminiSuggestedLanguage($class['subject_name']);
    if ($suggested_language !== null) {
        $user_input .= "Preferred response language: " . $suggested_language . " (this subject is taught in " . $suggested_language . " — reply entirely in " . $suggested_language . ").\n";
    }
    $user_input .= "\n";

    // The assignment the teacher is setting up in Classwork -> Create. Its
    // assessment type decides WHAT to generate; this overrides any habit of
    // defaulting to a quiz.
    $filePart = null;
    if (is_array($assignment) && !empty($assignment['type'])) {
        $user_input .= "ASSIGNMENT BEING CREATED (follow this exactly; it overrides any default of making a quiz)\n"
            . "Assessment type selected by the teacher: " . $assignment['type'] . " (grading category: " . $assignment['grading_label'] . ")\n"
            . ($assignment['title'] !== '' ? "Title: " . $assignment['title'] . "\n" : '')
            . ($assignment['instructions'] !== '' ? "Teacher's instruction: " . $assignment['instructions'] . "\n" : '')
            . ($assignment['points'] > 0 ? "Total points: " . $assignment['points'] . " (the scoring must add up to exactly this)\n" : '')
            . ($assignment['lesson_names'] ? "Attached lesson file(s): " . implode('; ', $assignment['lesson_names']) . "\n" : "No lesson file attached.\n")
            . "What to generate: " . $assignment['rule'] . "\n"
            . "Base it ONLY on the attached lesson (when there is one) and the teacher's instruction, for this grade level, section, subject and term. Never add unrelated topics.\n"
            . (($assignment['lesson_names'] ?? []) || !empty($assignment['lesson_text']) || !empty($assignment['file_part'])
                ? "THE ATTACHED LESSON IS THE MAIN SOURCE: first read it and identify its main topic (put it in lesson_topic, e.g. \"Philippine Poetry: elements of a poem\"). Every question, item and task must come directly from the lesson's own content -- its terms, definitions, examples, facts and texts. Do not write generic questions that could fit any lesson, and do not use outside topics.\n"
                : "No lesson is attached: use the title and the teacher's instruction as the topic, and put that topic in lesson_topic.\n")
            . "When you generate it, use response_type \"resource\", put the complete student-facing content in student_content (with clear directions), and the answer key / rubric / scoring guide in teacher_answer_key.\n"
            . (($assignment['output'] ?? '') === 'gform'
                ? "DELIVERY: an auto-graded GOOGLE FORM. Every item must be a single-best-answer multiple-choice (4 choices) or true/false question. Fill quiz_questions with EVERY item, in the same order as student_content"
                    . ($assignment['points'] > 0 ? ", and make the points of all quiz_questions add up to exactly " . $assignment['points'] : '') . ". Set output_format \"gform\".\n"
                : '');
        if (!empty($assignment['lesson_text'])) {
            $user_input .= "ATTACHED LESSON CONTENT:\n" . mb_substr($assignment['lesson_text'], 0, 12000) . "\n";
        }
        $user_input .= "\n";
        $filePart = $assignment['file_part'] ?? null;
    }

    if (is_array($currentDraft) && trim((string) ($currentDraft['student_content'] ?? '')) !== '') {
        $user_input .= "CURRENT DRAFT (the latest generated resource in this conversation — if the teacher's message asks to change, add to, or simplify it, edit THIS content and return the full updated version)\n";
        $user_input .= "Resource Type: " . (string) ($currentDraft['resource_type'] ?? '') . "\n";
        $user_input .= "Title: " . (string) ($currentDraft['title'] ?? '') . "\n";
        $user_input .= "Student Content:\n" . mb_substr((string) $currentDraft['student_content'], 0, 6000) . "\n";
        if (!empty($currentDraft['teacher_answer_key'])) {
            $user_input .= "Teacher Answer Key:\n" . mb_substr((string) $currentDraft['teacher_answer_key'], 0, 3000) . "\n";
        }
        $user_input .= "\n";
    }

    $recent_history = array_slice($history, -10);
    $has_history = false;
    if (!empty($recent_history)) {
        $history_lines = [];
        foreach ($recent_history as $turn) {
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text === '') continue;
            $role = ($turn['role'] ?? '') === 'ai' ? 'AI' : 'Teacher';
            $history_lines[] = $role . ': ' . $text;
        }
        if (!empty($history_lines)) {
            $has_history = true;
            $user_input .= "CONVERSATION SO FAR\n" . implode("\n", $history_lines) . "\n\n";
        }
    }

    if ($userMessage === '') {
        $user_input .= $has_history
            ? "The teacher hasn't sent a new message yet. Briefly continue being helpful for this class/subject."
            : "The teacher just opened this conversation for the class above. Greet them briefly, and offer one concrete idea for classwork/assessment content for this subject to start the conversation. response_type should be \"text\".";
    } else {
        $user_input .= "Teacher: " . $userMessage;
    }

    $result = geminiGenerateResource($system_instruction, $user_input, GEMINI_RESOURCE_TIMEOUT_SECONDS, 1000, $filePart);
    if (!$result['success']) {
        return ['success' => false, 'error' => $result['error'], 'error_code' => $result['error_code']];
    }

    // The class-wide Classwork composer only knows pdf / docx / none. The
    // shared schema now also allows 'gform' for the Ask AI chat, so never
    // let that value leak into this unrelated flow.
    if (($result['parsed']['output_format'] ?? null) === 'gform') {
        $result['parsed']['output_format'] = null;
    }
    // A generated resource is always labelled with the type the teacher chose.
    if (is_array($assignment) && !empty($assignment['resource_type']) && ($result['parsed']['response_type'] ?? '') === 'resource') {
        $result['parsed']['resource_type'] = $assignment['resource_type'];
        if (!in_array($assignment['kind'] ?? '', ['quiz', 'examination'], true)) {
            unset($result['parsed']['quiz_questions']);
        } elseif (($assignment['output'] ?? '') === 'gform') {
            $result['parsed']['output_format'] = 'gform';
            if (empty($result['parsed']['quiz_questions'])) {
                // The model wrote the quiz as text only: turn that same draft into Form questions.
                $result['parsed']['quiz_questions'] = geminiConvertDraftToQuizQuestions(
                    ['class_prompt' => "Grade Level: " . ($class['section_grade_level'] ?: $class['grade_level']) . "\nSubject: " . $class['subject_name'] . "\nWhole class quiz."],
                    $result['parsed']
                );
            }
        }
    }

    return array_merge(['success' => true], $result['parsed'], ['class' => $class]);
}