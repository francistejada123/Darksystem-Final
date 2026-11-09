-- 2026_teacher_attendance_schedule_snapshot.sql
-- Teacher Attendance (Teacher Portal -> My Attendance, Admin Portal -> Teacher Attendance).
--
-- OPTIONAL: the app applies exactly these changes by itself the first time the
-- My Attendance page, a Time In/Out, or Admin -> Teacher Attendance runs -- see
-- teacherAttendanceEnsureSchema() in includes/attendance_helpers.php. Run this
-- file by hand only if you prefer to prepare the database ahead of time.
--
-- Why: modules/attendance/index.php and admin_view.php read/write the schedule
-- snapshot taken at Time In and the break times, but egradepro.sql never created
-- those columns, so Time In failed ("Unknown column 'expected_time_in'") and the
-- admin attendance query returned nothing.
--
-- Purely additive and NULL-able: no table dropped/renamed, no row changed.
-- Requires MariaDB (ADD COLUMN IF NOT EXISTS); on MySQL let the app apply it.

ALTER TABLE `teacher_attendance`
  ADD COLUMN IF NOT EXISTS `break_start` time DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `break_end` time DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `expected_time_in` time DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `expected_time_out` time DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `expected_break_start` time DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `expected_break_end` time DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `schedule_type` varchar(50) DEFAULT NULL;
