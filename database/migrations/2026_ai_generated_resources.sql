-- ============================================================
-- 2026_ai_generated_resources.sql
-- SAFE, ADDITIVE migration for the "Ask AI" chat upgrade: real PDF/DOCX
-- academic-resource generation + Send to Student attachments.
--
-- Still the SAME Ask AI / Gemini architecture (includes/gemini_service.php
-- + modules/ai/chatbot.php), now able to produce an actual generated
-- document (quiz, reviewer, worksheet, performance task, etc.) instead of
-- only a chat reply, and to attach that document to an existing
-- student_support_messages row when the teacher explicitly clicks
-- "Send to Student".
--
-- This is DELIBERATELY a separate table from the existing per-student
-- ai_reviewers / ai_practice_quizzes (2026_ai_reviewer_quiz.sql) and the
-- class-level ai_class_content (2026_ai_grading_assistant.sql) tables —
-- those belong to a different, still-unwired planning feature for
-- modules/grading/items.php and are completely untouched here. This table
-- is generic across every supported resource type (quiz, reviewer,
-- worksheet, performance task, rubric, study guide, flashcards, etc.) and
-- always carries BOTH a student-safe version and an optional
-- teacher-only version (e.g. an answer key), plus the physical generated
-- file's metadata when one exists.
--
-- Nothing existing is dropped, altered, or renamed. Adds:
--   - ai_generated_resources          the new table (see column comments)
--   - student_support_messages.resource_id   nullable FK column so an
--     existing "Send to Student" message can optionally carry ONE
--     attachment. Every existing row gets resource_id = NULL, matching
--     today's text-only behavior exactly.
--
-- Safe to run on the live database at any time; safe to re-run (uses
-- IF NOT EXISTS / ADD COLUMN IF NOT EXISTS, matching this project's other
-- migrations, e.g. 2026_tutorial_pdf_resources.sql).
--
-- Run against the `egradepro` database, e.g.:
--   mysql -u root egradepro < database/migrations/2026_ai_generated_resources.sql
-- or import via phpMyAdmin.
-- ============================================================

CREATE TABLE IF NOT EXISTS `ai_generated_resources` (
  `resource_id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `school_year_id` int(11) DEFAULT NULL,
  `resource_type` varchar(40) NOT NULL COMMENT 'One of the app-level AI_RESOURCE_TYPES keys (quiz, reviewer, worksheet, performance_task, rubric, study_guide, flashcards, ...). Chosen by Gemini from a fixed schema enum, not by PHP keyword matching -- validated server-side against AI_RESOURCE_TYPES before insert.',
  `title` varchar(150) NOT NULL DEFAULT '',
  `source_prompt` varchar(500) DEFAULT NULL COMMENT 'The teacher''s own request that produced this version, truncated. Context only, never displayed to the student.',
  `student_content` mediumtext NOT NULL COMMENT 'The student-safe version: questions/activity/instructions. Never includes the failing grade, "at-risk", or the correct-answer key. This is the ONLY content ever shown or sent to the student.',
  `teacher_answer_key` mediumtext DEFAULT NULL COMMENT 'Correct answers / rubric notes / explanations, teacher-facing only. Never sent to send_to_student.php, never streamed by view/download to a student session.',
  `audience` enum('student','teacher') NOT NULL DEFAULT 'student' COMMENT 'Which content this particular generated FILE was rendered from. A teacher-audience file (an answer-key copy the teacher explicitly generated for themselves) can never be attached to a student message and is never streamed to a student session, regardless of who requests it.',
  `output_format` enum('pdf','docx') DEFAULT NULL COMMENT 'NULL until a physical file has actually been generated for this row (chat-only / content-preview draft). Set the moment generate_resource.php successfully writes a file.',
  `stored_filename` varchar(255) DEFAULT NULL COMMENT 'Generated, unguessable filename under static/uploads/ai_resources/ -- NEVER the teacher''s prompt or the display title. Mirrors the convention already used for tutorial_plan_items.resource_file.',
  `original_filename` varchar(255) DEFAULT NULL COMMENT 'Safe, display-only file name shown in the file card / download prompt (e.g. ap8_remedial_quiz.pdf). Never used as a filesystem path.',
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL COMMENT 'Bytes, for display.',
  `status` enum('Draft','Generated','Sent') NOT NULL DEFAULT 'Draft' COMMENT 'Draft = content exists, no file yet. Generated = a PDF/DOCX file exists on disk. Sent = attached to a student_support_messages row that has actually been sent. Generating a file never implies Sent -- only send_to_student.php sets that.',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `generated_at` timestamp NULL DEFAULT NULL COMMENT 'When the physical file was created.',
  `sent_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`resource_id`),
  KEY `idx_ai_resources_teacher` (`teacher_id`),
  KEY `idx_ai_resources_student` (`student_id`),
  KEY `idx_ai_resources_subject` (`subject_code`),
  KEY `idx_ai_resources_status` (`status`),
  CONSTRAINT `fk_ai_resources_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_resources_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Optional single attachment on an existing "Send to Student" message.
-- NULL on every pre-existing row -- the current text-only behavior is
-- completely unaffected.
ALTER TABLE `student_support_messages`
  ADD COLUMN IF NOT EXISTS `resource_id` int(11) DEFAULT NULL COMMENT 'Optional generated-resource attachment (ai_generated_resources.resource_id). NULL means a plain text message, exactly like every message before this column existed.' AFTER `final_message`;

-- Guard the FK add with a check so re-running this file (or running it on
-- a database where a previous partial run already added the column) never
-- errors. MySQL/MariaDB have no ADD CONSTRAINT IF NOT EXISTS, so this uses
-- an information_schema check + prepared statement, same pattern-safe
-- approach as the rest of this migration set relies on IF NOT EXISTS for.
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'student_support_messages'
    AND CONSTRAINT_NAME = 'fk_support_messages_resource'
);
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE `student_support_messages` ADD CONSTRAINT `fk_support_messages_resource` FOREIGN KEY (`resource_id`) REFERENCES `ai_generated_resources` (`resource_id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
