-- 2026_term_windows_and_plans.sql
-- Additive migration -- creates two new tables only. Nothing existing is
-- altered, dropped, or backfilled destructively.
--
-- 1) term_windows
--    Per-school-year, per-term Start Date / End Date, set by a Teacher or
--    Administrator in Manage Assessment Items. One row per (school_year_id,
--    term) -- Term 1/2/3 calendar dates are school-wide, not per class, so
--    whoever sets them first defines the window and anyone can update it
--    afterward (ON DUPLICATE KEY UPDATE). Used to compute the real-time
--    Upcoming / Ongoing / Ended status shown in Manage Assessment Items and
--    Encode Grades. A missing row simply means "dates not set yet" -- it
--    never blocks encoding or finalizing, it only affects the status label.
--
-- 2) assessment_plans
--    Optional, per-class "how many items do I expect this term" plan (e.g.
--    5 quizzes, 3 activities counted together under Written Works, 5
--    Performance Tasks). Purely informational -- shown as an "X of N added"
--    progress badge in Manage Assessment Items. Adjustable at any time by
--    the teacher; it never limits how many items can actually be added, and
--    is never read by any grade computation.

CREATE TABLE IF NOT EXISTS term_windows (
  window_id INT AUTO_INCREMENT PRIMARY KEY,
  school_year_id INT NOT NULL,
  term VARCHAR(20) NOT NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  updated_by INT NULL COMMENT 'users.user_id of the Teacher/Administrator who last set these dates',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_term_window (school_year_id, term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS assessment_plans (
  plan_id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  subject_code VARCHAR(20) NOT NULL,
  section_id INT NOT NULL,
  school_year_id INT NOT NULL,
  term VARCHAR(20) NOT NULL,
  category VARCHAR(5) NOT NULL COMMENT 'WW, PT, or QA -- same codes as assessment_items.category',
  planned_count INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_assessment_plan (teacher_id, subject_code, section_id, school_year_id, term, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
