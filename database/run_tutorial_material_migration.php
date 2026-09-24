<?php
// database/run_tutorial_material_migration.php
//
// ONE-TIME migration runner for the Tutorial Plan "Learning Material"
// feature (Add/Replace Material — video link, PDF, Word, or PowerPoint).
//
// The feature's PHP code (modules/tutorial/save_material.php and friends)
// already assumes the tutorial_plan_items table has the resource_* columns
// added by these two migrations:
//   - database/migrations/2026_tutorial_pdf_resources.sql
//   - database/migrations/2026_tutorial_learning_materials.sql
// If those were never actually run against the live database, every
// "Add Material" click updates 0 rows (the UPDATE query errors on a
// missing column), which is exactly the "nothing shows up in Learning
// Material, and students never see it" symptom.
//
// This script re-applies both migrations by hand, using the exact same
// idempotent ADD COLUMN IF NOT EXISTS / MODIFY COLUMN statements as the
// .sql files above (MariaDB 10.4 on XAMPP supports IF NOT EXISTS). It is
// safe to run more than once -- a column that already exists is left
// untouched.
//
// HOW TO USE: log in to EGradePro as an Administrator, then open this file
// directly in the browser, e.g.:
//   http://localhost/watdahel/database/run_tutorial_material_migration.php
// Once it reports success, go back to a Tutorial Plan and try "Add
// Material" again. You can then delete this file -- it's not linked from
// anywhere in the app and has no reason to stay on a live site.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

if (!hasRole('Administrator')) {
    http_response_code(403);
    die('Administrator login required. Log in to EGradePro as an Administrator, then reload this page.');
}

header('Content-Type: text/plain; charset=utf-8');

$statements = [
    // From 2026_tutorial_pdf_resources.sql
    'resource_type column exists'               => "ALTER TABLE `tutorial_plan_items` ADD COLUMN IF NOT EXISTS `resource_type` enum('link','pdf') NOT NULL DEFAULT 'link' AFTER `resource_url`",
    'resource_file column exists'                => "ALTER TABLE `tutorial_plan_items` ADD COLUMN IF NOT EXISTS `resource_file` varchar(255) DEFAULT NULL AFTER `resource_type`",
    'resource_file_original_name column exists'  => "ALTER TABLE `tutorial_plan_items` ADD COLUMN IF NOT EXISTS `resource_file_original_name` varchar(255) DEFAULT NULL AFTER `resource_file`",
    'resource_file_size column exists'           => "ALTER TABLE `tutorial_plan_items` ADD COLUMN IF NOT EXISTS `resource_file_size` int(11) DEFAULT NULL AFTER `resource_file_original_name`",
    // From 2026_tutorial_learning_materials.sql
    'resource_type widened to include word/powerpoint' => "ALTER TABLE `tutorial_plan_items` MODIFY COLUMN `resource_type` enum('link','pdf','word','powerpoint') NOT NULL DEFAULT 'link'",
    'resource_mime_type column exists'           => "ALTER TABLE `tutorial_plan_items` ADD COLUMN IF NOT EXISTS `resource_mime_type` varchar(120) DEFAULT NULL AFTER `resource_file_size`",
    'resource_title column exists'               => "ALTER TABLE `tutorial_plan_items` ADD COLUMN IF NOT EXISTS `resource_title` varchar(255) DEFAULT NULL AFTER `resource_mime_type`",
    'resource_description column exists'         => "ALTER TABLE `tutorial_plan_items` ADD COLUMN IF NOT EXISTS `resource_description` text DEFAULT NULL AFTER `resource_title`",
];

global $db;
echo "Tutorial Learning Material migration\n";
echo str_repeat('=', 50) . "\n\n";

$failures = 0;
foreach ($statements as $label => $sql) {
    try {
        $db->exec($sql);
        echo "[OK]    $label\n";
    } catch (PDOException $e) {
        $failures++;
        echo "[ERROR] $label -- " . $e->getMessage() . "\n";
    }
}

echo "\n";
if ($failures === 0) {
    echo "Done -- all columns are in place. Go back to a Tutorial Plan and try\n";
    echo "'Add Material' again. You can safely delete this file now:\n";
    echo "database/run_tutorial_material_migration.php\n";
} else {
    echo "$failures statement(s) failed. Copy the [ERROR] line(s) above and share\n";
    echo "them -- the table may need to be checked by hand (e.g. via phpMyAdmin).\n";
}