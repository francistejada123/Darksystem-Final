<?php
// includes/teacher_validation.php
// Required fields and format checks for the Add Teacher and Edit Teacher
// forms, and the list of profile fields a teacher record should have
// (shown as "Not set" + a notice on the Teacher Profile page).

const TV_SEX_OPTIONS = ['Male', 'Female', 'Other'];
const TV_EDUC_OPTIONS = ["Bachelor's Degree", "Master's Degree", 'Doctorate', 'Post Graduate Diploma'];
const TV_SPECIALIZATIONS = ['Mathematics', 'English', 'Science', 'Filipino', 'Social Studies', 'MAPEH', 'TLE', 'ICT', 'ESP', 'Values Education', 'Physical Education', 'Music', 'Arts', 'Health', 'Computer', 'Research', 'Statistics', 'General Education'];

/** Profile fields that should not be empty: column => label. */
function tvProfileFields(): array
{
    return [
        'sex' => 'Sex / Gender',
        'contact_number' => 'Contact Number',
        'highest_educ_attainment' => 'Highest Educational Attainment',
        'degree_course' => 'Degree / Course',
        'specialization' => 'Specialization',
        'position_designation' => 'Position / Designation',
    ];
}

/** Labels of the profile fields still empty for this teacher. */
function tvMissingFields(array $teacher): array
{
    $missing = [];
    foreach (tvProfileFields() as $col => $label) {
        if (trim((string) ($teacher[$col] ?? '')) === '') $missing[] = $label;
    }
    return $missing;
}

/** 09XXXXXXXXX (spaces, dashes and +63 are accepted and normalised). */
function tvNormalizeMobile(string $v): string
{
    $d = preg_replace('/[\s\-]/', '', $v);
    if (preg_match('/^\+?63(9\d{9})$/', $d, $m)) $d = '0' . $m[1];
    return $d;
}

/**
 * Checks the posted teacher fields. $mode is 'add' or 'edit' (the edit form
 * also has education, degree, years of experience and PRC license).
 * Returns a list of error messages (empty when everything is valid).
 */
function tvValidate(array $in, string $mode): array
{
    $e = [];
    $plain = function ($k) use ($in) { return trim(html_entity_decode((string) ($in[$k] ?? ''), ENT_QUOTES, 'UTF-8')); };
    $name_re = '/^[\p{L}\p{M}][\p{L}\p{M}\.\-\x27 ]*$/u';

    foreach (['first_name' => 'First name', 'last_name' => 'Last name'] as $k => $label) {
        if ($plain($k) === '') $e[] = "$label is required.";
        elseif (!preg_match($name_re, $plain($k))) $e[] = "$label must contain letters only (spaces, - and ' are allowed).";
    }
    if ($plain('middle_name') !== '' && !preg_match($name_re, $plain('middle_name'))) {
        $e[] = "Middle name must contain letters only (spaces, - and ' are allowed).";
    }
    if (!in_array($plain('sex'), TV_SEX_OPTIONS, true)) $e[] = 'Sex / Gender is required.';

    $mobile = tvNormalizeMobile($plain('contact_number'));
    if ($mobile === '') $e[] = 'Contact number is required.';
    elseif (!preg_match('/^09\d{9}$/', $mobile)) $e[] = 'Contact number must be a valid mobile number (09XXXXXXXXX).';

    if (!in_array($plain('specialization'), TV_SPECIALIZATIONS, true)) $e[] = 'Specialization is required.';

    $pos = $plain('position_designation');
    if ($pos === '') $e[] = 'Position / Designation is required.';
    elseif (mb_strlen($pos) < 2 || mb_strlen($pos) > 100) $e[] = 'Position / Designation must be 2 to 100 characters.';

    if ($mode === 'edit') {
        if (!in_array($plain('highest_educ_attainment'), TV_EDUC_OPTIONS, true)) $e[] = 'Highest educational attainment is required.';
        // Department / Level is required in both modes: it decides which classes
        // this teacher may ever be assigned to (JHS -> Grade 7-10, SHS -> 11-12).
        if (!in_array($plain('department_level'), ['JHS', 'SHS'], true)) $e[] = 'Department / Level is required (JHS or SHS).';
        $deg = $plain('degree_course');
        if ($deg === '') $e[] = 'Degree / Course is required.';
        elseif (mb_strlen($deg) < 3 || mb_strlen($deg) > 150) $e[] = 'Degree / Course must be 3 to 150 characters.';
        $yrs = $plain('years_experience');
        if ($yrs === '' || !ctype_digit($yrs) || (int) $yrs > 50) $e[] = 'Years of experience must be a whole number from 0 to 50.';
        $prc = $plain('prc_license_number');
        if ($prc !== '' && !preg_match('/^\d{6,10}$/', $prc)) $e[] = 'PRC license number must be 6 to 10 digits.';
    } else {
        if (!in_array($plain('department_level'), ['JHS', 'SHS'], true)) $e[] = 'Department / Level is required (JHS or SHS).';
    }
    return $e;
}
