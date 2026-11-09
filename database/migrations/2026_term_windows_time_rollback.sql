-- 2026_term_windows_time_rollback.sql
-- Removes the temporary Start Time / End Time from term_windows.
-- Term dates (start_date / end_date) are NOT touched.
-- IMPORTANT: restore the PHP files to the version without the time feature
-- FIRST, otherwise the app will simply add the columns back on next load.

ALTER TABLE term_windows
  DROP COLUMN IF EXISTS start_time,
  DROP COLUMN IF EXISTS end_time;

DELETE FROM system_settings WHERE setting_key = 'schema_ok_term_windows_time_v1';
