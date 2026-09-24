-- ============================================================
-- 2026_enrollment_officer_role.sql
-- SAFE, ADDITIVE migration for the new "Enrollment Officer" role.
--
-- Adds ONE new row to the existing `roles` table. Does NOT touch,
-- renumber, rename, or delete any of the existing roles (1 Administrator,
-- 2 Registrar, 3 Teacher, 4 Student, 5 Parent) — Registrar is untouched
-- and is NOT being converted into anything.
--
-- Safe to run on the live database at any time; safe to re-run (the
-- WHERE NOT EXISTS guard makes it a no-op if the role already exists).
--
-- Run against the `egradepro` database, e.g.:
--   mysql -u root egradepro < database/migrations/2026_enrollment_officer_role.sql
-- or import via phpMyAdmin.
-- ============================================================

INSERT INTO `roles` (`role_id`, `role_name`, `role_description`)
SELECT 6, 'Enrollment Officer',
       'Reviews and processes online enrollment applications and the enrollment records they create. Does not have full Administrator or Registrar access.'
WHERE NOT EXISTS (
    SELECT 1 FROM `roles` WHERE `role_id` = 6 OR `role_name` = 'Enrollment Officer'
);
