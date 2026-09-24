<?php
// includes/ai_quiz_gform_helpers.php
//
// DB-aware layer on top of includes/google_forms_service.php (which only
// talks to Google, with no idea this app's schema exists). This file is
// where "check a quiz Form for new submissions and remember them" actually
// happens — called lazily from teacher-facing pages (the Ask AI chat, and
// the student's own Messages/resource view) rather than on any schedule,
// since this app has no cron/Task Scheduler dependency to set up. Safe to
// call repeatedly — same pattern as syncTutorialPlanItems() in
// includes/tutorial_helpers.php.
//
// A quiz Form is always created for exactly ONE student (the same
// one-teacher/one-student/one-subject scope every other part of the Ask AI
// feature already enforces) — its link is only ever handed to that one
// student via Send to Student, so any response Google returns for that
// Form belongs to that resource's own student_id by construction. If a
// student submits more than once, every attempt is recorded as its own
// row (google_response_id is unique per submission) — nothing here tries
// to guess a "final" answer; the teacher sees every attempt with its score
// and timestamp.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/google_forms_service.php';
require_once __DIR__ . '/ai_quiz_grade_sync.php'; // aiQuizSyncGradeForResponse() — syncs each new response into Grade Management

/**
 * Check ONE quiz resource's Google Form for submissions not yet recorded,
 * insert any new ones into ai_quiz_gform_responses, and create a teacher
 * notification for each. Silent no-op (returns 0) if this resource isn't
 * a gform quiz, or if Google Forms isn't configured yet — callers should
 * treat this as best-effort and never let it block page rendering.
 *
 * @param int $resource_id
 * @return int|false Number of NEW responses recorded, or false on a hard error.
 */
