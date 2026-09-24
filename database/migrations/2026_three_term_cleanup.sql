-- ============================================================================
-- EGRADEPRO — DepEd THREE-TERM CLEANUP MIGRATION (SY 2026-2027)
-- ============================================================================
-- Run this ONCE against the EXISTING `egradepro` database.
--
-- BEFORE RUNNING: make sure `egradepro_before_2026_cleanup.sql` exists and
-- has been validated. This migration PERMANENTLY DELETES the obsolete
-- Quarter data and it is NOT reversible from inside the running system.
--
-- What this migration does NOT touch:
--   students, users, teachers, sections, enrollments, enrollment_requirements,
--   subjects, attendance, teacher_attendance, reports, tutorial_plans,
--   tutorial_plan_items, notifications, activity_logs, roles,
--   online_applications, application_documents, assessment_items,
--   assessment_scores, grade_components.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
/*!40101 SET NAMES utf8mb4 */;

START TRANSACTION;

-- ----------------------------------------------------------------------------
-- 1. DELETE ALL FOUR-QUARTER GRADE RECORDS (Grades 7-12)
-- ----------------------------------------------------------------------------
-- These are NOT converted into Term Grades: the 2026 assessment structure and
-- weights differ from DO 8, s. 2015, so converting would fabricate grades.
-- New Three-Term grades must be encoded again from the raw assessment data.
DELETE FROM `grades`
 WHERE `term` IN ('1st Quarter', '2nd Quarter', '3rd Quarter', '4th Quarter');

-- Belt-and-braces: anything that merely LOOKS like a Quarter label.
DELETE FROM `grades` WHERE `term` LIKE '%Quarter%';

-- The same cleanup for the per-component table, in case any component row was
-- ever written under a Quarter label.
DELETE FROM `grade_components` WHERE `term` LIKE '%Quarter%';

-- ----------------------------------------------------------------------------
-- 2. DELETE INVALID TEST-GRADE DATA
-- ----------------------------------------------------------------------------
-- Includes the Term 1 record holding a Performance Task percentage of 123.00.
-- Invalid values are DELETED, never rewritten to 100 — clamping an impossible
-- score would manufacture a passing grade out of bad data.
DELETE FROM `grades`
 WHERE `written_work`  < 0 OR `written_work`  > 100
    OR `performance_task` < 0 OR `performance_task` > 100
    OR `quarterly_assessment` < 0 OR `quarterly_assessment` > 100
    OR `initial_grade` < 0 OR `initial_grade` > 100
    OR `transmuted_grade` < 0 OR `transmuted_grade` > 100
    OR `final_grade` < 0 OR `final_grade` > 100;

DELETE FROM `grade_components`
 WHERE `written_work`  < 0 OR `written_work`  > 100
    OR `performance_task` < 0 OR `performance_task` > 100
    OR `examinations` < 0 OR `examinations` > 100
    OR `initial_grade` < 0 OR `initial_grade` > 100
    OR `transmuted_grade` < 0 OR `transmuted_grade` > 100;

-- Any assessment score that is impossible against its own Highest Possible
-- Score, and any item whose HPS is not greater than zero.
DELETE sc FROM `assessment_scores` sc
  JOIN `assessment_items` i ON sc.`item_id` = i.`item_id`
 WHERE sc.`raw_score` < 0 OR sc.`raw_score` > i.`highest_score` OR i.`highest_score` <= 0;

-- ----------------------------------------------------------------------------
-- 3. REMOVE THE OBSOLETE QUARTER ARCHIVE TABLE
-- ----------------------------------------------------------------------------
-- Structure, data, indexes, auto-increment and all. No replacement archive
-- table is created — the external emergency backup is the only recovery path.
DROP TABLE IF EXISTS `grades_quarter_archive_2026`;

-- ----------------------------------------------------------------------------
-- 4. CLEAR DERIVED ANALYSIS COMPUTED FROM QUARTER GRADES
-- ----------------------------------------------------------------------------
-- `ai_analysis` is a derived cache, rebuilt automatically by
-- syncStudentRiskAnalysis() the next time a risk view is opened. Every
-- existing row was computed from the Quarter grades deleted above, so leaving
-- them would let Quarter data keep driving risk badges and at-risk reports.
-- No student, enrollment or log record is affected.
DELETE FROM `ai_analysis`;

-- ----------------------------------------------------------------------------
-- 5. REMOVE OBSOLETE GLOBAL GRADING SETTINGS
-- ----------------------------------------------------------------------------
-- Grades 7-12 component weights now come ONLY from the verified subject
-- category in includes/grading_helpers.php. A global override cannot be
-- allowed to silently replace an official DO 015, s. 2026 weight.
-- `grading_pass_mark` is deliberately KEPT (default 75).
DELETE FROM `system_settings`
 WHERE `setting_key` IN ('grading_ww_weight', 'grading_pt_weight', 'grading_qa_weight');

-- Stray artefact row written by an old version of the settings form
-- (setting_key 'grading', setting_value 'group'). Read by nothing.
DELETE FROM `system_settings` WHERE `setting_key` = 'grading' AND `setting_value` = 'group';

-- ----------------------------------------------------------------------------
-- 6. SCHOOL YEAR CONFIGURATION — THREE-TERM ONLY
-- ----------------------------------------------------------------------------
-- Migrate the data BEFORE narrowing the enum, so no school-year row is
-- silently truncated to an empty string.
UPDATE `school_years` SET `grading_structure` = 'term' WHERE `grading_structure` <> 'term';

