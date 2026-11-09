<?php
// modules/users/delete.php
// PERMANENTLY delete a user account (Administrator only).
//
// Safety rules:
//  - POST + CSRF token only
//  - cannot delete your own account
//  - the account must already be Inactive (deactivate first)
//  - blocked if the user is linked to ANY record (students, subjects, grades, sections, applications,
//    logs of work done, etc.) so no grade / enrollment / audit data is ever lost or orphaned.
//    In that case the account should simply stay deactivated.

require_once '../../config/database.php';
require_once '../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['error_message'] = 'Invalid request. Please try again.';
    header('Location: index.php');
    exit;
}

$user_id = intval($_POST['id'] ?? 0);
if ($user_id <= 0) {
    header('Location: index.php');
    exit;
}

if ($user_id == $_SESSION['user_id']) {
    $_SESSION['error_message'] = 'You cannot delete your own account.';
    header('Location: index.php');
    exit;
}

$user = getRecord("SELECT user_id, username, first_name, last_name, is_active FROM users WHERE user_id = ?", [$user_id]);
if (!$user) {
    $_SESSION['error_message'] = 'User not found.';
    header('Location: index.php');
    exit;
}

if ((int) $user['is_active'] === 1) {
    $_SESSION['error_message'] = 'Deactivate this account first before deleting it permanently.';
    header('Location: index.php');
    exit;
}

// ---- Find any records that still point to this user -------------------------------------------
// 1) Every foreign key that references users.user_id (read from the database itself, so new tables are covered).
//    activity_logs (audit trail, ON DELETE SET NULL) and notifications (the user's own inbox) don't block.
$ignore = ['activity_logs', 'notifications'];
$linked = [];

$fk_cols = getRecords("
    SELECT TABLE_NAME AS t, COLUMN_NAME AS c
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND REFERENCED_TABLE_NAME = 'users' AND REFERENCED_COLUMN_NAME = 'user_id'
");
// 2) Columns that store a user id without a foreign key.
$extra_cols = [
    ['online_applications', 'reviewed_by'], ['online_applications', 'deleted_by'],
    ['student_info_corrections', 'reviewed_by'], ['classwork_comments', 'author_id'],
    ['student_message_comments', 'author_id'], ['section_term_status', 'completed_by'],
    ['student_year_status', 'computed_by'], ['teacher_invitations', 'invited_by'],
    ['term_finalizations', 'finalized_by'], ['term_windows', 'updated_by'],
    ['teacher_ai_resource_recipients', 'recipient_id'],
];

$pairs = [];
foreach ($fk_cols ?: [] as $r) { $pairs[$r['t'] . '.' . $r['c']] = [$r['t'], $r['c']]; }
foreach ($extra_cols as $p)    { $pairs[$p[0] . '.' . $p[1]] = $p; }

foreach ($pairs as [$tbl, $col]) {
    if (in_array($tbl, $ignore, true)) continue;
    if (!preg_match('/^\w+$/', $tbl) || !preg_match('/^\w+$/', $col)) continue;
    try {
        $row = getRecord("SELECT COUNT(*) AS n FROM `$tbl` WHERE `$col` = ?", [$user_id]);
        if ($row && (int) $row['n'] > 0) { $linked[$tbl] = true; }
    } catch (\Throwable $e) {
        // table/column not present in this database copy — nothing to protect there
    }
}

$full_name = trim($user['first_name'] . ' ' . $user['last_name']);

if (!empty($linked)) {
    $_SESSION['error_message'] = "Cannot permanently delete $full_name — this account is linked to existing records. Keep it deactivated instead.";
    header('Location: index.php');
    exit;
}

$result = executeQuery("DELETE FROM users WHERE user_id = ? AND is_active = 0", [$user_id]);

if ($result && $result->rowCount() > 0) {
    logActivity($_SESSION['user_id'], 'users', 'Deleted user', "Permanently deleted user: {$user['username']} ($full_name)");
    $_SESSION['success_message'] = "$full_name's account was permanently deleted.";
} else {
    $_SESSION['error_message'] = 'Failed to delete the account. Please try again.';
}

header('Location: index.php');
exit;