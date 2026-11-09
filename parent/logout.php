<?php
// parent/logout.php
session_start();
$lang = $_SESSION['pp_lang'] ?? null;
$_SESSION = [];
session_regenerate_id(true);
if ($lang) $_SESSION['pp_lang'] = $lang;
// No separate Parent login page any more: back to the Student Portal login,
// Parent tab.
header('Location: ../student_login.php?as=parent');
