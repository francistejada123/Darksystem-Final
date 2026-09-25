<?php
// includes/grading_helpers.php
// ============================================================
// CENTRAL GRADING ENGINE — DepEd SY 2026-2027
// ============================================================
// Rule sources implemented here:
//   * DO 009, s. 2026 — Three-Term School Calendar
//   * DO 015, s. 2026 — Revised Guidelines on Classroom Assessment,
//                       Grading System, and Awards and Recognition
//   * DO 8, s. 2015    — COMPONENT WEIGHTS ONLY, and ONLY for Grade 12
//                        learners still under the legacy SHS curriculum,
//                        as allowed by the DO 015 transition rule.
//   * Strengthened SHS implementation rules (Grade 11, and Grade 12 pilot)
//
// EVERY grading formula in this system must come from this file.
// Do not re-implement weights, transmutation, descriptors or pass/fail
// logic in any page — call these helpers instead.
//
// ------------------------------------------------------------
// STORAGE NOTES (2026 cleanup)
// ------------------------------------------------------------
// 1. The four-quarter grading structure has been REMOVED from this
//    system. There is no legacy path, no fallback formula and no
//    Quarter transmutation table. Term 1 / Term 2 / Term 3 are the
//    only grading periods that exist, and the database enforces that
//    with CHECK constraints on `grades`, `grade_components` and
//    `assessment_items`.
// 2. The EXAMINATIONS (EX) percentage is stored in
//    `grades.examinations` and `grade_components.examinations`.
//    (`grades.quarterly_assessment` was renamed by the 2026 cleanup
//    migration; nothing in this system writes the old name.)
// 3. `assessment_items.category` still uses the two-letter storage
//    code 'QA' for the Examinations category. It is an INTERNAL key
//    only — it is never shown to a user, and every label comes from
//    gh_examComponentLabel(). Renaming that enum would ripple through
//    the item editor, the score map and the browser preview for no
//    user-visible benefit, so it is deliberately left alone.
// ============================================================

// ============================================================
// SECTION 1 — SCHOOL YEAR / GRADING STRUCTURE
// ============================================================

/**
 * Resolve a school-year row from an id, a row, or the active SY.
 * @param array|int|null $sy
 * @return array|null
 */
function gh_resolveSchoolYear($sy = null) {
    static $cache = [];

    if (is_array($sy)) {
        return $sy;
    }

    $key = ($sy === null) ? '__active__' : (string)(int)$sy;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    if ($sy === null) {
        $row = getRecord("SELECT * FROM school_years WHERE is_active = 1");
    } else {
        $row = getRecord("SELECT * FROM school_years WHERE sy_id = ?", [(int)$sy]);
    }

    $cache[$key] = $row ? $row : null;
    return $cache[$key];
}

/**
 * The three official grading periods (DO 009 / DO 015, s. 2026).
 * This is the single source of truth for grading-period labels.
 * @return array
 */
function gh_threeTerms() {
    return ['Term 1', 'Term 2', 'Term 3'];
}

/**
 * Grading structure for a school year. Always 'term' — the four-quarter
 * structure was removed in the 2026 cleanup and cannot be re-enabled.
 * Retained so existing callers keep working.
 * @param array|int|null $sy
 * @return string
 */
function getGradingStructure($sy = null) {
    $row = gh_resolveSchoolYear($sy);
    $stored = $row['grading_structure'] ?? 'term';
    if ($stored !== 'term') {
        // Should be impossible: the column is enum('term') after the
        // cleanup migration. Flag it rather than quietly honouring it.
        gh_addConfigNotice(
            'grading_structure',
            'School year "' . ($row['sy_name'] ?? 'unknown') . '" has an unsupported grading structure ("'
            . $stored . '"). This system only supports the DepEd Three-Term structure. Re-run the 2026 '
            . 'cleanup migration.'
        );
    }
    return 'term';
}

/**
 * Grading structure of the ACTIVE school year. Retained for existing callers.
 * @return string
 */
function getActiveGradingStructure() {
    return getGradingStructure(null);
}

/**
 * Valid grading-period labels. Retained for existing callers.
 * @param string $structure ignored — only the Three-Term structure exists
 * @return array
 */
function getTermsForStructure($structure = 'term') {
    return gh_threeTerms();
}

/**
 * Valid grading-period labels for a school year.
 * @param array|int|null $sy
 * @return array
 */
function getTermsForSchoolYear($sy = null) {
    return gh_threeTerms();
}

/**
 * Valid grading-period labels for the ACTIVE school year:
 * ['Term 1', 'Term 2', 'Term 3'].
 * @return array
 */
function getActiveTerms() {
    return gh_threeTerms();
}

/**
 * Default grading period used when a page does not specify one.
 * @return string
 */
function getDefaultTerm() {
    $terms = getActiveTerms();
    return $terms[0];
}

/**
 * SERVER-SIDE grading-period validation. This is the authoritative check;
 * the browser is never trusted. Anything that is not Term 1, Term 2 or
 * Term 3 is rejected, and the database CHECK constraint rejects it again
 * underneath this.
 * @param string $term
 * @return bool
 */
function isValidTerm($term) {
    return in_array($term, gh_threeTerms(), true);
}

/**
 * Always true — this system is Three-Term only. Retained for callers.
 * @param array|int|null $sy
 * @return bool
 */
function isThreeTermYear($sy = null) {
    return true;
}

/**
 * User-facing label for the third grading component.
 * @param array|int|null $sy
 * @return string
 */
function gh_examComponentLabel($sy = null) {
    return 'Examinations';
}

/**
 * Short user-facing label for the third grading component.
 * @param array|int|null $sy
 * @return string
 */
function gh_examComponentShortLabel($sy = null) {
    return 'EX';
}

/**
 * Label for one grading period's reported grade. A single grading period
 * is a TERM GRADE — never a "Final Grade".
 * @param array|int|null $sy
 * @return string
 */
function gh_periodGradeLabel($sy = null) {
    return 'Term Grade';
}

/**
 * SQL fragment restricting a `grades` query to records that may legally
 * feed an average, a passing rate, a risk level, a tutorial plan or a
 * Final Grade.
 *
 * Excluded: any non-Term grading period, any record still marked
 * Incomplete or Not configured, and any row whose transmuted grade is
 * below the transmutation floor of 60 (i.e. never actually computed).
 *
 * Every grading-related query in the system uses this so the rule is
 * defined in exactly one place.
 *
 * @param string $alias table alias used in the query ('' for none)
 * @return string SQL, safe to concatenate — contains no user input
 */
function gh_validTermSql($alias = 'g') {
    $a = ($alias === '' || $alias === null) ? '' : $alias . '.';
    $terms = "'" . implode("', '", gh_threeTerms()) . "'";
    return "{$a}term IN ($terms)"
         . " AND ({$a}remarks IS NULL OR {$a}remarks NOT IN ('Incomplete', 'Not configured', 'Draft'))"
         . " AND {$a}transmuted_grade >= 60";
}

/**
 * SQL fragment for pages that LIST a learner's encoded grades.
 *
 * This is the DISPLAY counterpart of gh_validTermSql(). It accepts any
 * Term 1-3 record the teacher has actually saved, INCLUDING one still
 * marked Incomplete, so a grade that was encoded is never invisible to
 * the teacher, the adviser or the learner.
 *
 * The one thing it still excludes is 'Draft' -- a category percentage the
 * teacher typed into Encode Grades WHILE the term is still ongoing (before
 * clicking "Finalize"), auto-saved so it survives a page reload. A Draft
 * row carries no real Initial Grade / Term Grade yet (they are stored as
 * 0), so it is never "encoded" in the sense this helper means -- showing
 * it would look like a failing grade the learner never actually received.
 *
 * It must NEVER be used for a computation. Averages, passing rates, risk
 * levels, tutorial generation and Final Grades all keep using
 * gh_validTermSql(), which excludes both Incomplete and Draft records.
 *
 * @param string $alias table alias used in the query ('' for none)
 * @return string SQL, safe to concatenate — contains no user input
 */
function gh_encodedTermSql($alias = 'g') {
    $a = ($alias === '' || $alias === null) ? '' : $alias . '.';
    $terms = "'" . implode("', '", gh_threeTerms()) . "'";
    return "{$a}term IN ($terms) AND ({$a}remarks IS NULL OR {$a}remarks != 'Draft')";
}

/**
 * The same rule as gh_validTermSql(), applied in PHP to a `grades` row.
 * @param array $row
 * @return bool
 */
function gh_isValidGradeRow($row) {
    if (!is_array($row)) return false;
    if (!in_array($row['term'] ?? '', gh_threeTerms(), true)) return false;
    $remarks = $row['remarks'] ?? null;
    if (in_array($remarks, ['Incomplete', 'Not configured', 'Draft'], true)) return false;
    $tg = $row['transmuted_grade'] ?? $row['final_grade'] ?? null;
    return $tg !== null && $tg !== '' && (float)$tg >= 60;
}

// ============================================================
// SECTION 2 — CONFIGURATION NOTICES
// ============================================================
// Where the rules require information the database cannot supply
// (SHS subject category, Grade 12 curriculum type, equivalent units,
// ESP/Values mapping), we FLAG it instead of inventing a formula.

/**
 * Record a configuration gap for display to the user.
 * @param string $code
 * @param string $message
 */
function gh_addConfigNotice($code, $message) {
    if (!isset($GLOBALS['gh_config_notices'])) {
        $GLOBALS['gh_config_notices'] = [];
    }
    $GLOBALS['gh_config_notices'][$code] = $message;
}

/**
 * All configuration gaps collected during this request.
 * @return array code => message
 */
function gh_getConfigNotices() {
    return $GLOBALS['gh_config_notices'] ?? [];
}

// ============================================================
// SECTION 3 — GRADE LEVEL / KEY STAGE
// ============================================================

/**
 * Numeric grade level from 'Grade 7', '7', 'grade 11' etc.
 * @param string|int|null $grade_level
 * @return int|null
 */
function gh_gradeLevelNumber($grade_level) {
    if ($grade_level === null || $grade_level === '') return null;
    if (preg_match('/(\d+)/', (string)$grade_level, $m)) {
        return (int)$m[1];
    }
    return null;
}

/**
 * Key stage for a grade level: 'ks2' (4-6), 'ks3' (7-10), 'ks4' (11-12).
 * @param string|int|null $grade_level
 * @return string|null
 */
function gh_keyStage($grade_level) {
    $n = gh_gradeLevelNumber($grade_level);
    if ($n === null) return null;
    if ($n >= 4 && $n <= 6) return 'ks2';
    if ($n >= 7 && $n <= 10) return 'ks3';
    if ($n >= 11 && $n <= 12) return 'ks4';
    return null;
}

/**
 * Is this grade level Senior High School (Grade 11 / 12)?
 * @param string|int|null $grade_level
 * @return bool
 */
function gh_isSeniorHigh($grade_level) {
    return gh_keyStage($grade_level) === 'ks4';
}

// ============================================================
// SECTION 4 — SHS CURRICULUM TYPE
// ============================================================
// SY 2026-2027: BOTH Grade 11 and Grade 12 use the Strengthened SHS
// component weights.
//
// This was verified cell-by-cell against the OFFICIAL DepEd Three-Term
// SSHS E-Class Record for SY 2026-2027. Its HELPER sheet lists 139
// courses tagged for grade level '11', '11, 12' or 'gr 12', and every
// one of them draws from the same six Strengthened SHS weight sets.
// No course uses the DO 8, s. 2015 weights.
//
// Grade 12 encoding is therefore NO LONGER BLOCKED on a curriculum-type
// setting. The functions below are retained so that any stored
// curriculum_type value on a student or section row is still readable,
// but they no longer change how a grade is computed.

/**
 * Valid curriculum types. Only the Strengthened SHS curriculum is
 * recognised for SY 2026-2027.
 * @return array
 */
function gh_curriculumTypeOptions() {
    return [
        'strengthened_shs' => 'Strengthened SHS Curriculum',
    ];
}

// gh_defaultCurriculumType() has been DELETED. It read the school-wide
// 'shs_default_curriculum_type' setting to decide between the legacy and
// Strengthened SHS weights. Both Grade 11 and Grade 12 now use Strengthened
// SHS, so there is nothing left for it to decide and it had no callers.

/**
 * Resolve the curriculum type that applies to a learner.
 * Grade 11 in SY 2026-2027 is ALWAYS Strengthened SHS.
 * Grade 12 must be configured — this returns null when it is not.
 *
 * @param int|null    $student_id
 * @param string|null $grade_level
 * @param int|null    $section_id
 * @return string|null 'strengthened_shs' for SHS, null otherwise
 */
function getLearnerCurriculumType($student_id = null, $grade_level = null, $section_id = null) {
    $n = gh_gradeLevelNumber($grade_level);

    // Not SHS — curriculum type is not applicable.
    if ($n !== null && $n < 11) {
        return null;
    }

    // Grade 11 AND Grade 12 in SY 2026-2027 are both Strengthened SHS.
    // Nothing needs configuring and nothing is guessed.
    return 'strengthened_shs';
}

// ============================================================
// SECTION 5 — SUBJECT CATEGORY DEFINITIONS AND WEIGHTS
// ============================================================
// One row per official category. 'exam_mode' controls how the EX
// component is built:
//   'standard'       -> ST1 30% + ST2 30% + TE 40% (of EX)
//   'term_exam_only' -> EX = Term Examination percentage score only
//   'none'           -> no EX component at all
// 'domains' marks the GMRC / Values Education categories that must keep
// their Cognitive / Affective / Behavioral breakdown.

/**
 * All grading categories with their official component weights.
 * @return array
 */
function gh_categoryDefinitions() {
    return [
        // ---- Key Stage 2 / Key Stage 3 (Grades 4-10), DO 015 s. 2026 ----
        'ks_standard' => [
            'label'  => 'Core Learning Area (English, Filipino, Math, Science, AP)',
            'group'  => 'Grades 4-10',
            'ww' => 0.20, 'pt' => 0.50, 'ex' => 0.30,
            'exam_mode' => 'standard', 'domains' => false,
            'stage' => 'ks', 'rule' => 'DO 015, s. 2026',
        ],
        'ks_epp_tle_mapeh' => [
            'label'  => 'EPP / TLE (single component)',
            'group'  => 'Grades 4-10',
            'ww' => 0.20, 'pt' => 0.60, 'ex' => 0.20,
            'exam_mode' => 'standard', 'domains' => false,
            'stage' => 'ks', 'rule' => 'DO 015, s. 2026',
        ],
        // MAPEH carries the SAME prescribed weights as EPP/TLE, but they are
        // applied to EACH component separately — never to one pooled set of
        // scores. The official ECR splits MAPEH into TWO component sheets.
        'ks_mapeh' => [
            'label'  => 'MAPEH (Music / Arts / Physical Education / Health)',
            'group'  => 'Grades 4-10',
            'ww' => 0.20, 'pt' => 0.60, 'ex' => 0.20,
            'exam_mode' => 'standard', 'domains' => false,
            'stage' => 'ks', 'rule' => 'DO 015, s. 2026',
        ],
        'ks_gmrc' => [
            'label'  => 'GMRC (Key Stage 2)',
            'group'  => 'Grades 4-10',
            'ww' => 0.20, 'pt' => 0.50, 'ex' => 0.30,
            'exam_mode' => 'standard', 'domains' => true,
            'stage' => 'ks', 'rule' => 'DO 015, s. 2026',
        ],
        'ks_values_education' => [
            'label'  => 'Values Education (Key Stage 3)',
            'group'  => 'Grades 4-10',
            'ww' => 0.20, 'pt' => 0.50, 'ex' => 0.30,
            'exam_mode' => 'standard', 'domains' => true,
            'stage' => 'ks', 'rule' => 'DO 015, s. 2026',
        ],

        // ---- Strengthened SHS (Grade 11 always; Grade 12 pilot cohorts) ----
        'sshs_core' => [
            'label'  => 'SHS Core Subject / Other SHS Academic Elective',
            'group'  => 'Strengthened SHS',
            'ww' => 0.20, 'pt' => 0.50, 'ex' => 0.30,
            'exam_mode' => 'standard', 'domains' => false,
            'stage' => 'sshs', 'rule' => 'Strengthened SHS',
        ],
        'sshs_field_exposure' => [
            'label'  => 'Field Exposure / Arts Apprenticeship / Creative Production and Innovation',
            'group'  => 'Strengthened SHS',
            'ww' => 0.15, 'pt' => 0.70, 'ex' => 0.15,
            'exam_mode' => 'term_exam_only', 'domains' => false,
            'stage' => 'sshs', 'rule' => 'Strengthened SHS',
        ],
        'sshs_arts_sports_health' => [
            'label'  => 'Arts / Sports / Health and Wellness Elective',
            'group'  => 'Strengthened SHS',
            'ww' => 0.20, 'pt' => 0.60, 'ex' => 0.20,
            'exam_mode' => 'standard', 'domains' => false,
            'stage' => 'sshs', 'rule' => 'Strengthened SHS',
        ],
        'sshs_research_design' => [
            'label'  => 'Research Elective / Design and Innovation',
            'group'  => 'Strengthened SHS',
            'ww' => 0.40, 'pt' => 0.60, 'ex' => 0.00,
            'exam_mode' => 'none', 'domains' => false,
            'stage' => 'sshs', 'rule' => 'Strengthened SHS',
        ],
        'sshs_techpro' => [
            'label'  => 'TechPro Elective',
            'group'  => 'Strengthened SHS',
            'ww' => 0.15, 'pt' => 0.65, 'ex' => 0.20,
            'exam_mode' => 'standard', 'domains' => false,
            'stage' => 'sshs', 'rule' => 'Strengthened SHS',
        ],
        'sshs_work_immersion' => [
            'label'  => 'Work Immersion',
            'group'  => 'Strengthened SHS',
            'ww' => 0.20, 'pt' => 0.80, 'ex' => 0.00,
            'exam_mode' => 'none', 'domains' => false,
            'stage' => 'sshs', 'rule' => 'Strengthened SHS',
        ],

        // ---- NOTE ON GRADE 12 -------------------------------------
        // The four legacy DO 8, s. 2015 SHS categories that used to sit
        // here have been DELETED. Verified against the OFFICIAL DepEd
        // Three-Term SSHS E-Class Record for SY 2026-2027: of the 139
        // courses in its HELPER sheet — which cover Grade 11 AND Grade 12
        // alike — NONE uses 25/50/25, 25/45/30 or 35/40/25. Grade 12 uses
        // the same Strengthened SHS weights as Grade 11.
    ];
}

