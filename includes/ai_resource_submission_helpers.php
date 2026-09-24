<?php
// includes/ai_resource_submission_helpers.php
//
// Lets a student answer an AI-generated PDF/DOCX resource (see
// includes/ai_document_generator.php / ai_generated_resources) directly
// inside the student portal, instead of only being able to View/Download
// it with no way to send an answer back. See
// database/migrations/2026_ai_resource_submissions.sql for the table this
// file reads/writes.
//
// Two paths, chosen purely by whether the resource already carries a
// structured `quiz_questions_json` (multiple-choice/true-false -- the same
// column the "Create Google Form Quiz" feature populates, see
// includes/ai_quiz_gform_helpers.php, but present here regardless of
// output_format):
//   - Auto-graded: the student's chosen option per question is compared to
//     each question's correct_index right here, instantly -- no Google
//     Form, no waiting on the teacher.
//   - Manual-check: no structured question set exists (a worksheet, essay,
//     or discussion-type activity has no single correct answer to grade
//     against). The student downloads the activity, answers it in their
//     own copy, and ATTACHES that answered file back -- it is only ever
//     RECORDED, never scored. A teacher must open modules/ai/resources.php,
//     open the file, and mark it Reviewed (optionally with feedback)
//     before the student sees anything beyond "waiting for your teacher to
//     check this".

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ai_assessment_items.php'; // aiAssessmentBackfillLink(), aiAssessmentTableExists() -- links a graded quiz to its Manage Assessment Item

// Where a student's answered file lands -- deliberately its own directory
// (never static/uploads/ai_resources/, which holds the TEACHER's generated
// originals) so a student upload can never be confused with, or overwrite,
// the source activity. Same "random name on disk, real name in the DB
// column" convention as ai_generated_resources.stored_filename.
define('AI_RESOURCE_ANSWER_UPLOAD_DIR', __DIR__ . '/../static/uploads/ai_resource_answers/');
define('AI_RESOURCE_ANSWER_MAX_BYTES', 15 * 1024 * 1024); // 15 MB
const AI_RESOURCE_ANSWER_ALLOWED_EXT = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

/**
 * The single submission row (if any) a student has for one resource.
 *
 * @param int $resource_id
 * @param int $student_id
 * @return array|null
 */
function aiResourceGetSubmission($resource_id, $student_id)
{
    return getRecord(
        "SELECT * FROM ai_resource_submissions WHERE resource_id = ? AND student_id = ?",
        [(int) $resource_id, (int) $student_id]
    );
}

/**
 * Score a set of multiple-choice/true-false answers against a resource's
 * quiz_questions_json, exactly the same {question, choices[], correct_index,
 * points} shape the Google Form path already uses. An unanswered or
 * out-of-range question is simply wrong (0 points for it) -- never fatal,
 * never treated as "incomplete" the way a missing grade component is
 * elsewhere in this app, since a quiz attempt is either submitted or not.
 *
 * @param array $questions Decoded quiz_questions_json.
 * @param array $answers Student's chosen choice-index per question, keyed by the SAME numeric position as $questions (0-based).
 * @return array{score: float, max_score: float}
 */
function aiResourceGradeAnswers($questions, $answers)
{
    $score = 0.0;
    $max_score = 0.0;

    foreach (array_values($questions) as $i => $q) {
        $points = isset($q['points']) && is_numeric($q['points']) ? (float) $q['points'] : 1.0;
        $max_score += $points;

        $correct = isset($q['correct_index']) ? (int) $q['correct_index'] : null;
        $chosen = (isset($answers[$i]) && $answers[$i] !== '' && $answers[$i] !== null) ? (int) $answers[$i] : null;

        if ($correct !== null && $chosen !== null && $chosen === $correct) {
            $score += $points;
        }
    }

    return ['score' => round($score, 2), 'max_score' => round($max_score, 2)];
}

/**
 * Add the "teacher allowed a retake" flag to ai_resource_submissions
 * (database/migrations/2026_ai_quiz_one_attempt_retake.sql) if the live
 * database does not have it yet. Additive only; every existing row keeps
 * retake_allowed = 0. Returns whether the column is available.
 */
function aiResourceEnsureSchema()
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $has = function () {
        $r = getRecord(
            "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_resource_submissions' AND COLUMN_NAME = 'retake_allowed'"
        );
        return $r && (int) $r['n'] > 0;
    };
    if (!$has()) {
        executeQuery("ALTER TABLE `ai_resource_submissions` ADD COLUMN `retake_allowed` tinyint(1) NOT NULL DEFAULT 0");
    }
    $ready = $has();
    return $ready;
}

