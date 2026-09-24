<?php
// modules/students/bulk_import.php
// Bulk CSV import of students. Two-phase to avoid dumping bad data into
// the database: (1) upload → validate every row → show a preview with
// per-row status, nothing is saved yet; (2) confirm → only the rows that
// passed validation are actually inserted.
//
// Each imported row creates a `students` record AND a matching `enrollments`
// record (status Pending, no section assigned) — mirrors what
// modules/enrollment/new.php does for a walk-in enrollment, minus the
// auto section-assignment (that logic depends on an average grade the
// CSV doesn't necessarily have, so sections are left for the registrar
// to assign afterward, same as any Pending enrollment today).

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/enrollment_helpers_online.php'; // assignSectionForApplication()

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Registrar'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Bulk Import Students';
$current_page = 'students';
$base_url = BASE_URL;

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
$valid_grades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
$valid_strands = ['GAS', 'ASSH'];

$preview_rows = [];
$import_summary = null;
$error = '';

// ============================================================
// PHASE 2: CONFIRM IMPORT — re-validates (never trusts session/POST
// blindly) then writes only the valid rows.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid security token. Please refresh the page and try again.');
    }

    if (!$active_sy) {
        $error = 'No active school year found. Set one in Settings before importing.';
    } else {
        $rows = json_decode($_POST['validated_rows'] ?? '[]', true) ?: [];
        $imported = 0;
        $skipped = 0;
        $skip_reasons = [];

        foreach ($rows as $row) {
            // Re-check LRN uniqueness at write time too (another import or
            // manual add could have used it since the preview was shown).
            $exists = getRecord("SELECT student_id FROM students WHERE lrn = ?", [$row['lrn']]);
            if ($exists) {
                $skipped++;
                $skip_reasons[] = $row['lrn'] . ' — LRN already exists';
                continue;
            }

            $sql = "INSERT INTO students (lrn, first_name, last_name, middle_name, birth_date, gender,
                    contact_number, email, address, guardian_name, guardian_contact,
                    grade_level, strand, section_id, school_year_id, enrollment_status, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 1)";

            // Auto-assign a section the same way the walk-in enrollment form
            // does — only when an average grade was supplied (no average =
            // no basis to place them, same rule as modules/enrollment/new.php).
            $assigned_section = null;
            $average_grade = floatval($row['average_grade'] ?? 0);
            if ($average_grade > 0) {
                $assigned_section = assignSectionForApplication($row['grade_level'], $average_grade, $row['strand'] ?: null, $active_sy['sy_id']);
            }
            $section_id = $assigned_section ? $assigned_section['section_id'] : null;

            $params = [
                $row['lrn'], $row['first_name'], $row['last_name'], $row['middle_name'] ?: null,
                $row['birth_date'], $row['gender'], $row['contact_number'] ?: null, $row['email'] ?: null,
                $row['address'] ?: null, $row['guardian_name'] ?: null, $row['guardian_contact'] ?: null,
                $row['grade_level'], $row['strand'] ?: null, $section_id, $active_sy['sy_id'],
            ];
            $ok = executeQuery($sql, $params);

            if ($ok) {
                $student_id = $db->lastInsertId();
                executeQuery("
                    INSERT INTO enrollments (student_id, school_year_id, grade_level, strand, section_id, enrollment_date, enrollment_status, processed_by)
                    VALUES (?, ?, ?, ?, ?, NOW(), 'Pending', ?)
                ", [$student_id, $active_sy['sy_id'], $row['grade_level'], $row['strand'] ?: null, $section_id, $_SESSION['user_id']]);

                $enrollment = getRecord("SELECT enrollment_id FROM enrollments WHERE student_id = ? AND school_year_id = ?", [$student_id, $active_sy['sy_id']]);
                if ($enrollment) {
                    executeQuery("INSERT INTO enrollment_requirements (enrollment_id, submitted_at) VALUES (?, NOW())", [$enrollment['enrollment_id']]);
                }
                $imported++;
            } else {
                $skipped++;
                $skip_reasons[] = $row['lrn'] . ' — database error while saving';
            }
        }

        logActivity($_SESSION['user_id'], 'Bulk Student Import', 'students', "Imported $imported, skipped $skipped");

        $import_summary = ['imported' => $imported, 'skipped' => $skipped, 'reasons' => $skip_reasons];
    }
}
// ============================================================
// PHASE 1: UPLOAD & VALIDATE — parses the CSV, checks every row,
// nothing touches the database yet.
// ============================================================
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid security token. Please refresh the page and try again.');
    }

    if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'File upload failed. Please try again.';
    } else {
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$handle) {
            $error = 'Could not read the uploaded file.';
        } else {
            $header = fgetcsv($handle);
            if (!$header) {
                $error = 'The file appears to be empty.';
            } else {
                // Excel saves CSVs with a UTF-8 BOM prefix, which silently
                // attaches itself to the first header cell (e.g. "lrn"
                // becomes an invisible-prefixed "lrn" that never matches).
                // Strip it before anything else touches the header.
                if (isset($header[0])) {
                    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
                }
                // Normalize header names so column order in the file doesn't matter.
                $header = array_map(function ($h) { return strtolower(trim($h)); }, $header);
                $expected = ['lrn', 'last_name', 'first_name', 'middle_name', 'birth_date', 'gender',
                             'grade_level', 'strand', 'average_grade', 'contact_number', 'email', 'address',
                             'guardian_name', 'guardian_contact'];
                $missing_cols = array_diff(['lrn', 'last_name', 'first_name', 'birth_date', 'gender', 'grade_level'], $header);

                if (!empty($missing_cols)) {
                    $error = 'Missing required column(s) in the CSV: ' . implode(', ', $missing_cols) . '. Download the template below to check the expected format.';
                } else {
                    $row_num = 1;
                    $seen_lrns_in_file = [];
                    while (($data = fgetcsv($handle)) !== false) {
                        $row_num++;
                        if (count(array_filter($data, fn($v) => trim((string)$v) !== '')) === 0) {
                            continue; // skip fully blank lines
                        }
                        $combined = array_combine($header, array_pad($data, count($header), ''));
                        $get = fn($key) => trim($combined[$key] ?? '');

                        $lrn = preg_replace('/[^0-9]/', '', $get('lrn'));
                        $last_name = $get('last_name');
                        $first_name = $get('first_name');
                        $middle_name = $get('middle_name');
                        $birth_date_raw = $get('birth_date');
                        $gender = ucfirst(strtolower($get('gender')));
                        $grade_level = $get('grade_level');
                        $strand = strtoupper($get('strand'));
                        $contact_number = $get('contact_number');
                        $email = $get('email');
                        $address = $get('address');
                        $guardian_name = $get('guardian_name');
                        $guardian_contact = $get('guardian_contact');
                        $average_grade_raw = $get('average_grade');

                        $row_errors = [];

                        $average_grade = 0;
                        if ($average_grade_raw !== '') {
                            if (!is_numeric($average_grade_raw) || $average_grade_raw < 0 || $average_grade_raw > 100) {
                                $row_errors[] = 'Average grade must be a number between 0 and 100';
                            } else {
                                $average_grade = floatval($average_grade_raw);
                            }
                        }

                        if (strlen($lrn) !== 12) $row_errors[] = 'LRN must be exactly 12 digits';
                        if ($last_name === '') $row_errors[] = 'Last name is required';
                        if ($first_name === '') $row_errors[] = 'First name is required';

                        $birth_date = '';
                        if ($birth_date_raw === '') {
                            $row_errors[] = 'Birth date is required';
                        } else {
                            $ts = strtotime($birth_date_raw);
                            if ($ts === false) {
                                $row_errors[] = 'Birth date format not recognized (use YYYY-MM-DD)';
                            } else {
                                $birth_date = date('Y-m-d', $ts);
                            }
                        }

                        if (!in_array($gender, ['Male', 'Female'], true)) $row_errors[] = 'Gender must be Male or Female';
                        if (!in_array($grade_level, $valid_grades, true)) $row_errors[] = 'Grade level must be one of: ' . implode(', ', $valid_grades);

                        if (in_array($grade_level, ['Grade 11', 'Grade 12'], true)) {
                            if (!in_array($strand, $valid_strands, true)) {
                                $row_errors[] = 'Strand must be GAS or ASSH for Grade 11/12';
                            }
                        } else {
                            $strand = '';
                        }

                        if ($email !== '' && !validateEmail($email)) $row_errors[] = 'Email format is invalid';

                        if ($lrn !== '') {
                            if (isset($seen_lrns_in_file[$lrn])) {
                                $row_errors[] = "Duplicate LRN within this file (also on row {$seen_lrns_in_file[$lrn]})";
                            } else {
                                $seen_lrns_in_file[$lrn] = $row_num;
                                $existing = getRecord("SELECT student_id FROM students WHERE lrn = ?", [$lrn]);
                                if ($existing) $row_errors[] = 'LRN already exists in the system';
                            }
                        }

                        $preview_rows[] = [
                            'row_num' => $row_num,
                            'lrn' => $lrn,
                            'last_name' => $last_name,
                            'first_name' => $first_name,
                            'middle_name' => $middle_name,
                            'birth_date' => $birth_date,
                            'birth_date_display' => $birth_date_raw,
                            'gender' => $gender,
                            'grade_level' => $grade_level,
                            'strand' => $strand,
                            'average_grade' => $average_grade,
                            'contact_number' => $contact_number,
                            'email' => $email,
                            'address' => $address,
                            'guardian_name' => $guardian_name,
                            'guardian_contact' => $guardian_contact,
                            'errors' => $row_errors,
                            'valid' => empty($row_errors),
                        ];
                    }
                }
            }
            fclose($handle);
        }
    }
}