/**
 * Categories offered in the admin Subject form, grouped for the dropdown.
 * @return array group => [key => label]
 */
function getSubjectCategoryOptions() {
    $grouped = [];
    foreach (gh_categoryDefinitions() as $key => $def) {
        $grouped[$def['group']][$key] = $def['label'];
    }
    return $grouped;
}

/**
 * Which family of grading categories a grade level may use.
 *   Grades 4-10  -> 'ks'   (Key Stage 2 / 3 categories)
 *   Grades 11-12 -> 'sshs' (Strengthened SHS categories)
 *   anything else / blank -> null (no restriction)
 * @param string|int|null $grade_level
 * @return string|null
 */
function gh_categoryStageForGradeLevel($grade_level) {
    $n = gh_gradeLevelNumber($grade_level);
    if ($n === null) return null;
    if ($n >= 11 && $n <= 12) return 'sshs';
    if ($n >= 4 && $n <= 10)  return 'ks';
    return null;
}

/**
 * Does a grading category belong to the family allowed for a grade level?
 * A blank category, an unknown category, or an unknown grade level is not
 * treated as a mismatch here — callers decide whether blank is acceptable.
 * @param string|null     $category
 * @param string|int|null $grade_level
 * @return bool
 */
function gh_categoryMatchesGradeLevel($category, $grade_level) {
    $stage = gh_categoryStageForGradeLevel($grade_level);
    if ($stage === null || $category === null || $category === '') return true;
    $defs = gh_categoryDefinitions();
    if (!isset($defs[$category])) return true;
    return $defs[$category]['stage'] === $stage;
}

/**
 * Only Written/Oral Works and Examinations may be fed by AI-assisted activities
 * (AI quizzes and AI-checked activity submissions). Performance Tasks (PETA,
 * projects, group performance/output, demonstrations, portfolios, ...) are
 * observed in the classroom and are encoded and computed manually by the
 * teacher — the AI never records or computes them.
 * @param string|null $category assessment_items.category: 'WW' | 'PT' | 'QA'
 * @return bool
 */
function gh_isAiAssistedCategory($category) {
    return in_array((string)$category, ['WW', 'QA'], true);
}

// ------------------------------------------------------------
// WHAT GOES WHERE (DO 015, s. 2026)
// ------------------------------------------------------------
// The three official grading components are Written/Oral Works,
// Product/Performance Tasks and Examinations. Not every classroom activity
// is a Performance Task:
//   * Recitation and other spoken responses           -> Written/Oral Works
//   * Quizzes, written activities, short/structured
//     reports                                         -> Written/Oral Works
//   * PETA, projects, group performance/output,
//     demonstrations, portfolios, performance-based
//     presentations/reporting (output + rubric)       -> Performance Tasks
//   * ST1, ST2, Term Examination                      -> Examinations
//   * Attendance is NOT a grading component at all; it lives in the
//     Attendance module (record + risk monitoring) and never changes a grade.
// The item categories keep their storage keys ('WW' / 'PT' / 'QA'), so no
// existing item, score or grade is affected by any of this.

/**
 * Suggested names for Written/Oral Works items (type-ahead hints only —
 * free text is still accepted).
 * @return array
 */
function gh_writtenWorkSuggestions() {
    return [
        'Quiz', 'Written Activity', 'Recitation', 'Oral Activity',
        'Short Report', 'Structured Report',
    ];
}

/**
 * Suggested names for Performance Task items (type-ahead hints only — free
 * text is still accepted).
 * @return array
 */
function gh_performanceTaskSuggestions() {
    return [
        'PETA', 'Project', 'Group Performance', 'Group Output',
        'Performance-based Presentation', 'Demonstration', 'Portfolio',
    ];
}

/**
 * Plain-language guide for each component, used by the grading screens so the
 * wording is defined once. 'ATT' is not a component; it is here so the
 * screens can say where attendance lives.
 * @return array keyed 'WW' | 'PT' | 'QA' | 'ATT'
 */
function gh_componentGuide() {
    return [
        'WW' => [
            'label'    => 'Written/Oral Works',
            'examples' => 'Quiz, written activity, recitation, oral activity, short/structured report',
            'note'     => 'Recitation and other spoken responses belong here, not in Performance Tasks.',
        ],
        'PT' => [
            'label'    => 'Performance Tasks',
            'examples' => 'PETA, project, group performance/output, performance-based presentation or reporting, demonstration, portfolio',
            'note'     => 'Only tasks with a product, performance or output scored by a rubric. Teacher-encoded; the AI does not compute them.',
        ],
        'QA' => [
            'label'    => 'Examinations',
            'examples' => 'Summative Test 1, Summative Test 2, Term Examination',
            'note'     => 'ST1 30% + ST2 30% + Term Exam 40% before the subject\'s Exam weight is applied.',
        ],
        'ATT' => [
            'label'    => 'Attendance',
            'examples' => '',
            'note'     => 'Attendance is not a grading component. It is recorded in the Attendance module (record and risk monitoring) and never changes a grade.',
        ],
    ];
}

/**
 * Sanity-check an item name against the component it is being filed under.
 *
 * Returns null when the name is fine, or an array:
 *   ['type' => 'attendance', 'message' => ...]   -> must NOT be a grading item
 *   ['type' => 'misfiled',   'message' => ...,
 *    'suggested' => 'WW'|'PT']                    -> allowed, but probably in the wrong component
 * Names that are genuinely ambiguous (Reporting, Presentation, Group Activity)
 * are never flagged: whether they are WW or PT depends on the task itself.
 *
 * @param string $category 'WW' | 'PT' | 'QA'
 * @param string $name
 * @return array|null
 */
function gh_activityNameHint($category, $name) {
    // Item names are stored HTML-escaped (sanitize()); decode for matching and for the message.
    $name = trim(html_entity_decode((string)$name, ENT_QUOTES, 'UTF-8'));
    if ($name === '') return null;
    $guide = gh_componentGuide();

    if (preg_match('/\b(attendance|absent|absences?|tardy|tardiness)\b/i', $name)) {
        return ['type' => 'attendance', 'message' => $guide['ATT']['note']];
    }

    $ww = preg_match('/\b(recitation|oral|quiz(zes)?|seatwork|written|short report|structured report)\b/i', $name);
    $pt = preg_match('/\b(peta|project|demonstration|demo|portfolio|group performance|group output|performance task)\b/i', $name);
    if ($ww && $pt) return null;   // mixed wording — leave it to the teacher

    if ($category === 'PT' && $ww) {
        return ['type' => 'misfiled', 'suggested' => 'WW', 'message' =>
            '"' . $name . '" is normally counted under Written/Oral Works (quizzes, written activities, recitation and other oral responses), not Performance Tasks.'];
    }
    if ($category === 'WW' && $pt) {
        return ['type' => 'misfiled', 'suggested' => 'PT', 'message' =>
            '"' . $name . '" looks like a Performance Task (a project, demonstration, portfolio or other output scored by a rubric), not Written/Oral Works.'];
    }
    return null;
}

/**
 * The GMRC / Values Education domain weight map (DO 015, s. 2026).
 * These six weights sum to 1.00 and REPLACE the flat WW/PT split for
 * those two learning areas. The overall 20/50/30 shape is preserved:
 *   WW total = 0.10 + 0.10 = 0.20
 *   PT total = 0.10 + 0.10 + 0.30 = 0.50
 *   EX total = 0.30
 * @return array
 */
function gh_domainWeights() {
    return [
        'ww_cognitive'  => ['label' => 'Written/Oral Works — Cognitive',    'component' => 'WW', 'domain' => 'Cognitive',  'weight' => 0.10],
        'ww_affective'  => ['label' => 'Written/Oral Works — Affective',    'component' => 'WW', 'domain' => 'Affective',  'weight' => 0.10],
        'pt_cognitive'  => ['label' => 'Performance Tasks — Cognitive',     'component' => 'PT', 'domain' => 'Cognitive',  'weight' => 0.10],
        'pt_affective'  => ['label' => 'Performance Tasks — Affective',     'component' => 'PT', 'domain' => 'Affective',  'weight' => 0.10],
        'pt_behavioral' => ['label' => 'Performance Tasks — Behavioral',    'component' => 'PT', 'domain' => 'Behavioral', 'weight' => 0.30],
        'ex'            => ['label' => 'Examinations',                      'component' => 'QA', 'domain' => null,         'weight' => 0.30],
    ];
}

/**
 * Valid assessment domain values.
 * @return array
 */
function gh_domainOptions() {
    return ['Cognitive' => 'Cognitive', 'Affective' => 'Affective', 'Behavioral' => 'Behavioral'];
}

/**
 * Valid examination subtypes and their share of the EX component.
 * @return array
 */
function gh_examSubtypes() {
    return [
        'ST1' => ['label' => 'Summative Test 1', 'weight' => 0.30],
        'ST2' => ['label' => 'Summative Test 2', 'weight' => 0.30],
        'TE'  => ['label' => 'Term Examination', 'weight' => 0.40],
    ];
}

// ============================================================
// SECTION 5b — MULTI-COMPONENT LEARNING AREAS (MAPEH, etc.)
// ============================================================
// Some learning areas are officially made up of several components that
// are each graded in their own right. MAPEH is the standing example:
// Music, Arts, Physical Education and Health.
//
// RULES (DO 015, s. 2026):
//   * Component raw scores are NEVER pooled into one WW/PT/EX set.
//   * EACH component is computed with the prescribed weights for the
//     learning area (MAPEH: WW 20% / PT 60% / EX 20%) and transmuted on
//     its own, so it has its own reported component grade.
//   * The learning area's Term Grade is the AVERAGE of the component
//     grades, rounded to a whole number.
//   * Pass/fail is decided on that overall grade ONLY. A learner may be
//     below 75 in one component and still pass the learning area when the
//     overall grade is 75 or higher.
//
// WE DO NOT INVENT COMPONENT STRUCTURES. A subject is multi-component
// only when its components are known:
//   1. subjects.components — comma-separated official component names,
//      set by the administrator (this is how Mabisang Komunikasyon /
//      Effective Communication, or any other learning area DepEd defines
//      as multi-component, is supported); or
//   2. the subject is categorised as MAPEH, whose four components are
//      fixed by DepEd and are therefore safe to apply.
// Anything else is treated as an ordinary single-component subject.

/**
 * The officially fixed component sets we are willing to apply without
 * per-subject configuration. Keyed by grading category.
 * @return array
 */
function gh_fixedComponentSets() {
    return [
        // MAPEH — FOUR separate components (the school's choice): Music,
        // Arts, Physical Education and Health. Each is weighted 20/60/20 and
        // transmuted on its own; the learning area's Term Grade is the
        // AVERAGE of the component grades. Items saved earlier under the old
        // two-component tags still count -- see gh_effectiveComponents().
        'ks_mapeh' => ['Music', 'Arts', 'Physical Education', 'Health'],
    ];
}

/**
 * Older component tags that may still be on saved items (MAPEH used to be
 * graded as two components: Music and Arts / PE and Health).
 * @return array
 */
function gh_legacyComponentNames() {
    return ['Music and Arts', 'PE and Health'];
}

/**
 * The components a class/term is actually graded on, given its items.
 *
 *  - No item carries an old tag  -> the current list (all required).
 *  - Only old tags are used       -> the old two-component list, so terms
 *                                    encoded/finalized before the change
 *                                    compute exactly as they did.
 *  - Both old and new tags        -> the current list plus each old tag
 *                                    that still has items (so no saved
 *                                    score is ever dropped).
 *
 * @param array $components the subject's current component list
 * @param array $items      ['WW'=>[], 'PT'=>[], 'QA'=>[]]
 * @return array
 */
function gh_effectiveComponents($components, $items) {
    $components = array_values((array)$components);
    if (count($components) < 2) return $components;
    $legacy = array_values(array_diff(gh_legacyComponentNames(), $components));
    if (empty($legacy)) return $components;

    $used = [];
    foreach (['WW', 'PT', 'QA'] as $cat) {
        foreach ((array)($items[$cat] ?? []) as $item) {
            $c = (string)($item['component'] ?? '');
            if ($c !== '') $used[$c] = true;
        }
    }
    $legacy_used = array_values(array_filter($legacy, function ($c) use ($used) { return isset($used[$c]); }));
    if (empty($legacy_used)) return $components;

    $current_used = array_filter($components, function ($c) use ($used) { return isset($used[$c]); });
    if (empty($current_used)) return $legacy;

    return array_merge($components, $legacy_used);
}

/**
 * Normalise a stored components string into a clean list.
 * @param string|null $raw comma-separated
 * @return array
 */
function gh_parseComponents($raw) {
    if ($raw === null || trim((string)$raw) === '') return [];
    $parts = array_map('trim', explode(',', (string)$raw));
    $parts = array_values(array_filter($parts, function ($p) { return $p !== ''; }));
    return array_values(array_unique($parts));
}

/**
 * The component list for a subject, or [] when it is an ordinary
 * single-component learning area.
 *
 * Resolution order:
 *   1. subjects.components (administrator-configured, any learning area)
 *   2. the fixed set for the subject's category (MAPEH only)
 *   3. [] — single component
 *
 * @param array|string|null $subject  subjects row or subject_code
 * @param string|null       $category grading category key (optional)
 * @return array
 */
function gh_subjectComponents($subject = null, $category = null) {
    $row = null;
    if (is_array($subject)) {
        $row = $subject;
    } elseif (is_string($subject) && $subject !== '') {
        $row = gh_loadSubject($subject);
    }

    // 1. Explicit per-subject configuration wins.
    if ($row !== null && array_key_exists('components', $row)) {
        $configured = gh_parseComponents($row['components']);
        if (!empty($configured)) return $configured;
    }

    // 2. Fixed official set for the category.
    if ($category === null && $row !== null) {
        $category = trim((string)($row['subject_category'] ?? ''));
        if ($category === '') {
            $category = gh_deriveKeyStageCategory((string)($row['subject_type'] ?? ''));
        }
    }
    $fixed = gh_fixedComponentSets();
    if ($category !== null && isset($fixed[$category])) {
        return $fixed[$category];
    }

    // 3. Ordinary single-component learning area.
    return [];
}

/**
 * Is this subject an officially multi-component learning area?
 * @param array|string|null $subject
 * @param string|null       $category
 * @return bool
 */
function gh_isMultiComponent($subject = null, $category = null) {
    return count(gh_subjectComponents($subject, $category)) > 1;
}

/**
 * Filter a list of assessment items down to one component.
 * Items with no component tag belong to no component and are excluded —
 * they must be tagged before they can count, otherwise an untagged score
 * would silently be pooled across every component, which is exactly what
 * the rule forbids.
 * @return array
 */
function gh_itemsForComponent($items, $component) {
    $out = [];
    foreach ((array)$items as $item) {
        if ((string)($item['component'] ?? '') === (string)$component) {
            $out[] = $item;
        }
    }
    return $out;
}

