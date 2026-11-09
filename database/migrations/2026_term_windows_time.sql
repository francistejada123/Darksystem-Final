-- 2026_term_windows_time.sql
-- TEMPORARY (testing): optional Start Time / End Time per term.
-- Additive only. NULL = whole day (same as the old date-only behavior).
-- The app also adds these columns automatically on first load
-- (gh_ensureTermWindowTimeColumns in includes/grading_helpers.php), so
-- running this file by hand is optional.

ALTER TABLE term_windows
  ADD COLUMN IF NOT EXISTS start_time TIME NULL DEFAULT NULL
    COMMENT 'Optional (temporary): time the term starts on start_date; NULL = 12:00 AM' AFTER start_date,
  ADD COLUMN IF NOT EXISTS end_time TIME NULL DEFAULT NULL
    COMMENT 'Optional (temporary): time the term ends on end_date; NULL = end of day' AFTER end_date;

INSERT INTO system_settings (setting_key, setting_value, setting_group)
VALUES ('schema_ok_term_windows_time_v1', '1', 'system')
ON DUPLICATE KEY UPDATE setting_value = '1';
