<?php
// includes/ai_chat_outputs.php
// Helpers for the Ask AI chat (modules/ai/chatbot.php) so that a typed
// command such as "Generate this as PDF", "Make this a Word file" or "Create
// this as Google Form" produces the REAL file / Form inside the chat, not
// just text.
//
// Nothing here is new machinery: PDF/Word rendering is still
// aiGenerateResourceFile() (includes/ai_document_generator.php) and the Form
// is still googleFormsCreateQuizForm() (includes/google_forms_service.php),
// stored in the same ai_generated_resources columns modules/ai/
// generate_resource.php and modules/ai/create_quiz_form.php already write --
// so Preview/Open, Download, Send to Student, Quiz History and the score sync
// all keep working on these rows exactly as they do on rows made with the
// existing buttons. Nothing is ever sent to a student from here; that is
// still only modules/ai/send_to_student.php, on the teacher's own click.

require_once __DIR__ . '/gemini_service.php';
require_once __DIR__ . '/ai_document_generator.php';
require_once __DIR__ . '/google_forms_service.php'; // googleFormsIsConfigured(), googleFormsCreateQuizForm()

/**
 * Which file/Form output a chat message asks for, decided server-side from
 * the words themselves -- 'gform', 'pdf', 'docx' or null. English and the
 * common Filipino phrasing ("gawing PDF", "gumawa ng Google Form") both work
 * because only the format keywords are matched.
 */
function aiDetectOutputCommand($message)
{
    $m = mb_strtolower(trim((string) $message));
    if ($m === '') {
        return null;
    }
    if (preg_match('/\bgoogle\s*-?\s*forms?\b|\bg-?forms?\b/u', $m)) {
        return 'gform';
    }
    if (preg_match('/\bpdf\b/u', $m)) {
        return 'pdf';
    }
    // "word" on its own is an ordinary English word ("word problems", "key
    // word"), so it only counts in a file-format sense.
    if (preg_match('/\.docx\b|\bdocx\b|\b(?:microsoft|ms)\s+word\b|\bword\s+(?:file|document|doc|format|version)\b|\b(?:as|in|to|into)\s+(?:a\s+|an\s+)?word\b(?!\s*(?:problem|search|bank|list|puzzle|wall|game|cloud|study|families|family|work|meaning|choice))/u', $m)) {
        return 'docx';
    }
    if (preg_match('/^\s*(?:ms\s+)?word\s*[.!]?\s*$/u', $m)) {
        return 'docx';
    }
    return null;
}

/**
 * True when the message is only asking to CONVERT the draft already on
 * screen ("Generate this as PDF", "Make it a Word file", "Create this as
 * Google Form", or just "PDF") -- as opposed to asking for NEW content that
 * also names a format ("Create a 15-item quiz on fractions as PDF").
 */
function aiIsConversionCommand($message)
{
    $m = mb_strtolower(trim((string) $message));
    if ($m === '') {
        return false;
    }
    // Wants a fresh version, never a straight conversion.
    if (preg_match('/\b(regenerate|redo|again|fresh|another|different|new version|rewrite|revise|change|edit|add|remove|replace|simplify|easier|harder|shorter|longer)\b/u', $m)) {
        return false;
    }

    $hasDemonstrative = (bool) preg_match('/\b(this|that|it|these|above|same|current|draft|ito|iyan|niyan|na ito)\b/u', $m);
    $namesContent = (bool) preg_match('/\b(quiz|activity|worksheet|reviewer|exam|test|lesson|assessment|remedial|remediation|task|plan|guide|flashcards?|rubric|questions?|items?|topic|about|covering|regarding)\b/u', $m);

    // Strip everything that is just filler / format vocabulary; whatever is
    // left is "new content" the teacher is describing.
    $filler = 'please|pls|kindly|paki|po|can|could|would|will|you|me|us|i|want|need|like|to|as|into|in|a|an|the|and|also|now|then|so|just|'
        . 'generate|create|make|convert|export|turn|save|give|download|produce|send|gawing|gawin|gumawa|gawa|i-generate|ilagay|'
        . 'this|that|it|these|above|same|current|draft|resource|content|file|document|doc|format|version|copy|ito|iyan|niyan|na|ng|sa|bilang|'
        . 'quiz|activity|worksheet|reviewer|exam|test|assessment|task|word|pdf|docx|microsoft|ms|google|form|forms|gform|gforms|'
        . 'for|of|him|her|student|out|on|its|be|is|good|thanks|thank';
    $rest = preg_replace('/\.docx\b/u', ' ', $m);
    $rest = preg_replace('/\b(?:' . $filler . ')\b/u', ' ', $rest);
    $words = preg_split('/[^\p{L}\p{N}]+/u', $rest, -1, PREG_SPLIT_NO_EMPTY);
    $words = array_filter($words, function ($w) { return !preg_match('/^\d+$/', $w); });

    if (count($words) > 1) {
        return false;
    }
    return $hasDemonstrative || !$namesContent;
}

