-- ============================================================================
-- EGRADEPRO — OFFICIAL DepEd ECR ALIGNMENT (SY 2026-2027)
-- ============================================================================
-- Run this AFTER egradepro_2026_cleanup_migration.sql.
--
-- Every change below was verified cell-by-cell against the OFFICIAL DepEd
-- Three-Term E-Class Record workbooks for SY 2026-2027:
--   * Grades 2-10 (Science, Math, English, Filipino, Araling Panlipunan)
--   * Grades 2-10 (EPP/TLE)
--   * Grades 2-10 (Music and Arts, PE and Health)
--   * Grades 2-10 (GMRC, Values Education)
--   * KS4 SSHS (All Subjects)
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
/*!40101 SET NAMES utf8mb4 */;

START TRANSACTION;

-- ----------------------------------------------------------------------------
-- 1. MAPEH HAS TWO COMPONENTS, NOT FOUR
-- ----------------------------------------------------------------------------
-- The official ECR ships exactly two component sheets per term:
--     "TERM n Music and Arts"   and   "TERM n PE and Health"
-- Each is weighted 20/60/20 and transmuted separately; the MAPEH Term Grade is
-- the average of the two. It is NOT Music / Arts / PE / Health as four parts.
UPDATE `subjects`
   SET `components` = 'Music and Arts, PE and Health'
 WHERE `subject_category` = 'ks_mapeh'
    OR `components` IN (
        'Music, Arts, Physical Education, Health',
        'Music,Arts,Physical Education,Health'
    );

-- Any assessment item still tagged with one of the four old component names is
-- remapped onto the two official components. No score is deleted.
UPDATE `assessment_items` SET `component` = 'Music and Arts'
 WHERE `component` IN ('Music', 'Arts');
UPDATE `assessment_items` SET `component` = 'PE and Health'
 WHERE `component` IN ('Physical Education', 'Health', 'PE', 'MAPEH - PE');

-- ----------------------------------------------------------------------------
-- 2. RETIRE THE GRADE 12 LEGACY SHS CURRICULUM TYPE
-- ----------------------------------------------------------------------------
-- Of the 139 courses in the official SSHS ECR HELPER sheet — covering grade
-- levels "11", "11, 12" and "gr 12" alike — NOT ONE uses the DO 8, s. 2015
-- weights (25/50/25, 25/45/30, 35/40/25). Grade 11 and Grade 12 both use the
-- Strengthened SHS weights, so `legacy_shs` is no longer a valid value and
-- Grade 12 encoding is no longer blocked waiting for it to be set.
UPDATE `students` SET `curriculum_type` = 'strengthened_shs'
 WHERE `curriculum_type` IS NOT NULL AND `curriculum_type` <> 'strengthened_shs';

UPDATE `sections` SET `curriculum_type` = 'strengthened_shs'
 WHERE `curriculum_type` IS NOT NULL AND `curriculum_type` <> 'strengthened_shs';

UPDATE `system_settings` SET `setting_value` = 'strengthened_shs'
 WHERE `setting_key` = 'shs_default_curriculum_type'
   AND `setting_value` <> 'strengthened_shs';

-- Any subject still carrying one of the four deleted legacy categories is moved
-- to the equivalent Strengthened SHS category so no subject is left orphaned.
UPDATE `subjects` SET `subject_category` = 'sshs_core'
 WHERE `subject_category` IN ('legacy_shs_core', 'legacy_academic_other');
UPDATE `subjects` SET `subject_category` = 'sshs_research_design'
 WHERE `subject_category` = 'legacy_academic_immersion_research';
UPDATE `subjects` SET `subject_category` = 'sshs_arts_sports_health'
 WHERE `subject_category` = 'legacy_tvl_sports_arts';

-- ----------------------------------------------------------------------------
-- 3. PER-SUBJECT NUMBER OF TERMS
-- ----------------------------------------------------------------------------
-- The official SSHS ECR carries a "Number of terms" value per course (1, 2 or
-- 3) and withholds the Final Grade until that many term grades exist:
--     Final Grade = ROUND(SUM(term grades) * UnitsPerTerm / TotalUnits, 0)
--     TotalUnits  = UnitsPerTerm x NumberOfTerms
-- which reduces to the mean over the course's OWN number of terms.
--
-- Default 3 — every existing subject keeps its current behaviour until an
-- administrator sets a shorter course explicitly. Grades 7-10 learning areas
-- always run all three terms and must stay at 3.
ALTER TABLE `subjects`
  ADD COLUMN `terms_required` TINYINT(1) NOT NULL DEFAULT 3
  COMMENT 'Grading periods this subject runs for (1-3). Grades 4-10 are always 3; SHS courses vary.'
  AFTER `units`;

ALTER TABLE `subjects`
  ADD CONSTRAINT `chk_subjects_terms_required`
      CHECK (`terms_required` BETWEEN 1 AND 3);

-- Grades 4-10 learning areas are explicitly pinned to three terms.
UPDATE `subjects`
   SET `terms_required` = 3
 WHERE `subject_category` LIKE 'ks\_%';

COMMIT;

-- ============================================================================
-- ACTION REQUIRED AFTER RUNNING THIS MIGRATION
-- ============================================================================
-- Every subject now defaults to 3 terms. For any Senior High School course
-- that officially runs for only ONE or TWO terms, set it explicitly, e.g.
--
--   UPDATE `subjects` SET `terms_required` = 1 WHERE `subject_code` = 'XXXX';
--   UPDATE `subjects` SET `terms_required` = 2 WHERE `subject_code` = 'YYYY';
--
-- The correct number per course is in the official SSHS ECR HELPER sheet,
-- columns "GR 11 - TERMS" and "GR 12 - TERMS". This cannot be derived by the
-- system — it depends on which electives your school actually offers.
--
-- Until it is set, a 1-term or 2-term SHS course will keep reporting a
-- "Current Average" instead of a Final Grade. That is the safe direction to
-- fail: it withholds a grade rather than issuing a wrong one.
-- ============================================================================

-- ============================================================================
-- VERIFICATION (all four must return 0)
-- ============================================================================
-- SELECT COUNT(*) FROM `subjects`
--   WHERE `subject_category` LIKE 'legacy\_%';
-- SELECT COUNT(*) FROM `students`
--   WHERE `curriculum_type` IS NOT NULL AND `curriculum_type` <> 'strengthened_shs';
-- SELECT COUNT(*) FROM `assessment_items`
--   WHERE `component` IN ('Music','Arts','Physical Education','Health');
-- SELECT COUNT(*) FROM `subjects` WHERE `terms_required` NOT BETWEEN 1 AND 3;
-- ============================================================================
