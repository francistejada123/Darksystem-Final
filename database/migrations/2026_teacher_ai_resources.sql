-- ============================================================
-- 2026_teacher_ai_resources.sql
-- SAFE, ADDITIVE migration for E-GRADEPRO Teacher AI: generate a lesson, quiz,
-- activity, assessment or learning material, create it as PDF / Word /
-- PowerPoint / Google Form, then send it to selected students or a whole
-- Grade/Section of the teacher's own assigned classes.
--
-- You normally do NOT need to run this by hand: includes/teacher_ai_resources.php
-- creates the same objects automatically the first time a teacher uses the
-- feature (every statement is idempotent). It is shipped so an administrator can
-- apply it manually, for example if the database user used by the web app is not
-- allowed to CREATE/ALTER tables.
--
-- Nothing existing is dropped, renamed or rewritten. Adds:
--   - teacher_ai_resources            what Teacher AI generated (content, plus one
--                                     row per created file/Form). Owned by one
--                                     teacher; holds the teacher-only answer key.
--   - teacher_ai_resource_sends       one row per "send to class" click.
--   - teacher_ai_resource_recipients  which student received what (also stops the
--                                     same resource being sent twice to the same
--                                     student for the same subject).
--   - teacher_ai_resources.subject_code (nullable) remembers the subject a resource
--     was made for when the teacher used Teacher AI's subject mode, so it can only
--     be sent to that subject's classes.
--   - ai_generated_resources.output_format is WIDENED from
--     enum('pdf','docx','gform') to enum('pdf','docx','gform','pptx') so a
--     PowerPoint sent to a student can be stored like a PDF/Word file. Existing
--     rows keep their values.
--
-- No foreign keys, matching the other classroom-era tables, so this can never
-- fail on engine/collation ordering.
--
-- Safe to run on the live database at any time; safe to re-run.
--   mysql -u root egradepro < database/migrations/2026_teacher_ai_resources.sql
-- or import via phpMyAdmin.
-- ============================================================

CREATE TABLE IF NOT EXISTS `teacher_ai_resources` (
  `resource_id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL COMMENT 'NULL = the generated content (Draft). Set = a file/Form created from that Draft.',
  `resource_type` varchar(40) NOT NULL DEFAULT 'other' COMMENT 'One of AI_RESOURCE_TYPES (includes/ai_document_generator.php).',
  `title` varchar(150) NOT NULL DEFAULT '',
  `source_prompt` varchar(500) DEFAULT NULL COMMENT 'The teacher''s request, truncated. Context only, never shown to students.',
  `student_content` mediumtext NOT NULL COMMENT 'Student-facing material. The only content ever copied to a student.',
  `teacher_answer_key` mediumtext DEFAULT NULL COMMENT 'Teacher-only answers/notes. Never copied to a student row.',
  `quiz_questions_json` mediumtext DEFAULT NULL COMMENT 'Structured multiple-choice questions used to build a Google Form.',
  `quiz_max_score` int(11) DEFAULT NULL,
  `output_format` enum('pdf','docx','pptx','gform') DEFAULT NULL COMMENT 'NULL for the Draft; the produced format for an output row.',
  `stored_filename` varchar(255) DEFAULT NULL COMMENT 'Random name under static/uploads/ai_resources/.',
  `original_filename` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `google_form_id` varchar(64) DEFAULT NULL,
  `google_form_edit_url` varchar(500) DEFAULT NULL,
  `google_form_view_url` varchar(500) DEFAULT NULL,
  `status` enum('Draft','Generated','Sent') NOT NULL DEFAULT 'Draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`resource_id`),
  KEY `idx_tai_res_teacher` (`teacher_id`, `created_at`),
  KEY `idx_tai_res_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `teacher_ai_resource_sends` (
  `send_id` int(11) NOT NULL AUTO_INCREMENT,
  `resource_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `school_year_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `term` varchar(20) DEFAULT NULL,
  `grade_level` varchar(50) DEFAULT NULL,
  `scope` enum('selected','all') NOT NULL DEFAULT 'selected',
  `note` varchar(500) DEFAULT NULL,
  `recipient_count` int(11) NOT NULL DEFAULT 0,
  `skipped_count` int(11) NOT NULL DEFAULT 0,
  `failed_count` int(11) NOT NULL DEFAULT 0,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`send_id`),
  KEY `idx_tai_sends_resource` (`resource_id`),
  KEY `idx_tai_sends_teacher` (`teacher_id`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `teacher_ai_resource_recipients` (
  `recipient_id` int(11) NOT NULL AUTO_INCREMENT,
  `send_id` int(11) NOT NULL,
  `resource_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `student_resource_id` int(11) DEFAULT NULL COMMENT 'The per-student ai_generated_resources row the student portal reads.',
  `message_id` int(11) DEFAULT NULL COMMENT 'The student_support_messages row that delivered it.',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`recipient_id`),
  UNIQUE KEY `uq_tai_recipient` (`resource_id`, `student_id`, `subject_code`),
  KEY `idx_tai_recipient_send` (`send_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Allow 'pptx' on the per-student resource rows -- only if it is not there yet
-- (and only if ai_generated_resources exists). Re-running is a no-op.
SET @needs_pptx = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ai_generated_resources'
    AND COLUMN_NAME = 'output_format'
    AND COLUMN_TYPE NOT LIKE '%''pptx''%'
);
SET @sql = IF(@needs_pptx > 0,
  'ALTER TABLE `ai_generated_resources` MODIFY COLUMN `output_format` enum(''pdf'',''docx'',''gform'',''pptx'') DEFAULT NULL COMMENT ''NULL until produced in final form. pdf/docx/pptx = a generated file under static/uploads/ai_resources/. gform = a Google Form (see google_form_* columns).''',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Subject mode: remember the subject a resource was made for. Only added when
-- missing; re-running is a no-op.
SET @needs_subject = (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'teacher_ai_resources'
    AND COLUMN_NAME = 'subject_code'
);
SET @sql = IF(@needs_subject,
  'ALTER TABLE `teacher_ai_resources` ADD COLUMN `subject_code` varchar(20) DEFAULT NULL AFTER `teacher_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