/**
 * Is this quiz a GRADED assessment (one attempt) or just practice?
 * Graded = its score counts toward a Manage Assessment Item (it is linked to
 * one) or the teacher explicitly made it one-attempt. Practice quizzes that
 * were never linked keep working exactly as before (retake any time).
 *
 * @param array $resource ai_generated_resources row
 */
function aiResourceQuizIsGraded(array $resource)
{
    return !empty($resource['link_item_id']) || (int) ($resource['quiz_one_attempt'] ?? 0) === 1;
}

/**
 * What the student portal needs to know about a student's activities, read
 * separately from the main message query so a database that has not been
 * migrated yet can never break that query.
 *   teacher_ai   sent by Teacher AI to a class (answered inside the portal)
 *   graded       one attempt only (counts toward Manage Assessment Items)
 *   retake       the teacher has allowed another attempt
 * A Teacher AI quiz sent before automatic recording existed is linked here the
 * first time it is looked at (see aiAssessmentBackfillLink()).
 *
 * @param int   $student_id
 * @param int[] $resource_ids
 * @return array resource_id => ['teacher_ai'=>bool,'graded'=>bool,'retake'=>bool]
 */
function aiResourcePortalFlags($student_id, array $resource_ids)
{
    $flags = [];
    foreach ($resource_ids as $rid) {
        if ((int) $rid > 0) {
            $flags[(int) $rid] = ['teacher_ai' => false, 'graded' => false, 'retake' => false];
        }
    }
    if (empty($flags)) {
        return $flags;
    }
    aiResourceEnsureSchema();
    $ids = array_keys($flags);
    $in = implode(',', array_fill(0, count($ids), '?'));

    if (aiAssessmentTableExists('teacher_ai_resource_recipients')) {
        foreach (getRecords(
            "SELECT student_resource_id FROM teacher_ai_resource_recipients WHERE student_id = ? AND student_resource_id IN ($in)",
            array_merge([(int) $student_id], $ids)
        ) as $r) {
            $flags[(int) $r['student_resource_id']]['teacher_ai'] = true;
        }
    }

    foreach (getRecords(
        "SELECT * FROM ai_generated_resources WHERE student_id = ? AND resource_id IN ($in)",
        array_merge([(int) $student_id], $ids)
    ) as $row) {
        $rid = (int) $row['resource_id'];
        if ($flags[$rid]['teacher_ai'] && empty($row['link_item_id']) && !empty($row['quiz_questions_json'])) {
            $fresh = aiAssessmentBackfillLink($row);
            if ($fresh) {
                $row = $fresh;
            }
        }
        $flags[$rid]['graded'] = aiResourceQuizIsGraded($row);
    }

    foreach (getRecords(
        "SELECT resource_id, retake_allowed FROM ai_resource_submissions WHERE student_id = ? AND resource_id IN ($in)",
        array_merge([(int) $student_id], $ids)
    ) as $r) {
        $flags[(int) $r['resource_id']]['retake'] = (int) ($r['retake_allowed'] ?? 0) === 1;
    }
    return $flags;
}

/**
 * The teacher lets ONE student answer ONE quiz once more. Ownership is checked
 * here (the submission's resource must belong to this teacher). The next
 * submission replaces the score in Manage Assessment Items and Grade Management
 * (the latest attempt counts) and locks the quiz again.
 *
 * @return array{success:bool,error?:string}
 */
function aiResourceAllowRetake($submission_id, $teacher_id)
{
    if (!aiResourceEnsureSchema()) {
        return ['success' => false, 'error' => 'Retakes could not be enabled on this database yet. Please try again.'];
    }
    $row = getRecord(
        "SELECT s.submission_id, s.is_auto_graded FROM ai_resource_submissions s
         JOIN ai_generated_resources r ON r.resource_id = s.resource_id
         WHERE s.submission_id = ? AND r.teacher_id = ?",
        [(int) $submission_id, (int) $teacher_id]
    );
    if (!$row || (int) $row['is_auto_graded'] !== 1) {
        return ['success' => false, 'error' => 'Submission not found.'];
    }
    executeQuery("UPDATE ai_resource_submissions SET retake_allowed = 1 WHERE submission_id = ?", [(int) $submission_id]);
    return ['success' => true];
}