/**
 * Compute every component grade for a multi-component learning area, then
 * the overall Term Grade as their average.
 *
 * Each component is computed and TRANSMUTED independently, so a component
 * can sit below 75 without dragging the whole learning area down — the
 * overall grade is what decides pass/fail.
 *
 * @param array $weights     from getSubjectWeights() — the learning area's
 *                           prescribed weights, applied to EACH component
 * @param array $components  component names
 * @param array $items       ['WW'=>[], 'PT'=>[], 'QA'=>[]] all items for the class
 * @param array $scores_map  from getAssessmentScoresMap()
 * @param int   $student_id
 * @param array|int|null $sy
 * @return array {
 *     components      array component => [initial_grade, transmuted_grade,
 *                                         remarks, descriptor, complete, missing]
 *     initial_grade   float|null  average of the component INITIAL grades (display only)
 *     transmuted_grade int|null   OVERALL Term Grade = average of component grades
 *     remarks         string      decided on the OVERALL grade only
 *     descriptor      string|null
 *     complete        bool        true only when every component is complete
 *     missing         array       components with nothing encoded yet
 *     graded_count    int
 *     is_multi_component bool
 * }
 */
function computeMultiComponentTermGrade($weights, $components, $items, $scores_map, $student_id, $sy = null) {
    $result = [
        'components'         => [],
        'initial_grade'      => null,
        'transmuted_grade'   => null,
        'remarks'            => 'Incomplete',
        'descriptor'         => null,
        'complete'           => false,
        'missing'            => [],
        'graded_count'       => 0,
        'is_multi_component' => true,
        'weights'            => $weights,
    ];

    if (!empty($weights['blocked'])) {
        $result['remarks'] = 'Not configured';
        $result['missing'] = ['configuration'];
        return $result;
    }

    $components = gh_effectiveComponents($components, $items);

    $ww_all = $items['WW'] ?? [];
    $pt_all = $items['PT'] ?? [];
    $qa_all = $items['QA'] ?? [];

    $tg_values = [];
    $ig_values = [];
    $all_complete = true;

    foreach ($components as $component) {
        $ww_c = gh_itemsForComponent($ww_all, $component);
        $pt_c = gh_itemsForComponent($pt_all, $component);
        $qa_c = gh_itemsForComponent($qa_all, $component);

        $ww_pct = computeCategoryPercent($ww_c, $scores_map, $student_id);
        $pt_pct = computeCategoryPercent($pt_c, $scores_map, $student_id);

        $exam = ($weights['exam_mode'] === 'none')
            ? ['percent' => null, 'complete' => true, 'missing' => [], 'notice' => null, 'parts' => []]
            : computeExamPercent($qa_c, $scores_map, $student_id, $weights['exam_mode']);

        // Each component uses the learning area's prescribed weights.
        $computed = computeTermGrade($weights, [
            'ww' => $ww_pct,
            'pt' => $pt_pct,
            'ex' => $exam['percent'],
        ], $sy);

        $exam_incomplete = (!$exam['complete'] && !empty($exam['missing']));
        $complete = $computed['complete'] && !$exam_incomplete;

        $result['components'][$component] = [
            'initial_grade'    => $computed['initial_grade'],
            'transmuted_grade' => $computed['transmuted_grade'],
            // A component remark is informational only. It NEVER decides
            // whether the learning area is passed.
            'remarks'          => $complete ? $computed['remarks'] : 'Incomplete',
            'descriptor'       => $computed['descriptor'],
            'complete'         => $complete,
            'missing'          => $computed['missing'],
            'exam_missing'     => $exam['missing'],
            'ww'               => $ww_pct,
            'pt'               => $pt_pct,
            'ex'               => $exam['percent'],
        ];

        if ($computed['transmuted_grade'] === null) {
            $result['missing'][] = $component;
            $all_complete = false;
            continue;
        }
        if (!$complete) $all_complete = false;

        $tg_values[] = (float)$computed['transmuted_grade'];
        $ig_values[] = (float)$computed['initial_grade'];
    }

    $result['graded_count'] = count($tg_values);

    if (empty($tg_values)) {
        return $result;
    }

    // Overall = average of the COMPONENT GRADES, rounded to a whole number.
    $overall = array_sum($tg_values) / count($tg_values);
    $result['transmuted_grade'] = (int)round($overall);
    $result['initial_grade']    = round(array_sum($ig_values) / count($ig_values), 2);
    $result['complete']         = $all_complete;
    $result['descriptor']       = getGradeDescriptor($result['transmuted_grade'], $sy);
    // Pass/fail is decided on the OVERALL grade only. One weak component
    // does not fail the learning area.
    $result['remarks'] = $all_complete
        ? getPassFailRemark($result['transmuted_grade'])
        : 'Incomplete';

    return $result;
}

// ============================================================
// SECTION 6 — LEGACY SUBJECT TYPE LIST
// ============================================================
// The old four-quarter DO 8, s. 2015 weight map that used to live here
// has been DELETED. Component weights now come from exactly one place:
// the verified subject category in gh_categoryDefinitions(), resolved by
// getSubjectWeights(). There is no fallback and no "just in case" table.

/**
 * Legacy subject_type list, kept for existing dropdowns/validation.
 * @return array
 */
function getSubjectTypes() {
    // 'EsP' is retained ONLY so existing historical rows still render. It is
    // excluded from getSelectableSubjectTypes(), so no NEW subject can be
    // created with the obsolete type.
    return ['Languages', 'AP', 'Values Education', 'GMRC', 'EsP', 'Science', 'Math', 'MAPEH', 'TLE', 'EPP', 'SHS Core', 'Academic Track'];
}

/**
 * Subject types offered when CREATING or EDITING a subject.
 * The obsolete 'EsP' type is deliberately excluded — under DO 015, s. 2026
 * the Key Stage 3 learning area is Values Education and Key Stage 2 is GMRC.
 * Existing EsP rows keep working; they simply cannot be created anew.
 * @return array
 */
function getSelectableSubjectTypes() {
    return array_values(array_filter(getSubjectTypes(), function ($t) {
        return $t !== 'EsP';
    }));
}

// ============================================================
// SECTION 7 — SUBJECT RESOLUTION
// ============================================================

/**
 * Load a subject row by code (cached).
 * @param string $subject_code
 * @return array|null
 */
function gh_loadSubject($subject_code) {
    static $cache = [];
    $code = (string)$subject_code;
    if ($code === '') return null;
    if (array_key_exists($code, $cache)) return $cache[$code];

    $row = getRecord("SELECT * FROM subjects WHERE subject_code = ?", [$code]);
    $cache[$code] = $row ? $row : null;
    return $cache[$code];
}

/**
 * Derive a Grades 4-10 category from the legacy subject_type when the
 * admin has not set subjects.subject_category yet.
 *
 * IMPORTANT (DO 015 / project rule 3): 'EsP' is NOT auto-mapped to
 * Values Education. The old Edukasyon sa Pagpapakatao subjects in this
 * database predate the current Values Education learning area and there
 * is no reliable mapping table in the project, so they are flagged for
 * administrator configuration instead of being guessed.
 *
 * @param string $subject_type
 * @return string|null category key, or null when it cannot be derived
 */
function gh_deriveKeyStageCategory($subject_type) {
    switch ($subject_type) {
        case 'Languages':
        case 'AP':
        case 'Math':
        case 'Science':
            return 'ks_standard';
        case 'Values Education':
            // Key Stage 3 (Grades 7-10) under DO 015, s. 2026.
            return 'ks_values_education';
        case 'GMRC':
            // Key Stage 2 (Grades 4-6) under DO 015, s. 2026.
            return 'ks_gmrc';
        case 'MAPEH':
            // MAPEH is a multi-component learning area (Music, Arts, PE,
            // Health) — its own category so components are never pooled.
            return 'ks_mapeh';
        case 'TLE':
        case 'EPP':
            return 'ks_epp_tle_mapeh';
        default:
            // 'EsP' and anything unrecognised -> must be configured.
            return null;
    }
}

// ============================================================
// SECTION 8 — THE CENTRAL WEIGHT HELPER
// ============================================================

/**
 * Resolve the component weights that apply to one subject for one learner.
 *
 * This is the ONLY place component weights are decided. It considers the
 * grading structure, the school year, the grade level, the configured
 * subject category, and (for Grade 12) the curriculum type.
 *
 * @param array|string     $subject        subjects row, subject_code, or legacy subject_type
 * @param string|int|null  $grade_level    e.g. 'Grade 7'; falls back to the subject's own grade_level
 * @param array|int|null   $school_year    SY row or sy_id; null = active SY
 * @param string|null      $curriculum_type 'strengthened_shs' | null (no longer changes weights)
 * @return array {
 *     ww, pt, ex          float weights (0-1)
 *     qa                  float — alias of ex, kept for existing templates
 *     exam_mode           'standard' | 'term_exam_only' | 'none' | 'unconfigured'
 *     uses_domains        bool — GMRC / Values Education domain split required
 *     category            string|null category key
 *     category_label      string
 *     rule_source         string
 *     needs_config        bool — true when we refused to guess
 *     config_reason       string|null
 *     blocked             bool — true when grades must not be computed at all
 * }
 */
function getSubjectWeights($subject, $grade_level = null, $school_year = null, $curriculum_type = null) {
    $defs = gh_categoryDefinitions();

    // ---- Normalise the subject argument -------------------------------
    $subject_row  = null;
    $subject_type = null;
    $subject_code = null;

    if (is_array($subject)) {
        $subject_row  = $subject;
        $subject_code = $subject['subject_code'] ?? null;
        $subject_type = $subject['subject_type'] ?? null;
    } elseif (is_string($subject) && $subject !== '') {
        $loaded = gh_loadSubject($subject);
        if ($loaded) {
            $subject_row  = $loaded;
            $subject_code = $loaded['subject_code'];
            $subject_type = $loaded['subject_type'] ?? null;
        } else {
            // Legacy call style: getSubjectWeights('Languages')
            $subject_type = $subject;
        }
    }

    if ($grade_level === null && $subject_row) {
        $grade_level = $subject_row['grade_level'] ?? null;
    }

    // Surfaces a configuration notice if a school year somehow still
    // claims a retired structure. There is no legacy computation branch.
    getGradingStructure($school_year);

    // ---- Three-Term school year ---------------------------------------
    $category = null;
    $configured = trim((string)($subject_row['subject_category'] ?? ''));
    if ($configured !== '' && isset($defs[$configured])) {
        $category = $configured;
    }

    $n = gh_gradeLevelNumber($grade_level);
    $label = $subject_row['subject_name'] ?? ($subject_code ?: 'this subject');

    // ---- Senior High School (Grades 11-12) ----------------------------
    if ($n === 11 || $n === 12) {
        // Grade 11 and Grade 12 both use Strengthened SHS. Nothing is
        // blocked and nothing needs configuring at the curriculum level.
        $expected_stage = 'sshs';

        // A category from outside the Strengthened SHS family (e.g. a
        // Grades 4-10 category left on an SHS subject) is ignored rather
        // than silently applied.
        if ($category !== null && $defs[$category]['stage'] !== $expected_stage) {
            gh_addConfigNotice(
                'category_stage_mismatch',
                'Subject "' . $label . '" is categorised as "' . $defs[$category]['label'] . '", which is not a '
                . 'Senior High School category. Review the subject category.'
            );
            $category = null;
        }

        if ($category === null) {
            // Fall back to the general academic category for the applicable
            // curriculum, and flag it loudly. The special categories
            // (Work Immersion, Research, TechPro, Field Exposure, ...) cannot
            // be detected from this database — subjects.subject_type is
            // 'Academic Track' for every Grade 11/12 row, which is ambiguous.
            $category = 'sshs_core';
            gh_addConfigNotice(
                'shs_subject_category',
                'One or more Senior High School subjects have no curriculum category set, so the general academic '
                . 'category is being applied as a provisional default. Special categories (Work Immersion, Research / '
                . 'Design and Innovation, TechPro, Field Exposure, Arts / Sports / Health electives, Core subjects) '
                . 'must be assigned per subject before these grades are treated as official.'
            );
            $def = $defs[$category];
            return [
                'ww' => $def['ww'], 'pt' => $def['pt'], 'ex' => $def['ex'], 'qa' => $def['ex'],
                'exam_mode'      => $def['exam_mode'],
                'uses_domains'   => $def['domains'],
                'category'       => $category,
                'category_label' => $def['label'] . ' (provisional default)',
                'rule_source'    => $def['rule'],
                'needs_config'   => true,
                'config_reason'  => 'Subject category not set for "' . $label . '".',
                'blocked'        => false,
            ];
        }
    }

    // ---- Key Stage 2 / 3 (Grades 4-10) --------------------------------
    if ($category === null) {
        $derived = gh_deriveKeyStageCategory((string)$subject_type);
        if ($derived === null) {
            if ((string)$subject_type === 'EsP') {
                gh_addConfigNotice(
                    'esp_mapping',
                    'Subject "' . $label . '" uses the old "EsP" subject type. It is NOT automatically treated as '
                    . 'Values Education, because the required Cognitive / Affective / Behavioral domain structure '
                    . 'cannot be assumed from the old data. Set its category to GMRC or Values Education (or to a '
                    . 'standard learning area) in Subjects before encoding grades.'
                );
                $reason = 'Old EsP subject "' . $label . '" has no verified mapping to GMRC or Values Education.';
            } else {
                gh_addConfigNotice(
                    'unmapped_subject',
                    'Subject "' . $label . '" has no curriculum category and its subject type ("' . $subject_type
                    . '") does not map to a known learning area. Set its category in Subjects.'
                );
                $reason = 'Subject "' . $label . '" could not be mapped to a grading category.';
            }
            return [
                'ww' => null, 'pt' => null, 'ex' => null, 'qa' => null,
                'exam_mode'      => 'unconfigured',
                'uses_domains'   => false,
                'category'       => null,
                'category_label' => 'Not configured',
                'rule_source'    => null,
                'needs_config'   => true,
                'config_reason'  => $reason,
                'blocked'        => true,
            ];
        }
        $category = $derived;
    }

    $def = $defs[$category];
    return [
        'ww' => $def['ww'], 'pt' => $def['pt'], 'ex' => $def['ex'], 'qa' => $def['ex'],
        'exam_mode'      => $def['exam_mode'],
        'uses_domains'   => $def['domains'],
        'category'       => $category,
        'category_label' => $def['label'],
        'rule_source'    => $def['rule'],
        'needs_config'   => false,
        'config_reason'  => null,
        'blocked'        => false,
    ];
}

/**
 * Convenience wrapper: resolve weights for a subject as they apply to a
 * specific learner (so Grade 12 curriculum type is honoured per learner).
 *
 * @param array|string    $subject
 * @param array|int|null  $student  student row or student_id
 * @param array|int|null  $school_year
 * @return array same shape as getSubjectWeights()
 */
function getSubjectWeightsForStudent($subject, $student = null, $school_year = null) {
    $grade_level = null;
    $student_id  = null;
    $section_id  = null;

    if (is_array($student)) {
        $student_id  = $student['student_id'] ?? null;
        $grade_level = $student['grade_level'] ?? null;
        $section_id  = $student['section_id'] ?? null;
    } elseif ($student !== null && $student !== '') {
        $student_id = (int)$student;
        $row = getRecord("SELECT student_id, grade_level, section_id FROM students WHERE student_id = ?", [$student_id]);
        if ($row) {
            $grade_level = $row['grade_level'];
            $section_id  = $row['section_id'];
        }
    }

    if ($grade_level === null && is_array($subject)) {
        $grade_level = $subject['grade_level'] ?? null;
    }

    $curriculum_type = getLearnerCurriculumType($student_id, $grade_level, $section_id);
    return getSubjectWeights($subject, $grade_level, $school_year, $curriculum_type);
}

// ============================================================
// SECTION 9 — TRANSMUTATION
// ============================================================

/**
 * SY 2026-2027 Adjusted Transmutation Table.
 * Stored as [lower_bound, transmuted_grade] in DESCENDING order so a
 * single >= comparison finds the band. Using lower bounds (rather than
 * rounding the Initial Grade first) keeps the boundaries decimal-safe:
 * an IG of 99.495 correctly lands in the 98.32-99.49 band, not the
 * 99.50-100.00 band.
 * @return array
 */
function gh_adjustedTransmutationTable2026() {
    return [
        [99.50, 100], [98.32, 99], [97.14, 98], [95.96, 97], [94.78, 96],
        [93.60, 95],  [92.42, 94], [91.24, 93], [90.06, 92], [88.88, 91],
        [87.70, 90],  [86.52, 89], [85.34, 88], [84.16, 87], [82.98, 86],
        [81.80, 85],  [80.62, 84], [79.44, 83], [78.26, 82], [77.08, 81],
        [75.90, 80],  [74.72, 79], [73.54, 78], [72.36, 77], [71.18, 76],
        [70.00, 75],  [65.34, 74], [60.67, 73], [56.01, 72], [51.34, 71],
        [46.67, 70],  [42.01, 69], [37.34, 68], [32.68, 67], [28.01, 66],
        [23.35, 65],  [18.68, 64], [14.01, 63], [9.35, 62],  [4.68, 61],
        [0.00, 60],
    ];
}

