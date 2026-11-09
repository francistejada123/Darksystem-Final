-- 2026_document_requests.sql
-- OPTIONAL: the app creates this table automatically on first use
-- (includes/document_request_helpers.php, drEnsureSchema()). Additive only:
-- one NEW table; no existing table or column is changed.
-- Official Document Requests (e.g. Certificate of Enrollment for 4Ps,
-- scholarship, financial assistance). Separate from Enrollment Registration.

CREATE TABLE IF NOT EXISTS document_requests (
  request_id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  enrollment_id INT NULL,
  school_year_id INT NULL,
  grade_level VARCHAR(20) NULL COMMENT 'at the time of the request',
  strand VARCHAR(50) NULL COMMENT 'at the time of the request (SHS)',
  section_id INT NULL COMMENT 'at the time of the request',
  document_type VARCHAR(100) NOT NULL,
  document_other VARCHAR(255) NULL COMMENT 'name of the document when document_type = Other Document',
  purpose VARCHAR(50) NOT NULL,
  purpose_other VARCHAR(255) NULL,
  note TEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Pending',
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processing_at DATETIME NULL,
  ready_at DATETIME NULL,
  released_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  cancelled_by_student TINYINT(1) NOT NULL DEFAULT 0,
  claiming_date DATE NULL COMMENT 'scheduled claiming date (set when marked Ready for Release)',
  claiming_time_from TIME NULL,
  claiming_time_until TIME NULL,
  claiming_location VARCHAR(150) NULL,
  claiming_instructions TEXT NULL,
  processed_by INT NULL COMMENT 'users.user_id of the last staff action',
  released_by INT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_dr_student (student_id),
  KEY idx_dr_enrollment (enrollment_id),
  KEY idx_dr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- v2 (Document types update): a table created before gets the new column; existing requests are kept.
ALTER TABLE document_requests
  ADD COLUMN IF NOT EXISTS document_other VARCHAR(255) NULL
  COMMENT 'name of the document when document_type = Other Document' AFTER document_type;

-- v3 (Claiming / Release details): additive, existing requests are kept.
ALTER TABLE document_requests
  ADD COLUMN IF NOT EXISTS claiming_date DATE NULL AFTER cancelled_by_student,
  ADD COLUMN IF NOT EXISTS claiming_time_from TIME NULL AFTER claiming_date,
  ADD COLUMN IF NOT EXISTS claiming_time_until TIME NULL AFTER claiming_time_from,
  ADD COLUMN IF NOT EXISTS claiming_location VARCHAR(150) NULL AFTER claiming_time_until,
  ADD COLUMN IF NOT EXISTS claiming_instructions TEXT NULL AFTER claiming_location;

INSERT INTO system_settings (setting_key, setting_value, setting_group)
VALUES ('schema_ok_document_requests_v1', '1', 'system'), ('schema_ok_document_requests_v2', '1', 'system'), ('schema_ok_document_requests_v3', '1', 'system')
ON DUPLICATE KEY UPDATE setting_value = '1';

-- Document type labels are defined in one place: DR_DOC_* in includes/document_request_helpers.php.
