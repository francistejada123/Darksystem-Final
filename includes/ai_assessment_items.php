<?php
// includes/ai_assessment_items.php
//
// The bridge between a quiz / activity / performance task / exam the teacher
// made with AI and the Manage Assessment Items list (modules/grading/items.php)
// that Grade Management computes from.
//
//   * When the teacher SENDS a resource to students (Teacher AI), it is saved
//     automatically as an assessment item under the right category:
//        Quiz / activity / written work  -> Written/Oral Works   (WW)
//        Performance task                -> Performance Tasks    (PT)
//        Summative test / term exam      -> Examinations         (QA: ST1 / ST2 / TE)
//   * Every student's copy of the resource is then linked to that item
//     (ai_generated_resources.link_category / link_term / link_item_id), which is
//     exactly what includes/ai_quiz_grade_sync.php needs to record the student's
//     real score and recompute the grade when they submit.
//
// This is a REUSE of the same rules items.php applies when a teacher adds an
// item by hand (valid term, ST1/ST2/TE tag on an examination, a component tag on
// a multi-component learning area such as MAPEH, no attendance items, HPS > 0)
// and it writes the same table with the same columns. It never edits or
// deletes an existing item.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/grading_helpers.php';

/** Category labels shown to teachers. */
function aiAssessmentCategoryLabels()
{
    return ['WW' => 'Written/Oral Works', 'PT' => 'Performance Tasks', 'QA' => 'Examinations'];
}

/**
 * The columns that link a student's resource copy to an assessment item
 * (database/migrations/2026_ai_quiz_assessment_link.sql). Adds any that are
 * missing -- additive only -- so a database that has not run that migration
 * still works. Returns false only if they could not be added.
 */
function aiAssessmentLinkColumnsReady()
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $existing = [];
    foreach (getRecords(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_generated_resources'
           AND COLUMN_NAME IN ('link_category', 'link_term', 'link_item_id', 'grade_sync_status')"
    ) as $r) {
        $existing[$r['COLUMN_NAME']] = true;
    }
    $wanted = [
        'link_category'     => "ADD COLUMN `link_category` enum('WW','PT','QA') DEFAULT NULL",
        'link_term'         => "ADD COLUMN `link_term` enum('Term 1','Term 2','Term 3') DEFAULT NULL",
        'link_item_id'      => "ADD COLUMN `link_item_id` int(11) DEFAULT NULL",
        'grade_sync_status' => "ADD COLUMN `grade_sync_status` enum('synced','skipped_manual_review') DEFAULT NULL",
    ];
    foreach ($wanted as $col => $ddl) {
        if (!isset($existing[$col])) {
            executeQuery("ALTER TABLE `ai_generated_resources` " . $ddl);
        }
    }
    $count = getRecord(
        "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_generated_resources'
           AND COLUMN_NAME IN ('link_category', 'link_term', 'link_item_id', 'grade_sync_status')"
    );
    $ready = $count && (int) $count['n'] === 4;
    return $ready;
}

/**
 * Which category a resource belongs in by default, from its type and title.
 * The teacher can always change it in the send panel -- this only picks the
 * starting choice so the normal case is automatic.
 *
 * @return array{category:string,subtype:?string}  category: 'WW' | 'PT' | 'QA' | 'none'
 */
