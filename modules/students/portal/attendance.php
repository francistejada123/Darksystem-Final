<?php
// modules/students/portal/attendance.php
// Student Attendance has been removed from the system.
// Redirect to student dashboard.

session_start();
if (!isset($_SESSION['student_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../../../student_login.php');
    exit;
}
header('Location: dashboard.php');
exit;
