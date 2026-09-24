-- ============================================================
-- 2026_ai_quiz_one_attempt.sql
-- SAFE, ADDITIVE migration on top of 2026_ai_quiz_gform.sql (run that one
-- first). Adds an optional "only one attempt" control for a Google Form
-- quiz, per the teacher's own choice when they click "Create Google Form
-- Quiz" in the Ask AI chat.
--
-- Nothing existing is dropped, renamed, or reinterpreted:
--   - quiz_one_attempt: 0 (default) = unlimited attempts, exactly as the
--     feature behaved before this migration. 1 = the teacher opted in;
--     once the FIRST response is recorded (includes/ai_quiz_gform_helpers.php
--     -> aiQuizSyncFormResponses()), the app calls Google's
--     forms.setPublishSettings API to actually close the Form to further
--     responses (googleFormsSetAcceptingResponses() in
--     includes/google_forms_service.php) -- this is enforced at the source
--     (Google itself refuses a second submission), not just hidden in the UI.
--   - quiz_closed_at: NULL until the app has closed the Form for this
--     reason; sets once and is never re-checked/re-closed again -- avoids
--     calling the Google API a second time for the same quiz.
--
-- Safe to run on the live database at any time; safe to re-run (uses
-- ADD COLUMN IF NOT EXISTS, matching this project's other migrations).
--
-- Run against the `egradepro` database, e.g.:
--   mysql -u root egradepro < database/migrations/2026_ai_quiz_one_attempt.sql
-- or import via phpMyAdmin.
-- ============================================================

ALTER TABLE `ai_generated_resources`
  ADD COLUMN IF NOT EXISTS `quiz_one_attempt` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Teacher opted in at Create Google Form Quiz time: 1 = close the Form to further responses after the first submission (see aiQuizSyncFormResponses()). 0 = unlimited attempts (original behavior).' AFTER `quiz_max_score`,
  ADD COLUMN IF NOT EXISTS `quiz_closed_at` timestamp NULL DEFAULT NULL COMMENT 'When the app called Google to close this Form after its first response, for a one-attempt quiz. NULL if not applicable or not yet closed.' AFTER `quiz_one_attempt`;