// The legacy DO 8, s. 2015 transmutation table has been DELETED. It was
// only ever used to re-render historical Quarter records, and those
// records no longer exist.

/**
 * Which transmutation table applies to a school year.
 * The SY 2026-2027 adjusted table is deliberately NOT applied
 * automatically to later school years — a future SY must be reviewed
 * against whatever order governs it.
 * @param array|int|null $sy
 * @return string 'sy2026_2027_adjusted'
 */
function gh_transmutationTableKey($sy = null) {
    $row  = gh_resolveSchoolYear($sy);
    $name = preg_replace('/\s+/', '', (string)($row['sy_name'] ?? ''));

    if ($name !== '2026-2027') {
        gh_addConfigNotice(
            'transmutation_year',
            'School year "' . ($row['sy_name'] ?? 'unknown') . '" uses the Three-Term structure but is not '
            . 'SY 2026-2027. The SY 2026-2027 Adjusted Transmutation Table is being used as a provisional default '
            . 'and must be reviewed against the DepEd order that governs this school year.'
        );
    }
    return 'sy2026_2027_adjusted';
}

/**
 * The transmutation table for a school year, descending by lower bound.
 * @param array|int|null $sy
 * @return array
 */
function getTransmutationTable($sy = null) {
    // One table, no fallback. gh_transmutationTableKey() raises a config
    // notice if the school year is not the one this table was verified for.
    gh_transmutationTableKey($sy);
    return gh_adjustedTransmutationTable2026();
}

/**
 * Transmute an Initial Grade into the reported grade for a school year.
 * The Initial Grade is NOT rounded first — the raw decimal value is
 * compared against the band's lower bound (with a tiny epsilon to absorb
 * binary floating-point representation error).
 *
 * @param float          $initial_grade 0-100
 * @param array|int|null $sy            school year; null = active
 * @return int transmuted grade
 */
function transmuteGrade($initial_grade, $sy = null) {
    $ig = (float)$initial_grade;
    if ($ig < 0)   $ig = 0.0;
    if ($ig > 100) $ig = 100.0;

    $table   = getTransmutationTable($sy);
    $epsilon = 0.0000001;

    foreach ($table as $band) {
        if ($ig + $epsilon >= $band[0]) {
            return (int)$band[1];
        }
    }
    return (int)$table[count($table) - 1][1];
}

/**
 * The transmutation table as JSON, so the browser-side live preview uses
 * exactly the same bands as the server. Pages must not hard-code a table.
 * @param array|int|null $sy
 * @return string
 */
function gh_transmutationTableJson($sy = null) {
    return json_encode(getTransmutationTable($sy));
}

// ============================================================
// SECTION 10 — DESCRIPTORS AND PASS / FAIL
// ============================================================

/**
 * Qualitative descriptor for a reported numerical grade.
 * DO 015, s. 2026: Advancing / Benchmarking / Connecting / Developing /
 * Emerging. The old DO 8, s. 2015 descriptor set has been removed.
 *
 * @param int|float      $grade reported (transmuted) grade
 * @param array|int|null $sy
 * @return string
 */
function getGradeDescriptor($grade, $sy = null) {
    $g = (float)$grade;

    if ($g >= 90) return 'Advancing';
    if ($g >= 80) return 'Benchmarking';
    if ($g >= 75) return 'Connecting';
    if ($g >= 65) return 'Developing';
    return 'Emerging';
}

/**
 * Passing mark from system settings, defaulting to 75.
 * Defined here only if risk_helpers.php has not already defined it.
 */
if (!function_exists('getGradingPassMark')) {
    function getGradingPassMark() {
        $row = getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'grading_pass_mark'");
        return floatval($row['setting_value'] ?? 75);
    }
}

/**
 * Pass / fail remark. MUST be given the TRANSMUTED grade, never the
 * Initial Grade — IG 70.00 transmutes to 75 and is a PASS.
 * @param int|float $grade transmuted grade
 * @return string 'Passed' | 'Failed'
 */
function getPassFailRemark($grade) {
    return ((float)$grade >= getGradingPassMark()) ? 'Passed' : 'Failed';
}

// ============================================================
// SECTION 11 — SCORE VALIDATION
// ============================================================

/**
 * Validate one raw score against its Highest Possible Score.
 * Impossible scores are REJECTED, never silently clamped.
 *
 * @param mixed $raw raw score; '' or null means "not encoded yet"
 * @param mixed $hps highest possible score
 * @return array ['valid' => bool, 'value' => float|null, 'error' => string|null]
 */
function validateRawScore($raw, $hps) {
    $hps = (float)$hps;

    if ($hps <= 0) {
        return ['valid' => false, 'value' => null,
                'error' => 'Highest Possible Score must be greater than zero.'];
    }

    // Distinguish "no score encoded yet" from an actual zero.
    if ($raw === null || $raw === '' || $raw === false) {
        return ['valid' => true, 'value' => null, 'error' => null];
    }

    if (!is_numeric($raw)) {
        return ['valid' => false, 'value' => null, 'error' => 'Raw score must be a number.'];
    }

    $val = (float)$raw;

    if ($val < 0) {
        return ['valid' => false, 'value' => null, 'error' => 'Raw score cannot be negative.'];
    }

    if ($val > $hps) {
        return ['valid' => false, 'value' => null,
                'error' => 'Raw score cannot be greater than the Highest Possible Score.'];
    }

    return ['valid' => true, 'value' => $val, 'error' => null];
}

/**
 * Clamp a percentage score into 0-100 without hiding invalid raw data
 * (raw scores are validated separately by validateRawScore()).
 * @param float|null $pct
 * @return float|null
 */
function gh_clampPercent($pct) {
    if ($pct === null) return null;
    $p = (float)$pct;
    if ($p < 0)   return 0.0;
    if ($p > 100) return 100.0;
    return $p;
}

// ============================================================
// SECTION 12 — ASSESSMENT ITEMS AND SCORES
// ============================================================

/**
 * Applies the (already-designed) additive assessment_items.topic column if
 * it isn't there yet -- see database/migrations/2026_assessment_items_clarity.sql.
 * Purely descriptive metadata (short Topic/Lesson/Competency text, e.g.
 * "Fractions"); NEVER read by any grade computation. This lets a teacher
 * tag what an item actually covers so a future Teacher AI feature can read
 * item title + topic + the student's own assessment_scores rows together --
 * nothing here changes any existing score or grade.
 */
