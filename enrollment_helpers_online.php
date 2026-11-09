<?php
// includes/enrollment_helpers_online.php
// Shared helpers for the ONLINE student enrollment module.
// (Walk-in enrollment stays in modules/enrollment/new.php — this file is only
//  for the public, self-service online application.)

require_once __DIR__ . '/../config/database.php';

if (!defined('APPLICATION_UPLOAD_DIR')) {
    define('APPLICATION_UPLOAD_DIR', __DIR__ . '/../static/uploads/applications/');
}
if (!defined('APPLICATION_UPLOAD_URL')) {
    define('APPLICATION_UPLOAD_URL', 'static/uploads/applications/');
}

/**
 * Requirements differ per grade level.
 *  - Grade 7        : incoming Junior High (from Grade 6 elementary)
 *  - Grade 8 to 10  : Junior High continuing / transferee
 *  - Grade 11       : incoming Senior High (from Grade 10) — needs strand
 *  - Grade 12       : Senior High continuing — needs strand
 *  (Grade 11 is also open to Continuing learners who finished Grade 10 at IISS.)
 *
 * @return array list of [key, label, hint, required]
 */
function getRequiredDocuments($grade_level, $applicant_type = null)
{
    // With an Applicant Type the list follows the type rules (see below);
    // without one (older callers / applications submitted before the type
    // rules existed) the original per-grade list is returned unchanged.
    if ($applicant_type !== null && $applicant_type !== '') {
        return getRequiredDocumentsForType($grade_level, $applicant_type);
    }

    $psa        = ['key' => 'psa',          'label' => 'PSA / NSO Birth Certificate',                 'hint' => 'Clear photocopy or scanned copy.',                                  'required' => true];
    $good_moral = ['key' => 'good_moral',   'label' => 'Certificate of Good Moral Character',         'hint' => 'Issued by your previous school.',                                   'required' => true];
    $id_pic     = ['key' => 'id_picture',   'label' => '2x2 ID Picture (white background)',           'hint' => 'Recent photo, name tag optional.',                                  'required' => true];
    $form137    = ['key' => 'form137',      'label' => 'Form 137 (Permanent Record)',                 'hint' => 'Request from your previous school registrar.',                      'required' => false];
    $brgy       = ['key' => 'brgy_cert',    'label' => 'Barangay Certificate of Residency',           'hint' => 'Optional — for proof of address.',                                  'required' => false];

    switch ($grade_level) {
        case 'Grade 7':
            return [
                ['key' => 'form138', 'label' => 'Form 138 — Grade 6 Report Card',            'hint' => 'Front and back page, with the promotion remarks.', 'required' => true],
                ['key' => 'completion_cert', 'label' => 'Certificate of Completion (Elementary)', 'hint' => 'Proof that you finished Grade 6.',           'required' => true],
                $psa, $good_moral, $id_pic, $form137, $brgy,
            ];

        case 'Grade 8':
        case 'Grade 9':
        case 'Grade 10':
            $prev = 'Grade ' . (intval(str_replace('Grade ', '', $grade_level)) - 1);
            return [
                ['key' => 'form138', 'label' => 'Form 138 — ' . $prev . ' Report Card', 'hint' => 'Card from your last school year, with promotion remarks.', 'required' => true],
                array_merge($form137, ['required' => true]),
                $psa, $good_moral, $id_pic, $brgy,
            ];

        case 'Grade 11':
            return [
                ['key' => 'form138', 'label' => 'Form 138 — Grade 10 Report Card',                  'hint' => 'Junior High School card with the promotion remarks.', 'required' => true],
                ['key' => 'jhs_completion', 'label' => 'JHS Certificate of Completion',              'hint' => 'Proof of completing Junior High School.',             'required' => true],
                $psa, $good_moral, $id_pic,
                ['key' => 'esc_qvr',  'label' => 'ESC Certificate / QVR Voucher Number',             'hint' => 'Optional — only if you are a voucher recipient.',     'required' => false],
                ['key' => 'sf10_jhs', 'label' => 'SF10 / Form 137 (Junior High)',                    'hint' => 'Optional at application, required upon enrollment.',  'required' => false],
            ];

        case 'Grade 12':
            return [
                ['key' => 'form138', 'label' => 'Form 138 — Grade 11 Report Card',                   'hint' => 'Senior High card for both semesters.',                'required' => true],
                ['key' => 'cor_shs', 'label' => 'Certificate of Registration (Grade 11)',            'hint' => 'From your Grade 11 school.',                          'required' => true],
                ['key' => 'sf10_shs', 'label' => 'SF10 — Senior High Permanent Record',              'hint' => 'Shows your Grade 11 subjects and grades.',            'required' => true],
                $psa, $good_moral, $id_pic,
                ['key' => 'esc_qvr', 'label' => 'SHS Voucher / QVR Certificate',                     'hint' => 'Optional — voucher recipients only.',                 'required' => false],
            ];
    }

    return [$psa, $good_moral, $id_pic];
}

// ============================================================
// APPLICANT TYPE RULES
//   New Student  - Grade 7 and Grade 11 only.     Report Card + Birth Certificate
//   Continuing   - Grade 8 to 12, but only the ONE grade that follows the learner's
//                  verified IISS record (see getContinuingEligibility()).  Report Card only
//   Returning    - any grade (Balik-Aral).        Report Card + Birth Certificate
//                  + Last Grade Completed / Last School Year / Last School Attended
//   Transferee   - any grade.                     Report Card + Birth Certificate
//                  + Previous School Name / Address, Last Grade Completed / Last School Year
// The values are the existing online_applications.application_type ENUM values.
// ============================================================

/** @return string[] the four Applicant Types, in dropdown order */
function getApplicantTypes()
{
    return ['New Student', 'Transferee', 'Returning', 'Continuing'];
}

