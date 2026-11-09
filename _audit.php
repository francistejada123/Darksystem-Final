<?php
// TEMPORARY: audit every portal page that calls spwRequireChangedPassword()
// for the missing-include bug that made profile.php return HTTP 500.
$dir = __DIR__ . '/modules/students/portal';
$rows = [];
foreach (glob($dir . '/*.php') as $f) {
    $src = file_get_contents($f);
    if (strpos($src, 'spwRequireChangedPassword()') === false)
        continue;

    $callsAt = strpos($src, 'spwRequireChangedPassword()');
    $requires = [];
    if (preg_match_all('#require_once\s+[^;]*student_password\.php#', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit)
            $requires[] = $hit[1];
    }
    // Also fine if it comes in via the shared spRequireStudent() helper.
    $viaHelper = strpos($src, 'spRequireStudent()') !== false;

    $firstReq = $requires ? min($requires) : PHP_INT_MAX;
    $ok = $viaHelper || ($requires && $firstReq < $callsAt);
    $rows[] = [
        'file' => basename($f),
        'requires' => count($requires),
        'viaHelper' => $viaHelper,
        'ok' => $ok,
    ];
}
$bad = 0;
foreach ($rows as $r) {
    if (!$r['ok'])
        $bad++;
    printf(
        "%-26s requires=%d viaHelper=%-5s %s\n",
        $r['file'],
        $r['requires'],
        $r['viaHelper'] ? 'yes' : 'no',
        $r['ok'] ? 'OK' : 'BROKEN (call before include)'
    );
}
echo "\n" . ($bad === 0 ? "all good\n" : "$bad page(s) BROKEN\n");