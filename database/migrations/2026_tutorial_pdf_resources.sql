-- ============================================================
-- 2026_tutorial_pdf_resources.sql
-- SAFE, ADDITIVE migration for Section 6 of the improvement spec
-- ("Tutorial Plan — Support PDF Resources").
--
-- Adds support for a Tutorial Plan item's Learning Resource being either a
-- link (existing resource_url column, UNCHANGED) or an uploaded PDF file,
-- without deleting or renaming resource_url:
--   - resource_type                'link' (default, matches every existing
--                                   row) or 'pdf'.
--   - resource_file                generated, unguessable stored filename
--                                   under static/uploads/tutorial_resources/
--                                   -- NEVER the original uploaded filename.
--   - resource_file_original_name  the original filename, kept ONLY for
--                                   display (e.g. "Show file name") --
--                                   never used as a path.
--   - resource_file_size           file size in bytes, for display.
--
-- Existing YouTube progress tracking is untouched: it is keyed entirely off
-- resource_url via getYouTubeVideoId(), which naturally returns null for a
-- 'pdf' item (resource_url is cleared when a PDF is uploaded) -- so a PDF
-- resource can never be mistaken for a trackable video, and vice versa.
--
-- Safe to run on the live database at any time; safe to re-run (uses
-- ADD COLUMN IF NOT EXISTS -- supported on MariaDB 10.0.2+ / MySQL
-- 8.0.29+, which covers this project's MariaDB 10.4 server).
--
-- Run against the `egradepro` database, e.g.:
--   mysql -u root egradepro < database/migrations/2026_tutorial_pdf_resources.sql
-- or import via phpMyAdmin.
-- ============================================================

ALTER TABLE `tutorial_plan_items`
  ADD COLUMN IF NOT EXISTS `resource_type` enum('link','pdf') NOT NULL DEFAULT 'link' COMMENT 'Which kind of Learning Resource this item currently has -- link (resource_url) or pdf (resource_file). Every existing row defaults to link, matching its existing resource_url.' AFTER `resource_url`,
  ADD COLUMN IF NOT EXISTS `resource_file` varchar(255) DEFAULT NULL COMMENT 'Generated, unguessable stored filename under static/uploads/tutorial_resources/ -- never the original uploaded filename.' AFTER `resource_type`,
  ADD COLUMN IF NOT EXISTS `resource_file_original_name` varchar(255) DEFAULT NULL COMMENT 'Original uploaded filename, for display only -- never used as a path.' AFTER `resource_file`,
  ADD COLUMN IF NOT EXISTS `resource_file_size` int(11) DEFAULT NULL COMMENT 'File size in bytes, for display.' AFTER `resource_file_original_name`;
