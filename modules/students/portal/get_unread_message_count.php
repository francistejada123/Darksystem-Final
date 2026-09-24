<?php
// modules/students/portal/get_unread_message_count.php
// Lightweight AJAX endpoint polled by static/js/student-unread-badge.js
// to keep the sidebar's "Messages" badge in sync with the real, live
// unread count.
//
// The logged-in student is identified purely from the EXISTING session
// (the same check every other Student Portal page already uses) — a
// student ID is never accepted from the browser, so a student can only
// ever see their own count.

session_start();
require_once '../../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['student_id']) || ($_SESSION['role'] ?? '') !== 'Student') {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated', 'unread_count' => 0]);
    exit;
}

$student_id = (int) $_SESSION['student_id'];

// Same table/columns modules/students/portal/messages.php already reads
// and marks seen — no second messaging system, just a count against the
// existing one.
$unread_count = getCount(
    'student_support_messages',
    "student_id = ? AND status = 'Sent' AND is_seen = 0",
    [$student_id]
);

echo json_encode(['unread_count' => $unread_count]);