/** Grade levels each Applicant Type may apply for. */
function getApplicantTypeGrades($applicant_type)
{
    switch ($applicant_type) {
        case 'New Student': return ['Grade 7', 'Grade 11'];
        case 'Continuing':  return ['Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
        case 'Transferee':
        case 'Returning':   return getOnlineGradeLevels();
    }
    return [];
}

function isApplicantTypeAllowedForGrade($applicant_type, $grade_level)
{
    return in_array($grade_level, getApplicantTypeGrades($applicant_type), true);
}

/** Returning / Transferee also give details about the school they came from. */
function applicantTypeNeedsPreviousSchool($applicant_type)
{
    return in_array($applicant_type, ['Returning', 'Transferee'], true);
}

/** Human message when an Applicant Type / Grade combination is not allowed. */
function applicantTypeGradeMessage($applicant_type, $grade_level)
{
    if ($applicant_type === 'New Student') {
        return 'New Student applications are only for Grade 7 and Grade 11. For ' . $grade_level . ', please choose Continuing (current student), Returning or Transferee.';
    }
    if ($applicant_type === 'Continuing') {
        return 'Continuing applications are only for Grade 8, 9, 10, 11 and 12. For ' . $grade_level . ', please choose New Student, Returning or Transferee.';
    }
    return 'The selected Applicant Type is not available for ' . $grade_level . '.';
}

/**
 * Documents to upload for one Applicant Type + grade level.
 * Report Card (Form 138) for everyone, PSA/NSO Birth Certificate for everyone
 * except Continuing students. [] when the combination is not allowed.
 */
function getRequiredDocumentsForType($grade_level, $applicant_type)
{
    if (!isApplicantTypeAllowedForGrade($applicant_type, $grade_level)) {
        return [];
    }

    // Report card: reuse the grade's own Form 138 entry (label names the grade before it).
    $report = null;
    foreach (getRequiredDocuments($grade_level) as $d) {
        if ($d['key'] === 'form138') { $report = $d; break; }
    }
    if ($report === null) {
        $report = ['key' => 'form138', 'label' => 'Form 138 — Report Card', 'hint' => 'With the promotion remarks.', 'required' => true];
    }
    if (applicantTypeNeedsPreviousSchool($applicant_type)) {
        // Their last grade completed is not necessarily the grade just below the one applied for.
        $report['label'] = 'Form 138 — Report Card';
        $report['hint'] = 'Your most recent report card (the last grade level you completed), with the promotion remarks.';
    }
    $report['required'] = true;

    $docs = [$report];
    if ($applicant_type !== 'Continuing') {
        $docs[] = ['key' => 'psa', 'label' => 'PSA / NSO Birth Certificate', 'hint' => 'Clear photocopy or scanned copy.', 'required' => true];
    }
    if (oeForm137Counts($applicant_type)) {
        // Optional: a missing Form 137 never makes the application Incomplete Requirements.
        $docs[] = ['key' => 'form137', 'label' => 'Form 137 (Permanent Record)', 'hint' => 'Optional. Upload it if you already have it; you can also send it later on your Enrollment Tracking page.', 'required' => false];
    }
    return $docs;
}

/** Grade levels a Returning / Transferee applicant can name as "last grade completed" (lower than the grade applied for). */
function getLastGradeOptions($grade_level)
{
    $applying = intval(str_replace('Grade ', '', $grade_level));
    // Secondary school (Grade 7-12): no elementary grades. Only a Grade 7 applicant can have come from Grade 6.
    if ($applying === 7) return ['Grade 6'];
    $out = [];
    for ($i = 7; $i < $applying && $i <= 12; $i++) {
        $out[] = 'Grade ' . $i;
    }
    return $out;
}

/** Grade levels open for online application */
function getOnlineGradeLevels()
{
    return ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
}

function isSeniorHigh($grade_level)
{
    return in_array($grade_level, ['Grade 11', 'Grade 12'], true);
}

/** Unique, human-friendly reference number: OE-2026-4F7K2Q */
function generateApplicationReference()
{
    do {
        $ref = 'OE-' . date('Y') . '-' . strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6));
        $exists = getRecord("SELECT application_id FROM online_applications WHERE reference_no = ?", [$ref]);
    } while ($exists);
    return $ref;
}

/**
 * Save one uploaded requirement file.
 * @return array{success:bool,message:string,path?:string,name?:string,type?:string,size?:int}
 */
function saveApplicationFile($file, $reference_no, $doc_key)
{
    $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
    $max_size = 5 * 1024 * 1024; // 5 MB

    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'message' => 'No file uploaded.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $upload_error_messages = [
            UPLOAD_ERR_INI_SIZE   => 'This file is larger than the server allows. Please use a smaller photo or scan (under 5 MB).',
            UPLOAD_ERR_FORM_SIZE  => 'This file is larger than the server allows. Please use a smaller photo or scan (under 5 MB).',
            UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please check your connection and try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no place to store uploads right now. Please try again later or contact the registrar.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save this file. Please try again later or contact the registrar.',
            UPLOAD_ERR_EXTENSION  => 'The upload was blocked by the server. Please try again or contact the registrar.',
        ];
        $message = $upload_error_messages[$file['error']] ?? ('Upload failed (error code ' . $file['error'] . ').');
        return ['success' => false, 'message' => $message];
    }
    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File is larger than 5 MB.'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return ['success' => false, 'message' => 'Only JPG, PNG, WEBP or PDF files are allowed.'];
    }

    if (!is_dir(APPLICATION_UPLOAD_DIR)) {
        @mkdir(APPLICATION_UPLOAD_DIR, 0775, true);
    }

    $safe_ref = preg_replace('/[^A-Za-z0-9\-]/', '', $reference_no);
    $safe_key = preg_replace('/[^a-z0-9_]/', '', $doc_key);
    $new_name = $safe_ref . '_' . $safe_key . '_' . time() . '.' . $ext;
    $target = APPLICATION_UPLOAD_DIR . $new_name;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['success' => false, 'message' => 'Could not save the uploaded file.'];
    }

    return [
        'success' => true,
        'message' => 'Uploaded.',
        'path' => APPLICATION_UPLOAD_URL . $new_name,
        'name' => $file['name'],
        'type' => $file['type'] ?? $ext,
        'size' => (int) $file['size'],
    ];
}

