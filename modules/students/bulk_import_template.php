<?php
// modules/students/bulk_import_template.php
// Downloadable CSV template with the exact headers bulk_import.php expects.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!hasAnyRole(['Administrator', 'Registrar'])) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="student_import_template.csv"');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");

fputcsv($out, ['lrn', 'last_name', 'first_name', 'middle_name', 'birth_date', 'gender', 'grade_level', 'strand', 'average_grade', 'contact_number', 'email', 'address', 'guardian_name', 'guardian_contact']);
fputcsv($out, ['310100100099', 'Dela Cruz', 'France', 'Santos', '2010-05-14', 'Male', 'Grade 7', '', '97', '09171234567', 'juan.delacruz@example.com', '123 Sample St., Malabon City', 'Maria Dela Cruz', '09179876543']);
fputcsv($out, ['310100100098', 'Reyes', 'France', '', '2008-11-02', 'Female', 'Grade 11', 'ASSH', '80', '09181234567', '', '456 Sample Ave., Malabon City', 'Pedro Reyes', '09189876543']);

fclose($out);
exit;