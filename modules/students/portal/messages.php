<?php
// modules/students/portal/messages.php
// Messages now live under Updates > Messages (updates.php), which lists the
// teachers' messages of every class and opens each one on its class page.

session_start();
require_once '../../../includes/student_password.php';   // spwRequireChangedPassword()

if (!isset($_SESSION['student_id']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: ../../../student_login.php');
    exit;
}

// First login: the account still holds the temporary password issued when the
// Admin approved the enrollment, so the portal may not be used until it is
// changed (see includes/student_password.php).
if (spwRequireChangedPassword()) exit;


header('Location: updates.php?tab=messages');
exit;