/** Notify every Administrator / Registrar about a new online application. */
function notifyStaffNewApplication($application)
{
    $staff = getRecords("
        SELECT u.user_id FROM users u
        JOIN roles r ON u.role_id = r.role_id
        WHERE r.role_name IN ('Administrator', 'Registrar') AND u.is_active = 1
    ");

    $name = $application['first_name'] . ' ' . $application['last_name'];
    $title = 'New Online Enrollment Application';
    $message = $name . ' applied online for ' . $application['grade_level']
        . (!empty($application['strand']) ? ' (' . $application['strand'] . ')' : '')
        . '. Reference No. ' . $application['reference_no'] . '.';
    $link = 'modules/enrollment/view_application.php?id=' . $application['application_id'];

    foreach ($staff as $s) {
        executeQuery(
            "INSERT INTO notifications (user_id, title, message, type, link, is_read) VALUES (?, ?, ?, 'info', ?, 0)",
            [$s['user_id'], $title, $message, $link]
        );
    }
}

/** Count of applications still waiting for a decision (for the sidebar badge). */
function getPendingApplicationCount()
{
    require_once __DIR__ . '/eo_scope.php';
    [$eo_where, $eo_params] = eoSql('grade_level');   // Enrollment Officer: own grade level only
    $row = getRecord("SELECT COUNT(*) AS cnt FROM online_applications WHERE status IN ('Pending','Under Review') AND deleted_at IS NULL" . $eo_where, $eo_params);
    return intval($row['cnt'] ?? 0);
}

/** Documents attached to an application. */
function getApplicationDocuments($application_id)
{
    return getRecords("SELECT * FROM application_documents WHERE application_id = ? ORDER BY document_id", [$application_id]);
}

// ============================================================
// SECTION ASSIGNMENT (same rules as walk-in: A/B homogeneous, C+ heterogeneous)
// ============================================================
if (!defined('ONLINE_HOMOGENEOUS_SLOTS')) {
    define('ONLINE_HOMOGENEOUS_SLOTS', 2);
    define('ONLINE_HOMOGENEOUS_THRESHOLD', 90);
}

if (!function_exists('onlineSectionLetter')) {
    function onlineSectionLetter($section_name)
    {
        $last = strtoupper(substr(rtrim(trim($section_name)), -1));
        return preg_match('/^[A-Z]$/', $last) ? $last : null;
    }
}

if (!function_exists('onlineSectionOccupancy')) {
    function onlineSectionOccupancy($section_id, $school_year_id)
    {
        syncSectionEnrollmentCounts($school_year_id); // capacity is checked against the students actually enrolled
        $section = getRecord("SELECT current_enrollment FROM sections WHERE section_id = ?", [$section_id]);
        $pending = getRecord(
            "SELECT COUNT(*) AS cnt FROM enrollments WHERE section_id = ? AND school_year_id = ? AND enrollment_status = 'Pending'",
            [$section_id, $school_year_id]
        );
        return intval($section['current_enrollment'] ?? 0) + intval($pending['cnt'] ?? 0);
    }
}

if (!function_exists('onlineCreateNextSection')) {
    function onlineCreateNextSection($grade_level, $strand, $is_shs, $existing, $school_year_id)
    {
        $used = [];
        foreach ($existing as $s) {
            $l = onlineSectionLetter($s['section_name']);
            if ($l) $used[] = $l;
        }
        $next = null;
        for ($i = 0; $i < 26; $i++) {
            $c = chr(65 + $i);
            if (!in_array($c, $used, true)) { $next = $c; break; }
        }
        if ($next === null) return null;

        $grade_num = preg_replace('/[^0-9]/', '', $grade_level) ?: $grade_level;
        $section_name = ($is_shs && !empty($strand))
            ? $grade_num . '-' . $strand . ' ' . $next
            : $grade_num . '-' . $next;
        $capacity = $is_shs ? 40 : 45;

        $sy = getRecord("SELECT sy_name FROM school_years WHERE sy_id = ?", [$school_year_id]);
        $academic_year = $sy['sy_name'] ?? (date('Y') . '-' . (date('Y') + 1));

        $dupe = getRecord(
            "SELECT section_id, section_name, capacity, current_enrollment FROM sections WHERE section_name = ? AND academic_year = ? LIMIT 1",
            [$section_name, $academic_year]
        );
        if ($dupe) return $dupe;

        $section_id = insertRecord(
            "INSERT INTO sections (section_name, grade_level, strand, is_senior_high, academic_year, capacity, current_enrollment, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 0, 1)",
            [$section_name, $grade_level, $is_shs ? $strand : null, $is_shs ? 1 : 0, $academic_year, $capacity]
        );
        if (!$section_id) return null;

        return ['section_id' => $section_id, 'section_name' => $section_name, 'capacity' => $capacity, 'current_enrollment' => 0];
    }
}

/**
 * sections.min_average / max_average: the General Average range the school
 * set for a section (Settings > Sections). Additive columns, added once.
 */
function onlineEnsureSectionRangeColumns()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('schemaIsReady') && schemaIsReady('section_ga_range_v1')) return;
    $cols = array_column(getRecords("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sections'"), 'COLUMN_NAME');
    if (!in_array('min_average', $cols, true)) {
        executeQuery("ALTER TABLE sections ADD COLUMN min_average DECIMAL(5,2) NULL COMMENT 'Section assignment: lowest General Average for this section (NULL = not set)'");
    }
    if (!in_array('max_average', $cols, true)) {
        executeQuery("ALTER TABLE sections ADD COLUMN max_average DECIMAL(5,2) NULL COMMENT 'Section assignment: highest General Average for this section (NULL = not set)'");
    }
    if (function_exists('schemaMarkReady')) schemaMarkReady('section_ga_range_v1');
}