function aiAssessmentDefaultCategory($resourceType, $title = '')
{
    $type = (string) $resourceType;
    $t = mb_strtolower((string) $title);

    // Study material and practice -- nothing to record.
    $notGraded = [
        'practice_quiz', 'practice_test', 'reviewer', 'study_guide', 'study_plan', 'mini_lesson',
        'supportive_message', 'flashcards', 'rubric', 'reading_activity', 'question_bank',
        'remedial_activity', 'recovery_assessment', 'intervention_activity', 'other',
    ];
    if (in_array($type, $notGraded, true)) {
        return ['category' => 'none', 'subtype' => null];
    }

    // Examinations: summative tests and the term exam.
    $isExam = (bool) preg_match('/\b(?:exam|examination|summative|periodical|st[\s\-]?[12])\b/u', $t)
        || (bool) preg_match('/\b(?:term|quarterly|final|third)\s+(?:test|assessment)\b/u', $t);
    // "Exam Review Quiz" is a quiz, not the exam itself -- unless it also carries
    // an unmistakable exam tag (summative / periodical / ST1 / ST2 / term exam).
    if ($isExam && preg_match('/\bquiz\b/u', $t)
        && !preg_match('/\b(?:summative|periodical|st[\s\-]?[12]|term\s+(?:exam|examination))\b/u', $t)) {
        $isExam = false;
    }
    if ($isExam) {
        $subtype = null;
        if (preg_match('/\b(?:st[\s\-]?1|summative(?:\s+(?:test|assessment))?\s*(?:no\.?\s*)?(?:1|i|one)|first\s+summative)\b/u', $t)) {
            $subtype = 'ST1';
        } elseif (preg_match('/\b(?:st[\s\-]?2|summative(?:\s+(?:test|assessment))?\s*(?:no\.?\s*)?(?:2|ii|two)|second\s+summative)\b/u', $t)) {
            $subtype = 'ST2';
        } elseif (preg_match('/\b(?:term|periodical|quarterly|final|third)\s+(?:exam|examination|test|assessment)\b/u', $t)
            || preg_match('/\b(?:exam|examination)\b/u', $t)) {
            $subtype = 'TE';
        }
        return ['category' => 'QA', 'subtype' => $subtype ?: 'ST1'];
    }

    if ($type === 'performance_task') {
        return ['category' => 'PT', 'subtype' => null];
    }

    $written = [
        'quiz', 'written_work', 'short_assessment', 'assignment', 'worksheet', 'practice_exercise',
        'multiple_choice', 'true_false', 'identification', 'essay', 'reflection_activity', 'learning_activity',
    ];
    if (in_array($type, $written, true)) {
        return ['category' => 'WW', 'subtype' => null];
    }
    return ['category' => 'none', 'subtype' => null];
}

/**
 * What Manage Assessment Items needs to know about ONE class: is it a
 * multi-component learning area (MAPEH), which examination tags it uses, and
 * whether it has an Examinations component at all. Mirrors the setup block at
 * the top of modules/grading/items.php. Null when the teacher is not assigned
 * to that class.
 *
 * @return array|null
 */
function aiAssessmentClassMeta($teacherId, $sectionId, $subjectCode, $syId)
{
    $section = getRecord("SELECT * FROM sections WHERE section_id = ?", [(int) $sectionId]);
    $subject = getRecord(
        "SELECT sub.*, ts.teacher_id FROM subjects sub
         INNER JOIN teacher_subjects ts ON ts.subject_code = sub.subject_code
         WHERE sub.subject_code = ? AND ts.section_id = ? AND ts.teacher_id = ? AND ts.school_year_id = ?",
        [(string) $subjectCode, (int) $sectionId, (int) $teacherId, (int) $syId]
    );
    $sy = getRecord("SELECT * FROM school_years WHERE sy_id = ?", [(int) $syId]);
    if (!$section || !$subject || !$sy) {
        return null;
    }
    $curriculum = getLearnerCurriculumType(null, $section['grade_level'] ?? null, (int) $sectionId);
    $weights = getSubjectWeights($subject, $section['grade_level'] ?? null, $sy, $curriculum);
    $examMode = $weights['exam_mode'] ?? 'none';
    $components = gh_subjectComponents($subject, $weights['category'] ?? null);
    $isMulti = count($components) > 1;

    $required = [];
    if ($examMode === 'standard') {
        $required = ['ST1', 'ST2', 'TE'];
    } elseif ($examMode === 'term_exam_only') {
        $required = ['TE'];
    }
    return [
        'section'          => $section,
        'subject'          => $subject,
        'exam_mode'        => $examMode,
        'has_exam'         => $examMode !== 'none',
        'is_multi'         => $isMulti,
        'components'       => $isMulti ? array_values($components) : [],
        'required_subtypes' => $required,
        'blocked'          => !empty($weights['blocked']),
    ];
}

