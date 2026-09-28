<?php
// modules/parents/common.php
// Who on the staff may manage a child's Parent Portal access: Administrator,
// Registrar, and the adviser of the child's current section.

require_once __DIR__ . '/../../config/database.php';
if (!defined('BASE_URL')) define('BASE_URL', '/watdahel/');
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/parent_portal.php';

function ppStaffCanStudent($studentId)
{
    if (hasAnyRole(['Administrator', 'Registrar'])) return true;
    if (!hasRole('Teacher')) return false;
    return (bool) getRecord(
        "SELECT 1 FROM enrollments e JOIN sections sec ON sec.section_id = e.section_id
          WHERE e.student_id = ? AND e.school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1) AND sec.adviser_id = ?",
        [(int) $studentId, (int) ($_SESSION['user_id'] ?? 0)]
    );
}

function ppStaffCanSection($sectionId)
{
    if (hasAnyRole(['Administrator', 'Registrar'])) return true;
    return hasRole('Teacher') && (bool) getRecord("SELECT 1 FROM sections WHERE section_id = ? AND adviser_id = ?", [(int) $sectionId, (int) ($_SESSION['user_id'] ?? 0)]);
}

/** Students of a section this school year (for slips / progress reports). */
function ppSectionStudents($sectionId)
{
    return getRecords(
        "SELECT s.student_id FROM enrollments e JOIN students s ON s.student_id = e.student_id AND s.is_active = 1
          WHERE e.section_id = ? AND e.school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1) AND e.enrollment_status = 'Approved'
          ORDER BY s.last_name, s.first_name",
        [(int) $sectionId]
    );
}

/** One student with section, adviser and guardian (for slips / reports). */
function ppStudentCard($studentId)
{
    return getRecord(
        "SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.grade_level, s.guardian_name, s.guardian_contact,
                sec.section_name, CONCAT(adv.first_name, ' ', adv.last_name) AS adviser_name
           FROM students s
           LEFT JOIN enrollments e ON e.student_id = s.student_id AND e.school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1)
           LEFT JOIN sections sec ON sec.section_id = e.section_id
           LEFT JOIN users adv ON adv.user_id = sec.adviser_id
          WHERE s.student_id = ?", [(int) $studentId]);
}
