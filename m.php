<?php
// m.php?c=CODE -- short link in the Parent Portal SMS / slip: opens the setup page.
$c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['c'] ?? '')));
header('Referrer-Policy: no-referrer');
header('Location: parent/setup.php' . ($c !== '' ? '?c=' . rawurlencode($c) : ''));
