-- ============================================================
-- 2026_ai_resource_submissions.sql
-- SAFE, ADDITIVE migration that lets a student answer an AI-generated
-- PDF/DOCX resource (ai_generated_resources — see
-- 2026_ai_generated_resources.sql) directly inside the student portal
-- (modules/students/portal/messages.php), instead of only being able to
-- View/Download it with no way to send an answer back.
--
-- Two kinds of submission, distinguished by is_auto_graded:
--   - Multiple-choice / true-false resources already carry a structured
--     quiz_questions_json + quiz_max_score on ai_generated_resources (added
--     by 2026_ai_quiz_gform.sql for the Google Form path, but populated for
--     ANY gform-eligible resource_type regardless of output_format). When
--     present, the student answers right on the page and the score is
--     computed instantly against each question's correct_index — no
--     Google Form, no file round-trip needed.
--   - Anything else (worksheets, essays, discussion/identification
--     activities with no single correct answer) has no structure to grade
--     against. The student instead downloads the activity, answers it in
--     their own copy (editing the Word doc, writing on a printed/annotated
--     PDF, etc.), and ATTACHES that answered file back here -- this table
--     only ever RECORDS the file (plus an optional short note), it never
--     invents a score for it. See answer_stored_filename etc. below.
--
-- This is DELIBERATELY separate from ai_quiz_gform_responses (which is
-- Google-Forms-specific: google_response_id, synced via a webhook). This
-- table is the native, in-app equivalent for resources that were never
-- turned into a Google Form.
--
-- Nothing existing is dropped, altered, or renamed. Adds:
--   - ai_resource_submissions   the new table (see column comments)
--
-- Safe to run on the live database at any time; safe to re-run (uses
-- CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS, matching this
-- project's other migrations -- including on a database where an earlier,
-- text-only-answer version of this same table already ran: the ALTER block
-- below adds the answer_* file columns to it without touching a single
-- existing row).
--
-- Run against the `egradepro` database, e.g.:
--   mysql -u root egradepro < database/migrations/2026_ai_resource_submissions.sql
-- or import via phpMyAdmin.
-- ============================================================

CREATE TABLE IF NOT EXISTS `ai_resource_submissions` (
  `submission_id` int(11) NOT NULL AUTO_INCREMENT,
  `resource_id` int(11) NOT NULL COMMENT 'Which ai_generated_resources row this answer is for.',
  `student_id` int(11) NOT NULL COMMENT 'Denormalized from ai_generated_resources.student_id for fast/direct lookups and an extra authorization check in submit_resource_answer.php.',
  `is_auto_graded` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = this resource had quiz_questions_json (multiple-choice/true-false) and score/max_score below were computed instantly against each question''s correct_index. 0 = free-response answer with no single correct answer; score/max_score stay NULL and a teacher must check it manually.',
  `answers_json` mediumtext NOT NULL COMMENT 'Auto-graded: JSON array of the student''s chosen option index per question, same order as quiz_questions_json, e.g. [1,0,2]. Manual-check: JSON object {"note":"..."} holding an optional short text note the student typed alongside their attached file.',
  `answer_stored_filename` varchar(255) DEFAULT NULL COMMENT 'Manual-check only: the student''s answered file, generated/unguessable name under static/uploads/ai_resource_answers/ -- NEVER the student''s own filename. Mirrors ai_generated_resources.stored_filename''s convention.',
  `answer_original_filename` varchar(255) DEFAULT NULL COMMENT 'Safe, display-only file name shown to the teacher (e.g. aktibidad2_juan.docx). Never used as a filesystem path.',
  `answer_mime_type` varchar(100) DEFAULT NULL,
  `answer_file_size` int(11) DEFAULT NULL COMMENT 'Bytes, for display.',
  `score` decimal(6,2) DEFAULT NULL COMMENT 'Auto-graded points earned. Always NULL for a manual-check (is_auto_graded=0) submission -- this table never fabricates a score for free-response work.',
  `max_score` decimal(6,2) DEFAULT NULL COMMENT 'Total possible points at submission time (mirrors ai_generated_resources.quiz_max_score). NULL for a manual-check submission.',
  `status` enum('Submitted','Reviewed') NOT NULL DEFAULT 'Submitted' COMMENT 'Submitted = just came in. Reviewed = the teacher has looked at it (required before a manual-check student sees any feedback; for an auto-graded submission this is informational only, since the score is already final the moment it is computed).',
  `teacher_feedback` text DEFAULT NULL COMMENT 'Optional note the teacher leaves when marking a submission Reviewed -- shown back to the student on their Messages page.',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Updated to NOW() on every resubmission (see the resubmit rule in modules/students/portal/submit_resource_answer.php).',
  `reviewed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`submission_id`),
  UNIQUE KEY `uq_resource_submission` (`resource_id`, `student_id`) COMMENT 'One row per student per resource -- resubmitting UPDATEs this same row (INSERT ... ON DUPLICATE KEY UPDATE) instead of creating a duplicate.',
  KEY `idx_resource_submissions_student` (`student_id`),
  KEY `idx_resource_submissions_status` (`status`),
  CONSTRAINT `fk_resource_submissions_resource` FOREIGN KEY (`resource_id`) REFERENCES `ai_generated_resources` (`resource_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resource_submissions_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Defensive: adds the answer_* file columns to a copy of this table that
-- was already created by an earlier, text-only-answer run of this exact
-- file. A no-op (every column already exists) on a database seeing this
-- file for the first time, since CREATE TABLE above already included them.
ALTER TABLE `ai_resource_submissions`
  ADD COLUMN IF NOT EXISTS `answer_stored_filename` varchar(255) DEFAULT NULL COMMENT 'Manual-check only: the student''s answered file, generated/unguessable name under static/uploads/ai_resource_answers/ -- NEVER the student''s own filename. Mirrors ai_generated_resources.stored_filename''s convention.' AFTER `answers_json`,
  ADD COLUMN IF NOT EXISTS `answer_original_filename` varchar(255) DEFAULT NULL COMMENT 'Safe, display-only file name shown to the teacher (e.g. aktibidad2_juan.docx). Never used as a filesystem path.' AFTER `answer_stored_filename`,
  ADD COLUMN IF NOT EXISTS `answer_mime_type` varchar(100) DEFAULT NULL AFTER `answer_original_filename`,
  ADD COLUMN IF NOT EXISTS `answer_file_size` int(11) DEFAULT NULL COMMENT 'Bytes, for display.' AFTER `answer_mime_type`;