function gh_ensureAssessmentItemsClarity() {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (schemaIsReady('assessment_items_clarity_v1')) {
        return $ready = true;
    }

    $hasColumn = getRecord(
        "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_items' AND COLUMN_NAME = 'topic'"
    );
    if (!$hasColumn || (int) $hasColumn['n'] === 0) {
        executeQuery("ALTER TABLE `assessment_items`
            ADD COLUMN `topic` varchar(150) DEFAULT NULL
            COMMENT 'Optional short Topic/Lesson/Competency (e.g. \"Fractions\") shown alongside item_name -- display only, never used in grade computation.'
            AFTER `item_name`");
    }

    $ready = true;
    $__schema_ok = ($ready);
    if ($__schema_ok) {
        schemaMarkReady('assessment_items_clarity_v1');
    }
    return $__schema_ok;
}

/**
 * Assessment items for a class, grouped by category.
 * Category codes stay 'WW' / 'PT' / 'QA'; 'QA' is the Examinations
 * component for a Three-Term school year (see compatibility notes above).
 * @return array ['WW' => [...], 'PT' => [...], 'QA' => [...]]
 */
function getAssessmentItems($teacher_id, $subject_code, $section_id, $sy_id, $term) {
    $rows = getRecords("
        SELECT * FROM assessment_items
        WHERE teacher_id = ? AND subject_code = ? AND section_id = ?
        AND school_year_id = ? AND term = ? AND is_active = 1
        ORDER BY category, item_order, item_id
    ", [$teacher_id, $subject_code, $section_id, $sy_id, $term]);

    $grouped = ['WW' => [], 'PT' => [], 'QA' => []];
    foreach ($rows as $row) {
        if (isset($grouped[$row['category']])) {
            $grouped[$row['category']][] = $row;
        }
    }
    return $grouped;
}

// ============================================================
// TEACHER AI -- STUDENT-PERFORMANCE ANALYSIS (topic/competency level)
// Read-only helpers. Nothing here writes to assessment_items,
// assessment_scores, grades, or attendance -- they only shape existing
// scored data (Manage Items' item_name/topic + assessment_scores.raw_score)
// into the facts Teacher AI is allowed to reason from. See
// modules/teachers/teacher_ai_chat.php for how these are used.
// ============================================================

/**
 * Every SCORED assessment item row for a teacher (optionally one subject),
 * within a school year -- the raw material for topic-level analysis.
 * Only items the teacher owns and students who actually have a raw_score
 * are included (an unscored item tells the AI nothing about a student).
 * @return array of ['item_id','topic','item_name','category','term','section_id',
 *                    'highest_score','student_id','raw_score','scored_at']
 */
function gh_getScoredAssessmentRows($teacher_id, $sy_id, $subject_code = null) {
    $sql = "SELECT ai.item_id, ai.topic, ai.item_name, ai.category, ai.term, ai.section_id,
                   ai.highest_score, sc.student_id, sc.raw_score, sc.created_at AS scored_at
            FROM assessment_items ai
            INNER JOIN assessment_scores sc ON sc.item_id = ai.item_id
            WHERE ai.teacher_id = ? AND ai.school_year_id = ? AND ai.is_active = 1
              AND sc.raw_score IS NOT NULL AND ai.highest_score > 0";
    $params = [$teacher_id, $sy_id];
    if ($subject_code) {
        $sql .= " AND ai.subject_code = ?";
        $params[] = $subject_code;
    }
    $sql .= " ORDER BY sc.created_at ASC, ai.item_order ASC";
    return getRecords($sql, $params);
}

/** Plain-language label for a WW/PT/QA category code. */
function gh_categoryPlainLabel($category) {
    $labels = ['WW' => 'Written/Oral Work', 'PT' => 'Performance Task', 'QA' => 'Examination'];
    return $labels[$category] ?? $category;
}

/**
 * One student's scored items grouped by Topic/Lesson/Competency (falls back
 * to the item name itself when no topic was tagged, so untagged items are
 * never silently dropped), with a plain "basis" list, a weak/strong/passing
 * read against the school's passing mark, and a simple improving/declining/
 * stable read when there is enough chronological data. Purely descriptive --
 * never used to change any score or grade.
 * @return array [ ['label' => topic or item name, 'has_topic' => bool,
 *                   'count' => int, 'avg_percent' => float, 'status' => string,
 *                   'basis' => [string...], 'trend' => string], ... ]
 */
function gh_studentTopicPerformance(array $scoredRows, $student_id, $pass_mark) {
    $byLabel = [];
    foreach ($scoredRows as $row) {
        if ((int) $row['student_id'] !== (int) $student_id) {
            continue;
        }
        $label = trim((string) ($row['topic'] ?? ''));
        $hasTopic = $label !== '';
        if (!$hasTopic) {
            $label = trim((string) $row['item_name']); // fallback so untagged items still count
        }
        $percent = ((float) $row['raw_score'] / (float) $row['highest_score']) * 100;
        $byLabel[$label]['has_topic'] = $byLabel[$label]['has_topic'] ?? $hasTopic;
        $byLabel[$label]['entries'][] = [
            'item_name' => $row['item_name'],
            'category'  => $row['category'],
            'raw_score' => $row['raw_score'],
            'highest_score' => $row['highest_score'],
            'percent'   => $percent,
            'scored_at' => $row['scored_at'],
        ];
    }

    $out = [];
    foreach ($byLabel as $label => $data) {
        $entries = $data['entries'];
        $count = count($entries);
        $avg = array_sum(array_column($entries, 'percent')) / $count;

        // Topic-level labels only -- NOT a subject grade and NOT the Academic
        // At-Risk status (that stays in calculateStudentRisk()/AI Analytics).
        $status = 'Satisfactory';
        if ($avg < $pass_mark) {
            $status = 'Needs Improvement';
        } elseif ($avg >= $pass_mark + 10) {
            $status = 'Strong';
        }

        $trend = 'Not enough data for a trend';
        if ($count >= 4) {
            $half = (int) floor($count / 2);
            $firstHalf = array_slice($entries, 0, $half);
            $secondHalf = array_slice($entries, -$half);
            $firstAvg = array_sum(array_column($firstHalf, 'percent')) / count($firstHalf);
            $secondAvg = array_sum(array_column($secondHalf, 'percent')) / count($secondHalf);
            $diff = $secondAvg - $firstAvg;
            if ($diff >= 5) {
                $trend = 'Improving (earlier avg ' . round($firstAvg) . '% -> recent avg ' . round($secondAvg) . '%)';
            } elseif ($diff <= -5) {
                $trend = 'Declining (earlier avg ' . round($firstAvg) . '% -> recent avg ' . round($secondAvg) . '%)';
            } else {
                $trend = 'Stable (around ' . round($avg) . '%)';
            }
        }

        $basis = [];
        foreach ($entries as $e) {
            $basis[] = $e['item_name'] . ' - ' . rtrim(rtrim(number_format($e['raw_score'], 2), '0'), '.')
                . '/' . rtrim(rtrim(number_format($e['highest_score'], 2), '0'), '.');
        }

        $out[] = [
            'label'       => $label,
            'has_topic'   => $data['has_topic'],
            'count'       => $count,
            'avg_percent' => round($avg, 1),
            'status'      => $status,
            'basis'       => $basis,
            'trend'       => $trend,
        ];
    }

    // Weakest first -- that is usually what the teacher wants surfaced.
    usort($out, function ($a, $b) { return $a['avg_percent'] <=> $b['avg_percent']; });
    return $out;
}

/**
 * Class-wide rollup by TAGGED topic only (an untagged item has no shared
 * label across students, so it cannot be rolled up class-wide -- it still
 * appears in each student's own breakdown above). For each topic: how many
 * of the students who were actually assessed on it are below the passing
 * mark, and which single assessment within that topic pulled the average
 * down the most.
 * @return array [ ['topic','students_with_data','students_below_pass',
 *                   'class_avg_percent','weakest_item','weakest_item_avg'], ... ]
 */
function gh_classTopicWeakSpots(array $scoredRows, $pass_mark) {
    $byTopic = [];
    foreach ($scoredRows as $row) {
        $topic = trim((string) ($row['topic'] ?? ''));
        if ($topic === '') {
            continue; // untagged items are not comparable across students
        }
        $percent = ((float) $row['raw_score'] / (float) $row['highest_score']) * 100;
        $byTopic[$topic]['by_student'][(int) $row['student_id']][] = $percent;
        $byTopic[$topic]['by_item'][$row['item_name']][] = $percent;
        $byTopic[$topic]['item_meta'][$row['item_name']] = [
            'category' => $row['category'],
            'highest_score' => $row['highest_score'],
        ];
    }

    $out = [];
    foreach ($byTopic as $topic => $data) {
        $studentAverages = array_map(function ($percents) {
            return array_sum($percents) / count($percents);
        }, $data['by_student']);
        $studentsWithData = count($studentAverages);
        $studentsBelow = count(array_filter($studentAverages, function ($p) use ($pass_mark) {
            return $p < $pass_mark;
        }));
        $classAvg = array_sum($studentAverages) / $studentsWithData;

        $itemAverages = [];
        foreach ($data['by_item'] as $itemName => $percents) {
            $itemAverages[$itemName] = array_sum($percents) / count($percents);
        }
        asort($itemAverages);
        $weakestItem = array_key_first($itemAverages);

        // Per-assessment basis for this topic (which quizzes/activities/exams,
        // how many students were assessed on each, and their average).
        $items = [];
        foreach ($data['by_item'] as $itemName => $percents) {
            $items[] = [
                'name'               => $itemName,
                'category'           => gh_categoryPlainLabel($data['item_meta'][$itemName]['category'] ?? ''),
                'highest_score'      => rtrim(rtrim(number_format((float) ($data['item_meta'][$itemName]['highest_score'] ?? 0), 2), '0'), '.'),
                'students_assessed'  => count($percents),
                'avg_percent'        => round(array_sum($percents) / count($percents), 1),
            ];
        }

        $out[] = [
            'topic'              => $topic,
            'students_with_data' => $studentsWithData,
            'students_below_pass'=> $studentsBelow,
            'class_avg_percent'  => round($classAvg, 1),
            'weakest_item'       => $weakestItem,
            'weakest_item_avg'   => round($itemAverages[$weakestItem], 1),
            'item_count'         => count($items),
            'items'              => $items,
        ];
    }

    // Most students struggling first.
    usort($out, function ($a, $b) { return $b['students_below_pass'] <=> $a['students_below_pass']; });
    return $out;
}

/**
 * Question-level (item-level) evidence for ONE tagged Topic, class-wide --
 * read-only, used only by the Teacher AI focused-topic analysis.
 *
 * Source chain (all existing tables, nothing new):
 *   assessment_items (Topic)  <- ai_generated_resources.link_item_id
 *   (quiz_questions_json: the actual questions + correct_index)
 *   <- ai_resource_submissions (is_auto_graded = 1; answers_json = the
 *   student's chosen option index per question, same order).
 * Google Form responses are not used: their stored answers are text in no
 * guaranteed question order, so they cannot be matched to question numbers
 * reliably. Items with only a total score (entered in Manage Items) have no
 * question-level data and simply do not appear here.
 *
 * @param array $allowedStudentIds only these students are counted (the
 *   teacher's authorized roster); empty = none.
 * @return array ['items' => [ ['item_id','item_name','section','responses',
 *   'questions' => [ ['number','text','skill','correct','total','pct'], ...]], ...],
 *   'total_responses' => int]
 */
function gh_topicItemLevelEvidence($teacher_id, $sy_id, $subject_code, $topic, array $allowedStudentIds)
{
    $out = ['items' => [], 'total_responses' => 0];
    $topic = trim((string) $topic);
    if ($topic === '' || !$allowedStudentIds) {
        return $out;
    }
    $allowed = array_flip(array_map('intval', $allowedStudentIds));

    $sql = "SELECT ai.item_id, ai.item_name, sec.section_name, r.resource_id, r.quiz_questions_json,
                   sub.student_id, sub.answers_json
            FROM assessment_items ai
            INNER JOIN ai_generated_resources r ON r.link_item_id = ai.item_id AND r.teacher_id = ai.teacher_id
            INNER JOIN ai_resource_submissions sub ON sub.resource_id = r.resource_id
                   AND sub.student_id = r.student_id AND sub.is_auto_graded = 1
            LEFT JOIN sections sec ON sec.section_id = ai.section_id
            WHERE ai.teacher_id = ? AND ai.school_year_id = ? AND ai.is_active = 1
              AND LOWER(TRIM(ai.topic)) = LOWER(?)
              AND r.quiz_questions_json IS NOT NULL AND r.quiz_questions_json <> ''";
    $params = [$teacher_id, $sy_id, $topic];
    if ($subject_code) {
        $sql .= " AND ai.subject_code = ?";
        $params[] = $subject_code;
    }
    $sql .= " ORDER BY ai.item_order ASC, ai.item_id ASC";
    $rows = getRecords($sql, $params); // [] if the AI quiz tables do not exist on this install

    $items = [];
    foreach ($rows ?: [] as $row) {
        $studentId = (int) $row['student_id'];
        if (!isset($allowed[$studentId])) {
            continue;
        }
        $questions = json_decode((string) $row['quiz_questions_json'], true);
        $answers = json_decode((string) $row['answers_json'], true);
        if (!is_array($questions) || !$questions || !is_array($answers)) {
            continue;
        }
        $itemId = (int) $row['item_id'];
        if (!isset($items[$itemId])) {
            $qs = [];
            foreach (array_values($questions) as $i => $q) {
                $qs[] = [
                    'number'  => $i + 1,
                    'text'    => mb_substr(trim((string) ($q['question'] ?? '')), 0, 240),
                    'skill'   => trim((string) ($q['skill'] ?? '')), // only if the quiz itself carries one
                    'correct' => 0,
                    'total'   => 0,
                ];
            }
            $items[$itemId] = [
                'item_id'   => $itemId,
                'item_name' => (string) $row['item_name'],
                'section'   => (string) ($row['section_name'] ?? ''),
                'responses' => 0,
                'questions' => $qs,
                'seen'      => [],
            ];
        }
        $item = &$items[$itemId];
        // Same quiz only (a class send copies one question set to every
        // student's row); a different question count means a different quiz.
        if (count($questions) !== count($item['questions']) || isset($item['seen'][$studentId])) {
            unset($item);
            continue;
        }
        $item['seen'][$studentId] = true;
        $item['responses']++;
        foreach (array_values($questions) as $i => $q) {
            $chosen = $answers[$i] ?? null;
            $item['questions'][$i]['total']++;
            if ($chosen !== null && $chosen !== '' && (int) $chosen === (int) ($q['correct_index'] ?? -1)) {
                $item['questions'][$i]['correct']++;
            }
        }
        unset($item);
    }

    foreach ($items as $item) {
        if ($item['responses'] < 1) {
            continue;
        }
        unset($item['seen']);
        foreach ($item['questions'] as &$q) {
            $q['pct'] = $q['total'] > 0 ? round($q['correct'] / $q['total'] * 100, 1) : null;
        }
        unset($q);
        $out['items'][] = $item;
        $out['total_responses'] += $item['responses'];
    }
    return $out;
}

/**
 * Groups item-level questions by skill/subtopic and finds the weakest one.
 * $labels maps "<item_id>:<question number>" => skill name (from the quiz's
 * own `skill` field, or from Teacher AI's grouping of the actual question
 * text). Percent correct is computed here, never by the model.
 * @return array list sorted weakest first: ['skill','pct','correct','total',
 *   'evidence' => 'Quiz 5 (Grade 7 - A), Items 6-8', 'questions' => [text...]]
 */
function gh_groupItemEvidenceBySkill(array $evidence, array $labels)
{
    $bySkill = [];
    foreach ($evidence['items'] ?? [] as $item) {
        foreach ($item['questions'] as $q) {
            $skill = trim((string) ($labels[$item['item_id'] . ':' . $q['number']] ?? ''));
            if ($skill === '' || $q['total'] < 1) {
                continue;
            }
            $key = mb_strtolower($skill);
            if (!isset($bySkill[$key])) {
                $bySkill[$key] = ['skill' => $skill, 'correct' => 0, 'total' => 0, 'refs' => [], 'questions' => []];
            }
            $bySkill[$key]['correct'] += $q['correct'];
            $bySkill[$key]['total'] += $q['total'];
            $refKey = $item['item_name'] . ($item['section'] !== '' ? ' (' . $item['section'] . ')' : '');
            $bySkill[$key]['refs'][$refKey][] = $q['number'];
            if (count($bySkill[$key]['questions']) < 4) {
                $bySkill[$key]['questions'][] = 'Item ' . $q['number'] . ': ' . $q['text'];
            }
        }
    }

    $out = [];
    foreach ($bySkill as $g) {
        $parts = [];
        foreach ($g['refs'] as $name => $numbers) {
            $parts[] = $name . ', ' . gh_itemNumberRanges($numbers);
        }
        $out[] = [
            'skill'     => $g['skill'],
            'pct'       => round($g['correct'] / $g['total'] * 100, 1),
            'correct'   => $g['correct'],
            'total'     => $g['total'],
            'evidence'  => implode('; ', $parts),
            'questions' => $g['questions'],
        ];
    }
    usort($out, function ($a, $b) { return $a['pct'] <=> $b['pct']; });
    return $out;
}

/** [6,7,8,10] -> "Items 6-8, 10" (single: "Item 6"). */
function gh_itemNumberRanges(array $numbers)
{
    $numbers = array_values(array_unique(array_map('intval', $numbers)));
    sort($numbers);
    $ranges = [];
    $start = $prev = null;
    foreach ($numbers as $n) {
        if ($start === null) { $start = $prev = $n; continue; }
        if ($n === $prev + 1) { $prev = $n; continue; }
        $ranges[] = $start === $prev ? (string) $start : $start . "\u{2013}" . $prev;
        $start = $prev = $n;
    }
    if ($start !== null) {
        $ranges[] = $start === $prev ? (string) $start : $start . "\u{2013}" . $prev;
    }
    return (count($numbers) === 1 ? 'Item ' : 'Items ') . implode(', ', $ranges);
}

/**
 * Raw scores for a set of items: [item_id][student_id] => raw_score.
 * @return array
 */
function getAssessmentScoresMap($item_ids) {
    $map = [];
    if (empty($item_ids)) return $map;

    $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
    $rows = getRecords("SELECT * FROM assessment_scores WHERE item_id IN ($placeholders)", $item_ids);

    foreach ($rows as $row) {
        $map[$row['item_id']][$row['student_id']] = $row['raw_score'];
    }
    return $map;
}

/**
 * Percentage Score for a set of items:
 *     PS = (Total Raw Score / Total Highest Possible Score) x 100
 *
 * This is deliberately NOT the mean of the individual item percentages —
 * a 50-point test must carry more weight inside the category than a
 * 10-point quiz. Items with no score encoded yet are skipped entirely
 * (their HPS is not added), so a blank never behaves like a zero.
 *
 * @return float|null null when nothing has been encoded yet
 */
function computeCategoryPercent($items, $scores_map, $student_id) {
    $raw_total = 0.0;
    $hps_total = 0.0;

    foreach ($items as $item) {
        $raw = $scores_map[$item['item_id']][$student_id] ?? null;
        if ($raw === null || $raw === '') continue;
        $raw_total += (float)$raw;
        $hps_total += (float)$item['highest_score'];
    }

    if ($hps_total <= 0) return null;
    // Official ECR: PS = ROUND((total raw / total HPS) * 100, 2)
    return gh_clampPercent(round(($raw_total / $hps_total) * 100, 2));
}

/**
 * Percentage Score for one required domain inside a component
 * (GMRC / Values Education).
 * @return float|null
 */
function computeDomainPercent($items, $scores_map, $student_id, $domain) {
    $subset = [];
    foreach ($items as $item) {
        if (($item['assessment_domain'] ?? null) === $domain) {
            $subset[] = $item;
        }
    }
    if (empty($subset)) return null;
    return computeCategoryPercent($subset, $scores_map, $student_id);
}

/**
 * Build the EXAMINATIONS (EX) percentage score.
 *
 * exam_mode 'standard':
 *     Each of ST1, ST2 and TE is first converted to its OWN percentage
 *     score, then combined 30% / 30% / 40%. Pooling the raw scores would
 *     make each test's influence depend on its HPS, which is wrong.
 *         EX = (ST1% x 0.30) + (ST2% x 0.30) + (TE% x 0.40)
 *
 * exam_mode 'term_exam_only':
 *     EX = Term Examination percentage score. No ST1, no ST2.
 *
 * exam_mode 'none':
 *     There is no EX component.
 *
 * Legacy fallback: if QA items exist but NONE carry a subtype, we cannot
 * apply the 30/30/40 split, so the pooled percentage is used and the
 * result is flagged so the teacher is told to tag the items.
 *
 * @return array ['percent' => float|null, 'complete' => bool,
 *                'parts' => array, 'missing' => array, 'notice' => string|null]
 */
function computeExamPercent($qa_items, $scores_map, $student_id, $exam_mode) {
    if ($exam_mode === 'none') {
        return ['percent' => null, 'complete' => true, 'parts' => [], 'missing' => [], 'notice' => null];
    }

    if (empty($qa_items)) {
        return ['percent' => null, 'complete' => false, 'parts' => [], 'missing' => [], 'notice' => null];
    }

    $subtypes = gh_examSubtypes();
    $required = ($exam_mode === 'term_exam_only') ? ['TE'] : ['ST1', 'ST2', 'TE'];

    $tagged = false;
    foreach ($qa_items as $item) {
        if (!empty($item['assessment_subtype'])) { $tagged = true; break; }
    }

    if (!$tagged) {
        $pooled = computeCategoryPercent($qa_items, $scores_map, $student_id);
        return [
            'percent'  => $pooled,
            'complete' => $pooled !== null,
            'parts'    => [],
            'missing'  => [],
            'notice'   => 'Examination items are not tagged as Summative Test 1, Summative Test 2 or Term '
                        . 'Examination, so the required 30% / 30% / 40% split cannot be applied. The pooled '
                        . 'percentage is being used — tag each item in Manage Assessment Items.',
        ];
    }

    $parts   = [];
    $missing = [];
    $total   = 0.0;
    $used_weight = 0.0;

    foreach ($required as $code) {
        $subset = [];
        foreach ($qa_items as $item) {
            if (($item['assessment_subtype'] ?? null) === $code) {
                $subset[] = $item;
            }
        }

        $pct = empty($subset) ? null : computeCategoryPercent($subset, $scores_map, $student_id);
        $parts[$code] = $pct;

        if ($pct === null) {
            $missing[] = $code;
            continue;
        }

        $weight = ($exam_mode === 'term_exam_only') ? 1.0 : $subtypes[$code]['weight'];
        // Official ECR: WS ST1 = ROUND(ST1 / HPS * 30, 2), and likewise for
        // ST2 (30) and TE (40). The EX percentage score is their SUM.
        $total += round($pct * $weight, 2);
        $used_weight += $weight;
    }

    // A missing required test must NOT be treated as a zero, and must not
    // produce a completed official Term Grade.
    if (!empty($missing)) {
        return [
            'percent'  => ($used_weight > 0) ? gh_clampPercent(round($total / $used_weight, 2)) : null,
            'complete' => false,
            'parts'    => $parts,
            'missing'  => $missing,
            'notice'   => null,
        ];
    }

    return [
        'percent'  => gh_clampPercent(round($total, 2)),
        'complete' => true,
        'parts'    => $parts,
        'missing'  => [],
        'notice'   => null,
    ];
}

/**
 * Build the six GMRC / Values Education domain percentage scores.
 * @return array ['percents' => [key => float|null], 'missing' => array]
 */
function computeDomainPercents($ww_items, $pt_items, $qa_items, $scores_map, $student_id, $exam_mode) {
    $percents = [];
    $missing  = [];

    $percents['ww_cognitive']  = computeDomainPercent($ww_items, $scores_map, $student_id, 'Cognitive');
    $percents['ww_affective']  = computeDomainPercent($ww_items, $scores_map, $student_id, 'Affective');
    $percents['pt_cognitive']  = computeDomainPercent($pt_items, $scores_map, $student_id, 'Cognitive');
    $percents['pt_affective']  = computeDomainPercent($pt_items, $scores_map, $student_id, 'Affective');
    $percents['pt_behavioral'] = computeDomainPercent($pt_items, $scores_map, $student_id, 'Behavioral');

    $exam = computeExamPercent($qa_items, $scores_map, $student_id, $exam_mode);
    $percents['ex'] = $exam['percent'];

    foreach ($percents as $key => $val) {
        if ($val === null) $missing[] = $key;
    }

    return ['percents' => $percents, 'missing' => $missing, 'exam' => $exam];
}

// ============================================================
// SECTION 13 — INITIAL GRADE AND TERM GRADE
// ============================================================

/**
 * Initial Grade from component percentage scores.
 *     IG = (WW% x ww) + (PT% x pt) + (EX% x ex)
 * No intermediate rounding is applied before transmutation.
 *
 * When a category has a zero weight (Research, Work Immersion) its
 * percentage is ignored. When a weighted category has no score yet the
 * remaining weights are renormalised so the IG is not silently deflated
 * by treating the blank as a zero; the caller is told it is incomplete.
 *
 * @return array ['initial_grade' => float|null, 'complete' => bool, 'missing' => array]
 */
function computeInitialGrade($weights, $ww, $pt, $ex) {
    $components = [
        'WW' => ['pct' => $ww, 'w' => (float)($weights['ww'] ?? 0)],
        'PT' => ['pct' => $pt, 'w' => (float)($weights['pt'] ?? 0)],
        'EX' => ['pct' => $ex, 'w' => (float)($weights['ex'] ?? 0)],
    ];

    $total        = 0.0;
    $used_weight  = 0.0;
    $missing      = [];

    // OFFICIAL ROUNDING SEQUENCE. The DepEd ECR spreadsheet rounds at every
    // step, and we reproduce it exactly so this system and the official
    // workbook never disagree by a point at a transmutation band boundary:
    //     PS (percentage score) = ROUND(total / HPS * 100, 2)   [upstream]
    //     WS (weighted score)   = ROUND(PS * weight, 2)         [here]
    //     IG                    = ROUND(SUM of WS, 2)           [here]
    foreach ($components as $code => $c) {
        if ($c['w'] <= 0) continue;             // not part of this category
        if ($c['pct'] === null) {
            $missing[] = $code;
            continue;
        }
        $total       += round(((float)$c['pct']) * $c['w'], 2);
        $used_weight += $c['w'];
    }

    if ($used_weight <= 0) {
        return ['initial_grade' => null, 'complete' => false, 'missing' => $missing];
    }

    // Renormalise only when something is missing. A complete record is
    // computed with the exact official weights.
    $ig = empty($missing) ? $total : ($total / $used_weight);
    $ig = round($ig, 2);

    return [
        'initial_grade' => gh_clampPercent($ig),
        'complete'      => empty($missing),
        'missing'       => $missing,
    ];
}

/**
 * Initial Grade for GMRC / Values Education using the six required domains.
 *     IG = (WW Cog x .10) + (WW Aff x .10)
 *        + (PT Cog x .10) + (PT Aff x .10) + (PT Beh x .30)
 *        + (EX x .30)
 * @return array ['initial_grade' => float|null, 'complete' => bool, 'missing' => array]
 */
function computeInitialGradeWithDomains($domain_percents) {
    $weights = gh_domainWeights();

    $total       = 0.0;
    $used_weight = 0.0;
    $missing     = [];

    foreach ($weights as $key => $meta) {
        $pct = $domain_percents[$key] ?? null;
        if ($pct === null) {
            $missing[] = $key;
            continue;
        }
        // Same official rounding sequence as computeInitialGrade(): each
        // domain's weighted score is rounded to 2dp before it is summed,
        // exactly as the GMRC / Values Education ECR sheet does.
        $total       += round(((float)$pct) * $meta['weight'], 2);
        $used_weight += $meta['weight'];
    }

    if ($used_weight <= 0) {
        return ['initial_grade' => null, 'complete' => false, 'missing' => $missing];
    }

    $ig = round(empty($missing) ? $total : ($total / $used_weight), 2);

    return [
        'initial_grade' => gh_clampPercent($ig),
        'complete'      => empty($missing),
        'missing'       => $missing,
    ];
}

/**
 * THE central term-grade computation. Every encoding page must use this.
 *
 * @param array      $weights  from getSubjectWeights()
 * @param array      $components ['ww'=>?, 'pt'=>?, 'ex'=>?, 'domains'=>?]
 *                   percentages 0-100 or null for "not encoded"
 * @param array|int|null $sy
 * @return array {
 *     initial_grade    float|null  (2dp, for storage/display only)
 *     transmuted_grade int|null    the REPORTED Term Grade
 *     remarks          string      'Passed' | 'Failed' | 'Incomplete'
 *     descriptor       string|null
 *     complete         bool
 *     missing          array
 *     weights          array
 * }
 */
function computeTermGrade($weights, $components, $sy = null) {
    if (!empty($weights['blocked'])) {
        return [
            'initial_grade' => null, 'transmuted_grade' => null,
            'remarks' => 'Not configured', 'descriptor' => null,
            'complete' => false, 'missing' => ['configuration'], 'weights' => $weights,
        ];
    }

    if (!empty($weights['uses_domains']) && isset($components['domains'])) {
        $result = computeInitialGradeWithDomains($components['domains']);
    } else {
        $result = computeInitialGrade(
            $weights,
            $components['ww'] ?? null,
            $components['pt'] ?? null,
            $components['ex'] ?? null
        );
    }

    if ($result['initial_grade'] === null) {
        return [
            'initial_grade' => null, 'transmuted_grade' => null,
            'remarks' => 'Incomplete', 'descriptor' => null,
            'complete' => false, 'missing' => $result['missing'], 'weights' => $weights,
        ];
    }

    $ig = $result['initial_grade'];
    $tg = transmuteGrade($ig, $sy);

    return [
        'initial_grade'    => round($ig, 2),
        'transmuted_grade' => $tg,
        // Pass/fail is decided on the TRANSMUTED grade, never the IG.
        'remarks'          => $result['complete'] ? getPassFailRemark($tg) : 'Incomplete',
        'descriptor'       => getGradeDescriptor($tg, $sy),
        'complete'         => $result['complete'],
        'missing'          => $result['missing'],
        'weights'          => $weights,
    ];
}

// computeQuarterGrade() has been DELETED. It was the compatibility
// wrapper that let a page compute a grade from a bare legacy
// subject_type string without a grade level or curriculum type.
// Call getSubjectWeights() (or getSubjectWeightsForStudent()) followed by
// computeTermGrade() instead — that is the only supported path.

// ============================================================
// SECTION 14 — FINAL GRADE ACROSS TERMS
// ============================================================

/**
 * How many grading periods a subject actually runs for.
 *
 * Grades 4-10 learning areas always run all three terms. Senior High School
 * courses do NOT: the official DepEd Three-Term SSHS E-Class Record carries a
 * 'Number of terms' value per course (1, 2 or 3), and its Final Grade formula
 * is
 *     ROUND(SUM(term grades) * UnitsPerTerm / TotalUnits, 0)
 * where TotalUnits = UnitsPerTerm x NumberOfTerms — which reduces to the mean
 * over the course's OWN number of terms. It also refuses to show a Final Grade
 * until COUNT(term grades) equals that number.
 *
 * Stored in subjects.terms_required. Defaults to 3, so every existing subject
 * keeps its current behaviour until an administrator says otherwise.
 *
 * @param array|string|null $subject subjects row or subject_code
 * @return int 1, 2 or 3
 */
function gh_termsRequired($subject = null) {
    $row = null;

    if (is_array($subject)) {
        $row = $subject;
    } elseif (is_string($subject) && $subject !== '') {
        $row = getRecord("SELECT terms_required FROM subjects WHERE subject_code = ?", [$subject]);
    }

    if (!$row || !array_key_exists('terms_required', $row)) return 3;

    $n = (int)($row['terms_required'] ?? 3);
    return ($n >= 1 && $n <= 3) ? $n : 3;
}

/**
 * The grading periods a subject must have before a Final Grade may be issued.
 * A 1-term course requires Term 1; a 2-term course requires Terms 1-2.
 *
 * @param array|string|null $subject
 * @return array
 */
function gh_requiredTermsFor($subject = null) {
    return array_slice(gh_threeTerms(), 0, gh_termsRequired($subject));
}

/**
 * Final Grade for one learning area.
 *     FG = (Term 1 + Term 2 + Term 3) / 3, rounded to a whole number.
 *
 * A Final Grade is only COMPLETE when every grading period of the school
 * year has a reported grade. With a term still missing, the mean of what
 * exists is returned as a PROVISIONAL average and must be labelled as
 * such — it is not a Final Grade.
 *
 * @param array      $term_grades  term label => transmuted grade (or a flat list)
 * @param array|null $valid_terms  required terms; defaults to the SY's terms
 * @param array|int|null $sy
 * @return array {
 *     final_grade          int|null   only set when complete
 *     provisional_average  int|null   set when incomplete
 *     value                int|null   whichever applies, for display
 *     label                string     'Final Grade' | 'Current Average' | ''
 *     is_complete          bool
 *     missing_terms        array
 *     term_grades          array
 * }
 */
/**
 * Final Grades for a multi-component learning area.
 *
 * Each component gets its own Final Grade across the terms, and the
 * learning area's Final Grade is the average of the COMPONENT FINAL
 * GRADES, rounded to a whole number — consistent with how the Term Grade
 * is built. Pass/fail is decided on the overall Final Grade only.
 *
 * @param array $component_term_grades  component => [term => grade]
 * @param array|null $valid_terms
 * @param array|int|null $sy
 * @return array {
 *     components   component => computeSubjectFinalGrade() result
 *     final_grade  int|null   overall, only when every component is complete
 *     provisional_average int|null
 *     value        int|null
 *     label        string
 *     is_complete  bool
 *     remarks      string
 * }
 */
function computeMultiComponentFinalGrade($component_term_grades, $valid_terms = null, $sy = null) {
    if ($valid_terms === null) {
        $valid_terms = getTermsForSchoolYear($sy);
    }

    $per_component = [];
    $values        = [];
    $all_complete  = !empty($component_term_grades);

    foreach ($component_term_grades as $component => $terms) {
        $fg = computeSubjectFinalGrade($terms, $valid_terms, $sy);
        $per_component[$component] = $fg;
        if ($fg['value'] !== null) {
            $values[] = (float)$fg['value'];
        }
        if (!$fg['is_complete']) $all_complete = false;
    }

    if (empty($values)) {
        return [
            'components' => $per_component,
            'final_grade' => null, 'provisional_average' => null, 'value' => null,
            'label' => '', 'is_complete' => false, 'remarks' => 'Incomplete',
        ];
    }

    $rounded = (int)round(array_sum($values) / count($values));

    return [
        'components'          => $per_component,
        'final_grade'         => $all_complete ? $rounded : null,
        'provisional_average' => $all_complete ? null : $rounded,
        'value'               => $rounded,
        'label'               => $all_complete ? 'Final Grade' : 'Current Average',
        'is_complete'         => $all_complete,
        // Decided on the OVERALL grade — a single weak component does not
        // fail the learning area.
        'remarks'             => $all_complete ? getPassFailRemark($rounded) : 'Incomplete',
    ];
}

function computeSubjectFinalGrade($term_grades, $valid_terms = null, $sy = null, $subject = null) {
    if ($valid_terms === null) {
        // A Senior High School course may officially run for fewer than
        // three terms. Requiring all three would withhold a Final Grade the
        // learner has actually earned.
        $valid_terms = ($subject === null)
            ? getTermsForSchoolYear($sy)
            : gh_requiredTermsFor($subject);
    }

    $by_term = [];
    $is_assoc = false;
    foreach (array_keys((array)$term_grades) as $k) {
        if (!is_int($k)) { $is_assoc = true; break; }
    }

    if ($is_assoc) {
        foreach ($valid_terms as $t) {
            $g = $term_grades[$t] ?? null;
            if ($g !== null && $g !== '' && $g !== false) {
                $by_term[$t] = (float)$g;
            }
        }
    } else {
        $vals = array_values(array_filter((array)$term_grades, function ($g) {
            return $g !== null && $g !== '' && $g !== false;
        }));
        foreach ($vals as $i => $g) {
            $key = $valid_terms[$i] ?? ('Term ' . ($i + 1));
            $by_term[$key] = (float)$g;
        }
    }

    $missing = [];
    foreach ($valid_terms as $t) {
        if (!isset($by_term[$t])) $missing[] = $t;
    }

    if (empty($by_term)) {
        return [
            'final_grade' => null, 'provisional_average' => null, 'value' => null,
            'label' => '', 'is_complete' => false,
            'missing_terms' => $missing, 'term_grades' => [],
        ];
    }

    $average = array_sum($by_term) / count($by_term);
    $rounded = (int)round($average);

    if (empty($missing)) {
        return [
            'final_grade' => $rounded, 'provisional_average' => null, 'value' => $rounded,
            'label' => 'Final Grade', 'is_complete' => true,
            'missing_terms' => [], 'term_grades' => $by_term,
        ];
    }

    return [
        'final_grade' => null, 'provisional_average' => $rounded, 'value' => $rounded,
        'label' => 'Current Average', 'is_complete' => false,
        'missing_terms' => $missing, 'term_grades' => $by_term,
    ];
}

// computeFinalRating() has been DELETED. It averaged whatever term
// grades it was handed without checking whether all three terms were
// present, so its result could be presented as a Final Grade when it was
// really a provisional average. Use computeSubjectFinalGrade(), which
// reports 'Final Grade' only when Term 1, Term 2 and Term 3 all exist.

/**
 * Final grade for a student in one subject across the school year.
 * @return array computeSubjectFinalGrade() shape plus 'term_grades'
 */
function getStudentFinalGrade($student_id, $subject_code, $section_id, $school_year_id) {
    // A Final Grade is issued once the subject's OWN required terms are all
    // present — three for Grades 4-10, and for an SHS course whatever its
    // subjects.terms_required says (1, 2 or 3).
    $valid_terms = gh_requiredTermsFor($subject_code);

    // Only valid, completed Term 1-3 records may feed a Final Grade.
    $grades = getRecords("
        SELECT term, transmuted_grade, remarks FROM grades
        WHERE student_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ?
          AND " . gh_validTermSql('') . "
    ", [$student_id, $subject_code, $section_id, $school_year_id]);

    $term_grades = [];
    foreach ($grades as $g) {
        if (!gh_isValidGradeRow($g)) continue;
        $term_grades[$g['term']] = floatval($g['transmuted_grade']);
    }

    return computeSubjectFinalGrade($term_grades, $valid_terms, $school_year_id);
}

// ============================================================
// SECTION 15 — GENERAL AVERAGE
// ============================================================

/**
 * General Average across learning areas.
 *
 * Key Stage 2 / 3: the mean of the Final Grades, as a whole number.
 *
 * Key Stage 4 (SHS): the General Average must be weighted by each
 * subject's equivalent units. This system stores subjects.units, but if
 * every subject carries the same default value the column has clearly
 * never been configured, and a unit-weighted average cannot be produced.
 * In that case NO General Average is returned — we do not fabricate unit
 * values, and we do not present an unweighted mean as if it were the
 * official SHS General Average.
 *
 * @param array $subject_grades list of ['final_grade' => int, 'units' => int|null]
 * @param bool  $is_shs
 * @return array {
 *     general_average float|null
 *     rounded         int|null
 *     is_weighted     bool
 *     needs_config    bool
 *     message         string|null
 * }
 */
function computeGeneralAverage($subject_grades, $is_shs = false) {
    $values = [];
    $units  = [];

    foreach ($subject_grades as $sg) {
        $g = $sg['final_grade'] ?? null;
        if ($g === null || $g === '' || $g === false) continue;
        $values[] = (float)$g;
        $units[]  = isset($sg['units']) && $sg['units'] !== null ? (float)$sg['units'] : null;
    }

    if (empty($values)) {
        return ['general_average' => null, 'rounded' => null, 'is_weighted' => false,
                'needs_config' => false, 'message' => null];
    }

    if (!$is_shs) {
        $avg = array_sum($values) / count($values);
        return ['general_average' => round($avg, 2), 'rounded' => (int)round($avg),
                'is_weighted' => false, 'needs_config' => false, 'message' => null];
    }

    // --- SHS: require configured, non-uniform equivalent units ---------
    $has_null   = in_array(null, $units, true);
    $distinct   = array_unique(array_filter($units, function ($u) { return $u !== null; }));
    $unconfigured = $has_null || count($distinct) <= 1;

    if ($unconfigured) {
        gh_addConfigNotice(
            'shs_equivalent_units',
            'Senior High School equivalent units are not configured (every subject currently carries the same '
            . 'default unit value). A unit-weighted SHS General Average cannot be computed until the equivalent '
            . 'units for each SHS subject are entered in Subjects. No General Average is shown for SHS learners '
            . 'until then.'
        );
        return [
            'general_average' => null, 'rounded' => null, 'is_weighted' => false,
            'needs_config' => true,
            'message' => 'SHS General Average unavailable — equivalent units are not configured.',
        ];
    }

    $weighted = 0.0;
    $total_units = 0.0;
    foreach ($values as $i => $v) {
        $u = $units[$i];
        $weighted += $v * $u;
        $total_units += $u;
    }

    $avg = $weighted / $total_units;
    return ['general_average' => round($avg, 2), 'rounded' => (int)round($avg),
            'is_weighted' => true, 'needs_config' => false, 'message' => null];
}

// ============================================================
// SECTION 16 — TERM FINALIZATION
// ============================================================
// Manage Assessment Items (assessment_items + assessment_scores) is the
// system of record for a class's raw scores throughout the term. The
// `grades` table (Initial Grade / Term Grade / Remarks) -- and
// `grade_components` for multi-component learning areas -- is written
// ONLY when a teacher explicitly finalizes a term for a class in
// modules/grading/index.php (step 5). It is never written as a side
// effect of saving a raw score.
//
// `term_finalizations` records that a (teacher, subject, section, school
// year, term) has been finalized, by whom and when. Its presence is the
// ONLY signal that a class's `grades` row is official; every page that
// needs to know whether a term is finalized must go through the two
// functions below rather than querying that table directly.

/**
 * The finalization record for a class's term, or null if it has not been
 * finalized yet.
 * @return array|null
 */
function gh_getTermFinalization($teacher_id, $subject_code, $section_id, $sy_id, $term) {
    gh_ensureTermFinalizationsTable();
    // getRecord() returns false (not null) when the query runs fine but
    // matches no row -- PDOStatement::fetch() itself returns false at
    // end-of-results. Normalizing that to null here keeps this function's
    // documented array|null contract, so every caller's `!== null` /
    // `=== null` check (e.g. $is_finalized in modules/grading/index.php)
    // behaves correctly instead of treating "not finalized yet" as
    // finalized.
    $row = getRecord(
        "SELECT * FROM term_finalizations
         WHERE teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ?",
        [$teacher_id, $subject_code, $section_id, $sy_id, $term]
    );
    return $row === false ? null : $row;
}

/**
 * Has this teacher finalized this class's term?
 * @return bool
 */
function gh_isTermFinalized($teacher_id, $subject_code, $section_id, $sy_id, $term) {
    return gh_getTermFinalization($teacher_id, $subject_code, $section_id, $sy_id, $term) !== null;
}

/**
 * Make sure `term_finalizations` exists and has every column the Finalize
 * workflow writes. On a database where database/migrations/2026_term_finalization.sql
 * was never run (or an older copy of it was), Finalize used to compute the grades,
 * say "finalized", and silently record nothing -- so the class never counted as
 * finalized anywhere (Encode Grades, Term Transition). Additive and idempotent:
 * CREATE TABLE IF NOT EXISTS plus ADD COLUMN only for a column that is missing;
 * existing rows are never touched. Runs at most once per request.
 */
function gh_ensureTermFinalizationsTable() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('term_finalizations_v1')) return;
    executeQuery(
        "CREATE TABLE IF NOT EXISTS term_finalizations (
            finalization_id INT(11) NOT NULL AUTO_INCREMENT,
            teacher_id INT(11) NOT NULL,
            subject_code VARCHAR(20) NOT NULL,
            section_id INT(11) NOT NULL,
            school_year_id INT(11) NOT NULL,
            term VARCHAR(20) NOT NULL,
            finalized_by INT(11) DEFAULT NULL,
            finalized_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            student_count INT(11) NOT NULL DEFAULT 0,
            complete_count INT(11) NOT NULL DEFAULT 0,
            incomplete_count INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (finalization_id),
            UNIQUE KEY uniq_term_finalization (teacher_id, subject_code, section_id, school_year_id, term)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $have = [];
    foreach (getRecords("SHOW COLUMNS FROM term_finalizations") ?: [] as $c) {
        $have[strtolower($c['Field'])] = true;
    }
    if (!$have) return;   // could not inspect the table; leave it alone
    $wanted = [
        'finalized_by'     => "ADD COLUMN finalized_by INT(11) DEFAULT NULL",
        'finalized_at'     => "ADD COLUMN finalized_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        'student_count'    => "ADD COLUMN student_count INT(11) NOT NULL DEFAULT 0",
        'complete_count'   => "ADD COLUMN complete_count INT(11) NOT NULL DEFAULT 0",
        'incomplete_count' => "ADD COLUMN incomplete_count INT(11) NOT NULL DEFAULT 0",
    ];
    foreach ($wanted as $col => $ddl) {
        if (empty($have[$col])) {
            executeQuery("ALTER TABLE term_finalizations $ddl");
        }
    }
    schemaMarkReady('term_finalizations_v1');
}

/**
 * Record (or refresh) that a term has been finalized for a class. Safe to
 * call more than once — a later call (a "re-finalize" after a score
 * correction in Manage Assessment Items) simply updates the timestamp and
 * counts; it never creates a second row for the same class/term.
 *
 * @return bool true only when the finalization row really exists afterwards
 *              (the write is read back). On failure the database's message is
 *              left in $GLOBALS['gh_term_finalization_error'] so the caller
 *              can tell the teacher instead of claiming the term was finalized.
 */
function gh_recordTermFinalization($teacher_id, $subject_code, $section_id, $sy_id, $term, $finalized_by, $student_count, $complete_count, $incomplete_count) {
    global $db;
    $GLOBALS['gh_term_finalization_error'] = '';
    gh_ensureTermFinalizationsTable();
    try {
        $stmt = $db->prepare(
            "INSERT INTO term_finalizations
                (teacher_id, subject_code, section_id, school_year_id, term, finalized_by, student_count, complete_count, incomplete_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                finalized_by = VALUES(finalized_by), finalized_at = CURRENT_TIMESTAMP,
                student_count = VALUES(student_count), complete_count = VALUES(complete_count),
                incomplete_count = VALUES(incomplete_count)"
        );
        $stmt->execute([$teacher_id, $subject_code, $section_id, $sy_id, $term, $finalized_by, $student_count, $complete_count, $incomplete_count]);
    } catch (PDOException $e) {
        error_log('gh_recordTermFinalization failed: ' . $e->getMessage());
        $GLOBALS['gh_term_finalization_error'] = $e->getMessage();
        return false;
    }
    // Read it back with the exact key every other page uses.
    if (gh_getTermFinalization($teacher_id, $subject_code, $section_id, $sy_id, $term) === null) {
        $GLOBALS['gh_term_finalization_error'] = 'the finalization row could not be read back after saving';
        return false;
    }
    return true;
}

// ============================================================
// SECTION 17 — TERM WINDOWS (Start/End Dates) & ASSESSMENT PLAN
// ============================================================
// DepEd Three-Term calendar support: a Teacher/Administrator sets a Start
// Date and End Date for each Term (school-wide, one row per school year +
// term). This never gates encoding or Finalize by itself -- a class can
// still be finalized any time the teacher clicks the button -- it only
// drives the real-time status label (Upcoming / Ongoing / Ended) shown in
// Manage Assessment Items and Encode Grades, so a teacher knows when a term
// is "Ready for Finalization" without having to track calendar dates by
// hand.

/**
 * The Start/End Date row for one school year + term, or null if not set yet.
 * @return array|null {window_id, school_year_id, term, start_date, end_date, updated_by, updated_at}
 */
function gh_getTermWindow($sy_id, $term) {
    gh_ensureTermWindowsTable();
    return getRecord(
        "SELECT * FROM term_windows WHERE school_year_id = ? AND term = ?",
        [$sy_id, $term]
    );
}

/**
 * Make sure the term_windows table exists (same definition as
 * database/migrations/2026_term_windows_and_plans.sql). That table used to
 * exist only if somebody had run the migration by hand; on a database where
 * it was never created, every read quietly returned "dates not set" and every
 * save quietly did nothing while still saying "saved". Additive and
 * idempotent (CREATE TABLE IF NOT EXISTS) -- an existing table and its rows
 * are never touched. Runs at most once per request.
 */
/**
 * Terms of the school year that have no End Date yet. A class moves to the
 * next term only after its term's End Date (gh_syncSectionTerms), so these
 * are shown as a warning to the administrator and the teachers.
 * @return string[]
 */
function gh_termsWithoutEndDate($sy_id) {
    $out = [];
    foreach (gh_threeTerms() as $t) {
        $w = gh_getTermWindow($sy_id, $t);
        if (!$w || empty($w['end_date'])) $out[] = $t;
    }
    return $out;
}

function gh_ensureTermWindowsTable() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('term_windows_v1')) return;
    executeQuery(
        "CREATE TABLE IF NOT EXISTS term_windows (
            window_id INT AUTO_INCREMENT PRIMARY KEY,
            school_year_id INT NOT NULL,
            term VARCHAR(20) NOT NULL,
            start_date DATE NULL,
            end_date DATE NULL,
            updated_by INT NULL COMMENT 'users.user_id of the Teacher/Administrator who last set these dates',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_term_window (school_year_id, term)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    schemaMarkReady('term_windows_v1');
}

/**
 * Set (or update) a term's Start/End Date. Either date may be left blank
 * (null) -- a term can have only a Start Date set yet, for instance.
 * @return bool true when the dates were really written. On failure the
 *              database's message is left in $GLOBALS['gh_term_window_error']
 *              so the caller can tell the administrator instead of
 *              claiming the dates were saved.
 */
function gh_setTermWindow($sy_id, $term, $start_date, $end_date, $updated_by) {
    global $db;
    $GLOBALS['gh_term_window_error'] = '';
    gh_ensureTermWindowsTable();
    try {
        $stmt = $db->prepare(
            "INSERT INTO term_windows (school_year_id, term, start_date, end_date, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                start_date = VALUES(start_date), end_date = VALUES(end_date),
                updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$sy_id, $term, $start_date ?: null, $end_date ?: null, $updated_by]);
        return true;
    } catch (PDOException $e) {
        error_log('gh_setTermWindow failed: ' . $e->getMessage());
        $GLOBALS['gh_term_window_error'] = $e->getMessage();
        return false;
    }
}

/**
 * Real-time status of a term window against TODAY's date -- never cached,
 * always computed fresh so it can never go stale.
 *
 * @param array|null $window  Result of gh_getTermWindow(), or null.
 * @return array {
 *   status: 'not_set'|'upcoming'|'ongoing'|'ended',
 *   label: string           human-readable status, e.g. "Ongoing — 12 days remaining"
 *   days_remaining: int|null   only set while 'ongoing'
 *   badge: string            'secondary'|'info'|'success'|'warning' -- a suggested Bootstrap badge color
 * }
 */
function gh_termWindowStatus($window) {
    $start = $window['start_date'] ?? null;
    $end = $window['end_date'] ?? null;

    if (!$start || !$end) {
        return ['status' => 'not_set', 'label' => 'Dates not set', 'days_remaining' => null, 'badge' => 'secondary'];
    }

    $today = new DateTime('today');
    $start_dt = DateTime::createFromFormat('Y-m-d', substr($start, 0, 10)) ?: new DateTime($start);
    $end_dt = DateTime::createFromFormat('Y-m-d', substr($end, 0, 10)) ?: new DateTime($end);
    $start_dt->setTime(0, 0, 0);
    $end_dt->setTime(0, 0, 0);

    if ($today < $start_dt) {
        $days = (int) $today->diff($start_dt)->days;
        return [
            'status' => 'upcoming',
            'label' => 'Upcoming — starts in ' . $days . ' day' . ($days === 1 ? '' : 's'),
            'days_remaining' => null,
            'badge' => 'info',
        ];
    }

    if ($today > $end_dt) {
        $days_over = (int) $end_dt->diff($today)->days;
        return [
            'status' => 'ended',
            'label' => 'Ended' . ($days_over > 0 ? ' ' . $days_over . ' day' . ($days_over === 1 ? '' : 's') . ' ago' : '') . ' — Ready for Finalization',
            'days_remaining' => 0,
            'badge' => 'warning',
        ];
    }

    $days_remaining = (int) $today->diff($end_dt)->days;
    return [
        'status' => 'ongoing',
        'label' => $days_remaining === 0
            ? 'Ongoing — ends today'
            : 'Ongoing — ' . $days_remaining . ' day' . ($days_remaining === 1 ? '' : 's') . ' remaining',
        'days_remaining' => $days_remaining,
        'badge' => 'success',
    ];
}

/**
 * Which grading term is "live" right now, school-wide (JHS and SHS alike,
 * since the whole school runs on one Term 1/2/3 calendar) -- the term
 * whose Start/End Date window (term_windows, set from Manage Assessment
 * Items or from Teacher Management's "Term Dates" button -- both write
 * the same table) is currently 'ongoing' per gh_termWindowStatus().
 *
 * Used to switch Encode Grades / Assign Subject to Teacher between terms
 * automatically, so nobody has to click a term by hand.
 *
 * @param int $sy_id
 * @return string|null Term 1/2/3, or null if no term's window is
 *                      currently ongoing (dates not set for any term yet,
 *                      or today falls in a gap between two terms).
 */
function gh_getCurrentTerm($sy_id) {
    if (empty($sy_id)) {
        return null;
    }
    foreach (gh_threeTerms() as $t) {
        $status = gh_termWindowStatus(gh_getTermWindow($sy_id, $t));
        if ($status['status'] === 'ongoing') {
            return $t;
        }
    }
    return null;
}

// ============================================================
// SECTION 17b — SECTION TERM TRANSITION (Term 1 -> Term 2 -> Term 3)
// ============================================================
// A class/section moves to the next term by COMPLETION, not by the calendar:
// when every subject taught to the section has been finalized for a term AND
// every enrolled learner has a completed (Passed/Failed) grade in every one
// of those subjects, the term is marked Completed for that section and the
// next term is enabled for it. Term 2 -> Term 3 works the same way.
//
//   * Terms are strictly sequential (Term 2 opens only after Term 1 is
//     Completed, Term 3 only after Term 2).
//   * A Completed term is stored in `section_term_status` and stays Completed.
//     Its grades are never deleted or rewritten -- the grading pages and the
//     other grade writers treat the term as read-only history.
//   * If even one subject is not finalized, or one learner's grade is still
//     Incomplete/missing, the section stays on the current term.
//   * Nothing here changes how any grade is computed. The Start/End Dates set
//     by the administrator (Section 17) stay informational.

/**
 * Additive, idempotent: creates `section_term_status` if it does not exist
 * yet. One row per section + school year + term that has been Completed.
 * Runs at most once per request.
 */
function gh_ensureSectionTermStatusTable() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('section_term_status_v1')) return;
    executeQuery(
        "CREATE TABLE IF NOT EXISTS section_term_status (
            status_id INT AUTO_INCREMENT PRIMARY KEY,
            section_id INT NOT NULL,
            school_year_id INT NOT NULL,
            term VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Completed',
            completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_by INT NULL COMMENT 'users.user_id whose action completed the term (NULL when detected automatically)',
            UNIQUE KEY uniq_section_term (section_id, school_year_id, term)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    schemaMarkReady('section_term_status_v1');
}

