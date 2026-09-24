-- ============================================================================
-- EGRADEPRO — CLEAN DATABASE (DepEd Three-Term E-Class Record, SY 2026-2027)
-- ============================================================================
-- Complete schema for a FRESH INSTALLATION.
--
-- This file contains NO Quarter data, NO `grades_quarter_archive_2026` table
-- and NO obsolete global grading-weight settings.
--
-- Grading periods are restricted to 'Term 1', 'Term 2' and 'Term 3' by CHECK
-- constraints on `grades`, `grade_components` and `assessment_items`, so an
-- obsolete Quarter label cannot be stored even if a page is bypassed.
--
-- Component weights are NOT stored here. They come from the verified subject
-- category in includes/grading_helpers.php (DO 015, s. 2026).
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_module` (`module`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=378 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES
(1,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-18 10:25:49'),
(2,9,'teachers','Assigned subject to teacher','Assigned ENG7 to Jay Nunez','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-18 10:26:12'),
(3,9,'teachers','Assigned subject to teacher','Assigned FIL7 to Jay Nunez','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-18 10:26:15'),
(4,9,'teachers','Assigned subject to teacher','Assigned AP9 to Jay Nunez','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-18 10:26:22'),
(5,9,'teachers','Assigned subject to teacher','Assigned ESP9 to Jay Nunez','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-18 10:26:25'),
(6,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-18 10:26:40'),
(7,20,'grading','Saved grades','Saved grades for subject: ENG7, section: 45, term: 1st Quarter','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-18 10:37:52'),
(8,20,'grading','Saved grades','Saved grades for subject: ENG7, section: 45, term: 1st Quarter','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-18 10:38:12'),
(9,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-18 10:38:26'),
(10,NULL,'student','Student Portal Login','Student 110100100005 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-18 10:39:13'),
(11,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-18 10:39:43'),
(12,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-18 10:39:49'),
(13,NULL,'student','Student Portal Login','Student 110100100005 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-18 10:42:26'),
(14,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 06:51:35'),
(15,NULL,'student','Student Portal Login','Student 110100100005 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 06:51:51'),
(16,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 06:52:26'),
(17,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 06:53:21'),
(18,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 06:54:18'),
(19,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 06:55:46'),
(20,9,'teachers','Assigned subject to teacher','Assigned MAPEH7 to Jay Nunez','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 06:58:56'),
(21,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 07:22:53'),
(22,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 07:45:16'),
(23,9,'teachers','Assigned subject to teacher','Assigned MATH7 to Jay Nunez','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 07:47:27'),
(24,9,'teachers','Assigned subject to teacher','Assigned SCI7 to Jay Nunez','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 07:47:35'),
(25,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 07:48:33'),
(26,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 07:49:30'),
(27,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 07:53:39'),
(28,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 07:56:45'),
(29,9,'settings','Updated settings','Updated general settings','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 08:00:34'),
(30,9,'settings','Updated settings','Updated general settings','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 08:01:20'),
(31,9,'Added section','settings','Added C (Grade 8)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 08:22:04'),
(32,9,'Added section','settings','Added 8-C (Grade 8)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 08:22:33'),
(33,9,'enrollment','Created enrollment','Enrolled student ID: 20 for school year: 2','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 08:23:27'),
(34,9,'enrollment','Email failed','Failed to send email to: jaynunez017@gmail.com - Email could not be sent. Mailer Error: SMTP Error: Could not authenticate.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 08:23:44'),
(35,9,'enrollment','Approved enrollment','Approved enrollment ID: 18 for student: Dela Cruz, Mark','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 08:23:44'),
(36,9,'teachers','Assigned subject to teacher','Assigned AP8 to Jay Nunez','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 08:24:25'),
(37,NULL,'auth','Failed Login','Failed login attempt for: teacherjay','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 08:24:45'),
(38,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 08:24:53'),
(39,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 08:25:48'),
(40,9,'teachers','Assigned section adviser','Set Jay Nunez as adviser of 7-C','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 09:18:44'),
(41,9,'teachers','Assigned section adviser','Set Jay Nunez as adviser of 7-B','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 09:19:21'),
(42,9,'teachers','Assigned section adviser','Set Yisoo Evangelista as adviser of 7-A','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 09:32:38'),
(43,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 09:33:15'),
(44,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 09:33:41'),
(45,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 09:34:57'),
(46,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 09:36:09'),
(47,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 09:54:55'),
(48,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 10:04:38'),
(49,9,'enrollment','Created enrollment','Enrolled student ID: 21 for school year: 2','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 10:06:04'),
(50,9,'enrollment','Email failed','Failed to send email to: jaynunez017@gmail.com - Email could not be sent. Mailer Error: SMTP Error: Could not authenticate.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 10:06:25'),
(51,9,'enrollment','Approved enrollment','Approved enrollment ID: 19 for student: Dela Cruz, fra','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 10:06:25'),
(52,NULL,'student','Student Portal Login','Student 110100100005 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 10:56:08'),
(53,NULL,'student','Student Portal Login','Student 110100100005 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 11:39:17'),
(54,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 11:39:32'),
(55,NULL,'student','Student Portal Login','Student 110100100052 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-19 11:57:23'),
(56,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-21 09:09:15'),
(57,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-21 09:11:59'),
(58,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-21 10:03:13'),
(59,NULL,'auth','Failed Login','Failed login attempt for: admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-21 10:04:09'),
(60,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-21 10:04:12'),
(61,NULL,'Online application submitted','online_enrollment','Ref: OE-2026-VR3MD7 — Dela Cruz, Mark (Grade 7)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-21 10:43:00'),
(62,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-21 10:43:21'),
(63,9,'Approved online application','online_enrollment','Ref: OE-2026-VR3MD7 — created student ID 22, enrollment ID 20','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-21 10:44:17'),
(64,9,'enrollment','Email failed','Failed to send email to: yajiimnida@gmail.com - Email could not be sent. Mailer Error: SMTP Error: Could not authenticate.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-21 10:44:45'),
(65,9,'enrollment','Approved enrollment','Approved enrollment ID: 20 for student: Dela Cruz, Mark','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-21 10:44:45'),
(66,NULL,'Online application submitted','online_enrollment','Ref: OE-2026-JRUV3D — Dela Cruz, olegggg (Grade 11)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-21 10:48:10'),
(67,9,'Approved online application','online_enrollment','Ref: OE-2026-JRUV3D — created student ID 23, enrollment ID 21','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-21 10:49:08'),
(68,NULL,'student','Student Portal Login','Student 117281100098 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-21 10:49:52'),
(69,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 01:09:20'),
(70,NULL,'auth','Failed Login','Failed login attempt for: DWADDWADDWD','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 04:57:52'),
(71,NULL,'auth','Failed Login','Failed login attempt for: DWADDWADDWD','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:03:09'),
(72,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:03:24'),
(73,NULL,'auth','Failed Login','Failed login attempt for: asd','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:13:31'),
(74,NULL,'auth','Failed Login','Failed login attempt for: asd','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:13:38'),
(75,NULL,'auth','Failed Login','Failed login attempt for: asdasda','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:13:45'),
(76,NULL,'auth','Failed Login','Failed login attempt for: asd','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:13:50'),
(77,NULL,'auth','Failed Login','Failed login attempt for: asd','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:13:56'),
(78,NULL,'auth','Failed Login','Failed login attempt for: waed','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:14:03'),
(79,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:14:30'),
(80,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:14:33'),
(81,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:14:34'),
(82,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:14:35'),
(83,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:14:36'),
(84,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:14:36'),
(85,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:14:37'),
(86,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:14:38'),
(87,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:14:38'),
(88,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:14:39'),
(89,20,'auth','Password Reset Requested','Reset link emailed','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 05:22:42'),
(90,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 11:29:16'),
(91,NULL,'student','Student Portal Login','Student 110100100005 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 11:29:36'),
(92,NULL,'auth','Failed Login','Failed login attempt for: teacherjay','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 11:30:26'),
(93,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 11:30:54'),
(94,20,'attendance','Attendance Recorded','Section 45, Subject ENG7, Date 2026-08-24','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 11:32:49'),
(95,NULL,'student','Student Portal Login','Student 110100100005 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 11:33:00'),
(96,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 11:35:47'),
(97,20,'attendance','Attendance Recorded','Section 45, Subject ENG7, Date 2026-08-24','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 11:41:29'),
(98,NULL,'student','Student Portal Login','Student 110100100051 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 11:41:39'),
(99,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 11:42:33'),
(100,NULL,'student','Student Portal Login','Student 110100100051 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 11:46:22'),
(101,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 11:48:53'),
(102,20,'attendance','Attendance Recorded','Section 45, Subject ENG7, Date 2026-08-24','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 11:49:30'),
(103,20,'reports','Report Exported','CSV export: at_risk','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 12:00:24'),
(104,20,'reports','Report Exported','CSV export: section_occupancy','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 12:01:38'),
(105,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 12:09:32'),
(106,NULL,'student','Student Portal Login','Student 110100100005 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 12:12:00'),
(107,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 01:11:59'),
(108,9,'logs','Audit Trail Exported','Range 2026-08-18 to 2026-08-25','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 01:30:44'),
(109,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 05:47:28'),
(110,9,'logs','Audit Trail Exported','Range 2026-08-18 to 2026-08-25','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 05:51:26'),
(111,9,'students','Bulk Student Import','Imported 2, skipped 0','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 06:01:05'),
(112,9,'enrollment','Email failed','Failed to send email to: juan.delacruz@example.com - Email could not be sent. Mailer Error: SMTP Error: Could not authenticate.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 06:01:38'),
(113,9,'enrollment','Approved enrollment','Approved enrollment ID: 22 for student: Dela Cruz, Juan','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 06:01:38'),
(114,9,'students','Bulk Student Import','Imported 2, skipped 0','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 06:25:51'),
(115,NULL,'student','Student Portal Login','Student 110100100051 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 06:46:02'),
(116,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 06:47:27'),
(117,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 06:51:35'),
(118,9,'settings','Updated email settings','Updated email configuration','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 06:54:52'),
(119,20,'auth','Password Reset Requested','Reset link emailed','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 06:55:14'),
(120,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 06:56:33'),
(121,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 07:10:35'),
(122,9,'students','Bulk Student Import','Imported 2, skipped 0','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 07:13:11'),
(123,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:33:26'),
(124,9,'teachers','Assigned subject to teacher','Assigned TLE7 to Jay Nunez','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:34:50'),
(125,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:35:08'),
(126,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:35:59'),
(127,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:36:30'),
(128,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:36:35'),
(129,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:36:37'),
(130,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:36:38'),
(131,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:36:38'),
(132,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:36:39'),
(133,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:36:40'),
(134,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:36:41'),
(135,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:36:41'),
(136,NULL,'student','Failed Student Login','Failed LRN attempt: 2131313','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:36:42'),
(137,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:36:58'),
(138,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:39:43'),
(139,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:40:05'),
(140,9,'teachers','Assigned section adviser','Set Jay Nunez as adviser of 7-A','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:40:23'),
(141,9,'enrollment','Created enrollment','Enrolled student ID: 30 for school year: 2','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:52:13'),
(142,9,'enrollment','Email sent','Approval email sent to: imnidayaji@gmail.com','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:52:47'),
(143,9,'enrollment','Approved enrollment','Approved enrollment ID: 28 for student: Nunez, Jay','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:52:47'),
(144,NULL,'student','Student Portal Login','Student 110100100061 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 08:53:21'),
(145,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 11:50:31'),
(146,9,'students','Deleted student','Deleted student: asx, asd (LRN: 110100100056)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 11:52:45'),
(147,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 13:26:31'),
(148,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 00:20:24'),
(149,9,'students','Restored student','Restored student: Nuñeza, Jay (LRN: 117281100039)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 00:21:37'),
(150,9,'students','Restored student','Restored student: asx, asd (LRN: 110100100056)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 00:22:17'),
(151,9,'students','Deleted student','Deleted student: asx, asd (LRN: 110100100056)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 00:23:00'),
(152,9,'students','Deleted student','Deleted student: Nunez, asd (LRN: 110100100058)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 00:33:44'),
(153,9,'students','Restored student','Restored student: asx, asd (LRN: 110100100056)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 00:33:51'),
(154,9,'students','Restored student','Restored student: Nunez, asd (LRN: 110100100058)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 00:34:04'),
(155,9,'enrollment','Approved enrollment','Approved enrollment ID: 23 for student: Reyes, Ana','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 00:34:44'),
(156,9,'enrollment','Email sent','Rejection email sent to: juan.delacruz@example.com','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 00:35:09'),
(157,9,'enrollment','Rejected enrollment','Rejected enrollment ID: 24 for student: Dela Cruz, Alex','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 00:35:09'),
(158,9,'students','Deleted student','Deleted student: Nunez, Oleg (LRN: 110100100005)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 00:36:22'),
(159,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 00:43:05'),
(160,20,'grading','Saved grades','Saved grades for subject: ENG7, section: 45, term: 1st Quarter','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 00:43:49'),
(161,NULL,'student','Student Portal Login','Student 110100100052 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 00:44:03'),
(162,NULL,'student','Student Portal Login','Student 110100100051 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 00:44:23'),
(163,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 00:58:09'),
(164,9,'enrollment','Approved enrollment','Approved enrollment ID: 25 for student: Reyes, Lord','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 01:01:21'),
(165,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 01:02:25'),
(166,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 01:02:48'),
(167,9,'teachers','Assigned subject to teacher','Assigned AP8 to Jay Nunez','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 01:03:14'),
(168,9,'teachers','Assigned section adviser','Set Jay Nunez as adviser of 8-A','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 01:03:22'),
(169,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 01:03:37'),
(170,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 01:04:29'),
(171,9,'teachers','Assigned subject to teacher','Assigned ESP8 to Jay Nunez','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 01:05:22'),
(172,9,'teachers','Assigned subject to teacher','Assigned ENG8 to Jay Nunez','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 01:08:32'),
(173,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 01:08:41'),
(174,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 01:08:57'),
(175,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 05:27:15'),
(176,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 05:27:28'),
(177,20,'grading','Saved grades','Saved grades for subject: ENG7, section: 45, term: 1st Quarter','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 05:28:11'),
(178,20,'tutorial','Generated tutorial plan','Plan #1 for Jhoruis Robles','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 05:53:30'),
(179,NULL,'student','Student Portal Login','Student 110100100052 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 05:56:36'),
(180,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 05:57:52'),
(181,NULL,'student','Student Portal Login','Student 110100100052 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 05:58:17'),
(182,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 05:59:51'),
(183,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:01:31'),
(184,9,'students','Deleted student','Deleted student: Robles, France Jhoruis (LRN: 110100100051)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:02:15'),
(185,9,'students','Restored student','Restored student: Robles, France Jhoruis (LRN: 110100100051)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:02:21'),
(186,9,'reports','Report Exported','CSV export: enrollment_status','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:03:36'),
(187,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:06:26'),
(188,NULL,'student','Student Portal Login','Student 110100100051 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:12:13'),
(189,NULL,'student','Student Portal Login','Student 110100100052 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:12:37'),
(190,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:13:13'),
(191,NULL,'student','Student Portal Login','Student 110100100052 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:14:05'),
(192,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:25:59'),
(193,NULL,'student','Student Portal Login','Student 110100100052 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:31:01'),
(194,NULL,'auth','Failed Login','Failed login attempt for: teacherjay','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:32:48'),
(195,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:32:59'),
(196,NULL,'student','Student Portal Login','Student 110100100052 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:34:19'),
(197,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:38:01'),
(198,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:39:00'),
(199,20,'attendance','Attendance Recorded','Section 47, Subject AP8, Date 2026-08-26','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:53:53'),
(200,NULL,'student','Student Portal Login','Student 110100100058 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:54:03'),
(201,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:54:38'),
(202,20,'attendance','Attendance Recorded','Section 47, Subject AP8, Date 2026-08-26','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:54:50'),
(203,20,'attendance','Attendance Recorded','Section 47, Subject AP8, Date 2026-08-26','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:58:18'),
(204,20,'attendance','Attendance Recorded','Section 47, Subject AP8, Date 2026-08-26','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:58:57'),
(205,NULL,'student','Student Portal Login','Student 110100100052 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:59:11'),
(206,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 06:59:43'),
(207,NULL,'student','Student Portal Login','Student 110100100052 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:00:32'),
(208,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:02:33'),
(209,20,'grading','Saved grades','Saved grades for subject: MAPEH7, section: 45, term: 1st Quarter','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:03:20'),
(210,NULL,'student','Student Portal Login','Student 110100100051 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:03:31'),
(211,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:03:55'),
(212,20,'tutorial','Generated tutorial plan','Plan #2 for France Jhoruis Robles','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:04:19'),
(213,NULL,'student','Student Portal Login','Student 110100100051 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:05:14'),
(214,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:06:06'),
(215,20,'grading','Saved grades','Saved grades for subject: MATH7, section: 45, term: 1st Quarter','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:08:49'),
(216,NULL,'student','Student Portal Login','Student 110100100051 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:09:24'),
(217,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:09:50'),
(218,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:10:41'),
(219,20,'grading','Saved grades','Saved grades for subject: AP9, section: 49, term: 1st Quarter','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:11:22'),
(220,20,'tutorial','Generated tutorial plan','Plan #3 for Jqwd Nunez','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:11:45'),
(221,NULL,'auth','Failed Login','Failed login attempt for: teacherjay','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:12:29'),
(222,NULL,'auth','Failed Login','Failed login attempt for: teacherjay','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:12:36'),
(223,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:12:46'),
(224,NULL,'student','Student Portal Login','Student 110100100052 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:14:05'),
(225,NULL,'student','Student Portal Login','Student 110100100052 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:14:17'),
(226,NULL,'student','Student Portal Login','Student 110100100051 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:14:29'),
(227,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:15:28'),
(228,NULL,'student','Student Portal Login','Student 110100100051 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:16:25'),
(229,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:18:15'),
(230,NULL,'student','Student Portal Login','Student 110100100051 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:19:51'),
(231,NULL,'auth','Failed Login','Failed login attempt for: teacherjay','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:21:50'),
(232,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:22:05'),
(233,20,'grading','Saved grades','Saved grades for subject: FIL7, section: 45, term: 1st Quarter','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:23:02'),
(234,20,'grading','Saved grades','Saved grades for subject: TLE7, section: 45, term: 1st Quarter','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:25:15'),
(235,NULL,'student','Student Portal Login','Student 110100100051 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:25:49'),
(236,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:26:22'),
(237,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:34:08'),
(238,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:44:43'),
(239,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:48:29'),
(240,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 07:49:58'),
(241,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 08:09:32'),
(242,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 08:58:09'),
(243,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 09:05:12'),
(244,NULL,'student','Student Portal Login','Student 110100100052 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 09:07:30'),
(245,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 09:23:42'),
(246,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 09:25:42'),
(247,20,'auth','Password Reset Requested','Reset link emailed','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 09:28:04'),
(248,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 11:16:47'),
(249,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-26 11:17:02'),
(250,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 00:13:48'),
(251,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 00:18:45'),
(252,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 01:04:13'),
(253,NULL,'auth','Failed Login','Failed login attempt for: teacherjay','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:19:16'),
(254,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:20:03'),
(255,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:26:03'),
(256,NULL,'student','Student Portal Login','Student 110100100051 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:26:30'),
(257,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:28:13'),
(258,20,'grading','Saved grades','Saved grades for subject: SCI7, section: 45, term: 1st Quarter','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:36:46'),
(259,NULL,'student','Student Portal Login','Student 110100100051 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:37:02'),
(260,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:37:37'),
(261,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:52:32'),
(262,9,'enrollment','New Enrollment','Walk-in enrollment created for asx, asd (student ID 31)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:53:21'),
(263,9,'enrollment','Email sent','Approval email sent to: alexanderblancaver5@gmail.com','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:53:36'),
(264,9,'enrollment','Approved enrollment','Approved enrollment ID: 29 for student: asx, asd','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:53:36'),
(265,9,'teachers','Added teacher','Added teacher: asd, as (Filipino)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:55:33'),
(266,9,'teachers','Assigned subject to teacher','Assigned FIL10 to as asd','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:56:00'),
(267,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:58:24'),
(268,21,'grading','Saved grades','Saved grades for subject: FIL10, section: 103, term: 1st Quarter','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:58:47'),
(269,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:59:10'),
(270,21,'tutorial','Generated tutorial plan','Plan #4 for asd asx','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 07:59:32'),
(271,NULL,'student','Student Portal Login','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:00:08'),
(272,NULL,'auth','Failed Login','Failed login attempt for: alexanderblancaver5@gmail.com','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:00:57'),
(273,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:01:04'),
(274,NULL,'student','Student Portal Login','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:01:32'),
(275,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:02:06'),
(276,NULL,'student','Student Portal Login','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:02:49'),
(277,NULL,'student','Student Portal Login','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:11:36'),
(278,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:14:46'),
(279,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:50:18'),
(280,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:50:35'),
(281,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:51:20'),
(282,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:51:38'),
(283,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:53:35'),
(284,NULL,'auth','Failed Login','Failed login attempt for: admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:53:57'),
(285,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:54:06'),
(286,9,'teachers','Assigned subject to teacher','Assigned ESP10 to as asd','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:55:04'),
(287,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:55:28'),
(288,21,'grading','Saved grades','Saved grades for subject: ESP10, section: 103, term: 1st Quarter','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:55:52'),
(289,NULL,'student','Student Portal Login','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 08:56:58'),
(290,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 10:31:09'),
(291,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 10:43:18'),
(292,20,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 10:58:25'),
(293,21,'auth','Password Reset Requested','Reset link emailed','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 11:06:49'),
(294,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 11:08:45'),
(295,NULL,'student','Student Portal Login','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 11:09:22'),
(296,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 11:12:54'),
(297,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 11:33:21'),
(298,9,'reports','Report Exported','CSV export: all','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 12:01:17'),
(299,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 05:30:10'),
(300,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 05:43:30'),
(301,9,'enrollment','New Enrollment','Walk-in enrollment created for asx, asd (student ID 32)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 05:47:31'),
(302,9,'enrollment','Email sent','Approval email sent to: alexanderblancaver5@gmail.com','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 05:47:41'),
(303,9,'enrollment','Approved enrollment','Approved enrollment ID: 30 for student: asx, asd','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 05:47:41'),
(304,NULL,'student','Student Portal Login','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:13:51'),
(305,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:14:28'),
(306,21,'grading','Saved grades','Saved grades for subject: FIL10, section: 103, term: 1st Quarter','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:15:21'),
(307,NULL,'student','Student Portal Login','Student 111111111111 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:15:28'),
(308,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:15:54'),
(309,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:16:24'),
(310,NULL,'auth','Failed Login','Failed login attempt for: alexanderblancaver5@gmail.coma','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:17:41'),
(311,NULL,'auth','Failed Login','Failed login attempt for: alexanderblancaver5@gmail.com','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:17:51'),
(312,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:17:57'),
(313,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:18:15'),
(314,9,'teachers','Assigned subject to teacher','Assigned SCI10 to as asd for section 51','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:50:03'),
(315,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:50:16'),
(316,21,'grading','Saved grades','Saved grades for subject: SCI10, section: 51, term: 1st Quarter','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:50:40'),
(317,NULL,'student','Failed Student Login','Failed LRN attempt: 11010010012','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:50:54'),
(318,NULL,'student','Failed Student Login','Failed LRN attempt: 11010010012','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:50:59'),
(319,NULL,'student','Failed Student Login','Failed LRN attempt: 11010010012','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:51:05'),
(320,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:51:17'),
(321,9,'teachers','Assigned subject to teacher','Assigned SCI10 to as asd for section 103','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:54:14'),
(322,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:54:22'),
(323,21,'grading','Saved grades','Saved grades for subject: SCI10, section: 103, term: 1st Quarter','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:54:49'),
(324,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:55:00'),
(325,NULL,'student','Student Portal Login','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:55:31'),
(326,NULL,'student','Student Portal Login','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:55:57'),
(327,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 08:56:10'),
(328,NULL,'student','Student Portal Login','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:30:26'),
(329,NULL,'auth','Failed Login','Failed login attempt for: alexanderblancaver5@gmail.com','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:30:39'),
(330,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:30:44'),
(331,21,'tutorial','Generated tutorial plan','Plan #5 for asd asx','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:30:52'),
(332,NULL,'student','Failed Student Login','Failed LRN attempt: 00000000000','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:31:05'),
(333,NULL,'student','Failed Student Login','Failed LRN attempt: 00','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:31:06'),
(334,NULL,'student','Student Portal Login','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:31:11'),
(335,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:39:03'),
(336,NULL,'student','Failed Student Login','Failed LRN attempt: 00000000000','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:40:20'),
(337,NULL,'student','Failed Student Login','Failed LRN attempt: 00000000000','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:40:23'),
(338,NULL,'student','Student Portal Login','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:40:27'),
(339,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:40:55'),
(340,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:41:44'),
(341,NULL,'student','Student Portal Login','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:43:26'),
(342,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:44:35'),
(343,NULL,'student','Failed Student Login','Failed LRN attempt: 00000000000','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:45:35'),
(344,NULL,'student','Student Portal Login','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:45:39'),
(345,NULL,'student','Student Portal Login','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:50:54'),
(346,NULL,'student','Student Portal Login','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 09:55:15'),
(347,21,'attendance','Teacher Time In','Clocked in at 6:09 PM','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 10:09:16'),
(348,21,'attendance','Teacher Time Out','Clocked out at 6:09 PM','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 10:09:24'),
(349,NULL,'auth','Failed Login','Failed login attempt for: admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 10:09:46'),
(350,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 10:09:53'),
(351,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 11:58:37'),
(352,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 11:59:11'),
(353,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 12:00:21'),
(354,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 13:22:36'),
(355,21,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 13:22:51'),
(356,21,'grading','Saved grades','Saved grades for subject: SCI10, section: 51, term: Term 1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 13:26:02'),
(357,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 14:00:51'),
(358,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 14:26:50'),
(359,9,'auth','Login','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 14:40:20'),
(360,9,'Login','auth','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 15:18:46'),
(361,21,'Login','auth','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 15:37:10'),
(362,NULL,'Portal login','student','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 15:37:58'),
(363,21,'Login','auth','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 15:38:57'),
(364,NULL,'Portal login','student','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 15:39:35'),
(365,NULL,'Portal login','student','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 15:47:56'),
(366,21,'Login','auth','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 15:55:01'),
(367,9,'Login','auth','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-28 16:13:35'),
(368,9,'Login','auth','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-29 05:21:21'),
(369,21,'Login','auth','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-29 05:24:17'),
(370,9,'Login','auth','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-29 05:31:30'),
(371,9,'Login','auth','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-29 06:12:31'),
(372,21,'Login','auth','User logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-29 06:25:03'),
(373,NULL,'Portal login','student','Student 000000000000 logged in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-29 06:26:33'),
(374,20,'grading','Saved grades','Saved grades for subject: MATH7, section: 45, term: Term 1','127.0.0.1','curl/8.5.0','2026-08-29 09:05:08'),
(375,20,'grading','Saved grades','Saved grades for subject: MAPEH7, section: 45, term: Term 1','127.0.0.1','curl/8.5.0','2026-08-29 09:05:08'),
(376,20,'grading','Saved grades','Saved grades for subject: ESP7, section: 45, term: Term 1','127.0.0.1','curl/8.5.0','2026-08-29 09:05:08'),
(377,NULL,'Portal login','student','Student 110100100051 logged in','127.0.0.1','curl/8.5.0','2026-08-29 09:05:43');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `ai_analysis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_analysis` (
  `analysis_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `school_year_id` int(11) NOT NULL,
  `term` varchar(20) NOT NULL,
  `performance_score` decimal(5,2) DEFAULT 0.00,
  `risk_level` enum('Low','Medium','High') DEFAULT 'Low',
  `analysis_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`analysis_data`)),
  `recommendations` text DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`analysis_id`),
  KEY `school_year_id` (`school_year_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_risk` (`risk_level`),
  CONSTRAINT `ai_analysis_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `ai_analysis_ibfk_2` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`sy_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `ai_analysis` WRITE;
/*!40000 ALTER TABLE `ai_analysis` DISABLE KEYS */;
/*!40000 ALTER TABLE `ai_analysis` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `ai_chat_recommendations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_chat_recommendations` (
  `recommendation_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`recommendation_id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `ai_chat_recommendations` WRITE;
/*!40000 ALTER TABLE `ai_chat_recommendations` DISABLE KEYS */;
INSERT INTO `ai_chat_recommendations` VALUES
(1,7,20,'Hi! I\'m the AI Assistant for Jhoruis Robles. Based on current grades, here are subjects that need attention:\n\n- English 7 (60.00%): Read short stories daily, keep a vocabulary journal, and practice writing short paragraphs with feedback.\n\nYou can ask me about a study schedule, parent involvement, or motivation strategies for this student.','2026-08-21 09:09:22'),
(2,7,20,'A few general tips: give extra review time to English 7, encourage consistent attendance, and celebrate small improvements. You can also ask me about \'study schedule\', \'parent involvement\', or \'motivation\'.','2026-08-21 09:10:32'),
(3,7,20,'Suggested weekly study plan: 30-45 minutes daily per weak subject, review notes within 24 hours after class, and a short weekly quiz to check retention.','2026-08-21 09:10:35'),
(4,7,20,'Consider scheduling a parent-teacher conference to discuss performance, agree on a home study routine, and set up regular progress check-ins (weekly or bi-weekly).','2026-08-21 09:10:35'),
(5,7,20,'Try small, achievable goals with positive reinforcement, peer study groups, and connect lessons to the student\'s interests to boost engagement.','2026-08-21 09:10:36'),
(6,7,20,'Hi! I\'m the AI Assistant for Jhoruis Robles. Based on current grades, here are subjects that need attention:\n\n- English 7 (62.00%): Read short stories daily, keep a vocabulary journal, and practice writing short paragraphs with feedback.\n\nYou can ask me about a study schedule, parent involvement, or motivation strategies for this student.','2026-08-26 05:28:21'),
(7,9,20,'Hi! I\'m the AI Assistant for Jqwd Nunez. Based on current grades, here are subjects that need attention:\n\n- Araling Panlipunan 9 (61.00%): Use timelines and concept maps to connect events, and review with flashcards before quizzes.\n\nYou can ask me about a study schedule, parent involvement, or motivation strategies for this student.','2026-08-26 07:28:37'),
(8,9,20,'Try small, achievable goals with positive reinforcement, peer study groups, and connect lessons to the student\'s interests to boost engagement.','2026-08-26 07:28:43'),
(9,31,21,'Hi! I\'m the AI Assistant for asd asx. Based on current grades, here are subjects that need attention:\n\n- Filipino 10 (63.00%): Magbasa ng maiikling kwento araw-araw at magsanay sa pagsulat ng maikling sanaysay o journal.\n\nYou can ask me about a study schedule, parent involvement, or motivation strategies for this student.','2026-08-27 07:59:23');
/*!40000 ALTER TABLE `ai_chat_recommendations` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `application_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `application_documents` (
  `document_id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `doc_key` varchar(50) NOT NULL,
  `doc_label` varchar(150) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `file_size` int(11) DEFAULT 0,
  `is_verified` tinyint(1) DEFAULT 0,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`document_id`),
  KEY `idx_app` (`application_id`),
  CONSTRAINT `application_documents_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `online_applications` (`application_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `application_documents` WRITE;
/*!40000 ALTER TABLE `application_documents` DISABLE KEYS */;
INSERT INTO `application_documents` VALUES
(1,1,'form138','Form 138 — Grade 6 Report Card','33.png','static/uploads/applications/OE-2026-VR3MD7_form138_1787308980.png','image/png',292933,0,'2026-08-21 10:43:00'),
(2,1,'completion_cert','Certificate of Completion (Elementary)','33.png','static/uploads/applications/OE-2026-VR3MD7_completion_cert_1787308980.png','image/png',292933,0,'2026-08-21 10:43:00'),
(3,1,'psa','PSA / NSO Birth Certificate','33.png','static/uploads/applications/OE-2026-VR3MD7_psa_1787308980.png','image/png',292933,0,'2026-08-21 10:43:00'),
(4,1,'good_moral','Certificate of Good Moral Character','33.png','static/uploads/applications/OE-2026-VR3MD7_good_moral_1787308980.png','image/png',292933,0,'2026-08-21 10:43:00'),
(5,1,'id_picture','2x2 ID Picture (white background)','33.png','static/uploads/applications/OE-2026-VR3MD7_id_picture_1787308980.png','image/png',292933,0,'2026-08-21 10:43:00'),
(6,1,'form137','Form 137 (Permanent Record)','33.png','static/uploads/applications/OE-2026-VR3MD7_form137_1787308980.png','image/png',292933,0,'2026-08-21 10:43:00'),
(7,1,'brgy_cert','Barangay Certificate of Residency','33.png','static/uploads/applications/OE-2026-VR3MD7_brgy_cert_1787308980.png','image/png',292933,0,'2026-08-21 10:43:00'),
(8,2,'form138','Form 138 — Grade 10 Report Card','33.png','static/uploads/applications/OE-2026-JRUV3D_form138_1787309290.png','image/png',292933,0,'2026-08-21 10:48:10'),
(9,2,'jhs_completion','JHS Certificate of Completion','33.png','static/uploads/applications/OE-2026-JRUV3D_jhs_completion_1787309290.png','image/png',292933,0,'2026-08-21 10:48:10'),
(10,2,'psa','PSA / NSO Birth Certificate','33.png','static/uploads/applications/OE-2026-JRUV3D_psa_1787309290.png','image/png',292933,0,'2026-08-21 10:48:10'),
(11,2,'good_moral','Certificate of Good Moral Character','33.png','static/uploads/applications/OE-2026-JRUV3D_good_moral_1787309290.png','image/png',292933,0,'2026-08-21 10:48:10'),
(12,2,'id_picture','2x2 ID Picture (white background)','33.png','static/uploads/applications/OE-2026-JRUV3D_id_picture_1787309290.png','image/png',292933,0,'2026-08-21 10:48:10'),
(13,2,'esc_qvr','ESC Certificate / QVR Voucher Number','33.png','static/uploads/applications/OE-2026-JRUV3D_esc_qvr_1787309290.png','image/png',292933,0,'2026-08-21 10:48:10'),
(14,2,'sf10_jhs','SF10 / Form 137 (Junior High)','33.png','static/uploads/applications/OE-2026-JRUV3D_sf10_jhs_1787309290.png','image/png',292933,0,'2026-08-21 10:48:10');
/*!40000 ALTER TABLE `application_documents` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `assessment_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `assessment_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `section_id` int(11) NOT NULL,
  `school_year_id` int(11) NOT NULL,
  `term` varchar(20) NOT NULL,
  `category` enum('WW','PT','QA') NOT NULL,
  `assessment_subtype` varchar(5) DEFAULT NULL COMMENT 'ST1 | ST2 | TE â€” Examinations sub-component (30/30/40). NULL for WW/PT.',
  `assessment_domain` varchar(20) DEFAULT NULL COMMENT 'Cognitive | Affective | Behavioral â€” GMRC / Values Education domains only.',
  `component` varchar(30) DEFAULT NULL COMMENT 'Component of a multi-component learning area (Music/Arts/Physical Education/Health). NULL otherwise.',
  `item_name` varchar(100) NOT NULL,
  `highest_score` decimal(6,2) NOT NULL DEFAULT 100.00,
  `item_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`item_id`),
  KEY `subject_code` (`subject_code`),
  KEY `section_id` (`section_id`),
  KEY `school_year_id` (`school_year_id`),
  KEY `idx_lookup` (`teacher_id`,`subject_code`,`section_id`,`school_year_id`,`term`,`category`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `assessment_items_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `assessment_items_ibfk_2` FOREIGN KEY (`subject_code`) REFERENCES `subjects` (`subject_code`) ON DELETE CASCADE,
  CONSTRAINT `assessment_items_ibfk_3` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`) ON DELETE CASCADE,
  CONSTRAINT `assessment_items_ibfk_4` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`sy_id`) ON DELETE CASCADE,
  CONSTRAINT `chk_items_term` CHECK (`term` in ('Term 1','Term 2','Term 3')),
  CONSTRAINT `chk_items_hps` CHECK (`highest_score` > 0)
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `assessment_items` WRITE;
/*!40000 ALTER TABLE `assessment_items` DISABLE KEYS */;
INSERT INTO `assessment_items` VALUES
(5,20,'MAPEH7',45,2,'Term 1','WW',NULL,NULL,'Music and Arts','Music WW',100.00,1,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(6,20,'MAPEH7',45,2,'Term 1','PT',NULL,NULL,'Music and Arts','Music PT',100.00,2,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(7,20,'MAPEH7',45,2,'Term 1','QA','ST1',NULL,'Music and Arts','Music QA ST1',100.00,3,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(8,20,'MAPEH7',45,2,'Term 1','QA','ST2',NULL,'Music and Arts','Music QA ST2',100.00,4,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(9,20,'MAPEH7',45,2,'Term 1','QA','TE',NULL,'Music and Arts','Music QA TE',100.00,5,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(10,20,'MAPEH7',45,2,'Term 1','WW',NULL,NULL,'Music and Arts','Arts WW',100.00,6,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(11,20,'MAPEH7',45,2,'Term 1','PT',NULL,NULL,'Music and Arts','Arts PT',100.00,7,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(12,20,'MAPEH7',45,2,'Term 1','QA','ST1',NULL,'Music and Arts','Arts QA ST1',100.00,8,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(13,20,'MAPEH7',45,2,'Term 1','QA','ST2',NULL,'Music and Arts','Arts QA ST2',100.00,9,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(14,20,'MAPEH7',45,2,'Term 1','QA','TE',NULL,'Music and Arts','Arts QA TE',100.00,10,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(15,20,'MAPEH7',45,2,'Term 1','WW',NULL,NULL,'PE and Health','Physical Education WW',100.00,11,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(16,20,'MAPEH7',45,2,'Term 1','PT',NULL,NULL,'PE and Health','Physical Education PT',100.00,12,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(17,20,'MAPEH7',45,2,'Term 1','QA','ST1',NULL,'PE and Health','Physical Education QA ST1',100.00,13,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(18,20,'MAPEH7',45,2,'Term 1','QA','ST2',NULL,'PE and Health','Physical Education QA ST2',100.00,14,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(19,20,'MAPEH7',45,2,'Term 1','QA','TE',NULL,'PE and Health','Physical Education QA TE',100.00,15,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(20,20,'MAPEH7',45,2,'Term 1','WW',NULL,NULL,'PE and Health','Health WW',100.00,16,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(21,20,'MAPEH7',45,2,'Term 1','PT',NULL,NULL,'PE and Health','Health PT',100.00,17,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(22,20,'MAPEH7',45,2,'Term 1','QA','ST1',NULL,'PE and Health','Health QA ST1',100.00,18,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(23,20,'MAPEH7',45,2,'Term 1','QA','ST2',NULL,'PE and Health','Health QA ST2',100.00,19,1,'2026-08-29 13:53:34','2026-08-30 06:43:40'),
(24,20,'MAPEH7',45,2,'Term 1','QA','TE',NULL,'PE and Health','Health QA TE',100.00,20,1,'2026-08-29 13:53:34','2026-08-30 06:43:40');
/*!40000 ALTER TABLE `assessment_items` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `assessment_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `assessment_scores` (
  `score_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `raw_score` decimal(6,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`score_id`),
  UNIQUE KEY `unique_score` (`item_id`,`student_id`),
  KEY `idx_student` (`student_id`),
  CONSTRAINT `assessment_scores_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `assessment_items` (`item_id`) ON DELETE CASCADE,
  CONSTRAINT `assessment_scores_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `chk_scores_nonneg` CHECK (`raw_score` is null or `raw_score` >= 0)
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `assessment_scores` WRITE;
/*!40000 ALTER TABLE `assessment_scores` DISABLE KEYS */;
INSERT INTO `assessment_scores` VALUES
(4,5,5,90.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(5,5,7,75.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(6,6,5,90.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(7,6,7,75.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(8,7,5,90.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(9,7,7,75.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(10,8,5,90.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(11,8,7,75.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(12,9,5,90.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(13,9,7,75.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(14,10,5,88.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(15,10,7,73.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(16,11,5,88.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(17,11,7,73.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(18,12,5,88.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(19,12,7,73.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(20,13,5,88.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(21,13,7,73.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(22,14,5,88.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(23,14,7,73.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(24,15,5,85.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(25,15,7,70.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(26,16,5,85.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(27,16,7,70.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(28,17,5,85.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(29,17,7,70.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(30,18,5,85.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(31,18,7,70.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(32,19,5,85.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(33,19,7,70.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(34,20,5,62.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(35,20,7,47.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(36,21,5,62.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(37,21,7,47.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(38,22,5,62.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(39,22,7,47.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(40,23,5,62.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(41,23,7,47.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(42,24,5,62.00,'2026-08-29 13:53:34','2026-08-29 13:53:34'),
(43,24,7,47.00,'2026-08-29 13:53:34','2026-08-29 13:53:34');
/*!40000 ALTER TABLE `assessment_scores` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance` (
  `attendance_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `section_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `status` enum('Present','Absent','Late','Excused') DEFAULT 'Present',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`attendance_id`),
  UNIQUE KEY `uniq_attendance` (`student_id`,`subject_code`,`section_id`,`date`),
  KEY `section_id` (`section_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_subject` (`subject_code`),
  KEY `idx_date` (`date`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`subject_code`) REFERENCES `subjects` (`subject_code`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_3` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
INSERT INTO `attendance` VALUES
(1,18,'ENG7',45,'2026-08-24','Present','','2026-08-24 11:32:49','2026-08-24 11:32:49'),
(2,5,'ENG7',45,'2026-08-24','Present','','2026-08-24 11:32:49','2026-08-24 11:32:49'),
(3,7,'ENG7',45,'2026-08-24','Present','','2026-08-24 11:32:49','2026-08-24 11:32:49'),
(4,10,'AP8',47,'2026-08-26','Present','','2026-08-26 06:53:53','2026-08-26 06:53:53'),
(5,20,'AP8',47,'2026-08-26','Absent','','2026-08-26 06:53:53','2026-08-26 06:54:50'),
(6,8,'AP8',47,'2026-08-26','Present','','2026-08-26 06:53:53','2026-08-26 06:53:53');
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `enrollment_requirements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `enrollment_requirements` (
  `requirement_id` int(11) NOT NULL AUTO_INCREMENT,
  `enrollment_id` int(11) NOT NULL,
  `form_138` tinyint(1) DEFAULT 0,
  `form_137` tinyint(1) DEFAULT 0,
  `psa_birth_cert` tinyint(1) DEFAULT 0,
  `good_moral` tinyint(1) DEFAULT 0,
  `id_picture` tinyint(1) DEFAULT 0,
  `submitted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`requirement_id`),
  KEY `idx_enrollment` (`enrollment_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `enrollment_requirements` WRITE;
/*!40000 ALTER TABLE `enrollment_requirements` DISABLE KEYS */;
INSERT INTO `enrollment_requirements` VALUES
(1,20,0,0,0,0,0,'2026-08-21 18:44:17'),
(2,21,0,0,0,0,0,'2026-08-21 18:49:08'),
(3,22,0,0,0,0,0,'2026-08-25 14:01:05'),
(4,23,0,0,0,0,0,'2026-08-25 14:01:05'),
(5,24,0,0,0,0,0,'2026-08-25 14:25:51'),
(6,25,0,0,0,0,0,'2026-08-25 14:25:51'),
(7,26,0,0,0,0,0,'2026-08-25 15:13:11'),
(8,27,0,0,0,0,0,'2026-08-25 15:13:11'),
(9,28,0,0,0,0,0,'2026-08-25 16:52:13'),
(10,29,0,0,0,0,0,'2026-08-27 15:53:21'),
(11,30,0,0,0,0,0,'2026-08-28 13:47:31');
/*!40000 ALTER TABLE `enrollment_requirements` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `enrollments` (
  `enrollment_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `school_year_id` int(11) NOT NULL,
  `grade_level` varchar(20) NOT NULL,
  `strand` varchar(50) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `enrollment_date` date NOT NULL,
  `enrollment_status` enum('Pending','Approved','Rejected','Transferred','Graduated') DEFAULT 'Pending',
  `processed_by` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`enrollment_id`),
  UNIQUE KEY `unique_enrollment` (`student_id`,`school_year_id`),
  KEY `section_id` (`section_id`),
  KEY `processed_by` (`processed_by`),
  KEY `idx_student` (`student_id`),
  KEY `idx_sy` (`school_year_id`),
  KEY `idx_status` (`enrollment_status`),
  KEY `idx_enrollments_date` (`enrollment_date`),
  CONSTRAINT `enrollments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `enrollments_ibfk_2` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`sy_id`) ON DELETE CASCADE,
  CONSTRAINT `enrollments_ibfk_3` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`) ON DELETE SET NULL,
  CONSTRAINT `enrollments_ibfk_4` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `enrollments` WRITE;
/*!40000 ALTER TABLE `enrollments` DISABLE KEYS */;
INSERT INTO `enrollments` VALUES
(7,1,2,'Grade 11','HUMSS',95,'2026-08-16','Approved',9,NULL,'2026-08-16 07:55:31','2026-08-16 07:55:45'),
(8,5,2,'Grade 7','',45,'2026-08-16','Approved',9,NULL,'2026-08-16 08:15:39','2026-08-16 08:15:58'),
(10,7,2,'Grade 7','',45,'2026-08-16','Approved',9,NULL,'2026-08-16 08:48:15','2026-08-16 08:48:19'),
(11,8,2,'Grade 8','',47,'2026-08-16','Approved',9,NULL,'2026-08-16 10:40:23','2026-08-16 10:40:28'),
(12,9,2,'Grade 9','',49,'2026-08-16','Approved',9,NULL,'2026-08-16 10:56:28','2026-08-16 10:57:38'),
(13,10,2,'Grade 8','',47,'2026-08-16','Approved',9,NULL,'2026-08-16 10:57:24','2026-08-16 10:57:51'),
(14,15,2,'Grade 10','',51,'2026-08-16','Approved',9,NULL,'2026-08-16 11:01:13','2026-08-16 11:01:21'),
(15,16,2,'Grade 11','GAS',89,'2026-08-16','Approved',9,NULL,'2026-08-16 11:12:34','2026-08-16 11:12:44'),
(16,18,2,'Grade 7','',45,'2026-08-16','Approved',9,NULL,'2026-08-16 11:18:30','2026-08-16 11:18:37'),
(17,19,2,'Grade 9','',50,'2026-08-16','Approved',9,NULL,'2026-08-16 11:26:55','2026-08-16 11:27:00'),
(18,20,2,'Grade 8','',47,'2026-08-19','Approved',9,NULL,'2026-08-19 08:23:27','2026-08-19 08:23:41'),
(19,21,2,'Grade 11','GAS',91,'2026-08-19','Approved',9,NULL,'2026-08-19 10:06:04','2026-08-19 10:06:22'),
(20,22,2,'Grade 7',NULL,46,'2026-08-21','Approved',9,'Online application OE-2026-VR3MD7','2026-08-21 10:44:17','2026-08-21 10:44:42'),
(21,23,2,'Grade 11','HUMSS',97,'2026-08-21','Pending',9,'Online application OE-2026-JRUV3D','2026-08-21 10:49:08','2026-08-21 10:49:08'),
(22,24,2,'Grade 7',NULL,NULL,'2026-08-25','Approved',9,NULL,'2026-08-25 06:01:05','2026-08-25 06:01:35'),
(23,25,2,'Grade 11','HUMSS',NULL,'2026-08-25','Approved',9,NULL,'2026-08-25 06:01:05','2026-08-26 00:34:44'),
(24,26,2,'Grade 7',NULL,46,'2026-08-25','Rejected',9,NULL,'2026-08-25 06:25:51','2026-08-26 00:35:04'),
(25,27,2,'Grade 11','HUMSS',95,'2026-08-25','Approved',9,NULL,'2026-08-25 06:25:51','2026-08-26 01:01:21'),
(26,28,2,'Grade 7',NULL,44,'2026-08-25','Pending',9,NULL,'2026-08-25 07:13:11','2026-08-25 07:13:11'),
(27,29,2,'Grade 11','HUMSS',97,'2026-08-25','Pending',9,NULL,'2026-08-25 07:13:11','2026-08-25 07:13:11'),
(28,30,2,'Grade 7','',46,'2026-08-25','Approved',9,NULL,'2026-08-25 08:52:13','2026-08-25 08:52:40'),
(29,31,2,'Grade 10',NULL,103,'2026-08-27','Approved',9,'Walk-in enrollment','2026-08-27 07:53:21','2026-08-27 07:53:31'),
(30,32,2,'Grade 10',NULL,103,'2026-08-28','Approved',9,'Walk-in enrollment','2026-08-28 05:47:31','2026-08-28 05:47:37');
/*!40000 ALTER TABLE `enrollments` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `grade_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `grade_components` (
  `gc_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `section_id` int(11) NOT NULL,
  `school_year_id` int(11) NOT NULL,
  `term` varchar(20) NOT NULL,
  `component` varchar(30) NOT NULL,
  `written_work` decimal(5,2) DEFAULT NULL,
  `performance_task` decimal(5,2) DEFAULT NULL,
  `examinations` decimal(5,2) DEFAULT NULL,
  `initial_grade` decimal(5,2) DEFAULT NULL,
  `transmuted_grade` decimal(5,2) DEFAULT NULL,
  `remarks` varchar(50) DEFAULT NULL,
  `encoded_by` int(11) DEFAULT NULL,
  `encoded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`gc_id`),
  UNIQUE KEY `unique_grade_component` (`student_id`,`subject_code`,`section_id`,`school_year_id`,`term`,`component`),
  KEY `idx_gc_lookup` (`subject_code`,`section_id`,`school_year_id`,`term`),
  CONSTRAINT `chk_gc_term` CHECK (`term` in ('Term 1','Term 2','Term 3')),
  CONSTRAINT `chk_gc_ranges` CHECK ((`written_work` is null or `written_work` between 0 and 100) and (`performance_task` is null or `performance_task` between 0 and 100) and (`examinations` is null or `examinations` between 0 and 100) and (`initial_grade` is null or `initial_grade` between 0 and 100) and (`transmuted_grade` is null or `transmuted_grade` between 0 and 100))
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `grade_components` WRITE;
/*!40000 ALTER TABLE `grade_components` DISABLE KEYS */;
/*!40000 ALTER TABLE `grade_components` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `grades` (
  `grade_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `school_year_id` int(11) NOT NULL,
  `term` varchar(20) NOT NULL,
  `written_work` decimal(5,2) DEFAULT 0.00,
  `performance_task` decimal(5,2) DEFAULT 0.00,
  `examinations` decimal(5,2) DEFAULT 0.00 COMMENT 'EX — Examinations percentage (ST1 30% + ST2 30% + Term Exam 40%).',
  `initial_grade` decimal(5,2) DEFAULT 0.00,
  `transmuted_grade` decimal(5,2) DEFAULT 0.00,
  `final_grade` decimal(5,2) DEFAULT 0.00,
  `remarks` varchar(50) DEFAULT 'Passed',
  `encoded_by` int(11) DEFAULT NULL,
  `encoded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`grade_id`),
  UNIQUE KEY `unique_grade` (`student_id`,`subject_code`,`section_id`,`school_year_id`,`term`),
  KEY `encoded_by` (`encoded_by`),
  KEY `idx_student` (`student_id`),
  KEY `idx_subject` (`subject_code`),
  KEY `idx_teacher` (`teacher_id`),
  KEY `idx_section` (`section_id`),
  KEY `idx_sy` (`school_year_id`),
  KEY `idx_grades_final` (`final_grade`),
  KEY `idx_grades_remarks` (`remarks`),
  CONSTRAINT `grades_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `grades_ibfk_2` FOREIGN KEY (`subject_code`) REFERENCES `subjects` (`subject_code`) ON DELETE CASCADE,
  CONSTRAINT `grades_ibfk_3` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `grades_ibfk_4` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`) ON DELETE CASCADE,
  CONSTRAINT `grades_ibfk_5` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`sy_id`) ON DELETE CASCADE,
  CONSTRAINT `grades_ibfk_6` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `chk_grades_term` CHECK (`term` in ('Term 1','Term 2','Term 3')),
  CONSTRAINT `chk_grades_ranges` CHECK (`written_work` between 0 and 100 and `performance_task` between 0 and 100 and `examinations` between 0 and 100 and `initial_grade` between 0 and 100 and `transmuted_grade` between 0 and 100 and `final_grade` between 0 and 100)
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `grades` WRITE;
/*!40000 ALTER TABLE `grades` DISABLE KEYS */;
/*!40000 ALTER TABLE `grades` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) DEFAULT 'info',
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_read` (`is_read`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES
(8,5,'Enrollment Approved','Your enrollment has been approved. You are now enrolled in 7-B.','success','',0,'2026-08-16 08:16:01','2026-08-16 08:16:01'),
(10,7,'Enrollment Approved','Your enrollment has been approved. You are now enrolled in 7-B.','success','',0,'2026-08-16 08:48:30','2026-08-16 08:48:30'),
(13,9,'Enrollment Approved','Your enrollment has been approved. You are now enrolled in 9-A.','success','',1,'2026-08-16 10:57:44','2026-08-21 10:03:15'),
(17,18,'Enrollment Approved','Your enrollment has been approved. You are now enrolled in 7-B.','success','',0,'2026-08-16 11:18:40','2026-08-16 11:18:40'),
(18,19,'Enrollment Approved','Your enrollment has been approved. You are now enrolled in 9-B.','success','',0,'2026-08-16 11:27:03','2026-08-16 11:27:03'),
(19,20,'Enrollment Approved','Your enrollment has been approved. You are now enrolled in 8-A.','success','',1,'2026-08-19 08:23:44','2026-08-24 11:30:59'),
(21,9,'New Online Enrollment Application','Mark Dela Cruz applied online for Grade 7. Reference No. OE-2026-VR3MD7.','info','modules/enrollment/view_application.php?id=1',1,'2026-08-21 10:43:00','2026-08-21 10:43:25'),
(23,9,'New Online Enrollment Application','olegggg Dela Cruz applied online for Grade 11 (HUMSS). Reference No. OE-2026-JRUV3D.','info','modules/enrollment/view_application.php?id=2',1,'2026-08-21 10:48:10','2026-08-21 10:48:24'),
(25,20,'New Subject Assigned','You have been assigned to teach English 8 for section 8-A.','success','modules/teachers/my_sections.php',1,'2026-08-26 01:08:32','2026-08-26 01:09:00'),
(26,20,'New Tutorial Plan','Jhoruis Robles has a new AI-generated tutorial plan for a subject you handle. Please review the recommended focus areas.','warning','modules/tutorial/view.php?id=1',1,'2026-08-26 05:53:30','2026-08-26 05:55:55'),
(27,20,'New Tutorial Plan','France Jhoruis Robles has a new AI-generated tutorial plan for a subject you handle. Please review the recommended focus areas.','warning','modules/tutorial/view.php?id=2',1,'2026-08-26 07:04:19','2026-08-26 07:04:40'),
(28,20,'New Tutorial Plan','Jqwd Nunez has a new AI-generated tutorial plan for a subject you handle. Please review the recommended focus areas.','warning','modules/tutorial/view.php?id=3',1,'2026-08-26 07:11:45','2026-08-26 07:12:05'),
(29,21,'New Tutorial Plan','asd asx has a new AI-generated tutorial plan for a subject you handle. Please review the recommended focus areas.','warning','modules/tutorial/view.php?id=4',1,'2026-08-27 07:59:32','2026-08-27 08:01:11'),
(30,21,'New Subject Assigned','You have been assigned to teach Edukasyon sa Pagpapakatao 10 for section 10-C.','success','modules/teachers/my_sections.php',1,'2026-08-27 08:55:04','2026-08-27 08:55:56'),
(31,21,'New Tutorial Plan','asd asx has a new AI-generated tutorial plan for a subject you handle. Please review the recommended focus areas.','warning','modules/tutorial/view.php?id=5',1,'2026-08-28 09:30:52','2026-08-28 09:40:57');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `online_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `online_applications` (
  `application_id` int(11) NOT NULL AUTO_INCREMENT,
  `reference_no` varchar(20) NOT NULL,
  `application_type` enum('New Student','Transferee','Returning','Continuing') DEFAULT 'New Student',
  `grade_level` varchar(20) NOT NULL,
  `strand` varchar(20) DEFAULT NULL,
  `school_year_id` int(11) DEFAULT NULL,
  `lrn` varchar(12) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `birth_date` date NOT NULL,
  `birth_place` varchar(200) DEFAULT NULL,
  `gender` enum('Male','Female') NOT NULL,
  `religion` varchar(100) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT 'Filipino',
  `contact_number` varchar(15) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `father_name` varchar(200) DEFAULT NULL,
  `mother_name` varchar(200) DEFAULT NULL,
  `guardian_name` varchar(200) DEFAULT NULL,
  `guardian_relationship` varchar(50) DEFAULT NULL,
  `guardian_contact` varchar(15) DEFAULT NULL,
  `last_school_name` varchar(200) DEFAULT NULL,
  `last_school_address` varchar(255) DEFAULT NULL,
  `last_school_year` varchar(20) DEFAULT NULL,
  `last_grade_completed` varchar(20) DEFAULT NULL,
  `english_grade` decimal(5,2) DEFAULT 0.00,
  `math_grade` decimal(5,2) DEFAULT 0.00,
  `science_grade` decimal(5,2) DEFAULT 0.00,
  `filipino_grade` decimal(5,2) DEFAULT 0.00,
  `ap_grade` decimal(5,2) DEFAULT 0.00,
  `tle_grade` decimal(5,2) DEFAULT 0.00,
  `mapeh_grade` decimal(5,2) DEFAULT 0.00,
  `average_grade` decimal(5,2) DEFAULT 0.00,
  `status` enum('Pending','Under Review','Approved','Rejected') DEFAULT 'Pending',
  `remarks` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `enrollment_id` int(11) DEFAULT NULL,
  `submitted_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`application_id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  KEY `idx_status` (`status`),
  KEY `idx_grade` (`grade_level`),
  KEY `idx_ref` (`reference_no`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `online_applications` WRITE;
/*!40000 ALTER TABLE `online_applications` DISABLE KEYS */;
INSERT INTO `online_applications` VALUES
(1,'OE-2026-VR3MD7','New Student','Grade 7',NULL,2,'117281100091','Mark','Dela Cruz','Mendoza','jr','2007-08-25','division 1 mangung','Male','catholic','Filipino','09946832063','yajiimnida@gmail.com','11-17 Guava Rd. Potrero Malabon City','oleg omaga','aiz calupit','Maria Dela Cruz','mother','09565487569','calderon high school','11-17 Guava Rd. Potrero Malabon City','2023-2024','',89.00,87.00,84.00,84.00,85.00,84.00,86.00,85.57,'Approved','',9,'2026-08-21 18:44:17',22,20,'::1','2026-08-21 10:43:00','2026-08-21 10:44:17'),
(2,'OE-2026-JRUV3D','New Student','Grade 11','HUMSS',2,'117281100098','olegggg','Dela Cruz','Mendoza','jr','2007-08-25','division 1 mangung','Female','catholic','Filipino','09946832063','yajiimnida@gmail.com','11-17 Guava Rd. Potrero Malabon City','oleg omaga','aiz calupit','Maria Dela Cruz','mother','09123456789','calderon high school','11-17 Guava Rd. Potrero Malabon City','2023-2024','',98.00,78.00,98.00,87.00,87.00,87.00,86.00,88.71,'Approved','PUTANGAINAKA',9,'2026-08-21 18:49:08',23,21,'::1','2026-08-21 10:48:10','2026-08-21 10:49:08');
/*!40000 ALTER TABLE `online_applications` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reports` (
  `report_id` int(11) NOT NULL AUTO_INCREMENT,
  `report_type` varchar(50) NOT NULL,
  `report_name` varchar(100) NOT NULL,
  `school_year_id` int(11) NOT NULL,
  `generated_by` int(11) NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `report_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`report_data`)),
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`report_id`),
  KEY `generated_by` (`generated_by`),
  KEY `idx_type` (`report_type`),
  KEY `idx_sy` (`school_year_id`),
  CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`sy_id`) ON DELETE CASCADE,
  CONSTRAINT `reports_ibfk_2` FOREIGN KEY (`generated_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `reports` WRITE;
/*!40000 ALTER TABLE `reports` DISABLE KEYS */;
/*!40000 ALTER TABLE `reports` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `role_description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES
(1,'Administrator','Full system access and management','2026-07-21 05:47:43','2026-07-21 05:47:43'),
(2,'Registrar','Enrollment and student records management','2026-07-21 05:47:43','2026-07-21 05:47:43'),
(3,'Teacher','Grade encoding and class management','2026-07-21 05:47:43','2026-07-21 05:47:43'),
(4,'Student','View grades and enrollment status','2026-07-21 05:47:43','2026-07-21 05:47:43'),
(5,'Parent','Monitor student progress','2026-07-21 05:47:43','2026-07-21 05:47:43');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `school_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `school_years` (
  `sy_id` int(11) NOT NULL AUTO_INCREMENT,
  `sy_name` varchar(50) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_active` tinyint(1) DEFAULT 0,
  `grading_structure` enum('term') NOT NULL DEFAULT 'term' COMMENT 'DepEd Three-Term structure (DO 009 / DO 015, s. 2026). Quarter is retired.',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`sy_id`),
  UNIQUE KEY `sy_name` (`sy_name`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `school_years` WRITE;
/*!40000 ALTER TABLE `school_years` DISABLE KEYS */;
INSERT INTO `school_years` VALUES
(2,'2026-2027','2026-07-06','2027-04-20',1,'term','2026-07-23 06:15:07','2026-08-28 13:07:47');
/*!40000 ALTER TABLE `school_years` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sections` (
  `section_id` int(11) NOT NULL AUTO_INCREMENT,
  `section_name` varchar(50) NOT NULL,
  `strand` varchar(50) DEFAULT NULL,
  `grade_level` varchar(20) NOT NULL,
  `is_senior_high` tinyint(1) DEFAULT 0,
  `academic_year` varchar(20) NOT NULL,
  `adviser_id` int(11) DEFAULT NULL,
  `capacity` int(11) DEFAULT 45,
  `current_enrollment` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `curriculum_type` varchar(20) DEFAULT NULL COMMENT 'legacy_shs | strengthened_shs. Cohort-level default for Grade 12.',
  PRIMARY KEY (`section_id`),
  UNIQUE KEY `unique_section` (`section_name`,`academic_year`),
  KEY `adviser_id` (`adviser_id`),
  KEY `idx_grade` (`grade_level`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `sections_ibfk_1` FOREIGN KEY (`adviser_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=110 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `sections` WRITE;
/*!40000 ALTER TABLE `sections` DISABLE KEYS */;
INSERT INTO `sections` VALUES
(44,'7-A',NULL,'Grade 7',0,'2025-2026',20,45,0,1,'2026-07-23 07:34:04','2026-08-25 08:40:23',NULL),
(45,'7-B',NULL,'Grade 7',0,'2025-2026',20,45,2,1,'2026-07-23 07:34:04','2026-08-26 06:02:21',NULL),
(46,'7-C',NULL,'Grade 7',0,'2025-2026',20,45,2,1,'2026-07-23 07:34:04','2026-08-25 08:52:40',NULL),
(47,'8-A',NULL,'Grade 8',0,'2025-2026',20,45,3,1,'2026-07-23 07:34:04','2026-08-26 01:03:22',NULL),
(48,'8-B',NULL,'Grade 8',0,'2025-2026',NULL,45,0,1,'2026-07-23 07:34:04','2026-07-23 07:34:04',NULL),
(49,'9-A',NULL,'Grade 9',0,'2025-2026',NULL,45,1,1,'2026-07-23 07:34:04','2026-08-16 11:08:53',NULL),
(50,'9-B',NULL,'Grade 9',0,'2025-2026',NULL,45,1,1,'2026-07-23 07:34:04','2026-08-16 11:27:00',NULL),
(51,'10-A',NULL,'Grade 10',0,'2025-2026',NULL,45,1,1,'2026-07-23 07:34:04','2026-08-16 11:08:53',NULL),
(52,'10-B',NULL,'Grade 10',0,'2025-2026',NULL,45,0,1,'2026-07-23 07:34:04','2026-08-16 08:54:31',NULL),
(89,'11-GAS A','GAS','Grade 11',1,'2025-2026',NULL,40,1,1,'2026-08-15 09:48:56','2026-08-16 11:14:29',NULL),
(90,'11-GAS B','GAS','Grade 11',1,'2025-2026',NULL,40,0,1,'2026-08-15 09:48:56','2026-08-15 09:48:56',NULL),
(91,'11-GAS C','GAS','Grade 11',1,'2025-2026',NULL,40,1,1,'2026-08-15 09:48:56','2026-08-19 10:06:22',NULL),
(92,'12-GAS A','GAS','Grade 12',1,'2025-2026',NULL,40,0,1,'2026-08-15 09:48:56','2026-08-15 09:48:56',NULL),
(93,'12-GAS B','GAS','Grade 12',1,'2025-2026',NULL,40,0,1,'2026-08-15 09:48:56','2026-08-15 09:48:56',NULL),
(94,'12-GAS C','GAS','Grade 12',1,'2025-2026',NULL,40,0,1,'2026-08-15 09:48:56','2026-08-15 09:48:56',NULL),
(95,'11-HUMSS A','HUMSS','Grade 11',1,'2025-2026',NULL,40,2,1,'2026-08-15 09:48:56','2026-08-26 01:01:21',NULL),
(96,'11-HUMSS B','HUMSS','Grade 11',1,'2025-2026',NULL,40,0,1,'2026-08-15 09:48:56','2026-08-15 09:48:56',NULL),
(97,'11-HUMSS C','HUMSS','Grade 11',1,'2025-2026',NULL,40,0,1,'2026-08-15 09:48:56','2026-08-15 09:48:56',NULL),
(98,'12-HUMSS A','HUMSS','Grade 12',1,'2025-2026',NULL,40,0,1,'2026-08-15 09:48:56','2026-08-15 09:48:56',NULL),
(99,'12-HUMSS B','HUMSS','Grade 12',1,'2025-2026',NULL,40,0,1,'2026-08-15 09:48:56','2026-08-15 09:48:56',NULL),
(100,'12-HUMSS C','HUMSS','Grade 12',1,'2025-2026',NULL,40,0,1,'2026-08-15 09:48:56','2026-08-15 09:48:56',NULL),
(102,'8-C',NULL,'Grade 8',0,'2026-2027',NULL,45,0,1,'2026-08-19 08:22:33','2026-08-19 08:22:33',NULL),
(103,'10-C',NULL,'Grade 10',0,'2026-2027',NULL,45,2,1,'2026-08-27 07:53:21','2026-08-28 05:47:37',NULL);
/*!40000 ALTER TABLE `sections` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `students` (
  `student_id` int(11) NOT NULL AUTO_INCREMENT,
  `lrn` varchar(12) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `birth_date` date NOT NULL,
  `gender` enum('Male','Female') NOT NULL,
  `contact_number` varchar(15) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `guardian_name` varchar(200) DEFAULT NULL,
  `guardian_contact` varchar(15) DEFAULT NULL,
  `grade_level` varchar(20) NOT NULL,
  `strand` varchar(50) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `school_year_id` int(11) DEFAULT NULL,
  `enrollment_status` enum('Enrolled','Pending','Transferred','Graduated') DEFAULT 'Pending',
  `user_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `curriculum_type` varchar(20) DEFAULT NULL COMMENT 'legacy_shs | strengthened_shs. Grade 12 only. NULL = inherit section/default.',
  PRIMARY KEY (`student_id`),
  UNIQUE KEY `lrn` (`lrn`),
  UNIQUE KEY `unique_lrn` (`lrn`),
  KEY `school_year_id` (`school_year_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_grade` (`grade_level`),
  KEY `idx_status` (`enrollment_status`),
  KEY `idx_section` (`section_id`),
  KEY `idx_students_grade` (`grade_level`),
  KEY `idx_students_name` (`last_name`,`first_name`),
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`) ON DELETE SET NULL,
  CONSTRAINT `students_ibfk_2` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`sy_id`) ON DELETE SET NULL,
  CONSTRAINT `students_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT INTO `students` VALUES
(1,'099265332251','Francis','Tejada','Torrefiel','2005-02-16','Male','09926533225','francistejada77@gmail.com',NULL,'longos malabon','Malou tejada','0985950364','Grade 11','HUMSS',95,2,'Enrolled',NULL,1,'2026-08-12 12:50:18','2026-08-16 07:55:45',NULL),
(3,'117281100039','Jay','Nuñeza','Torrefiel','2004-12-11','Female','09926533225','jaynunez017@gmail.com',NULL,'','Malou tejada','0985950364','Grade 7','',46,2,'Pending',NULL,1,'2026-08-14 10:49:50','2026-08-26 00:21:37',NULL),
(5,'110100100051','France Jhoruis','Robles','Torregoza','2004-10-24','Male','09072719163','fjroblestorregoza@gmail.com','profile_5_1787640369.png','Malabon City','John V.','09072719163','Grade 7','',45,2,'Enrolled',NULL,1,'2026-08-16 08:15:39','2026-08-26 06:02:21',NULL),
(7,'110100100052','Jhoruis','Robles','Torregoza','2004-10-24','Female','09072719163','fjroblestorregoza@gmail.com',NULL,'Mlabaon','John V.','09072719163','Grade 7','',45,2,'Enrolled',NULL,1,'2026-08-16 08:48:15','2026-08-16 08:48:19',NULL),
(8,'110100100058','asd','Nunez','Mendoza','2026-08-13','Male','09123456789','jaynunez017@gmail.com',NULL,'alexa','John V.','09072719163','Grade 8','',47,2,'Enrolled',NULL,1,'2026-08-16 10:40:23','2026-08-26 00:34:04',NULL),
(9,'110100100055','Jqwd','Nunez','Mendoza','2026-08-06','Male','09123456789','jaynunez017@gmail.com',NULL,'alexa','John V.','09072719163','Grade 9','',49,2,'Enrolled',NULL,1,'2026-08-16 10:56:28','2026-08-16 10:57:38',NULL),
(10,'110100100056','asd','asx','aq','2026-08-16','Male','09123456789','jaynunez017@gmail.com',NULL,'alexa','John V.','09072719163','Grade 8','',47,2,'Enrolled',NULL,1,'2026-08-16 10:57:24','2026-08-26 00:33:51',NULL),
(15,'110100100123','Yww','Evangelista','Mendoza','2026-08-16','Male','09123456789','jaynunez017@gmail.com',NULL,'alexa','John V.','09072719163','Grade 10','',51,2,'Enrolled',NULL,1,'2026-08-16 11:01:13','2026-08-16 11:01:21',NULL),
(16,'123456789021','Oleg','Omaga','Alexander','2026-08-16','Male','09123456789','jaynunez017@gmail.com',NULL,'alexa','John V.','09072719163','Grade 11','GAS',89,2,'Enrolled',NULL,1,'2026-08-16 11:12:34','2026-08-16 11:12:44',NULL),
(18,'110100100005','Oleg','Nunez','Mendoza','2026-08-16','Male','09123456789','jaynunez017@gmail.com',NULL,'alexa','John V.','09072719163','Grade 7','',45,2,'Enrolled',NULL,0,'2026-08-16 11:18:30','2026-08-26 00:36:22',NULL),
(19,'424242425252','Oleg','Nunez','Mendoza','2026-08-16','Female','09123456789','jaynunez017@gmail.com',NULL,'alexa','John V.','09072719163','Grade 9','',50,2,'Enrolled',NULL,1,'2026-08-16 11:26:55','2026-08-16 11:27:00',NULL),
(20,'117281100330','Mark','Dela Cruz','Mendoza','0012-12-12','Female','09946832063','jaynunez017@gmail.com',NULL,'ASD','Maria Dela Cruz','09123456788','Grade 8','',47,2,'Enrolled',NULL,1,'2026-08-19 08:23:27','2026-08-19 08:23:41',NULL),
(21,'117281100331','fra','Dela Cruz','Mendoza','0012-12-12','Male','09946832063','jaynunez017@gmail.com',NULL,'sad','Maria Dela Cruz','09123456788','Grade 11','GAS',91,2,'Enrolled',NULL,1,'2026-08-19 10:06:04','2026-08-19 10:06:22',NULL),
(22,'117281100091','Mark','Dela Cruz','Mendoza','2007-08-25','Male','09946832063','yajiimnida@gmail.com',NULL,'11-17 Guava Rd. Potrero Malabon City','Maria Dela Cruz','09565487569','Grade 7','',46,2,'Enrolled',NULL,1,'2026-08-21 10:44:17','2026-08-21 10:44:42',NULL),
(23,'117281100098','olegggg','Dela Cruz','Mendoza','2007-08-25','Female','09946832063','yajiimnida@gmail.com',NULL,'11-17 Guava Rd. Potrero Malabon City','Maria Dela Cruz','09123456789','Grade 11','HUMSS',97,2,'Pending',NULL,1,'2026-08-21 10:49:08','2026-08-21 10:49:08',NULL),
(24,'110100100099','Juan','Dela Cruz','Santos','2010-05-14','Male','09171234567','juan.delacruz@example.com',NULL,'123 Sample St., Malabon City','Maria Dela Cruz','09179876543','Grade 7','',NULL,2,'Enrolled',NULL,1,'2026-08-25 06:01:05','2026-08-25 06:01:35',NULL),
(25,'110100100098','Ana','Reyes',NULL,'2008-11-02','Female','09181234567',NULL,NULL,'456 Sample Ave., Malabon City','Pedro Reyes','09189876543','Grade 11','HUMSS',NULL,2,'Enrolled',NULL,1,'2026-08-25 06:01:05','2026-08-26 00:34:44',NULL),
(26,'210100100099','Alex','Dela Cruz','Santos','2010-05-14','Male','09171234567','juan.delacruz@example.com',NULL,'123 Sample St., Malabon City','Maria Dela Cruz','09179876543','Grade 7',NULL,46,2,'Pending',NULL,1,'2026-08-25 06:25:51','2026-08-25 06:25:51',NULL),
(27,'210100100098','Lord','Reyes',NULL,'2008-11-02','Female','09181234567',NULL,NULL,'456 Sample Ave., Malabon City','Pedro Reyes','09189876543','Grade 11','HUMSS',95,2,'Enrolled',NULL,1,'2026-08-25 06:25:51','2026-08-26 01:01:21',NULL),
(28,'310100100099','France','Dela Cruz','Santos','2010-05-14','Male','09171234567','juan.delacruz@example.com',NULL,'123 Sample St., Malabon City','Maria Dela Cruz','09179876543','Grade 7',NULL,44,2,'Pending',NULL,1,'2026-08-25 07:13:11','2026-08-25 07:13:11',NULL),
(29,'310100100098','France','Reyes',NULL,'2008-11-02','Female','09181234567',NULL,NULL,'456 Sample Ave., Malabon City','Pedro Reyes','09189876543','Grade 11','HUMSS',97,2,'Pending',NULL,1,'2026-08-25 07:13:11','2026-08-25 07:13:11',NULL),
(30,'110100100061','Jay','Nunez','','2026-08-25','Female','09946832063','imnidayaji@gmail.com',NULL,'Caloocan City','Yaji','Imnida','Grade 7','',46,2,'Enrolled',NULL,1,'2026-08-25 08:52:13','2026-08-25 08:52:40',NULL),
(31,'000000000000','asd','asx','aq','2005-02-26','Male','09123456789','alexanderblancaver5@gmail.com',NULL,'11-17 guava','John V.','09072719163','Grade 10','',103,2,'Enrolled',NULL,1,'2026-08-27 07:53:21','2026-08-27 07:53:31',NULL),
(32,'111111111111','asd','asx','aq','2005-02-26','Male','09123456789','alexanderblancaver5@gmail.com',NULL,NULL,NULL,NULL,'Grade 10','',103,2,'Enrolled',NULL,1,'2026-08-28 05:47:31','2026-08-28 05:47:37',NULL);
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `subjects` (
  `subject_code` varchar(20) NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `grade_level` varchar(20) NOT NULL,
  `strand` varchar(50) DEFAULT NULL,
  `subject_type` varchar(30) NOT NULL DEFAULT 'Languages',
  `subject_category` varchar(50) DEFAULT NULL COMMENT 'DO 015 / Strengthened SHS grading category key. NULL = derive or configure.',
  `components` varchar(255) DEFAULT NULL COMMENT 'Comma-separated official components, e.g. Music,Arts,Physical Education,Health. NULL = single component.',
  `units` int(11) DEFAULT 1,
  `terms_required` tinyint(1) NOT NULL DEFAULT 3 COMMENT 'Grading periods this subject runs for (1-3). Grades 4-10 are always 3; SHS courses vary.',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`subject_code`),
  KEY `idx_grade` (`grade_level`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `chk_subjects_terms_required` CHECK (`terms_required` between 1 and 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `subjects` WRITE;
/*!40000 ALTER TABLE `subjects` DISABLE KEYS */;
INSERT INTO `subjects` VALUES
('AP10','Araling Panlipunan 10','Grade 10 AP','Grade 10',NULL,'AP',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('AP101','Philippine Politics and Governance',NULL,'Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-07-29 06:31:25','2026-08-18 10:25:25'),
('AP102','Understanding Culture, Society and Politics',NULL,'Grade 11','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-07-29 06:31:25','2026-08-18 10:25:25'),
('AP7','Araling Panlipunan 7','Grade 7 AP','Grade 7',NULL,'AP',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('AP8','Araling Panlipunan 8','Grade 8 AP','Grade 8',NULL,'AP',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('AP9','Araling Panlipunan 9','Grade 9 AP','Grade 9',NULL,'AP',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('ENG10','English 10','Grade 10 English','Grade 10',NULL,'Languages',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-15 08:44:15'),
('ENG101','English for Academic Purposes',NULL,'Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-07-29 06:31:25','2026-08-18 10:25:25'),
('ENG102','Creative Writing',NULL,'Grade 11','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-07-29 06:31:25','2026-08-18 10:25:25'),
('ENG7','English 7','Grade 7 English','Grade 7',NULL,'Languages',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-15 08:44:15'),
('ENG8','English 8','Grade 8 English','Grade 8',NULL,'Languages',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-15 08:44:15'),
('ENG9','English 9','Grade 9 English','Grade 9',NULL,'Languages',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-15 08:44:15'),
('ESP10','Values Education 10','Grade 10 ESP','Grade 10',NULL,'Values Education','ks_values_education',NULL,1,3,1,'2026-08-15 08:44:15','2026-08-29 14:29:04'),
('ESP7','Values Education 7','Grade 7 ESP','Grade 7',NULL,'Values Education','ks_values_education',NULL,1,3,1,'2026-08-15 08:44:15','2026-08-29 14:29:04'),
('ESP8','Values Education 8','Grade 8 ESP','Grade 8',NULL,'Values Education','ks_values_education',NULL,1,3,1,'2026-08-15 08:44:15','2026-08-29 14:29:04'),
('ESP9','Values Education 9','Grade 9 ESP','Grade 9',NULL,'Values Education','ks_values_education',NULL,1,3,1,'2026-08-15 08:44:15','2026-08-29 14:29:04'),
('FIL10','Filipino 10','Grade 10 Filipino','Grade 10',NULL,'Languages',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-15 08:44:15'),
('FIL101','Komunikasyon at Pananaliksik',NULL,'Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-07-29 06:31:25','2026-08-18 10:25:25'),
('FIL102','Pagbasa at Pagsusuri',NULL,'Grade 11','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-07-29 06:31:25','2026-08-18 10:25:25'),
('FIL7','Filipino 7','Grade 7 Filipino','Grade 7',NULL,'Languages',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-15 08:44:15'),
('FIL8','Filipino 8','Grade 8 Filipino','Grade 8',NULL,'Languages',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-15 08:44:15'),
('FIL9','Filipino 9','Grade 9 Filipino','Grade 9',NULL,'Languages',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-15 08:44:15'),
('GAS11-AP1','Philippine Politics and Governance','Grade 11 GAS AP','Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('GAS11-AP2','Understanding Culture, Society and Politics','Grade 11 GAS AP','Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('GAS11-ENG1','English for Academic Purposes','Grade 11 GAS English','Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('GAS11-FIL1','Komunikasyon at Pananaliksik','Grade 11 GAS Filipino','Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('GAS11-FIL2','Pagbasa at Pagsusuri','Grade 11 GAS Filipino','Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('GAS11-MAPEH','Music and Arts','Grade 11 GAS MAPEH','Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('GAS11-MATH1','General Mathematics','Grade 11 GAS Math','Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('GAS11-MATH2','Statistics and Probability','Grade 11 GAS Math','Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('GAS11-PE','Physical Education and Health','Grade 11 GAS PE','Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('GAS11-SCI1','General Biology','Grade 11 GAS Science','Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('GAS11-SCI2','General Chemistry','Grade 11 GAS Science','Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('GAS11-TLE','Computer Systems Servicing','Grade 11 GAS TLE','Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('GAS12-CALC','Basic Calculus','Grade 12 GAS Math','Grade 12','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('GAS12-ENG','Reading and Writing','Grade 12 GAS English','Grade 12','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('GAS12-ENG2','Creative Non-Fiction','Grade 12 GAS English','Grade 12','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('GAS12-FIL','Pananaliksik sa Filipino','Grade 12 GAS Filipino','Grade 12','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('GAS12-MAPEH','Music and Arts','Grade 12 GAS MAPEH','Grade 12','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('GAS12-MATH','Pre-Calculus','Grade 12 GAS Math','Grade 12','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('GAS12-PE','Physical Education and Health','Grade 12 GAS PE','Grade 12','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('GAS12-SCI','Earth and Life Science','Grade 12 GAS Science','Grade 12','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('GAS12-SCI2','Physical Science','Grade 12 GAS Science','Grade 12','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('GAS12-TLE','Computer Systems Servicing','Grade 12 GAS TLE','Grade 12','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('HUMSS11-AP','Understanding Culture, Society and Politics','Grade 11 HUMSS AP','Grade 11','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('HUMSS11-ENG','Creative Writing','Grade 11 HUMSS English','Grade 11','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('HUMSS11-ENG2','21st Century Literature','Grade 11 HUMSS English','Grade 11','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('HUMSS11-FIL','Pagbasa at Pagsusuri','Grade 11 HUMSS Filipino','Grade 11','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('HUMSS11-FIL2','Filipino sa Piling Larang','Grade 11 HUMSS Filipino','Grade 11','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('HUMSS11-MAPEH','Music and Arts','Grade 11 HUMSS MAPEH','Grade 11','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('HUMSS11-PE','Physical Education and Health','Grade 11 HUMSS PE','Grade 11','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('HUMSS11-POL','Philippine Politics and Governance','Grade 11 HUMSS Politics','Grade 11','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('HUMSS12-AP','Trends and Issues','Grade 12 HUMSS AP','Grade 12','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('HUMSS12-ENG','Creative Non-Fiction','Grade 12 HUMSS English','Grade 12','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('HUMSS12-ENG2','Reading and Writing','Grade 12 HUMSS English','Grade 12','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('HUMSS12-FIL','Pananaliksik sa Filipino','Grade 12 HUMSS Filipino','Grade 12','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('HUMSS12-MAPEH','Music and Arts','Grade 12 HUMSS MAPEH','Grade 12','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('HUMSS12-PE','Physical Education and Health','Grade 12 HUMSS PE','Grade 12','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('HUMSS12-SOC','Social Sciences','Grade 12 HUMSS Social Sciences','Grade 12','HUMSS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:45:13','2026-08-18 10:25:25'),
('MAPEH10','MAPEH 10','Grade 10 MAPEH','Grade 10',NULL,'MAPEH','ks_mapeh','Music and Arts, PE and Health',1,3,1,'2026-08-15 08:44:15','2026-08-30 06:43:40'),
('MAPEH101','Music and Arts',NULL,'Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-07-29 06:31:25','2026-08-18 10:25:25'),
('MAPEH7','MAPEH 7','Grade 7 MAPEH','Grade 7',NULL,'MAPEH','ks_mapeh','Music and Arts, PE and Health',1,3,1,'2026-08-15 08:44:15','2026-08-30 06:43:40'),
('MAPEH8','MAPEH 8','Grade 8 MAPEH','Grade 8',NULL,'MAPEH','ks_mapeh','Music and Arts, PE and Health',1,3,1,'2026-08-15 08:44:15','2026-08-30 06:43:40'),
('MAPEH9','MAPEH 9','Grade 9 MAPEH','Grade 9',NULL,'MAPEH','ks_mapeh','Music and Arts, PE and Health',1,3,1,'2026-08-15 08:44:15','2026-08-30 06:43:40'),
('MATH10','Mathematics 10','Grade 10 Mathematics','Grade 10',NULL,'Math',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('MATH101','General Mathematics',NULL,'Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-07-29 06:31:25','2026-08-18 10:25:25'),
('MATH102','Statistics and Probability',NULL,'Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-07-29 06:31:25','2026-08-18 10:25:25'),
('MATH7','Mathematics 7','Grade 7 Mathematics','Grade 7',NULL,'Math',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('MATH8','Mathematics 8','Grade 8 Mathematics','Grade 8',NULL,'Math',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('MATH9','Mathematics 9','Grade 9 Mathematics','Grade 9',NULL,'Math',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('PE101','Physical Education and Health',NULL,'Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-07-29 06:31:25','2026-08-18 10:25:25'),
('PRACTICAL-RESEARCH-1','Practical Research 1','Grade 11 Research','Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('RESEARCH11','Research','sad','Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-08-14 11:52:06','2026-08-18 10:25:25'),
('SCI10','Science 10','Grade 10 Science','Grade 10',NULL,'Science',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('SCI101','General Biology',NULL,'Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-07-29 06:31:25','2026-08-18 10:25:25'),
('SCI102','General Chemistry',NULL,'Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-07-29 06:31:25','2026-08-18 10:25:25'),
('SCI7','Science 7','Grade 7 Science','Grade 7',NULL,'Science',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('SCI8','Science 8','Grade 8 Science','Grade 8',NULL,'Science',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('SCI9','Science 9','Grade 9 Science','Grade 9',NULL,'Science',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('TLE10','TLE 10','Grade 10 TLE','Grade 10',NULL,'TLE',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('TLE101','Computer Systems Servicing',NULL,'Grade 11','GAS','Academic Track',NULL,NULL,1,3,1,'2026-07-29 06:31:25','2026-08-18 10:25:25'),
('TLE7','TLE 7','Grade 7 TLE','Grade 7',NULL,'TLE',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('TLE8','TLE 8','Grade 8 TLE','Grade 8',NULL,'TLE',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25'),
('TLE9','TLE 9','Grade 9 TLE','Grade 9',NULL,'TLE',NULL,NULL,1,3,1,'2026-08-15 08:44:15','2026-08-18 10:25:25');
/*!40000 ALTER TABLE `subjects` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_group` varchar(50) DEFAULT 'general',
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES
(1,'school_name','Imelda Integrated Secondary School','general','School Name','2026-07-21 05:47:44','2026-07-21 05:47:44'),
(2,'school_address','Imelda, Philippines','general','School Address','2026-07-21 05:47:44','2026-07-21 05:47:44'),
(3,'school_contact','123-456-7890','general','School Contact Number','2026-07-21 05:47:44','2026-07-21 05:47:44'),
(4,'school_email','imelda.iss@deped.gov.ph','general','School Email','2026-07-21 05:47:44','2026-07-21 05:47:44'),
(5,'grading_pass_mark','75','grading','Passing Grade','2026-07-21 05:47:44','2026-07-21 05:47:44'),
(10,'smtp_host','smtp.gmail.com','email',NULL,'2026-07-23 08:23:40','2026-07-23 08:23:40'),
(11,'smtp_port','587','email',NULL,'2026-07-23 08:23:40','2026-07-23 08:23:40'),
(12,'smtp_username','jaynunez017@gmail.com','email',NULL,'2026-07-23 08:23:40','2026-07-23 08:24:02'),
(13,'smtp_password','','email',NULL,'2026-07-23 08:23:40','2026-08-25 06:54:52'),
(14,'smtp_from','jaynunez017@gmail.com','email',NULL,'2026-07-23 08:23:40','2026-07-23 08:24:02'),
(15,'smtp_from_name','EGRADEPRO Grading &amp; Enrollment System','email',NULL,'2026-07-23 08:23:40','2026-08-25 06:54:52'),
(16,'shs_default_curriculum_type','strengthened_shs','grading','Default Grade 12 curriculum: legacy_shs or strengthened_shs. Empty = must be set per learner/section.','2026-08-29 09:04:45','2026-08-30 06:43:40');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `teacher_attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `teacher_attendance` (
  `attendance_id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `status` enum('No Time In','Timed In','Completed') NOT NULL DEFAULT 'No Time In',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`attendance_id`),
  UNIQUE KEY `uniq_teacher_date` (`teacher_id`,`attendance_date`),
  KEY `idx_teacher` (`teacher_id`),
  KEY `idx_date` (`attendance_date`),
  CONSTRAINT `fk_ta_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `teacher_attendance` WRITE;
/*!40000 ALTER TABLE `teacher_attendance` DISABLE KEYS */;
INSERT INTO `teacher_attendance` VALUES
(1,21,'2026-08-28','18:09:16','18:09:24','Completed','2026-08-28 10:09:16','2026-08-28 10:09:24');
/*!40000 ALTER TABLE `teacher_attendance` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `teacher_subject_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `teacher_subject_assignments` (
  `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` bigint(20) NOT NULL,
  `subject_id` bigint(20) NOT NULL,
  `grade_level` varchar(20) DEFAULT NULL,
  `section` varchar(50) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `is_primary` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`assignment_id`),
  UNIQUE KEY `unique_teacher_subject` (`teacher_id`,`subject_id`,`school_year`,`section`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `teacher_subject_assignments` WRITE;
/*!40000 ALTER TABLE `teacher_subject_assignments` DISABLE KEYS */;
/*!40000 ALTER TABLE `teacher_subject_assignments` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `teacher_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `teacher_subjects` (
  `ts_id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `section_id` int(11) NOT NULL,
  `school_year_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ts_id`),
  UNIQUE KEY `unique_teacher_subject` (`teacher_id`,`subject_code`,`section_id`,`school_year_id`),
  KEY `subject_code` (`subject_code`),
  KEY `section_id` (`section_id`),
  KEY `school_year_id` (`school_year_id`),
  CONSTRAINT `teacher_subjects_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `teacher_subjects_ibfk_2` FOREIGN KEY (`subject_code`) REFERENCES `subjects` (`subject_code`) ON DELETE CASCADE,
  CONSTRAINT `teacher_subjects_ibfk_3` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`) ON DELETE CASCADE,
  CONSTRAINT `teacher_subjects_ibfk_4` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`sy_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `teacher_subjects` WRITE;
/*!40000 ALTER TABLE `teacher_subjects` DISABLE KEYS */;
INSERT INTO `teacher_subjects` VALUES
(18,18,'AP7',44,2,'2026-08-15 11:14:58','2026-08-15 11:14:58'),
(19,19,'MAPEH7',44,2,'2026-08-15 11:52:45','2026-08-15 11:52:45'),
(20,20,'AP10',52,2,'2026-08-16 09:00:20','2026-08-16 09:00:20'),
(21,19,'AP7',45,2,'2026-08-16 09:01:50','2026-08-16 09:01:50'),
(22,19,'ESP7',45,2,'2026-08-16 09:03:07','2026-08-16 09:03:07'),
(23,20,'ENG7',45,2,'2026-08-18 10:26:12','2026-08-18 10:26:12'),
(24,20,'FIL7',45,2,'2026-08-18 10:26:15','2026-08-18 10:26:15'),
(25,20,'AP9',49,2,'2026-08-18 10:26:22','2026-08-18 10:26:22'),
(26,20,'ESP9',49,2,'2026-08-18 10:26:25','2026-08-18 10:26:25'),
(27,20,'MAPEH7',45,2,'2026-08-19 06:58:56','2026-08-19 06:58:56'),
(28,20,'MATH7',45,2,'2026-08-19 07:47:27','2026-08-19 07:47:27'),
(29,20,'SCI7',45,2,'2026-08-19 07:47:35','2026-08-19 07:47:35'),
(30,20,'AP8',102,2,'2026-08-19 08:24:25','2026-08-19 08:24:25'),
(31,20,'TLE7',45,2,'2026-08-25 08:34:50','2026-08-25 08:34:50'),
(32,20,'AP8',47,2,'2026-08-26 01:03:14','2026-08-26 01:03:14'),
(33,20,'ESP8',47,2,'2026-08-26 01:05:22','2026-08-26 01:05:22'),
(34,20,'ENG8',47,2,'2026-08-26 01:08:32','2026-08-26 01:08:32'),
(35,21,'FIL10',103,2,'2026-08-27 07:56:00','2026-08-27 07:56:00'),
(36,21,'ESP10',103,2,'2026-08-27 08:55:04','2026-08-27 08:55:04'),
(37,21,'SCI10',51,2,'2026-08-28 08:50:03','2026-08-28 08:50:03'),
(38,21,'SCI10',103,2,'2026-08-28 08:54:14','2026-08-28 08:54:14');
/*!40000 ALTER TABLE `teacher_subjects` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `tutorial_plan_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tutorial_plan_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `plan_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `current_grade` decimal(5,2) DEFAULT NULL,
  `target_grade` decimal(5,2) NOT NULL DEFAULT 75.00,
  `tutor_id` int(11) DEFAULT NULL,
  `recommended_action` text DEFAULT NULL,
  `resource_url` varchar(500) DEFAULT NULL,
  `status` enum('Pending','In Progress','Completed') NOT NULL DEFAULT 'Pending',
  `student_status` enum('Not Started','Working On It','Done') NOT NULL DEFAULT 'Not Started',
  `student_viewed_at` timestamp NULL DEFAULT NULL,
  `student_updated_at` timestamp NULL DEFAULT NULL,
  `video_watched_at` timestamp NULL DEFAULT NULL,
  `watch_progress_percentage` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Video Watch Progress %, from actual accumulated playback time / video duration',
  `watched_seconds` decimal(8,2) NOT NULL DEFAULT 0.00 COMMENT 'Accumulated ACTUAL playback seconds (never buffered/skip time), stored as a running max',
  `video_duration_seconds` int(11) DEFAULT NULL COMMENT 'Total video duration in seconds, from the YouTube player',
  `last_video_position_seconds` decimal(8,2) DEFAULT NULL COMMENT 'Last playback position, used to resume the video where the student left off',
  `started_watching_at` timestamp NULL DEFAULT NULL COMMENT 'First time the student actually started playing this video',
  `last_watched_at` timestamp NULL DEFAULT NULL COMMENT 'Most recent playback activity timestamp',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`item_id`),
  KEY `plan_id` (`plan_id`),
  KEY `subject_code` (`subject_code`),
  KEY `tutor_id` (`tutor_id`),
  CONSTRAINT `fk_titem_plan` FOREIGN KEY (`plan_id`) REFERENCES `tutorial_plans` (`plan_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_titem_subject` FOREIGN KEY (`subject_code`) REFERENCES `subjects` (`subject_code`) ON DELETE CASCADE,
  CONSTRAINT `fk_titem_tutor` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `tutorial_plan_items` WRITE;
/*!40000 ALTER TABLE `tutorial_plan_items` DISABLE KEYS */;
INSERT INTO `tutorial_plan_items` VALUES
(1,1,'ENG7',62.00,75.00,20,'Read short stories daily, keep a vocabulary journal, and practice writing short paragraphs with feedback.',NULL,'Pending','Done','2026-08-26 09:22:48','2026-08-26 06:14:14',NULL,0.00,0.00,NULL,0.00,NULL,NULL,'2026-08-26 05:53:30','2026-08-28 09:53:33'),
(2,2,'MAPEH7',62.00,75.00,20,'Balance study time between physical practice and written concepts, and review performance-task rubrics beforehand.',NULL,'Pending','Not Started','2026-08-29 09:05:43',NULL,NULL,0.00,0.00,NULL,0.00,NULL,NULL,'2026-08-26 07:04:19','2026-08-29 09:05:43'),
(3,3,'AP9',61.00,75.00,20,'Use timelines and concept maps to connect events, and review with flashcards before quizzes.',NULL,'Pending','Not Started',NULL,NULL,NULL,0.00,0.00,NULL,NULL,NULL,NULL,'2026-08-26 07:11:45','2026-08-28 09:35:59'),
(4,1,'MATH7',60.00,75.00,20,'Practice a few problems daily, revisit the specific topics missed on the last exam, and try peer tutoring or short daily math drills.','https://youtu.be/-gHk4ZXte_8?si=GvfjIi70E-IX_hmp','Pending','Working On It','2026-08-26 09:22:48','2026-08-26 09:07:58',NULL,0.00,0.00,NULL,0.00,NULL,NULL,'2026-08-26 08:57:20','2026-08-29 05:35:45'),
(5,1,'MAPEH7',60.00,75.00,20,'Balance study time between physical practice and written concepts, and review performance-task rubrics beforehand.',NULL,'Pending','Not Started','2026-08-26 09:22:48',NULL,NULL,0.00,0.00,NULL,0.00,NULL,NULL,'2026-08-26 08:57:20','2026-08-28 09:53:33'),
(6,1,'TLE7',60.00,75.00,20,'Focus on hands-on, step-by-step practice and review procedures before each activity.',NULL,'Pending','Not Started','2026-08-26 09:22:48',NULL,NULL,0.00,0.00,NULL,0.00,NULL,NULL,'2026-08-26 08:57:20','2026-08-28 09:53:33'),
(7,1,'FIL7',60.00,75.00,20,'Magbasa ng maiikling kwento araw-araw at magsanay sa pagsulat ng maikling sanaysay o journal.',NULL,'Pending','Not Started','2026-08-26 09:22:48',NULL,NULL,0.00,0.00,NULL,0.00,NULL,NULL,'2026-08-26 08:57:20','2026-08-28 09:53:33'),
(8,2,'FIL7',67.00,75.00,20,'Magbasa ng maiikling kwento araw-araw at magsanay sa pagsulat ng maikling sanaysay o journal.',NULL,'Pending','Not Started','2026-08-29 09:05:43',NULL,NULL,0.00,0.00,NULL,0.00,NULL,NULL,'2026-08-26 09:26:35','2026-08-29 09:05:43'),
(9,2,'MATH7',68.00,75.00,20,'Practice a few problems daily, revisit the specific topics missed on the last exam, and try peer tutoring or short daily math drills.',NULL,'Pending','Not Started','2026-08-29 09:05:43',NULL,NULL,0.00,0.00,NULL,0.00,NULL,NULL,'2026-08-26 09:26:35','2026-08-29 09:05:43'),
(10,2,'TLE7',68.00,75.00,20,'Focus on hands-on, step-by-step practice and review procedures before each activity.',NULL,'Pending','Not Started','2026-08-29 09:05:43',NULL,NULL,0.00,0.00,NULL,0.00,NULL,NULL,'2026-08-26 09:26:35','2026-08-29 09:05:43'),
(11,4,'FIL10',63.00,75.00,21,'Magbasa ng maiikling kwento araw-araw at magsanay sa pagsulat ng maikling sanaysay o journal.',NULL,'Pending','Not Started','2026-08-28 08:56:01',NULL,NULL,0.00,0.00,NULL,0.00,NULL,NULL,'2026-08-27 07:59:32','2026-08-28 09:53:33'),
(12,4,'ESP10',73.00,75.00,21,'Set aside focused review time after class, go over notes within 24 hours, and ask for extra practice materials.',NULL,'Pending','Not Started','2026-08-28 08:56:01',NULL,NULL,0.00,0.00,NULL,0.00,NULL,NULL,'2026-08-27 08:56:01','2026-08-28 09:53:33'),
(13,1,'SCI7',60.00,75.00,20,'Review key concepts using diagrams and real-life examples, and use hands-on activities or simple experiments to reinforce lessons.',NULL,'Pending','Not Started',NULL,NULL,NULL,0.00,0.00,NULL,NULL,NULL,NULL,'2026-08-27 11:37:35','2026-08-28 09:35:59'),
(14,4,'SCI10',61.00,75.00,21,'Review key concepts using diagrams and real-life examples, and use hands-on activities or simple experiments to reinforce lessons.',NULL,'Pending','Not Started',NULL,NULL,NULL,0.00,0.00,NULL,NULL,NULL,NULL,'2026-08-28 09:08:33','2026-08-28 09:35:59'),
(15,5,'SCI10',61.00,75.00,21,'Review key concepts using diagrams and real-life examples, and use hands-on activities or simple experiments to reinforce lessons.','https://youtu.be/-gHk4ZXte_8?si=GvfjIi70E-IX_hmp','In Progress','Not Started','2026-08-29 06:26:37',NULL,NULL,80.16,137.07,171,485.21,'2026-08-28 09:31:24','2026-08-29 06:27:51','2026-08-28 09:30:52','2026-08-29 06:27:51'),
(16,5,'FIL10',63.00,75.00,21,'Magbasa ng maiikling kwento araw-araw at magsanay sa pagsulat ng maikling sanaysay o journal.','https://youtu.be/nTNM38gSwGM?si=n9jZzKVqV_H9lByw','In Progress','Not Started','2026-08-29 06:26:37',NULL,NULL,29.84,65.95,221,65.96,'2026-08-29 06:26:44','2026-08-29 06:27:49','2026-08-28 09:30:52','2026-08-29 06:27:49'),
(17,5,'ESP10',73.00,75.00,21,'Set aside focused review time after class, go over notes within 24 hours, and ask for extra practice materials.',NULL,'Pending','Not Started','2026-08-29 06:26:37',NULL,NULL,0.00,0.00,218,0.00,NULL,NULL,'2026-08-28 09:30:52','2026-08-29 06:26:37');
/*!40000 ALTER TABLE `tutorial_plan_items` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `tutorial_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tutorial_plans` (
  `plan_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `school_year_id` int(11) NOT NULL,
  `risk_level` enum('Medium','High') NOT NULL DEFAULT 'High',
  `target_average` decimal(5,2) NOT NULL DEFAULT 75.00,
  `status` enum('Draft','Active','Completed','Cancelled') NOT NULL DEFAULT 'Active',
  `created_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`plan_id`),
  KEY `student_id` (`student_id`),
  KEY `school_year_id` (`school_year_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `fk_tplan_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tplan_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tplan_sy` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`sy_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `tutorial_plans` WRITE;
/*!40000 ALTER TABLE `tutorial_plans` DISABLE KEYS */;
INSERT INTO `tutorial_plans` VALUES
(1,7,2,'High',75.00,'Active',20,NULL,'2026-08-26 05:53:30','2026-08-26 05:53:30'),
(2,5,2,'High',75.00,'Active',20,NULL,'2026-08-26 07:04:19','2026-08-26 07:04:19'),
(3,9,2,'High',75.00,'Active',20,NULL,'2026-08-26 07:11:45','2026-08-26 07:11:45'),
(4,31,2,'High',75.00,'Completed',21,NULL,'2026-08-27 07:59:32','2026-08-28 09:18:33'),
(5,31,2,'High',75.00,'Active',21,NULL,'2026-08-28 09:30:52','2026-08-28 09:30:52');
/*!40000 ALTER TABLE `tutorial_plans` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `sex` enum('Male','Female','Other') DEFAULT NULL,
  `role_id` int(11) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `failed_attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `prc_license_number` varchar(50) DEFAULT NULL,
  `position_designation` varchar(100) DEFAULT NULL,
  `specialization` varchar(100) DEFAULT NULL,
  `degree_course` varchar(150) DEFAULT NULL,
  `years_experience` int(11) DEFAULT 0,
  `highest_educ_attainment` varchar(50) DEFAULT NULL,
  `qualification_status` enum('Pending','Qualified','Not Qualified') DEFAULT 'Pending',
  `can_handle_shs` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_role` (`role_id`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(5,'student','$2y$10$fi2oWPQZXeoW4uE0X2rciuioiKHx5uAy0hdZYltTTb53T9c/jDuHi','yajiimnida@gmail.com',NULL,NULL,'Jay','Nuñez',NULL,NULL,NULL,4,1,0,NULL,NULL,NULL,'2026-07-29 12:39:09','2026-07-21 06:18:19','2026-07-29 04:39:09',NULL,NULL,NULL,NULL,0,NULL,'Pending',0),
(7,'teacher1','$2y$10$QFGOz5sEfqtzrqv.VOOnU.YWfE/WlHCOcDlX6N1bI4GR3QRtdr3uC','ASD@GMAIL.COM',NULL,NULL,'ASD','ASD','ASD',NULL,NULL,3,0,0,NULL,NULL,NULL,'2026-07-23 16:39:14','2026-07-22 03:46:19','2026-07-26 10:13:25',NULL,NULL,NULL,NULL,0,NULL,'Pending',0),
(9,'admin','$2y$10$XFfjQBFUro0h.HWyFDNOweVoCJighddN3NNSB8yaOb.Zjnm0Vb97S','admin@egradepro.com',NULL,'admin_9_1785390821.png','System','Administrator','',NULL,NULL,1,1,0,NULL,NULL,NULL,'2026-08-29 14:12:31','2026-07-23 05:55:27','2026-08-29 06:12:31',NULL,NULL,NULL,NULL,0,NULL,'Pending',0),
(11,'teacher2','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','teacher1@school.com',NULL,NULL,'Maria','Santos',NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,'2026-07-23 08:27:27','2026-07-26 10:13:34',NULL,NULL,NULL,NULL,0,NULL,'Pending',0),
(18,'teacher65','$2y$10$REuxshHzq1wegfECO8rsauXNGmwTnHUil3Noo83ME7tnkuoOlQ/qG','asdasdad@gmail.com',NULL,NULL,'Francis','Tejada','T.',NULL,NULL,3,1,0,NULL,NULL,NULL,NULL,'2026-08-15 09:16:40','2026-08-15 09:16:40','123123',NULL,'Filipino',NULL,1,NULL,'Qualified',0),
(19,'yohei','$2y$10$JTWpZTnY6qu1TcZZtqkGMe/ewm5G9GJ/ci/onJRikMlbjrUw0GJ.m','mark.evang22@gmail.com',NULL,NULL,'Yisoo','Evangelista','Mendoza',NULL,NULL,3,1,0,NULL,NULL,NULL,'2026-08-16 18:09:12','2026-08-15 11:40:53','2026-08-16 10:09:12','654687',NULL,'English',NULL,3,NULL,'Qualified',1),
(20,'teacherjay','$2y$10$YnpKjkRVl7t/JqMZaqEpzOOF6/VF09fzh1bgSqxZeL.mfVHtL5BrS','jaynunez017@gmail.com',NULL,'admin_20_1787828642.png','Jay','Nunez','Mendoza',NULL,NULL,3,1,0,NULL,'9ea050197f36293ebd20ca7e5da6451c5f94e218127291a5d4cbdaf45915efa7','2026-08-26 12:27:58','2026-08-27 18:58:25','2026-08-16 08:59:32','2026-08-27 11:04:02','123123',NULL,'Music',NULL,1,NULL,'Qualified',0),
(21,'alex','$2y$10$Bs7lp1SzOCQM5tWMjmBFguV1lR0NooEnPFTAXsOQvALb8zC6SWk2W','alexanderblancaver5@gmail.com',NULL,NULL,'as','asd','asd',NULL,NULL,3,1,0,NULL,'2db6a49217d9a6317b63a19bc761dff598fc88e2384f41753a1a23297bebdb32','2026-08-27 14:06:37','2026-08-29 14:25:03','2026-08-27 07:55:33','2026-08-29 06:25:03','654687',NULL,'Filipino',NULL,1,NULL,'Qualified',0);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `vw_active_enrollments`;
/*!50001 DROP VIEW IF EXISTS `vw_active_enrollments`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `vw_active_enrollments` AS SELECT
 1 AS `enrollment_id`,
  1 AS `student_id`,
  1 AS `lrn`,
  1 AS `student_name`,
  1 AS `grade_level`,
  1 AS `section_name`,
  1 AS `sy_name`,
  1 AS `enrollment_status`,
  1 AS `enrollment_date` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `vw_student_grades`;
/*!50001 DROP VIEW IF EXISTS `vw_student_grades`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `vw_student_grades` AS SELECT
 1 AS `student_id`,
  1 AS `lrn`,
  1 AS `student_name`,
  1 AS `subject_code`,
  1 AS `subject_name`,
  1 AS `term`,
  1 AS `written_work`,
  1 AS `performance_task`,
  1 AS `examinations`,
  1 AS `initial_grade`,
  1 AS `term_grade`,
  1 AS `final_grade`,
  1 AS `remarks`,
  1 AS `sy_name` */;
SET character_set_client = @saved_cs_client;
/*!50001 DROP VIEW IF EXISTS `vw_active_enrollments`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `vw_active_enrollments` AS select `e`.`enrollment_id` AS `enrollment_id`,`s`.`student_id` AS `student_id`,`s`.`lrn` AS `lrn`,concat(`s`.`last_name`,', ',`s`.`first_name`) AS `student_name`,`s`.`grade_level` AS `grade_level`,`sec`.`section_name` AS `section_name`,`sy`.`sy_name` AS `sy_name`,`e`.`enrollment_status` AS `enrollment_status`,`e`.`enrollment_date` AS `enrollment_date` from (((`enrollments` `e` join `students` `s` on(`e`.`student_id` = `s`.`student_id`)) left join `sections` `sec` on(`e`.`section_id` = `sec`.`section_id`)) join `school_years` `sy` on(`e`.`school_year_id` = `sy`.`sy_id`)) where `e`.`enrollment_status` = 'Approved' and `s`.`is_active` = 1 */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `vw_student_grades`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `vw_student_grades` AS select `s`.`student_id` AS `student_id`,`s`.`lrn` AS `lrn`,concat(`s`.`last_name`,', ',`s`.`first_name`) AS `student_name`,`sub`.`subject_code` AS `subject_code`,`sub`.`subject_name` AS `subject_name`,`g`.`term` AS `term`,`g`.`written_work` AS `written_work`,`g`.`performance_task` AS `performance_task`,`g`.`examinations` AS `examinations`,`g`.`initial_grade` AS `initial_grade`,`g`.`transmuted_grade` AS `term_grade`,`g`.`final_grade` AS `final_grade`,`g`.`remarks` AS `remarks`,`sy`.`sy_name` AS `sy_name` from (((`grades` `g` join `students` `s` on(`g`.`student_id` = `s`.`student_id`)) join `subjects` `sub` on(`g`.`subject_code` = `sub`.`subject_code`)) join `school_years` `sy` on(`g`.`school_year_id` = `sy`.`sy_id`)) where `g`.`term` in ('Term 1','Term 2','Term 3') and `g`.`remarks` not in ('Incomplete','Not configured') and `g`.`transmuted_grade` >= 60 */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- --------------------------------------------------------

--
-- Table structure for table `teacher_class_schedule`
-- Master schedule: one row per teacher/subject/section/day meeting.
--

CREATE TABLE `teacher_class_schedule` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `section_id` int(11) NOT NULL,
  `school_year_id` int(11) NOT NULL,
  `day_of_week` tinyint(4) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `shift_type` varchar(30) NOT NULL DEFAULT 'Custom',
  `shift` varchar(10) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`schedule_id`),
  KEY `idx_tcs_teacher_day` (`teacher_id`,`school_year_id`,`day_of_week`,`start_time`,`end_time`),
  KEY `idx_tcs_section_day` (`section_id`,`school_year_id`,`day_of_week`,`start_time`,`end_time`),
  KEY `idx_tcs_assignment` (`teacher_id`,`subject_code`,`section_id`,`school_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