/**
 * Render the resource as a PDF/Word file and record it as a Generated
 * ai_generated_resources row -- the same thing modules/ai/generate_resource.php
 * does for the Generate PDF / Generate Word buttons, minus the optional
 * gradebook link (the chat command has no picker; a teacher can still link
 * later from Student Submissions).
 *
 * @param array  $resource ['resource_type','title','student_content','teacher_answer_key']
 * @param array  $docContext ['student_name','grade_level','subject_name','teacher_name','date']
 * @return array{success:bool,resource_id?:int,original_filename?:string,file_size?:int,error?:string}
 */
function aiChatSaveFileResource($teacherId, $studentId, $subjectCode, array $resource, array $docContext, $format, $sourcePrompt = '')
{
    $format = $format === 'docx' ? 'docx' : 'pdf';
    $file = aiGenerateResourceFile($resource, $docContext, $format, 'student');
    if (empty($file['success'])) {
        return ['success' => false, 'error' => $file['error'] ?? 'The document could not be generated. Please try again.'];
    }

    $activeSy = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
    $resourceId = insertRecord(
        "INSERT INTO ai_generated_resources
            (teacher_id, student_id, subject_code, school_year_id, resource_type, title, source_prompt,
             student_content, teacher_answer_key, audience, output_format, stored_filename, original_filename,
             mime_type, file_size, status, generated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'student', ?, ?, ?, ?, ?, 'Generated', NOW())",
        [
            (int) $teacherId,
            (int) $studentId,
            $subjectCode,
            $activeSy['sy_id'] ?? null,
            $resource['resource_type'],
            $resource['title'],
            $sourcePrompt !== '' ? mb_substr($sourcePrompt, 0, 500) : null,
            $resource['student_content'],
            !empty($resource['teacher_answer_key']) ? $resource['teacher_answer_key'] : null,
            $format,
            $file['stored_filename'],
            $file['original_filename'],
            $file['mime_type'],
            $file['file_size'],
        ]
    );
    if (!$resourceId) {
        return ['success' => false, 'error' => 'The file was created, but could not be saved. Please try again.'];
    }

    logActivity(
        (int) $teacherId,
        'AI Resource',
        'Generated ' . strtoupper($format) . ' resource',
        "Resource #$resourceId ({$resource['title']}) for student #$studentId, subject $subjectCode"
    );

    return [
        'success' => true,
        'resource_id' => (int) $resourceId,
        'original_filename' => $file['original_filename'],
        'file_size' => (int) $file['file_size'],
    ];
}

/**
 * The first EXISTING assessment item this student has no score for, across
 * the term(s) in which this subject is at risk -- the same item the manual
 * "Create Google Form Quiz" picker defaults to. Read-only. Returns null when
 * nothing can be chosen safely (no at-risk term, nothing missing) so the
 * caller falls back to the picker rather than guessing a gradebook link.
 *
 * @return array|null ['item' => assessment_items row, 'term' => string, 'section_id' => int]
 */
function aiChatResolveRecoveryItem($teacherId, $studentId, $subjectCode, array $student, array $activeSy)
{
    require_once __DIR__ . '/grading_helpers.php'; // getAssessmentItems(), getAssessmentScoresMap(), isValidTerm()

    if (empty($student['section_id'])) {
        return null;
    }
    $teacherSubject = getRecord(
        "SELECT ts.* FROM teacher_subjects ts WHERE ts.teacher_id = ? AND ts.subject_code = ? AND ts.section_id = ? AND ts.school_year_id = ?",
        [(int) $teacherId, $subjectCode, $student['section_id'], $activeSy['sy_id']]
    );
    if (!$teacherSubject) {
        return null;
    }
    $sectionId = (int) $teacherSubject['section_id'];

    $terms = [];
    foreach (getAtRiskSubjectRecords((int) $studentId, $activeSy['sy_id']) as $record) {
        if (($record['subject_code'] ?? '') === $subjectCode && !in_array($record['term'], $terms, true)) {
            $terms[] = $record['term'];
        }
    }

    foreach ($terms as $term) {
        if (!isValidTerm($term)) continue;
        $grouped = getAssessmentItems((int) $teacherId, $subjectCode, $sectionId, $activeSy['sy_id'], $term);
        $allIds = array_merge(
            array_column($grouped['WW'], 'item_id'),
            array_column($grouped['PT'], 'item_id'),
            array_column($grouped['QA'], 'item_id')
        );
        if (empty($allIds)) continue;
        $scores = getAssessmentScoresMap($allIds);
        foreach (['WW', 'PT', 'QA'] as $category) {
            foreach ($grouped[$category] as $item) {
                if (($scores[$item['item_id']][$studentId] ?? null) === null) {
                    return ['item' => $item, 'term' => $term, 'section_id' => $sectionId];
                }
            }
        }
    }
    return null;
}

/**
 * Create the auto-graded Google Form for a quiz drafted in the chat and store
 * it exactly like modules/ai/create_quiz_form.php does. The gradebook link is
 * REQUIRED by that endpoint (the score has to land on an existing item), so
 * here it is filled in only when it can be determined without guessing --
 * see aiChatResolveRecoveryItem(). Otherwise 'needs_link' => true and the
 * chat shows the normal card with the Term / assessment pickers.
 *
 * @return array{success:bool,needs_link?:bool,error?:string,resource_id?:int,edit_url?:string,view_url?:string,max_score?:int,question_count?:int,link_category?:string,link_term?:string,link_item_id?:int,link_item_name?:string,one_attempt?:bool}
 */
