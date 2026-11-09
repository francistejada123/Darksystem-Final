<?php
// Temporary: exercise ppLoginByLrnAndName() directly for the sibling case.
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/parent_portal.php';

function show($label, $res)
{
    echo str_pad($label, 52) . ' => ';
    if (empty($res['ok'])) {
        echo "REJECTED (" . ($res['reason'] ?? '?') . ")\n";
        return;
    }
    $sid = (int) $res['student_id'];
    $st = getRecord("SELECT lrn, first_name, last_name FROM students WHERE student_id = ?", [$sid]);
    echo "OK  child=#" . $sid . " " . $st['first_name'] . " " . $st['last_name'] . " (LRN " . $st['lrn'] . ")\n";
}

echo "=== Same guardian name 'Alexander', DIFFERENT LRNs ===\n";
show("LRN 136613100196 + Alexander", ppLoginByLrnAndName('136613100196', 'Alexander'));
show("LRN 110100100052 + Alexander", ppLoginByLrnAndName('110100100052', 'Alexander'));
show("LRN 110100100051 + Alexander", ppLoginByLrnAndName('110100100051', 'Alexander'));
show("LRN 136421090056 + Alexander", ppLoginByLrnAndName('136421090056', 'Alexander'));

echo "\n=== Wrong / missing inputs ===\n";
show("LRN 110100100051 + Wrong Name", ppLoginByLrnAndName('110100100051', 'Not The Parent'));
show("Unknown LRN + Alexander", ppLoginByLrnAndName('999999999999', 'Alexander'));
show("Short LRN + Alexander", ppLoginByLrnAndName('12345', 'Alexander'));

echo "\n=== Name tolerance (case / extra spaces) ===\n";
show("LRN 110100100052 + '  alexander '", ppLoginByLrnAndName('110100100052', '  alexander '));
show("LRN 110100100052 + 'ALEXANDER'", ppLoginByLrnAndName('110100100052', 'ALEXANDER'));

echo "\n=== parent_students links now on file ===\n";
foreach (getRecords("SELECT ps.parent_id, ps.student_id, ps.linked_via, s.first_name, s.last_name, p.mobile
                       FROM parent_students ps
                       JOIN students s ON s.student_id = ps.student_id
                       JOIN parents p ON p.parent_id = ps.parent_id
                      ORDER BY ps.parent_id, ps.student_id") as $r) {
    echo "  parent #" . $r['parent_id'] . " (" . $r['mobile'] . ") -> #" . $r['student_id'] . " " . $r['first_name'] . " " . $r['last_name'] . "  via " . $r['linked_via'] . "\n";
}