/**
 * Save a student's multiple-choice/true-false answers for a resource, grading
 * them instantly against each question's correct_index.
 *
 * ONE ATTEMPT: a GRADED quiz (its score counts toward a Manage Assessment Item)
 * can be submitted once. After that the server refuses every further
 * submission -- refreshing the page, going Back or posting the form again does
 * nothing -- until the teacher allows a retake (aiResourceAllowRetake()). A
 * practice quiz that is not linked to a gradebook item keeps its old behaviour
 * (resubmit any time; the newest attempt replaces the old one).
 *
 * The student's ACTUAL score (e.g. 5/10) is what counts: when the quiz is
 * linked to an assessment item it is written straight to that item and the
 * grade is recomputed (aiQuizSyncGradeForResponse()), and the teacher gets a
 * notification. Ownership is re-validated from scratch here.
 *
 * @param int $resource_id
 * @param int $student_id
 * @param array $answers Raw $_POST-shaped answers, keyed by question position.
 * @return array{success: bool, score?: float, max_score?: float, grade_sync_status?: ?string, already_submitted?: bool, error?: string}
 */
function aiResourceSubmitQuizAnswers($resource_id, $student_id, $answers)
{
    aiResourceEnsureSchema();

    $resource = getRecord(
        "SELECT * FROM ai_generated_resources
         WHERE resource_id = ? AND student_id = ? AND audience = 'student'",
        [(int) $resource_id, (int) $student_id]
    );
    if (!$resource) {
        return ['success' => false, 'error' => 'Resource not found.'];
    }

    $questions = json_decode((string) ($resource['quiz_questions_json'] ?? ''), true);
    if (!is_array($questions) || empty($questions)) {
        return ['success' => false, 'error' => 'This activity has no answerable questions.'];
    }
    if (!is_array($answers)) {
        $answers = [];
    }

    // Every question must have a valid answer before anything is saved or
    // graded (the portal checks this too; this is the server-side guard).
    $missing = [];
    foreach (array_values($questions) as $i => $q) {
        $a = $answers[$i] ?? null;
        $choiceCount = is_array($q['choices'] ?? null) ? count($q['choices']) : 0;
        if ($a === null || $a === '' || !ctype_digit((string) $a) || (int) $a >= $choiceCount) {
            $missing[] = $i + 1;
        }
    }
    if ($missing) {
        return ['success' => false, 'error' => 'Your quiz was not submitted because some questions have no answer (' . implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ', ...' : '') . '). Please answer all questions, then submit again.'];
    }

    // A Teacher AI quiz sent before automatic recording existed is linked now,
    // so it is treated as graded from this very submission.
    if (empty($resource['link_item_id'])) {
        $fresh = aiAssessmentBackfillLink($resource);
        if ($fresh) {
            $resource = $fresh;
        }
    }
    $graded_quiz = aiResourceQuizIsGraded($resource);
    $already = ['success' => false, 'already_submitted' => true,
        'error' => 'You already submitted this quiz. Your score is final unless your teacher allows a retake.'];

    $graded = aiResourceGradeAnswers($questions, $answers);

    $stored_answers = [];
    foreach (array_values($questions) as $i => $q) {
        $stored_answers[] = (isset($answers[$i]) && $answers[$i] !== '' && $answers[$i] !== null) ? (int) $answers[$i] : null;
    }
    $answers_json = json_encode($stored_answers);

    $existing = aiResourceGetSubmission($resource_id, $student_id);
    if ($existing && $graded_quiz && empty($existing['retake_allowed'])) {
        return $already;
    }

    if (!$existing) {
        $ins = executeQuery(
            "INSERT INTO ai_resource_submissions
                (resource_id, student_id, is_auto_graded, answers_json, score, max_score, status, submitted_at)
             VALUES (?, ?, 1, ?, ?, ?, 'Submitted', NOW())",
            [(int) $resource_id, (int) $student_id, $answers_json, $graded['score'], $graded['max_score']]
        );
        if ($ins === false) {
            // Two submits at once: the unique key let only one of them in.
            $existing = aiResourceGetSubmission($resource_id, $student_id);
            if (!$existing) {
                return ['success' => false, 'error' => 'Your answers could not be saved. Please try again.'];
            }
            if ($graded_quiz) {
                return $already;
            }
        }
    }
    if ($existing) {
        // A retake (or a practice quiz): replace the attempt. For a graded quiz
        // the update only lands while the teacher's retake is still unused.
        $upd = executeQuery(
            "UPDATE ai_resource_submissions
             SET is_auto_graded = 1, answers_json = ?, score = ?, max_score = ?, status = 'Submitted',
                 teacher_feedback = NULL, reviewed_at = NULL, submitted_at = NOW(), retake_allowed = 0
             WHERE submission_id = ?" . ($graded_quiz ? " AND retake_allowed = 1" : ""),
            [$answers_json, $graded['score'], $graded['max_score'], (int) $existing['submission_id']]
        );
        if ($upd === false) {
            return ['success' => false, 'error' => 'Your answers could not be saved. Please try again.'];
        }
        if ($graded_quiz && $upd->rowCount() < 1) {
            return $already;
        }
    }

    // ---- The actual score goes straight to Manage Assessment Items and the
    // grade is recomputed. Best-effort: a problem here never loses the
    // student's answers, which are already saved above. ----
    $sync_status = null;
    if (!empty($resource['link_item_id'])) {
        try {
            require_once __DIR__ . '/ai_quiz_grade_sync.php';
            $sync = aiQuizSyncGradeForResponse($resource, (float) $graded['score'], (float) $graded['max_score']);
            $sync_status = $sync['status'] ?? null;
        } catch (\Throwable $e) {
            error_log('aiResourceSubmitQuizAnswers grade sync failed for resource ' . (int) $resource_id . ': ' . $e->getMessage());
        }
    }

    aiResourceNotifyTeacherOfQuiz($resource, (float) $graded['score'], (float) $graded['max_score'], $sync_status);

    return ['success' => true, 'score' => $graded['score'], 'max_score' => $graded['max_score'], 'grade_sync_status' => $sync_status];
}

