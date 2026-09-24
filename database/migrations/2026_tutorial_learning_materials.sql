-- ============================================================
-- 2026_tutorial_learning_materials.sql
-- SAFE, ADDITIVE migration extending Tutorial Plan "Video" resources into
-- general "Learning Material" resources — a video link, PDF, Word, or
-- PowerPoint file per subject — per the "Improve Subjects Needing
-- Attention" spec.
--
-- Reuses the existing tutorial_plan_items resource_* columns added by
-- 2026_tutorial_pdf_resources.sql (resource_type, resource_file,
-- resource_file_original_name, resource_file_size) instead of creating a
-- parallel set of columns or a new table:
--
--   - resource_type          WIDENED from enum('link','pdf') to
--                             enum('link','pdf','word','powerpoint').
--                             Every existing row is already 'link' or
--                             'pdf' — both remain valid values, so this is
--                             a pure widen with zero data loss. 'link'
--                             keeps meaning exactly what it always has
--                             (a video URL in resource_url) — the UI now
--                             just labels it "Video" instead of assuming
--                             a link is the only kind of material.
--   - resource_mime_type      NEW. Server-detected MIME type of an
--                             uploaded file, stored at upload time — used
--                             to serve the right Content-Type and as a
--                             second validation check. Never trusted from
--                             the browser on its own.
--   - resource_title          NEW. Optional teacher-entered display title
--                             ("Mathematics Reviewer"), separate from
--                             resource_file_original_name (the raw
--                             uploaded filename, kept for display only)
--                             and from recommended_action (the unrelated
--                             "Focus" tip). Falls back to the original
--                             filename, or "Tutorial Video" for a link,
--                             when left empty.
--   - resource_description    NEW. Optional teacher instruction shown
--                             alongside the material — distinct from
--                             recommended_action.
--
-- Existing data is fully preserved: every current row's resource_type
-- stays 'link' or 'pdf' with the exact same meaning, resource_url keeps
-- working exactly as before, and the automatic YouTube watch-progress
-- tracking (status, watch_progress_percentage, last_video_position_seconds,
-- etc.) is completely untouched by this migration — no column it uses is
-- renamed, dropped, or reinterpreted.
--
-- Safe to run on the live database at any time; safe to re-run (the ADD
-- COLUMN IF NOT EXISTS lines are idempotent, and the MODIFY COLUMN simply
-- re-applies the same widened definition on a second run).
--
-- Run against the `egradepro` database, e.g.:
--   mysql -u root egradepro < database/migrations/2026_tutorial_learning_materials.sql
-- or import via phpMyAdmin.
-- ============================================================

ALTER TABLE `tutorial_plan_items`
  MODIFY COLUMN `resource_type` enum('link','pdf','word','powerpoint') NOT NULL DEFAULT 'link' COMMENT 'Which kind of Learning Material this item currently has -- link (video, resource_url), or an uploaded pdf/word/powerpoint file (resource_file). Every existing row is link or pdf, both still valid, unchanged meaning.';

ALTER TABLE `tutorial_plan_items`
  ADD COLUMN IF NOT EXISTS `resource_mime_type` varchar(120) DEFAULT NULL COMMENT 'Server-detected MIME type of the uploaded file, set at upload time -- never trusted from the browser.' AFTER `resource_file_size`,
  ADD COLUMN IF NOT EXISTS `resource_title` varchar(255) DEFAULT NULL COMMENT 'Optional display title for the material; falls back to the uploaded filename (or "Tutorial Video" for a link) when empty.' AFTER `resource_mime_type`,
  ADD COLUMN IF NOT EXISTS `resource_description` text DEFAULT NULL COMMENT 'Optional teacher instruction shown alongside the material.' AFTER `resource_title`;
