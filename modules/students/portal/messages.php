<?php
// modules/students/portal/messages.php
// Messages now live under Updates > Messages (updates.php), which lists the
// teachers' messages of every class and opens each one on its class page.

session_start();

if (!isset($_SESSION['student_id']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: ../../../student_login.php');
    exit;
}

header('Location: updates.php?tab=messages');
exit;
