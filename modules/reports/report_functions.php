<?php
// modules/reports/report_functions.php
// Shared report-rendering functions. Each function streams a real,
// branded PDF (via includes/pdf_helpers.php / Dompdf) and ends the
// request — this is a direct 1-click download, not a browser print
// dialog. Included by generate.php, which is the ONLY page that calls
// these.

require_once __DIR__ . '/../../includes/pdf_helpers.php';

// Look up a student by EITHER their internal Student ID or their LRN.
// The "Student" field on the Generate Report form (and the LRN column
// everyone actually sees in the UI, e.g. the At-Risk list) shows the
// LRN — not the internal auto-increment student_id — so most people
// naturally type the LRN in. Matching only on student_id silently
// failed for that case (blank report), so this checks both.
function findStudentByIdentifier($identifier) {
    $identifier = trim((string)$identifier);
    if ($identifier === '') {
        return null;
    }
    return getRecord(
        "SELECT * FROM students WHERE is_active = 1 AND (student_id = ? OR lrn = ?) LIMIT 1",
        [$identifier, $identifier]
    );
}

// A small, plain (non-PDF) page for "nothing to show" states — e.g. an
// unknown student identifier. There's no report data to brand/paginate,
// so this stays a simple inline HTML message rather than a download.
function reportNotFoundPage($message) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Report Unavailable</title>';
    echo '<style>body{font-family:Arial,sans-serif;padding:40px;color:#333;} a{color:#5B5FEF;}</style></head><body>';
    echo '<p>' . htmlspecialchars($message) . '</p>';
    echo '<p><a href="index.php">&larr; Back to Reports</a></p>';
    echo '</body></html>';
}

// Bold, light-background summary strip used under a report's main table
// (row counts, pass rates, etc.) — a lighter-weight alternative to a
// <tfoot> that stays readable no matter how the table paginates.
function pdfSummaryLine($text) {
    return '<div style="background:#F3F6F9; border:1px solid #DCE3EC; border-radius:6px; padding:8px 14px; font-size:11px; font-weight:bold; color:#14213D; margin-top:6px;">' . $text . '</div>';
}

function currentSchoolYearLabel() {
    $sy = getRecord("SELECT sy_name FROM school_years WHERE is_active = 1");
    return $sy['sy_name'] ?? (date('Y') . '-' . (date('Y') + 1));
}

// ============================================================
// SF1 — School Register
// ============================================================
function generateSF1($section_id, $grade_level) {
    $subtitleParts = ['SY ' . currentSchoolYearLabel()];
    $section = null;
    if ($section_id) {
        $section = getRecord("SELECT section_name, grade_level FROM sections WHERE section_id = ?", [$section_id]);
        if ($section) {
            $subtitleParts[] = $section['grade_level'] . ' - ' . $section['section_name'];
        }
    } elseif ($grade_level) {
        $subtitleParts[] = $grade_level;
    }

    $sql = "SELECT s.*, e.enrollment_status
            FROM students s
            JOIN enrollments e ON s.student_id = e.student_id
            WHERE s.is_active = 1 AND e.enrollment_status = 'Approved'";
    $params = [];
    if ($section_id) {
        $sql .= " AND e.section_id = ?";
        $params[] = $section_id;
    }
    if ($grade_level) {
        $sql .= " AND s.grade_level = ?";
        $params[] = $grade_level;
    }
    $sql .= " ORDER BY s.last_name, s.first_name";
    $students = getRecords($sql, $params);

    $rows = [];
    $counter = 1;
    foreach ($students as $student) {
        $rows[] = [
            $counter++,
            htmlspecialchars($student['lrn']),
            htmlspecialchars($student['last_name'] . ', ' . $student['first_name']),
            $student['birth_date'] ? date('Y-m-d', strtotime($student['birth_date'])) : '',
            htmlspecialchars($student['gender'] ?? ''),
            htmlspecialchars($student['guardian_name'] ?? ''),
            htmlspecialchars($student['enrollment_status']),
        ];
    }

    $body = pdfSectionTitle('School Register');
    $body .= pdfTable(
        ['No.', 'LRN', 'Student Name', 'Birth Date', 'Gender', 'Guardian', 'Status'],
        $rows,
        [0 => 'center', 3 => 'center', 4 => 'center', 6 => 'center'],
        'No students found for this selection.'
    );
    $body .= pdfSummaryLine('Total: ' . count($students) . ' student' . (count($students) === 1 ? '' : 's'));

    $filename = 'SF1_School_Register_' . date('Y-m-d') . '.pdf';
    streamReportPdf('SF1 - School Register', implode(' · ', $subtitleParts), $body, $filename, 'A4', 'landscape');
}

