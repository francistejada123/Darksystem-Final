<?php
// modules/enrollment/grading.php
// ============================================================
// RETIRED PAGE — REDIRECT ONLY
// ============================================================
// This used to be a SECOND grade-encoding page with its own manual
// WW/PT/QA computation and its own browser-side formula. It was removed
// in the DepEd Three-Term cleanup (SY 2026-2027) because a second
// grading implementation can silently disagree with the official one.
//
// There is now exactly ONE grading engine:
//     includes/grading_helpers.php
// and exactly ONE encoding page:
//     modules/grading/index.php
//
// Do not restore a manual computation here. Any page that needs a grade
// must call getSubjectWeights() / getSubjectWeightsForStudent() and
// computeTermGrade() instead of implementing its own formula.
// ============================================================

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

// Preserve the drill-down the user was on, so an old bookmark still
// lands them in the right place in the real gradebook.
$params = [];
foreach (['section_id', 'subject_code', 'term', 'grade'] as $key) {
    if (isset($_GET[$key]) && $_GET[$key] !== '') {
        $params[$key] = $_GET[$key];
    }
}
if (!empty($params['section_id']) && !empty($params['subject_code'])) {
    $params['step'] = 5;
}

$target = BASE_URL . 'modules/grading/index.php';
if (!empty($params)) {
    $target .= '?' . http_build_query($params);
}

header('Location: ' . $target, true, 301);
exit;
