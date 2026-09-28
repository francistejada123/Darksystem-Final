<?php
// parent/logout.php
session_start();
$lang = $_SESSION['pp_lang'] ?? null;
$_SESSION = [];
session_regenerate_id(true);
if ($lang) $_SESSION['pp_lang'] = $lang;
header('Location: login.php');
