-- 2026_qr_attendance.sql
-- QR-code attendance (Teacher Portal -> Attendance).
--
-- OPTIONAL: the app applies exactly these changes by itself the first time the
-- Attendance page (or Attendance History) is opened -- see attQrEnsureSchema()
-- in includes/attendance_qr_helpers.php. Run this file by hand only if you
-- prefer to prepare the database ahead of time (e.g. on the hosted copy).
-- Purely additive: no table is dropped, no existing row is changed.

-- 1) Exact scan time + how the row was recorded (both NULL for every existing record).
ALTER TABLE `attendance`
  ADD COLUMN `time_in` time DEFAULT NULL COMMENT 'Exact scan time when recorded by QR/LRN scan',
  ADD COLUMN `marked_via` varchar(20) DEFAULT NULL COMMENT 'qr | lrn | session_close (NULL = recorded manually before QR attendance)';

-- 2) One row per class per day: when scanning started, the class start time the
--    scans are compared with, and whether the session is still open.
CREATE TABLE IF NOT EXISTS `attendance_sessions` (
  `session_id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `section_id` int(11) NOT NULL,
  `school_year_id` int(11) NOT NULL,
  `session_date` date NOT NULL,
  `schedule_start` time NOT NULL,
  `start_source` varchar(20) NOT NULL DEFAULT 'schedule',
  `status` enum('Open','Closed') NOT NULL DEFAULT 'Open',
  `started_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `uniq_att_session` (`subject_code`,`section_id`,`session_date`),
  KEY `idx_att_session_teacher` (`teacher_id`,`session_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3) The secret that signs student QR codes (created automatically on first use).
--    Do not delete or change it: every QR code already printed/saved would stop scanning.
CREATE TABLE IF NOT EXISTS `attendance_qr_keys` (
  `key_id` tinyint(4) NOT NULL,
  `secret` char(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`key_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