// ============================================================
// SF5 — Report on Promotion and Learning Progress
// ============================================================
function generateSF5($section_id) {
    $subtitleParts = ['SY ' . currentSchoolYearLabel()];
    $section = null;
    if ($section_id) {
        $section = getRecord("SELECT section_name, grade_level FROM sections WHERE section_id = ?", [$section_id]);
        if ($section) {
            $subtitleParts[] = $section['grade_level'] . ' - ' . $section['section_name'];
        }
    }

    $active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
    $active_sy_id = $active_sy['sy_id'] ?? 0;
    $sql = "SELECT s.student_id, s.lrn, s.first_name, s.last_name,
                   AVG(g.final_grade) as average
            FROM students s
            JOIN enrollments e ON s.student_id = e.student_id
            JOIN grades g ON s.student_id = g.student_id AND " . gh_validTermSql('g') . "
            WHERE s.is_active = 1 AND e.enrollment_status = 'Approved' AND e.school_year_id = ? AND g.school_year_id = ?";
    $params = [$active_sy_id, $active_sy_id];
    if ($section_id) {
        $sql .= " AND e.section_id = ?";
        $params[] = $section_id;
    }
    $sql .= " GROUP BY s.student_id ORDER BY s.last_name, s.first_name";
    $students = getRecords($sql, $params);

    $rows = [];
    $counter = 1;
    $promoted = 0;
    foreach ($students as $student) {
        $avg = round($student['average'] ?? 0, 2);
        $isPromoted = $avg >= 75;
        if ($isPromoted) {
            $promoted++;
        }
        $rows[] = [
            $counter++,
            htmlspecialchars($student['lrn']),
            htmlspecialchars($student['last_name'] . ', ' . $student['first_name']),
            number_format($avg, 2),
            pdfBadge($isPromoted ? 'Promoted' : 'Retained', $isPromoted ? 'success' : 'danger'),
        ];
    }

    $body = pdfSectionTitle('Promotion and Learning Progress');
    $body .= pdfTable(
        ['No.', 'LRN', 'Student Name', 'Average', 'Status'],
        $rows,
        [0 => 'center', 3 => 'center', 4 => 'center'],
        'No students with recorded grades for this selection.'
    );
    $total = count($students);
    $rate = $total > 0 ? round(($promoted / $total) * 100, 1) : 0;
    $body .= pdfSummaryLine("Total: {$total} student" . ($total === 1 ? '' : 's') . " &middot; Promoted: {$promoted} ({$rate}%)");

    $filename = 'SF5_Promotion_Report_' . date('Y-m-d') . '.pdf';
    streamReportPdf('SF5 - Report on Promotion', implode(' · ', $subtitleParts), $body, $filename);
}

// ============================================================
// SF9 — Report Card
// ============================================================
function generateSF9($student_id) {
    // Accepts either the internal Student ID or the LRN — the form
    // field and every place in the UI that shows a student actually
    // displays the LRN, so look up both instead of only student_id.
    $student = findStudentByIdentifier($student_id);
    if (!$student) {
        reportNotFoundPage('No active student found for that LRN/Student ID.');
        return;
    }
    // From here on, always use the real numeric primary key pulled
    // from the row we just found — never the raw form input, since
    // that may have been the LRN rather than the internal ID.
    $real_student_id = $student['student_id'];

    $terms = getActiveTerms();

    $grades = getRecords("
        SELECT sub.subject_name, g.term, g.final_grade
        FROM grades g
        JOIN subjects sub ON g.subject_code = sub.subject_code
        WHERE g.student_id = ? AND g.school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1)
          AND " . gh_validTermSql('g') . "
        ORDER BY sub.subject_name",
        [$real_student_id]
    );

    // Group by subject
    $subject_grades = [];
    foreach ($grades as $g) {
        $subject_grades[$g['subject_name']][$g['term']] = $g['final_grade'];
    }

    $rows = [];
    foreach ($subject_grades as $subject => $grades_by_term) {
        $averages = [];
        foreach ($terms as $term) {
            $averages[] = $grades_by_term[$term] ?? '';
        }
        $filled = array_filter($averages, function ($v) { return $v !== ''; });
        $final_avg = count($filled) > 0 ? array_sum($filled) / count($filled) : null;

        $row = [htmlspecialchars($subject)];
        foreach ($terms as $term) {
            $row[] = isset($grades_by_term[$term]) ? number_format($grades_by_term[$term], 2) : '&mdash;';
        }
        $row[] = $final_avg !== null ? number_format($final_avg, 2) : '&mdash;';
        $rows[] = $row;
    }

    $align = [0 => 'left'];
    for ($i = 1; $i <= count($terms) + 1; $i++) {
        $align[$i] = 'center';
    }

    $body = pdfInfoBox([
        'Student Name' => htmlspecialchars($student['last_name'] . ', ' . $student['first_name']),
        'LRN' => htmlspecialchars($student['lrn']),
        'Grade Level' => htmlspecialchars($student['grade_level']),
    ]);
    $body .= pdfSectionTitle('Grades by Subject');
    $body .= pdfTable(
        array_merge(['Subject'], $terms, ['Final Average']),
        $rows,
        $align,
        'No grades recorded yet for the active school year.'
    );

    $filename = 'SF9_Report_Card_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $student['lrn']) . '.pdf';
    streamReportPdf('SF9 - Report Card', 'SY ' . currentSchoolYearLabel(), $body, $filename);
}