/** "Student X completed <quiz> - Score: 5/10" in the teacher's notifications. Best-effort. */
function aiResourceNotifyTeacherOfQuiz(array $resource, $score, $max, $sync_status)
{
    if (empty($resource['teacher_id'])) {
        return;
    }
    $teacher = getRecord("SELECT user_id FROM users WHERE user_id = ?", [(int) $resource['teacher_id']]);
    if (!$teacher) {
        return;
    }
    $student = getRecord("SELECT first_name, last_name FROM students WHERE student_id = ?", [(int) $resource['student_id']]);
    $name = $student ? trim($student['first_name'] . ' ' . $student['last_name']) : 'A student';
    $fmt = function ($n) { return rtrim(rtrim(number_format((float) $n, 2), '0'), '.'); };
    $pct = ((float) $max > 0) ? ' (' . $fmt(((float) $score / (float) $max) * 100) . '%)' : '';

    $message = 'Student ' . $name . ' completed ' . $resource['title'] . ' - Score: ' . $fmt($score) . '/' . $fmt($max) . $pct . '.';
    if ($sync_status === 'synced') {
        $message .= ' The score was added to Manage Assessment Items and the grade was updated automatically.';
    } elseif ($sync_status === 'skipped_manual_review') {
        $message .= ' The grade was not changed automatically (Performance Tasks are encoded by the teacher), so please encode this score in Grade Management.';
    } elseif (empty($resource['link_item_id'])) {
        $message .= ' This quiz is not recorded in Manage Assessment Items, so no grade was changed.';
    }

    $link = '';
    if (!empty($resource['link_item_id'])) {
        $item = getRecord("SELECT section_id FROM assessment_items WHERE item_id = ?", [(int) $resource['link_item_id']]);
        if ($item) {
            $link = 'modules/grading/items.php?section_id=' . (int) $item['section_id']
                . '&subject_code=' . urlencode((string) $resource['subject_code'])
                . '&term=' . urlencode((string) ($resource['link_term'] ?? ''));
        }
    }
    executeQuery(
        "INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
        [(int) $resource['teacher_id'], 'Quiz Answered', $message, 'success', $link]
    );
}

/**
 * Save (or resubmit) a student's ANSWERED FILE for a resource with no
 * gradable structure (worksheet/essay/discussion-type) -- e.g. they
 * downloaded the activity, filled it in (or wrote their answers in a photo
 * of their paper), and are attaching that back. An optional short text note
 * can ride along, but the file is the actual submission. Locked once the
 * teacher has marked it Reviewed -- the student can still see what they
 * attached and the teacher's feedback, just can't replace the file out from
 * under an already-checked activity.
 *
 * @param int $resource_id
 * @param int $student_id
 * @param string $note Optional short text note (never required).
 * @param array|null $uploaded_file One element of $_FILES (e.g. $_FILES['answer_file']), or null if the student didn't attach a new file this time (only possible when a file from an earlier submission already exists and they're just updating their note).
 * @return array{success: bool, error?: string}
 */
