<?php
// online_enrollment.php
// PUBLIC ONLINE STUDENT ENROLLMENT FORM (no login required).
// Different from walk-in enrollment (modules/enrollment/new.php):
//  - filled out by the student/parent themselves
//  - requires uploading the documents for their grade level
//  - creates an APPLICATION only; the registrar/admin reviews and approves it

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/enrollment_helpers_online.php';
require_once __DIR__ . '/includes/email_verify_helpers.php'; // real, existing email account check ("Couldn't find this account")
require_once __DIR__ . '/includes/enrollment_lrn_card.php';   // LRN on the uploaded Report Card must match the entered LRN

$errors = [];
$success_ref = null;
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
$grade_levels = getOnlineGradeLevels();
$old = $_POST;

// An application already submitted on this browser is remembered (session +
// 30-day cookie): opening / refreshing this page goes straight to its
// Enrollment Tracking page instead of the form. "?new=1" starts a new one.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['new'])) {
        oeForgetSubmitted();
    } elseif (($oe_mine = oeSubmittedRef()) !== null) {
        header('Location: enrollment_status.php?ref=' . urlencode($oe_mine));
        exit;
    }
}

// ---------------------------------------------------------------------------
// AJAX (Step 1/2): LRN check + learner lookup.
//   New Student / Transferee -> is this LRN still free?
//   Continuing / Returning   -> LRN + date of birth -> load the saved profile.
// The profile is only released when both match, and every successful lookup is
// remembered in the session so the final submit can be trusted (see below).
// ---------------------------------------------------------------------------
// The email is contact information only during Online Enrollment, so there is
// deliberately NO email_check endpoint here any more: nothing is verified
// against a mail server and an address already on file is never rejected.
// (The former oe_action=email_check handler was removed with the validation.)

