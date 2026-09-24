-- ============================================================
-- 2026_ai_grading_assistant.sql
-- SAFE, ADDITIVE migration for Ask AI's new assistant inside Manage
-- Assessment Items (modules/grading/items.php) — Section 10 of the
-- improvement spec. Still the SAME Ask AI / Gemini architecture
-- (includes/gemini_service.php + modules/ai/chatbot.php), now made
-- context-aware for a TEACHER'S WHOLE SECTION/SUBJECT instead of one
-- individual student.
--
-- Adds ONE new table. Nothing existing is dropped, altered, or renamed,
-- and the existing per-student ai_reviewers / ai_practice_quizzes tables
-- (2026_ai_reviewer_quiz.sql) are completely untouched:
--   - ai_class_content   Draft/Published AI-generated CLASS-LEVEL content
--                        for one teacher's section+subject: Generate
--                        Quiz, Generate Practice Quiz, Generate Reviewer,
--                        Generate Remedial Activity, Suggest Performance
--                        Task, Suggest Written Work, and Generate
--                        Question Bank. Always teacher-reviewed/edited
--                        before Published — the AI never auto-publishes.
--
--                        "Published" means different things per type:
--                          - quiz / practice_quiz / reviewer /
--                            remedial_activity: becomes visible to every
--                            student in that section+subject, in the
--                            Student Portal.
--                          - performance_task_suggestion /
--                            written_work_suggestion / question_bank:
--                            planning-only, never shown to any student —
--                            "Published" just marks the teacher's own
--                            final version vs. a Draft.
--
-- Safe to run on the live database at any time; safe to re-run (uses
-- IF NOT EXISTS).
--
-- Run against the `egradepro` database, e.g.:
--   mysql -u root egradepro < database/migrations/2026_ai_grading_assistant.sql
-- or import via phpMyAdmin.
-- ============================================================

CREATE TABLE IF NOT EXISTS `ai_class_content` (
  `content_id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `school_year_id` int(11) DEFAULT NULL,
  `term` varchar(20) DEFAULT NULL,
  `content_type` enum('quiz','practice_quiz','reviewer','remedial_activity','performance_task_suggestion','written_work_suggestion','question_bank') NOT NULL,
  `category` enum('WW','PT','QA') DEFAULT NULL COMMENT 'Assessment category this content relates to, when the teacher picked one. Purely descriptive — never read by grade computation.',
  `topic` varchar(255) DEFAULT NULL,
  `title` varchar(150) NOT NULL DEFAULT '',
  `content` mediumtext DEFAULT NULL COMMENT 'Teacher-reviewed/edited plain text, for the reviewer / remedial_activity / performance_task_suggestion / written_work_suggestion / question_bank types.',
  `question_type` enum('multiple_choice','true_false','mixed') DEFAULT NULL COMMENT 'quiz / practice_quiz types only.',
  `questions_json` mediumtext DEFAULT NULL COMMENT 'quiz / practice_quiz types only. Same shape as ai_practice_quizzes.questions_json: {"questions":[{"type":"mc|tf","question":"...","choices":["..."],"correct_index":0}]}',
  `status` enum('Draft','Published') NOT NULL DEFAULT 'Draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `published_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`content_id`),
  KEY `idx_ai_class_content_teacher` (`teacher_id`),
  KEY `idx_ai_class_content_section` (`section_id`),
  KEY `idx_ai_class_content_subject` (`subject_code`),
  KEY `idx_ai_class_content_lookup` (`section_id`,`subject_code`,`status`),
  CONSTRAINT `fk_ai_class_content_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_class_content_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