function aiResourceSubmitManualAnswer($resource_id, $student_id, $note, $uploaded_file)
{
    $note = trim((string) $note);
    if (mb_strlen($note) > 2000) {
        return ['success' => false, 'error' => 'Your note is too long (max 2,000 characters).'];
    }

    $resource = getRecord(
        "SELECT resource_id FROM ai_generated_resources WHERE resource_id = ? AND student_id = ? AND audience = 'student'",
        [(int) $resource_id, (int) $student_id]
    );
    if (!$resource) {
        return ['success' => false, 'error' => 'Resource not found.'];
    }

    $existing = aiResourceGetSubmission($resource_id, $student_id);
    if ($existing && $existing['status'] === 'Reviewed') {
        return ['success' => false, 'error' => 'Your teacher has already checked this activity, so it can no longer be edited.'];
    }

    $has_new_file = $uploaded_file && ($uploaded_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if (!$has_new_file && !$existing) {
        return ['success' => false, 'error' => 'Please attach your answered file.'];
    }

    // Default to whatever was already attached (a note-only resubmission
    // must never wipe out a previously attached file).
    $stored_filename = $existing['answer_stored_filename'] ?? null;
    $original_filename = $existing['answer_original_filename'] ?? null;
    $mime_type = $existing['answer_mime_type'] ?? null;
    $file_size = $existing['answer_file_size'] ?? null;

    if ($has_new_file) {
        if ($uploaded_file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'The file could not be uploaded. Please try again.'];
        }
        if ($uploaded_file['size'] > AI_RESOURCE_ANSWER_MAX_BYTES) {
            return ['success' => false, 'error' => 'That file is too large (max 15 MB).'];
        }
        $ext = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, AI_RESOURCE_ANSWER_ALLOWED_EXT, true)) {
            return ['success' => false, 'error' => 'Please attach a PDF, Word document, or photo (jpg/png) of your answer.'];
        }
        if (!is_dir(AI_RESOURCE_ANSWER_UPLOAD_DIR) && !mkdir(AI_RESOURCE_ANSWER_UPLOAD_DIR, 0755, true) && !is_dir(AI_RESOURCE_ANSWER_UPLOAD_DIR)) {
            return ['success' => false, 'error' => 'Could not save your file. Please try again.'];
        }

        $new_stored_filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = AI_RESOURCE_ANSWER_UPLOAD_DIR . $new_stored_filename;
        if (!move_uploaded_file($uploaded_file['tmp_name'], $dest)) {
            return ['success' => false, 'error' => 'Could not save your file. Please try again.'];
        }

        // Clean up the previous attempt's file now that the new one is
        // safely on disk -- never leave the old one from an earlier
        // resubmission orphaned.
        if (!empty($existing['answer_stored_filename'])) {
            $old_path = AI_RESOURCE_ANSWER_UPLOAD_DIR . $existing['answer_stored_filename'];
            if (is_file($old_path)) {
                @unlink($old_path);
            }
        }

        $stored_filename = $new_stored_filename;
        $original_filename = basename($uploaded_file['name']);
        $mime_type = $uploaded_file['type'] ?: null;
        $file_size = (int) $uploaded_file['size'];
    }

    executeQuery(
        "INSERT INTO ai_resource_submissions
            (resource_id, student_id, is_auto_graded, answers_json,
             answer_stored_filename, answer_original_filename, answer_mime_type, answer_file_size,
             status, submitted_at)
         VALUES (?, ?, 0, ?, ?, ?, ?, ?, 'Submitted', NOW())
         ON DUPLICATE KEY UPDATE
            answers_json = VALUES(answers_json),
            answer_stored_filename = VALUES(answer_stored_filename),
            answer_original_filename = VALUES(answer_original_filename),
            answer_mime_type = VALUES(answer_mime_type),
            answer_file_size = VALUES(answer_file_size),
            status = 'Submitted', submitted_at = NOW()",
        [
            (int) $resource_id, (int) $student_id, json_encode(['note' => $note]),
            $stored_filename, $original_filename, $mime_type, $file_size,
        ]
    );

    return ['success' => true];
}

