-- ============================================================
-- 2026_ai_resource_ai_grading.sql
-- SAFE, ADDITIVE migration on top of 2026_ai_resource_submissions.sql
-- (run that one first). Lets the AI give a FIRST-PASS suggested score on a
-- student's attached answer file (Word/PDF/photo), which the teacher then
-- reviews/edits before it counts as the final grade -- never posted to the
-- gradebook automatically. See includes/ai_resource_submission_helpers.php
-- (aiResourceRunAiCheck / aiResourceMarkReviewed) for the workflow this
-- unlocks, and 2026_ai_quiz_assessment_link.sql for the (already-existing,
-- reused as-is here) ai_generated_resources.link_item_id / link_category /
-- link_term / grade_sync_status columns that let a confirmed score sync
-- into Grade Management and clear the item from Missing Assessments.
--
-- Column changes on `ai_resource_submissions`:
--   - `status` gains a THIRD value, 'AI-Checked', between 'Submitted' and
--     'Reviewed' -- an AI-suggested score+feedback sitting in score/
--     max_score/teacher_feedback as an editable DRAFT the teacher has not
--     yet confirmed. 'Reviewed' still means "final, done" exactly as
--     before; nothing about the existing two values changes meaning.
--   - `answer_source_type`: what kind of content the AI actually graded --
--     'text' (extracted from a .docx, or typed) or 'image' (read directly
--     from a photo/scan via Gemini vision) -- so the review page can show
--     an extra "AI read this from a photo, please double-check" notice
--     only when it's warranted. NULL = never AI-checked yet.
--   - `ai_checked_at`: when the AI check last ran (NULL = never). Distinct
--     from `reviewed_at`, which only gets set once the teacher confirms.
--
-- Safe to run on the live database at any time; safe to re-run (MODIFY
-- COLUMN is naturally idempotent; ADD COLUMN uses IF NOT EXISTS, matching
-- this project's other migrations).
--
-- Run against the `egradepro` database, e.g.:
--   mysql -u root egradepro < database/migrations/2026_ai_resource_ai_grading.sql
-- or import via phpMyAdmin.
-- ============================================================

ALTER TABLE `ai_resource_submissions`
  MODIFY COLUMN `status` enum('Submitted','AI-Checked','Reviewed') NOT NULL DEFAULT 'Submitted' COMMENT 'Submitted = waiting; AI-Checked = AI left a suggested score/feedback as an editable draft, not yet confirmed; Reviewed = teacher confirmed, final.';

ALTER TABLE `ai_resource_submissions`
  ADD COLUMN IF NOT EXISTS `answer_source_type` enum('text','image') DEFAULT NULL COMMENT 'What the AI actually read for its last check: text (extracted from the attached .docx, or a typed note) or image (read directly from a photo/scan via Gemini vision -- less certain, flagged to the teacher). NULL = never AI-checked.' AFTER `answer_file_size`,
  ADD COLUMN IF NOT EXISTS `ai_checked_at` timestamp NULL DEFAULT NULL COMMENT 'When the AI last produced a suggested score for this submission. NULL = never AI-checked. Distinct from reviewed_at, which is only set once the teacher confirms.' AFTER `answer_source_type`;