function aiQuizSyncFormResponses($resource_id)
{
    $resource_id = (int) $resource_id;
    if ($resource_id <= 0 || !googleFormsIsConfigured()) {
        return 0;
    }

    $resource = getRecord(
        "SELECT resource_id, teacher_id, student_id, subject_code, school_year_id, title, output_format,
                google_form_id, quiz_one_attempt, quiz_closed_at, link_category, link_term, link_item_id
         FROM ai_generated_resources WHERE resource_id = ? AND output_format = 'gform'",
        [$resource_id]
    );
    if (!$resource || empty($resource['google_form_id'])) {
        return 0;
    }

    // A Form the Teacher AI sent to a whole class is ONE Google Form whose
    // link sits on several students' ai_generated_resources rows. Google's
    // response list cannot say which student submitted, so recording those
    // responses against this row's student_id would credit one student with a
    // classmate's score (and sync it into the gradebook). Skip it: the quiz
    // itself and its link keep working, and the teacher reviews that Form's
    // results in Google Forms. Single-student quizzes (the original Ask AI
    // flow) have exactly one row per form and are unaffected.
    $sharedForm = getRecord(
        "SELECT COUNT(DISTINCT student_id) AS students FROM ai_generated_resources WHERE google_form_id = ?",
        [$resource['google_form_id']]
    );
    if ($sharedForm && (int) $sharedForm['students'] > 1) {
        return 0;
    }

    $known = getRecords(
        "SELECT google_response_id FROM ai_quiz_gform_responses WHERE resource_id = ?",
        [$resource_id]
    );
    $known_ids = array_column($known, 'google_response_id');

    $result = googleFormsListNewResponses($resource['google_form_id'], $known_ids);
    if (!$result['success']) {
        error_log('aiQuizSyncFormResponses: ' . $result['error']);
        return false;
    }

    $new_count = 0;
    foreach ($result['responses'] as $r) {
        $inserted = insertRecord(
            "INSERT INTO ai_quiz_gform_responses
                (resource_id, student_id, google_response_id, score, max_score, answers_json, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $resource_id,
                $resource['student_id'],
                $r['google_response_id'],
                $r['score'],
                (float) getResourceQuizMaxScore($resource_id),
                $r['answers_json'],
                $r['submitted_at'],
            ]
        );

        if ($inserted) {
            $new_count++;

            // ---- Full pipeline for THIS new submission: Score Retrieved
            // (above) -> Score Added to Assessment Item -> Grade Recomputed
            // -> At-Risk Status Recomputed. Reuses the exact grading
            // computation already used everywhere else in the app (see
            // includes/ai_quiz_grade_sync.php) — never a reimplementation,
            // and never a manual "Passed" override. Best-effort: a sync
            // problem here must not stop the response itself from being
            // recorded or the teacher from being notified. ----
            $max = getResourceQuizMaxScore($resource_id);
            $sync = aiQuizSyncGradeForResponse($resource, (float) $r['score'], (float) $max);

            $score_text = $max > 0 ? ($r['score'] . '/' . $max) : (string) $r['score'];
            $pct_text = ($max > 0) ? ' (' . round(((float) $r['score'] / $max) * 100, 1) . '%)' : '';

            $teacher_user = getRecord("SELECT user_id FROM users WHERE user_id = ?", [$resource['teacher_id']]);
            if ($teacher_user) {
                $student = getRecord("SELECT first_name, last_name FROM students WHERE student_id = ?", [$resource['student_id']]);
                $student_name = $student ? trim($student['first_name'] . ' ' . $student['last_name']) : 'A student';

                // Matches the requested wording, e.g.:
                // "Student Juan Dela Cruz completed AP8 Quiz — Score: 16/20."
                $message = 'Student ' . $student_name . ' completed ' . $resource['title'] . ' — Score: ' . $score_text . $pct_text . '.';
                if (($sync['status'] ?? '') === 'synced') {
                    $message .= ' Grade and at-risk status have been updated automatically.';
                } elseif (($sync['status'] ?? '') === 'skipped_manual_review') {
                    $message .= ' The score was recorded in Manage Items, but this subject needs the Term Grade finished manually in Grade Management.';
                }

                // Link straight to the Assessment Item this score just fed —
                // falls back to no link if it can't be resolved (e.g. an
                // older quiz created before this feature existed).
                $link = '';
                if (!empty($resource['link_item_id'])) {
                    $item_row = getRecord("SELECT section_id FROM assessment_items WHERE item_id = ?", [(int) $resource['link_item_id']]);
                    if ($item_row) {
                        $link = 'modules/grading/items.php?section_id=' . (int) $item_row['section_id']
                            . '&subject_code=' . urlencode($resource['subject_code'])
                            . '&term=' . urlencode((string) $resource['link_term']);
                    }
                }

                aiQuizNotifyTeacher(
                    $teacher_user['user_id'],
                    'Quiz Answered',
                    $message,
                    'success',
                    $link
                );
            }
        }
    }

    // ---- "One attempt only": the teacher opted in when creating this
    // quiz. The FIRST response just recorded above is exactly the moment
    // to actually close the Form at the source — Google itself will then
    // refuse any further submission, rather than this app merely hiding a
    // link in the UI. Only ever done once (quiz_closed_at guards it) so a
    // second sync call never re-issues the same Google API call. ----
    if ($new_count > 0 && (int) $resource['quiz_one_attempt'] === 1 && empty($resource['quiz_closed_at'])) {
        $close = googleFormsSetAcceptingResponses($resource['google_form_id'], false);
        if ($close['success']) {
            executeQuery(
                "UPDATE ai_generated_resources SET quiz_closed_at = NOW() WHERE resource_id = ?",
                [$resource_id]
            );
        } else {
            // Not fatal — the response was already recorded and the
            // teacher already notified; the Form just stays open for now
            // and a later sync call will try closing it again.
            error_log('aiQuizSyncFormResponses: could not close one-attempt Form for resource ' . $resource_id . ': ' . ($close['error'] ?? 'unknown'));
        }
    }

    return $new_count;
}

/**
 * Notify the teacher. The Student Portal runs this sync (when a student opens
 * their subject page) WITHOUT includes/auth.php, where createNotification()
 * lives -- calling it there ended the page with a fatal error right after the
 * score was saved, before a one-attempt form was closed. Use it when it is
 * loaded, otherwise write the same notifications row directly.
 */
function aiQuizNotifyTeacher($user_id, $title, $message, $type = 'info', $link = '')
{
    if (function_exists('createNotification')) {
        return createNotification($user_id, $title, $message, $type, $link);
    }
    return executeQuery(
        "INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
        [(int) $user_id, $title, $message, $type, $link]
    ) !== false;
}

/** Small helper so both spots above stay consistent — reads quiz_max_score once. */
function getResourceQuizMaxScore($resource_id)
{
    static $cache = [];
    if (!isset($cache[$resource_id])) {
        $row = getRecord("SELECT quiz_max_score FROM ai_generated_resources WHERE resource_id = ?", [(int) $resource_id]);
        $cache[$resource_id] = $row ? (int) $row['quiz_max_score'] : 0;
    }
    return $cache[$resource_id];
}

/**
 * All recorded submissions for a quiz resource, newest first — for the
 * teacher-facing "Quiz Answered" summary in the Ask AI chat / resource view.
 *
 * @param int $resource_id
 * @return array
 */
function aiQuizGetResponses($resource_id)
{
    return getRecords(
        "SELECT * FROM ai_quiz_gform_responses WHERE resource_id = ? ORDER BY received_at DESC",
        [(int) $resource_id]
    );
}
