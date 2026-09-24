<?php
// modules/students/portal/tutorial_progress.php
//
// AJAX endpoint (fetch / navigator.sendBeacon) called by tutorial.php's
// embedded YouTube IFrame Player while a student is watching a tutorial
// video. Saves ACTUAL playback progress — never a manual "I watched this"
// click — so watch_status / watch_progress can be fully automatic.
//
// Only ever touches tutorial_plan_items rows that belong to a plan owned
// by the currently logged-in student (see the ownership check below).

session_start();
require_once '../../../config/database.php';
require_once '../../../includes/tutorial_helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['student_id']) || $_SESSION['role'] !== 'Student') {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$student_id = $_SESSION['student_id'];

// navigator.sendBeacon() posts as text/plain with a URL-encoded body, so
// $_POST may be empty for beacon requests — fall back to parsing the raw
// body the same way in that case.
$input = $_POST;
if (empty($input)) {
    parse_str(file_get_contents('php://input'), $input);
}

$item_id = intval($input['item_id'] ?? 0);
$watched_seconds = floatval($input['watched_seconds'] ?? 0);
$current_position = floatval($input['current_position'] ?? 0);
$video_duration = floatval($input['video_duration'] ?? 0);
$event = in_array($input['event'] ?? '', ['start', 'progress', 'pause', 'end', 'exit'], true)
    ? $input['event']
    : 'progress';

if (!$item_id || $watched_seconds < 0 || $video_duration < 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

// Ownership check: the item must belong to a Draft/Active plan owned by
// THIS student. This is the access-control boundary — a student can never
// update another student's tutorial progress this way.
$item = getRecord("
    SELECT tpi.item_id, tpi.status, tpi.watched_seconds, tpi.video_duration_seconds,
           tpi.watch_progress_percentage, tpi.started_watching_at, tpi.video_watched_at,
           tpi.resource_url
    FROM tutorial_plan_items tpi
    JOIN tutorial_plans tp ON tpi.plan_id = tp.plan_id
    WHERE tpi.item_id = ? AND tp.student_id = ? AND tp.status IN ('Draft', 'Active')
", [$item_id, $student_id]);

if (!$item) {
    http_response_code(404);
    echo json_encode(['error' => 'Tutorial item not found']);
    exit;
}

// Automatic tracking only applies to a resource that is a specific,
// embeddable YouTube video — never a search-results link.
if (!getYouTubeVideoId($item['resource_url'])) {
    http_response_code(422);
    echo json_encode(['error' => 'This resource is not a trackable video']);
    exit;
}

// Never let accumulated watch time exceed the video's own duration
// (guards against minor client-side rounding drift).
if ($video_duration > 0) {
    $watched_seconds = min($watched_seconds, $video_duration);
}

// Accumulated watch time is stored as a running MAXIMUM, never overwritten
// downward by a later/duplicate request (e.g. a stale beacon arriving
// after a newer fetch already saved further progress).
$new_watched_seconds = max(floatval($item['watched_seconds']), $watched_seconds);

// The duration a specific video reports should be stable, but always take
// the latest value the player gave us (falls back to whatever was already
// on file if this particular request didn't include one).
$new_duration = $video_duration > 0 ? $video_duration : floatval($item['video_duration_seconds']);

$new_progress = calculateWatchProgressPercent($new_watched_seconds, $new_duration);
$new_status = determineTutorialWatchStatus($new_progress, $item['status']);

$just_finished = ($new_status === 'Completed' && $item['status'] !== 'Completed');
$just_started = ($new_watched_seconds > 0 && empty($item['started_watching_at']));

$sql = "
    UPDATE tutorial_plan_items
    SET watched_seconds = ?,
        video_duration_seconds = ?,
        watch_progress_percentage = ?,
        last_video_position_seconds = ?,
        last_watched_at = NOW(),
        status = ?
        " . ($just_started ? ", started_watching_at = NOW()" : "") . "
        " . ($just_finished ? ", video_watched_at = NOW()" : "") . "
    WHERE item_id = ?
";

executeQuery($sql, [
    $new_watched_seconds,
    $new_duration ?: null,
    $new_progress,
    $current_position,
    $new_status,
    $item_id,
]);

echo json_encode([
    'status' => $new_status,
    'status_label' => getTutorialWatchStatusLabel($new_status),
    'badge_class' => getTutorialWatchStatusBadgeClass($new_status),
    'watch_progress_percentage' => $new_progress,
    'just_finished' => $just_finished,
]);
