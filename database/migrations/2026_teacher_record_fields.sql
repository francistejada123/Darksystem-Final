-- Add Teacher form: Teacher ID / Employee ID and Department / Level.
-- add.php creates these automatically if they are missing; run this only if you
-- prefer to add them by hand (phpMyAdmin). Safe to skip if the columns already exist.
ALTER TABLE `users` ADD COLUMN `employee_id` VARCHAR(50) NULL DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `department_level` VARCHAR(10) NULL DEFAULT NULL;