if (!function_exists('assignSectionForApplication')) {
    function assignSectionForApplication($grade_level, $average_grade, $strand, $school_year_id)
    {
        $is_shs = isSeniorHigh($grade_level);
        onlineEnsureSectionRangeColumns();

        $sections = getRecords(
            "SELECT section_id, section_name, grade_level, strand, capacity, current_enrollment, min_average, max_average
             FROM sections WHERE is_active = 1 AND grade_level = ? ORDER BY section_name",
            [$grade_level]
        );

        if ($is_shs && !empty($strand)) {
            $sections = array_values(array_filter($sections, fn($s) => $s['strand'] === $strand));
        }

        // School-configured General Average ranges (Settings > Sections). When a
        // grade level has any, the learner goes to a section whose range holds
        // their verified General Average -- the least-filled one, so learners of
        // the same range are spread by capacity. If every matching section is
        // full, a section without a range takes them, otherwise the usual rule below.
        $ranged = array_values(array_filter($sections, fn($s) => $s['min_average'] !== null || $s['max_average'] !== null));
        if (!empty($ranged) && $average_grade > 0) {
            $fits = array_filter($ranged, fn($s) => ($s['min_average'] === null || $average_grade >= (float) $s['min_average'])
                                                 && ($s['max_average'] === null || $average_grade <= (float) $s['max_average']));
            $best = null; $bestFill = 2.0;
            foreach ($fits as $s) {
                $cap = max(1, (int) $s['capacity']);
                $occ = onlineSectionOccupancy($s['section_id'], $school_year_id);
                if ($occ < $cap && ($occ / $cap) < $bestFill) { $best = $s; $bestFill = $occ / $cap; }
            }
            if ($best) return $best;
            foreach ($sections as $s) {
                if ($s['min_average'] === null && $s['max_average'] === null
                    && onlineSectionOccupancy($s['section_id'], $school_year_id) < intval($s['capacity'])) {
                    return $s;
                }
            }
            // Nothing configured fits / has room: the school's usual rule below, over every section.
        }

        usort($sections, fn($a, $b) => strcmp(onlineSectionLetter($a['section_name']) ?? 'Z', onlineSectionLetter($b['section_name']) ?? 'Z'));

        $homogeneous = array_slice($sections, 0, ONLINE_HOMOGENEOUS_SLOTS);
        $heterogeneous = array_slice($sections, ONLINE_HOMOGENEOUS_SLOTS);

        if ($average_grade >= ONLINE_HOMOGENEOUS_THRESHOLD) {
            foreach ($homogeneous as $s) {
                if (onlineSectionOccupancy($s['section_id'], $school_year_id) < intval($s['capacity'])) return $s;
            }
        }
        foreach ($heterogeneous as $s) {
            if (onlineSectionOccupancy($s['section_id'], $school_year_id) < intval($s['capacity'])) return $s;
        }

        return onlineCreateNextSection($grade_level, $strand, $is_shs, $sections, $school_year_id);
    }
}

// ============================================================
// LRN LOOKUP / DUPLICATE-STUDENT PROTECTION (online enrollment)
//   Continuing - must already have a student record: ask LRN first, load the
//                profile, only the details that changed are updated.
//   Returning  - LRN is checked; an old record is reused (never duplicated),
//                but the learner has to review / update every detail.
//   New / Transferee - full form; an LRN that already exists is refused.
// The profile is only released after the LRN AND the date of birth match, so
// nobody can read a learner's details by guessing LRNs.
// ============================================================

/** Types that look the learner up by LRN before the personal-information form. */
function applicantTypeLooksUpLRN($applicant_type)
{
    return in_array($applicant_type, ['Continuing', 'Returning'], true);
}

/**
 * Saved profile of an existing learner (students row + the extra details the
 * students table has no columns for, taken from the latest application with
 * the same LRN). Null when no student has this LRN.
 * @return array|null [student_id, ...profile fields..., current_grade]
 */
