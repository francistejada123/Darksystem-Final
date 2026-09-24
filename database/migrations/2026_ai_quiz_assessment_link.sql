-- ============================================================
-- 2026_ai_quiz_assessment_link.sql
-- SAFE, ADDITIVE migration on top of 2026_ai_quiz_gform.sql and
-- 2026_ai_quiz_one_attempt.sql (run those first). Links an AI-generated
-- Google Form quiz to a REAL assessment item in the existing grading
-- system (assessment_items / assessment_scores / grades — see
-- modules/grading/items.php and modules/grading/index.php), so a
-- student's Google Forms score can flow automatically into Grade
-- Management instead of the teacher re-typing it.
--
-- Nothing existing is dropped, renamed, or reinterpreted. This migration
-- does not touch assessment_items, assessment_scores, or grades at all —
-- it only adds nullable columns to ai_generated_resources that point AT
-- one row the app creates there, exactly the way a teacher creating an
-- item manually in "Manage Items" would.
--
--   - link_category / link_term: the Written Works / Performance Tasks /
--     Examinations category and Term the TEACHER explicitly chose when
--     creating this quiz (required at creation time — see
--     modules/ai/create_quiz_form.php). NULL only for a quiz created
--     before this migration existed.
--   - link_item_id: the assessment_items.item_id this quiz's Google Form
--     total-points item was created as (highest_score = the quiz's
--     max_score). No FK constraint is added on purpose — assessment_items
--     rows are never deleted by this feature, and keeping this a plain
--     indexed column avoids any risk to the existing grading schema.
--   - grade_sync_status: what happened the last time a submission for
--     this quiz was synced — 'synced' (grade + risk recomputed
--     automatically), 'skipped_manual_review' (the subject uses MAPEH-style
--     multi-component or GMRC/Values-Ed domain grading, which a single
--     quiz score can't be safely auto-mapped into — the raw score is
--     still recorded in assessment_scores, but the teacher finishes the
--     grade the normal way in Grade Management), or NULL (not synced yet
--     / not applicable).
--
-- Safe to run on the live database at any time; safe to re-run (uses
-- ADD COLUMN IF NOT EXISTS / ADD INDEX IF NOT EXISTS, matching this
-- project's other migrations).
--
-- Run against the `egradepro` database, e.g.:
--   mysql -u root egradepro < database/migrations/2026_ai_quiz_assessment_link.sql
-- or import via phpMyAdmin.
-- ============================================================

ALTER TABLE `ai_generated_resources`
  ADD COLUMN IF NOT EXISTS `link_category` enum('WW','PT','QA') DEFAULT NULL COMMENT 'Assessment category the teacher chose at quiz-creation time: Written Works, Performance Tasks, or Examinations (Quarterly Assessment).' AFTER `quiz_closed_at`,
  ADD COLUMN IF NOT EXISTS `link_term` enum('Term 1','Term 2','Term 3') DEFAULT NULL COMMENT 'Grading term this quiz''s score counts toward, chosen by the teacher at quiz-creation time.' AFTER `link_category`,
  ADD COLUMN IF NOT EXISTS `link_item_id` int(11) DEFAULT NULL COMMENT 'The assessment_items.item_id created for this quiz (highest_score = quiz_max_score) — the same table/shape a manually-added item in Manage Items uses. No FK constraint (see file header).' AFTER `link_term`,
  ADD COLUMN IF NOT EXISTS `grade_sync_status` enum('synced','skipped_manual_review') DEFAULT NULL COMMENT 'Result of the most recent automatic grade sync attempt for this quiz. NULL = not synced yet (no submission recorded).' AFTER `link_item_id`,
  ADD INDEX IF NOT EXISTS `idx_ai_resources_link_item` (`link_item_id`);
