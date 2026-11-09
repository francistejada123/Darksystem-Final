-- Form 137 follow-up status for online applications (New Student / Transferee).
-- Separate from online_applications.status. NULL = not set by staff yet; the app then
-- shows 'Received' if a Form 137 was uploaded with the application, otherwise 'To Follow'.
-- (The app also adds this column by itself on first use; running this file is optional.)
ALTER TABLE online_applications
  ADD COLUMN IF NOT EXISTS form137_status ENUM('To Follow','Received','Verified') NULL DEFAULT NULL;