// Function to generate Report Card — same layout as SF9.
function generateReportCard($student_id) {
    generateSF9($student_id);
}

// ============================================================
// Grade Summary
// ============================================================
function generateGradeSummary($section_id) {
    $subtitleParts = ['SY ' . currentSchoolYearLabel()];
    if ($section_id) {
        $section = getRecord("SELECT section_name, grade_level FROM sections WHERE section_id = ?", [$section_id]);
        if ($section) {
            $subtitleParts[] = $section['grade_level'] . ' - ' . $section['section_name'];
        }
    }

    $active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
    $active_sy_id = $active_sy['sy_id'] ?? 0;
    $sql = "SELECT s.student_id, s.lrn, s.first_name, s.last_name,
                   AVG(g.final_grade) as average
            FROM students s
            JOIN enrollments e ON s.student_id = e.student_id
            JOIN grades g ON s.student_id = g.student_id AND " . gh_validTermSql('g') . "
            WHERE s.is_active = 1 AND e.enrollment_status = 'Approved' AND e.school_year_id = ? AND g.school_year_id = ?";
    $params = [$active_sy_id, $active_sy_id];
    if ($section_id) {
        $sql .= " AND e.section_id = ?";
        $params[] = $section_id;
    }
    $sql .= " GROUP BY s.student_id ORDER BY s.last_name, s.first_name";
    $students = getRecords($sql, $params);

    $rows = [];
    $counter = 1;
    $passing_count = 0;
    foreach ($students as $student) {
        $avg = round($student['average'] ?? 0, 2);
        $is_passing = $avg >= 75;
        if ($is_passing) {
            $passing_count++;
        }
        $rows[] = [
            $counter++,
            htmlspecialchars($student['lrn']),
            htmlspecialchars($student['last_name'] . ', ' . $student['first_name']),
            number_format($avg, 2),
            pdfBadge($is_passing ? 'Yes' : 'No', $is_passing ? 'success' : 'danger'),
            $is_passing ? 'Passed' : 'Failed',
        ];
    }

    $body = pdfSectionTitle('Grade Summary');
    $body .= pdfTable(
        ['No.', 'LRN', 'Student Name', 'Average', 'Passing', 'Remarks'],
        $rows,
        [0 => 'center', 3 => 'center', 4 => 'center', 5 => 'center'],
        'No students with recorded grades for this selection.'
    );
    $total = count($students);
    $rate = $total > 0 ? round(($passing_count / $total) * 100, 2) : 0;
    $body .= pdfSummaryLine("Total: {$total} student" . ($total === 1 ? '' : 's') . " &middot; Passing: {$passing_count} ({$rate}%)");

    $filename = 'Grade_Summary_' . date('Y-m-d') . '.pdf';
    streamReportPdf('Grade Summary', implode(' · ', $subtitleParts), $body, $filename);
}