function getLearnerProfileByLRN($lrn)
{
    $s = getRecord("SELECT * FROM students WHERE lrn = ?", [$lrn]);
    if (!$s) return null;

    $a = getRecord("SELECT suffix, birth_place, religion, nationality, father_name, mother_name, guardian_relationship, address, contact_number, email, guardian_name, guardian_contact
                    FROM online_applications WHERE (lrn = ? OR student_id = ?) ORDER BY application_id DESC LIMIT 1", [$lrn, $s['student_id']]) ?: [];

    $pick = function ($key, $from_student = true) use ($s, $a) {
        if ($from_student && isset($s[$key]) && trim((string) $s[$key]) !== '') return trim((string) $s[$key]);
        return isset($a[$key]) ? trim((string) $a[$key]) : '';
    };

    return [
        'student_id'            => (int) $s['student_id'],
        'lrn'                   => $s['lrn'],
        'last_name'             => trim((string) $s['last_name']),
        'first_name'            => trim((string) $s['first_name']),
        'middle_name'           => trim((string) $s['middle_name']),
        'suffix'                => $pick('suffix', false),
        'birth_date'            => !empty($s['birth_date']) ? substr((string) $s['birth_date'], 0, 10) : '',
        'gender'                => (string) $s['gender'],
        'contact_number'        => $pick('contact_number'),
        'email'                 => $pick('email'),
        'address'               => $pick('address'),
        'guardian_name'         => $pick('guardian_name'),
        'guardian_contact'      => $pick('guardian_contact'),
        'birth_place'           => $pick('birth_place', false),
        'religion'              => $pick('religion', false),
        'nationality'           => $pick('nationality', false),
        'father_name'           => $pick('father_name', false),
        'mother_name'           => $pick('mother_name', false),
        'guardian_relationship' => $pick('guardian_relationship', false),
        'current_grade'         => (string) $s['grade_level'],
        'previous_grades'       => getContinuingPreviousGrades($s['student_id']),
    ];
}

/**
 * The seven learning areas the application form reports, and how each one is
 * recognised from a Junior High subject name (English 8, Values Education is
 * deliberately not one of them).
 * @return array form field => regex on the subject name
 */
function oePreviousGradeAreas()
{
    return [
        'english_grade'  => '/^english\b/i',
        'math_grade'     => '/^math(ematics)?\b/i',
        'science_grade'  => '/^science\b/i',
        'filipino_grade' => '/^filipino\b/i',
        'ap_grade'       => '/^araling\s+panlipunan\b/i',
        'tle_grade'      => '/^tle\b|^technology\s+and\s+livelihood/i',
        'mapeh_grade'    => '/^mapeh\b/i',
    ];
}

/**
 * A Continuing learner's grades as the school already has them: the Final Grade
 * (mean of the three term grades, rounded -- the same rule the grading module
 * uses) of each learning area, taken from the most recent school year that has
 * completed grades. Read-only lookup: nothing is written or changed.
 *
 * @return array {found, grades[field => final grade], missing[fields], school_year, grade_level}
 *   found=false when the system holds no completed Junior High grade for the learner
 *   (e.g. Senior High, or a record that was only imported) -- the form then stays manual.
 */
function getContinuingPreviousGrades($student_id)
{
    $areas = oePreviousGradeAreas();
    $none = ['found' => false, 'grades' => [], 'missing' => array_keys($areas), 'school_year' => '', 'grade_level' => ''];
    $student_id = (int) $student_id;
    if ($student_id <= 0) return $none;

    require_once __DIR__ . '/grading_helpers.php';
    if (!function_exists('computeSubjectFinalGrade') || !function_exists('gh_isValidGradeRow')) return $none;

    $rows = getRecords(
        "SELECT g.school_year_id, g.term, g.transmuted_grade, g.final_grade, g.remarks,
                sub.subject_code, sub.subject_name, sub.grade_level, sub.term AS sub_term, sub.terms_required,
                sy.sy_name
         FROM grades g
         JOIN subjects sub ON sub.subject_code = g.subject_code
         LEFT JOIN school_years sy ON sy.sy_id = g.school_year_id
         WHERE g.student_id = ?
           AND sub.grade_level IN ('Grade 7', 'Grade 8', 'Grade 9', 'Grade 10')
         ORDER BY g.school_year_id DESC",
        [$student_id]
    );
    if (!$rows) return $none;

    // school year -> subject code -> [subject info, term => grade]
    $by_year = [];
    foreach ($rows as $r) {
        if (!gh_isValidGradeRow($r)) continue;
        $sy = (int) $r['school_year_id'];
        if (!isset($by_year[$sy])) $by_year[$sy] = [];
        $code = $r['subject_code'];
        if (!isset($by_year[$sy][$code])) $by_year[$sy][$code] = ['sub' => $r, 'terms' => []];
        $by_year[$sy][$code]['terms'][$r['term']] = (float) $r['transmuted_grade'];
    }

    foreach ($by_year as $sy_id => $subjects) {   // newest school year first
        $grades = [];
        $grade_level = '';
        $sy_name = '';
        foreach ($subjects as $item) {
            $sub = $item['sub'];
            $field = null;
            foreach ($areas as $f => $rx) {
                if (preg_match($rx, (string) $sub['subject_name'])) { $field = $f; break; }
            }
            if ($field === null || isset($grades[$field])) continue;
            $valid_terms = !empty($sub['sub_term']) ? [$sub['sub_term']] : gh_requiredTermsFor($sub);
            $fg = computeSubjectFinalGrade($item['terms'], $valid_terms, $sy_id, $sub);
            if (empty($fg['is_complete']) || $fg['final_grade'] === null) continue;
            $grades[$field] = (float) $fg['final_grade'];
            $grade_level = $sub['grade_level'];
            $sy_name = (string) $sub['sy_name'];
        }
        if ($grades) {
            return [
                'found'       => true,
                'grades'      => $grades,
                'missing'     => array_values(array_diff(array_keys($areas), array_keys($grades))),
                'school_year' => $sy_name,
                'grade_level' => $grade_level,
            ];
        }
    }
    return $none;
}

/** Is this LRN already blocked by a pending application or a current-school-year enrollment? Returns an error message or ''. */
/**
 * One LRN = one application per school year: is there already an Approved
 * (not Recycle-Bin) online application with this LRN for this school year?
 * $exclude_id skips the application being looked at (used when approving).
 * @return array|null the clashing application (reference_no) or null
 */
function lrnApprovedApplication($lrn, $sy_id, $exclude_id = 0)
{
    $lrn = trim((string) $lrn);
    if ($lrn === '') return null;
    $sql = "SELECT application_id, reference_no FROM online_applications
            WHERE lrn = ? AND status = 'Approved' AND deleted_at IS NULL AND application_id <> ?";
    $params = [$lrn, (int) $exclude_id];
    if ($sy_id) {
        $sql .= ' AND school_year_id = ?';
        $params[] = (int) $sy_id;
    }
    return getRecord($sql . ' LIMIT 1', $params) ?: null;
}

function lrnBlockingMessage($lrn, $student_id, $sy_id)
{
    if ($student_id && $sy_id) {
        $already = getRecord(
            "SELECT enrollment_id FROM enrollments WHERE student_id = ? AND school_year_id = ? AND enrollment_status IN ('Pending','Approved')",
            [$student_id, $sy_id]
        );
        if ($already) return 'This learner is already enrolled (or has an enrollment waiting for approval) for the current school year. Please contact the registrar instead.';
    }
    $dupe_app = getRecord("SELECT reference_no FROM online_applications WHERE lrn = ? AND status IN ('Pending','Under Review','For Correction','Incomplete Requirements') AND deleted_at IS NULL", [$lrn]);
    if ($dupe_app) return 'You already have a pending application (Ref. ' . $dupe_app['reference_no'] . ').';
    $approved = lrnApprovedApplication($lrn, $sy_id);
    if ($approved) return 'This LRN already has an approved application (Ref. ' . $approved['reference_no'] . ') for the current school year. Please contact the registrar instead.';
    return '';
}

// ------------------------------------------------------------
// CONTINUING ELIGIBILITY
// "Continuing" is decided by the learner's own IISS enrollment history, never
// by what the applicant clicked:
//   - latest Approved / Graduated enrollment BEFORE the active school year
//   - that school year must be the one right before the active one (a gap
//     means Returning / Balik-Aral)
//   - next grade = last grade + 1 (Grade 10 -> Grade 11 is still Continuing),
//     or the same grade when the year result says "Not Promoted"
// Uses the existing students / enrollments / student_year_status rows only.
// ------------------------------------------------------------
const OE_MSG_CONTINUING_NOT_FOUND = 'No existing IISS learner record was found. Continuing enrollment is only available to students who previously studied at IISS. If this is your first enrollment at IISS, please select New Student or Transferee as applicable.';
const OE_MSG_USE_CONTINUING = 'An existing IISS learner record was found. Since you previously studied at IISS, please select Continuing Student.';

/** 'Grade 10' -> 10 (0 when it is not a grade label). */
function oeGradeNumber($grade_level)
{
    return preg_match('/^Grade\s+(\d{1,2})$/', trim((string) $grade_level), $m) ? (int) $m[1] : 0;
}

/** First year of a school year ('2025-2026' -> 2025); falls back to its start date; 0 when unknown. */
function oeSchoolYearStart($sy_name, $start_date = null)
{
    if (preg_match('/^(\d{4})\s*-\s*(\d{4})$/', trim((string) $sy_name), $m)) return (int) $m[1];
    if (!empty($start_date) && preg_match('/^(\d{4})-/', (string) $start_date, $m)) return (int) $m[1];
    return 0;
}

/**
 * Is this learner allowed to apply as Continuing for the school year $sy_id,
 * and for which grade level?
 *
 * @return array {ok, status, message, last_grade, last_school_year, expected_grade, retained}
 *   status: ok | no_history | gap | completed_shs | invalid_grade | current_year
 */
function getContinuingEligibility($student_id, $sy_id)
{
    $fail = function ($status, $message, $last_grade = '', $last_sy = '') {
        return ['ok' => false, 'status' => $status, 'message' => $message, 'last_grade' => $last_grade,
                'last_school_year' => $last_sy, 'expected_grade' => '', 'retained' => false];
    };
    $no_history = 'We could not find a completed IISS enrollment for this learner, so this cannot be a Continuing enrollment. Please choose Returning (Balik-Aral) or contact the registrar.';

    $student_id = (int) $student_id;
    $student = $student_id > 0 ? getRecord("SELECT student_id, grade_level, school_year_id, enrollment_status FROM students WHERE student_id = ?", [$student_id]) : null;
    if (!$student) return $fail('no_history', OE_MSG_CONTINUING_NOT_FOUND);

    $active = $sy_id ? getRecord("SELECT sy_id, sy_name, start_date FROM school_years WHERE sy_id = ?", [(int) $sy_id]) : null;
    $active_start = $active ? oeSchoolYearStart($active['sy_name'], $active['start_date']) : 0;

    // Latest Approved / Graduated enrollment that happened BEFORE the school year being applied for.
    $last = null;
    $rows = getRecords(
        "SELECT e.enrollment_id, e.school_year_id, e.grade_level, sy.sy_name, sy.start_date
         FROM enrollments e
         LEFT JOIN school_years sy ON sy.sy_id = e.school_year_id
         WHERE e.student_id = ? AND e.enrollment_status IN ('Approved','Graduated')",
        [$student_id]
    );
    foreach ($rows as $r) {
        $start = oeSchoolYearStart($r['sy_name'], $r['start_date']);
        if ($active_start > 0 && $start >= $active_start) continue;              // this / a later school year
        if ($sy_id && (int) $r['school_year_id'] === (int) $sy_id) continue;
        if ($last === null || $start > $last['start'] || ($start === $last['start'] && (int) $r['enrollment_id'] > (int) $last['enrollment_id'])) {
            $last = ['start' => $start, 'enrollment_id' => $r['enrollment_id'], 'school_year_id' => (int) $r['school_year_id'],
                     'grade_level' => (string) $r['grade_level'], 'sy_name' => (string) ($r['sy_name'] ?? '')];
        }
    }

    if ($last === null) {
        // Learner records created without an enrollment row (imported): the student record itself is the history.
        $enr = (string) $student['enrollment_status'];
        if ($enr === 'Transferred') {
            return $fail('no_history', $no_history);
        }
        $sy_row = !empty($student['school_year_id']) ? getRecord("SELECT sy_name, start_date FROM school_years WHERE sy_id = ?", [(int) $student['school_year_id']]) : null;
        $start = $sy_row ? oeSchoolYearStart($sy_row['sy_name'], $sy_row['start_date']) : 0;
        if ($active_start > 0 && $start >= $active_start) {
            return $fail('current_year', 'This learner is already registered for the current school year. Please contact the registrar instead.');
        }
        $last = ['start' => $start, 'enrollment_id' => 0, 'school_year_id' => (int) ($student['school_year_id'] ?? 0),
                 'grade_level' => (string) $student['grade_level'], 'sy_name' => $sy_row ? (string) $sy_row['sy_name'] : ''];
    }

    $last_grade = $last['grade_level'];
    $n = oeGradeNumber($last_grade);
    if ($n < 7 || $n > 12) {
        return $fail('invalid_grade', 'The grade level on your IISS record could not be used for Continuing enrollment. Please contact the registrar.', $last_grade, $last['sy_name']);
    }

    // An enrollment gap (one or more school years skipped) is Returning / Balik-Aral, not Continuing.
    if ($active_start > 0 && $last['start'] > 0 && ($active_start - $last['start']) > 1) {
        return $fail('gap',
            'Our records show you last studied at IISS in SY ' . $last['sy_name'] . ' (' . $last_grade . '). Because school years were skipped, this is not a Continuing enrollment. Please select Returning (Balik-Aral) instead.',
            $last_grade, $last['sy_name']);
    }

    // Year result (Completed / Promoted or Not Promoted) when the school has finalized one.
    $retained = false;
    if ($last['school_year_id'] > 0) {
        $ys = getRecord("SELECT status FROM student_year_status WHERE student_id = ? AND school_year_id = ? AND grade_level = ? LIMIT 1",
            [$student_id, $last['school_year_id'], $last_grade]);
        $retained = $ys && stripos((string) $ys['status'], 'not promoted') !== false;
    }
    $next_n = $retained ? $n : $n + 1;
    if ($next_n > 12) {
        return $fail('completed_shs', 'Our records show you already completed Grade 12 at IISS, so there is no next grade level to enroll in. Please contact the registrar.', $last_grade, $last['sy_name']);
    }

    return ['ok' => true, 'status' => 'ok', 'message' => '', 'last_grade' => $last_grade, 'last_school_year' => $last['sy_name'],
            'expected_grade' => 'Grade ' . $next_n, 'retained' => $retained];
}

/**
 * Why a Continuing application for $grade_level is refused, or '' when it is
 * exactly the grade the learner's verified IISS record allows.
 */
function continuingGradeError(array $elig, $grade_level)
{
    if (empty($elig['ok'])) return $elig['message'];
    if ($grade_level === $elig['expected_grade']) return '';
    return 'Based on your verified IISS record (' . $elig['last_grade'] . ($elig['retained'] ? ', not promoted' : ', completed') . '), Continuing students can only enroll in ' . $elig['expected_grade'] . '. You selected ' . ($grade_level !== '' ? $grade_level : 'no grade level') . '.';
}

/**
 * New Student / Transferee entered an LRN that already has an IISS record.
 * A learner whose record shows normal progression is sent to Continuing; any
 * other existing record keeps the general "already registered" message.
 */
function existingLRNApplicantMessage($applicant_type, $student_id, $sy_id)
{
    if ($applicant_type === 'New Student') {
        $e = getContinuingEligibility($student_id, $sy_id);
        if (!empty($e['ok'])) return OE_MSG_USE_CONTINUING;
    }
    return 'This LRN is already registered. If you are a current or former student of the school, choose Continuing or Returning instead; otherwise please contact the registrar.';
}

/**
 * The lookup behind the enrollment wizard's LRN check.
 * @return array {ok, status, message, [profile], [found]}
 *   status: verified | new_record | available | invalid | duplicate | blocked | not_found | mismatch
 */
function lookupLearnerForApplication($applicant_type, $lrn, $birth_date, $sy_id)
{
    $lrn = trim((string) $lrn);
    if ($lrn === '') return ['ok' => false, 'status' => 'invalid', 'message' => 'LRN is required.'];
    if (!ctype_digit($lrn)) return ['ok' => false, 'status' => 'invalid', 'message' => 'LRN must contain numbers only.'];
    if (strlen($lrn) !== 12) return ['ok' => false, 'status' => 'invalid', 'message' => 'LRN must contain exactly 12 digits.'];

    $exists = getRecord("SELECT student_id FROM students WHERE lrn = ?", [$lrn]);

    // ---- New Student / Transferee: the LRN must not be on file yet ----
    if (!applicantTypeLooksUpLRN($applicant_type)) {
        if ($exists) {
            return ['ok' => false, 'status' => 'duplicate', 'message' => existingLRNApplicantMessage($applicant_type, $exists['student_id'], $sy_id)];
        }
        $blocked = lrnBlockingMessage($lrn, null, $sy_id);
        if ($blocked !== '') return ['ok' => false, 'status' => 'blocked', 'message' => $blocked];
        return ['ok' => true, 'status' => 'available', 'message' => 'LRN is available.'];
    }

    // ---- Continuing / Returning: date of birth confirms it is really the learner ----
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $birth_date)) {
        return ['ok' => false, 'status' => 'invalid', 'message' => 'Please enter the date of birth.'];
    }

    if (!$exists) {
        if ($applicant_type === 'Continuing') {
            return ['ok' => false, 'status' => 'not_found', 'message' => OE_MSG_CONTINUING_NOT_FOUND];
        }
        $blocked = lrnBlockingMessage($lrn, null, $sy_id);
        if ($blocked !== '') return ['ok' => false, 'status' => 'blocked', 'message' => $blocked];
        return ['ok' => true, 'status' => 'new_record', 'found' => false, 'message' => 'No previous record was found for this LRN. Please fill out the complete form below.'];
    }

    $profile = getLearnerProfileByLRN($lrn);
    if (!$profile || $profile['birth_date'] === '' || $profile['birth_date'] !== $birth_date) {
        return ['ok' => false, 'status' => 'mismatch', 'message' => 'The LRN and date of birth do not match our records. Please check them and try again, or contact the registrar.'];
    }

    $blocked = lrnBlockingMessage($lrn, $profile['student_id'], $sy_id);
    if ($blocked !== '') return ['ok' => false, 'status' => 'blocked', 'message' => $blocked];

    if ($applicant_type === 'Continuing') {
        // The learner's own IISS history decides the grade level -- not the applicant.
        $elig = getContinuingEligibility($profile['student_id'], $sy_id);
        if (empty($elig['ok'])) {
            return ['ok' => false, 'status' => 'not_continuing', 'message' => $elig['message']];
        }
        return ['ok' => true, 'status' => 'verified', 'found' => true, 'profile' => $profile,
                'continuing' => ['expected_grade' => $elig['expected_grade'], 'last_grade' => $elig['last_grade'],
                                 'last_school_year' => $elig['last_school_year'], 'retained' => $elig['retained']],
                'message' => 'Record found. Your last grade at IISS was ' . $elig['last_grade']
                    . ($elig['last_school_year'] !== '' ? ' (SY ' . $elig['last_school_year'] . ')' : '')
                    . ($elig['retained'] ? ' and you will repeat it' : '')
                    . ', so your grade level is ' . $elig['expected_grade'] . '. Your saved details are shown below — change only what is different.'];
    }

    return ['ok' => true, 'status' => 'verified', 'found' => true, 'profile' => $profile,
            'message' => 'Welcome back! We found your old record. Please review every detail and update whatever changed.'];
}