/**
 * A submission's answered-file row, with enough of its parent resource to
 * authorize either the owning student or the owning teacher -- backs
 * modules/ai/view_submission_answer.php and download_submission_answer.php.
 *
 * @param int $submission_id
 * @return array|null
 */
function aiResourceGetSubmissionFile($submission_id)
{
    return getRecord(
        "SELECT s.submission_id, s.student_id, s.answer_stored_filename, s.answer_original_filename, s.answer_mime_type,
                r.teacher_id
         FROM ai_resource_submissions s
         JOIN ai_generated_resources r ON r.resource_id = s.resource_id
         WHERE s.submission_id = ?",
        [(int) $submission_id]
    );
}

/**
 * Extract plain text from a .docx file using PHP's built-in ZipArchive --
 * no external library or Composer package needed. A .docx is just a ZIP
 * archive; word/document.xml holds the actual text as a run of <w:t>
 * elements. This is intentionally lightweight (drops all formatting,
 * tables become run-together text) -- good enough to hand to Gemini for
 * grading, never meant as a general-purpose document converter.
 *
 * @param string $path Absolute path to the .docx file on disk.
 * @return string|null Plain text, or null if it couldn't be read.
 */
function docxExtractPlainText($path)
{
    if (!is_file($path) || !class_exists('ZipArchive')) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return null;
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        return null;
    }

    // Word marks a paragraph as <w:p ...>...</w:p> and a manual line break
    // as <w:br/> -- turn both into a real newline BEFORE stripping tags,
    // so the extracted text keeps roughly the shape the student actually
    // typed instead of running every paragraph together into one line.
    $xml = str_replace(['<w:p ', '<w:p>'], ["\n<w:p ", "\n<w:p>"], $xml);
    $xml = preg_replace('/<w:br\s*\/?>/', "\n", $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $text = preg_replace("/\n{3,}/", "\n\n", trim($text));

    return $text === '' ? null : $text;
}

/** Which MIME type to tell Gemini this extension is -- never trusts the browser-supplied answer_mime_type column for this. */
function aiResourceAnswerFileGeminiMime($ext)
{
    $map = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'pdf' => 'application/pdf',
    ];
    return $map[$ext] ?? null;
}

/**
 * Run the AI first-pass check on one manual-check submission's attached
 * answer file. Saves the result as an editable DRAFT (status =
 * 'AI-Checked', score/max_score/teacher_feedback populated) -- this NEVER
 * touches the gradebook and is never final; the teacher reviews/edits it
 * in modules/ai/resources.php and only their explicit confirm
 * (aiResourceMarkReviewed()) makes it count.
 *
 * .docx: text is extracted locally (docxExtractPlainText()) and sent to
 * Gemini as plain text. .pdf/.jpg/.jpeg/.png: the raw file is sent to
 * Gemini directly (Gemini reads/OCRs it itself, including handwriting on a
 * photo or a scanned PDF) -- flagged answer_source_type = 'image' so the
 * review page can show an extra "please double-check" notice, since a
 * photo/scan reading is inherently less certain than typed text. Old
 * binary .doc files are not supported (no built-in extraction available)
 * -- the file stays there for the teacher to open and check by hand.
 *
 * @param int $submission_id
 * @param int $teacher_id
 * @return array{success:bool,score?:float,max_score?:float,feedback?:string,source_type?:string,is_confident?:bool,error?:string}
 */
