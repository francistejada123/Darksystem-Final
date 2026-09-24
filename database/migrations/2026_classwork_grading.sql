-- Classroom classwork as real, gradable work (Classwork -> Create, and
-- "Your Work" on the student's subject page).
--
-- Additive only. includes/classwork_helpers.php (classworkEnsureSchema())
-- applies all of this automatically on first use; this file documents it.

-- The assessment TYPE decides the grading category (never online/offline).
ALTER TABLE classwork_assignments
    ADD COLUMN IF NOT EXISTS grading_category VARCHAR(2) DEFAULT NULL COMMENT 'WW / PT / QA from the assessment type -- never from online/offline',
    ADD COLUMN IF NOT EXISTS assessment_item_id INT(11) DEFAULT NULL COMMENT 'Manage Assessment Items row the score goes to',
    ADD COLUMN IF NOT EXISTS max_points DECIMAL(6,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS assigned_at DATETIME DEFAULT NULL;
-- due_date (existing column) is the due date/time.

-- Each student's work: status Assigned -> Submitted (Turned In) -> Reviewed (graded).
ALTER TABLE classwork_assignment_students
    ADD COLUMN IF NOT EXISTS assigned_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS submitted_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS is_late TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = turned in after the due date (still accepted)',
    ADD COLUMN IF NOT EXISTS score DECIMAL(6,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS graded_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS teacher_feedback TEXT DEFAULT NULL;

-- Files a student attaches to their work.
CREATE TABLE IF NOT EXISTS classwork_submission_files (
    file_id INT(11) NOT NULL AUTO_INCREMENT,
    assignment_id INT(11) NOT NULL,
    student_id INT(11) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL COMMENT 'random name under static/uploads/classwork_submissions/',
    mime_type VARCHAR(100) DEFAULT NULL,
    file_size INT(11) DEFAULT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (file_id),
    KEY idx_csf (assignment_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Student uploads are stored outside the web-executable path rules already
-- applied to static/uploads/ (see static/uploads/.htaccess).
