-- ============================================================
-- 2026_ai_reviewer_quiz.sql
-- SAFE, ADDITIVE migration for Ask AI's new "Generate Reviewer" and
-- "Generate Practice Quiz" actions (still the SAME Ask AI / Gemini
-- architecture — includes/gemini_service.php + modules/ai/chatbot.php —
-- no new/duplicate AI system).
--
-- Adds TWO new tables. Nothing existing is dropped, altered, or renamed:
--   - ai_reviewers          Draft/Published AI-generated study reviewers,
--                           one per student+subject generation, always
--                           teacher-reviewed before becoming visible.
--   - ai_practice_quizzes   Draft/Published AI-generated practice quiz
--                           content (question/choices/correct answer as
--                           JSON) for teacher review/edit before
--                           publishing. This is NOT yet the interactive
--                           online-quiz-taking system — that additive
--                           piece (online_quizzes/quiz_questions/
--                           quiz_attempts/etc., with automatic objective
--                           grading wired into the existing grade
--                           computation) is a separate, later migration.
--
-- Safe to run on the live database at any time; safe to re-run (uses
-- IF NOT EXISTS).
--
-- Run against the `egradepro` database, e.g.:
--   mysql -u root egradepro < database/migrations/2026_ai_reviewer_quiz.sql
-- or import via phpMyAdmin.
-- ============================================================

CREATE TABLE IF NOT EXISTS `ai_reviewers` (
  `reviewer_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `school_year_id` int(11) DEFAULT NULL,
  `content` mediumtext NOT NULL COMMENT 'Teacher-reviewed/edited reviewer text (structured plain text: Topic Explanation, Key Concepts, Important Terms, Examples, Practice Questions, Study Checklist).',
  `status` enum('Draft','Published') NOT NULL DEFAULT 'Draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `published_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`reviewer_id`),
  KEY `idx_ai_reviewers_student` (`student_id`),
  KEY `idx_ai_reviewers_teacher` (`teacher_id`),
  KEY `idx_ai_reviewers_subject` (`subject_code`),
  CONSTRAINT `fk_ai_reviewers_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_reviewers_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ai_practice_quizzes` (
  `quiz_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `school_year_id` int(11) DEFAULT NULL,
  `title` varchar(150) NOT NULL DEFAULT '',
  `question_type` enum('multiple_choice','true_false','mixed') NOT NULL DEFAULT 'multiple_choice',
  `questions_json` mediumtext NOT NULL COMMENT 'Teacher-reviewed/edited JSON: {"questions":[{"type":"mc|tf","question":"...","choices":["..."],"correct_index":0}]}',
  `status` enum('Draft','Published') NOT NULL DEFAULT 'Draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `published_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`quiz_id`),
  KEY `idx_ai_quizzes_student` (`student_id`),
  KEY `idx_ai_quizzes_teacher` (`teacher_id`),
  KEY `idx_ai_quizzes_subject` (`subject_code`),
  CONSTRAINT `fk_ai_quizzes_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_quizzes_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