function aiResourceRunAiCheck($submission_id, $teacher_id)
{
    require_once __DIR__ . '/gemini_service.php'; // aiGradeResourceSubmission()

    $row = getRecord(
        "SELECT s.*, r.resource_id, r.teacher_id, r.student_content, r.teacher_answer_key, r.link_item_id
         FROM ai_resource_submissions s
         JOIN ai_generated_resources r ON r.resource_id = s.resource_id
         WHERE s.submission_id = ? AND r.teacher_id = ?",
        [(int) $submission_id, (int) $teacher_id]
    );
    if (!$row) {
        return ['success' => false, 'error' => 'Submission not found.'];
    }
    if ($row['status'] === 'Reviewed') {
        return ['success' => false, 'error' => 'This submission has already been reviewed.'];
    }
    if (empty($row['answer_stored_filename'])) {
        return ['success' => false, 'error' => 'No attached file to check.'];
    }

    $path = AI_RESOURCE_ANSWER_UPLOAD_DIR . $row['answer_stored_filename'];
    if (!is_file($path)) {
        return ['success' => false, 'error' => 'The attached file could not be found on the server.'];
    }
    $ext = strtolower(pathinfo($row['answer_stored_filename'], PATHINFO_EXTENSION));

    // Grade directly on the linked gradebook item's own point value when
    // this resource is linked (database/migrations/2026_ai_quiz_assessment_link.sql)
    // -- so the AI's score already lands on the same scale Grade Management
    // uses for that item. Otherwise, a plain 100-point default; that draft
    // score is for the teacher's reference only until the resource gets
    // linked (see modules/ai/link_resource_item.php).
    $max_score = 100.0;
    if (!empty($row['link_item_id'])) {
        $item = getRecord("SELECT highest_score FROM assessment_items WHERE item_id = ?", [(int) $row['link_item_id']]);
        if ($item && (float) $item['highest_score'] > 0) {
            $max_score = (float) $item['highest_score'];
        }
    }

    $resource_for_grading = [
        'student_content' => $row['student_content'],
        'teacher_answer_key' => $row['teacher_answer_key'],
    ];

    $answer_text = null;
    $answer_file = null;
    $source_type = null;

    if ($ext === 'docx') {
        $answer_text = docxExtractPlainText($path);
        if ($answer_text === null || trim($answer_text) === '') {
            return ['success' => false, 'error' => 'Could not read any text from that Word document. Please check it manually.'];
        }
        $source_type = 'text';
    } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true)) {
        $mime = aiResourceAnswerFileGeminiMime($ext);
        $bytes = file_get_contents($path);
        if ($bytes === false || $mime === null) {
            return ['success' => false, 'error' => 'Could not read the attached file.'];
        }
        $answer_file = ['bytes' => $bytes, 'mime_type' => $mime];
        $source_type = 'image';
    } else {
        return ['success' => false, 'error' => 'AI checking isn\'t available for old .doc files yet — please open and check this one yourself.'];
    }

    $result = aiGradeResourceSubmission($resource_for_grading, $max_score, $answer_text, $answer_file);
    if (!$result['success']) {
        return $result;
    }

    executeQuery(
        "UPDATE ai_resource_submissions
         SET score = ?, max_score = ?, teacher_feedback = ?, status = 'AI-Checked',
             answer_source_type = ?, ai_checked_at = NOW()
         WHERE submission_id = ?",
        [$result['score'], $result['max_score'], $result['feedback'], $source_type, (int) $submission_id]
    );

    return [
        'success' => true,
        'score' => $result['score'],
        'max_score' => $result['max_score'],
        'feedback' => $result['feedback'],
        'source_type' => $source_type,
        'is_confident' => $result['is_confident'],
    ];
}

/**
 * Mark one submission Reviewed -- the teacher's final, explicit
 * confirmation of a score (whether typed by hand, or edited from an
 * AI-suggested draft) and optional feedback. Ownership is re-checked here
 * (the submission's resource must belong to THIS teacher) rather than
 * trusting the caller already filtered the list correctly.
 *
 * When the resource is linked to a real gradebook item (link_item_id --
 * see database/migrations/2026_ai_quiz_assessment_link.sql), a non-null
 * score/max_score is ALSO synced into Grade Management the exact same way
 * an auto-graded Google Form quiz score already is (see
 * includes/ai_quiz_grade_sync.php's aiQuizSyncGradeForResponse(), reused
 * as-is here) -- this is what actually clears the activity from Missing
 * Assessments, and only once it's genuinely been checked and scored, never
 * just because a file was attached.
 *
 * @param int $submission_id
 * @param int $teacher_id
 * @param string $feedback
 * @param float|null $score Final score, or null to leave this activity unscored (feedback-only review).
 * @param float|null $max_score Required together with $score.
 * @return array{success:bool,error?:string,grade_sync_status?:string|null}
 */
