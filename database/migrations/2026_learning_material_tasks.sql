-- Learning Materials -> "Add to All Students" (Teacher Portal, Manage Assessment Items)
--
-- Additive only. includes/assessment_material_helpers.php (aim_ensureTable())
-- creates these automatically on first use, so importing this file is
-- optional; it is here so the schema change is documented.

-- Per-file topic (an Examination can cover several topics, one file each).
ALTER TABLE assessment_item_materials
    ADD COLUMN IF NOT EXISTS topic VARCHAR(150) DEFAULT NULL
    COMMENT 'Optional topic this one file covers (Examinations may cover several topics).' AFTER title;

-- One task per Quiz / Examination item: its setup and availability window.
CREATE TABLE IF NOT EXISTS learning_material_tasks (
    task_id INT(11) NOT NULL AUTO_INCREMENT,
    item_id INT(11) NOT NULL COMMENT 'assessment_items.item_id -- the Quiz/Examination whose score marks a student Completed.',
    teacher_id INT(11) NOT NULL,
    subject_code VARCHAR(20) NOT NULL,
    section_id INT(11) NOT NULL,
    school_year_id INT(11) NOT NULL,
    term VARCHAR(20) NOT NULL,
    assessment_type ENUM('Quiz','Examination') NOT NULL DEFAULT 'Quiz',
    exam_term VARCHAR(20) DEFAULT NULL COMMENT 'Examinations only: Term 1 / Term 2 / Term 3.',
    title VARCHAR(255) NOT NULL,
    topic VARCHAR(150) DEFAULT NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (task_id),
    UNIQUE KEY uniq_lmt_item (item_id),
    KEY idx_lmt_class (teacher_id, subject_code, section_id, school_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Which students received it, and their stored status. Upcoming/Active are
-- not stored (they come from start_at/end_at); Missing is set automatically
-- once end_at passes with no score in assessment_scores.
CREATE TABLE IF NOT EXISTS learning_material_task_students (
    id INT(11) NOT NULL AUTO_INCREMENT,
    task_id INT(11) NOT NULL,
    student_id INT(11) NOT NULL,
    status ENUM('Assigned','Completed','Missing') NOT NULL DEFAULT 'Assigned',
    completed_at DATETIME DEFAULT NULL,
    missing_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_lmts (task_id, student_id),
    KEY idx_lmts_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- "Send to <item> AI": a Teacher AI draft generated for an existing
-- Manage Assessment Items row. Sending it records scores on that item
-- (no duplicate item). Created automatically by aim_ensureDraftLinkColumn().
ALTER TABLE teacher_ai_resources
    ADD COLUMN IF NOT EXISTS assessment_item_id INT(11) DEFAULT NULL
    COMMENT 'assessment_items.item_id this draft was generated for (Send to <item> AI).';