/**
 * Find or create the assessment item a resource is recorded under, with the same
 * rules as adding it by hand in Manage Assessment Items.
 *
 * Re-sending the same resource (or another resource with the same title) to the
 * same class / term / category re-uses the item that is already there, so the
 * list never fills with duplicates and an existing item is never changed.
 *
 * @param string      $category  'WW' | 'PT' | 'QA'
 * @param string|null $subtype   'ST1' | 'ST2' | 'TE' (examinations only)
 * @param string|null $component a component of a multi-component subject (MAPEH), else null
 * @return array{success:bool,item?:array,created?:bool,error?:string}
 */
function aiAssessmentEnsureItem($teacherId, $sectionId, $subjectCode, $syId, $term, $category, $subtype, $component, $name, $hps)
{
    $fail = function ($msg) { return ['success' => false, 'error' => $msg]; };
    $labels = aiAssessmentCategoryLabels();

    if (!isset($labels[$category])) {
        return $fail('Choose Written/Oral Works, Performance Tasks or Examinations.');
    }
    if (!isValidTerm($term)) {
        return $fail('Please choose a valid term.');
    }
    $hps = round((float) $hps, 2);
    if ($hps <= 0 || $hps > 9999.99) {
        return $fail('The highest possible score must be greater than zero.');
    }
    $meta = aiAssessmentClassMeta($teacherId, $sectionId, $subjectCode, $syId);
    if (!$meta) {
        return $fail('You can only record assessments for classes that are assigned to you.');
    }

    $name = trim(sanitize((string) $name));
    if (mb_strlen($name) > 100) {
        $name = trim(mb_substr($name, 0, 100));
    }
    if ($name === '') {
        $name = $category === 'QA' ? 'Examination' : ($category === 'PT' ? 'Performance Task' : 'Quiz');
    }

    if ($category === 'QA') {
        if (!$meta['has_exam']) {
            return $fail('This subject has no Examinations component, so this cannot be recorded as an examination. Choose Written/Oral Works or Performance Tasks instead.');
        }
        $subtypes = gh_examSubtypes();
        $subtype = (string) $subtype;
        if (!array_key_exists($subtype, $subtypes)) {
            $subtype = null;
        }
        if (!empty($meta['required_subtypes'])) {
            if ($subtype === null) {
                $subtype = count($meta['required_subtypes']) === 1 ? $meta['required_subtypes'][0] : null;
            }
            if ($subtype === null || !in_array($subtype, $meta['required_subtypes'], true)) {
                return $fail('Select which examination this is (' . implode(' / ', $meta['required_subtypes']) . ').');
            }
        }
    } else {
        $subtype = null;
    }

    if ($meta['is_multi']) {
        $component = (string) $component;
        if (!in_array($component, $meta['components'], true)) {
            return $fail('This is a multi-component learning area. Select which component this belongs to (' . implode(' / ', $meta['components']) . ').');
        }
    } else {
        $component = null;
    }

    // Attendance is never a grading component (DO 015, s. 2026).
    $hint = gh_activityNameHint($category, $name);
    if ($hint && ($hint['type'] ?? '') === 'attendance') {
        return $fail('Not recorded. ' . $hint['message']);
    }

    $found = getRecord(
        "SELECT * FROM assessment_items
         WHERE teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ?
           AND category = ? AND item_name = ? AND is_active = 1
           AND (assessment_subtype <=> ?) AND (component <=> ?)
         ORDER BY item_id LIMIT 1",
        [(int) $teacherId, (string) $subjectCode, (int) $sectionId, (int) $syId, (string) $term, $category, $name, $subtype, $component]
    );
    if ($found) {
        return ['success' => true, 'item' => $found, 'created' => false];
    }

    $order = getRecord(
        "SELECT COALESCE(MAX(item_order), 0) AS m FROM assessment_items
         WHERE teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ? AND category = ?",
        [(int) $teacherId, (string) $subjectCode, (int) $sectionId, (int) $syId, (string) $term, $category]
    );
    $nextOrder = (int) ($order['m'] ?? 0) + 1;

    $itemId = insertRecord(
        "INSERT INTO assessment_items
            (teacher_id, subject_code, section_id, school_year_id, term, category, item_name, highest_score, item_order,
             assessment_subtype, assessment_domain, component)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)",
        [(int) $teacherId, (string) $subjectCode, (int) $sectionId, (int) $syId, (string) $term, $category, $name, $hps, $nextOrder, $subtype, $component]
    );
    if (!$itemId) {
        return $fail('The assessment item could not be saved. Please try again.');
    }
    $item = getRecord("SELECT * FROM assessment_items WHERE item_id = ?", [(int) $itemId]);
    logActivity(
        (int) $teacherId,
        'Added assessment item',
        'grading',
        "Added '$name' ($category) for $subjectCode / section $sectionId / $term automatically from an AI resource sent to students"
    );
    return ['success' => true, 'item' => $item, 'created' => true];
}

