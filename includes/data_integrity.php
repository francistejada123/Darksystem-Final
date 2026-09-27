<?php
// includes/data_integrity.php
// Database rules added once (each behind its own schema flag), so the database
// itself refuses the mistakes the pages already check for:
//
//   - enrollment_requirements.enrollment_id -> enrollments  (ON DELETE CASCADE)
//   - online_applications.student_id / enrollment_id -> students / enrollments
//                                                          (ON DELETE SET NULL)
//   - teacher_class_schedule.teacher_id -> users           (ON DELETE CASCADE)
//   - teacher_subjects: one teacher per subject, section and school year (UNIQUE)
//   - students.email: one student per email (UNIQUE; blank is stored as NULL)
//
// Rows that already point at something deleted are cleaned first (they cannot
// be used anyway). A rule that still cannot be added (e.g. two students already
// share an email) is skipped and retried on a later request; the pages keep
// checking in the meantime. Loaded from config/database.php.

function diEnsureIntegrity()
{
    static $done = false;
    if ($done || !function_exists('schemaIsReady')) return;
    $done = true;

    $fk = function ($table, $name) {
        return (bool) getRecord(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$table, $name]
        );
    };
    $idx = function ($table, $name) {
        return (bool) getRecord("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?", [$table, $name]);
    };
    $run = function ($flag, callable $fn) {
        if (schemaIsReady($flag)) return;
        try {
            if ($fn() !== false) schemaMarkReady($flag);
        } catch (\Throwable $e) {
            error_log("data_integrity $flag: " . $e->getMessage());
        }
    };

    // Enrollment requirements belong to an enrollment.
    $run('di_fk_enrollment_requirements_v1', function () use ($fk) {
        executeQuery("DELETE r FROM enrollment_requirements r LEFT JOIN enrollments e ON e.enrollment_id = r.enrollment_id WHERE e.enrollment_id IS NULL");
        if (!$fk('enrollment_requirements', 'fk_er_enrollment')) {
            executeQuery("ALTER TABLE enrollment_requirements ADD CONSTRAINT fk_er_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollments (enrollment_id) ON DELETE CASCADE");
        }
    });

    // An application keeps its history when the student/enrollment is removed.
    $run('di_fk_online_applications_v1', function () use ($fk) {
        executeQuery("UPDATE online_applications a LEFT JOIN students s ON s.student_id = a.student_id SET a.student_id = NULL WHERE a.student_id IS NOT NULL AND s.student_id IS NULL");
        executeQuery("UPDATE online_applications a LEFT JOIN enrollments e ON e.enrollment_id = a.enrollment_id SET a.enrollment_id = NULL WHERE a.enrollment_id IS NOT NULL AND e.enrollment_id IS NULL");
        if (!$fk('online_applications', 'fk_oa_student')) {
            executeQuery("ALTER TABLE online_applications ADD CONSTRAINT fk_oa_student FOREIGN KEY (student_id) REFERENCES students (student_id) ON DELETE SET NULL");
        }
        if (!$fk('online_applications', 'fk_oa_enrollment')) {
            executeQuery("ALTER TABLE online_applications ADD CONSTRAINT fk_oa_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollments (enrollment_id) ON DELETE SET NULL");
        }
    });

    // A class schedule belongs to a teacher account.
    $run('di_fk_teacher_schedule_v1', function () use ($fk) {
        executeQuery("DELETE c FROM teacher_class_schedule c LEFT JOIN users u ON u.user_id = c.teacher_id WHERE u.user_id IS NULL");
        if (!$fk('teacher_class_schedule', 'fk_tcs_teacher')) {
            executeQuery("ALTER TABLE teacher_class_schedule ADD CONSTRAINT fk_tcs_teacher FOREIGN KEY (teacher_id) REFERENCES users (user_id) ON DELETE CASCADE");
        }
    });

    // One teacher per subject in a section per school year.
    $run('di_uniq_teacher_subjects_v1', function () use ($idx) {
        if ($idx('teacher_subjects', 'uniq_ts_subject_section_sy')) return true;
        if (getRecord("SELECT 1 FROM teacher_subjects GROUP BY subject_code, section_id, school_year_id HAVING COUNT(*) > 1 LIMIT 1")) {
            error_log('data_integrity: a subject has two teachers in one section; UNIQUE rule not added yet.');
            return false;
        }
        executeQuery("ALTER TABLE teacher_subjects ADD UNIQUE KEY uniq_ts_subject_section_sy (subject_code, section_id, school_year_id)");
    });

    // One student per email (blank = no email = NULL, allowed for many).
    $run('di_uniq_students_email_v1', function () use ($idx) {
        executeQuery("UPDATE students SET email = NULL WHERE email IS NOT NULL AND TRIM(email) = ''");
        foreach (['INSERT', 'UPDATE'] as $ev) {
            $name = 'trg_students_email_blank_' . strtolower($ev);
            if (!getRecord("SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?", [$name])) {
                executeQuery("CREATE TRIGGER $name BEFORE $ev ON students FOR EACH ROW SET NEW.email = NULLIF(TRIM(NEW.email), '')");
            }
        }
        if ($idx('students', 'uniq_students_email')) return true;
        if (getRecord("SELECT 1 FROM students WHERE email IS NOT NULL GROUP BY LOWER(email) HAVING COUNT(*) > 1 LIMIT 1")) {
            error_log('data_integrity: two students share an email; UNIQUE rule not added yet.');
            return false;
        }
        executeQuery("ALTER TABLE students ADD UNIQUE KEY uniq_students_email (email)");
    });
}

/**
 * Who already uses this email? Checks students and staff/teacher accounts.
 * @return string|null e.g. "student Dela Cruz, Mark" / "teacher account Nunez, Jay"
 */
function diEmailOwner($email, $exceptStudentId = 0, $exceptUserId = 0)
{
    $email = strtolower(trim((string) $email));
    if ($email === '') return null;
    $s = getRecord("SELECT first_name, last_name FROM students WHERE LOWER(email) = ? AND student_id <> ? LIMIT 1", [$email, (int) $exceptStudentId]);
    if ($s) return 'student ' . $s['last_name'] . ', ' . $s['first_name'];
    $u = getRecord("SELECT u.first_name, u.last_name, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE LOWER(u.email) = ? AND u.user_id <> ? LIMIT 1", [$email, (int) $exceptUserId]);
    if ($u) return strtolower($u['role_name']) . ' account ' . $u['last_name'] . ', ' . $u['first_name'];
    return null;
}
