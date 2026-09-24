<?php
// modules/subjects/delete_selected.php
// Delete multiple subjects

require_once __DIR__ . '/../../config/database.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';

if (!hasRole('Administrator')) {
    header('Location: ../../index.php');
    exit;
}

$codes = $_GET['codes'] ?? '';
if (empty($codes)) {
    header('Location: index.php');
    exit;
}

$code_array = explode(',', $codes);
$deleted = 0;
$errors = 0;

foreach ($code_array as $code) {
    $code = trim(urldecode($code));
    if (!empty($code)) {
        $subject = getRecord("SELECT subject_code, subject_name FROM subjects WHERE subject_code = ? AND is_active = 1", [$code]);
        if ($subject) {
            $result = executeQuery("UPDATE subjects SET is_active = 0 WHERE subject_code = ?", [$code]);
            if ($result) {
                $deleted++;
                logActivity($_SESSION['user_id'], 'Deleted subject', 'subjects', "Deleted subject: {$subject['subject_code']} - {$subject['subject_name']}");
            } else {
                $errors++;
            }
        }
    }
}

if ($deleted > 0) {
    $_SESSION['success_message'] = $deleted . ' subject(s) deleted successfully!';
}
if ($errors > 0) {
    $_SESSION['error_message'] = $errors . ' subject(s) failed to delete.';
}

header('Location: index.php');
exit;
?>