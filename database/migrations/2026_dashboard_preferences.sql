-- ============================================================
-- 2026_dashboard_preferences.sql
-- SAFE, ADDITIVE migration for the Customizable Admin/Registrar
-- Dashboard feature (Ask AI / grading / enrollment tables are NOT touched).
--
-- Adds ONE new table: `dashboard_preferences`. Nothing existing is
-- dropped, altered, renamed, or re-keyed. Safe to run on the live
-- database at any time; safe to re-run (uses IF NOT EXISTS).
--
-- Run against the `egradepro` database, e.g.:
--   mysql -u root egradepro < database/migrations/2026_dashboard_preferences.sql
-- or import via phpMyAdmin.
-- ============================================================

CREATE TABLE IF NOT EXISTS `dashboard_preferences` (
  `preference_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `widget_key` varchar(50) NOT NULL,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`preference_id`),
  UNIQUE KEY `uniq_user_widget` (`user_id`,`widget_key`),
  KEY `idx_dashboard_preferences_user` (`user_id`),
  CONSTRAINT `fk_dashboard_preferences_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
