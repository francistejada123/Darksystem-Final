-- ============================================================
-- 2026_ai_quiz_gform.sql
-- SAFE, ADDITIVE migration for the "Generate a quiz -> Google Form"
-- feature on top of the existing Ask AI chatbot (includes/gemini_service.php,
-- modules/ai/chatbot.php, includes/ai_document_generator.php,
-- ai_generated_resources -- see 2026_ai_generated_resources.sql).
--
-- Nothing existing is dropped, renamed, or reinterpreted:
--   - ai_generated_resources.output_format WIDENED from enum('pdf','docx')
--     to enum('pdf','docx','gform'). Every existing row is 'pdf', 'docx',
--     or NULL -- all three remain valid, unchanged meaning. A 'gform' row
--     never has a stored_filename/original_filename on disk; instead it
--     carries a Google Form id + links in the new columns below.
--   - New nullable columns on ai_generated_resources carry the Google Form
--     itself (google_form_id / edit link / student-facing fill-out link),
--     the structured quiz question set used to build it (quiz_questions_json
--     -- {question, choices[], correct_index, points} per item, produced by
--     a NEW, quiz-specific Gemini response schema -- see
--     includes/gemini_service.php), its total possible score, and a
--     per-form webhook_secret so the Apps Script attached to THIS form
--     alone can authenticate its score callbacks (leaking one form's
--     secret never exposes another teacher's quiz data).
--   - ai_quiz_gform_responses is a brand-new table holding each student's
--     auto-graded submission, pushed here the instant they submit the
--     Google Form (via an Apps Script onFormSubmit trigger installed on
--     that specific form -- see includes/google_forms_service.php and
--     modules/ai/gform_webhook.php). A unique key on
--     (resource_id, google_response_id) makes the webhook idempotent, so a
--     retried Apps Script delivery can never double-count a score.
--
-- Safe to run on the live database at any time; safe to re-run (uses
-- ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS, matching this
-- project's other migrations).
--
-- Run against the `egradepro` database, e.g.:
--   mysql -u root egradepro < database/migrations/2026_ai_quiz_gform.sql
-- or import via phpMyAdmin.
-- ============================================================

ALTER TABLE `ai_generated_resources`
  MODIFY COLUMN `output_format` enum('pdf','docx','gform') DEFAULT NULL COMMENT 'NULL until this resource has actually been produced in final form (chat-only / content-preview draft). pdf/docx = a generated file on disk under static/uploads/ai_resources/. gform = a Google Form was created for this quiz -- no local file, see google_form_id and the google_form_*_url columns instead.';

ALTER TABLE `ai_generated_resources`
  ADD COLUMN IF NOT EXISTS `google_form_id` varchar(64) DEFAULT NULL COMMENT 'The Google Form''s id (forms.body.create response formId). Set only when output_format = gform.' AFTER `output_format`,
  ADD COLUMN IF NOT EXISTS `google_form_edit_url` varchar(500) DEFAULT NULL COMMENT 'Teacher-facing Google Forms editor link (opens in the shared Google account) -- never shown to students.' AFTER `google_form_id`,
  ADD COLUMN IF NOT EXISTS `google_form_view_url` varchar(500) DEFAULT NULL COMMENT 'Student-facing fill-out link (the actual quiz). This is what gets delivered via Send to Student instead of a file attachment.' AFTER `google_form_edit_url`,
  ADD COLUMN IF NOT EXISTS `quiz_questions_json` mediumtext DEFAULT NULL COMMENT 'The structured quiz Gemini produced for this resource -- a JSON array of {question, choices[], correct_index, points} -- used to build the Google Form and kept here so the answer key never has to be re-derived from free text.' AFTER `google_form_view_url`,
  ADD COLUMN IF NOT EXISTS `quiz_max_score` int(11) DEFAULT NULL COMMENT 'Sum of every question''s points -- the denominator for a student''s score.' AFTER `quiz_questions_json`,
  ADD COLUMN IF NOT EXISTS `webhook_secret` varchar(64) DEFAULT NULL COMMENT 'Random per-form secret embedded in that form''s own Apps Script trigger and required on every gform_webhook.php callback for this resource_id -- scopes a leaked secret to a single quiz, never the whole system.' AFTER `quiz_max_score`;

CREATE TABLE IF NOT EXISTS `ai_quiz_gform_responses` (
  `response_id` int(11) NOT NULL AUTO_INCREMENT,
  `resource_id` int(11) NOT NULL COMMENT 'Which ai_generated_resources (gform) quiz this submission is for.',
  `student_id` int(11) NOT NULL COMMENT 'Denormalized from ai_generated_resources.student_id for fast/direct lookups and an extra authorization check in gform_webhook.php.',
  `google_response_id` varchar(128) NOT NULL COMMENT 'The Google Form response''s own id -- makes a retried webhook delivery a no-op instead of a duplicate score.',
  `score` decimal(6,2) NOT NULL COMMENT 'Auto-graded score Google Forms computed for this submission.',
  `max_score` decimal(6,2) NOT NULL COMMENT 'Total possible points at submission time (mirrors quiz_max_score, kept per-row in case a form is edited later).',
  `answers_json` mediumtext DEFAULT NULL COMMENT 'Per-question submitted answers, teacher-facing only, for review -- never required for grading (Google Forms already graded it).',
  `submitted_at` timestamp NULL DEFAULT NULL COMMENT 'When the student actually submitted, per Google.',
  `received_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'When our webhook received this callback.',
  `teacher_notified_at` timestamp NULL DEFAULT NULL COMMENT 'Set once a teacher-facing notification for this score has been created.',
  PRIMARY KEY (`response_id`),
  UNIQUE KEY `uq_quiz_response` (`resource_id`, `google_response_id`),
  KEY `idx_quiz_responses_student` (`student_id`),
  CONSTRAINT `fk_quiz_responses_resource` FOREIGN KEY (`resource_id`) REFERENCES `ai_generated_resources` (`resource_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_quiz_responses_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
