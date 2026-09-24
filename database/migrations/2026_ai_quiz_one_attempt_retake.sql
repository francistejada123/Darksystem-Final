-- ============================================================
-- 2026_ai_quiz_one_attempt_retake.sql
-- SAFE, ADDITIVE migration for the Ask AI / Teacher AI assessment flow:
--   * a graded quiz is ONE ATTEMPT per student; the teacher can allow a retake
--   * a resource sent to a class is recorded in Manage Assessment Items
--
-- The app adds these columns by itself the first time they are needed
-- (includes/ai_resource_submission_helpers.php and includes/teacher_ai_resources.php),
-- so running this file is optional -- it is here so the schema is written down
-- and can be applied by hand.
--
-- Nothing existing is dropped, altered or renamed; every existing row keeps its
-- values (retake_allowed = 0, record_* = NULL).
--
--   mysql -u root egradepro < database/migrations/2026_ai_quiz_one_attempt_retake.sql
-- ============================================================

ALTER TABLE `ai_resource_submissions`
  ADD COLUMN IF NOT EXISTS `retake_allowed` tinyint(1) NOT NULL DEFAULT 0
    COMMENT '1 = the teacher allowed this student ONE more attempt. Cleared automatically by the next submission, which replaces the score.';

-- Needs teacher_ai_resource_sends to exist (created by 2026_teacher_ai_resources.sql
-- or automatically the first time a teacher uses Teacher AI).
ALTER TABLE `teacher_ai_resource_sends`
  ADD COLUMN IF NOT EXISTS `record_category` varchar(8) DEFAULT NULL
    COMMENT 'WW / PT / QA = recorded in Manage Assessment Items under that category; none = the teacher chose not to record it; NULL = sent before automatic recording existed.',
  ADD COLUMN IF NOT EXISTS `record_item_id` int(11) DEFAULT NULL
    COMMENT 'assessment_items.item_id this send was recorded under.';