/**
 * Is this section's term already Completed (and therefore locked)?
 * A cheap single lookup, safe to call from any grade-writing page.
 * @return bool
 */
function gh_isSectionTermCompleted($section_id, $sy_id, $term) {
    if (empty($section_id) || empty($sy_id) || !isValidTerm($term)) {
        return false;
    }
    gh_ensureSectionTermStatusTable();
    $row = getRecord(
        "SELECT status_id FROM section_term_status WHERE section_id = ? AND school_year_id = ? AND term = ?",
        [(int)$section_id, (int)$sy_id, $term]
    );
    return !empty($row);
}

/**
 * Where does a section stand for ONE term? Read-only: it never writes.
 *
 * A term is "ready" (complete) only when the section has at least one
 * subject and one enrolled learner, EVERY subject has been finalized, and
 * EVERY learner has a grade in EVERY subject that is not Incomplete /
 * Draft / Not configured.
 *
 * @return array {
 *   term, ready(bool), student_count, subject_count, finalized_count,
 *   subjects: [ {subject_code, subject_name, finalized, complete_count,
 *                pending_students: [names], problem: string} ],
 *   reasons: [string]   section-level reasons (no subjects / no learners)
 * }
 */
function gh_sectionTermProgress($section_id, $sy_id, $term) {
    $section_id = (int)$section_id;
    $sy_id = (int)$sy_id;
    $out = [
        'term' => $term, 'ready' => false, 'student_count' => 0,
        'subject_count' => 0, 'finalized_count' => 0, 'subjects' => [], 'reasons' => [],
    ];

    // Subjects taught to this section for this term (any teacher). A Junior
    // High subject has no term and counts in every term; a Senior High
    // subject counts only in its own term.
    $subjects = getRecords(
        "SELECT DISTINCT ts.subject_code, sub.subject_name
         FROM teacher_subjects ts
         INNER JOIN subjects sub ON sub.subject_code = ts.subject_code
         WHERE ts.section_id = ? AND ts.school_year_id = ?
           AND sub.is_active = 1 AND (sub.term IS NULL OR sub.term = ?)
         ORDER BY sub.subject_name",
        [$section_id, $sy_id, $term]
    ) ?: [];

    $students = getRecords(
        "SELECT DISTINCT s.student_id, s.last_name, s.first_name
         FROM students s
         INNER JOIN enrollments e ON e.student_id = s.student_id
         WHERE e.section_id = ? AND e.school_year_id = ? AND e.enrollment_status = 'Approved' AND s.is_active = 1
         ORDER BY s.last_name, s.first_name",
        [$section_id, $sy_id]
    ) ?: [];

    $out['subject_count'] = count($subjects);
    $out['student_count'] = count($students);
    if (!$subjects) { $out['reasons'][] = 'No subjects are assigned to this section yet.'; }
    if (!$students) { $out['reasons'][] = 'No enrolled learners in this section yet.'; }

    gh_ensureTermFinalizationsTable();
    $finalized = [];
    $fin_rows = getRecords(
        "SELECT DISTINCT subject_code FROM term_finalizations WHERE section_id = ? AND school_year_id = ? AND term = ?",
        [$section_id, $sy_id, $term]
    ) ?: [];
    foreach ($fin_rows as $f) {
        $finalized[$f['subject_code']] = true;
    }

    $remark_of = [];   // [subject_code][student_id] => remarks
    $grade_rows = getRecords(
        "SELECT student_id, subject_code, remarks FROM grades WHERE section_id = ? AND school_year_id = ? AND term = ?",
        [$section_id, $sy_id, $term]
    ) ?: [];
    foreach ($grade_rows as $g) {
        $remark_of[$g['subject_code']][(int)$g['student_id']] = (string)$g['remarks'];
    }

    $all_ok = (!empty($subjects) && !empty($students));
    foreach ($subjects as $sub) {
        $code = $sub['subject_code'];
        $is_fin = !empty($finalized[$code]);
        $pending = [];
        foreach ($students as $st) {
            $rem = $remark_of[$code][(int)$st['student_id']] ?? null;
            $done = ($rem !== null && $rem !== '' && !in_array($rem, ['Incomplete', 'Draft', 'Not configured'], true));
            if (!$done) {
                $pending[] = $st['last_name'] . ', ' . $st['first_name'];
            }
        }
        $problem = '';
        if (!$is_fin) {
            $problem = 'Not finalized yet';
        } elseif ($pending) {
            $problem = count($pending) . ' learner' . (count($pending) === 1 ? '' : 's') . ' still Incomplete';
        }
        if ($is_fin) { $out['finalized_count']++; }
        if ($problem !== '') { $all_ok = false; }
        $out['subjects'][] = [
            'subject_code'     => $code,
            'subject_name'     => $sub['subject_name'],
            'finalized'        => $is_fin,
            'complete_count'   => count($students) - count($pending),
            'pending_students' => $pending,
            'problem'          => $problem,
        ];
    }

    $out['ready'] = (bool)$all_ok;
    return $out;
}

