<?php
// modules/teachers/delete_selected.php
// Delete multiple teachers

require_once __DIR__ . '/../../config/database.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';

if (!hasRole('Administrator')) {
    header('Location: ../../index.php');
    exit;
}

$ids = $_GET['ids'] ?? '';
if (empty($ids)) {
    header('Location: index.php');
    exit;
}

$id_array = explode(',', $ids);
$deleted = 0;
$errors = 0;

foreach ($id_array as $id) {
    $id = intval(trim($id));
    if ($id > 0) {
        $teacher = getRecord("SELECT * FROM users WHERE user_id = ? AND role_id = 3", [$id]);
        if ($teacher) {
            $has_classes = getRecord("SELECT COUNT(*) as count FROM teacher_subjects WHERE teacher_id = ?", [$id]);
            if ($has_classes && $has_classes['count'] > 0) {
                $errors++;
            } else {
                $result = executeQuery("UPDATE users SET is_active = 0 WHERE user_id = ?", [$id]);
                if ($result) {
                    $deleted++;
                    logActivity($_SESSION['user_id'], 'Deleted teacher', 'teachers', "Deleted teacher: {$teacher['last_name']}, {$teacher['first_name']}");
                } else {
                    $errors++;
                }
            }
        }
    }
}

if ($deleted > 0) {
    $_SESSION['success_message'] = $deleted . ' teacher(s) deleted successfully!';
}
if ($errors > 0) {
    $_SESSION['error_message'] = $errors . ' teacher(s) failed to delete. Some may have assigned subjects.';
}

header('Location: index.php');
exit;
?>