// AJAX (Step 3): read the Report Card the applicant just chose (grade level and
// grades). The LRN on the card is NOT compared with Step 2 any more.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['oe_action'] ?? '') === 'report_card_lrn') {
    header('Content-Type: application/json; charset=utf-8');
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['status' => 'error', 'message' => 'Your session expired. Please refresh the page.']);
        exit;
    }
    $rc_now = time();
    $rc_tries = array_values(array_filter($_SESSION['oe_tries_card'] ?? [], function ($t) use ($rc_now) { return $t > $rc_now - 600; }));
    if (count($rc_tries) >= 20) {
        echo json_encode(['status' => 'error', 'message' => 'Too many checks. Please wait a few minutes and try again.']);
        exit;
    }
    $rc_tries[] = $rc_now;
    $_SESSION['oe_tries_card'] = $rc_tries;
    // report_card[] = the pages in order (Page 1 / Report Card, optional Page 2 and Page 3)
    $rc_paths = [];
    $rc_in = $_FILES['report_card'] ?? null;
    if ($rc_in && is_array($rc_in['name'] ?? null)) {
        foreach ($rc_in['name'] as $i => $n) {
            if (($rc_in['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($rc_in['size'][$i] ?? 0) > 5 * 1024 * 1024) continue;
            $rc_paths[] = $rc_in['tmp_name'][$i];
            if (count($rc_paths) >= 3) break;
        }
    }
    if (count($rc_paths) < 1) {
        echo json_encode(['status' => 'incomplete', 'message' => OE_CARD_MSG_INCOMPLETE]);
        exit;
    }
    @set_time_limit(120);
    $rc = oeReportCardCheck($rc_paths);
    $rc_d = $rc['data'] ?? [];
    echo json_encode([
        'status' => $rc['status'], 'message' => $rc['message'], 'detected' => $rc['detected'],
        // shown to the applicant so they can see what was read
        'card' => $rc['status'] === 'verified' ? [
            'name' => $rc_d['name'] ?? '', 'grade_level' => $rc_d['grade_level'] ?? '',
            'general_average' => $rc_d['general_average'] ?? null, 'remarks' => $rc_d['remarks'] ?? '',
            'subjects' => count($rc_d['subjects'] ?? []),
        ] : null,
        // Form 138 grade vs Step 1 grade (the page re-compares when the applicant changes the grade)
        'grade_check' => $rc['status'] === 'verified' ? oeGradeLevelCheck($rc_d, (string) ($_POST['grade_level'] ?? '')) : null,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['oe_action'] ?? '') === 'lrn_lookup') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $reply = function ($data) { echo json_encode($data); exit; };

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $reply(['ok' => false, 'status' => 'error', 'message' => 'Your session expired. Please reload the page and try again.']);
    }
    $lk_type = (string) ($_POST['application_type'] ?? '');
    $lk_lrn  = trim((string) ($_POST['lrn'] ?? ''));
    $lk_dob  = trim((string) ($_POST['birth_date'] ?? ''));
    if (!in_array($lk_type, getApplicantTypes(), true)) {
        $reply(['ok' => false, 'status' => 'invalid', 'message' => 'Please select your Applicant Type first.']);
    }

    // Guessing protection: limited attempts per 10 minutes for each kind of check.
    $lk_kind = applicantTypeLooksUpLRN($lk_type) ? 'oe_tries_lookup' : 'oe_tries_check';
    $lk_max  = applicantTypeLooksUpLRN($lk_type) ? 10 : 40;
    $lk_now  = time();
    $lk_tries = array_values(array_filter($_SESSION[$lk_kind] ?? [], function ($t) use ($lk_now) { return $t > $lk_now - 600; }));
    if (count($lk_tries) >= $lk_max) {
        $_SESSION[$lk_kind] = $lk_tries;
        $reply(['ok' => false, 'status' => 'throttled', 'message' => 'Too many attempts. Please wait a few minutes and try again.']);
    }
    $lk_tries[] = $lk_now;
    $_SESSION[$lk_kind] = $lk_tries;

    $res = lookupLearnerForApplication($lk_type, $lk_lrn, $lk_dob, $active_sy['sy_id'] ?? null);
    if (isset($_SESSION['oe_verified'][$lk_lrn])) unset($_SESSION['oe_verified'][$lk_lrn]);
    if (!empty($res['ok']) && $res['status'] === 'verified') {
        $_SESSION['oe_verified'][$lk_lrn] = ['student_id' => $res['profile']['student_id'], 'type' => $lk_type, 'time' => $lk_now];
        // The browser fills text boxes with plain text (the form is sanitised again on submit).
        foreach ($res['profile'] as $k => $v) {
            if (is_string($v)) $res['profile'][$k] = html_entity_decode($v, ENT_QUOTES, 'UTF-8');
        }
        unset($res['profile']['student_id'], $res['profile']['previous_grades']);
    }
    $reply($res);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // When the uploaded documents (plus the rest of the form) are bigger than
    // PHP's post_max_size, PHP empties $_POST and $_FILES before this script
    // even starts running â€” every field the applicant typed is just gone.
    // That used to fall through to the CSRF check below and show a
    // misleading "session expired" error, so applicants kept resubmitting
    // the exact same oversized photos and it kept "not working". Detect
    // that specific case and say what actually happened.
    $content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (empty($_POST) && empty($_FILES) && $content_length > 0) {
        $errors[] = 'Your uploaded documents were too large for the server to accept in one submission. Please make sure each file is under 5 MB (smaller photos/scans work best) and submit again.';
    } elseif (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please submit the form again.';
    } else {
        $grade_level = sanitize($_POST['grade_level'] ?? '');
        $strand = sanitize($_POST['strand'] ?? '');
        $application_type = sanitize($_POST['application_type'] ?? '');

        $lrn = sanitize(trim($_POST['lrn'] ?? ''));
        $first_name = sanitize($_POST['first_name'] ?? '');
        $last_name = sanitize($_POST['last_name'] ?? '');
        $middle_name = sanitize($_POST['middle_name'] ?? '');
        $suffix = sanitize($_POST['suffix'] ?? '');
        $birth_date = sanitize($_POST['birth_date'] ?? '');
        $birth_place = sanitize($_POST['birth_place'] ?? '');
        $gender = sanitize($_POST['gender'] ?? '');
        $religion = sanitize($_POST['religion'] ?? '');
        $nationality = sanitize($_POST['nationality'] ?? 'Filipino');
        $contact_number = sanitize($_POST['contact_number'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $address = sanitize($_POST['address'] ?? '');

        $father_name = sanitize($_POST['father_name'] ?? '');
        $mother_name = sanitize($_POST['mother_name'] ?? '');
        $guardian_name = sanitize($_POST['guardian_name'] ?? '');
        $guardian_relationship = sanitize($_POST['guardian_relationship'] ?? '');
        $guardian_contact = sanitize($_POST['guardian_contact'] ?? '');

        $last_school_name = sanitize($_POST['last_school_name'] ?? '');
        $last_school_address = sanitize($_POST['last_school_address'] ?? '');
        $last_school_year = sanitize($_POST['last_school_year'] ?? '');
        $last_grade_completed = sanitize($_POST['last_grade_completed'] ?? '');

        $english_grade = floatval($_POST['english_grade'] ?? 0);
        $math_grade = floatval($_POST['math_grade'] ?? 0);
        $science_grade = floatval($_POST['science_grade'] ?? 0);
        $filipino_grade = floatval($_POST['filipino_grade'] ?? 0);
        $ap_grade = floatval($_POST['ap_grade'] ?? 0);
        $tle_grade = floatval($_POST['tle_grade'] ?? 0);
        $mapeh_grade = floatval($_POST['mapeh_grade'] ?? 0);
        $average_grade = round(($english_grade + $math_grade + $science_grade + $filipino_grade + $ap_grade + $tle_grade + $mapeh_grade) / 7, 2);

        // --- reuse of an existing learner record (Continuing / Returning) ---
        // The wizard asked for the LRN + date of birth first. Trust that only if
        // this very session verified it (never the posted form on its own):
        //   Continuing : identity (name, sex, date of birth, LRN) comes from the saved
        //                record; only contact / address / family details can differ.
        //   Returning  : old record is reused, everything may be updated, but the
        //                learner must confirm they reviewed it.
        $existing_learner = null;
        $lrn_shape_ok = ($lrn !== '' && ctype_digit($lrn) && strlen($lrn) === 12);
        if ($lrn_shape_ok && applicantTypeLooksUpLRN($application_type)) {
            $existing_learner = getLearnerProfileByLRN($lrn);
            if ($existing_learner) {
                $ver = $_SESSION['oe_verified'][$lrn] ?? null;
                if (!$ver || (int) $ver['student_id'] !== (int) $existing_learner['student_id'] || $ver['type'] !== $application_type || (time() - (int) $ver['time']) > 7200) {
                    $errors[] = 'Please verify your LRN and date of birth in Step 2 first, so we can load your saved record.';
                } else {
                    $birth_date = $existing_learner['birth_date'];
                    if ($application_type === 'Continuing') {
                        // The grade level must be exactly the one the learner's IISS record allows
                        // (re-derived here from the database; the posted grade is never trusted).
                        $continuing_elig = getContinuingEligibility($existing_learner['student_id'], $active_sy['sy_id'] ?? null);
                        $continuing_err = continuingGradeError($continuing_elig, $grade_level);
                        if ($continuing_err !== '') $errors[] = $continuing_err;
                        $last_name = $existing_learner['last_name'];
                        $first_name = $existing_learner['first_name'];
                        $middle_name = $existing_learner['middle_name'];
                        $suffix = $existing_learner['suffix'];
                        $gender = $existing_learner['gender'];
                        // Grades come from the school's own records, never from what was typed
                        // (the boxes are read-only in the wizard; this makes it true on the server too).
                        // A learning area the system has no completed grade for stays as entered.
                        $auto_grades = $existing_learner['previous_grades']['grades'] ?? [];
                        if (!empty($auto_grades)) {
                            foreach (['english_grade', 'math_grade', 'science_grade', 'filipino_grade', 'ap_grade', 'tle_grade', 'mapeh_grade'] as $gf) {
                                if (isset($auto_grades[$gf])) $$gf = (float) $auto_grades[$gf];
                            }
                            $average_grade = round(($english_grade + $math_grade + $science_grade + $filipino_grade + $ap_grade + $tle_grade + $mapeh_grade) / 7, 2);
                        }
                    } elseif (empty($_POST['profile_reviewed'])) {
                        $errors[] = 'Please confirm that you reviewed your saved information and updated anything that changed.';
                    }
                }
            } elseif ($application_type === 'Continuing') {
                $errors[] = OE_MSG_CONTINUING_NOT_FOUND;
            }
        }

        // --- validation ---
        if (!in_array($grade_level, $grade_levels, true)) $errors[] = 'Please select the grade level you are applying for.';
        // Applicant Type must be one of the four, and allowed for the chosen grade
        // (New Student: Grade 7 / 11 only, Continuing: Grade 8 - 12, and only the grade its IISS record allows).
        if (!in_array($application_type, getApplicantTypes(), true)) {
            $errors[] = 'Please select your Applicant Type.';
        } elseif (in_array($grade_level, $grade_levels, true) && !isApplicantTypeAllowedForGrade($application_type, $grade_level)) {
            $errors[] = applicantTypeGradeMessage($application_type, $grade_level);
        }
        if (isSeniorHigh($grade_level) && !in_array($strand, ['GAS', 'ASSH'], true)) $errors[] = 'Strand (GAS or ASSH) is required for Grade 11 and Grade 12.';
        if (!isSeniorHigh($grade_level)) $strand = '';
        if ($first_name === '') $errors[] = 'First name is required.';
        if ($last_name === '') $errors[] = 'Last name is required.';
        if ($birth_date === '') $errors[] = 'Date of birth is required.';
        if (!in_array($gender, ['Male', 'Female'], true)) $errors[] = 'Please select your sex.';
        if ($contact_number === '') $errors[] = 'Contact number is required.';
        // The email is collected as CONTACT INFORMATION ONLY. It is never checked
        // against a mail server, never verified by a code/link, and never compared
        // with another learner's or school account -- a family that already has an
        // address on file must still be able to apply for another learner.
        // Only the shape of the address matters here; nothing is sent to it during
        // Steps 1-4 and no password is created before the Admin approves.
        if ($email === '' || !validateEmail($email)) {
            $errors[] = 'A valid email address is required for contact purposes.';
        }
        if ($address === '') $errors[] = 'Complete address is required.';
        if ($guardian_name === '') $errors[] = 'Parent/Guardian name is required.';
        if ($guardian_contact === '') $errors[] = 'Parent/Guardian contact number is required.';

        // --- previous-school details: only Returning / Transferee give them ---
        // (whatever a hidden field still held is discarded for New Student / Continuing)
        if (!applicantTypeNeedsPreviousSchool($application_type)) {
            $last_school_name = $last_school_address = $last_school_year = $last_grade_completed = '';
        } else {
            $is_transferee = ($application_type === 'Transferee');
            if ($last_school_name === '') $errors[] = ($is_transferee ? 'Previous school name' : 'Last school attended') . ' is required.';
            if ($is_transferee) {
                if ($last_school_address === '') $errors[] = 'Previous school address is required.';
            } else {
                $last_school_address = '';
            }
            if ($last_grade_completed === '') {
                $errors[] = 'Last grade level completed is required.';
            } elseif (!in_array($last_grade_completed, getLastGradeOptions($grade_level), true)) {
                $errors[] = 'Last grade level completed must be a grade level lower than ' . $grade_level . '.';
            }
            if ($last_school_year === '') {
                $errors[] = 'Last school year attended is required.';
            } elseif (!preg_match('/^(\d{4})-(\d{4})$/', $last_school_year, $ly) || intval($ly[2]) !== intval($ly[1]) + 1) {
                $errors[] = 'Last school year attended must look like 2025-2026.';
            }
        }

        // --- LRN is required (12-digit official Learner Reference Number) ---
        if ($lrn === '') {
            $errors[] = 'LRN is required.';
        } elseif (!ctype_digit($lrn)) {
            $errors[] = 'LRN must contain numbers only.';
        } elseif (strlen($lrn) !== 12) {
            $errors[] = 'LRN must contain exactly 12 digits.';
        }

        if (empty($_POST['certify'])) $errors[] = 'You must certify that the information given is true and correct.';
        if (empty($_POST['privacy_consent'])) $errors[] = 'Please read and agree to the Privacy Notice.';

        if ($lrn !== '' && ctype_digit($lrn) && strlen($lrn) === 12) {
            $dupe_student = getRecord("SELECT student_id FROM students WHERE lrn = ?", [$lrn]);
            if ($dupe_student) {
                if (in_array($application_type, ['Continuing', 'Returning'], true)) {
                    // Current / former students of the school already have an LRN on file -- that is
                    // expected. Only refuse when they are already enrolled (or waiting) this school year.
                    $already = !empty($active_sy['sy_id']) ? getRecord(
                        "SELECT enrollment_id FROM enrollments WHERE student_id = ? AND school_year_id = ? AND enrollment_status IN ('Pending','Approved')",
                        [$dupe_student['student_id'], $active_sy['sy_id']]
                    ) : null;
                    if ($already) $errors[] = 'This learner is already enrolled (or has an enrollment waiting for approval) for the current school year. Please contact the registrar instead.';
                } elseif ($application_type === 'New Student') {
                    // New Student = first enrollment at IISS. An LRN that already has an IISS record
                    // belongs to a Continuing (or Returning) learner.
                    $errors[] = existingLRNApplicantMessage('New Student', $dupe_student['student_id'], $active_sy['sy_id'] ?? null);
                } else {
                    $errors[] = 'This LRN is already registered. Please contact the registrar instead.';
                }
            }
            oeEnsureCorrectionStatus();
            $dupe_app = getRecord("SELECT reference_no FROM online_applications WHERE lrn = ? AND status IN ('Pending','Under Review','For Correction','Incomplete Requirements') AND deleted_at IS NULL", [$lrn]);
            if ($dupe_app) $errors[] = 'You already have a pending application (Ref. ' . $dupe_app['reference_no'] . ').';
            $approved_app = lrnApprovedApplication($lrn, $active_sy['sy_id'] ?? null);
            if ($approved_app) $errors[] = 'This LRN already has an approved application (Ref. ' . $approved_app['reference_no'] . ') for the current school year. Please contact the registrar instead.';
        }

        // --- documents ---
        $required_docs = empty($errors) ? getRequiredDocuments($grade_level, $application_type) : [];
        $card_data = null; // what was read from the verified Report Card
        $uploads = [];
        foreach ($required_docs as $doc) {
            $field = 'doc_' . $doc['key'];
            $file = $_FILES[$field] ?? null;
            $has_file = $file && isset($file['error']) && $file['error'] !== UPLOAD_ERR_NO_FILE;

            if ($doc['key'] === OE_LRN_CARD_DOC_KEY) {
                // Only Page 1 is required up front; Page 2 / Page 3 are optional (the content check below asks for another page if info is missing).
                $card_paths = [];
                $card_missing = [];
                foreach (oeReportCardPages() as $suffix => $pg) {
                    $pf = $_FILES[$field . $suffix] ?? null;
                    $pf_has = $pf && isset($pf['error']) && $pf['error'] !== UPLOAD_ERR_NO_FILE;
                    if (!$pf_has) {
                        if ($pg['required']) $card_missing[] = $pg['label'];
                        continue;
                    }
                    $uploads[] = ['doc' => ['key' => $doc['key'] . $suffix, 'label' => $doc['label'] . ' â€” ' . $pg['label']] + $doc, 'file' => $pf];
                    if (($pf['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) $card_paths[] = $pf['tmp_name'];
                }
                if ($card_missing) {
                    $errors[] = $doc['label'] . ': please upload ' . implode(' and ', $card_missing) . '.';
                    continue;
                }
                // All pages read together: the grades / General Average must be found (the LRN on the card is no longer compared).
                $rc = oeReportCardCheck($card_paths);
                if ($rc['status'] !== 'verified') {
                    $errors[] = $rc['message'];
                } else {
                    $card_data = $rc['data'];
                    $grade_check = oeGradeLevelCheck($card_data, $grade_level);
                    if ($grade_check['status'] === OE_GC_MISMATCH) {
                        if ($application_type === 'Continuing' && !empty($continuing_elig['ok'])) {
                            // The grade is fixed by the IISS record, so the card is what conflicts.
                            $errors[] = 'Step 1 â€“ Grade Level Information: Grade Level Does Not Match. Your uploaded Form 138 shows ' . $grade_check['card_grade'] . ($grade_check['retained'] ? ' (Retained)' : '') . ', but your IISS record says you are enrolling in ' . $continuing_elig['expected_grade'] . '. Please upload the correct Form 138 or contact the registrar. Your grade level was not changed.';
                        } else {
                            $errors[] = 'Grade level does not match the uploaded Report Card. ' . $grade_check['message'];
                        }
                        $grade_mismatch_error = true;
                    }
                    // Returning / Transferee type their own "last grade completed": it must be the grade on the Form 138.
                    if (applicantTypeNeedsPreviousSchool($application_type) && $last_grade_completed !== ''
                        && $grade_check['card_grade'] !== '' && $last_grade_completed !== $grade_check['card_grade']) {
                        $errors[] = 'Last grade completed does not match the uploaded Report Card. Your Form 138 shows ' . $grade_check['card_grade'] . ($grade_check['retained'] ? ' (Retained)' : ' completed') . ', but you selected ' . $last_grade_completed . '. Please change "Last Grade Level Completed" to ' . $grade_check['card_grade'] . '.';
                        $last_grade_mismatch_error = true;
                    }
                }
                continue;
            }

            if (!$has_file) {
                if ($doc['required']) $errors[] = $doc['label'] . ' is required for ' . $application_type . ' applicants (' . $grade_level . ').';
                continue;
            }
            $uploads[] = ['doc' => $doc, 'file' => $file];
        }

        if (empty($errors)) {
            $reference_no = generateApplicationReference();

            $saved = [];
            foreach ($uploads as $u) {
                $res = saveApplicationFile($u['file'], $reference_no, $u['doc']['key']);
                if (!$res['success']) {
                    $errors[] = $u['doc']['label'] . ': ' . $res['message'];
                } else {
                    $saved[] = ['doc' => $u['doc'], 'res' => $res];
                }
            }

            if (empty($errors)) {
                $application_id = insertRecord(
                    "INSERT INTO online_applications
                     (reference_no, application_type, grade_level, strand, school_year_id,
                      lrn, first_name, last_name, middle_name, suffix, birth_date, birth_place, gender,
                      religion, nationality, contact_number, email, address,
                      father_name, mother_name, guardian_name, guardian_relationship, guardian_contact,
                      last_school_name, last_school_address, last_school_year, last_grade_completed,
                      english_grade, math_grade, science_grade, filipino_grade, ap_grade, tle_grade, mapeh_grade, average_grade,
                      status, submitted_ip)
                     VALUES (?,?,?,?,?, ?,?,?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?,?, ?,?,?,?,?,?,?,?, 'Pending', ?)",
                    [
                        $reference_no, $application_type, $grade_level, ($strand ?: null), ($active_sy['sy_id'] ?? null),
                        ($lrn ?: null), $first_name, $last_name, $middle_name, $suffix, $birth_date, $birth_place, $gender,
                        $religion, $nationality, $contact_number, $email, $address,
                        $father_name, $mother_name, $guardian_name, $guardian_relationship, $guardian_contact,
                        $last_school_name, $last_school_address, $last_school_year, $last_grade_completed,
                        $english_grade, $math_grade, $science_grade, $filipino_grade, $ap_grade, $tle_grade, $mapeh_grade, $average_grade,
                        $_SERVER['REMOTE_ADDR'] ?? null,
                    ]
                );

                if ($application_id && !empty($grade_check)) {
                    oeEnsureGradeCheckSchema();
                    executeQuery("UPDATE online_applications SET grade_level_check = ?, grade_level_check_note = ? WHERE application_id = ?",
                        [$grade_check['status'], mb_substr($grade_check['message'], 0, 255), (int) $application_id]);
                }
                if ($application_id && $card_data !== null) {
                    // The verified Report Card's General Average is the one used for section assignment.
                    oeEnsureReportCardSchema();
                    executeQuery(
                        "UPDATE online_applications SET report_card_average = ?, report_card_data = ?, average_grade = ? WHERE application_id = ?",
                        [$card_data['general_average'], json_encode($card_data, JSON_UNESCAPED_UNICODE), $card_data['general_average'], (int) $application_id]
                    );
                }
                if ($application_id) {
                    foreach ($saved as $s) {
                        executeQuery(
                            "INSERT INTO application_documents (application_id, doc_key, doc_label, file_name, file_path, file_type, file_size)
                             VALUES (?,?,?,?,?,?,?)",
                            [$application_id, $s['doc']['key'], $s['doc']['label'], $s['res']['name'], $s['res']['path'], $s['res']['type'], $s['res']['size']]
                        );
                    }

                    notifyStaffNewApplication([
                        'application_id' => $application_id,
                        'reference_no' => $reference_no,
                        'first_name' => $first_name,
                        'last_name' => $last_name,
                        'grade_level' => $grade_level,
                        'strand' => $strand,
                    ]);

                    logActivity(null, 'online_enrollment', 'Online application submitted', "Ref: $reference_no â€” $last_name, $first_name ($grade_level)");

                    $success_ref = $reference_no;
                    $old = [];
                    if ($lrn !== '') unset($_SESSION['oe_verified'][$lrn]);
                    // Saved: go to its tracking page (a refresh there never re-submits the form).
                    oeRememberSubmitted($reference_no);
                    header('Location: enrollment_status.php?ref=' . urlencode($reference_no) . '&submitted=1');
                    exit;
                } else {
                    $errors[] = 'We could not save your application. Please try again.';
                }
            }
        }
    }
}

$csrf_token = generateCSRFToken();
$school_name = getSchoolName();
$selected_grade = $old['grade_level'] ?? '';
function ov($k, $old, $d = '') { return htmlspecialchars($old[$k] ?? $d, ENT_QUOTES); }

// --- presentation-only helpers (no data/logic impact) ---
// Soft per-grade accent used only to tint the Step 1 grade cards.
function gradeAccentSlug($g) {
    $map = ['Grade 7' => 'g7', 'Grade 8' => 'g8', 'Grade 9' => 'g9', 'Grade 10' => 'g10', 'Grade 11' => 'g11', 'Grade 12' => 'g12'];
    return $map[$g] ?? 'g7';
}
function gradeIcon($g) {
    return isSeniorHigh($g) ? 'fa-graduation-cap' : 'fa-book-open-reader';
}
// Unique DOM id for a given grade's copy of a document requirement (the same
// doc key, e.g. "psa", appears in more than one grade's list).
function docFieldId($g, $key, $type = '') {
    return 'docfile_' . preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '', $g)) . ($type !== '' ? '_' . preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '', $type)) : '') . '_' . $key;
}
// Reads existing system_settings rows (already used elsewhere, e.g. modules/settings)
// for the Contact modal only. Read-only â€” no new settings are created here.
function getSchoolSetting($key) {
    $row = getRecord("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$key]);
    return $row ? trim($row['setting_value']) : '';
}
$school_address = getSchoolSetting('school_address');
$school_contact = getSchoolSetting('school_contact');
$school_email_addr = getSchoolSetting('school_email');
// If this was a POST that came back with server-side validation errors, the
// applicant was on the final Review & Submit step when they clicked Submit â€”
// send them back there (with all their entered values intact) instead of
// dumping them back on Step 1.
$initial_step = (!empty($errors) && !$success_ref && !empty($old)) ? (!empty($grade_mismatch_error) ? 1 : (!empty($last_grade_mismatch_error) ? 3 : 4)) : 1;

// After a server-side error the wizard is rebuilt from what was posted. For
// Continuing / Returning, hand the JS the (still session-verified) saved record
// so the locked / "Updated" state of every field can be restored.
$oe_restore = null;
if (!empty($errors) && !$success_ref && !empty($old['lrn']) && !empty($old['application_type']) && applicantTypeLooksUpLRN($old['application_type'])) {
    $r_lrn = trim((string) $old['lrn']);
    if (ctype_digit($r_lrn) && strlen($r_lrn) === 12) {
        $r_prof = getLearnerProfileByLRN($r_lrn);
        if ($r_prof) {
            $r_ver = $_SESSION['oe_verified'][$r_lrn] ?? null;
            if ($r_ver && (int) $r_ver['student_id'] === (int) $r_prof['student_id'] && $r_ver['type'] === $old['application_type']) {
                foreach ($r_prof as $k => $v) { if (is_string($v)) $r_prof[$k] = html_entity_decode($v, ENT_QUOTES, 'UTF-8'); }
                unset($r_prof['student_id'], $r_prof['previous_grades']);
                $oe_restore = ['status' => 'verified', 'profile' => $r_prof];
                if ($old['application_type'] === 'Continuing') {
                    $r_el = getContinuingEligibility($r_ver['student_id'], $active_sy['sy_id'] ?? null);
                    if (!empty($r_el['ok'])) {
                        $oe_restore['continuing'] = ['expected_grade' => $r_el['expected_grade'], 'last_grade' => $r_el['last_grade'],
                                                     'last_school_year' => $r_el['last_school_year'], 'retained' => $r_el['retained']];
                    } else {
                        $oe_restore = null;
                    }
                }
            }
        } elseif ($old['application_type'] === 'Returning') {
            $oe_restore = ['status' => 'new_record'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
    (function () {
        try {
            var t = localStorage.getItem('egradepro-theme');
            if (!t) { t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light'; }
            if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        } catch (e) {}
    })();
    </script>
    <title>Online Enrollment â€” <?php echo htmlspecialchars($school_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>static/css/theme.css" rel="stylesheet">
    <style>
        :root {
            --bg: #F3F7FC;
            --card: #FCFDFE;
            --muted: #F5F8FC;
            --primary: #2563EB;
            --primary-dark: #1E4F9A;
            --deep-blue: #174EA6;
            --ink: #172033;
            --text-muted: #667085;
            --border: #DCE5F0;
            --success: #059669;
            --success-soft: #ecfdf5;
            --danger: #dc2626;
            --danger-soft: #fef2f2;
        }
        *,*::before,*::after{box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{background:var(--bg);font-family:'Inter',system-ui,-apple-system,sans-serif;margin:0;color:var(--ink);position:relative}
        /* Faint, fixed page-wide tint so the wizard card reads as sitting on a
           soft school-blue backdrop rather than plain white â€” subtle by design,
           not a decorative graphic that competes with the form. */
        body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;
            background:
                radial-gradient(circle at 8% 8%, rgba(37,99,235,.06) 0, transparent 32%),
                radial-gradient(circle at 95% 38%, rgba(37,99,235,.05) 0, transparent 30%),
                radial-gradient(circle at 50% 100%, rgba(23,78,166,.05) 0, transparent 40%);
        }

        /* ===================== TOP NAV ===================== */
        .site-nav{background:#fff;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:40}
        .site-nav-inner{max-width:1200px;margin:0 auto;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px}
        .site-nav-brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--ink);min-width:0}
        .site-nav-brand-icon{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--primary),var(--deep-blue));color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
        .site-nav-brand-text{display:flex;align-items:center;gap:10px;min-width:0}
        .site-nav-brand-text strong{font-size:15px;font-weight:800;letter-spacing:.2px;color:var(--ink)}
        .site-nav-sep{width:1px;height:16px;background:var(--border);flex-shrink:0}
        .site-nav-tagline{font-size:13.5px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .site-nav-links{display:flex;align-items:center;gap:4px}
        .site-nav-links a{display:inline-flex;align-items:center;padding:8px 12px;border-radius:7px;font-size:15px;font-weight:600;color:var(--text-muted);text-decoration:none;background:transparent;border:none;cursor:pointer;transition:background .15s ease,color .15s ease}
        .site-nav-links a:hover{background:var(--muted);color:var(--primary)}
        .nav-hamburger{display:none;width:38px;height:38px;border-radius:8px;border:1px solid var(--border);background:#fff;color:var(--ink);align-items:center;justify-content:center;cursor:pointer;flex-shrink:0}
        .mobile-nav{display:none;flex-direction:column;padding:6px 12px 14px;border-top:1px solid var(--border);background:#fff}
        .mobile-nav.open{display:flex}
        .mobile-nav a{padding:11px 8px;font-size:15px;font-weight:600;color:var(--ink);text-decoration:none;border-bottom:1px solid var(--muted)}
        .mobile-nav a:last-child{border-bottom:none}

        /* ===================== HERO ===================== */
        .site-header{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;padding:26px 0 30px;text-align:center;position:relative;overflow:hidden}
        .site-header::before{content:'';position:absolute;inset:0;background-image:radial-gradient(circle at 12% 20%, rgba(255,255,255,.10) 0, transparent 42%), radial-gradient(circle at 88% 80%, rgba(255,255,255,.10) 0, transparent 46%);pointer-events:none}
        .site-header .header-inner{position:relative;z-index:1;max-width:1080px;margin:0 auto;padding:0 16px}
        .site-header img{width:48px;height:48px;margin-bottom:8px;border-radius:9px;background:#fff;padding:4px;object-fit:contain}
        .site-header h2{font-size:20px;font-weight:700;margin:0 0 3px;letter-spacing:.1px}
        .site-header p{margin:0;font-size:14.5px;opacity:.9}
        .header-wave{display:block;width:100%;height:26px;position:relative;z-index:1;margin-top:-1px}

        .wrap{max-width:1160px;margin:0 auto 8px;padding:22px 16px 0}
        .card-box{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:30px;margin-bottom:16px;box-shadow:0 10px 30px rgba(15,38,75,.08)}
        @media (min-width:768px){ .card-box{padding:34px 38px} }

        /* ===================== STEPPER ===================== */
        .stepper{display:flex;align-items:flex-start;justify-content:center;margin-bottom:10px;padding:6px 4px 0}
        .step-node{display:flex;flex-direction:column;align-items:center;gap:8px;flex:0 0 auto;min-width:66px}
        .stepper .dot{width:40px;height:40px;border-radius:50%;background:#e3e9f2;color:#98a2b3;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;flex-shrink:0;transition:background .2s ease,color .2s ease,box-shadow .2s ease;border:3px solid #fff;box-shadow:0 0 0 1px var(--border)}
        .stepper .dot.active{background:linear-gradient(135deg,var(--primary),var(--deep-blue));color:#fff;box-shadow:0 0 0 1px var(--primary),0 0 0 5px rgba(37,99,235,.14)}
        .stepper .dot.done{background:var(--success);color:#fff;font-size:0;box-shadow:0 0 0 1px var(--success)}
        .stepper .dot.done::after{content:"\f00c";font-family:"Font Awesome 6 Free";font-weight:900;font-size:15px}
        .step-label{font-size:13.5px;font-weight:600;color:var(--text-muted);text-align:center;max-width:96px;line-height:1.25}
        .dot.active + .step-label{color:var(--primary);font-weight:700}
        .dot.done + .step-label{color:var(--success)}
        .stepper .line{flex:1 1 32px;height:3px;background:var(--border);margin-top:19px;max-width:72px;border-radius:2px;transition:background .2s ease}
        .stepper .line.done{background:var(--success)}
        .mobile-step-status{display:none;text-align:center;font-size:14px;color:var(--text-muted);margin:0 0 18px}
        .mobile-step-status strong{display:block;font-size:15px;color:var(--ink);margin-top:2px}
        .mobile-progress-track{display:none;height:6px;border-radius:6px;background:var(--border);overflow:hidden;margin:10px auto 18px;max-width:340px}
        .mobile-progress-fill{height:100%;width:20%;border-radius:6px;background:linear-gradient(90deg,var(--primary),var(--deep-blue));transition:width .25s ease}

        /* Wizard steps */
        .wizard-step{display:none}
        .wizard-step.active{display:block;animation:stepFadeIn .28s ease both}
        @keyframes stepFadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
        @media (prefers-reduced-motion: reduce){
            .wizard-step.active{animation:none}
            html{scroll-behavior:auto}
        }

        /* Step heading */
        .step-heading{display:flex;align-items:flex-start;gap:14px;margin-bottom:22px;padding-bottom:16px;border-bottom:1px solid var(--border)}
        .step-heading .num{width:36px;height:36px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;flex-shrink:0}
        .step-heading h5{margin:0;font-weight:700;font-size:17px;color:var(--ink)}
        .step-heading small{color:var(--text-muted);font-size:14.5px}

        /* Step 3 â€” one card, subtle subsections instead of boxed groups */
        .subsection{padding:18px 0}
        .subsection + .subsection{border-top:1px solid var(--border)}
        .subsection:first-child{padding-top:2px}
        .sub-heading{font-weight:700;font-size:14px;color:var(--primary);margin:0 0 14px;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:6px}

        .avg-field{background:#eef4ff;border:1px solid #c9dbfb;border-radius:8px}
        .avg-field input{background:transparent;border:none;font-weight:800;color:var(--primary);height:40px}
        .avg-field input:focus{box-shadow:none}

        /* Grade picker */
        .grade-grid{display:grid;grid-template-columns:1fr;gap:12px;border-radius:14px;transition:box-shadow .15s ease}
        .grade-grid.is-invalid{box-shadow:0 0 0 2px var(--danger)}
        @media (min-width:480px){ .grade-grid{grid-template-columns:repeat(2,1fr)} }
        @media (min-width:768px){ .grade-grid{grid-template-columns:repeat(3,1fr)} }
        @media (min-width:992px){ .grade-grid{grid-template-columns:repeat(4,1fr)} }

        .grade-option{margin:0;cursor:pointer;display:block}
        .grade-option input{position:absolute;opacity:0;width:0;height:0}
        .grade-card{display:flex;align-items:center;gap:12px;padding:14px 14px;border:1.5px solid var(--border);border-radius:12px;background:var(--muted);transition:border-color .15s ease,background .15s ease,transform .15s ease,box-shadow .15s ease;position:relative}
        .grade-card-icon{width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:var(--accent-soft,#eef2f7);color:var(--accent,#667085);font-size:15px}
        .grade-card-body{flex:1;min-width:0}
        .grade-card-title{display:block;font-weight:700;font-size:15px;color:var(--ink)}
        .grade-card-sub{display:block;font-size:13px;color:var(--text-muted);margin-top:1px}
        .grade-card-chevron{color:#c2cad6;font-size:14.5px}
        .grade-card-check{display:none;width:20px;height:20px;border-radius:50%;background:var(--accent,var(--primary));color:#fff;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}

        .grade-option:hover .grade-card{border-color:#b9c3d1;transform:translateY(-2px);box-shadow:0 4px 10px rgba(23,32,51,.07)}
        .grade-option input:checked + .grade-card{border-color:var(--accent,var(--primary));background:var(--accent-soft,#eef4ff);box-shadow:0 2px 8px rgba(23,32,51,.06)}
        .grade-option input:checked + .grade-card .grade-card-chevron{display:none}
        .grade-option input:checked + .grade-card .grade-card-check{display:flex}
        .grade-option input:focus-visible + .grade-card{outline:2px solid var(--primary);outline-offset:2px}

        .grade-accent-g7{--accent:#16a34a;--accent-soft:#eafbf1}
        .grade-accent-g8{--accent:#2563eb;--accent-soft:#eef4ff}
        .grade-accent-g9{--accent:#d97706;--accent-soft:#fff7ec}
        .grade-accent-g10{--accent:#7c3aed;--accent-soft:#f5f0fe}
        .grade-accent-g11{--accent:#b1504f;--accent-soft:#fbeeee}
        .grade-accent-g12{--accent:#0d9488;--accent-soft:#e9f9f7}

        /* Form */
        .form-label{font-weight:600;font-size:14.5px;color:var(--ink);margin-bottom:5px;display:block}
        /* Required fields are no longer marked with a red asterisk (validation
           still runs server- and client-side) â€” only genuinely optional fields
           are called out, with the muted "(Optional)" tag below. */
        .optional-tag{color:var(--text-muted);font-weight:500;font-size:13.5px;margin-left:4px}
        .form-control,.form-select{height:42px;border-radius:8px;font-size:15px;border:1px solid var(--border);padding:0 12px;background-color:var(--card);color:var(--ink);transition:border-color .15s ease,box-shadow .15s ease}
        textarea.form-control{height:auto;padding:10px 12px}
        /* Custom chevron: Bootstrap's own background-image gets wiped out the
           moment anything sets the `background` shorthand (as the old rule
           above did), which is why selects like Applicant Type looked like
           plain text fields. Painting our own arrow avoids relying on
           `background` ever staying untouched again, and â€” since it's a
           background image, not an element â€” it can never intercept clicks. */
        .form-select{
            -webkit-appearance:none;-moz-appearance:none;appearance:none;
            padding-right:38px;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill='none' stroke='%23667085' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' d='M4 6l4 4 4-4'/%3E%3C/svg%3E");
            background-repeat:no-repeat;
            background-position:right 12px center;
            background-size:13px 10px;
        }
        .form-select::-ms-expand{display:none}
        .form-select:disabled{background-color:var(--muted);opacity:.65}
        .form-control:focus,.form-select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.12);outline:none}
        .form-hint{font-size:13.5px;color:var(--text-muted);margin-top:4px}
        .is-invalid,.form-control.is-invalid,.form-select.is-invalid{border-color:var(--danger) !important;box-shadow:0 0 0 3px rgba(220,38,38,.08) !important}
        .field-error{font-size:13.5px;color:var(--danger);margin-top:4px;display:none;font-weight:600}
        .field-error.show{display:block}
        .field-error::before{content:"\f06a";font-family:"Font Awesome 6 Free";font-weight:900;font-size:12.5px;margin-right:4px}

        /* Inline "please fix the highlighted fields" banner per step (no
           browser alert() popups) */
        .step-validation-banner{display:none;align-items:center;gap:8px;background:var(--danger-soft);border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:11px 14px;font-size:14.5px;font-weight:600;margin-bottom:20px}
        .step-validation-banner.show{display:flex}

        /* Buttons */
        .btn-next,.btn-back,.btn-submit,.btn-doc{height:43px;padding:0 22px;border-radius:9px;font-weight:600;font-size:15px;display:inline-flex;align-items:center;justify-content:center;gap:7px;cursor:pointer;transition:background .15s ease,border-color .15s ease,transform .15s ease,box-shadow .15s ease}
        .btn-next{background:linear-gradient(135deg,var(--primary),var(--deep-blue));color:#fff;border:none;box-shadow:0 3px 10px rgba(37,99,235,.25)}
                .btn-next:hover{background:linear-gradient(135deg,#1d4ed8,var(--primary-dark));transform:translateY(-1px);box-shadow:0 6px 14px rgba(37,99,235,.3)}
        .btn-next:disabled,.btn-next:disabled:hover{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none;background:linear-gradient(135deg,var(--primary),var(--deep-blue))}
        .btn-back{background:#fff;color:var(--ink);border:1px solid var(--border)}
        .btn-back:hover{background:var(--muted);transform:translateY(-1px)}
        .btn-submit{background:linear-gradient(135deg,var(--success),#047857);color:#fff;border:none;padding:0 30px;box-shadow:0 3px 10px rgba(5,150,105,.28)}
        .btn-submit:hover:not(:disabled){background:linear-gradient(135deg,#047857,#036c4c);transform:translateY(-1px);box-shadow:0 6px 16px rgba(5,150,105,.32)}
        .btn-submit:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none}
        .btn-submit.is-loading{pointer-events:none}
        .btn-submit .fa-spinner{display:none}
        .btn-submit.is-loading .fa-spinner{display:inline-block}
        .btn-submit.is-loading .fa-paper-plane{display:none}
        .btn-next:focus-visible,.btn-back:focus-visible,.btn-submit:focus-visible,.btn-doc:focus-visible{outline:2px solid var(--primary);outline-offset:2px}
        .btn-group{display:flex;justify-content:space-between;align-items:center;margin-top:26px;gap:12px;flex-wrap:wrap}

        /* Review */
        .review-grid-2{display:grid;grid-template-columns:1fr;gap:14px;align-items:start}
        @media (min-width:900px){ .review-grid-2{grid-template-columns:1fr 1fr} }
        .review-card{background:#fbfcfe;border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(15,38,75,.04)}
        .review-grid-2 .review-card{margin-bottom:0}
        .review-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid var(--border)}
        .review-header span{font-weight:700;font-size:15px;color:var(--ink);display:flex;align-items:center;gap:9px}
        .review-header span i{width:26px;height:26px;border-radius:7px;background:#eef4ff;color:var(--primary);display:inline-flex;align-items:center;justify-content:center;font-size:13.5px}
        .review-header button{font-size:13.5px;padding:5px 12px;border-radius:20px;border:1px solid #c9dbfb;background:#eef4ff;color:var(--primary);cursor:pointer;font-weight:700;transition:background .15s ease}
        .review-header button:hover{background:#dde9fc}
        .review-row{display:flex;justify-content:space-between;padding:6px 0;font-size:14.5px;gap:12px;border-bottom:1px dashed #eef1f6}
        .review-row:last-child{border-bottom:none}
        .review-row span:first-child{color:var(--text-muted);font-weight:600;flex-shrink:0}
        .review-row span:last-child{color:var(--ink);text-align:right;max-width:60%;font-weight:600}
        .certify-box{background:#eef4ff;border:1px solid #c9dbfb;border-radius:12px;padding:16px 18px;margin:22px 0 16px}

        /* Review â€” uploaded documents list */
        .doc-review-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px dashed #eef1f6}
        .doc-review-row:last-child{border-bottom:none}
        .doc-review-icon{width:34px;height:34px;border-radius:9px;background:var(--success-soft);color:var(--success);display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
        .doc-review-info{flex:1;min-width:0}
        .doc-review-label{font-size:14px;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:7px;flex-wrap:wrap}
        .doc-review-file{font-size:13.5px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}

        /* Documents â€” polished upload cards */
        .doc-card{border:1px solid var(--border);border-radius:12px;padding:16px 18px;margin-bottom:14px;background:var(--card);box-shadow:0 1px 3px rgba(15,38,75,.04);transition:border-color .15s ease,box-shadow .15s ease}
        .doc-card.required{border-color:#c7d9f7;background:#f9fbfe}
        .doc-card-head{display:flex;gap:12px;align-items:flex-start}
        .doc-card-icon{width:40px;height:40px;border-radius:10px;background:var(--muted);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
        .doc-card.has-file .doc-card-icon{background:var(--success-soft);color:var(--success)}
        .doc-card-title-wrap{flex:1;min-width:0}
        .doc-card h6{margin:0 0 2px;font-weight:700;font-size:15px;color:var(--ink)}
        .doc-card-title-wrap small{color:var(--text-muted);display:block;font-size:13.5px;line-height:1.4}
        .doc-formats{font-size:13px;color:var(--text-muted);margin:8px 0 12px;display:flex;align-items:center;gap:5px}
        .badge-req{background:#fdecec;color:#b42318;font-size:12px;padding:3px 10px;border-radius:20px;font-weight:700;white-space:nowrap;flex-shrink:0}
        .badge-opt{background:var(--muted);color:var(--text-muted);font-size:12px;padding:3px 10px;border-radius:20px;font-weight:700;border:1px solid var(--border);white-space:nowrap;flex-shrink:0}

        .doc-file-input{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}

        .doc-empty-state{display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:var(--muted);border:1.5px dashed var(--border);border-radius:9px;padding:12px 14px}
        .doc-empty-text{font-size:14px;color:var(--text-muted);font-weight:500}

        .doc-file-state{display:flex;align-items:center;gap:12px;background:var(--success-soft);border:1px solid #bbf0d8;border-radius:9px;padding:12px 14px;flex-wrap:wrap}
        .doc-file-icon{width:36px;height:36px;border-radius:9px;background:#fff;color:var(--success);display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;border:1px solid #bbf0d8}
        .doc-file-info{flex:1;min-width:120px}
        .doc-file-name{font-weight:700;font-size:14.5px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
        .doc-file-meta{font-size:13px;color:#0d7a52;font-weight:600;margin-top:1px}
        .doc-file-actions{display:flex;gap:8px;flex-wrap:wrap}

        .btn-doc{height:34px;padding:0 13px;font-size:14px;border-radius:7px;background:#fff;border:1px solid var(--border);color:var(--ink);font-weight:600}
        .btn-doc:hover{background:var(--muted)}
        .btn-doc-choose{background:var(--primary);color:#fff;border:none;box-shadow:0 2px 6px rgba(37,99,235,.25)}
        .btn-doc-choose:hover{background:var(--primary-dark)}
        .btn-doc-view{color:var(--primary);border-color:#c9dbfb}
        .btn-doc-remove{color:var(--danger);border-color:#f3c7c7}
        .btn-doc-remove:hover{background:var(--danger-soft)}
        .btn-doc:disabled{opacity:.5;cursor:not-allowed}

        .doc-card-error{font-size:13.5px;color:var(--danger);font-weight:600;margin-top:8px;display:none}
        .form-label.req::after{content:" *";color:var(--danger,#dc2626);font-weight:700}
        .doc-slot.is-page{border:1px dashed var(--border,#dbe3ee);border-radius:10px;padding:8px 10px;margin-top:8px}
        .doc-slot-label{display:flex;align-items:center;gap:8px;font-size:14.5px;font-weight:700;margin-bottom:6px;flex-wrap:wrap}
        .doc-slot-note{font-size:13px;color:var(--text-muted,#64748b);margin:-2px 0 6px}
        .doc-slot-note.is-required{color:#B91C1C;font-weight:700}
        .doc-slot-note.is-ok{color:#047857;font-weight:600}
        .doc-slot-done{display:none;color:#047857;font-size:13.5px;font-weight:700}
        .doc-slot.has-file .doc-slot-done{display:inline-flex;align-items:center;gap:4px}
        .doc-card-read{display:none;margin-top:6px;font-size:14px;color:#334155;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:7px 10px}
        .doc-card-read.show{display:block}
        .doc-lrn-status{display:none;margin-top:8px;font-size:14px;font-weight:700;border-radius:8px;padding:7px 10px}
        .doc-lrn-status.is-checking{display:block;background:#EFF6FF;color:#1D4ED8}
        .doc-lrn-status.is-verified{display:block;background:#ECFDF5;color:#047857}
        .doc-lrn-status.is-bad{display:block;background:#FEF2F2;color:#B91C1C}
        /* Form 138 grade level vs Step 1 grade level */
        .gc-box{display:none;margin-top:8px;border-radius:10px;padding:10px 12px;font-size:14.5px;line-height:1.45}
        .gc-box.show{display:block}
        .gc-box.ok{background:#ECFDF5;border:1px solid #A7F3D0;color:#047857;font-weight:600}
        .gc-box.manual{background:#FFFBEB;border:1px solid #FDE68A;color:#92400E}
        .gc-box.bad{background:#FEF2F2;border:1.5px solid #FCA5A5;color:#991B1B}
        .gc-step{display:inline-block;font-size:13px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;background:#DC2626;color:#fff;border-radius:6px;padding:2px 8px;margin-bottom:6px}
        .gc-title{display:block;font-size:15.5px;font-weight:800;margin-bottom:3px}
        .gc-btn{display:inline-flex;align-items:center;gap:6px;margin-top:8px;border:0;border-radius:8px;background:#DC2626;color:#fff;font-weight:700;font-size:14.5px;padding:7px 14px;cursor:pointer}
        .gc-btn:hover{background:#B91C1C}
        .grade-grid.gc-flag{outline:2px solid #DC2626;outline-offset:6px;border-radius:14px;animation:gcPulse 1.2s ease 2}
        .grade-option.gc-wrong .grade-card{border-color:#DC2626 !important;background:#FEF2F2 !important;box-shadow:0 0 0 3px rgba(220,38,38,.18) !important}
        .grade-option.gc-expected .grade-card{border-style:dashed;border-color:#16A34A}
        @keyframes gcPulse{0%,100%{outline-color:#DC2626}50%{outline-color:rgba(220,38,38,.2)}}
        html[data-theme="dark"] .gc-box.ok{background:rgba(5,150,105,.12);border-color:rgba(5,150,105,.4);color:#6ee7b7}
        html[data-theme="dark"] .gc-box.manual{background:rgba(217,119,6,.12);border-color:rgba(217,119,6,.4);color:#fcd34d}
        html[data-theme="dark"] .gc-box.bad{background:rgba(220,38,38,.14);border-color:rgba(248,113,113,.5);color:#fca5a5}
        html[data-theme="dark"] .grade-option.gc-wrong .grade-card{background:rgba(220,38,38,.14) !important}
        .doc-card-error.show{display:block}
        .doc-card-invalid{border-color:var(--danger) !important;background:var(--danger-soft) !important}
        .doc-card-invalid .doc-empty-state{border-color:var(--danger)}
        .doc-card-invalid .doc-slot.is-optional .doc-empty-state{border-color:var(--border,#cbd5e1)}

        @media (max-width:480px){
            .doc-file-actions{width:100%}
            .doc-file-actions .btn-doc{flex:1 1 auto}
        }

        /* Shared file preview modal (image / PDF) */
        .preview-modal-body{text-align:center;background:#0b1220;border-radius:0 0 12px 12px;padding:0}
        .preview-modal-img{max-width:100%;max-height:74vh;object-fit:contain;display:inline-block;margin:16px auto}
        .preview-modal-frame{width:100%;height:74vh;border:none;background:#fff}
        .preview-modal-meta{background:#fff;padding:10px 18px;font-size:14px;color:var(--text-muted);text-align:left;border-top:1px solid var(--border)}

        /* Success */
        .success-box{text-align:center;padding:48px 20px}
        .success-box .ref{font-size:28px;font-weight:800;letter-spacing:2px;color:var(--primary);margin:16px 0}
        .success-box i.fa-circle-check{font-size:60px;color:var(--success)}
        .success-box h3{font-weight:700;margin:12px 0 8px}
        .success-box p{color:var(--text-muted);margin:4px 0}

        /* Error / info alerts */
        .alert-error{background:var(--danger-soft);border:1px solid #fecaca;border-radius:10px;padding:16px;margin-bottom:16px;color:#991b1b}
        .alert-error strong{display:block;margin-bottom:6px}
        .alert-error ul{margin:0;padding-left:18px}
        /* ---- Applicant Type cards + type-driven flow ---- */
        .type-grid{display:grid;grid-template-columns:1fr;gap:12px;border-radius:14px;transition:box-shadow .15s ease}
        .type-grid.is-invalid{box-shadow:0 0 0 2px var(--danger)}
        @media (min-width:600px){ .type-grid{grid-template-columns:repeat(2,1fr)} }
        @media (min-width:992px){ .type-grid{grid-template-columns:repeat(4,1fr)} }
        .type-card{display:flex;align-items:flex-start;gap:12px;width:100%;text-align:left;padding:14px;border:1.5px solid var(--border);border-radius:12px;background:var(--muted);color:var(--ink);cursor:pointer;position:relative;font-family:inherit;transition:border-color .15s ease,background .15s ease,transform .15s ease,box-shadow .15s ease}
        .type-card:hover{border-color:#b9c3d1;transform:translateY(-2px);box-shadow:0 4px 10px rgba(23,32,51,.07)}
        .type-card:focus-visible{outline:2px solid var(--primary);outline-offset:2px}
        .type-card.is-selected{border-color:var(--primary);background:rgba(37,99,235,.08);box-shadow:0 2px 8px rgba(37,99,235,.14)}
        .type-card-icon{width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:rgba(37,99,235,.10);color:var(--primary);font-size:15px}
        .type-card.is-selected .type-card-icon{background:var(--primary);color:#fff}
        .type-card-body{flex:1;min-width:0;display:block}
        .type-card-title{display:block;font-weight:700;font-size:15.5px;color:var(--ink)}
        .type-card-sub{display:block;font-size:13.5px;color:var(--text-muted);margin-top:2px;line-height:1.35}
        .type-card-tag{display:inline-block;margin-top:8px;font-size:12px;font-weight:700;padding:2px 9px;border-radius:20px;background:rgba(37,99,235,.10);color:var(--primary)}
        .type-card-check{display:none;width:20px;height:20px;border-radius:50%;background:var(--primary);color:#fff;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}
        .type-card.is-selected .type-card-check{display:flex}
        #appTypeHint{margin-top:10px}
        .type-flow{margin-top:14px;border:1px solid rgba(37,99,235,.28);background:rgba(37,99,235,.06);border-radius:12px;padding:14px 16px}
        .type-flow-title{font-weight:700;font-size:14.5px;color:var(--primary);margin-bottom:10px;display:flex;align-items:center;gap:7px}
        .type-flow-list{margin:0;padding:0;list-style:none;display:grid;gap:7px}
        .type-flow-list li{display:flex;gap:10px;align-items:flex-start;font-size:14.5px;color:var(--ink);line-height:1.4}
        .type-flow-list li b{flex-shrink:0;width:22px;height:22px;border-radius:50%;background:var(--primary);color:#fff;font-size:13px;display:flex;align-items:center;justify-content:center}
        .grade-option.is-disabled{cursor:not-allowed;opacity:.42}
        .grade-option.is-disabled:hover .grade-card{transform:none;box-shadow:none;border-color:var(--border)}
        /* LRN-first panel (Continuing / Returning) */
        .verify-panel{border:1.5px solid rgba(37,99,235,.30);background:rgba(37,99,235,.05);border-radius:14px;padding:18px 18px 14px;margin-bottom:22px}
        .verify-head{display:flex;align-items:center;gap:12px;margin-bottom:14px}
        .verify-head strong{display:block;font-size:15px;color:var(--ink)}
        .verify-head span:not(.verify-icon){display:block;font-size:14px;color:var(--text-muted);margin-top:1px}
        .verify-icon{width:40px;height:40px;border-radius:11px;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
        .verify-btn{width:100%;padding:0 12px}
        .verify-result{margin-top:12px;display:flex;align-items:flex-start;gap:9px;border-radius:10px;padding:10px 13px;font-size:14.5px;font-weight:600;line-height:1.4}
        .verify-result.ok{background:rgba(5,150,105,.12);border:1px solid rgba(5,150,105,.35);color:#047857}
        .verify-result.err{background:var(--danger-soft);border:1px solid #fecaca;color:#991b1b}
        .verify-result.info{background:rgba(37,99,235,.09);border:1px solid rgba(37,99,235,.28);color:var(--primary-dark)}
        .verify-meta{display:inline-block;margin-left:6px;font-weight:600;font-size:13.5px;opacity:.85}
        html[data-theme="dark"] .verify-result.ok{color:#6ee7b7}
        html[data-theme="dark"] .verify-result.err{background:rgba(220,38,38,.14);border-color:rgba(248,113,113,.45);color:#fca5a5}
        html[data-theme="dark"] .verify-result.info{color:#93c5fd}
        .lrn-status.ok{color:var(--success);font-weight:600}
        .email-status.ok{color:var(--success);font-weight:600}
        .email-suggest{border:0;background:transparent;color:var(--primary,#1a73e8);font-weight:700;padding:0;text-decoration:underline;cursor:pointer}
        /* Saved-profile fields: locked / editable / updated */
        .form-control.oe-locked,.form-select.oe-locked{background-color:rgba(100,116,139,.12);color:var(--text-muted);cursor:not-allowed}
        .form-control.oe-changed,.form-select.oe-changed{border-color:#d97706;box-shadow:0 0 0 3px rgba(217,119,6,.13)}
        .oe-label-tools{display:inline-flex;align-items:center;gap:6px;margin-left:8px;vertical-align:middle;font-weight:400}
        .oe-lock-tag{font-size:12.5px;color:var(--text-muted)}
        .oe-edit-btn{border:1px solid rgba(37,99,235,.35);background:rgba(37,99,235,.08);color:var(--primary);font-size:12.5px;font-weight:700;border-radius:20px;padding:1px 9px;cursor:pointer;line-height:1.5}
        .oe-edit-btn:hover{background:rgba(37,99,235,.16)}
        .oe-changed-tag{font-size:12px;font-weight:700;color:#b45309;background:rgba(217,119,6,.16);border-radius:20px;padding:1px 8px}
        html[data-theme="dark"] .oe-changed-tag{color:#fbbf24}
        .review-confirm{margin:6px 0 18px;padding:14px 16px;border:1.5px solid rgba(217,119,6,.45);background:rgba(217,119,6,.07);border-radius:12px}
        .review-confirm label{display:flex;gap:10px;align-items:flex-start;font-size:15px;font-weight:600;color:var(--ink);cursor:pointer;margin:0}
        .review-confirm input{margin-top:3px;width:17px;height:17px;flex-shrink:0}
        .review-confirm.is-invalid{border-color:var(--danger);box-shadow:0 0 0 3px rgba(220,38,38,.10)}
        .review-changes{border-color:rgba(217,119,6,.45);background:rgba(217,119,6,.05)}
        .chg-row{display:flex;flex-wrap:wrap;align-items:center;gap:4px 10px;padding:7px 0;border-bottom:1px dashed var(--border);font-size:14.5px}
        .chg-row:last-child{border-bottom:0}
        .chg-row strong{min-width:150px;color:var(--ink)}
        .chg-row .from{color:var(--text-muted);text-decoration:line-through}
        .chg-row .to{color:var(--ink);font-weight:600}
        .chg-row i{font-size:12.5px;color:var(--text-muted)}
        .chg-none{font-size:14.5px;color:var(--success);font-weight:600;display:flex;gap:7px;align-items:center}
        .alert-info-box{background:#eef4ff;border:1px solid #c9dbfb;border-radius:8px;padding:12px 14px;font-size:14.5px;color:#1d4ed8;margin-bottom:16px}

        /* Checkbox */
        .form-check-label{font-size:14.5px;color:#374151;line-height:1.5}

        /* Footer */
        .site-footer{text-align:center;padding:22px 16px 30px;font-size:14px;color:var(--text-muted)}
        .site-footer .foot-school{font-weight:700;color:var(--ink);font-size:15px}
        .site-footer .foot-tagline{font-size:13.5px;color:var(--text-muted);margin:2px 0 10px}
        .site-footer .foot-copy{font-size:13px}

        /* Modals â€” light touch over Bootstrap defaults */
        .modal-content{border-radius:12px;border:1px solid var(--border)}
        .modal-header{border-bottom:1px solid var(--border)}
        .modal-title{font-weight:700;font-size:16px;color:var(--ink)}
        .modal-body ol{padding-left:20px;margin-bottom:10px}
        .modal-body li{margin-bottom:6px;font-size:15px}
        .modal-body p{font-size:15px;color:#374151}

        @media (max-width:900px){
            .site-nav-links{display:none}
            .nav-hamburger{display:flex}
        }
        @media (max-width:767px){
            .stepper{display:none}
            .mobile-step-status{display:block}
            .mobile-progress-track{display:block}
        }
        @media (max-width:480px){
            .site-nav-tagline{display:none}
            .card-box{padding:20px 16px}
        }

        /* ===================== DARK MODE ===================== */
        /* This wizard is a self-contained page with its own :root
           palette (separate from static/css/style.css), so its dark
           overrides live here rather than in the shared theme.css â€”
           same shared toggle button / localStorage key / theme.js,
           just page-local colors. Nothing about the upload cards,
           LRN validation, dropdown chevrons or step navigation is
           touched â€” only color values. */
        html[data-theme="dark"] {
            color-scheme: dark;
            --bg: #0B1220;
            --card: #121B2E;
            --muted: #16223A;
            --primary: #6D9BFF;
            --primary-dark: #4C7FE0;
            --deep-blue: #3D6BC7;
            --ink: #E4E9F2;
            --text-muted: #93A0B8;
            --border: #263352;
            --success: #34C48D;
            --success-soft: rgba(52,196,141,0.14);
            --danger: #E2726F;
            --danger-soft: rgba(226,114,111,0.14);
        }
        html[data-theme="dark"] body::before { opacity: 0.5; }
        html[data-theme="dark"] .site-nav,
        html[data-theme="dark"] .mobile-nav,
        html[data-theme="dark"] .nav-hamburger,
        html[data-theme="dark"] .btn-back,
        html[data-theme="dark"] .btn-doc,
        html[data-theme="dark"] .doc-file-icon,
        html[data-theme="dark"] .form-input-custom {
            background: var(--card);
        }
        html[data-theme="dark"] .theme-toggle-btn { border-color: var(--border); color: var(--ink); }
        html[data-theme="dark"] .theme-toggle-btn:hover { background: var(--muted); }
        html[data-theme="dark"] .site-header svg path { fill: var(--bg); }
        html[data-theme="dark"] .stepper .dot { background: #1B2A44; color: #6E7B96; border-color: var(--card); }
        html[data-theme="dark"] .avg-field,
        html[data-theme="dark"] .certify-box,
        html[data-theme="dark"] .alert-info-box,
        html[data-theme="dark"] .review-header span i {
            background: rgba(109,155,255,0.12);
            border-color: rgba(109,155,255,0.28);
        }
        html[data-theme="dark"] .alert-info-box { color: #BFD3FF; }
        html[data-theme="dark"] .review-card { background: var(--muted); }
        html[data-theme="dark"] .review-header button { background: rgba(109,155,255,0.14); border-color: rgba(109,155,255,0.3); color: var(--primary); }
        html[data-theme="dark"] .review-header button:hover { background: rgba(109,155,255,0.22); }
        html[data-theme="dark"] .review-row,
        html[data-theme="dark"] .doc-review-row { border-bottom-color: rgba(255,255,255,0.08); }
        html[data-theme="dark"] .doc-card.required { background: rgba(109,155,255,0.08); border-color: rgba(109,155,255,0.3); }
        html[data-theme="dark"] .doc-empty-state { background: var(--muted); }
        html[data-theme="dark"] .badge-req { background: rgba(226,114,111,0.18); color: #F3B3B1; }
        html[data-theme="dark"] .form-check-label { color: var(--text-muted); }
        html[data-theme="dark"] .modal-body p { color: var(--text-muted); }
        html[data-theme="dark"] .preview-modal-meta { background: var(--card); color: var(--text-muted); }
        /* The file-preview backdrop and preview-modal-frame stay their
           existing near-black / white panel look on purpose in both
           themes (a photo/PDF needs a neutral frame, not a theme
           tint) â€” and are never run through any invert filter. */
        @media (prefers-reduced-motion: reduce) {
            .theme-toggle-btn.theme-toggle-flip .theme-toggle-icon-sun,
            .theme-toggle-btn.theme-toggle-flip .theme-toggle-icon-moon { animation: none !important; }
        }
    </style>
    <?php include __DIR__ . '/includes/pwa_head.php'; ?>
</head>
<body>

    <nav class="site-nav" id="top">
        <div class="site-nav-inner">
            <a href="#top" class="site-nav-brand">
                <span class="site-nav-brand-icon"><i class="fas fa-graduation-cap"></i></span>
                <span class="site-nav-brand-text">
                    <strong>EGRADEPRO</strong>
                    <span class="site-nav-sep"></span>
                    <span class="site-nav-tagline">Enrolling Today. A Brighter Tomorrow.</span>
                </span>
            </a>
            <div class="site-nav-links">
                <a href="index.php">Home</a>
                <a href="#" data-bs-toggle="modal" data-bs-target="#helpModal">Help</a>
                <a href="#" data-bs-toggle="modal" data-bs-target="#aboutModal">About Us</a>
                <a href="#" data-bs-toggle="modal" data-bs-target="#contactModal">Contact</a>
            </div>
            <button type="button" class="theme-toggle-btn" id="themeToggleBtn" aria-label="Switch to dark mode" title="Switch to dark mode" aria-pressed="false">
                <i class="fas fa-sun theme-toggle-icon-sun" aria-hidden="true"></i>
                <i class="fas fa-moon theme-toggle-icon-moon" aria-hidden="true"></i>
            </button>
            <button type="button" class="nav-hamburger" id="navHamburger" aria-label="Open menu" aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        <div class="mobile-nav" id="mobileNav">
            <a href="index.php" id="mobileNavHome">Home</a>
            <a href="#" data-bs-toggle="modal" data-bs-target="#helpModal">Help</a>
            <a href="#" data-bs-toggle="modal" data-bs-target="#aboutModal">About Us</a>
            <a href="#" data-bs-toggle="modal" data-bs-target="#contactModal">Contact</a>
        </div>
    </nav>

    <div class="site-header">
        <div class="header-inner">
            <img src="<?php echo BASE_URL; ?>static/images/logo.png" alt="Logo" onerror="this.style.display='none'">
            <h2><?php echo htmlspecialchars($school_name); ?></h2>
            <p>Online Student Enrollment<?php echo $active_sy ? ' &mdash; S.Y. ' . htmlspecialchars($active_sy['sy_name']) : ''; ?></p>
        </div>
        <svg class="header-wave" viewBox="0 0 1200 26" preserveAspectRatio="none" aria-hidden="true">
            <path d="M0,14 C200,26 400,0 600,10 C800,20 1000,4 1200,14 L1200,26 L0,26 Z" fill="#F3F7FC"></path>
        </svg>
    </div>

    <div class="wrap">
        <?php if ($success_ref): ?>
            <div class="card-box success-box">
                <i class="fas fa-circle-check"></i>
                <h3>Application Submitted!</h3>
                <p>Your application was sent to the registrar for review. Save your reference number:</p>
                <div class="ref"><?php echo htmlspecialchars($success_ref); ?></div>
                <p>You will receive an email once your enrollment is approved or if a document needs to be re-submitted.</p>
                <div style="margin-top:20px">
                    <a href="enrollment_status.php?ref=<?php echo urlencode($success_ref); ?>" class="btn-next" style="text-decoration:none;display:inline-flex"><i class="fas fa-magnifying-glass me-1"></i> Track my application</a>
                    <a href="online_enrollment.php" class="btn-back" style="text-decoration:none;display:inline-flex;margin-left:8px">Submit another</a>
                </div>
            </div>
        <?php else: ?>

            <?php if ($errors): ?>
                <div class="alert-error">
                    <strong><i class="fas fa-triangle-exclamation me-1"></i> Please fix the following:</strong>
                    <ul>
                        <?php foreach ($errors as $e): ?>
                            <li><?php echo htmlspecialchars($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Progress (desktop/tablet) -->
            <div class="stepper" id="progressBar">
                <div class="step-node">
                    <div class="dot active" data-dot="1">1</div>
                    <div class="step-label">Applicant Type &amp; Grade</div>
                </div>
                <div class="line" data-line="1"></div>
                <div class="step-node">
                    <div class="dot" data-dot="2">2</div>
                    <div class="step-label">Personal Information</div>
                </div>
                <div class="line" data-line="2"></div>
                <div class="step-node">
                    <div class="dot" data-dot="3">3</div>
                    <div class="step-label">Additional Info &amp; Documents</div>
                </div>
                <div class="line" data-line="3"></div>
                <div class="step-node">
                    <div class="dot" data-dot="4">4</div>
                    <div class="step-label">Review &amp; Submit</div>
                </div>
            </div>
            <!-- Progress (mobile) -->
            <div class="mobile-step-status" id="mobileStepStatus">Step 1 of 4<strong>Applicant Type &amp; Grade</strong></div>
            <div class="mobile-progress-track" aria-hidden="true"><div class="mobile-progress-fill" id="mobileProgressFill"></div></div>

            <form id="oeForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                <!-- STEP 1: Applicant Type & Grade Level -->
                <div class="wizard-step<?php echo $initial_step === 1 ? ' active' : ''; ?>" id="step1">
                    <div class="card-box">
                        <div class="step-heading">
                            <div class="num">1</div>
                            <div>
                                <h5>Applicant Type &amp; Grade Level</h5>
                                <small>Tell us who is applying first &mdash; the next steps and the requirements change to match your choice.</small>
                            </div>
                        </div>

                        <div class="step-validation-banner" id="banner-step1"><i class="fas fa-triangle-exclamation"></i> <span id="banner1Text">Please select your Applicant Type to continue.</span></div>

                        <!-- Applicant Type: click a card. It decides the steps, the fields and the documents that follow. -->
                        <div id="appTypeWrap">
                            <label class="form-label req">Applicant Type</label>
                            <div class="type-grid" id="typeGrid" role="radiogroup" aria-label="Applicant Type">
                                <?php
                                $type_cards = [
                                    'New Student' => ['fa-user-plus',  'New Student',            'First time enrolling in this school.',            'Grade 7 &middot; Grade 11'],
                                    'Continuing'  => ['fa-user-check', 'Continuing',             'Currently enrolled here and progressing to the next grade level.', 'Grade 8 &ndash; 12'],
                                    'Returning'   => ['fa-rotate-left', 'Returning (Balik-Aral)', 'Stopped studying and is coming back.',           'Any grade'],
                                    'Transferee'  => ['fa-right-left', 'Transferee',             'Coming from another school.',                     'Grade 8 &ndash; 12'],
                                ];
                                foreach (getApplicantTypes() as $t):
                                    $tc = $type_cards[$t] ?? ['fa-user', $t, '', '']; ?>
                                    <button type="button" class="type-card" data-type="<?php echo htmlspecialchars($t, ENT_QUOTES); ?>" role="radio" aria-checked="false">
                                        <span class="type-card-icon"><i class="fas <?php echo $tc[0]; ?>"></i></span>
                                        <span class="type-card-body">
                                            <span class="type-card-title"><?php echo $tc[1]; ?></span>
                                            <span class="type-card-sub"><?php echo $tc[2]; ?></span>
                                            <span class="type-card-tag"><?php echo $tc[3]; ?></span>
                                        </span>
                                        <span class="type-card-check"><i class="fas fa-check"></i></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <!-- The real form field (the cards above fill it in). -->
                            <select id="f_application_type" name="application_type" class="form-select" hidden>
                                <option value="">-- Select applicant type --</option>
                                <?php foreach (getApplicantTypes() as $t): ?>
                                    <option value="<?php echo $t; ?>" <?php echo ($old['application_type'] ?? '') === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-hint" id="appTypeHint">Choose the option that describes you. Not sure? Continuing = enrolled here this year, Returning = stopped and coming back.</div>
                            <div class="field-error" id="err-application_type">Please select your Applicant Type.</div>

                            <!-- What happens next (rewritten every time the type changes) -->
                            <div class="type-flow" id="typeFlow" style="display:none" aria-live="polite">
                                <div class="type-flow-title"><i class="fas fa-route"></i> <span id="typeFlowTitle">Your steps</span></div>
                                <ol class="type-flow-list" id="typeFlowList"></ol>
                            </div>
                        </div>

                        <!-- Continuing: LRN + date of birth are verified HERE, before any grade level can be chosen
                             (the panel itself is moved in and out of this slot by applyStep2Layout()). -->
                        <div id="verifyHome1" style="margin-top:18px"></div>

                        <div style="margin-top:22px">
                            <label class="form-label req">Grade Level</label>
                            <div class="grade-grid">
                                <?php foreach ($grade_levels as $g): ?>
                                    <label class="grade-option grade-accent-<?php echo gradeAccentSlug($g); ?>">
                                        <input type="radio" name="grade_level" value="<?php echo $g; ?>" <?php echo $selected_grade === $g ? 'checked' : ''; ?>>
                                        <span class="grade-card">
                                            <span class="grade-card-icon"><i class="fas <?php echo gradeIcon($g); ?>"></i></span>
                                            <span class="grade-card-body">
                                                <span class="grade-card-title"><?php echo $g; ?></span>
                                                <span class="grade-card-sub"><?php echo isSeniorHigh($g) ? 'Senior High' : 'Junior High'; ?></span>
                                            </span>
                                            <span class="grade-card-check"><i class="fas fa-check"></i></span>
                                            <i class="fas fa-chevron-right grade-card-chevron"></i>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-hint" id="gradeHint"></div>
                            <div class="gc-box bad" id="gcStep1Box" role="alert" aria-live="assertive"></div>
                        </div>

                        <div id="strandWrap" style="display:none;margin-top:16px">
                            <label class="form-label req">Senior High Strand</label>
                            <select name="strand" class="form-select" id="strandSelect" style="max-width:320px">
                                <option value="">-- Select strand --</option>
                                <option value="GAS" <?php echo ($old['strand'] ?? '') === 'GAS' ? 'selected' : ''; ?>>GAS â€” General Academic Strand</option>
                                <option value="ASSH" <?php echo ($old['strand'] ?? '') === 'ASSH' ? 'selected' : ''; ?>>ASSH Cluster (Arts, Social Sciences, and Humanities)</option>
                            </select>
                            <div class="field-error" id="err-strand">Strand is required for Senior High.</div>
                        </div>

                        <div class="btn-group">
                            <div></div>
                            <button type="button" class="btn-next" data-next="1">Next <i class="fas fa-arrow-right ms-1"></i></button>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: Personal Information -->
                <div class="wizard-step<?php echo $initial_step === 2 ? ' active' : ''; ?>" id="step2">
                    <div class="card-box">
                        <div class="step-heading">
                            <div class="num">2</div>
                            <div>
                                <h5 id="step2Title">Personal Information</h5>
                                <small id="step2Sub">Provide your personal details as they appear on your official documents.</small>
                            </div>
                        </div>

                        <div class="step-validation-banner" id="banner-step2"><i class="fas fa-triangle-exclamation"></i> <span id="banner2Text">Please complete the required fields highlighted below.</span></div>

                        <!-- Continuing / Returning: LRN first. We look the learner up and load the saved profile. -->
                        <div id="verifyHome2">
                        <div class="verify-panel" id="verifyPanel" style="display:none">
                            <div class="verify-head">
                                <span class="verify-icon"><i class="fas fa-id-card"></i></span>
                                <div>
                                    <strong id="verifyTitle">Enter your LRN</strong>
                                    <span id="verifySub">We will look for your record so you do not have to retype everything.</span>
                                </div>
                            </div>
                            <div class="row g-3 align-items-start">
                                <div class="col-md-5">
                                    <label class="form-label req" for="v_lrn">Learner Reference Number (LRN)</label>
                                    <input type="text" id="v_lrn" class="form-control" inputmode="numeric" maxlength="12" autocomplete="off" placeholder="Enter 12-digit LRN">
                                    <div class="field-error" id="err-v_lrn">LRN is required.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label req" for="v_dob">Date of Birth</label>
                                    <input type="date" id="v_dob" class="form-control">
                                    <div class="field-error" id="err-v_dob">Date of birth is required.</div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <button type="button" class="btn-next verify-btn" id="btnVerify"><i class="fas fa-magnifying-glass"></i> <span id="btnVerifyText">Find my record</span></button>
                                </div>
                            </div>
                            <div class="form-hint">For your privacy, your saved details are only shown when the LRN and date of birth both match our records.</div>
                            <div class="verify-result" id="verifyResult" role="status" aria-live="polite" style="display:none"></div>
                        </div>
                        </div>

                        <div id="personalFields">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label req" for="f_last_name">Last Name</label>
                                <input type="text" id="f_last_name" name="last_name" class="form-control" autocomplete="family-name" value="<?php echo ov('last_name', $old); ?>">
                                <div class="field-error">Last name is required.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label req" for="f_first_name">First Name</label>
                                <input type="text" id="f_first_name" name="first_name" class="form-control" autocomplete="given-name" value="<?php echo ov('first_name', $old); ?>">
                                <div class="field-error">First name is required.</div>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="f_middle_name">Middle Name <span class="optional-tag">(Optional)</span></label>
                                <input type="text" id="f_middle_name" name="middle_name" class="form-control" autocomplete="additional-name" value="<?php echo ov('middle_name', $old); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="f_suffix">Suffix <span class="optional-tag">(Optional)</span></label>
                                <input type="text" id="f_suffix" name="suffix" class="form-control" placeholder="e.g., Jr., Sr., II, III" value="<?php echo ov('suffix', $old); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label req" for="f_lrn">Learner Reference Number (LRN)</label>
                                <input type="text" id="f_lrn" name="lrn" class="form-control" inputmode="numeric" maxlength="12" autocomplete="off" placeholder="Enter 12-digit LRN" value="<?php echo ov('lrn', $old); ?>">
                                <div class="form-hint" id="lrnHint">Enter the applicant's official 12-digit Learner Reference Number.</div>
                                <div class="field-error" id="err-lrn">LRN is required.</div>
                                <div class="form-hint lrn-status" id="lrnStatus" style="display:none"></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label req" for="f_birth_date">Date of Birth</label>
                                <input type="date" id="f_birth_date" name="birth_date" class="form-control" value="<?php echo ov('birth_date', $old); ?>">
                                <div class="field-error">Date of birth is required.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label req" for="f_gender">Sex</label>
                                <select id="f_gender" name="gender" class="form-select">
                                    <option value="">-- Select --</option>
                                    <option value="Male" <?php echo ($old['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo ($old['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                </select>
                                <div class="field-error">Please select your sex.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label req" for="f_contact_number">Mobile Number</label>
                                <input type="tel" id="f_contact_number" name="contact_number" class="form-control" inputmode="numeric" autocomplete="tel" placeholder="09XXXXXXXXX" value="<?php echo ov('contact_number', $old); ?>">
                                <div class="field-error">Mobile number is required.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label req" for="f_email">Email Address</label>
                                <input type="email" id="f_email" name="email" class="form-control" autocomplete="email" placeholder="example@email.com" value="<?php echo ov('email', $old); ?>">
                                <div class="field-error" id="err-email">A valid email address is required.</div>
                                <div class="form-hint">Contact information only. It does not have to be unique and is never verified here.</div>
                                <div class="form-hint" id="emailStatus" style="display:none"></div>
                            </div>
                        </div>
                        </div><!-- /#personalFields -->

                        <div class="btn-group">
                            <button type="button" class="btn-back" data-prev="2"><i class="fas fa-arrow-left me-1"></i> Back</button>
                            <button type="button" class="btn-next" data-next="2">Next <i class="fas fa-arrow-right ms-1"></i></button>
                        </div>
                    </div>
                </div>

                <!-- STEP 3: Additional Information -->
                <div class="wizard-step<?php echo $initial_step === 3 ? ' active' : ''; ?>" id="step3">
                    <div class="card-box">
                        <div class="step-heading">
                            <div class="num">3</div>
                            <div>
                                <h5 id="step3Title">Additional Information</h5>
                                <small id="step3Sub">Fill in what you know. This helps the school understand your background.</small>
                            </div>
                        </div>

                        <div class="step-validation-banner" id="banner-step3"><i class="fas fa-triangle-exclamation"></i> Please complete the required fields highlighted below.</div>

                        <div class="alert-info-box" id="step3TypeNote">
                            <i class="fas fa-circle-info me-1"></i>
                            Applying as <strong id="step3TypeName">&mdash;</strong> for <strong id="step3GradeName">&mdash;</strong>.
                            <span id="step3TypeText">Only the information needed for this applicant type is shown.</span>
                            <button type="button" data-edit="1" style="margin-left:6px;padding:0;border:0;background:none;color:var(--primary);font-weight:600;text-decoration:underline;cursor:pointer">Change</button>
                        </div>

                        <div class="subsection">
                            <div class="sub-heading"><i class="fas fa-user"></i> Learner Details</div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="f_birth_place">Place of Birth</label>
                                    <input type="text" id="f_birth_place" name="birth_place" class="form-control" placeholder="City/Municipality, Province" value="<?php echo ov('birth_place', $old); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="f_religion">Religion</label>
                                    <input type="text" id="f_religion" name="religion" class="form-control" value="<?php echo ov('religion', $old); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="f_nationality">Nationality</label>
                                    <input type="text" id="f_nationality" name="nationality" class="form-control" value="<?php echo ov('nationality', $old, 'Filipino'); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label req" for="f_address">Complete Address</label>
                                    <input type="text" id="f_address" name="address" class="form-control" placeholder="House No., Street, Barangay, City/Municipality, Province" value="<?php echo ov('address', $old); ?>">
                                    <div class="field-error">Complete address is required.</div>
                                </div>
                            </div>
                        </div>

                        <div class="subsection">
                            <div class="sub-heading"><i class="fas fa-users"></i> Parent / Guardian</div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="f_father_name">Father's Full Name</label>
                                    <input type="text" id="f_father_name" name="father_name" class="form-control" value="<?php echo ov('father_name', $old); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="f_mother_name">Mother's Maiden Name</label>
                                    <input type="text" id="f_mother_name" name="mother_name" class="form-control" value="<?php echo ov('mother_name', $old); ?>">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label req" for="f_guardian_name">Parent/Guardian Name</label>
                                    <input type="text" id="f_guardian_name" name="guardian_name" class="form-control" value="<?php echo ov('guardian_name', $old); ?>">
                                    <div class="field-error">Parent/Guardian name is required.</div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="f_guardian_relationship">Relationship</label>
                                    <input type="text" id="f_guardian_relationship" name="guardian_relationship" class="form-control" placeholder="e.g., Mother, Father, Aunt, Uncle, Guardian" value="<?php echo ov('guardian_relationship', $old); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label req" for="f_guardian_contact">Parent/Guardian Mobile No.</label>
                                    <input type="tel" id="f_guardian_contact" name="guardian_contact" class="form-control" inputmode="numeric" placeholder="09XXXXXXXXX" value="<?php echo ov('guardian_contact', $old); ?>">
                                    <div class="form-hint">The Parent Portal link is texted to this number once the enrollment is approved. Please check it.</div>
                                    <div class="field-error">Guardian contact number is required.</div>
                                </div>
                            </div>
                        </div>

                        <div class="subsection" id="prevSchoolSection" style="display:none">
                            <div class="sub-heading"><i class="fas fa-school"></i> <span id="prevSchoolHeading">Previous School</span></div>
                            <!-- Only for Returning / Transferee (shown and required by the Applicant Type chosen above). -->
                            <div class="row g-3 mb-3" id="prevSchoolRow" style="display:none">
                                <div class="col-md-6" data-types="Returning,Transferee">
                                    <label class="form-label req" for="f_last_school_name" id="lblLastSchoolName">Last School Attended</label>
                                    <input type="text" id="f_last_school_name" name="last_school_name" class="form-control" value="<?php echo ov('last_school_name', $old); ?>">
                                    <div class="field-error">This field is required.</div>
                                </div>
                                <div class="col-md-6" data-types="Transferee">
                                    <label class="form-label req" for="f_last_school_address">Previous School Address</label>
                                    <input type="text" id="f_last_school_address" name="last_school_address" class="form-control" value="<?php echo ov('last_school_address', $old); ?>">
                                    <div class="field-error">Previous school address is required.</div>
                                </div>
                                <div class="col-md-6" data-types="Returning,Transferee">
                                    <label class="form-label req" for="f_last_grade_completed">Last Grade Level Completed</label>
                                    <select id="f_last_grade_completed" name="last_grade_completed" class="form-select" data-old="<?php echo ov('last_grade_completed', $old); ?>">
                                        <option value="">-- Select --</option>
                                        <?php for ($gi = 6; $gi <= 11; $gi++): /* secondary school: Grade 7+ only; Grade 6 is kept (and shown by the script) only when applying to Grade 7 */ ?>
                                            <option value="Grade <?php echo $gi; ?>" <?php echo ($old['last_grade_completed'] ?? '') === 'Grade ' . $gi ? 'selected' : ''; ?>>Grade <?php echo $gi; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <div class="field-error" id="err-last_grade_completed">Last grade level completed is required.</div>
                                </div>
                                <div class="col-md-6" data-types="Returning,Transferee">
                                    <label class="form-label req" for="f_last_school_year">Last School Year Attended</label>
                                    <input type="text" id="f_last_school_year" name="last_school_year" class="form-control" placeholder="e.g., 2025-2026" maxlength="9" value="<?php echo ov('last_school_year', $old); ?>">
                                    <div class="field-error" id="err-last_school_year">Last school year attended is required.</div>
                                </div>
                            </div>
                        </div>

                        <!-- Upload Requirements (was Step 4). The Report Card (Form 138) replaces the typed-in grades. -->
                        <div class="subsection" id="docsSection">
                            <div class="sub-heading"><i class="fas fa-file-arrow-up"></i> <span>Upload Requirements</span></div>
                            <small id="docHint" style="display:block;color:var(--text-muted);margin:-6px 0 12px">Upload the documents required for your grade level and applicant type. JPG, PNG, WEBP or PDF &mdash; max 5 MB each.</small>
                        <div class="step-validation-banner" id="banner-stepdocs"><i class="fas fa-triangle-exclamation"></i> <span id="bannerDocsText">Please upload every document marked Required below.</span></div>

                        <div class="alert-info-box" id="docPlaceholder">
                            <i class="fas fa-circle-info me-1"></i>
                            Select your grade level and Applicant Type (Step 1) to see the documents you need to upload.
                        </div>
                        <?php foreach ($grade_levels as $g): foreach (getApplicantTypes() as $t):
                            $group_docs = getRequiredDocuments($g, $t);
                            if (empty($group_docs)) continue; // combination not allowed (e.g. New Student + Grade 8) ?>
                            <div class="doc-group" data-grade="<?php echo $g; ?>" data-type="<?php echo $t; ?>" style="display:none">
                                <div class="alert-info-box">
                                    <i class="fas fa-circle-info me-1"></i>
                                    Requirements for <strong><?php echo $t; ?></strong> applicants &mdash; <strong><?php echo $g; ?></strong> <?php echo isSeniorHigh($g) ? '(Senior High School)' : '(Junior High School)'; ?>.
                                </div>
                                <?php foreach ($group_docs as $doc): $fid = docFieldId($g, $doc['key'], $t); ?>
                                    <div class="doc-card <?php echo $doc['required'] ? 'required' : 'optional'; ?>" data-doc-key="<?php echo htmlspecialchars($doc['key']); ?>">
                                        <div class="doc-card-head">
                                            <div class="doc-card-icon"><i class="fas fa-file-lines"></i></div>
                                            <div class="doc-card-title-wrap">
                                                <h6><?php echo htmlspecialchars($doc['label']); ?></h6>
                                                <small><?php echo htmlspecialchars($doc['hint']); ?></small>
                                            </div>
                                            <span class="<?php echo $doc['required'] ? 'badge-req' : 'badge-opt'; ?>"><?php echo $doc['required'] ? 'Required' : 'Optional'; ?></span>
                                        </div>
                                        <div class="doc-formats"><i class="fas fa-circle-info"></i> JPG, PNG, WEBP or PDF &middot; Maximum 5 MB</div>

                                        <?php
                                            // The Report Card is 2-3 pages under ONE requirement; every other document is one file.
                                            $doc_slots = $doc['key'] === OE_LRN_CARD_DOC_KEY
                                                ? oeReportCardPages()
                                                : ['' => ['label' => '', 'required' => (bool) $doc['required']]];
                                        ?>
                                        <?php foreach ($doc_slots as $slot_suffix => $slot): $sfid = $fid . $slot_suffix; ?>
                                        <div class="doc-slot<?php echo $slot['label'] !== '' ? ' is-page' : ''; ?><?php echo !$slot['required'] ? ' is-optional' : ''; ?>">
                                            <?php if ($slot['label'] !== ''): ?>
                                                <div class="doc-slot-label"><?php echo htmlspecialchars($slot['label']); ?>
                                                    <span class="<?php echo $slot['required'] ? 'badge-req' : 'badge-opt'; ?>"><?php echo $slot['required'] ? 'Required' : 'Optional'; ?></span>
                                                    <span class="doc-slot-done"><i class="fas fa-circle-check"></i> Uploaded</span>
                                                </div>
                                                <?php if ($doc['key'] === OE_LRN_CARD_DOC_KEY && $slot_suffix === '_p2'): ?>
                                                    <div class="doc-slot-note">Optional â€” required only if some report card information is missing on Page 1.</div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <label class="visually-hidden" for="<?php echo $sfid; ?>"><?php echo htmlspecialchars($doc['label'] . ($slot['label'] !== '' ? ' ' . $slot['label'] : '')); ?> file upload</label>
                                            <input type="file" class="doc-file-input" id="<?php echo $sfid; ?>" name="doc_<?php echo $doc['key'] . $slot_suffix; ?>" accept=".jpg,.jpeg,.png,.webp,.pdf"
                                                   data-page-label="<?php echo htmlspecialchars($slot['label']); ?>" data-required="<?php echo $slot['required'] ? '1' : '0'; ?>" disabled>

                                            <div class="doc-upload-zone">
                                                <div class="doc-empty-state">
                                                    <button type="button" class="btn-doc btn-doc-choose" data-for="<?php echo $sfid; ?>"><i class="fas fa-upload"></i> Choose File</button>
                                                    <span class="doc-empty-text">No file selected</span>
                                                </div>
                                                <div class="doc-file-state" style="display:none">
                                                    <div class="doc-file-icon"><i class="fas fa-file"></i></div>
                                                    <div class="doc-file-info">
                                                        <div class="doc-file-name"></div>
                                                        <div class="doc-file-meta"></div>
                                                    </div>
                                                    <div class="doc-file-actions">
                                                        <button type="button" class="btn-doc btn-doc-view" data-for="<?php echo $sfid; ?>"><i class="fas fa-eye"></i> View</button>
                                                        <button type="button" class="btn-doc btn-doc-change" data-for="<?php echo $sfid; ?>"><i class="fas fa-rotate"></i> Replace</button>
                                                        <button type="button" class="btn-doc btn-doc-remove" data-for="<?php echo $sfid; ?>"><i class="fas fa-trash"></i> Remove</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php if ($doc['key'] === OE_LRN_CARD_DOC_KEY): ?>
                                            <div class="doc-lrn-status" aria-live="polite"></div>
                                            <div class="doc-card-read"></div>
                                        <?php endif; ?>
                                        <div class="doc-card-error"></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; endforeach; ?>
                        </div>

                        <!-- Returning (record found): the learner must confirm they reviewed / updated the saved details. -->
                        <div class="review-confirm" id="reviewConfirmBox" style="display:none">
                            <label for="profileReviewed">
                                <input type="checkbox" id="profileReviewed" name="profile_reviewed" value="1" disabled>
                                <span>I have reviewed all of my saved information in Steps 2 and 3 and updated everything that has changed.</span>
                            </label>
                            <div class="field-error" id="err-profile_reviewed">Please confirm that you reviewed your information.</div>
                        </div>

                        <div class="btn-group">
                            <button type="button" class="btn-back" data-prev="3"><i class="fas fa-arrow-left me-1"></i> Back</button>
                            <button type="button" class="btn-next" data-next="3">Next <i class="fas fa-arrow-right ms-1"></i></button>
                        </div>
                    </div>
                </div>

                <!-- STEP 4: Review & Submit -->
                <div class="wizard-step<?php echo $initial_step === 4 ? ' active' : ''; ?>" id="step4">
                    <div class="card-box">
                        <div class="step-heading">
                            <div class="num">4</div>
                            <div>
                                <h5>Review &amp; Submit</h5>
                                <small>Review your information. Click "Edit" to go back and make changes.</small>
                            </div>
                        </div>

                        <div class="gc-box bad" id="gcStep4Box" role="alert" style="margin-bottom:16px"></div>

                        <!-- Review: details changed from the saved record (Continuing / Returning) -->
                        <div class="review-card review-changes" id="revChangesCard" style="display:none">
                            <div class="review-header">
                                <span><i class="fas fa-pen-to-square me-1"></i> <span id="revChangesTitle">Details you changed</span></span>
                                <button type="button" data-edit="2"><i class="fas fa-pen me-1"></i> Edit</button>
                            </div>
                            <div id="revChangesList"></div>
                        </div>

                        <div class="review-grid-2">
                            <!-- Review: Grade Level -->
                            <div class="review-card">
                                <div class="review-header">
                                    <span><i class="fas fa-graduation-cap me-1"></i> Applicant Type &amp; Grade</span>
                                    <button type="button" data-edit="1"><i class="fas fa-pen me-1"></i> Edit</button>
                                </div>
                                <div class="review-row"><span>Grade Level</span><span id="revGradeLevel">â€”</span></div>
                                <div class="review-row"><span>Applicant Type</span><span id="revAppType">â€”</span></div>
                                <div class="review-row" id="revStrandRow" style="display:none"><span>Strand</span><span id="revStrand">â€”</span></div>
                            </div>

                            <!-- Review: Personal Information -->
                            <div class="review-card">
                                <div class="review-header">
                                    <span><i class="fas fa-user me-1"></i> Personal Information</span>
                                    <button type="button" data-edit="2"><i class="fas fa-pen me-1"></i> Edit</button>
                                </div>
                                <div class="review-row"><span>Full Name</span><span id="revFullName">â€”</span></div>
                                <div class="review-row"><span>LRN</span><span id="revLRN">â€”</span></div>
                                <div class="review-row"><span>Date of Birth</span><span id="revDOB">â€”</span></div>
                                <div class="review-row"><span>Sex</span><span id="revGender">â€”</span></div>
                                <div class="review-row"><span>Mobile Number</span><span id="revContact">â€”</span></div>
                                <div class="review-row"><span>Email Address</span><span id="revEmail">â€”</span></div>
                            </div>
                        </div>

                        <!-- Review: Additional Info -->
                        <div class="review-card">
                            <div class="review-header">
                                <span><i class="fas fa-sliders me-1"></i> Additional Information</span>
                                <button type="button" data-edit="3"><i class="fas fa-pen me-1"></i> Edit</button>
                            </div>
                            <div class="review-row"><span>Place of Birth</span><span id="revBirthPlace">â€”</span></div>
                            <div class="review-row"><span>Religion</span><span id="revReligion">â€”</span></div>
                            <div class="review-row"><span>Nationality</span><span id="revNationality">â€”</span></div>
                            <div class="review-row"><span>Address</span><span id="revAddress">â€”</span></div>
                            <hr class="my-1">
                            <div class="review-row"><span>Father's Name</span><span id="revFather">â€”</span></div>
                            <div class="review-row"><span>Mother's Name</span><span id="revMother">â€”</span></div>
                            <div class="review-row"><span>Guardian Name</span><span id="revGuardian">â€”</span></div>
                            <div class="review-row"><span>Relationship</span><span id="revGuardianRel">â€”</span></div>
                            <div class="review-row"><span>Guardian Contact</span><span id="revGuardianContact">â€”</span></div>
                            <hr class="my-1">
                            <div class="review-row" data-rev-types="Returning,Transferee"><span id="revSchoolLbl">Last School</span><span id="revSchool">â€”</span></div>
                            <div class="review-row" data-rev-types="Transferee"><span>School Address</span><span id="revSchoolAddr">â€”</span></div>
                            <div class="review-row" data-rev-types="Returning,Transferee"><span>Last Grade Completed</span><span id="revLastGrade">â€”</span></div>
                            <div class="review-row" data-rev-types="Returning,Transferee"><span>School Year</span><span id="revSchoolYear">â€”</span></div>
                        </div>

                        <!-- Review: Documents -->
                        <div class="review-card">
                            <div class="review-header">
                                <span><i class="fas fa-file-arrow-up me-1"></i> Uploaded Documents</span>
                                <button type="button" data-edit="3"><i class="fas fa-pen me-1"></i> Edit</button>
                            </div>
                            <div id="revDocs"><span style="color:var(--text-muted);font-size:14.5px">No documents attached.</span></div>
                        </div>

                        <!-- Certification -->
                        <div class="certify-box">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="certify" value="1" id="certify" style="margin-top:3px">
                                <label class="form-check-label" for="certify">
                                    I certify that all the information and documents I submitted are true, correct and authentic. I understand that any false statement may cancel my enrollment.
                                </label>
                            </div>
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" name="privacy_consent" value="1" id="privacyConsent" style="margin-top:3px">
                                <label class="form-check-label" for="privacyConsent">
                                    <strong>Privacy Notice (Data Privacy Act of 2012):</strong> I agree that the school may use the information and documents above only for enrollment and school records, and may send enrollment results, the Parent Portal link and school notices (email / SMS) to the learner's email and the parent/guardian mobile number. SMS is sent through the school's SMS provider; only the mobile number and the short message are shared with it.
                                </label>
                            </div>
                        </div>

                        <div class="btn-group">
                            <button type="button" class="btn-back" data-prev="4"><i class="fas fa-arrow-left me-1"></i> Back</button>
                            <button type="submit" class="btn-submit" id="submitBtn" disabled>
                                <i class="fas fa-spinner fa-spin"></i>
                                <i class="fas fa-paper-plane"></i>
                                <span id="submitBtnText">Submit Application</span>
                            </button>
                        </div>
                        <p class="text-center mt-3 mb-0" style="font-size:13.5px;color:var(--text-muted)">Original copies of your documents must still be presented to the registrar upon reporting to school.</p>
                    </div>
                </div>

            </form>
        <?php endif; ?>
    </div>

    <div class="site-footer">
        <div class="foot-school"><?php echo htmlspecialchars($school_name); ?></div>
        <div class="foot-tagline">Quality Education for a Stronger Tomorrow</div>
        <div class="foot-copy">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> &middot; Powered by EGRADEPRO</div>
    </div>

    <!-- Help modal (static UI content only â€” no backend) -->
    <div class="modal fade" id="helpModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-circle-question me-2" style="color:var(--primary)"></i>How to Enroll</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <ol>
                        <li>Select your Grade Level.</li>
                        <li>Fill in your Personal Information.</li>
                        <li>Complete your Additional Information.</li>
                        <li>Upload your Required Documents.</li>
                        <li>Review and Submit your application.</li>
                    </ol>
                    <p class="mb-0">Your application will be reviewed by the Registrar. The email address you provide is kept as contact information only.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- About Us modal (static UI content only â€” no backend) -->
    <div class="modal fade" id="aboutModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-school me-2" style="color:var(--primary)"></i>About Us</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p><strong><?php echo htmlspecialchars($school_name); ?></strong></p>
                    <p class="mb-0">Online Enrollment is powered by EGRADEPRO to provide students and parents with a faster and more convenient enrollment application process.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Contact modal (static UI content only â€” no backend) -->
    <div class="modal fade" id="contactModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-envelope me-2" style="color:var(--primary)"></i>Contact</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if ($school_address || $school_contact || $school_email_addr): ?>
                        <?php if ($school_address): ?><p><i class="fas fa-location-dot me-2" style="color:var(--text-muted)"></i><?php echo htmlspecialchars($school_address); ?></p><?php endif; ?>
                        <?php if ($school_contact): ?><p><i class="fas fa-phone me-2" style="color:var(--text-muted)"></i><?php echo htmlspecialchars($school_contact); ?></p><?php endif; ?>
                        <?php if ($school_email_addr): ?><p class="mb-0"><i class="fas fa-envelope me-2" style="color:var(--text-muted)"></i><?php echo htmlspecialchars($school_email_addr); ?></p><?php endif; ?>
                    <?php else: ?>
                        <p class="mb-0">For enrollment concerns, please contact the school registrar.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Shared document preview modal (image lightbox / PDF viewer). Purely a
         client-side preview of the File the applicant just picked â€” nothing
         here is uploaded early or persisted; it just reads the local file
         with an object URL so they can confirm it's the right document. -->
    <div class="modal fade" id="docPreviewModal" tabindex="-1" aria-hidden="true" aria-labelledby="docPreviewTitle">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="docPreviewTitle"><i class="fas fa-file me-2" style="color:var(--primary)"></i>Document Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="preview-modal-body" id="docPreviewBody"></div>
                <div class="preview-modal-meta" id="docPreviewMeta"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>static/js/theme.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var currentStep = <?php echo (int) $initial_step; ?>;
        var totalSteps = 4;
        var stepTitles = ['Applicant Type & Grade', 'Personal Information', 'Additional Info & Documents', 'Review & Submit'];
        var MAX_FILE_SIZE = 5 * 1024 * 1024;
        var ALLOWED_EXTS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

        // Mobile hamburger nav (UI-only â€” no routes involved)
        var hamburger = document.getElementById('navHamburger');
        var mobileNav = document.getElementById('mobileNav');
        if (hamburger && mobileNav) {
            hamburger.addEventListener('click', function() {
                var open = mobileNav.classList.toggle('open');
                hamburger.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            mobileNav.querySelectorAll('a').forEach(function(a) {
                a.addEventListener('click', function() {
                    mobileNav.classList.remove('open');
                    hamburger.setAttribute('aria-expanded', 'false');
                });
            });
        }

        // Next buttons
        document.querySelectorAll('[data-next]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var step = parseInt(this.getAttribute('data-next'));
                // Review & Submit is only reachable once every step (fields AND
                // documents for the chosen grade + applicant type) is complete.
                if (step === 3 ? validateAll(3) : validateStep(step)) {
                    if (step === 3) populateReview();
                    showStep(step + 1);
                }
            });
        });

        // Back buttons
        document.querySelectorAll('[data-prev]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var step = parseInt(this.getAttribute('data-prev'));
                showStep(step - 1);
            });
        });

        // Edit buttons in review
        document.querySelectorAll('[data-edit]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var step = parseInt(this.getAttribute('data-edit'));
                showStep(step);
            });
        });

        function showStep(n) {
            document.querySelectorAll('.wizard-step').forEach(function(el) {
                el.classList.remove('active');
            });
            document.getElementById('step' + n).classList.add('active');
            updateProgress(n);
            currentStep = n;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function markInvalid(el, errorId) {
            if (!el) return;
            el.classList.add('is-invalid');
            var err = errorId ? document.getElementById(errorId) : (el.parentElement ? el.parentElement.querySelector('.field-error') : null);
            if (err) err.classList.add('show');
        }
        function clearInvalid(el) {
            if (!el) return;
            el.classList.remove('is-invalid');
            var err = el.parentElement ? el.parentElement.querySelector('.field-error') : null;
            if (err) err.classList.remove('show');
        }
        document.querySelectorAll('.form-control, .form-select').forEach(function(el) {
            el.addEventListener('input', function() { clearInvalid(el); });
            el.addEventListener('change', function() { clearInvalid(el); });
        });

        function showBanner(n) {
            var b = document.getElementById('banner-step' + n);
            if (b) b.classList.add('show');
        }
        function setBanner1(msg) {
            var t = document.getElementById('banner1Text');
            if (t) t.textContent = msg;
        }
        function hideBanner(n) {
            var b = document.getElementById('banner-step' + n);
            if (b) b.classList.remove('show');
        }
        function focusAndScroll(el) {
            if (!el) return;
            try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // ---- Applicant Type rules (mirrors includes/enrollment_helpers_online.php) ----
        var TYPE_GRADES = <?php
            $tg = [];
            foreach (getApplicantTypes() as $t_) { $tg[$t_] = getApplicantTypeGrades($t_); }
            echo json_encode($tg);
        ?>;
        var TYPE_INFO = {
            'New Student': 'Incoming Grade 7 or Grade 11 learner. You fill out the complete form. Upload: Report Card + Birth Certificate.',
            'Continuing': 'Current IISS student progressing to the next grade level (Grade 8 to 12). Enter your LRN and date of birth first \u2014 we verify your IISS record, set your grade level for you and load your saved profile. Upload: Report Card only.',
            'Returning': 'Former student coming back (Balik-Aral). We check your LRN and reuse your old record \u2014 you review and update your information. Upload: Report Card + Birth Certificate.',
            'Transferee': 'Coming from another school. You fill out the complete form plus your previous school details. Upload: Report Card + Birth Certificate.'
        };
        var TYPE_LABEL = { 'New Student': 'New Student', 'Continuing': 'Continuing', 'Returning': 'Returning (Balik-Aral)', 'Transferee': 'Transferee' };
        // What each Applicant Type sees in steps 2-4 (stepper labels, headings, the "next steps" list).
        var TYPE_FLOW = {
            '': {
                dots: ['Personal Information', 'Additional Info & Documents'],
                s2: ['Personal Information', 'Provide your personal details as they appear on your official documents.'],
                s3: ['Additional Information', 'Fill in what you know. This helps the school understand your background.'],
                s3note: 'Only the information needed for this applicant type is shown.',
                list: []
            },
            'New Student': {
                dots: ['Personal Information', 'Additional Info & Documents'],
                s2: ['Personal Information', 'Provide your personal details as they appear on your official documents.'],
                s3: ['Additional Info & Documents', 'Fill in your address and guardian, then upload your Report Card and Birth Certificate.'],
                s3note: 'Only the information needed for a New Student is shown.',
                list: ['Complete personal information form (name, LRN, birth date, contact).', 'Address and parent / guardian, then upload your Report Card and PSA Birth Certificate.', 'Review and submit.']
            },
            'Continuing': {
                dots: ['Your Saved Profile', 'Update Details & Report Card'],
                s2: ['Your Saved Profile', 'Your LRN and date of birth were verified in Step 1. Your saved profile is loaded \u2014 only the details that changed can be updated.'],
                s3: ['Update Details & Upload Report Card', 'Everything is filled in from your saved record. Press Edit only on the details that changed, then upload your Report Card.'],
                s3note: 'Your saved details are locked. Press Edit on the ones that changed \u2014 only those are updated.',
                list: ['Your saved profile is loaded (you verified your LRN and date of birth in Step 1).', 'Check your details (press Edit only on what changed) and upload your Report Card \u2014 birth certificate is already on file.', 'Review your changes and submit.']
            },
            'Returning': {
                dots: ['Verify LRN & Profile', 'Review, Update & Upload'],
                s2: ['Verify LRN & Review Profile', 'Enter your LRN and date of birth. If we find your old record we reuse it \u2014 please review and update every detail.'],
                s3: ['Review, Update & Upload', 'Review each detail, update what changed, tell us where you last studied, and upload your documents.'],
                s3note: 'Returning learners must review their saved information and give their last school details.',
                list: ['Enter your LRN and date of birth \u2014 we look for your old record and reuse it.', 'Review and update every detail, add your last school attended, and upload your Report Card and PSA Birth Certificate.', 'Review and submit.']
            },
            'Transferee': {
                dots: ['Personal Information', 'Previous School & Documents'],
                s2: ['Personal Information', 'Provide your personal details as they appear on your official documents. Your LRN must not be registered in our school yet.'],
                s3: ['Previous School & Documents', 'Tell us about your previous school and your guardian, then upload your Report Card and Birth Certificate.'],
                s3note: 'Transferees give their previous school details.',
                list: ['Complete personal information form (name, LRN, birth date, contact).', 'Address, parent / guardian and previous school, then upload your Report Card and PSA Birth Certificate.', 'Review and submit.']
            }
        };
        function isLookupType(t) { return t === 'Continuing' || t === 'Returning'; }
        function curGrade() { var p = document.querySelector('input[name="grade_level"]:checked'); return p ? p.value : ''; }
        function curType() { var t = document.getElementById('f_application_type'); return t ? t.value : ''; }
        function typeAllowed(type, grade) { return !type || !grade || (TYPE_GRADES[type] || []).indexOf(grade) !== -1; }
        // Continuing: has this browser verified LRN + date of birth against an IISS record (and got a grade back)?
        function continuingVerified() { return !!(lookup && lookup.status === 'verified' && lookup.type === 'Continuing' && lookup.expectedGrade); }
        // Open (and select) only the verified next grade for Continuing; with no verified record every grade stays closed.
        function applyContinuingGrade() {
            if (curType() !== 'Continuing') return;
            var g = continuingVerified() ? lookup.expectedGrade : '';
            document.querySelectorAll('input[name="grade_level"]').forEach(function (r) { r.checked = (g !== '' && r.value === g); });
            onGradeChange();   // re-syncs the open / closed cards, the strand box and the document list
        }
        function needsPrev(type) { return type === 'Returning' || type === 'Transferee'; }
        function gradeNum(g) { return parseInt(String(g).replace('Grade ', ''), 10) || 0; }
        function typeGradeMessage(type, grade) {
            if (type === 'New Student') return 'New Student is only for Grade 7 and Grade 11. For ' + grade + ', choose Continuing, Returning or Transferee.';
            if (type === 'Continuing') return 'Continuing is only for Grade 8, 9, 10, 11 and 12. For ' + grade + ', choose New Student, Returning or Transferee.';
            if (type === 'Transferee') return 'Transferee is only for Grade 8, 9, 10, 11 and 12. Grade 7 is for New Students, so choose New Student for ' + grade + '.';
            return 'This Applicant Type is not available for ' + grade + '.';
        }
        function activeDocGroup() {
            var g = curGrade(), t = curType();
            if (!g || !t || !typeAllowed(t, g)) return null;
            return document.querySelector('.doc-group[data-grade="' + g + '"][data-type="' + t + '"]');
        }
        // Runs steps 1..upTo in order; stops (and shows) the first step that is incomplete.
        function validateAll(upTo) {
            for (var i = 1; i <= upTo; i++) {
                if (!validateStep(i)) {
                    if (currentStep !== i) showStep(i);
                    return false;
                }
            }
            return true;
        }

        // Required fields keep their validation â€” only the visible red
        // asterisk was removed (see item 5/16 of the design brief). Every
        // check below still blocks Next/Submit exactly as before; only the
        // UX changed from a browser alert() to an inline banner + highlight.
        function validateStep(n) {
            if (n === 1) {
                hideBanner(1);
                // 1) Applicant Type (it decides everything that follows)
                var typeEl = document.getElementById('typeGrid');
                var typeVal = curType();
                var typeErr = document.getElementById('err-application_type');
                if (typeVal === '') {
                    if (typeErr) typeErr.textContent = 'Please select your Applicant Type.';
                    markInvalid(typeEl, 'err-application_type');
                    setBanner1('Please select your Applicant Type to continue.');
                    showBanner(1);
                    if (typeEl) typeEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return false;
                }
                clearInvalid(typeEl);

                // 1b) Continuing: the record must be verified first -- the grade comes from it
                if (typeVal === 'Continuing' && !continuingVerified()) {
                    setBanner1('Continuing students must first enter their LRN and date of birth and press "Find my record". Your grade level is set from your verified IISS record.');
                    showBanner(1);
                    var vpEl = document.getElementById('verifyPanel');
                    if (vpEl) vpEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return false;
                }

                // 2) Grade level (must be one this Applicant Type may apply for)
                var gradeGrid = document.querySelector('.grade-grid');
                var gl = document.querySelector('input[name="grade_level"]:checked');
                if (!gl) {
                    if (gradeGrid) gradeGrid.classList.add('is-invalid');
                    setBanner1('Please select a grade level to continue.');
                    showBanner(1);
                    if (gradeGrid) gradeGrid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return false;
                }
                if (gradeGrid) gradeGrid.classList.remove('is-invalid');
                var grade = gl.value;
                if (typeVal === 'Continuing' && grade !== lookup.expectedGrade) {
                    setBanner1('Based on your verified IISS record, Continuing students can only enroll in ' + lookup.expectedGrade + '.');
                    showBanner(1);
                    if (gradeGrid) gradeGrid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return false;
                }
                if (!typeAllowed(typeVal, grade)) {
                    setBanner1(typeGradeMessage(typeVal, grade));
                    showBanner(1);
                    if (gradeGrid) gradeGrid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return false;
                }
                var shs = (grade === 'Grade 11' || grade === 'Grade 12');
                if (shs) {
                    var strand = document.getElementById('strandSelect');
                    if (!strand || !strand.value) {
                        setBanner1('Please select your Senior High strand to continue.');
                        markInvalid(strand, 'err-strand');
                        showBanner(1);
                        focusAndScroll(strand);
                        return false;
                    }
                    clearInvalid(strand);
                }
                var gcNow = gradeCheckNow();
                // (From Step 3 / Submit the same mismatch is reported in Step 3 by validateDocs(), so the applicant is stopped there.)
                if (gcNow && gcNow.status === 'mismatch' && currentStep === 1) {
                    renderGradeCheck();
                    setBanner1(gcFullMessage(gcNow.info));
                    showBanner(1);
                    if (gradeGrid) { gradeGrid.classList.add('gc-flag'); gradeGrid.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                    return false;
                }
                return true;
            }

            if (n === 2) {
                hideBanner(2);
                var setBanner2 = function (m) { var bt = document.getElementById('banner2Text'); if (bt) bt.textContent = m; };
                setBanner2('Please complete the required fields highlighted below.');
                var ok = true, firstInvalid = null;
                // Continuing / Returning: the LRN lookup has to be done (and matched) first.
                if (isLookupType(curType()) && !lookup.status) {
                    var vl = document.getElementById('v_lrn'), vd = document.getElementById('v_dob');
                    var vlOk = /^[0-9]{12}$/.test(vl.value.trim());
                    if (!vlOk) { var ve = document.getElementById('err-v_lrn'); ve.textContent = vl.value.trim() === '' ? 'LRN is required.' : 'LRN must contain exactly 12 digits.'; markInvalid(vl, 'err-v_lrn'); }
                    if (!vd.value) markInvalid(vd, 'err-v_dob');
                    setBanner2(vlOk && vd.value ? 'Please press "Find my record" to check your LRN before continuing.' : 'Enter your LRN and date of birth, then press "Find my record".');
                    showBanner(2);
                    focusAndScroll(!vlOk ? vl : (!vd.value ? vd : document.getElementById('btnVerify')));
                    return false;
                }
                function check2(name, isValid) {
                    var el = document.querySelector('[name="' + name + '"]');
                    if (!el) return;
                    if (!isValid) {
                        markInvalid(el);
                        ok = false;
                        if (!firstInvalid) firstInvalid = el;
                    } else {
                        clearInvalid(el);
                    }
                }
                check2('last_name', document.querySelector('[name="last_name"]').value.trim() !== '');
                check2('first_name', document.querySelector('[name="first_name"]').value.trim() !== '');
                check2('birth_date', document.querySelector('[name="birth_date"]').value.trim() !== '');
                check2('gender', document.querySelector('[name="gender"]').value.trim() !== '');
                check2('contact_number', document.querySelector('[name="contact_number"]').value.trim() !== '');
                var emailVal = document.querySelector('[name="email"]').value.trim();
                var emailShapeOk = emailVal !== '' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal);
                var errEmail = document.getElementById('err-email');
                if (errEmail) errEmail.textContent = 'A valid email address is required.';
                check2('email', emailShapeOk);
                // The email is contact information only: it is never verified against
                // a mail server and never compared with another account, so shape is
                // the ONLY thing that can fail here (mirrors the server check).

                // LRN â€” required, exactly 12 digits, numbers only (mirrors the
                // server-side check in online_enrollment.php one-for-one).
                var lrnEl = document.getElementById('f_lrn');
                if (lrnEl) {
                    var lrnVal = lrnEl.value.trim();
                    var lrnErrEl = document.getElementById('err-lrn');
                    var lrnMsg = '';
                    var lrnValid = true;
                    if (lrnVal === '') { lrnValid = false; lrnMsg = 'LRN is required.'; }
                    else if (!/^[0-9]+$/.test(lrnVal)) { lrnValid = false; lrnMsg = 'LRN must contain numbers only.'; }
                    else if (lrnVal.length !== 12) { lrnValid = false; lrnMsg = 'LRN must contain exactly 12 digits.'; }
                    // New Student / Transferee: the LRN must not be registered already.
                    if (lrnValid && !isLookupType(curType()) && lrnCheck.lrn === lrnVal && lrnCheck.status === 'bad') {
                        lrnValid = false; lrnMsg = lrnCheck.message;
                    }
                    if (!lrnValid) {
                        if (lrnErrEl) lrnErrEl.textContent = lrnMsg;
                        markInvalid(lrnEl, 'err-lrn');
                        ok = false;
                        if (!firstInvalid) firstInvalid = lrnEl;
                    } else {
                        clearInvalid(lrnEl);
                    }
                }

                if (!ok) { showBanner(2); focusAndScroll(firstInvalid); return false; }
                return true;
            }

            if (n === 3) {
                hideBanner(3);
                var ok3 = true, firstInvalid3 = null;
                function bad3(el, errId, msg) {
                    if (!el) return;
                    var err = errId ? document.getElementById(errId) : (el.parentElement ? el.parentElement.querySelector('.field-error') : null);
                    if (err && msg) err.textContent = msg;
                    markInvalid(el, errId);
                    ok3 = false;
                    if (!firstInvalid3) firstInvalid3 = el;
                }
                function check3(name) {
                    var el = document.querySelector('[name="' + name + '"]');
                    if (!el || el.disabled) return;
                    if (el.value.trim() === '') { bad3(el); } else { clearInvalid(el); }
                }
                var typeVal = curType(), gradeVal = curGrade();
                check3('address');
                check3('guardian_name');
                check3('guardian_contact');
                // Returning / Transferee: where the learner came from.
                if (needsPrev(typeVal)) {
                    var isT = typeVal === 'Transferee';
                    var nameEl = document.getElementById('f_last_school_name');
                    if (nameEl && nameEl.value.trim() === '') bad3(nameEl, null, (isT ? 'Previous school name' : 'Last school attended') + ' is required.'); else clearInvalid(nameEl);
                    if (isT) check3('last_school_address');
                    var lgEl = document.getElementById('f_last_grade_completed');
                    if (lgEl) {
                        if (lgEl.value === '') bad3(lgEl, 'err-last_grade_completed', 'Last grade level completed is required.');
                        else if (gradeNum(lgEl.value) >= gradeNum(gradeVal)) bad3(lgEl, 'err-last_grade_completed', 'It must be a grade level lower than ' + gradeVal + '.');
                        else clearInvalid(lgEl);
                    }
                    var lyEl = document.getElementById('f_last_school_year');
                    if (lyEl) {
                        var ly = lyEl.value.trim(), m = /^(\d{4})-(\d{4})$/.exec(ly);
                        if (ly === '') bad3(lyEl, 'err-last_school_year', 'Last school year attended is required.');
                        else if (!m || parseInt(m[2], 10) !== parseInt(m[1], 10) + 1) bad3(lyEl, 'err-last_school_year', 'Use the format 2025-2026.');
                        else clearInvalid(lyEl);
                    }
                }
                // Returning (record found): confirm the saved information was reviewed.
                var rcBox = document.getElementById('reviewConfirmBox'), rcChk = document.getElementById('profileReviewed');
                if (rcBox && rcBox.style.display !== 'none' && rcChk && !rcChk.checked) {
                    var rcErr = document.getElementById('err-profile_reviewed');
                    if (rcErr) rcErr.classList.add('show');
                    rcBox.classList.add('is-invalid');
                    ok3 = false;
                    if (!firstInvalid3) firstInvalid3 = rcBox;
                }
                if (!ok3) { showBanner(3); focusAndScroll(firstInvalid3); return false; }
                return validateDocs();
            }

            return true;
        }

        // The required uploads for the chosen grade + applicant type (part of Step 3).
        function validateDocs() {
            {
                hideBanner('docs');
                var group = activeDocGroup();
                if (!group) { showBanner('docs'); return false; }   // grade / applicant type not (validly) chosen
                var ok4 = true, firstInvalidCard = null;
                group.querySelectorAll('.doc-card.required').forEach(function(card) {
                    var pagesMissing = Array.prototype.slice.call(card.querySelectorAll('.doc-file-input[data-required="1"]'))
                        .filter(function (i) { return !(i.files && i.files.length); });
                    if (pagesMissing.length) {
                        var labels = pagesMissing.map(function (i) { return i.getAttribute('data-page-label'); }).filter(Boolean);
                        showDocError(card, labels.length ? 'Please upload ' + labels.join(' and ') + '.' : 'This document is required.');
                        ok4 = false;
                        if (!firstInvalidCard) firstInvalidCard = card;
                    } else {
                        clearDocError(card);
                    }
                });
                if (ok4) {
                    var rcCard = group.querySelector('.doc-card[data-doc-key="form138"]');
                    var rcMsg = rcCard ? reportCardProblem(rcCard) : '';
                    if (rcMsg) {
                        rcCard.classList.add('doc-card-invalid');   // the message itself is on the card's status line
                        ok4 = false;
                        firstInvalidCard = rcCard;
                    }
                }
                if (!ok4) {
                    var gcDocs = gradeCheckNow(), docsTxt = document.getElementById('bannerDocsText');
                    if (docsTxt) docsTxt.textContent = (gcDocs && gcDocs.status === 'mismatch') ? gcFullMessage(gcDocs.info) : (lastGradeMessage() || 'Please upload every document marked Required below.');
                    showBanner('docs');
                    if (firstInvalidCard) firstInvalidCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return false;
                }
                return true;
            }
        }

        function updateProgress(n) {
            document.querySelectorAll('#progressBar .dot').forEach(function(dot) {
                var s = parseInt(dot.getAttribute('data-dot'));
                dot.classList.remove('active', 'done');
                if (s < n) dot.classList.add('done');
                else if (s === n) dot.classList.add('active');
            });
            document.querySelectorAll('#progressBar .line').forEach(function(line) {
                var l = parseInt(line.getAttribute('data-line'));
                line.classList.toggle('done', l < n);
            });
            var mobileStatus = document.getElementById('mobileStepStatus');
            if (mobileStatus) {
                mobileStatus.innerHTML = 'Step ' + n + ' of ' + totalSteps + '<strong>' + stepTitles[n - 1] + '</strong>';
            }
            var fill = document.getElementById('mobileProgressFill');
            if (fill) fill.style.width = Math.round((n / totalSteps) * 100) + '%';
        }

        // Grade / Applicant Type changes decide which document group is shown.
        // The files picked for the group that stops applying are cleared (never
        // silently submitted) -- except when only the Applicant Type changed
        // and the same document (e.g. Report Card) is still asked for: that
        // file is carried over so the applicant does not have to pick it again.
        var previousGrade = curGrade();
        var previousGroupKey = (function () { var g = curGrade(), t = curType(); return (g && t && typeAllowed(t, g)) ? g + '|' + t : ''; })();

        function refreshDocs() {
            var grade = curGrade(), type = curType();
            var key = (grade && type && typeAllowed(type, grade)) ? grade + '|' + type : '';
            if (previousGroupKey !== key) {
                var pk = previousGroupKey.split('|');
                var oldGroup = previousGroupKey ? document.querySelector('.doc-group[data-grade="' + pk[0] + '"][data-type="' + pk[1] + '"]') : null;
                var newGroup = key ? document.querySelector('.doc-group[data-grade="' + grade + '"][data-type="' + type + '"]') : null;
                if (oldGroup) {
                    oldGroup.querySelectorAll('.doc-file-input').forEach(function(inp) {
                        if (inp.files && inp.files.length) {
                            var file = inp.files[0];
                            var card = inp.closest('.doc-card');
                            var target = (newGroup && card) ? newGroup.querySelector('.doc-card[data-doc-key="' + card.getAttribute('data-doc-key') + '"] .doc-file-input[name="' + inp.name + '"]') : null;
                            if (target) {
                                try { var dt = new DataTransfer(); dt.items.add(file); target.files = dt.files; updateDocCardUI(target); } catch (err) {}
                            }
                            inp.value = '';
                            updateDocCardUI(inp);
                            clearDocError(card);
                        }
                    });
                }
            }
            previousGroupKey = key;

            document.querySelectorAll('.doc-group').forEach(function(g) {
                var match = key !== '' && g.dataset.grade === grade && g.dataset.type === type;
                g.style.display = match ? 'block' : 'none';
                g.querySelectorAll('input[type=file]').forEach(function(i) { i.disabled = !match; });
            });
            var ph = document.getElementById('docPlaceholder');
            if (ph) ph.style.display = key ? 'none' : 'block';

            document.getElementById('docHint').textContent = key
                ? 'These are the documents required for ' + (TYPE_LABEL[type] || type) + ' applicants (' + grade + '). ' + (type === 'Continuing' ? 'Your birth certificate is already on file, so only the Report Card is needed. ' : '') + 'JPG, PNG, WEBP or PDF â€” max 5 MB each.'
                : 'Select your Applicant Type and grade level to see your required documents.';
        }

        // Applicant Type comes first: it decides which grade levels can still be picked.
        // (A grade that is not open to the chosen type is greyed out; if one was already
        // picked and no longer fits, it is cleared with a note.)
        function syncTypeOptions(resetNote) {
            var type = curType();
            var allowed = type ? (TYPE_GRADES[type] || []) : null;
            // Continuing never picks a grade freely: nothing is open until the LRN + date of birth are
            // verified, then only the one grade that follows the learner's IISS record.
            var contLock = (type === 'Continuing');
            if (contLock) allowed = continuingVerified() && lookup.expectedGrade ? [lookup.expectedGrade] : [];
            var cleared = '';
            document.querySelectorAll('input[name="grade_level"]').forEach(function(r) {
                var ok = !allowed || allowed.indexOf(r.value) !== -1;
                r.disabled = !ok;
                var opt = r.closest('.grade-option');
                if (opt) opt.classList.toggle('is-disabled', !ok);
                if (!ok && r.checked) { r.checked = false; cleared = r.value; }
            });
            if (cleared) {
                document.getElementById('strandWrap').style.display = 'none';
                previousGrade = '';
            }
            var gh = document.getElementById('gradeHint');
            if (gh) {
                gh.style.color = '';
                if (contLock) {
                    gh.textContent = 'Continuing students may proceed to Grade 8, Grade 9, Grade 10, Grade 11, or Grade 12 based on their verified previous IISS enrollment record.'
                        + (allowed.length ? ' Your verified record opens ' + allowed[0] + ' only.' : ' Enter your LRN and date of birth above to unlock your grade level.');
                }
                else if (cleared) { gh.textContent = typeGradeMessage(type, cleared) + ' Please pick another grade level.'; gh.style.color = 'var(--danger)'; }
                else if (allowed) gh.textContent = (TYPE_LABEL[type] || type) + ' can apply for: ' + allowed.join(', ') + '.';
                else gh.textContent = 'Choose your Applicant Type above to see which grade levels are open.';
            }
            var hint = document.getElementById('appTypeHint');
            if (hint) {
                hint.style.color = '';
                hint.textContent = type ? (TYPE_INFO[type] || '') : 'Choose the option that describes you. Not sure? Continuing = enrolled here this year, Returning = stopped and is coming back.';
            }
            renderTypeCards();
        }

        function renderTypeCards() {
            var t = curType();
            document.querySelectorAll('#typeGrid .type-card').forEach(function(c) {
                var on = c.getAttribute('data-type') === t;
                c.classList.toggle('is-selected', on);
                c.setAttribute('aria-checked', on ? 'true' : 'false');
            });
        }

        // Steps 2-4 follow the Applicant Type: stepper labels, headings, and the "what happens next" list.
        function applyFlow() {
            var type = curType();
            var f = TYPE_FLOW[type] || TYPE_FLOW[''];
            var labels = document.querySelectorAll('#progressBar .step-node .step-label');
            for (var i = 0; i < 2; i++) { if (labels[i + 1]) labels[i + 1].textContent = f.dots[i]; }
            stepTitles[1] = f.dots[0]; stepTitles[2] = f.dots[1];
            var set = function (id, txt) { var e = document.getElementById(id); if (e) e.textContent = txt; };
            set('step2Title', f.s2[0]); set('step2Sub', f.s2[1]);
            set('step3Title', f.s3[0]); set('step3Sub', f.s3[1]);
            set('step3TypeText', f.s3note);
            var flow = document.getElementById('typeFlow'), list = document.getElementById('typeFlowList');
            if (flow && list) {
                flow.style.display = type ? '' : 'none';
                set('typeFlowTitle', type ? 'Your steps as ' + (TYPE_LABEL[type] || type) : 'Your steps');
                list.innerHTML = '';
                f.list.forEach(function(txt, idx) {
                    var li = document.createElement('li');
                    li.innerHTML = '<b>' + (idx + 2) + '</b><span></span>';
                    li.querySelector('span').textContent = txt;
                    list.appendChild(li);
                });
            }
            updateProgress(currentStep);
        }

        // Show / hide (and require / ignore) the Additional Information fields that belong to the chosen type.
        function applyTypeFields() {
            var type = curType(), grade = curGrade(), show = needsPrev(type);
            var row = document.getElementById('prevSchoolRow');
            if (row) {
                row.style.display = show ? '' : 'none';
                row.querySelectorAll('[data-types]').forEach(function(col) {
                    var on = show && col.getAttribute('data-types').split(',').indexOf(type) !== -1;
                    col.style.display = on ? '' : 'none';
                    col.querySelectorAll('input, select').forEach(function(i) {
                        i.disabled = !on;            // a hidden field is never submitted
                        if (!on) clearInvalid(i);
                    });
                });
            }
            var tn = document.getElementById('step3TypeName'), gn = document.getElementById('step3GradeName');
            if (tn) tn.textContent = (TYPE_LABEL[type] || type) || '\u2014';
            if (gn) gn.textContent = grade || '\u2014';
            var lbl = document.getElementById('lblLastSchoolName');
            if (lbl) lbl.textContent = type === 'Transferee' ? 'Previous School Name' : 'Last School Attended';
            var prevSec = document.getElementById('prevSchoolSection');
            if (prevSec) prevSec.style.display = show ? '' : 'none';

            // "Last Grade Level Completed" can only be a grade below the one applied for.
            var lg = document.getElementById('f_last_grade_completed');
            if (lg) {
                Array.prototype.forEach.call(lg.options, function(o) {
                    if (o.value === '') return;
                    var below = (o.value === 'Grade 6') ? (grade === 'Grade 7') : (!grade || gradeNum(o.value) < gradeNum(grade));
                    o.disabled = !below; o.hidden = !below;
                });
                if (lg.value && grade && gradeNum(lg.value) >= gradeNum(grade)) lg.value = '';
            }
            var hint = document.getElementById('appTypeHint');
            if (hint && type) { hint.textContent = TYPE_INFO[type] || ''; hint.style.color = ''; }
        }

        function onGradeChange() {
            var grade = curGrade();
            var shs = (grade === 'Grade 11' || grade === 'Grade 12');
            document.getElementById('strandWrap').style.display = shs ? 'block' : 'none';
            var gg = document.querySelector('.grade-grid'); if (gg) gg.classList.remove('is-invalid');
            previousGrade = grade;
            syncTypeOptions(false);
            applyTypeFields();
            refreshDocs();
        }
        function onTypeChange() {
            var sel = document.getElementById('f_application_type');
            var err = document.getElementById('err-application_type');
            if (err) err.classList.remove('show');
            var tg = document.getElementById('typeGrid'); if (tg) tg.classList.remove('is-invalid');
            hideBanner(1);
            var t = curType();
            // A record loaded for another type (or a stale LRN check) no longer applies.
            if (lookup.status && lookup.type !== t) resetLookup(true);
            lrnCheck = { lrn: '', status: '', message: '' };
            setLrnStatus('', '');
            syncTypeOptions(true);
            applyFlow();
            applyStep2Layout();
            applyTypeFields();
            refreshDocs();
            setTimeout(syncLastGradeCheck, 0);
        }

        document.querySelectorAll('input[name="grade_level"]').forEach(function(radio) {
            radio.addEventListener('change', onGradeChange);
        });
        var lgSel = document.getElementById('f_last_grade_completed');
        if (lgSel) lgSel.addEventListener('change', syncLastGradeCheck);
        var appTypeSel = document.getElementById('f_application_type');
        if (appTypeSel) appTypeSel.addEventListener('change', onTypeChange);
        document.querySelectorAll('#typeGrid .type-card').forEach(function(card) {
            card.addEventListener('click', function() {
                var sel = document.getElementById('f_application_type');
                if (!sel || sel.value === card.getAttribute('data-type')) return;
                sel.value = card.getAttribute('data-type');
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });

        // ===================================================================
        // LRN-FIRST FLOW (Continuing / Returning) + duplicate-LRN check
        //   Continuing : LRN + birth date -> saved profile is loaded and locked;
        //                only the details the learner presses "Edit" on can change.
        //   Returning  : LRN + birth date -> old record reused, every detail is
        //                editable and has to be reviewed (confirm box in Step 3).
        //   New / Transferee : full form; the LRN is checked so no duplicate
        //                student record can be created (the server checks again).
        // ===================================================================
        var PROFILE_FIELDS = ['last_name', 'first_name', 'middle_name', 'suffix', 'lrn', 'birth_date', 'gender', 'contact_number', 'email',
                              'birth_place', 'religion', 'nationality', 'address', 'father_name', 'mother_name', 'guardian_name', 'guardian_relationship', 'guardian_contact'];
        var FIELD_LABELS = { last_name: 'Last Name', first_name: 'First Name', middle_name: 'Middle Name', suffix: 'Suffix', lrn: 'LRN', birth_date: 'Date of Birth', gender: 'Sex',
                             contact_number: 'Mobile Number', email: 'Email Address', birth_place: 'Place of Birth', religion: 'Religion', nationality: 'Nationality', address: 'Address',
                             father_name: "Father's Name", mother_name: "Mother's Name", guardian_name: 'Parent/Guardian Name', guardian_relationship: 'Relationship', guardian_contact: 'Guardian Contact No.' };
        var ALWAYS_LOCKED = ['lrn', 'birth_date'];
        var CONTINUING_LOCKED = ['last_name', 'first_name', 'middle_name', 'suffix', 'gender'];
        var lookup = { status: '', type: '', lrn: '', profile: null };   // status: '' | 'verified' | 'new_record'
        var lrnCheck = { lrn: '', status: '', message: '' };            // New Student / Transferee LRN check
        var fieldMode = {};                                             // name -> 'perm' | 'edit' | 'free'
        var oeForm0 = document.getElementById('oeForm');

        function fieldEl(name) { return document.querySelector('#oeForm [name="' + name + '"]'); }
        function lockField(el) {
            el.classList.add('oe-locked');
            if (el.tagName === 'SELECT') el.disabled = true; else el.readOnly = true;
        }
        function unlockField(el) {
            el.classList.remove('oe-locked', 'oe-changed');
            if (el.tagName === 'SELECT') el.disabled = false; else el.readOnly = false;
        }
        function fieldTools(el) {
            var lab = el.id ? document.querySelector('label[for="' + el.id + '"]') : null;
            if (!lab) return null;
            var t = lab.querySelector('.oe-label-tools');
            if (!t) { t = document.createElement('span'); t.className = 'oe-label-tools'; lab.appendChild(t); }
            return t;
        }
        function refreshFieldState(el) {
            var mode = fieldMode[el.name];
            if (!mode) return;
            var orig = el.getAttribute('data-orig') || '';
            var changed = el.value.trim() !== orig.trim();
            el.classList.toggle('oe-changed', changed);
            var t = fieldTools(el);
            if (!t) return;
            var html = '';
            if (mode === 'perm') html = '<span class="oe-lock-tag"><i class="fas fa-lock"></i> Locked</span>';
            else if (mode === 'edit') {
                html = el.classList.contains('oe-locked')
                    ? '<button type="button" class="oe-edit-btn" data-oe-edit="' + el.name + '"><i class="fas fa-pen"></i> Edit</button>'
                    : '<button type="button" class="oe-edit-btn" data-oe-undo="' + el.name + '"><i class="fas fa-rotate-left"></i> Undo</button>';
            }
            if (changed) html += '<span class="oe-changed-tag">Updated</span>';
            t.innerHTML = html;
        }
        if (oeForm0) {   // (the form is not on the page once an application was submitted)
            oeForm0.addEventListener('input', function(e) { if (e.target && e.target.name && fieldMode[e.target.name]) refreshFieldState(e.target); });
            oeForm0.addEventListener('change', function(e) { if (e.target && e.target.name && fieldMode[e.target.name]) refreshFieldState(e.target); });
        }
        document.addEventListener('click', function(e) {
            var b = e.target.closest ? e.target.closest('[data-oe-edit], [data-oe-undo]') : null;
            if (!b) return;
            e.preventDefault();
            var name = b.getAttribute('data-oe-edit') || b.getAttribute('data-oe-undo');
            var el = fieldEl(name);
            if (!el) return;
            if (b.hasAttribute('data-oe-edit')) { unlockField(el); refreshFieldState(el); try { el.focus(); } catch (err) {} }
            else { el.value = el.getAttribute('data-orig') || ''; lockField(el); refreshFieldState(el); }
        });

        function clearProfileDecor() {
            PROFILE_FIELDS.forEach(function(name) {
                var el = fieldEl(name);
                if (!el) return;
                unlockField(el);
                el.removeAttribute('data-orig');
                var t = el.id ? document.querySelector('label[for="' + el.id + '"] .oe-label-tools') : null;
                if (t) t.parentNode.removeChild(t);
            });
            fieldMode = {};
        }

        function showResult(kind, html) {
            var r = document.getElementById('verifyResult');
            if (!r) return;
            if (!html) { r.style.display = 'none'; r.innerHTML = ''; return; }
            r.className = 'verify-result ' + kind;
            r.innerHTML = html;
            r.style.display = '';
        }
        function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

        // Back to "nothing looked up yet" (values that came from a saved profile are cleared).
        function resetLookup(clearValues) {
            var wasVerified = lookup.status === 'verified';
            clearProfileDecor();
            if (clearValues && wasVerified) {
                PROFILE_FIELDS.forEach(function(name) {
                    var el = fieldEl(name);
                    if (el) el.value = (name === 'nationality') ? 'Filipino' : '';
                });
            }
            lookup = { status: '', type: '', lrn: '', profile: null, expectedGrade: '' };
            showResult('', '');
            var rc = document.getElementById('profileReviewed'), rb = document.getElementById('reviewConfirmBox');
            if (rc) { rc.checked = false; rc.disabled = true; }
            if (rb) { rb.style.display = 'none'; rb.classList.remove('is-invalid'); }
            applyStep2Layout();
            applyContinuingGrade();   // Continuing: no verified record -> every grade level closes again
        }

        function applyLookupModes(type, profile, restoring) {
            fieldMode = {};
            PROFILE_FIELDS.forEach(function(name) {
                var el = fieldEl(name);
                if (!el) return;
                var saved = profile ? (profile[name] || '') : '';
                var orig = (name === 'nationality' && saved === '') ? 'Filipino' : saved;
                var lockedAlways = ALWAYS_LOCKED.indexOf(name) !== -1 || (type === 'Continuing' && CONTINUING_LOCKED.indexOf(name) !== -1);
                if (profile) {
                    if (!restoring || lockedAlways || el.value.trim() === '') el.value = orig;
                    el.setAttribute('data-orig', orig);
                }
                if (lockedAlways) { fieldMode[name] = 'perm'; lockField(el); }
                else if (type === 'Continuing' && saved !== '') { fieldMode[name] = 'edit'; if (!restoring || el.value.trim() === orig.trim()) lockField(el); else unlockField(el); }
                else { fieldMode[name] = 'free'; unlockField(el); }
                refreshFieldState(el);
            });
        }

        function finishLookup(status, type, lrn, dob, profile, restoring, continuing) {
            lookup = { status: status, type: type, lrn: lrn, profile: profile || null,
                       expectedGrade: (status === 'verified' && type === 'Continuing' && continuing && continuing.expected_grade) ? continuing.expected_grade : '' };
            if (status === 'verified') {
                applyLookupModes(type, profile, restoring);
            } else {                       // new_record: nothing saved -- full form; only the checked LRN is locked
                clearProfileDecor();
                var l = fieldEl('lrn'); l.value = lrn;
                var d = fieldEl('birth_date'); if (!restoring || !d.value) d.value = dob;
                fieldMode = { lrn: 'perm' };
                lockField(l); refreshFieldState(l);
            }
            var rc = document.getElementById('profileReviewed'), rb = document.getElementById('reviewConfirmBox');
            var needConfirm = status === 'verified' && type === 'Returning';
            if (rb) rb.style.display = needConfirm ? '' : 'none';
            if (rc) { rc.disabled = !needConfirm; if (!needConfirm) rc.checked = false; }
            applyStep2Layout();
            applyTypeFields();
            applyContinuingGrade();   // Continuing: open and select only the verified next grade level
        }

        function applyStep2Layout() {
            var type = curType(), lk = isLookupType(type);
            var vp = document.getElementById('verifyPanel'), pf = document.getElementById('personalFields');
            if (vp) vp.style.display = lk ? '' : 'none';
            if (pf) pf.style.display = (!lk || lookup.status) ? '' : 'none';
            // Continuing verifies in Step 1 (before the grade level); Returning keeps it in Step 2.
            var home = document.getElementById(type === 'Continuing' ? 'verifyHome1' : 'verifyHome2');
            if (vp && home && vp.parentNode !== home) home.appendChild(vp);
            var vt = document.getElementById('verifyTitle'), vs = document.getElementById('verifySub');
            if (vt) vt.textContent = type === 'Returning' ? 'Welcome back! Enter your LRN' : 'Verify your LRN and date of birth first';
            if (vs) vs.textContent = type === 'Returning'
                ? 'We will check if you studied here before and reuse your old record. You will still review and update your information.'
                : 'We check your existing IISS record, set your grade level for you and load your saved profile. You only need to update the details that changed.';
            var lrnEl = document.getElementById('f_lrn');
            if (lrnEl && !lk && lookup.status === '' && lrnEl.classList.contains('oe-locked')) unlockField(lrnEl);
            var st = document.getElementById('lrnStatus');
            if (st && lk) st.style.display = 'none';
            var lh = document.getElementById('lrnHint');
            if (lh) lh.style.display = lk ? 'none' : '';
        }

        function postLookup(type, lrn, dob) {
            var fd = new FormData();
            fd.append('oe_action', 'lrn_lookup');
            fd.append('csrf_token', oeForm0.querySelector('[name="csrf_token"]').value);
            fd.append('application_type', type);
            fd.append('lrn', lrn);
            if (dob) fd.append('birth_date', dob);
            return fetch(window.location.href.split('#')[0], { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function(r) { return r.json(); });
        }

        // "Find my record" (Continuing / Returning)
        var btnVerify = document.getElementById('btnVerify');
        var vLrn = document.getElementById('v_lrn'), vDob = document.getElementById('v_dob');
        function runVerify() {
            var type = curType();
            if (!isLookupType(type) || btnVerify.disabled) return;
            var lrn = vLrn.value.trim(), dob = vDob.value;
            var bad = false;
            if (!/^[0-9]{12}$/.test(lrn)) {
                document.getElementById('err-v_lrn').textContent = lrn === '' ? 'LRN is required.' : (/^[0-9]+$/.test(lrn) ? 'LRN must contain exactly 12 digits.' : 'LRN must contain numbers only.');
                markInvalid(vLrn, 'err-v_lrn'); bad = true;
            }
            if (!dob) { markInvalid(vDob, 'err-v_dob'); bad = true; }
            if (bad) return;
            hideBanner(2);
            btnVerify.disabled = true;
            document.getElementById('btnVerifyText').textContent = 'Checking\u2026';
            showResult('info', '<i class="fas fa-spinner fa-spin"></i> Looking for your record\u2026');
            postLookup(type, lrn, dob).then(function(res) {
                if (curType() !== type) return;                     // the applicant changed type meanwhile
                if (lookup.status) resetLookup(true);
                if (res && res.ok && res.status === 'verified') {
                    finishLookup('verified', type, lrn, dob, res.profile, false, res.continuing || null);
                    var extra = (type === 'Continuing' && res.continuing)
                        ? ' <span class="verify-meta">Last grade at IISS: ' + esc(res.continuing.last_grade) + ' &rarr; ' + esc(res.continuing.expected_grade) + '</span>'
                        : (res.profile.current_grade ? ' <span class="verify-meta">On record: ' + esc(res.profile.current_grade) + '</span>' : '');
                    showResult('ok', '<i class="fas fa-circle-check"></i> <span>' + esc(res.message) + extra + '</span>');
                } else if (res && res.ok && res.status === 'new_record') {
                    finishLookup('new_record', type, lrn, dob, null, false);
                    showResult('info', '<i class="fas fa-circle-info"></i> <span>' + esc(res.message) + '</span>');
                } else {
                    showResult('err', '<i class="fas fa-circle-exclamation"></i> <span>' + esc((res && res.message) || 'We could not check your LRN. Please try again.') + '</span>');
                }
            }).catch(function() {
                showResult('err', '<i class="fas fa-circle-exclamation"></i> <span>We could not reach the server. Please check your connection and try again.</span>');
            }).then(function() {
                btnVerify.disabled = false;
                document.getElementById('btnVerifyText').textContent = lookup.status ? 'Search again' : 'Find my record';
            });
        }
        if (btnVerify) btnVerify.addEventListener('click', runVerify);
        [vLrn, vDob].forEach(function(inp) {
            if (!inp) return;
            inp.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); runVerify(); } });
            // Changing the LRN / date of birth after a match means the loaded record no longer applies.
            inp.addEventListener('input', function() {
                if (lookup.status) { resetLookup(true); document.getElementById('btnVerifyText').textContent = 'Find my record'; }
            });
        });
        if (vLrn) vLrn.addEventListener('input', function() { vLrn.value = vLrn.value.replace(/\D/g, '').slice(0, 12); });

        // The email is contact information only during Online Enrollment: no
        // account lookup, no verification code, no confirmation tick box. These
        // no-op stubs are kept so any leftover markup or caller degrades quietly
        // instead of throwing, and the Step 2 gate can still call them.
        function setEmailStatus(kind, msg) {
            var st = document.getElementById('emailStatus');
            if (!st) return;
            if (!msg) { st.style.display = 'none'; st.textContent = ''; return; }
            st.style.display = '';
            st.textContent = msg;
        }
        function showEmailConfirm(show) {
            var w = document.getElementById('emailConfirmWrap');
            if (w) w.style.display = show ? 'flex' : 'none';
        }
        function checkEmailAccount(continueAfter) {
            // Nothing to verify any more -- always let the applicant continue.
            if (continueAfter) { hideBanner(2); var nb = document.querySelector('[data-next="2"]'); if (nb) nb.click(); }
        }
        // New Student / Transferee: is this LRN still free?
        function setLrnStatus(kind, msg) {
            var st = document.getElementById('lrnStatus');
            if (!st) return;
            if (!msg) { st.style.display = 'none'; st.textContent = ''; return; }
            st.className = 'form-hint lrn-status ' + kind;
            st.textContent = msg;
            st.style.display = '';
        }
        var lrnTimer = null;
        function checkLrnAvailability() {
            var type = curType(), lrnEl = document.getElementById('f_lrn');
            if (!lrnEl || isLookupType(type) || !type) return;
            var lrn = lrnEl.value.trim();
            if (!/^[0-9]{12}$/.test(lrn)) { lrnCheck = { lrn: '', status: '', message: '' }; setLrnStatus('', ''); return; }
            if (lrnCheck.lrn === lrn && lrnCheck.status) return;
            setLrnStatus('', 'Checking LRN\u2026');
            postLookup(type, lrn, '').then(function(res) {
                if (curType() !== type || lrnEl.value.trim() !== lrn) return;
                if (res && res.ok) {
                    lrnCheck = { lrn: lrn, status: 'ok', message: '' };
                    setLrnStatus('ok', '\u2713 This LRN is available.');
                } else {
                    lrnCheck = { lrn: lrn, status: 'bad', message: (res && res.message) || 'This LRN cannot be used.' };
                    var er = document.getElementById('err-lrn');
                    if (er) er.textContent = lrnCheck.message;
                    markInvalid(lrnEl, 'err-lrn');
                    setLrnStatus('', '');
                }
            }).catch(function() { setLrnStatus('', ''); });   // offline: the server still checks on submit
        }
        (function() {
            var lrnEl = document.getElementById('f_lrn');
            if (!lrnEl) return;
            lrnEl.addEventListener('input', function() {
                if (lrnEl.readOnly) return;
                lrnCheck = { lrn: '', status: '', message: '' };
                setLrnStatus('', '');
                clearTimeout(lrnTimer);
                lrnTimer = setTimeout(checkLrnAvailability, 400);
            });
            lrnEl.addEventListener('blur', function() { clearTimeout(lrnTimer); checkLrnAvailability(); });
        })();
        var rcCheck = document.getElementById('profileReviewed');
        if (rcCheck) rcCheck.addEventListener('change', function() {
            var rb = document.getElementById('reviewConfirmBox'); if (rb) rb.classList.remove('is-invalid');
            var er = document.getElementById('err-profile_reviewed'); if (er) er.classList.remove('show');
        });

        // ===================================================================
        // DOCUMENT UPLOAD CARDS (Step 3 + the Review list in Step 4)
        // The real <input type="file"> for each requirement is never removed
        // or recreated â€” it just sits visually hidden inside its card â€” so
        // whatever the browser is holding in its .files stays put across
        // Back/Next/Edit. Everything below only changes how that same input
        // is *presented*; a page reload is the one thing that can clear a
        // file input (browser security â€” see item 41), which is unrelated to
        // this in-page wizard navigation.
        // ===================================================================
        function fmtBytes(bytes) {
            if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
            return bytes + ' B';
        }

        // ---- Report Card (Form 138) check (Step 3) ----
        // The 2-3 pages are sent TOGETHER; the server reads them as one Report
        // Card (name, grade level, grades, General Average, remarks). The LRN on
        // the card is NOT compared with Step 2 any more.
        // Kept per set of pages.
        var rcState = {};   // card key -> { sig, status, detected, message, card }
        var RC_MSG = {
            unreadable: 'Some information could not be verified. Please upload a clear and readable Report Card.',
            incomplete: 'Additional page required. Please upload the page containing the missing report card information.',
            unverified: 'Some information could not be verified from the uploaded report card. Please upload a clearer image or add another page of the Form 138.'
        };
        // Page 2 is conditional: optional unless Page 1 (and any other page) lacks required information.
        function rcSetPage2(card, mode) {
            var p2 = card.querySelector('.doc-file-input[id$="_p2"]');
            var slot = p2 && p2.closest('.doc-slot');
            if (!slot) return;
            var note = slot.querySelector('.doc-slot-note');
            var badge = slot.querySelector('.doc-slot-label .badge-req, .doc-slot-label .badge-opt');
            var need = mode === 'needed';
            if (badge) { badge.className = need ? 'badge-req' : 'badge-opt'; badge.textContent = need ? 'Required' : 'Optional'; }
            if (note) {
                note.className = 'doc-slot-note' + (need ? ' is-required' : (mode === 'ok' ? ' is-ok' : ''));
                note.textContent = need ? 'Required â€” Some report card information was not found on Page 1.'
                    : (mode === 'ok' ? 'Optional â€” No additional page needed.'
                    : 'Optional â€” required only if some report card information is missing on Page 1.');
            }
        }
        function rcPageCount(card) { return rcPages(card).filter(Boolean).length; }
        function fileSig(f) { return f ? f.name + '|' + f.size + '|' + f.lastModified : ''; }
        function rcKey(card) { var g = card.closest('.doc-group'); return (g ? g.dataset.grade + '|' + g.dataset.type : '') + '|form138'; }
        function rcPages(card) {   // chosen files, in page order
            return Array.prototype.slice.call(card.querySelectorAll('.doc-file-input')).map(function (i) { return i.files && i.files[0]; });
        }
        function rcRequiredDone(card) {
            return Array.prototype.slice.call(card.querySelectorAll('.doc-file-input[data-required="1"]')).every(function (i) { return i.files && i.files.length; });
        }
        function rcSig(card) { return rcPages(card).map(fileSig).join('#'); }
        function setCardLrnStatus(card, kind, msg) {
            if (!card) return;
            var st = card.querySelector('.doc-lrn-status');
            if (st) { st.className = 'doc-lrn-status' + (kind ? ' is-' + kind : ''); st.innerHTML = msg; }
        }
        function setCardRead(card, info) {
            var el = card && card.querySelector('.doc-card-read');
            if (!el) return;
            if (!info) { el.classList.remove('show'); el.innerHTML = ''; return; }
            el.innerHTML = '<b>Read from your Report Card:</b> ' + [
                info.name ? esc(info.name) : '',
                info.grade_level ? esc(info.grade_level) : '',
                info.general_average != null ? 'General Average <b>' + esc(String(info.general_average)) + '</b>' : '',
                info.remarks ? esc(info.remarks) : '',
                info.subjects ? info.subjects + ' subjects' : ''
            ].filter(Boolean).join(' &middot; ');
            el.classList.add('show');
        }
        function applyRcState(card) {
            setTimeout(syncLastGradeCheck, 0);
            var s = rcState[rcKey(card)];
            if (!rcRequiredDone(card)) { setCardLrnStatus(card, '', ''); setCardRead(card, null); rcSetPage2(card, 'idle'); return; }
            if (!s || s.sig !== rcSig(card)) { setCardLrnStatus(card, '', ''); setCardRead(card, null); rcSetPage2(card, 'idle'); return; }
            if (s.status === 'checking') {
                setCardLrnStatus(card, 'checking', '<i class="fas fa-spinner fa-spin me-1"></i>Reading your Report Card pages (grades)â€¦ this can take up to a minute.');
                setCardRead(card, null);
                return;
            }
            clearDocError(card);   // the status line below carries the result (no stale "please wait")
            if (s.status === 'verified') {
                setCardLrnStatus(card, 'verified', 'âœ“ Report Card read &middot; Required information detected');
                setCardRead(card, s.card);
                rcSetPage2(card, 'ok');
                renderGradeCheck();
            } else {
                rcSetPage2(card, s.status === 'incomplete' ? 'needed' : 'idle');
                setCardLrnStatus(card, 'bad', '<i class="fas fa-circle-xmark me-1"></i>' + esc(s.message || RC_MSG.unreadable));
                setCardRead(card, null);
            }
        }
        function checkReportCard(card) {
            if (!rcRequiredDone(card)) { applyRcState(card); return; }
            var key = rcKey(card), sig = rcSig(card);
            var old = rcState[key];
            if (old && old.sig === sig && old.status !== 'error') { applyRcState(card); return; }
            rcState[key] = { sig: sig, status: 'checking', detected: '', message: '', card: null };
            applyRcState(card);
            var fd = new FormData();
            fd.append('oe_action', 'report_card_lrn');
            fd.append('csrf_token', document.querySelector('#oeForm [name="csrf_token"]').value);
            fd.append('grade_level', curGrade());
            rcPages(card).forEach(function (f) { if (f) fd.append('report_card[]', f); });
            fetch(window.location.href.split('#')[0], { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (rcSig(card) !== sig) return;                     // pages changed meanwhile
                    rcState[key] = { sig: sig, status: (res && res.status) || 'error', detected: (res && res.detected) || '', message: (res && res.message) || '', card: (res && res.card) || null };
                    if (res && res.grade_check) gcBySig[sig] = res.grade_check;
                    applyRcState(card);
                })
                .catch(function () {
                    rcState[key] = { sig: sig, status: 'error', detected: '', message: 'The Report Card could not be checked right now. Please replace a page to try again.', card: null };
                    applyRcState(card);
                });
        }
        /** '' when the Report Card is complete and verified; otherwise the message to show. */
        function reportCardProblem(card) {
            var missing = Array.prototype.slice.call(card.querySelectorAll('.doc-file-input[data-required="1"]'))
                .filter(function (i) { return !(i.files && i.files.length); })
                .map(function (i) { return i.getAttribute('data-page-label'); });
            if (missing.length) return 'Please upload ' + missing.join(' and ') + ' of your Report Card.';   // Page 1 only; Page 2 is conditional
            var s = rcState[rcKey(card)];
            if (!s || s.sig !== rcSig(card)) { checkReportCard(card); return 'Checking your Report Cardâ€¦ please wait.'; }
            if (s.status === 'checking') return 'Checking your Report Cardâ€¦ please wait.';
            applyRcState(card);
            if (s.status !== 'verified') return s.message || RC_MSG.unreadable;
            var gc = gradeCheckNow();
            if (gc && gc.status === 'mismatch') return gcFullMessage(gc.info);
            return lastGradeMessage();
        }

        // ---- Grade level on the Form 138 vs the grade chosen in Step 1 ----
        // The server reads the grade on the card and says which grade is expected
        // (one grade up; the same grade when the card says Retained). Kept by page
        // set, so changing the grade in Step 1 re-compares at once. The selected
        // grade is never changed for the applicant.
        var gcBySig = {};
        function gradeCheckNow() {
            var group = activeDocGroup();
            var card = group ? group.querySelector('.doc-card[data-doc-key="form138"]') : null;
            if (!card || !rcRequiredDone(card)) return null;
            var s = rcState[rcKey(card)], sig = rcSig(card);
            if (!s || s.sig !== sig || s.status !== 'verified') return null;
            var g = gcBySig[sig];
            if (!g) return null;
            if (!g.card_grade) return { status: 'manual', card: card, info: g };
            var ok = g.expected !== '' && curGrade() === g.expected;
            return { status: ok ? 'match' : 'mismatch', card: card, info: g };
        }
        function gcMessage(info) {
            if (continuingVerified() && info.card_grade) {
                return 'Your uploaded Form 138 shows ' + info.card_grade + (info.retained ? ' (Retained)' : '') + ', but your IISS record says you are enrolling in ' + lookup.expectedGrade
                    + '. Please upload the correct Form 138 or contact the registrar. Your grade level was not changed.';
            }
            if (!info.expected) return info.message;
            return 'Your Form 138 shows ' + info.card_grade + (info.retained ? ' (Retained)' : ' completed') + ', so the correct enrollment level is ' + info.expected + '.';
        }
        // Returning / Transferee type their own "Last Grade Level Completed": it must be the grade shown on the Form 138.
        function lastGradeMessage() {
            if (!needsPrev(curType())) return '';
            var lg = document.getElementById('f_last_grade_completed'), gc = gradeCheckNow();
            if (!lg || !lg.value || !gc || !gc.info || !gc.info.card_grade || lg.value === gc.info.card_grade) return '';
            return 'Last grade completed does not match the uploaded Report Card. Your Form 138 shows ' + gc.info.card_grade + (gc.info.retained ? ' (Retained)' : ' completed')
                + ', but you selected ' + lg.value + '. Please change "Last Grade Level Completed" to ' + gc.info.card_grade + '.';
        }
        var lgSyncOwned = false;
        // Shows the message on the field and switches Step 3's Next button off while the Report Card disagrees.
        function syncLastGradeCheck() {
            var msg = lastGradeMessage(), gc = gradeCheckNow();
            var lg = document.getElementById('f_last_grade_completed'), err = document.getElementById('err-last_grade_completed');
            var nb = document.querySelector('[data-next="3"]');
            if (msg) {
                if (lg) lg.classList.add('is-invalid');
                if (err) { err.textContent = msg; err.classList.add('show'); }
                lgSyncOwned = true;
            } else if (lgSyncOwned) {
                lgSyncOwned = false;
                if (lg) lg.classList.remove('is-invalid');
                if (err) { err.textContent = 'Last grade level completed is required.'; err.classList.remove('show'); }
            }
            if (nb) nb.disabled = !!(msg || (gc && gc.status === 'mismatch'));
        }
        function gcFullMessage(info) { return 'Grade level does not match the uploaded Report Card. ' + gcMessage(info); }
        function gcBoxHtml(info, withButton) {
            return (withButton ? '<span class="gc-step">Action Required: Step 1</span>' : '<span class="gc-step">Step 1 &ndash; Grade Level Information</span>')
                + '<span class="gc-title"><i class="fas fa-triangle-exclamation me-1"></i>Grade Level Does Not Match</span>'
                + esc(gcMessage(info))
                + (withButton && !continuingVerified() ? '<br><button type="button" class="gc-btn" data-gc-go="1"><i class="fas fa-arrow-left"></i> Go to Step 1</button>' : '');
        }
        function renderGradeCheck() {
            var gc = gradeCheckNow();
            // the Form 138 card(s)
            document.querySelectorAll('.doc-card[data-doc-key="form138"]').forEach(function (c) {
                var box = c.querySelector('.gc-box');
                if (!box) {
                    box = document.createElement('div');
                    box.className = 'gc-box';
                    var read = c.querySelector('.doc-card-read');
                    if (read) read.insertAdjacentElement('afterend', box); else c.appendChild(box);
                }
                if (!gc || gc.card !== c) { box.className = 'gc-box'; box.innerHTML = ''; return; }
                if (gc.status === 'match') {
                    box.className = 'gc-box show ok';
                    box.innerHTML = '<i class="fas fa-circle-check me-1"></i>Grade Level Validated &middot; Form 138 shows ' + esc(gc.info.card_grade) + ' &rarr; ' + esc(gc.info.expected);
                } else if (gc.status === 'manual') {
                    box.className = 'gc-box show manual';
                    box.innerHTML = '<i class="fas fa-user-check me-1"></i><b>For Manual Verification</b> &middot; The grade level on your Form 138 could not be read. You may continue; the registrar will check it.';
                } else {
                    box.className = 'gc-box show bad';
                    box.innerHTML = gcBoxHtml(gc.info, true);
                    c.classList.add('doc-card-invalid');
                }
            });
            // Step 1 and Step 4
            var bad = gc && gc.status === 'mismatch';
            var b1 = document.getElementById('gcStep1Box'), b4 = document.getElementById('gcStep4Box');
            if (b1) { b1.className = 'gc-box bad' + (bad ? ' show' : ''); b1.innerHTML = bad ? gcBoxHtml(gc.info, false) : ''; }
            if (b4) { b4.className = 'gc-box bad' + (bad ? ' show' : ''); b4.innerHTML = bad ? gcBoxHtml(gc.info, true) : ''; }
            var grid = document.querySelector('.grade-grid');
            document.querySelectorAll('.grade-option').forEach(function (o) {
                var inp = o.querySelector('input[name="grade_level"]');
                o.classList.toggle('gc-wrong', !!(bad && inp && inp.checked));
                o.classList.toggle('gc-expected', !!(bad && inp && gc.info.expected && inp.value === gc.info.expected));
            });
            if (grid && !bad) grid.classList.remove('gc-flag');
            syncLastGradeCheck();
        }
        function goToStep1ForGrade() {
            showStep(1);
            var grid = document.querySelector('.grade-grid');
            if (!grid) return;
            grid.classList.remove('gc-flag'); void grid.offsetWidth; grid.classList.add('gc-flag');
            setTimeout(function () {
                grid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                var sel = grid.querySelector('input[name="grade_level"]:checked');
                if (sel) { try { sel.focus({ preventScroll: true }); } catch (e) {} }
            }, 350);
        }
        document.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('[data-gc-go]')) { e.preventDefault(); goToStep1ForGrade(); }
        });
        document.querySelectorAll('input[name="grade_level"]').forEach(function (r) {
            r.addEventListener('change', function () {
                setTimeout(function () {
                    // The Report Card pages moved to this grade's documents: reuse their result (same files).
                    var group = activeDocGroup();
                    var card = group ? group.querySelector('.doc-card[data-doc-key="form138"]') : null;
                    if (card && rcRequiredDone(card) && !rcState[rcKey(card)]) {
                        var sig = rcSig(card);
                        Object.keys(rcState).forEach(function (k) {
                            if (!rcState[rcKey(card)] && rcState[k].sig === sig && rcState[k].status !== 'checking') rcState[rcKey(card)] = rcState[k];
                        });
                        if (rcState[rcKey(card)]) applyRcState(card);
                    }
                    renderGradeCheck();
                }, 0);
            });
        });
        // (The LRN no longer affects the Report Card check, so editing it in
        // Step 2 does not re-check the cards.)

        function validateDocFile(file) {
            var ext = (file.name.split('.').pop() || '').toLowerCase();
            if (ALLOWED_EXTS.indexOf(ext) === -1) return 'Only JPG, PNG, WEBP and PDF files are allowed.';
            if (file.size > MAX_FILE_SIZE) return 'File exceeds the 5 MB limit.';
            return null;
        }

        function showDocError(card, msg) {
            if (!card) return;
            card.classList.add('doc-card-invalid');
            var err = card.querySelector('.doc-card-error');
            if (err) { err.textContent = msg; err.classList.add('show'); }
        }
        function clearDocError(card) {
            if (!card) return;
            card.classList.remove('doc-card-invalid');
            var err = card.querySelector('.doc-card-error');
            if (err) { err.textContent = ''; err.classList.remove('show'); }
        }

        function updateDocCardUI(input) {
            var card = input.closest('.doc-card');
            if (!card) return;
            var scope = input.closest('.doc-slot') || card;
            var emptyState = scope.querySelector('.doc-empty-state');
            var fileState = scope.querySelector('.doc-file-state');
            var file = input.files && input.files[0];
            if (scope !== card) scope.classList.toggle('has-file', !!file);
            var anyFile = Array.prototype.slice.call(card.querySelectorAll('.doc-file-input')).some(function (i) { return i.files && i.files.length; });
            card.classList.toggle('has-file', anyFile);
            if (file) {
                card.classList.add('has-file');
                if (emptyState) emptyState.style.display = 'none';
                if (fileState) {
                    fileState.style.display = 'flex';
                    var nameEl = fileState.querySelector('.doc-file-name');
                    var metaEl = fileState.querySelector('.doc-file-meta');
                    var ext = (file.name.split('.').pop() || '').toUpperCase();
                    if (nameEl) { nameEl.textContent = file.name; nameEl.title = file.name; }
                    if (metaEl) metaEl.textContent = ext + ' â€¢ ' + fmtBytes(file.size);
                }
            } else {
                if (!anyFile) card.classList.remove('has-file');
                if (emptyState) emptyState.style.display = 'flex';
                if (fileState) fileState.style.display = 'none';
            }
        }

        // Choose / Change / Remove / View â€” delegated so it works for every
        // grade's document cards without binding per-card listeners.
        document.addEventListener('click', function(e) {
            var chooseBtn = e.target.closest('.btn-doc-choose, .btn-doc-change');
            if (chooseBtn) {
                var input = document.getElementById(chooseBtn.getAttribute('data-for'));
                if (input && !input.disabled) input.click();
                return;
            }
            var removeBtn = e.target.closest('.btn-doc-remove');
            if (removeBtn) {
                var rInput = document.getElementById(removeBtn.getAttribute('data-for'));
                if (rInput) {
                    rInput.value = '';
                    updateDocCardUI(rInput);
                    clearDocError(rInput.closest('.doc-card'));
                    var rCard = rInput.closest('.doc-card');
                    if (rCard && rCard.getAttribute('data-doc-key') === 'form138') applyRcState(rCard);
                }
                return;
            }
            var viewBtn = e.target.closest('.btn-doc-view');
            if (viewBtn) {
                var vInput = document.getElementById(viewBtn.getAttribute('data-for'));
                if (vInput && vInput.files && vInput.files[0]) {
                    var card = vInput.closest('.doc-card');
                    var label = card ? card.querySelector('h6').textContent : 'Document';
                    openDocPreview(vInput.files[0], label);
                }
                return;
            }
        });

        // File chosen/changed â€” validate the same rules the server enforces
        // (extension + 5 MB) before accepting it, so a rejected file never
        // reaches Submit only to bounce back from PHP.
        document.addEventListener('change', function(e) {
            if (!e.target.classList || !e.target.classList.contains('doc-file-input')) return;
            var input = e.target;
            var card = input.closest('.doc-card');
            var file = input.files && input.files[0];
            if (!file) { updateDocCardUI(input); return; }
            var err = validateDocFile(file);
            if (err) {
                input.value = '';
                showDocError(card, err);
                updateDocCardUI(input);
                return;
            }
            clearDocError(card);
            updateDocCardUI(input);
            if (card && card.getAttribute('data-doc-key') === 'form138') checkReportCard(card);
        });

        // Shared preview modal (image lightbox / PDF viewer) for both the
        // Step 3 upload cards and the Step 4 Review "View" buttons.
        var docPreviewModalEl = document.getElementById('docPreviewModal');
        var docPreviewModal = (docPreviewModalEl && window.bootstrap) ? new bootstrap.Modal(docPreviewModalEl) : null;
        var docPreviewObjectUrl = null;

        function openDocPreview(file, label) {
            var body = document.getElementById('docPreviewBody');
            var meta = document.getElementById('docPreviewMeta');
            var title = document.getElementById('docPreviewTitle');
            if (!body || !meta || !title) return;
            if (docPreviewObjectUrl) { URL.revokeObjectURL(docPreviewObjectUrl); docPreviewObjectUrl = null; }
            var url = URL.createObjectURL(file);
            docPreviewObjectUrl = url;
            title.innerHTML = '<i class="fas fa-file me-2" style="color:var(--primary)"></i>' + label;
            var isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
            var isImage = file.type.indexOf('image/') === 0;
            if (isPdf) {
                body.innerHTML = '<iframe class="preview-modal-frame" src="' + url + '" title="' + label + ' preview"></iframe>';
            } else if (isImage) {
                body.innerHTML = '<img class="preview-modal-img" src="' + url + '" alt="' + label + ' preview">';
            } else {
                body.innerHTML = '<p style="color:#fff;padding:50px 20px;margin:0">Preview is not available for this file type.</p>';
            }
            meta.innerHTML = '<strong>' + file.name + '</strong> &middot; ' + fmtBytes(file.size);
            if (docPreviewModal) docPreviewModal.show();
        }
        if (docPreviewModalEl) {
            docPreviewModalEl.addEventListener('hidden.bs.modal', function() {
                document.getElementById('docPreviewBody').innerHTML = '';
                if (docPreviewObjectUrl) { URL.revokeObjectURL(docPreviewObjectUrl); docPreviewObjectUrl = null; }
            });
        }

        function val(name) {
            var el = document.querySelector('[name="' + name + '"]');
            if (!el) return '';
            if (el.tagName === 'SELECT') {
                return el.options[el.selectedIndex] ? el.options[el.selectedIndex].text : '';
            }
            return el.value.trim();
        }

        function valRaw(name) {
            var el = document.querySelector('[name="' + name + '"]');
            return el ? el.value.trim() : '';
        }

        function populateReview() {
            var gl = document.querySelector('input[name="grade_level"]:checked');
            document.getElementById('revGradeLevel').textContent = gl ? gl.value : 'â€”';

            var strand = document.getElementById('strandSelect');
            var isSHS = gl && (gl.value === 'Grade 11' || gl.value === 'Grade 12');
            document.getElementById('revStrandRow').style.display = isSHS ? 'flex' : 'none';
            document.getElementById('revStrand').textContent = strand && strand.value ? strand.value : 'â€”';

            var ln = val('last_name'), fn = val('first_name'), mn = val('middle_name'), sf = val('suffix');
            var fullName = fn;
            if (mn) fullName += ' ' + mn;
            fullName += ' ' + ln;
            if (sf) fullName += ' ' + sf;
            document.getElementById('revFullName').textContent = fullName.trim() || 'â€”';

            document.getElementById('revLRN').textContent = valRaw('lrn') || 'â€”';
            document.getElementById('revDOB').textContent = valRaw('birth_date') || 'â€”';
            document.getElementById('revGender').textContent = val('gender') !== '-- Select --' ? val('gender') : 'â€”';
            document.getElementById('revContact').textContent = valRaw('contact_number') || 'â€”';
            document.getElementById('revEmail').textContent = valRaw('email') || 'â€”';

            document.getElementById('revAppType').textContent = val('application_type') || 'â€”';
            document.getElementById('revBirthPlace').textContent = valRaw('birth_place') || 'â€”';
            document.getElementById('revReligion').textContent = valRaw('religion') || 'â€”';
            document.getElementById('revNationality').textContent = valRaw('nationality') || 'â€”';
            document.getElementById('revAddress').textContent = valRaw('address') || 'â€”';
            document.getElementById('revFather').textContent = valRaw('father_name') || 'â€”';
            document.getElementById('revMother').textContent = valRaw('mother_name') || 'â€”';
            document.getElementById('revGuardian').textContent = valRaw('guardian_name') || 'â€”';
            document.getElementById('revGuardianRel').textContent = valRaw('guardian_relationship') || 'â€”';
            document.getElementById('revGuardianContact').textContent = valRaw('guardian_contact') || 'â€”';
            var revType = curType();
            document.querySelectorAll('[data-rev-types]').forEach(function(row) {
                row.style.display = row.getAttribute('data-rev-types').split(',').indexOf(revType) !== -1 ? 'flex' : 'none';
            });
            document.getElementById('revSchoolLbl').textContent = revType === 'Transferee' ? 'Previous School' : 'Last School';
            document.getElementById('revSchool').textContent = valRaw('last_school_name') || 'â€”';
            document.getElementById('revSchoolAddr').textContent = valRaw('last_school_address') || 'â€”';
            document.getElementById('revLastGrade').textContent = valRaw('last_grade_completed') || 'â€”';
            document.getElementById('revSchoolYear').textContent = valRaw('last_school_year') || 'â€”';

            // Continuing / Returning: which saved details were changed
            var chgCard = document.getElementById('revChangesCard'), chgList = document.getElementById('revChangesList');
            if (chgCard && chgList) {
                chgList.innerHTML = '';
                if (lookup.status === 'verified') {
                    chgCard.style.display = '';
                    document.getElementById('revChangesTitle').textContent = revType === 'Returning' ? 'Details you updated' : 'Details you changed';
                    var n = 0;
                    PROFILE_FIELDS.forEach(function(name) {
                        var el = fieldEl(name);
                        if (!el) return;
                        var was = (el.getAttribute('data-orig') || '').trim(), now = el.value.trim();
                        if (was === now) return;
                        n++;
                        var row = document.createElement('div');
                        row.className = 'chg-row';
                        row.innerHTML = '<strong></strong><span class="from"></span><i class="fas fa-arrow-right"></i><span class="to"></span>';
                        row.querySelector('strong').textContent = FIELD_LABELS[name] || name;
                        row.querySelector('.from').textContent = was || '(empty)';
                        row.querySelector('.to').textContent = now || '(empty)';
                        chgList.appendChild(row);
                    });
                    if (!n) chgList.innerHTML = '<div class="chg-none"><i class="fas fa-circle-check"></i> No details changed \u2014 everything matches your saved record.</div>';
                } else {
                    chgCard.style.display = 'none';
                }
            }

            var docsDiv = document.getElementById('revDocs');
            docsDiv.innerHTML = '';
            var hasFiles = false;
            var activeGroup = activeDocGroup();
            if (activeGroup) {
                activeGroup.querySelectorAll('.doc-card .doc-file-input').forEach(function(input) {
                    var card = input.closest('.doc-card');
                    var file = input && input.files && input.files[0];
                    if (!file) return;
                    hasFiles = true;
                    var label = card.querySelector('h6').textContent + (input.getAttribute('data-page-label') ? ' â€” ' + input.getAttribute('data-page-label') : '');
                    var isRequired = card.classList.contains('required');
                    var row = document.createElement('div');
                    row.className = 'doc-review-row';
                    row.innerHTML =
                        '<div class="doc-review-icon"><i class="fas fa-file-circle-check"></i></div>' +
                        '<div class="doc-review-info">' +
                            '<div class="doc-review-label">' + label + ' <span class="' + (isRequired ? 'badge-req' : 'badge-opt') + '">' + (isRequired ? 'Required' : 'Optional') + '</span></div>' +
                            '<div class="doc-review-file" title="' + file.name.replace(/"/g, '&quot;') + '">' + file.name + ' &middot; ' + fmtBytes(file.size) + '</div>' +
                        '</div>' +
                        '<button type="button" class="btn-doc btn-doc-view" data-for="' + input.id + '"><i class="fas fa-eye"></i> View</button>';
                    docsDiv.appendChild(row);
                });
            }
            if (!hasFiles) {
                docsDiv.innerHTML = '<span style="color:var(--text-muted);font-size:14.5px">No documents attached.</span>';
            }
        }

        // Form submit â€” inline feedback instead of alert(), plus a loading
        // state that blocks a second click while the request is in flight
        // (the server also only ever creates one application per submit).
        var oeForm = document.getElementById('oeForm');
        var submitBtn = document.getElementById('submitBtn');
        var submitBtnText = document.getElementById('submitBtnText');
        oeForm.addEventListener('submit', function(e) {
            // Belt and braces: never submit unless every step is still complete.
            if (!validateAll(3)) {
                e.preventDefault();
                return false;
            }
            var certify = document.getElementById('certify');
            var consent = document.getElementById('privacyConsent');
            if (!certify.checked || (consent && !consent.checked)) {
                e.preventDefault();
                var certifyBox = document.querySelector('.certify-box');
                if (certifyBox) {
                    certifyBox.style.boxShadow = '0 0 0 2px var(--danger)';
                    certifyBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return false;
            }
            if (submitBtn.classList.contains('is-loading')) {
                e.preventDefault();
                return false;
            }
            submitBtn.classList.add('is-loading');
            submitBtn.disabled = true;
            if (submitBtnText) submitBtnText.textContent = 'Submitting Application...';
        });

        document.getElementById('certify').addEventListener('change', function() {
            document.getElementById('submitBtn').disabled = !this.checked;
            document.querySelector('.certify-box').style.boxShadow = 'none';
        });

        // Initialize
        // If the server sent us back with errors, restore the loaded / verified saved record too.
        var OE_RESTORE = <?php echo json_encode($oe_restore); ?>;
        (function() {
            var t = curType();
            if (!OE_RESTORE || !isLookupType(t)) return;
            var l = fieldEl('lrn').value.trim(), d = fieldEl('birth_date').value;
            vLrn.value = l; vDob.value = (OE_RESTORE.profile && OE_RESTORE.profile.birth_date) || d;
            lookup = { status: OE_RESTORE.status, type: t, lrn: l, profile: OE_RESTORE.profile || null, expectedGrade: '' };
            var rc = document.getElementById('profileReviewed');
            var wasChecked = rc && <?php echo !empty($old['profile_reviewed']) ? 'true' : 'false'; ?>;
            finishLookup(OE_RESTORE.status, t, l, vDob.value, OE_RESTORE.profile || null, true, OE_RESTORE.continuing || null);
            if (rc && wasChecked && !rc.disabled) rc.checked = true;
            showResult(OE_RESTORE.status === 'verified' ? 'ok' : 'info', '<i class="fas fa-circle-check"></i> <span>' + (OE_RESTORE.status === 'verified' ? 'Your saved record is loaded.' : 'No previous record was found for this LRN \u2014 full form.') + '</span>');
            document.getElementById('btnVerifyText').textContent = 'Search again';
        })();
        onGradeChange();
        onTypeChange();
        updateProgress(currentStep);
        // If the server round-tripped us with validation errors, the applicant
        // was on Review & Submit â€” refill the review with their preserved values.
        if (currentStep === 4) {
            populateReview();
        }
    });
    </script>
</body>
</html>