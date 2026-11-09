<?php
// includes/teacher_department.php
// Single source of truth for the Teacher Department / Level restriction.
//
// A teacher registered under a Department / Level (users.department_level,
// saved by teacher_register.php / includes/teacher_invitations.php and shown
// on the Teacher Profile) may only be assigned to classes of that department:
//
//   JHS -> Grade 7, 8, 9, 10   (no strand; Junior High subjects have no term)
//   SHS -> Grade 11, 12        (strand-specific, term-specific subjects)
//
// Nothing here reads or writes the database on its own and no historical row is
// ever deleted -- these helpers only answer "may this teacher be assigned
// here?" and "which grade levels / rows belong to this teacher?". Existing
// assignments that violate the rule are reported as flags for the Admin.
//
// Purely additive helper file: it adds no column and touches no table.

/** Grade levels a teacher of each department may be assigned to. */
const TD_JHS_GRADES = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'];
const TD_SHS_GRADES = ['Grade 11', 'Grade 12'];

/** Normalises a stored department value; '' means "not set on file". */
function tdNormalize($department)
{
    $d = strtoupper(trim((string) $department));
    return in_array($d, ['JHS', 'SHS'], true) ? $d : '';
}

/** Grade levels allowed for a department ('JHS' / 'SHS'). */
function tdGradesFor($department)
{
    return tdNormalize($department) === 'SHS' ? TD_SHS_GRADES : TD_JHS_GRADES;
}

/**
 * Is this grade level inside the department? An unset department
 * (legacy teacher rows that predate the field) allows both levels so no
 * existing teacher is locked out of anything until an Admin sets it.
 */
function tdGradeAllowed($department, $gradeLevel)
{
    $dept = tdNormalize($department);
    if ($dept === '')
        return true;
    return in_array((string) $gradeLevel, tdGradesFor($dept), true);
}

/**
 * Restriction check for one assignment.
 *
 * @param string $department users.department_level of the teacher
 * @param string $gradeLevel grade_level of the section or subject
 * @return array{ok:bool, message:string}
 */
function tdCheckAssignment($department, $gradeLevel)
{
    if (tdGradeAllowed($department, $gradeLevel)) {
        return ['ok' => true, 'message' => ''];
    }
    $dept = tdNormalize($department) ?: 'their department';
    $allowed = tdGradesFor($dept);
    return [
        'ok' => false,
        'message' => sprintf(
            'Department restriction: this teacher is %s and may only be assigned to %s, so "%s" is not allowed. '
            . 'Correct the teacher\'s Department / Level, or pick a %s class instead.',
            htmlspecialchars($dept),
            htmlspecialchars(implode(', ', $allowed)),
            htmlspecialchars((string) $gradeLevel),
            htmlspecialchars(implode(' / ', $allowed))
        )
    ];
}

/**
 * Loads users.department_level for a teacher (role_id = 3).
 * Returns '' when the teacher does not exist or has no department on file.
 */
function tdDepartmentFor($teacherId)
{
    $row = getRecord(
        'SELECT department_level FROM users WHERE user_id = ? AND role_id = 3',
        [(int) $teacherId]
    );
    return tdNormalize($row['department_level'] ?? '');
}

/**
 * SQL fragment + params that limit a query on an already-joined section
 * column to the department's grade levels. Returns ['', []] when the teacher
 * has no department on file (nothing is filtered out).
 *
 * @param string $sectionAlias alias of the joined `sections` table, e.g. 'sec'
 */
function tdGradeSqlFilter($teacherId, $sectionAlias = 'sec')
{
    $dept = tdDepartmentFor($teacherId);
    if ($dept === '')
        return ['', []];
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', (string) $sectionAlias);
    $grades = tdGradesFor($dept);
    $in = implode(',', array_fill(0, count($grades), '?'));
    return [" AND {$alias}.grade_level IN ({$in}) ", $grades];
}

/** Keeps only the rows of $rows whose 'grade_level' matches the department. */
function tdFilterRows(array $rows, $department, string $key = 'grade_level'): array
{
    return array_values(array_filter($rows, function ($row) use ($department, $key) {
        return tdGradeAllowed($department, $row[$key] ?? '');
    }));
}

/**
 * Existing assignments that violate the teacher's department, for the active
 * school year. These are NEVER deleted here -- they are reported so an Admin
 * can correct them.
 *
 * @return array<int, array{ts_id:int, teacher_id:int, teacher_name:string,
 *     subject_code:string, subject_name:string, section_id:int,
 *     section_name:string, grade_level:string, department:string}>
 */
function tdConflictingAssignments($teacherId = 0)
{
    $params = [];
    $where = 'ts.school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1 LIMIT 1)';
    if ((int) $teacherId > 0) {
        $where .= ' AND ts.teacher_id = ?';
        $params[] = (int) $teacherId;
    }
    $rows = getRecords(
        "SELECT ts.ts_id, ts.teacher_id, ts.subject_code, ts.section_id,
                CONCAT(TRIM(u.first_name), ' ', TRIM(u.last_name)) AS teacher_name,
                u.department_level,
                COALESCE(sub.subject_name, ts.subject_code) AS subject_name,
                sec.section_name, sec.grade_level
         FROM teacher_subjects ts
         JOIN sections sec ON sec.section_id = ts.section_id
         LEFT JOIN subjects sub ON sub.subject_code = ts.subject_code
         JOIN users u ON u.user_id = ts.teacher_id
         WHERE {$where}",
        $params
    );

    $conflicts = [];
    foreach ($rows as $row) {
        if (tdGradeAllowed($row['department_level'], $row['grade_level']))
            continue;
        $row['ts_id'] = (int) $row['ts_id'];
        $row['teacher_id'] = (int) $row['teacher_id'];
        $row['section_id'] = (int) $row['section_id'];
        $row['department'] = tdNormalize($row['department_level']);
        unset($row['department_level']);
        $conflicts[] = $row;
    }
    return $conflicts;
}