/** The decision besides Approved: a required document is missing (replaces Rejected / For Correction). */
const OE_INCOMPLETE = 'Incomplete Requirements';

/**
 * Online application statuses: Pending / Under Review while waiting, then
 * Approved or Incomplete Requirements. Older Rejected / For Correction rows
 * become Incomplete Requirements (once).
 */
function oeEnsureCorrectionStatus()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('online_app_status_v3')) return;
    executeQuery("ALTER TABLE online_applications MODIFY status ENUM('Pending','Under Review','For Correction','Incomplete Requirements','Approved','Rejected') DEFAULT 'Pending'");
    executeQuery("UPDATE online_applications SET status = 'Incomplete Requirements' WHERE status IN ('Rejected','For Correction')");
    schemaMarkReady('online_app_status_v3');
}

/** New Student and Transferee can attach Form 137, but it is OPTIONAL (it never affects the application status). */
function oeForm137Counts($applicant_type)
{
    return in_array($applicant_type, ['New Student', 'Transferee'], true);
}

/**
 * Requirements that are actually REQUIRED but still missing for this application.
 * Form 137 is optional, so it is never listed here. Form 138 / other required
 * documents are enforced when the application is submitted.
 */
function oeMissingRequirements($application_id, $applicant_type)
{
    return [];
}

