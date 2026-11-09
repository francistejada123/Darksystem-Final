-- Requirements Status is separate from the application / enrollment status.
--   online_applications.status              : Pending / Under Review / Incomplete Requirements / Approved
--   online_applications.requirements_status : Complete / Incomplete Requirements
-- An Approved application can still have pending requirements (e.g. Form 137 To Follow).
-- (The app also creates these by itself on first use; running this file is optional.)
ALTER TABLE online_applications
  ADD COLUMN IF NOT EXISTS requirements_status ENUM('Complete','Incomplete Requirements') NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS requirements_updated_at DATETIME NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS online_app_requirements (
  requirement_id INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  doc_key VARCHAR(40) NOT NULL,
  label VARCHAR(150) NOT NULL,
  status ENUM('To Follow','Received','Verified') NOT NULL DEFAULT 'To Follow',
  updated_by INT NULL,
  updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_app_doc (application_id, doc_key),
  KEY idx_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