function aiResourceMarkReviewed($submission_id, $teacher_id, $feedback = '', $score = null, $max_score = null)
{
    $feedback = trim((string) $feedback);

    // r.* covers every field aiQuizSyncGradeForResponse() needs
    // (student_id, teacher_id, subject_code, school_year_id, link_category,
    // link_term, link_item_id, resource_id) in one query -- no separate
    // re-fetch before the sync call below.
    $row = getRecord(
        "SELECT s.submission_id, r.* FROM ai_resource_submissions s
         JOIN ai_generated_resources r ON r.resource_id = s.resource_id
         WHERE s.submission_id = ? AND r.teacher_id = ?",
        [(int) $submission_id, (int) $teacher_id]
    );
    if (!$row) {
        return ['success' => false, 'error' => 'Submission not found.'];
    }

    $has_score = $score !== null && $max_score !== null && (float) $max_score > 0;

    executeQuery(
        "UPDATE ai_resource_submissions SET status = 'Reviewed', teacher_feedback = ?, score = ?, max_score = ?, reviewed_at = NOW() WHERE submission_id = ?",
        [
            $feedback !== '' ? $feedback : null,
            $has_score ? round((float) $score, 2) : null,
            $has_score ? round((float) $max_score, 2) : null,
            (int) $submission_id,
        ]
    );

    $grade_sync_status = null;
    if ($has_score && !empty($row['link_item_id'])) {
        require_once __DIR__ . '/ai_quiz_grade_sync.php';
        // Pass $max_score as the "form's own" total every time (even when
        // it already equals the linked item's highest_score) so
        // aiQuizSyncGradeForResponse() always proportionally scales onto
        // that item's real point value -- correct whether or not the
        // teacher edited the score/total away from what the AI suggested.
        $sync = aiQuizSyncGradeForResponse($row, (float) $score, (float) $max_score);
        $grade_sync_status = $sync['status'] ?? null;
    }

    return ['success' => true, 'grade_sync_status' => $grade_sync_status];
}

/**
 * Every submission for resources this teacher owns, newest first -- backs
 * modules/ai/resources.php. Denormalizes the student's name/LRN and the
 * resource's title/type so the review page needs exactly one query.
 *
 * @param int $teacher_id
 * @param bool $only_pending When true, only manual-check submissions still waiting on a teacher's final confirm -- 'Submitted' (never checked) or 'AI-Checked' (AI left a draft) -- never 'Reviewed'. Auto-graded ones never need this to see their score.
 * @return array
 */
function aiResourceGetTeacherSubmissions($teacher_id, $only_pending = false)
{
    $sql = "SELECT s.*, r.title AS resource_title, r.resource_type, r.subject_code, r.output_format,
                   r.quiz_questions_json, r.quiz_max_score, r.quiz_one_attempt, r.link_item_id, r.link_category, r.link_term,
                   st.first_name, st.last_name, st.lrn
            FROM ai_resource_submissions s
            JOIN ai_generated_resources r ON r.resource_id = s.resource_id
            JOIN students st ON st.student_id = s.student_id
            WHERE r.teacher_id = ?";
    if ($only_pending) {
        $sql .= " AND s.is_auto_graded = 0 AND s.status IN ('Submitted', 'AI-Checked')";
    }
    $sql .= " ORDER BY s.submitted_at DESC";

    return getRecords($sql, [(int) $teacher_id]);
}

/**
 * Pending (not yet Reviewed) manual-check submissions for ONE student in
 * ONE subject, scoped to this teacher -- backs the "Ask AI" chat's own
 * check-and-record flow (modules/ai/index.php, via
 * modules/ai/list_pending_submissions.php), so a teacher already looking at
 * one at-risk student+subject in that chat sees a submitted activity
 * waiting to be checked right there, instead of only finding out on the
 * separate Student Submissions page (modules/ai/resources.php). Same
 * ownership/status rules as aiResourceGetTeacherSubmissions($teacher_id,
 * true) -- just additionally scoped to one student+subject.
 *
 * @param int $teacher_id
 * @param int $student_id
 * @param string $subject_code
 * @return array
 */
function aiResourceGetPendingSubmissionsForSubject($teacher_id, $student_id, $subject_code)
{
    return getRecords(
        "SELECT s.*, r.title AS resource_title, r.resource_type, r.subject_code, r.output_format,
                r.resource_id, r.link_item_id, r.link_category, r.link_term
         FROM ai_resource_submissions s
         JOIN ai_generated_resources r ON r.resource_id = s.resource_id
         WHERE r.teacher_id = ? AND s.student_id = ? AND r.subject_code = ?
           AND s.is_auto_graded = 0 AND s.status IN ('Submitted', 'AI-Checked')
         ORDER BY s.submitted_at DESC",
        [(int) $teacher_id, (int) $student_id, trim((string) $subject_code)]
    );
}