// ============================================================
// At-Risk / Failing Student — single-student progress & intervention
// report with recommended actions.
// ============================================================
function generateAtRiskStudentReport($student_id) {
    // Accepts either the internal Student ID or the LRN (see
    // findStudentByIdentifier for why — same fix as SF9/Report Card).
    $student = findStudentByIdentifier($student_id);
    if (!$student) {
        reportNotFoundPage('No active student found for that ID/LRN.');
        return;
    }
    // Always use the real numeric primary key from here on, never the
    // raw form input (which may have been the LRN).
    $real_student_id = $student['student_id'];

    $active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
    $school_year_id = $active_sy['sy_id'] ?? 0;

    $pass_mark_row = getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'grading_pass_mark'");
    $pass_mark = floatval($pass_mark_row['setting_value'] ?? 75);
    $at_risk_low = $pass_mark - 5;

    $enrollment = getRecord("
        SELECT sec.section_name, sec.grade_level
        FROM enrollments e
        LEFT JOIN sections sec ON sec.section_id = e.section_id
        WHERE e.student_id = ? AND e.school_year_id = ? AND e.enrollment_status = 'Approved'
        LIMIT 1
    ", [$real_student_id, $school_year_id]);

    // Per-subject average (across whatever terms have been recorded so far)
    $subject_grades = getRecords("
        SELECT sub.subject_name, AVG(g.final_grade) AS subject_average
        FROM grades g
        JOIN subjects sub ON g.subject_code = sub.subject_code
        WHERE g.student_id = ?
          AND " . gh_validTermSql('g') . "
        GROUP BY sub.subject_code, sub.subject_name
        ORDER BY sub.subject_name
    ", [$real_student_id]);

    $overall_average = 0;
    $weak_subjects = [];
    if ($subject_grades) {
        $sum = 0;
        foreach ($subject_grades as &$sg) {
            $sg['subject_average'] = round(floatval($sg['subject_average']), 2);
            $sum += $sg['subject_average'];
            if ($sg['subject_average'] < $pass_mark) {
                $weak_subjects[] = $sg['subject_name'];
            }
        }
        unset($sg);
        $overall_average = round($sum / count($subject_grades), 2);
    }

    if ($overall_average < $at_risk_low) {
        $status = 'Failing';
        $statusVariant = 'danger';
    } elseif ($overall_average < $pass_mark) {
        $status = 'At Risk';
        $statusVariant = 'warning';
    } else {
        $status = 'Passing';
        $statusVariant = 'success';
    }

    // Recommended actions — a mix of general support steps and one
    // per weak subject, so the plan is specific to what's actually failing.
    $recommendations = [
        'Schedule a conference with the class adviser to review progress and set weekly check-ins.',
        'Refer to the guidance office for study-habits and time-management support.',
        'Coordinate with parent/guardian so progress can be monitored at home as well.',
    ];
    foreach ($weak_subjects as $subj) {
        $recommendations[] = 'Attend remedial or tutorial sessions for ' . htmlspecialchars($subj) . ' and complete any missing requirements.';
    }
    if (empty($weak_subjects) && $status !== 'Passing') {
        $recommendations[] = 'Review overall study load — the average is close to the passing mark across subjects, so a general study-skills check-in is recommended.';
    }

    $rows = [];
    if ($subject_grades) {
        foreach ($subject_grades as $sg) {
            $needsWork = $sg['subject_average'] < $pass_mark;
            $rows[] = [
                htmlspecialchars($sg['subject_name']),
                number_format($sg['subject_average'], 2),
                pdfBadge($needsWork ? 'Needs Improvement' : 'Passing', $needsWork ? 'danger' : 'success'),
            ];
        }
    }

    $body = pdfInfoBox([
        'Student Name' => htmlspecialchars($student['last_name'] . ', ' . $student['first_name']),
        'LRN' => htmlspecialchars($student['lrn']),
        'Grade Level' => htmlspecialchars($enrollment['grade_level'] ?? $student['grade_level'] ?? 'N/A'),
        'Section' => htmlspecialchars($enrollment['section_name'] ?? 'Unassigned'),
        'Overall Average' => number_format($overall_average, 2) . '&nbsp;&nbsp;' . pdfBadge($status, $statusVariant),
    ]);

    $body .= pdfSectionTitle('Grades by Subject');
    $body .= pdfTable(
        ['Subject', 'Average', 'Standing'],
        $rows,
        [1 => 'center', 2 => 'center'],
        'No grades recorded yet.'
    );

    $body .= pdfSectionTitle('Recommended Actions');
    if ($status === 'Passing') {
        $body .= '<p>This student is currently passing all subjects. No intervention needed at this time.</p>';
    } else {
        $body .= pdfListItems($recommendations);
    }

    $filename = 'Progress_Report_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $student['lrn']) . '.pdf';
    streamReportPdf('Student Progress & Intervention Report', 'SY ' . currentSchoolYearLabel(), $body, $filename);
}