/** Allowed Form 137 follow-up statuses (kept separate from the application / enrollment status). */
const OE_FORM137_STATUSES = ['To Follow', 'Received', 'Verified'];

/** Adds online_applications.form137_status once (NULL = not set by staff yet, see oeForm137Status()). */
function oeEnsureForm137Schema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('online_app_form137_status_v1')) return;
    try {
        if (!getRecord("SHOW COLUMNS FROM online_applications LIKE 'form137_status'")) {
            executeQuery("ALTER TABLE online_applications ADD COLUMN form137_status ENUM('To Follow','Received','Verified') NULL DEFAULT NULL COMMENT 'Form 137 follow-up (New Student / Transferee). Separate from status.'");
        }
        schemaMarkReady('online_app_form137_status_v1');
    } catch (\Throwable $e) {
        error_log('oeEnsureForm137Schema: ' . $e->getMessage());
    }
}

/**
 * Form 137 state for New Student / Transferee: 'To Follow', 'Received' or 'Verified'.
 * It is its own status, separate from the application / enrollment status: 'To Follow'
 * is NOT an Incomplete Requirements condition (the application can still be Approved and
 * the student is counted in the section), and changing it never touches the approval.
 * Until staff set it, it is worked out from the application: a Form 137 uploaded with the
 * application = 'Received', nothing uploaded = 'To Follow'.
 */
