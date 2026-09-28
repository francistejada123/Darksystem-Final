<?php
// includes/eo_scope.php
// Enrollment Officer grade-level assignment.
//
// Each Enrollment Officer account is assigned ONE grade level
// (users.assigned_grade_level), and each grade level has at most ONE
// Enrollment Officer (UNIQUE index -- the database refuses a second one).
// A signed-in Enrollment Officer only sees and opens enrollment records of
// their own grade level; every query and record check goes through the
// helpers below. Administrators (and every other role) are not restricted.

require_once __DIR__ . '/../config/database.php';

const EO_GRADE_LEVELS = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];

function eoEnsureSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('eo_grade_assignment_v1')) return;
    try {
        if (!getRecord("SHOW COLUMNS FROM users LIKE 'assigned_grade_level'")) {
            executeQuery("ALTER TABLE users ADD COLUMN assigned_grade_level VARCHAR(20) NULL DEFAULT NULL COMMENT 'Enrollment Officer: the one grade level they handle'");
        }
        if (!getRecord("SHOW INDEX FROM users WHERE Key_name = 'uniq_eo_grade'")) {
            executeQuery("ALTER TABLE users ADD UNIQUE KEY uniq_eo_grade (assigned_grade_level)");
        }
        schemaMarkReady('eo_grade_assignment_v1');
    } catch (\Throwable $e) {
        error_log('eoEnsureSchema: ' . $e->getMessage());
    }
}

/** Is the signed-in user an Enrollment Officer (restricted to one grade)? */
function eoRestricted()
{
    return function_exists('hasRole') && hasRole('Enrollment Officer') && !hasRole('Administrator');
}

/** The signed-in Enrollment Officer's grade ('' when none is assigned); null for everyone else. */
function eoGrade()
{
    static $g = false;
    if (!eoRestricted()) return null;
    if ($g === false) {
        eoEnsureSchema();
        $r = getRecord("SELECT assigned_grade_level FROM users WHERE user_id = ?", [(int) ($_SESSION['user_id'] ?? 0)]);
        $g = (string) ($r['assigned_grade_level'] ?? '');
    }
    return $g;
}

/**
 * SQL filter for a grade-level column: ['', []] for unrestricted users,
 * [' AND col = ?', [grade]] for an Enrollment Officer, and [' AND 1 = 0', []]
 * for an Enrollment Officer with no grade assigned (sees nothing).
 */
function eoSql($column)
{
    $g = eoGrade();
    if ($g === null) return ['', []];
    if ($g === '') return [' AND 1 = 0', []];
    return [' AND ' . $column . ' = ?', [$g]];
}

/** May the signed-in user open a record of this grade level? */
function eoCanGrade($gradeLevel)
{
    $g = eoGrade();
    return $g === null || ($g !== '' && (string) $gradeLevel === $g);
}

/** Stop here (flash + redirect) when the record is outside the officer's grade level. */
function eoGuard($gradeLevel, $back = 'index.php')
{
    if (eoCanGrade($gradeLevel)) return;
    $g = eoGrade();
    $_SESSION['error_message'] = $g === ''
        ? 'No grade level is assigned to your Enrollment Officer account yet. Please ask the Administrator.'
        : 'You can only open ' . $g . ' enrollment records.';
    logActivity($_SESSION['user_id'] ?? null, 'enrollment', 'Blocked record access', 'Enrollment Officer (' . ($g ?: 'no grade') . ') tried to open a ' . $gradeLevel . ' record');
    header('Location: ' . $back);
    exit;
}

/** Grade already taken by another Enrollment Officer? Returns that officer's username, or null. */
function eoGradeTakenBy($gradeLevel, $exceptUserId = 0)
{
    eoEnsureSchema();
    $r = getRecord("SELECT username FROM users WHERE assigned_grade_level = ? AND user_id <> ? LIMIT 1", [$gradeLevel, (int) $exceptUserId]);
    return $r ? $r['username'] : null;
}
