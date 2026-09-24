-- ============================================================
-- 2026_assessment_items_clarity.sql
-- SAFE, ADDITIVE migration for Section 5 of the improvement spec
-- ("Improve Teacher Assessment Management Items" — modules/grading/items.php).
--
-- Adds two OPTIONAL, purely descriptive columns to assessment_items:
--   - topic     short topic/title text (e.g. "Fractions"), shown alongside
--               the item name so a teacher can immediately see what a
--               "Quiz 1" or "Seatwork 1" actually covers, without having
--               to cram it into the item name itself.
--   - due_date  optional date, shown on the item card when set.
--
-- Both are nullable and default to NULL for every existing row — nothing
-- about an existing item changes, no existing item_name/highest_score/
-- category/assessment_subtype/assessment_domain/component data is touched,
-- and the official grading computation (Written Works / Performance Tasks
-- / Examinations percentages) NEVER reads these two columns — they are
-- display-only metadata.
--
-- Safe to run on the live database at any time; safe to re-run (uses
-- ADD COLUMN IF NOT EXISTS — supported on MariaDB 10.0.2+ / MySQL
-- 8.0.29+, which covers this project's MariaDB 10.4 server).
--
-- Run against the `egradepro` database, e.g.:
--   mysql -u root egradepro < database/migrations/2026_assessment_items_clarity.sql
-- or import via phpMyAdmin.
-- ============================================================

ALTER TABLE `assessment_items`
  ADD COLUMN IF NOT EXISTS `topic` varchar(150) DEFAULT NULL COMMENT 'Optional short topic/title (e.g. "Fractions") shown alongside item_name -- display only, never used in grade computation.' AFTER `item_name`,
  ADD COLUMN IF NOT EXISTS `due_date` date DEFAULT NULL COMMENT 'Optional due date -- display only, never used in grade computation.' AFTER `highest_score`;