function aiChatCreateGoogleForm($teacherId, $studentId, $subjectCode, array $resource, array $quizQuestions, $sourcePrompt = '')
{
    if (!googleFormsIsConfigured()) {
        return ['success' => false, 'error' => 'Google Forms is not set up yet, so I could not create the Form (an Administrator can finish it in Settings → Google Forms Setup). You can still send this quiz to the student now: click Send to Student and it goes out as a PDF.'];
    }
    $quizQuestions = aiSanitizeQuizQuestions($quizQuestions);
    if (empty($quizQuestions)) {
        return ['success' => false, 'error' => 'No gradable multiple-choice questions were found in this quiz, so a Google Form could not be built. Try regenerating it, or use PDF / Word instead.'];
    }
    if (!in_array($resource['resource_type'], AI_GFORM_ELIGIBLE_TYPES, true)) {
        $resource['resource_type'] = 'quiz';
    }

    $ctx = geminiResolveStudentContext($teacherId, $studentId, $subjectCode);
    if (!$ctx['success']) {
        return ['success' => false, 'error' => $ctx['error']];
    }
    $student = $ctx['student'];
    $activeSy = $ctx['active_sy'];

    $link = aiChatResolveRecoveryItem($teacherId, $studentId, $subjectCode, $student, $activeSy);
    if ($link === null) {
        return [
            'success' => false,
            'needs_link' => true,
            'error' => 'Your quiz is ready, but I could not tell which existing assessment it recovers (no missing-score item was found for the at-risk term). Pick a Term and the assessment below, then click Create Google Form Quiz.',
        ];
    }
    $item = $link['item'];

    $title = trim((string) $resource['title']);
    if ($title === '') $title = aiResourceTypeLabel($resource['resource_type']);
    if (mb_strlen($title) > 150) $title = mb_substr($title, 0, 150);

    $studentName = trim($student['first_name'] . ' ' . $student['last_name']);
    $description = $title . ' — for ' . $studentName . ' (' . $ctx['subject']['subject_name'] . '). Auto-generated by E-GradePro Ask AI; your teacher will see your score automatically once you submit.';

    $form = googleFormsCreateQuizForm($title, $description, $quizQuestions);
    if (empty($form['success'])) {
        return ['success' => false, 'error' => $form['error'] ?? 'The Google Form could not be created. Please try again.'];
    }

    $resourceId = insertRecord(
        "INSERT INTO ai_generated_resources
            (teacher_id, student_id, subject_code, school_year_id, resource_type, title, source_prompt,
             student_content, teacher_answer_key, audience, output_format,
             google_form_id, google_form_edit_url, google_form_view_url, quiz_questions_json, quiz_max_score, quiz_one_attempt,
             link_category, link_term, link_item_id, grade_sync_status,
             status, generated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'student', 'gform', ?, ?, ?, ?, ?, 0, ?, ?, ?, NULL, 'Generated', NOW())",
        [
            (int) $teacherId,
            (int) $studentId,
            $subjectCode,
            $activeSy['sy_id'],
            $resource['resource_type'],
            $title,
            $sourcePrompt !== '' ? mb_substr($sourcePrompt, 0, 500) : null,
            trim((string) $resource['student_content']) !== '' ? $resource['student_content'] : $title,
            !empty($resource['teacher_answer_key']) ? $resource['teacher_answer_key'] : null,
            $form['form_id'],
            $form['edit_url'],
            $form['view_url'],
            json_encode($quizQuestions),
            $form['max_score'],
            $item['category'],
            $link['term'],
            (int) $item['item_id'],
        ]
    );
    if (!$resourceId) {
        return ['success' => false, 'error' => 'The Google Form was created, but could not be saved here. Please try again.'];
    }

    logActivity(
        (int) $teacherId,
        'AI Resource',
        'Created Google Form quiz',
        "Resource #$resourceId ({$title}) for student #$studentId, subject $subjectCode — " . count($quizQuestions) . ' questions, ' . $form['max_score'] . ' points. '
            . 'Linked to EXISTING Assessment Item #' . (int) $item['item_id'] . " ({$item['category']}, {$link['term']}) from the chat command."
    );

    return [
        'success' => true,
        'resource_id' => (int) $resourceId,
        'title' => $title,
        'resource_type' => $resource['resource_type'],
        'resource_type_label' => aiResourceTypeLabel($resource['resource_type']),
        'edit_url' => $form['edit_url'],
        'view_url' => $form['view_url'],
        'max_score' => (int) $form['max_score'],
        'question_count' => count($quizQuestions),
        'one_attempt' => false,
        'link_category' => $item['category'],
        'link_term' => $link['term'],
        'link_item_id' => (int) $item['item_id'],
        'link_item_name' => (string) ($item['item_name'] ?? ''),
    ];
}
