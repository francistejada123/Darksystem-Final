-- 2026_sync_approved_online_applications.sql
-- ONE-TIME repair (safe to run more than once).
--
-- Before this update, approving an ONLINE application created the student /
-- enrollment as "Pending" and needed a second manual approval, so applicants the
-- Admin had already approved kept showing "Pending" under Recently Enrolled and
-- their LRN was not clickable. From now on the approval sets Approved / Enrolled
-- right away; this script fixes the applications that were approved earlier.
--
-- It only touches rows that belong to an application whose status is 'Approved'
-- AND that are still 'Pending'. Walk-in enrollments and students that are
-- genuinely waiting for approval are NOT affected. sections.current_enrollment is
-- NOT changed (the approval already counted these students once).

UPDATE enrollments e
JOIN online_applications a ON a.enrollment_id = e.enrollment_id
SET e.enrollment_status = 'Approved'
WHERE a.status = 'Approved'
  AND e.enrollment_status = 'Pending';

UPDATE students s
JOIN online_applications a ON a.student_id = s.student_id
SET s.enrollment_status = 'Enrolled'
WHERE a.status = 'Approved'
  AND s.enrollment_status = 'Pending';
