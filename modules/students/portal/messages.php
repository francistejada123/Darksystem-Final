<?php
// modules/students/portal/messages.php
// RETIRED — this standalone "all subjects in one inbox" Messages page has
// been replaced by the Google-Classroom-style redesign: every message
// (plain support message or an activity/task attachment) now lives on that
// message's OWN subject page instead (modules/students/portal/subject.php),
// reached from a class card on dashboard.php. There is no more single page
// listing every subject's messages together, and no more top-bar "Messages"
// bell — a student only finds out something is waiting by opening that
// subject (dashboard.php shows a small, content-free "New" count per class
// card so they know WHERE to look, never WHAT).
//
// This file is kept only as a redirect, in case a bookmark, an old email,
// or browser history still points here — never linked to from anywhere in
// the app anymore (see dashboard.php, grades.php, tutorial.php,
// enrollment.php, profile.php, all of which dropped the bell that used to
// point here).

session_start();

if (!isset($_SESSION['student_id']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: ../../../student_login.php');
    exit;
}

header('Location: dashboard.php');
exit;