/**
 * Evaluate a section's terms in order and RECORD every term that has just
 * become complete, then say which term the section is on now. Call it when a
 * section's grade pages open and right after a Finalize; it is idempotent and
 * never removes or downgrades a term that is already Completed.
 *
 * @param int      $section_id
 * @param int      $sy_id
 * @param int|null $user_id  who triggered it (stored on newly completed terms)
 * @return array {
 *   completed: [terms Completed, in order],
 *   current: the term the section is on now (the last term once all three are Completed),
 *   all_completed: bool,
 *   newly: [terms completed by THIS call],
 *   statuses: [ 'Term 1' => 'completed'|'current'|'locked', ... ],
 *   completed_at: [ term => datetime ],
 *   progress: progress array for the current term (null when all three are Completed)
 * }
 */
function gh_syncSectionTerms($section_id, $sy_id, $user_id = null) {
    $section_id = (int)$section_id;
    $sy_id = (int)$sy_id;
    gh_ensureSectionTermStatusTable();

    $stored = [];
    $stored_rows = getRecords(
        "SELECT term, completed_at FROM section_term_status WHERE section_id = ? AND school_year_id = ?",
        [$section_id, $sy_id]
    ) ?: [];
    foreach ($stored_rows as $r) {
        $stored[$r['term']] = $r['completed_at'];
    }

    $completed = [];
    $completed_at = [];
    $newly = [];
    $progress = null;
    $current = null;

    foreach (gh_threeTerms() as $t) {
        if (isset($stored[$t])) {
            $completed[] = $t;
            $completed_at[$t] = $stored[$t];
            continue;
        }
        $p = gh_sectionTermProgress($section_id, $sy_id, $t);
        // A term ends only after the End Date the administrator set for it
        // (Teacher Management > Term Dates). Finalizing every subject early
        // does not end it; with no End Date set it does not end at all.
        $t_win = gh_getTermWindow($sy_id, $t);
        $t_end = ($t_win && !empty($t_win['end_date'])) ? $t_win['end_date'] : null;
        $t_ended = ($t_end !== null && date('Y-m-d') > $t_end);
        if (!$t_ended) {
            $p['ready'] = false;
            $p['reasons'][] = $t_end !== null
                ? $t . ' ends on ' . date('M j, Y', strtotime($t_end)) . ' (the End Date set by the administrator); the class moves on after that date.'
                : $t . ' has no End Date set yet; the class moves on only after the administrator sets one and that date has passed.';
        }
        if ($p['ready']) {
            executeQuery(
                "INSERT IGNORE INTO section_term_status (section_id, school_year_id, term, status, completed_by)
                 VALUES (?, ?, ?, 'Completed', ?)",
                [$section_id, $sy_id, $t, $user_id ?: null]
            );
            $completed[] = $t;
            $completed_at[$t] = date('Y-m-d H:i:s');
            $newly[] = $t;
            continue;
        }
        $current = $t;
        $progress = $p;
        break;
    }

    $terms = gh_threeTerms();
    $all_completed = ($current === null);
    if ($all_completed) {
        $current = end($terms);
    }

    $statuses = [];
    foreach ($terms as $t) {
        $statuses[$t] = in_array($t, $completed, true) ? 'completed' : ($t === $current ? 'current' : 'locked');
    }

    if ($newly) {
        logActivity(
            $user_id ?: null, 'grading', 'Term completed',
            "Section $section_id: " . implode(' and ', $newly) . ' Completed'
            . ($all_completed ? ' (all terms done)' : ' — ' . $current . ' enabled')
        );
    }

    // All three terms Completed: compute each learner's Final Grades and save
    // the year status (Completed / Promoted). Idempotent -- see Section 17c.
    $year = $all_completed ? gh_recordSectionYearStatus($section_id, $sy_id, $user_id) : null;

    return [
        'completed' => $completed, 'current' => $current, 'all_completed' => $all_completed,
        'newly' => $newly, 'statuses' => $statuses, 'completed_at' => $completed_at, 'progress' => $progress,
        'year' => $year,
    ];
}

