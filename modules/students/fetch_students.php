<?php
// modules/students/fetch_students.php
// AJAX endpoint for fetching students (with deleted filter)

require_once '../../config/database.php';
require_once '../../includes/auth.php';

if (!hasAnyRole(['Administrator', 'Registrar'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$search = sanitize($_GET['search'] ?? '');
$grade_level = sanitize($_GET['grade_level'] ?? '');
$section = sanitize($_GET['section'] ?? '');
$status = sanitize($_GET['status'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$where = "1=1";
$params = [];

if ($search !== '') {
    $where .= " AND (s.lrn LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.last_name, ', ', s.first_name) LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if ($grade_level) {
    $where .= " AND s.grade_level = ?";
    $params[] = $grade_level;
}

if ($section) {
    $where .= " AND s.section_id = ?";
    $params[] = $section;
}

if ($status) {
    if ($status === 'deleted') {
        $where .= " AND s.is_active = 0";
    } else {
        $where .= " AND s.enrollment_status = ?";
        $params[] = $status;
    }
} else {
    // Default: show only active students (not deleted)
    $where .= " AND s.is_active = 1";
}

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM students s WHERE $where";
$count_result = getRecord($count_sql, $params);
$total_records = $count_result ? $count_result['total'] : 0;
$total_pages = ceil($total_records / $limit);

// Get students
$sql = "SELECT s.*, sec.section_name, sy.sy_name 
        FROM students s 
        LEFT JOIN sections sec ON s.section_id = sec.section_id 
        LEFT JOIN school_years sy ON s.school_year_id = sy.sy_id 
        WHERE $where 
        ORDER BY s.last_name, s.first_name 
        LIMIT $offset, $limit";

$students = getRecords($sql, $params);

// Prepare response
$response = [
    'students' => $students,
    'total_records' => $total_records,
    'total_pages' => $total_pages,
    'page' => $page,
    'from' => $offset + 1,
    'to' => min($offset + $limit, $total_records)
];

echo json_encode($response);
exit;
?>