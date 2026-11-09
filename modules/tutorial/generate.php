<?php
// modules/tutorial/generate.php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/risk_helpers.php';
require_once __DIR__ . '/../../includes/tutorial_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Teacher'])) {
    header('Location: ../../index.php');
    exit;
}

// Generating a plan changes academic data, so it is Teacher-only. Enforced
// here as well as in the UI — hiding the button is not a permission check.
if (!hasRole('Teacher')) {
    $_SESSION['error_message'] = 'Generating tutorial plans is view-only for your role.';
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$student_id = intval($_POST['student_id'] ?? 0);
$risk_level = in_array($_POST['risk_level'] ?? '', ['High', 'Medium']) ? $_POST['risk_level'] : 'High';

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
$sy_id = $active_sy['sy_id'] ?? 0;

if (!$student_id || !$sy_id) {
    $_SESSION['error_message'] = 'Unable to generate plan: missing student or active school year.';
    header('Location: index.php');
    exit;
}

$result = generateTutorialPlan($student_id, $sy_id, $_SESSION['user_id'], $risk_level);

if (!$result) {
    $_SESSION['error_message'] = 'This student has no at-risk subject right now (no failing grade, Incomplete term, missing assessments or repeated low scores), so a tutorial plan could not be generated.';
    header('Location: index.php');
    exit;
}

$student = getRecord("SELECT first_name, last_name FROM students WHERE student_id = ?", [$student_id]);
$student_name = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));

if ($result['created']) {
    // Notify every teacher assigned as a tutor for a subject in this plan
    // (skip nulls — subjects with no assigned teacher yet just won't notify anyone).
    $tutors = getRecords("
        SELECT DISTINCT tutor_id FROM tutorial_plan_items
        WHERE plan_id = ? AND tutor_id IS NOT NULL
    ", [$result['plan_id']]);

    foreach ($tutors as $t) {
        createNotification(
            $t['tutor_id'],
            'New Tutorial Plan',
            $student_name . ' has a new AI-generated tutorial plan for a subject you handle. Please review the recommended focus areas.',
            'warning',
            'modules/tutorial/view.php?id=' . $result['plan_id']
        );
    }

    logActivity($_SESSION['user_id'], 'tutorial', 'Generated plan', "Plan #{$result['plan_id']} for $student_name");
    $_SESSION['success_message'] = 'Tutorial plan generated for ' . $student_name . '.';
} else {
    $_SESSION['success_message'] = $student_name . ' already has an active tutorial plan.';
}

header('Location: view.php?id=' . $result['plan_id']);
exit;