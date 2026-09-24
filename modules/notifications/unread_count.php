<?php
// modules/notifications/unread_count.php
// Tiny, read-only AJAX endpoint the topbar bell polls every 30s (see
// includes/header.php) so a notification created by a background process —
// e.g. a Google Form quiz getting auto-graded while the teacher is sitting
// on some other page — reaches the bell without a full page reload. This
// endpoint does nothing else: no state change, no CSRF token needed (GET,
// session-cookie-gated, identical query to the one header.php already runs
// on every page load).

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['count' => 0]);
    exit;
}

$row = getRecord("SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0", [$_SESSION['user_id']]);
echo json_encode(['count' => intval($row['cnt'] ?? 0)]);