$valid_count = count(array_filter($preview_rows, fn($r) => $r['valid']));
$invalid_count = count($preview_rows) - $valid_count;
$csrf_token = generateCSRFToken();

include '../../includes/header.php';
?>

<style>
    .import-page h4, .import-page h6 { font-family: var(--font-primary, 'Inter', sans-serif); }
    .import-drop {
        border: 2px dashed var(--border); border-radius: var(--radius); padding: 40px 20px;
        text-align: center; background: var(--surface);
    }
    .preview-table td, .preview-table th { font-size: 12.5px; vertical-align: middle; }
    .row-invalid { background: #FFF5F5; }
    .row-valid { background: #F3FBF6; }
    .err-list { font-size: 11px; color: #C0392B; margin: 0; padding-left: 16px; }
</style>

<div class="container-fluid py-4 import-page">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="fas fa-file-import me-2" style="color:var(--ink);"></i>Bulk Import Students</h4>
            <p class="text-muted mb-0 small">Upload a CSV to create multiple student + Pending enrollment records at once.</p>
        </div>
        <a href="<?php echo BASE_URL; ?>modules/students/bulk_import_template.php" class="btn btn-sm btn-outline-dark">
            <i class="fas fa-download me-1"></i>Download CSV Template
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($import_summary): ?>
        <!-- ============================================================ -->
        <!-- RESULT SCREEN -->
        <!-- ============================================================ -->
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body text-center py-5">
                <i class="fas fa-circle-check text-success mb-3" style="font-size:42px;"></i>
                <h5 class="fw-bold">Import Complete</h5>
                <p class="text-muted mb-3">
                    <strong class="text-success"><?php echo $import_summary['imported']; ?></strong> student(s) imported successfully
                    <?php if ($import_summary['skipped'] > 0): ?>
                        · <strong class="text-danger"><?php echo $import_summary['skipped']; ?></strong> skipped
                    <?php endif; ?>
                </p>
                <?php if (!empty($import_summary['reasons'])): ?>
                    <div class="alert alert-warning text-start mx-auto" style="max-width:500px;">
                        <strong>Skipped rows:</strong>
                        <ul class="mb-0 small">
                            <?php foreach ($import_summary['reasons'] as $reason): ?>
                                <li><?php echo htmlspecialchars($reason); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <p class="text-muted small">Imported students are Pending — assign sections and approve them from the Enrollment page.</p>
                <div class="d-flex justify-content-center gap-2 mt-3">
                    <a href="<?php echo BASE_URL; ?>modules/students/bulk_import.php" class="btn btn-outline-dark">Import More</a>
                    <a href="<?php echo BASE_URL; ?>modules/enrollment/index.php" class="btn btn-dark" style="background:var(--ink); border-color:var(--ink);">Go to Enrollment</a>
                </div>
            </div>
        </div>

    <?php elseif (!empty($preview_rows)): ?>
        <!-- ============================================================ -->
        <!-- PREVIEW SCREEN — nothing saved yet -->
        <!-- ============================================================ -->
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="card stats-card border-0 rounded-3">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div><h6 class="mb-1">Ready to Import</h6><h2 class="mb-0 text-success"><?php echo $valid_count; ?></h2></div>
                        <div class="stats-icon bg-success bg-opacity-10"><i class="fas fa-check text-success"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card stats-card border-0 rounded-3">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div><h6 class="mb-1">Has Errors (will be skipped)</h6><h2 class="mb-0 text-danger"><?php echo $invalid_count; ?></h2></div>
                        <div class="stats-icon bg-danger bg-opacity-10"><i class="fas fa-triangle-exclamation text-danger"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-3 mb-3">
            <div class="card-body">
                <div class="table-responsive" style="max-height:500px; overflow-y:auto;">
                    <table class="table table-sm preview-table mb-0">
                        <thead class="small text-uppercase text-muted" style="position:sticky; top:0; background: var(--surface);">
                            <tr>
                                <th>#</th><th>LRN</th><th>Name</th><th>Birth Date</th><th>Gender</th>
                                <th>Grade</th><th>Strand</th><th>Avg. Grade</th><th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview_rows as $r): ?>
                            <tr class="<?php echo $r['valid'] ? 'row-valid' : 'row-invalid'; ?>">
                                <td><?php echo $r['row_num']; ?></td>
                                <td><?php echo htmlspecialchars($r['lrn']); ?></td>
                                <td><?php echo htmlspecialchars($r['last_name'] . ', ' . $r['first_name']); ?></td>
                                <td><?php echo htmlspecialchars($r['birth_date'] ?: $r['birth_date_display']); ?></td>
                                <td><?php echo htmlspecialchars($r['gender']); ?></td>
                                <td><?php echo htmlspecialchars($r['grade_level']); ?></td>
                                <td><?php echo htmlspecialchars($r['strand']); ?></td>
                                <td><?php echo $r['average_grade'] > 0 ? htmlspecialchars($r['average_grade']) : '<span class="text-muted">—</span>'; ?></td>
                                <td>
                                    <?php if ($r['valid']): ?>
                                        <span class="badge bg-success">Valid</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Error</span>
                                        <ul class="err-list mt-1">
                                            <?php foreach ($r['errors'] as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($valid_count > 0): ?>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="confirm_import" value="1">
            <input type="hidden" name="validated_rows" value='<?php echo htmlspecialchars(json_encode(array_values(array_filter($preview_rows, fn($r) => $r['valid']))), ENT_QUOTES); ?>'>
            <div class="d-flex justify-content-between align-items-center">
                <a href="<?php echo BASE_URL; ?>modules/students/bulk_import.php" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-dark" style="background:var(--ink); border-color:var(--ink);">
                    <i class="fas fa-upload me-2"></i>Confirm Import (<?php echo $valid_count; ?> student<?php echo $valid_count === 1 ? '' : 's'; ?>)
                </button>
            </div>
        </form>
        <?php else: ?>
            <div class="alert alert-warning">No valid rows to import. Fix the errors above and re-upload.</div>
            <a href="<?php echo BASE_URL; ?>modules/students/bulk_import.php" class="btn btn-outline-dark">Try Again</a>
        <?php endif; ?>

    <?php else: ?>
        <!-- ============================================================ -->
        <!-- UPLOAD SCREEN -->
        <!-- ============================================================ -->
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body">
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="import-drop mb-3">
                        <i class="fas fa-file-csv mb-2" style="font-size:32px; color:var(--ink); opacity:0.6;"></i>
                        <p class="mb-2 fw-semibold">Select a CSV file to upload</p>
                        <input type="file" name="csv_file" accept=".csv" class="form-control" style="max-width:360px; margin:0 auto;" required>
                    </div>
                    <div class="alert alert-light border small">
                        <strong>Required columns:</strong> lrn, last_name, first_name, birth_date, gender, grade_level<br>
                        <strong>Optional columns:</strong> middle_name, strand, average_grade, contact_number, email, address, guardian_name, guardian_contact<br>
                        Column order doesn't matter, but the header row must use these exact names. Download the template above for the correct format.
                        <hr class="my-2">
                        <strong>About <code>average_grade</code>:</strong> if you include a number (0–100), the student's section is auto-assigned the same way the walk-in enrollment form does — high averages get first pick of the "A/B" sections, others fill the remaining sections. Leave it blank to import with no section (assign manually later from Enrollment).
                    </div>
                    <button type="submit" class="btn btn-dark" style="background:var(--ink); border-color:var(--ink);">
                        <i class="fas fa-magnifying-glass me-2"></i>Upload &amp; Preview
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>