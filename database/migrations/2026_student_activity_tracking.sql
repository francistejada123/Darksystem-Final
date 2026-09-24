-- ============================================================
-- 2026_student_activity_tracking.sql
-- SAFE, ADDITIVE migration for lightweight Student Activity /
-- Engagement Monitoring (Section 4 of the improvement spec).
--
-- Adds:
--   - students.last_login       set once, on each successful Student
--                                Portal login (LRN-based).
--   - students.last_active_at   a lightweight "last seen" heartbeat,
--                                updated whenever the student opens any
--                                Student Portal page. This alone drives
--                                the Online / Recently Active / Inactive
--                                indicator — it is NEVER used to affect a
--                                grade or risk status.
--   - student_activity_log      a new, additive table recording specific
--                                academic engagement events (portal login,
--                                tutorial plan viewed, study reviewer
--                                viewed, practice quiz viewed, support
--                                message viewed, tutorial video progress)
--                                so staff and Ask AI can reference REAL
--                                recorded activity — never invented.
--
-- Nothing existing is dropped, altered destructively, or renamed. Safe to
-- run on the live database at any time; safe to re-run:
--   - CREATE TABLE IF NOT EXISTS for the new table
--   - ADD COLUMN IF NOT EXISTS for the two new students columns (supported
--     on MariaDB 10.0.2+ / MySQL 8.0.29+ — this project's server is
--     MariaDB 10.4, so this is safe here)
--
-- Run against the `egradepro` database, e.g.:
--   mysql -u root egradepro < database/migrations/2026_student_activity_tracking.sql
-- or import via phpMyAdmin.
-- ============================================================

ALTER TABLE `students`
  ADD COLUMN IF NOT EXISTS `last_login` DATETIME NULL DEFAULT NULL COMMENT 'Last successful Student Portal login (LRN-based).' AFTER `curriculum_type`,
  ADD COLUMN IF NOT EXISTS `last_active_at` DATETIME NULL DEFAULT NULL COMMENT 'Lightweight heartbeat updated on every Student Portal page load. Drives Online/Recently Active/Inactive display only -- never affects grades or risk status.' AFTER `last_login`;

CREATE TABLE IF NOT EXISTS `student_activity_log` (
  `activity_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `event_type` varchar(50) NOT NULL COMMENT 'login | tutorial_viewed | reviewer_viewed | quiz_viewed | message_viewed | video_progress',
  `reference_table` varchar(50) DEFAULT NULL COMMENT 'e.g. ai_reviewers, ai_practice_quizzes, tutorial_plan_items -- optional pointer to the specific record involved.',
  `reference_id` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`activity_id`),
  KEY `idx_student_activity_student` (`student_id`, `created_at`),
  CONSTRAINT `fk_student_activity_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
