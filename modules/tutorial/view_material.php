<?php
// modules/tutorial/view_material.php
//
// ID-based, server-authorized endpoint that streams an uploaded Learning
// Material file (PDF/Word/PowerPoint). Video-link materials are opened
// directly via their resource_url and never pass through here.
//
// Authorization (the actual security boundary for this feature):
//   - Administrator / Teacher: may view any item's material, matching the
//     access level teachers/admins already have to every Tutorial Plan
//     video link in modules/tutorial/view.php.
//   - Student: may ONLY view a material that belongs to a plan that is
//     THEIR OWN (tutorial_plans.student_id === the logged-in student's own
//     student_id). The item_id in the URL is never trusted on its own --
//     ownership is re-checked against the database on every request, so a
//     student cannot see another student's material by editing the id.
//
// resource_file in the database is always a random, server-generated
// filename (never anything a browser ever sent us), so there is no path
// traversal surface here -- basename() is applied anyway as defense in depth.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/tutorial_helpers.php';

function viewMaterialDeny($code, $message)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$item_id = intval($_GET['id'] ?? 0);
if ($item_id <= 0) {
    viewMaterialDeny(400, 'Invalid material.');
}

$item = getRecord("
    SELECT tpi.*, tp.student_id AS plan_student_id
    FROM tutorial_plan_items tpi
    JOIN tutorial_plans tp ON tpi.plan_id = tp.plan_id
    WHERE tpi.item_id = ?
", [$item_id]);

if (!$item) {
    viewMaterialDeny(404, 'Material not found.');
}

$isStudentView = false;

if (hasAnyRole(['Administrator', 'Teacher'])) {
    // Same access level teachers/admins already have to every Tutorial
    // Plan item today -- no change to that existing model.
} elseif (isset($_SESSION['student_id']) && ($_SESSION['role'] ?? '') === 'Student') {
    // Server-side ownership check: the plan this item belongs to must be
    // THIS student's own plan. This is re-verified here on every request,
    // regardless of what the id in the URL is.
    if ((int) $item['plan_student_id'] !== (int) $_SESSION['student_id']) {
        viewMaterialDeny(403, 'You do not have access to this material.');
    }
    $isStudentView = true;
} else {
    viewMaterialDeny(403, 'Please log in to view this material.');
}

if (!in_array($item['resource_type'], ['pdf', 'word', 'powerpoint'], true) || empty($item['resource_file'])) {
    viewMaterialDeny(404, 'No file is attached to this item.');
}

$path = tutorialMaterialStorageDir() . '/' . basename($item['resource_file']);
if (!is_file($path)) {
    viewMaterialDeny(404, 'This file is no longer available.');
}

// Best-effort, additive progress signal: the first time a student actually
// opens a file-based material, move it from "Not Started" to "In Progress"
// -- mirrors what real YouTube playback already does for videos, without
// ever fabricating a "Completed" state we can't verify.
if ($isStudentView && $item['status'] === 'Pending') {
    executeQuery(
        "UPDATE tutorial_plan_items SET status = 'In Progress', student_viewed_at = NOW() WHERE item_id = ?",
        [$item_id]
    );
}

$displayName = tutorialSafeDisplayFilename($item['resource_file_original_name'] ?? 'material');
$mime = tutorialMaterialCanonicalMime($item['resource_type'], $item['resource_file']);

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . str_replace('"', '', $displayName) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($path);
exit;
