-- ============================================================
-- 2026_term_finalization.sql
-- ============================================================
-- Adds the "Finalize Term" workflow on top of the existing Manage
-- Assessment Items / Encode Grades pages.
--
-- BEHAVIOUR THIS ENABLES (see includes/grading_helpers.php, Section 16,
-- and modules/grading/index.php step 5):
--   * assessment_items + assessment_scores (Manage Assessment Items)
--     remain the ONLY place raw Written Works / Performance Tasks /
--     Examinations scores and HPS are stored while Term 1, 2 or 3 is
--     ongoing.
--   * Saving a score in Encode Grades no longer immediately computes or
--     writes the student's Initial Grade / Term Grade / Remarks into
--     `grades`. That computation now happens ONLY when a teacher clicks
--     "Finalize <Term>" for a class -- an explicit, repeatable action.
--   * `term_finalizations` records that a (teacher, subject, section,
--     school year, term) has been finalized, by whom and when. Its
--     presence is the ONLY signal that a class's Encode Grades numbers
--     are official; everything else keeps working exactly as before.
--
-- SAFE AND ADDITIVE:
--   * No table is dropped, renamed, or has a column removed.
--   * No existing row in `grades`, `grade_components`, `assessment_items`
--     or `assessment_scores` is modified or deleted.
--   * Every (teacher, subject, section, school year, term) that ALREADY
--     has a `grades` row today is backfilled into `term_finalizations`,
--     so classes that were already graded under the old immediate-
--     compute behaviour keep showing their grades in Encode Grades
--     exactly as before -- nothing that was already graded disappears.
--
-- Safe to re-run: CREATE TABLE IF NOT EXISTS, and the backfill uses
-- INSERT IGNORE against the table's own unique key.
--
-- Run against the `egradepro` database, e.g.:
--   mysql -u root egradepro < database/migrations/2026_term_finalization.sql
-- or import via phpMyAdmin.
-- ============================================================

CREATE TABLE IF NOT EXISTS `term_finalizations` (
  `finalization_id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `section_id` int(11) NOT NULL,
  `school_year_id` int(11) NOT NULL,
  `term` varchar(20) NOT NULL,
  `finalized_by` int(11) DEFAULT NULL,
  `finalized_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `student_count` int(11) NOT NULL DEFAULT 0,
  `complete_count` int(11) NOT NULL DEFAULT 0,
  `incomplete_count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`finalization_id`),
  UNIQUE KEY `uniq_term_finalization` (`teacher_id`,`subject_code`,`section_id`,`school_year_id`,`term`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Marks a (teacher, subject, section, school year, term) as finalized. Presence of a row = the grades for that class/term are official and shown in Encode Grades.';

-- Backfill: a `grades` row that already exists today was written under the
-- OLD immediate-compute behaviour, so it is, by definition, already
-- "finalized". This keeps every already-graded class looking exactly the
-- same in Encode Grades after this migration runs -- nothing that was
-- already graded appears to "disappear".
INSERT IGNORE INTO `term_finalizations`
  (`teacher_id`, `subject_code`, `section_id`, `school_year_id`, `term`, `finalized_by`, `finalized_at`, `student_count`)
SELECT
  `teacher_id`, `subject_code`, `section_id`, `school_year_id`, `term`,
  MAX(`encoded_by`), MAX(`encoded_at`), COUNT(*)
FROM `grades`
GROUP BY `teacher_id`, `subject_code`, `section_id`, `school_year_id`, `term`;