-- `quarter` is no longer a selectable value anywhere in the system.
ALTER TABLE `school_years`
  MODIFY `grading_structure` ENUM('term') NOT NULL DEFAULT 'term'
  COMMENT 'DepEd Three-Term structure (DO 009 / DO 015, s. 2026). Quarter is retired.';

-- ----------------------------------------------------------------------------
-- 7. RENAME quarterly_assessment -> examinations
-- ----------------------------------------------------------------------------
-- Safe: no foreign key, index or generated column depends on this column NAME,
-- and `grade_components` already uses `examinations`. This makes the storage
-- name match the DO 015, s. 2026 component name end-to-end, including CSV
-- exports and the `vw_student_grades` view.
ALTER TABLE `grades`
  CHANGE `quarterly_assessment` `examinations` DECIMAL(5,2) DEFAULT 0.00
  COMMENT 'EX — Examinations percentage (ST1 30% + ST2 30% + Term Exam 40%).';

-- ----------------------------------------------------------------------------
-- 8. SERVER-SIDE VALIDATION AT THE DATABASE LAYER
-- ----------------------------------------------------------------------------
-- Enforced by the database itself, so an invalid grading period or an
-- out-of-range percentage is rejected even if a page is ever changed, bypassed
-- or called directly. This is in ADDITION to the PHP validation, never
-- instead of it, and never relies on JavaScript.
ALTER TABLE `grades`
  ADD CONSTRAINT `chk_grades_term`
      CHECK (`term` IN ('Term 1', 'Term 2', 'Term 3')),
  ADD CONSTRAINT `chk_grades_ranges`
      CHECK (`written_work`     BETWEEN 0 AND 100
         AND `performance_task` BETWEEN 0 AND 100
         AND `examinations`     BETWEEN 0 AND 100
         AND `initial_grade`    BETWEEN 0 AND 100
         AND `transmuted_grade` BETWEEN 0 AND 100
         AND `final_grade`      BETWEEN 0 AND 100);

ALTER TABLE `grade_components`
  ADD CONSTRAINT `chk_gc_term`
      CHECK (`term` IN ('Term 1', 'Term 2', 'Term 3')),
  ADD CONSTRAINT `chk_gc_ranges`
      CHECK ((`written_work`     IS NULL OR `written_work`     BETWEEN 0 AND 100)
         AND (`performance_task` IS NULL OR `performance_task` BETWEEN 0 AND 100)
         AND (`examinations`     IS NULL OR `examinations`     BETWEEN 0 AND 100)
         AND (`initial_grade`    IS NULL OR `initial_grade`    BETWEEN 0 AND 100)
         AND (`transmuted_grade` IS NULL OR `transmuted_grade` BETWEEN 0 AND 100));

ALTER TABLE `assessment_items`
  ADD CONSTRAINT `chk_items_term`
      CHECK (`term` IN ('Term 1', 'Term 2', 'Term 3')),
  ADD CONSTRAINT `chk_items_hps`
      CHECK (`highest_score` > 0);

ALTER TABLE `assessment_scores`
  ADD CONSTRAINT `chk_scores_nonneg`
      CHECK (`raw_score` IS NULL OR `raw_score` >= 0);

-- ----------------------------------------------------------------------------
-- 9. REBUILD THE STUDENT GRADES VIEW
-- ----------------------------------------------------------------------------
-- Uses the renamed `examinations` column, and exposes ONLY valid, completed
-- Term 1-3 records so no report, export or dashboard built on this view can
-- pick up an incomplete or non-Term row.
DROP VIEW IF EXISTS `vw_student_grades`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `vw_student_grades` AS
SELECT `s`.`student_id`                                   AS `student_id`,
       `s`.`lrn`                                          AS `lrn`,
       CONCAT(`s`.`last_name`, ', ', `s`.`first_name`)    AS `student_name`,
       `sub`.`subject_code`                               AS `subject_code`,
       `sub`.`subject_name`                               AS `subject_name`,
       `g`.`term`                                         AS `term`,
       `g`.`written_work`                                 AS `written_work`,
       `g`.`performance_task`                             AS `performance_task`,
       `g`.`examinations`                                 AS `examinations`,
       `g`.`initial_grade`                                AS `initial_grade`,
       `g`.`transmuted_grade`                             AS `term_grade`,
       `g`.`final_grade`                                  AS `final_grade`,
       `g`.`remarks`                                      AS `remarks`,
       `sy`.`sy_name`                                     AS `sy_name`
  FROM `grades` `g`
  JOIN `students`     `s`   ON `g`.`student_id`    = `s`.`student_id`
  JOIN `subjects`     `sub` ON `g`.`subject_code`  = `sub`.`subject_code`
  JOIN `school_years` `sy`  ON `g`.`school_year_id` = `sy`.`sy_id`
 WHERE `g`.`term` IN ('Term 1', 'Term 2', 'Term 3')
   AND `g`.`remarks` NOT IN ('Incomplete', 'Not configured')
   AND `g`.`transmuted_grade` >= 60;

-- `vw_active_enrollments` is unrelated to grading and is left exactly as it is.

COMMIT;

-- ============================================================================
-- POST-MIGRATION VERIFICATION (all four must return 0)
-- ============================================================================
-- SELECT COUNT(*) FROM `grades` WHERE `term` NOT IN ('Term 1','Term 2','Term 3');
-- SELECT COUNT(*) FROM `information_schema`.`tables`
--   WHERE `table_schema` = DATABASE() AND `table_name` = 'grades_quarter_archive_2026';
-- SELECT COUNT(*) FROM `system_settings`
--   WHERE `setting_key` IN ('grading_ww_weight','grading_pt_weight','grading_qa_weight');
-- SELECT COUNT(*) FROM `school_years` WHERE `grading_structure` <> 'term';
-- ============================================================================
