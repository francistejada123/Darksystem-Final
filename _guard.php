<?php
// TEMPORARY: insert the first-login password-change guard into the Student
// Portal pages, right after each page's own "is a Student signed in" check.
$dir = __DIR__ . '/modules/students/portal';
$files = glob($dir . '/*.php');

$guard = "\n// First login: the account still holds the temporary password issued when the\n"
    . "// Admin approved the enrollment, so the portal may not be used until it is\n"
    . "// changed (see includes/student_password.php).\n"
    . "if (spwRequireChangedPassword()) exit;\n";

$done = [];
foreach ($files as $f) {
    $src = file_get_contents($f);
    if (strpos($src, 'spwRequireChangedPassword') !== false) {
        continue;
    }   // already guarded

    // Match the session check + its redirect/exit block, then insert after it.
    $patterns = [
        // header('Location: ../../../student_login.php'); exit;
        "/(if \(!isset\(\\\$_SESSION\['student_id'\]\)[^`\n]*\{[ ]*\n[ ]*header\('Location: \.\.\/\.\.\/\.\.\/student_login\.php'\);\n[ ]*exit;\n[ ]*\})/",
    ];
    $hit = false;
    foreach ($patterns as $re) {
        if (preg_match($re, $src, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[1][1] + strlen($m[1][0]);
            $src = substr($src, 0, $at) . "\n" . $guard . substr($src, $at);
            $hit = true;
            break;
        }
    }
    if ($hit) {
        file_put_contents($f, $src);
        $done[] = basename($f);
    }
}
echo "guarded: " . implode(', ', $done) . PHP_EOL;
echo "skipped: " . implode(', ', array_diff(array_map('basename', $files), $done)) . PHP_EOL;