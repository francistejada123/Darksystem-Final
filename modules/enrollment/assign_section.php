<?php
// modules/enrollment/assign_section.php
// Assign a section to an approved enrollment

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/eo_scope.php';   // Enrollment Officer: own grade level only

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Registrar', 'Enrollment Officer'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['error_message'] = 'Invalid request.';
    header('Location: index.php');
    exit;
}

$enrollment_id = intval($_POST['enrollment_id'] ?? 0);
$section_id = intval($_POST['section_id'] ?? 0);

if ($enrollment_id <= 0 || $section_id <= 0) {
    $_SESSION['error_message'] = 'Invalid enrollment or section.';
    header('Location: index.php');
    exit;
}

$enrollment = getRecord("SELECT * FROM enrollments WHERE enrollment_id = ?", [$enrollment_id]);
if (!$enrollment) {
    $_SESSION['error_message'] = 'Enrollment not found.';
    header('Location: index.php');
    exit;
}
eoGuard($enrollment['grade_level']);

$section = getRecord("SELECT * FROM sections WHERE section_id = ?", [$section_id]);
if (!$section) {
    $_SESSION['error_message'] = 'Section not found.';
    header('Location: view.php?id=' . $enrollment_id);
    exit;
}

if (strtoupper(trim((string)($section['strand'] ?? ''))) === 'HUMSS') {
    $_SESSION['error_message'] = 'HUMSS sections are no longer offered. Please choose an ASSH or GAS section.';
    header('Location: view.php?id=' . $enrollment_id);
    exit;
}

$db->beginTransaction();

try {
    $old_section_id = $enrollment['section_id'] ?? null;

    // Update enrollment with section
    executeQuery(
        "UPDATE enrollments SET section_id = ? WHERE enrollment_id = ?",
        [$section_id, $enrollment_id]
    );

    // Update student record
    executeQuery(
        "UPDATE students SET section_id = ?, strand = COALESCE(strand, ?) WHERE student_id = ?",
        [$section_id, $section['strand'] ?? null, $enrollment['student_id']]
    );

    // Decrement old section count if reassigning
    if ($old_section_id && $old_section_id != $section_id) {
        executeQuery(
            "UPDATE sections SET current_enrollment = GREATEST(current_enrollment - 1, 0) WHERE section_id = ?",
            [$old_section_id]
        );
    }

    // Increment new section enrollment count (only if not already assigned to same section)
    if (!$old_section_id || $old_section_id != $section_id) {
        executeQuery(
            "UPDATE sections SET current_enrollment = current_enrollment + 1 WHERE section_id = ?",
            [$section_id]
        );
    }

    logActivity($_SESSION['user_id'], 'Section Assigned', 'enrollment',
        "Section {$section['section_name']} assigned to enrollment ID $enrollment_id");

    $db->commit();
    $_SESSION['success_message'] = 'Section ' . $section['section_name'] . ' assigned successfully.';
} catch (Exception $e) {
    $db->rollBack();
    $_SESSION['error_message'] = 'Failed to assign section.';
}

header('Location: view.php?id=' . $enrollment_id);
exit;