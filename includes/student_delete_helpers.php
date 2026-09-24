<?php
// includes/student_delete_helpers.php
// ============================================================
// PERMANENT STUDENT DELETE — used by Enrollment Management
// (modules/enrollment/delete_student.php). Administrator only.
//
// WHY THIS IS NOT JUST "DELETE FROM students"
// -------------------------------------------
// A student is referenced from many tables. Some declare a foreign key with
// ON DELETE CASCADE, some declare none at all (the tables created by
// config/database.php and the 2026_*.sql migrations deliberately have no
// FKs), and which tables exist differs between installs. Deleting only the
// tables we know about would either fail on a foreign key or, worse, silently
// leave orphan rows behind (grades / attendance / AI resources / classwork
// links for a student that no longer exists).
//
// So instead of a hard-coded list this reads the LIVE schema:
//   * every base table with a `student_id` column,
//   * every column that a foreign key points at students.student_id,
//   * every table keyed by the student's enrollment_id(s),
// and deletes this one student's rows from each of them inside ONE
// transaction. Finally the `students` row itself is deleted; if anything
// still references it the database refuses, the whole transaction rolls back
// and NOTHING is deleted — so the result is always all-or-nothing.
//
// Files uploaded for the student (application documents, AI activity answer
// files, generated resource files, profile photo) are removed after the
// commit, best-effort, and only when they resolve inside the expected upload
// folder.
// ============================================================

/** Identifier safety: table/column names only ever come from information_schema. */
function egp_safeIdent($name) {
    return preg_match('/^[A-Za-z0-9_]+$/', (string)$name) ? '`' . $name . '`' : null;
}

/**
 * Does $table.$column exist in the current database (base tables only)?
 */
function egp_hasColumn(PDO $db, $table, $column) {
    $st = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS c
         JOIN information_schema.TABLES t
           ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
         WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = ? AND c.COLUMN_NAME = ?"
    );
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

/**
 * Base tables (not views) that have a given column, in the current database.
 * @return string[]
 */
function egp_tablesWithColumn(PDO $db, $column) {
    $st = $db->prepare(
        "SELECT c.TABLE_NAME AS t FROM information_schema.COLUMNS c
         JOIN information_schema.TABLES t
           ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
         WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = ?
         ORDER BY c.TABLE_NAME"
    );
    $st->execute([$column]);
    return array_map(function ($r) { return $r['t']; }, $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Columns (other than the conventional one) that a foreign key points at
 * $ref_table. @return array of [table, column]
 */
function egp_foreignKeyColumnsTo(PDO $db, $ref_table) {
    $st = $db->prepare(
        "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?"
    );
    $st->execute([$ref_table]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [$r['t'], $r['c']];
    }
    return $out;
}

/** DELETE FROM `t` WHERE `c` = ? / IN (...). Returns rows deleted. Throws PDOException. */
function egp_deleteWhere(PDO $db, $table, $column, array $values) {
    $t = egp_safeIdent($table);
    $c = egp_safeIdent($column);
    if ($t === null || $c === null || empty($values)) return 0;
    $ph = implode(',', array_fill(0, count($values), '?'));
    $st = $db->prepare("DELETE FROM $t WHERE $c IN ($ph)");
    $st->execute(array_values($values));
    return $st->rowCount();
}

/** A relative upload path resolved safely inside $base_dir, or null. */
function egp_safeUploadPath($base_dir, $relative_name) {
    $relative_name = (string)$relative_name;
    if ($relative_name === '') return null;
    $base = realpath($base_dir);
    if ($base === false) return null;
    $full = realpath($base . DIRECTORY_SEPARATOR . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relative_name), DIRECTORY_SEPARATOR));
    if ($full === false || !is_file($full)) return null;
    return (strpos($full, $base . DIRECTORY_SEPARATOR) === 0) ? $full : null;
}

/**
 * Permanently delete one student and everything that belongs to them.
 *
 * @param PDO $db            connection (ERRMODE_EXCEPTION)
 * @param int $student_id
 * @param int $actor_user_id the administrator doing it (for the activity log)
 * @return array {
 *   success  bool
 *   error    string|null   safe, user-facing
 *   student  array|null    name / lrn / grade / section, as they were
 *   deleted  array         table => rows deleted (only tables where rows were removed)
 * }
 */
