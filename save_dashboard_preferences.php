<?php
// save_dashboard_preferences.php
// AJAX JSON endpoint for the "Customize Dashboard" modal on index.php.
// Saves or resets the CURRENT user's widget visibility/order. Never
// touches any dashboard statistic — only which of the existing widgets
// render and in what order (see includes/dashboard_helpers.php).

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/dashboard_helpers.php';

header('Content-Type: application/json');

// Dashboard customization (save/reset/load preferences) is an
// Administrator-only feature — Registrar and every other role always see
// the default dashboard layout. This is enforced here server-side (never
// just by hiding the "Customize Dashboard" button in the UI), and always
// against the authenticated session's own role, never anything the client
// can influence.
if (!hasRole('Administrator')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page and try again.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $input['action'] ?? 'save';

if ($action === 'reset') {
    $ok = resetDashboardPreferences($user_id);
    if ($ok) {
        logActivity($user_id, 'Dashboard', 'Reset Preferences', 'Reset dashboard layout to default');
        $_SESSION['success_message'] = 'Dashboard restored to default layout.';
    }
    echo json_encode(['success' => $ok]);
    exit;
}

// action === 'save' (default)
$widgets = $input['widgets'] ?? null;
if (!is_array($widgets) || empty($widgets)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No widget data received.']);
    exit;
}

// Basic shape validation so a malformed payload can't silently corrupt
// preferences — every entry must at least carry a non-empty key.
$clean = [];
foreach ($widgets as $w) {
    if (!is_array($w) || empty($w['key'])) {
        continue;
    }
    $clean[] = [
        'key'     => (string)$w['key'],
        'visible' => !empty($w['visible']),
        'order'   => (int)($w['order'] ?? 0),
    ];
}

if (empty($clean)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No valid widget data received.']);
    exit;
}

$ok = saveDashboardPreferences($user_id, $clean);
if ($ok) {
    logActivity($user_id, 'Dashboard', 'Updated Preferences', 'Customized dashboard layout (' . count($clean) . ' widgets)');
    $_SESSION['success_message'] = 'Dashboard layout saved successfully.';
}

echo json_encode(['success' => $ok]);