// ============================================================
// SECTION 17c — END-OF-YEAR STUDENT STATUS (Completed / Promoted)
// ============================================================
// When a section's Term 3 becomes Completed (Section 17b) -- which means Terms
// 1, 2 and 3 are finalized and every subject grade of every learner is
// complete -- each learner's Final Grades are computed with the EXISTING
// rules (computeSubjectFinalGrade(): the mean of the subject's term grades,
// rounded) and the year result is saved in `student_year_status`, one row
// per learner + school year + grade level.
//
//   * A learner is "Completed / Promoted" only when EVERY learning area has a
//     Final Grade at or above the pass mark (getPassFailRemark()). One
//     failed or missing subject means "Not Promoted" is recorded instead
//     (kept for the record; the admin screens show only Completed / Promoted).
//   * The status is an OVERLAY: enrollments.enrollment_status, the grades and
//     every other enrollment record stay exactly as they are (many pages
//     select on enrollment_status = 'Approved'), and an existing status row
//     is never overwritten (INSERT IGNORE against the unique key).

/** The label shown in the admin Status columns and Enrollment History. */
function gh_yearStatusPromotedLabel() {
    return 'Completed / Promoted';
}

/**
 * Additive, idempotent: creates `student_year_status` if it does not exist
 * yet. Runs at most once per request. Call it before any query that joins
 * the table.
 */
function gh_ensureStudentYearStatusTable() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('student_year_status_v1')) return;
    executeQuery(
        "CREATE TABLE IF NOT EXISTS student_year_status (
            status_id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            school_year_id INT NOT NULL,
            grade_level VARCHAR(20) NOT NULL,
            section_id INT NULL,
            enrollment_id INT NULL,
            status VARCHAR(30) NOT NULL,
            general_average DECIMAL(5,2) NULL,
            subject_count INT NOT NULL DEFAULT 0,
            failed_count INT NOT NULL DEFAULT 0,
            failed_subjects TEXT NULL,
            computed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            computed_by INT NULL,
            UNIQUE KEY uniq_student_year_grade (student_id, school_year_id, grade_level),
            KEY idx_year_status_sy (school_year_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    schemaMarkReady('student_year_status_v1');
}

/**
 * Compute one learner's year result from the saved grades. Read-only.
 *
 * Learning areas = every active subject assigned to the learner's section for
 * the school year. A subject that belongs to ONE term (Senior High subjects
 * carry subjects.term) is judged on that term only; every other subject uses
 * its own required terms (gh_requiredTermsFor(): normally all three).
 *
 * @return array|null {status: 'Completed / Promoted'|'Not Promoted',
 *   general_average: float|null, subject_count, failed_count,
 *   subjects: [{subject_code, subject_name, final_grade, is_complete, passed}],
 *   failed_subjects: string}  -- null when the section has no subjects at all
 */
function gh_computeStudentYearResult($student_id, $section_id, $sy_id, $grade_level = null) {
    $subjects = getRecords(
        "SELECT DISTINCT ts.subject_code, sub.subject_name, sub.term, sub.terms_required
         FROM teacher_subjects ts
         INNER JOIN subjects sub ON sub.subject_code = ts.subject_code
         WHERE ts.section_id = ? AND ts.school_year_id = ? AND sub.is_active = 1
         ORDER BY sub.subject_name",
        [(int)$section_id, (int)$sy_id]
    ) ?: [];
    if (!$subjects) return null;

    $rows = [];
    $finals = [];
    $failed = [];
    $all_complete = true;
    foreach ($subjects as $sub) {
        $valid_terms = !empty($sub['term']) ? [$sub['term']] : gh_requiredTermsFor($sub);
        $grades = getRecords(
            "SELECT term, transmuted_grade, remarks FROM grades
             WHERE student_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ?
               AND " . gh_validTermSql(''),
            [(int)$student_id, $sub['subject_code'], (int)$section_id, (int)$sy_id]
        ) ?: [];
        $term_grades = [];
        foreach ($grades as $g) {
            if (!gh_isValidGradeRow($g)) continue;
            $term_grades[$g['term']] = (float)$g['transmuted_grade'];
        }
        $fg = computeSubjectFinalGrade($term_grades, $valid_terms, $sy_id, $sub);
        $complete = !empty($fg['is_complete']);
        $passed = $complete && getPassFailRemark($fg['final_grade']) === 'Passed';
        if (!$complete) { $all_complete = false; }
        if (!$passed)   { $failed[] = $sub['subject_name'] . ($complete ? ' (' . $fg['final_grade'] . ')' : ' (incomplete)'); }
        if ($complete)  { $finals[] = (float)$fg['final_grade']; }
        $rows[] = [
            'subject_code' => $sub['subject_code'], 'subject_name' => $sub['subject_name'],
            'final_grade' => $complete ? $fg['final_grade'] : null, 'is_complete' => $complete, 'passed' => $passed,
        ];
    }

    // The mean of the Final Grades is the General Average for Grades 7-10. A
    // Senior High General Average is unit-weighted (see computeGeneralAverage()),
    // so none is stored here rather than an unweighted number posing as official.
    $avg = null;
    if ($all_complete && $finals && !($grade_level && gh_isSeniorHigh($grade_level))) {
        $ga = computeGeneralAverage(array_map(function ($f) { return ['final_grade' => $f]; }, $finals), false);
        $avg = $ga['general_average'];
    }

    return [
        'status'          => $failed ? 'Not Promoted' : gh_yearStatusPromotedLabel(),
        'general_average' => $avg,
        'subject_count'   => count($rows),
        'failed_count'    => count($failed),
        'subjects'        => $rows,
        'failed_subjects' => implode('; ', $failed),
    ];
}

/**
 * Save the year status of every approved learner of a section that does not
 * have one yet. Only meaningful once the section's Term 3 is Completed;
 * gh_syncSectionTerms() calls it then. Idempotent -- a learner who already has
 * a row for that school year + grade level is left exactly as is.
 *
 * @return array {created: int, promoted: int, not_promoted: int}
 */
function gh_recordSectionYearStatus($section_id, $sy_id, $user_id = null) {
    $out = ['created' => 0, 'promoted' => 0, 'not_promoted' => 0];
    gh_ensureStudentYearStatusTable();
    $students = getRecords(
        "SELECT s.student_id, e.enrollment_id, e.grade_level
         FROM students s
         INNER JOIN enrollments e ON e.student_id = s.student_id
         WHERE e.section_id = ? AND e.school_year_id = ? AND e.enrollment_status = 'Approved' AND s.is_active = 1",
        [(int)$section_id, (int)$sy_id]
    ) ?: [];
    if (!$students) return $out;

    $have = [];
    foreach (getRecords(
        "SELECT student_id, grade_level FROM student_year_status WHERE school_year_id = ?", [(int)$sy_id]
    ) ?: [] as $r) {
        $have[(int)$r['student_id'] . '|' . $r['grade_level']] = true;
    }

    foreach ($students as $st) {
        if (isset($have[(int)$st['student_id'] . '|' . $st['grade_level']])) continue;
        $res = gh_computeStudentYearResult($st['student_id'], $section_id, $sy_id, $st['grade_level']);
        if ($res === null) continue;
        executeQuery(
            "INSERT IGNORE INTO student_year_status
                (student_id, school_year_id, grade_level, section_id, enrollment_id, status,
                 general_average, subject_count, failed_count, failed_subjects, computed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [(int)$st['student_id'], (int)$sy_id, $st['grade_level'], (int)$section_id, (int)$st['enrollment_id'],
             $res['status'], $res['general_average'], $res['subject_count'], $res['failed_count'],
             $res['failed_subjects'] !== '' ? $res['failed_subjects'] : null, $user_id ?: null]
        );
        $out['created']++;
        if ($res['status'] === gh_yearStatusPromotedLabel()) { $out['promoted']++; } else { $out['not_promoted']++; }
    }

    if ($out['created'] > 0) {
        logActivity(
            $user_id ?: null, 'grading', 'Year status computed',
            "Section $section_id, SY $sy_id: {$out['promoted']} Completed / Promoted, {$out['not_promoted']} Not Promoted"
        );
    }
    return $out;
}

/**
 * Catch-up for sections whose Term 3 was Completed before this feature
 * existed (or by a path that skipped it): saves any missing year status for
 * the school year. Cheap, and throttled to once every 5 minutes per session
 * because the admin pages call it on load.
 */
function gh_sweepYearStatuses($sy_id) {
    if (empty($sy_id)) return;
    if (isset($_SESSION['gh_year_sweep'][(int)$sy_id]) && time() - $_SESSION['gh_year_sweep'][(int)$sy_id] < 300) return;
    $_SESSION['gh_year_sweep'][(int)$sy_id] = time();
    gh_ensureSectionTermStatusTable();
    gh_ensureStudentYearStatusTable();
    $secs = getRecords(
        "SELECT section_id FROM section_term_status WHERE school_year_id = ? AND term = 'Term 3'", [(int)$sy_id]
    ) ?: [];
    foreach ($secs as $r) {
        gh_recordSectionYearStatus((int)$r['section_id'], (int)$sy_id, null);
    }
}

/**
 * The configurable assessment plan for one class/term -- how many items the
 * teacher/school EXPECTS per category (e.g. 5 quizzes + 3 activities under
 * Written Works, 5 Performance Tasks). Purely informational: it is never
 * read by any grade computation and never limits how many items can
 * actually be added -- it only powers an "X of N added" progress badge in
 * Manage Assessment Items.
 * @return array ['WW'=>int,'PT'=>int,'QA'=>int]
 */
function gh_getAssessmentPlan($teacher_id, $subject_code, $section_id, $sy_id, $term) {
    $plan = ['WW' => 0, 'PT' => 0, 'QA' => 0];
    $rows = getRecords(
        "SELECT category, planned_count FROM assessment_plans
         WHERE teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ?",
        [$teacher_id, $subject_code, $section_id, $sy_id, $term]
    );
    foreach ($rows as $row) {
        if (array_key_exists($row['category'], $plan)) {
            $plan[$row['category']] = (int) $row['planned_count'];
        }
    }
    return $plan;
}

/**
 * Set (or update) how many items the teacher/school expects for ONE
 * category this term. A count of 0 means "no plan set" (shown as no badge).
 */
function gh_setAssessmentPlanCount($teacher_id, $subject_code, $section_id, $sy_id, $term, $category, $planned_count) {
    executeQuery(
        "INSERT INTO assessment_plans (teacher_id, subject_code, section_id, school_year_id, term, category, planned_count)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE planned_count = VALUES(planned_count), updated_at = CURRENT_TIMESTAMP",
        [$teacher_id, $subject_code, $section_id, $sy_id, $term, $category, max(0, (int) $planned_count)]
    );
}

// ============================================================
// SECTION 18 — MISSING ASSESSMENTS (per student, per class/term)
// ============================================================
// Used by the AI Analytics At-Risk list (includes/risk_helpers.php) so a
// teacher sees WHICH specific item(s) a student still hasn't answered,
// instead of just a bare "Incomplete" label.

/**
 * Every active assessment item for a class/term that this ONE student has
 * no raw score for yet (never scored, not merely scored 0 -- a real zero
 * still counts as "answered").
 * @return string[] e.g. ["Quiz 2 (Written Works)", "Term Exam (Examinations)"]
 */
function gh_getMissingAssessmentItemsForStudent($teacher_id, $subject_code, $section_id, $sy_id, $term, $student_id) {
    if (empty($teacher_id) || empty($section_id)) {
        return [];
    }
    $items = getRecords(
        "SELECT item_id, item_name, category FROM assessment_items
         WHERE teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ? AND is_active = 1
         ORDER BY category, item_order",
        [$teacher_id, $subject_code, $section_id, $sy_id, $term]
    );
    if (empty($items)) {
        return [];
    }

    $item_ids = array_column($items, 'item_id');
    $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
    $scored = getRecords(
        "SELECT item_id FROM assessment_scores WHERE student_id = ? AND raw_score IS NOT NULL AND item_id IN ($placeholders)",
        array_merge([$student_id], $item_ids)
    );
    $scored_ids = array_map('intval', array_column($scored, 'item_id'));

    $cat_labels = ['WW' => 'Written/Oral Works', 'PT' => 'Performance Tasks', 'QA' => 'Examinations'];
    $missing = [];
    foreach ($items as $item) {
        if (!in_array((int) $item['item_id'], $scored_ids, true)) {
            $missing[] = $item['item_name'] . ' (' . ($cat_labels[$item['category']] ?? $item['category']) . ')';
        }
    }
    return $missing;
}