/**
 * Point one student's copy of a resource at an assessment item.
 * @return bool
 */
function aiAssessmentLinkResource($studentResourceId, array $item, $term)
{
    if (!aiAssessmentLinkColumnsReady()) {
        return false;
    }
    return executeQuery(
        "UPDATE ai_generated_resources
         SET link_category = ?, link_term = ?, link_item_id = ?, grade_sync_status = NULL
         WHERE resource_id = ?",
        [$item['category'], (string) $term, (int) $item['item_id'], (int) $studentResourceId]
    ) !== false;
}

/**
 * Teacher AI tables exist only after a teacher has used Teacher AI once, so
 * anything that reads them first asks.
 */
function aiAssessmentTableExists($table)
{
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        $r = getRecord(
            "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$table]
        );
        $cache[$table] = $r && (int) $r['n'] > 0;
    }
    return $cache[$table];
}

/**
 * A resource Teacher AI sent to students BEFORE this feature existed has no
 * link to an assessment item, so its scores would never reach Grade
 * Management. This links it the first time it is needed, using the class and
 * term it was sent to and the default category for its type -- but only when
 * that can be decided safely:
 *   - not when the teacher chose "Don't record" on a newer send;
 *   - not for a multi-component subject (MAPEH), where the component cannot be
 *     guessed without risking a score in the wrong component. The teacher fixes
 *     that one by sending it again and choosing the component.
 *
 * @param array $resource A full ai_generated_resources row.
 * @return array|null The refreshed resource row when it was linked, else null.
 */
function aiAssessmentBackfillLink(array $resource)
{
    if (!empty($resource['link_item_id']) || empty($resource['resource_id'])) {
        return null;
    }
    if (!aiAssessmentTableExists('teacher_ai_resource_recipients') || !aiAssessmentTableExists('teacher_ai_resource_sends')) {
        return null;
    }
    if (!aiAssessmentLinkColumnsReady()) {
        return null;
    }
    $send = getRecord(
        "SELECT s.* FROM teacher_ai_resource_recipients rc
         JOIN teacher_ai_resource_sends s ON s.send_id = rc.send_id
         WHERE rc.student_resource_id = ? LIMIT 1",
        [(int) $resource['resource_id']]
    );
    if (!$send) {
        return null;
    }
    // A send made with this feature already recorded (or deliberately skipped)
    // its item; only sends from before it have no record_category at all.
    if (array_key_exists('record_category', $send) && $send['record_category'] !== null && $send['record_category'] !== '') {
        return null;
    }
    $plan = aiAssessmentDefaultCategory($resource['resource_type'] ?? '', $resource['title'] ?? '');
    if ($plan['category'] === 'none') {
        return null;
    }
    $hps = (float) ($resource['quiz_max_score'] ?? 0);
    if ($hps <= 0) {
        return null;
    }
    $meta = aiAssessmentClassMeta((int) $send['teacher_id'], (int) $send['section_id'], (string) $send['subject_code'], (int) $send['school_year_id']);
    if (!$meta || $meta['is_multi']) {
        return null;
    }
    $ensured = aiAssessmentEnsureItem(
        (int) $send['teacher_id'], (int) $send['section_id'], (string) $send['subject_code'], (int) $send['school_year_id'],
        (string) $send['term'], $plan['category'], $plan['subtype'], null, (string) $resource['title'], $hps
    );
    if (empty($ensured['success'])) {
        return null;
    }
    if (!aiAssessmentLinkResource((int) $resource['resource_id'], $ensured['item'], (string) $send['term'])) {
        return null;
    }
    return getRecord("SELECT * FROM ai_generated_resources WHERE resource_id = ?", [(int) $resource['resource_id']]) ?: null;
}