function egp_deleteStudentPermanently(PDO $db, $student_id, $actor_user_id) {
    $student_id = (int)$student_id;
    $result = ['success' => false, 'error' => null, 'student' => null, 'deleted' => []];

    $root = realpath(__DIR__ . '/..');
    $files_to_remove = [];   // [base_dir, relative_name, kind, table, column]

    try {
        $db->beginTransaction();

        // Lock the row so two admins cannot delete / edit the same student at once.
        $st = $db->prepare(
            "SELECT s.*, sec.section_name
             FROM students s LEFT JOIN sections sec ON sec.section_id = s.section_id
             WHERE s.student_id = ? FOR UPDATE"
        );
        $st->execute([$student_id]);
        $student = $st->fetch(PDO::FETCH_ASSOC);
        if (!$student) {
            $db->rollBack();
            $result['error'] = 'Student not found. They may already have been deleted.';
            return $result;
        }
        $result['student'] = [
            'student_id' => $student_id,
            'name'       => trim($student['last_name'] . ', ' . $student['first_name']),
            'lrn'        => $student['lrn'],
            'grade'      => $student['grade_level'],
            'section'    => $student['section_name'],
        ];

        // ---- Ids that other tables are keyed by ------------------------------
        $st = $db->prepare("SELECT enrollment_id FROM enrollments WHERE student_id = ?");
        $st->execute([$student_id]);
        $enrollment_ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        $application_ids = [];
        if (egp_hasColumn($db, 'online_applications', 'student_id')) {
            $st = $db->prepare("SELECT application_id FROM online_applications WHERE student_id = ?");
            $st->execute([$student_id]);
            $application_ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (!empty($enrollment_ids)) {
                $ph = implode(',', array_fill(0, count($enrollment_ids), '?'));
                $st = $db->prepare("SELECT application_id FROM online_applications WHERE enrollment_id IN ($ph)");
                $st->execute($enrollment_ids);
                $application_ids = array_values(array_unique(array_merge($application_ids, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)))));
            }
        }

        $plan_ids = [];
        if (egp_hasColumn($db, 'tutorial_plans', 'student_id')) {
            $st = $db->prepare("SELECT plan_id FROM tutorial_plans WHERE student_id = ?");
            $st->execute([$student_id]);
            $plan_ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }

        // ---- Remember uploaded files (removed after the commit) --------------
        $uploads = $root . '/static/uploads';
        if (!empty($application_ids) && egp_hasColumn($db, 'application_documents', 'file_path')) {
            $ph = implode(',', array_fill(0, count($application_ids), '?'));
            $st = $db->prepare("SELECT file_path FROM application_documents WHERE application_id IN ($ph)");
            $st->execute($application_ids);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) {
                $files_to_remove[] = [$root, $p, 'application document', 'application_documents', 'file_path'];
            }
        }
        if (egp_hasColumn($db, 'ai_resource_submissions', 'answer_stored_filename')) {
            $st = $db->prepare("SELECT answer_stored_filename FROM ai_resource_submissions WHERE student_id = ? AND answer_stored_filename IS NOT NULL");
            $st->execute([$student_id]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) {
                $files_to_remove[] = [$uploads . '/ai_resource_answers', $p, 'AI activity answer', 'ai_resource_submissions', 'answer_stored_filename'];
            }
        }
        if (egp_hasColumn($db, 'ai_generated_resources', 'stored_filename')) {
            $st = $db->prepare("SELECT stored_filename FROM ai_generated_resources WHERE student_id = ? AND stored_filename IS NOT NULL");
            $st->execute([$student_id]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) {
                $files_to_remove[] = [$uploads . '/ai_resources', $p, 'AI generated file', 'ai_generated_resources', 'stored_filename'];
            }
        }
        if (!empty($student['profile_image'])) {
            $files_to_remove[] = [$uploads . '/profiles', $student['profile_image'], 'profile photo', 'students', 'profile_image'];
        }

        // ---- 1) Children that are keyed by a PARENT id, not by student_id ----
        // application_documents -> online_applications;  tutorial_plan_items -> tutorial_plans
        if (!empty($application_ids) && egp_hasColumn($db, 'application_documents', 'application_id')) {
            $n = egp_deleteWhere($db, 'application_documents', 'application_id', $application_ids);
            if ($n) $result['deleted']['application_documents'] = $n;
        }
        if (!empty($plan_ids) && egp_hasColumn($db, 'tutorial_plan_items', 'plan_id')) {
            $n = egp_deleteWhere($db, 'tutorial_plan_items', 'plan_id', $plan_ids);
            if ($n) $result['deleted']['tutorial_plan_items'] = $n;
        }

        // ---- 2) Tables keyed by the student's enrollment id(s) ---------------
        // (enrollment_requirements, ...). The applications table is handled
        // in step 3; enrollments themselves in step 4.
        if (!empty($enrollment_ids)) {
            $targets = [];
            foreach (egp_tablesWithColumn($db, 'enrollment_id') as $t) $targets[$t . '|enrollment_id'] = [$t, 'enrollment_id'];
            foreach (egp_foreignKeyColumnsTo($db, 'enrollments') as $tc) $targets[$tc[0] . '|' . $tc[1]] = $tc;
            foreach ($targets as $tc) {
                if (in_array($tc[0], ['enrollments', 'online_applications'], true)) continue;
                $n = egp_deleteWhere($db, $tc[0], $tc[1], $enrollment_ids);
                if ($n) $result['deleted'][$tc[0]] = ($result['deleted'][$tc[0]] ?? 0) + $n;
            }
        }

        // ---- 3) The student's online application(s) -------------------------
        // An application row pointing at a deleted student would be an orphan
        // (and its LRN would block the real student's application).
        if (!empty($application_ids)) {
            $n = egp_deleteWhere($db, 'online_applications', 'application_id', $application_ids);
            if ($n) $result['deleted']['online_applications'] = $n;
        }

        // ---- 4) Everything else that points at this student ------------------
        // student_id tables + any foreign key column that references students.
        $targets = [];
        foreach (egp_tablesWithColumn($db, 'student_id') as $t) $targets[$t . '|student_id'] = [$t, 'student_id'];
        foreach (egp_foreignKeyColumnsTo($db, 'students') as $tc) $targets[$tc[0] . '|' . $tc[1]] = $tc;
        unset($targets['students|student_id']);
        foreach ($targets as $k => $tc) {
            if ($tc[0] === 'students') unset($targets[$k]);
        }

        // Some tables may reference each other (e.g. submissions -> resources)
        // with a RESTRICT foreign key, so retry the ones a foreign key refused
        // until everything is gone; a pass with no progress means a real problem.
        $pending = array_values($targets);
        $last_error = null;
        for ($pass = 0; $pass < 6 && !empty($pending); $pass++) {
            $still = [];
            foreach ($pending as $tc) {
                try {
                    $n = egp_deleteWhere($db, $tc[0], $tc[1], [$student_id]);
                    if ($n) $result['deleted'][$tc[0]] = ($result['deleted'][$tc[0]] ?? 0) + $n;
                } catch (PDOException $e) {
                    $still[] = $tc;
                    $last_error = $e;
                }
            }
            if (count($still) === count($pending)) break;   // no progress
            $pending = $still;
        }
        if (!empty($pending)) {
            throw ($last_error ?: new RuntimeException('Some related records could not be removed.'));
        }

        // ---- 5) The student's login account, only if it is a plain student ----
        // Students sign in through the students table, so most have no users row.
        // If one is linked, remove it ONLY when its role is Student and no other
        // student shares it — never a teacher / admin account.
        if (!empty($student['user_id'])) {
            $uid = (int)$student['user_id'];
            $st = $db->prepare(
                "SELECT r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?"
            );
            $st->execute([$uid]);
            $role = $st->fetchColumn();
            $st = $db->prepare("SELECT COUNT(*) FROM students WHERE user_id = ? AND student_id <> ?");
            $st->execute([$uid, $student_id]);
            $shared = (int)$st->fetchColumn();

            if ($role === 'Student' && $shared === 0) {
                foreach (egp_tablesWithColumn($db, 'user_id') as $t) {
                    if (in_array($t, ['users', 'activity_logs', 'students'], true)) continue;
                    $n = egp_deleteWhere($db, $t, 'user_id', [$uid]);
                    if ($n) $result['deleted'][$t] = ($result['deleted'][$t] ?? 0) + $n;
                }
                $n = egp_deleteWhere($db, 'users', 'user_id', [$uid]);
                if ($n) $result['deleted']['users'] = $n;
            }
        }

        // ---- 6) The student. If anything still points here the DB refuses ------
        // and the whole transaction rolls back.
        $st = $db->prepare("DELETE FROM students WHERE student_id = ?");
        $st->execute([$student_id]);
        if ($st->rowCount() !== 1) {
            throw new RuntimeException('The student record could not be deleted.');
        }
        $result['deleted']['students'] = 1;

        // ---- 7) Keep the section head-count honest ---------------------------
        // The counter is incremented when an enrollment is approved and
        // decremented when a student leaves, so only an active, enrolled
        // student holds a seat. (A student already soft-deleted in Student
        // Management had their seat released then — do not release it twice.)
        if (!empty($student['section_id']) && (int)$student['is_active'] === 1
            && $student['enrollment_status'] === 'Enrolled') {
            $st = $db->prepare("UPDATE sections SET current_enrollment = GREATEST(current_enrollment - 1, 0) WHERE section_id = ?");
            $st->execute([(int)$student['section_id']]);
        }

        // ---- 8) Audit trail ----------------------------------------------------
        logActivity(
            (int)$actor_user_id, 'PERMANENT DELETE', 'enrollment',
            'Permanently deleted student ' . $result['student']['name'] . ' (LRN: ' . $student['lrn'] . ')'
            . ' with related records: ' . json_encode($result['deleted'])
        );

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Permanent student delete failed for student_id ' . $student_id . ': ' . $e->getMessage());
        $result['success'] = false;
        $result['deleted'] = [];
        $result['error'] = 'The student could not be deleted, so nothing was removed. '
                         . 'Please try again, or check the server error log if it keeps happening.';
        return $result;
    }

    // ---- Uploaded files: after the commit, best-effort ------------------------
    foreach ($files_to_remove as $f) {
        list($base, $rel, $kind, $table, $column) = $f;
        try {
            // Files that another row still uses are kept.
            if ($table !== 'application_documents') {
                $tq = egp_safeIdent($table); $cq = egp_safeIdent($column);
                if ($tq && $cq) {
                    $st = $db->prepare("SELECT COUNT(*) FROM $tq WHERE $cq = ?");
                    $st->execute([$rel]);
                    if ((int)$st->fetchColumn() > 0) continue;
                }
            }
            $path = egp_safeUploadPath($base, $rel);
            if ($path !== null) @unlink($path);
        } catch (Throwable $e) {
            error_log('Could not remove ' . $kind . ' file: ' . $e->getMessage());
        }
    }

    $result['success'] = true;
    return $result;
}