function oeForm137Status($application_id, $applicant_type)
{
    if (!oeForm137Counts($applicant_type)) return 'Not Applicable';
    try {
        oeEnsureForm137Schema();
        $row = getRecord("SELECT form137_status FROM online_applications WHERE application_id = ?", [(int) $application_id]);
        if ($row && in_array($row['form137_status'], OE_FORM137_STATUSES, true)) return $row['form137_status'];
    } catch (\Throwable $e) {
        error_log('oeForm137Status: ' . $e->getMessage());
    }
    $has = getRecord("SELECT 1 FROM application_documents WHERE application_id = ? AND doc_key = 'form137'", [(int) $application_id]);
    return $has ? 'Received' : 'To Follow';
}

/** Optional Form 137 not uploaded yet (New Student / Transferee). Display / optional-upload use only. */
function oeOptionalForm137Missing($application_id, $applicant_type)
{
    if (!oeForm137Counts($applicant_type)) return [];
    $has = getRecord("SELECT 1 FROM application_documents WHERE application_id = ? AND doc_key = 'form137'", [(int) $application_id]);
    return $has ? [] : ['Form 137 (Permanent Record)'];
}

/** Cookie that remembers a submitted application on this browser (30 days). */
const OE_SUBMITTED_COOKIE = 'oe_ref';

/** Remember the application this browser just submitted (session + cookie). */
function oeRememberSubmitted($ref)
{
    $_SESSION['oe_submitted_ref'] = $ref;
    setcookie(OE_SUBMITTED_COOKIE, $ref, [
        'expires' => time() + 30 * 86400, 'path' => defined('BASE_URL') ? BASE_URL : '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

/** Forget it (the applicant chose to start a new application). */
function oeForgetSubmitted()
{
    unset($_SESSION['oe_submitted_ref']);
    setcookie(OE_SUBMITTED_COOKIE, '', ['expires' => time() - 3600, 'path' => defined('BASE_URL') ? BASE_URL : '/']);
}

/** Reference number of the application submitted on this browser, if it still exists. */
function oeSubmittedRef()
{
    $ref = (string) ($_SESSION['oe_submitted_ref'] ?? ($_COOKIE[OE_SUBMITTED_COOKIE] ?? ''));
    if ($ref === '' || !preg_match('/^[A-Za-z0-9-]{4,20}$/', $ref)) return null;
    return getRecord("SELECT reference_no FROM online_applications WHERE reference_no = ?", [$ref]) ? $ref : null;
}

/**
 * Walk-in enrollments (table enrollments): "Rejected" is now "Incomplete
 * Requirements" -- a requirement is still missing, and the enrollment can
 * still be approved later. Older Rejected rows are converted once.
 */
function enEnsureIncompleteStatus()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('enrollment_incomplete_v1')) return;
    executeQuery("ALTER TABLE enrollments MODIFY enrollment_status ENUM('Pending','Approved','Rejected','Incomplete Requirements','Transferred','Graduated') DEFAULT 'Pending'");
    executeQuery("UPDATE enrollments SET enrollment_status = 'Incomplete Requirements' WHERE enrollment_status = 'Rejected'");
    schemaMarkReady('enrollment_incomplete_v1');
}

/**
 * Online applications Recycle Bin: deleted_at / deleted_by (soft delete, like
 * students). A deleted application is hidden from the inbox, the counts and the
 * duplicate checks; it can be restored or permanently deleted from
 * modules/enrollment/applications_deleted.php.
 */
function oeEnsureAppTrashSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (schemaIsReady('online_app_trash_v1')) return;
        if (!getRecord("SHOW COLUMNS FROM online_applications LIKE 'deleted_at'")) {
            executeQuery("ALTER TABLE online_applications ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL, ADD COLUMN deleted_by INT NULL DEFAULT NULL, ADD KEY idx_oa_deleted (deleted_at)");
        }
        schemaMarkReady('online_app_trash_v1');
    } catch (\Throwable $e) {
        error_log('oeEnsureAppTrashSchema: ' . $e->getMessage());
    }
}
oeEnsureAppTrashSchema();