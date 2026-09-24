<?php
// includes/risk_helpers.php
//
// AT-RISK / RISK LEVEL ANALYTICS — shared, data-driven logic.
//
// This is intentionally a SEPARATE file from includes/grading_helpers.php.
// It only READS already-computed grades (grades.final_grade) — it never
// touches WW/PT/QA computation, transmutation, or final rating logic.
//
// Every page that shows a student's risk level (modules/ai/index.php,
// modules/analytics/index.php, modules/students/view.php,
// modules/students/portal/dashboard.php, modules/grading/view_student.php)
// calls calculateStudentRisk() so the classification and its reason are
// always computed fresh from the current grades — never hand-set by a
// Teacher/Admin, and never left stale.
//
// RISK RULES (unchanged from the existing system, now driven by the
// configurable "grading_pass_mark" system setting instead of a hard-coded
// 75 so it stays correct if the passing grade is ever changed):
//
//   pass_mark            = system_settings.grading_pass_mark (default 75)
//   high_min_threshold   = pass_mark - 5   (was hard-coded 70 when pass_mark=75)
//   medium_avg_threshold = pass_mark + 5   (was hard-coded 80 when pass_mark=75)
//
//   High Risk   = average < pass_mark  OR  lowest subject grade < high_min_threshold
//   Medium Risk = average < medium_avg_threshold  OR  lowest subject grade < pass_mark
//   Low Risk    = everything else (passing, and no subject below pass mark)
//
// These are the exact thresholds already used by modules/ai/index.php
// before this fix (avg<75/min<70 => High, avg<80/min<75 => Medium) —
// only the numbers are now derived from the pass mark setting instead of
// being hard-coded, which was the one real inconsistency found (Reports'
// live_data.php already reads this same setting; the AI module did not).

// The central grading engine supplies gh_validTermSql(), which is what
// keeps invalid and incomplete records out of every risk calculation.
require_once __DIR__ . '/grading_helpers.php';

/**
 * Get the current official passing grade from system_settings.
 * Falls back to 75 (DepEd default) only if the setting row is missing.
 * @return float
 */
if (!function_exists('getGradingPassMark')) {
function getGradingPassMark() {
    $row = getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'grading_pass_mark'");
    return floatval($row['setting_value'] ?? 75);
}
}

/**
 * Format a threshold number without a trailing ".00" when it's a whole
 * number (so "75" instead of "75.00"), but keep decimals when needed.
 * @param float $n
 * @return string
 */
function formatRiskThreshold($n) {
    return (floatval($n) == intval($n)) ? number_format($n, 0) : number_format($n, 2);
}

/**
 * Fixed, general-purpose explanation of what a risk level MEANS and how
 * it's assigned — the definition of the classification rule itself,
 * not any one student's specific numbers.
 *
 * This is intentionally different from calculateStudentRisk()'s 'reason'
 * field: that one is per-student ("this student's average is 68.50...").
 * This one is the same text for every student at a given risk level, so
 * showing it as a hover tooltip on the badge doesn't look inconsistent
 * from student to student. Per-student specifics still show separately
 * (e.g. the "Reason:" text under the badge).
 *
 * Numbers are still derived live from system_settings.grading_pass_mark,
 * so this stays correct if the passing grade is ever changed — it just
 * reads back as fixed text at any single point in time.
 *
 * @param string $risk_level 'High'|'Medium'|'Low'
 * @param float|null $pass_mark  Defaults to getGradingPassMark().
 * @return string
 */
function getRiskLevelDescription($risk_level, $pass_mark = null) {
    if ($pass_mark === null) {
        $pass_mark = getGradingPassMark();
    }
    $high_min_threshold = $pass_mark - 5;
    $medium_avg_threshold = $pass_mark + 5;

    $pass_s = formatRiskThreshold($pass_mark);
    $high_min_s = formatRiskThreshold($high_min_threshold);
    $medium_avg_s = formatRiskThreshold($medium_avg_threshold);
    $medium_range_high_s = formatRiskThreshold($pass_mark - 1);

    switch ($risk_level) {
        case 'High':
            return "High Risk — The student requires immediate academic attention. "
                . "Assigned when the overall average is below {$pass_s} or when any subject grade falls below {$high_min_s}.";
        case 'Medium':
            return "Medium Risk — The student is passing but may require additional academic support. "
                . "Assigned when the overall average is below {$medium_avg_s} or when a subject grade is between {$high_min_s} and {$medium_range_high_s}.";
        case 'Low':
            return "Low Risk — The student is performing satisfactorily. "
                . "Assigned when the overall average is {$medium_avg_s} or higher and all subject grades are at least {$pass_s}.";
        default:
            return "Insufficient Data — no grades have been encoded yet for this school year, so a risk level cannot be determined.";
    }
}

/**
 * Calculate a student's academic risk level, live, from their actual
 * grade data — plus a plain-language, data-based reason.
 *
 * @param int $student_id
 * @param int|null $school_year_id  Defaults to the active school year.
 * @return array {
 *   status: 'ok' | 'insufficient_data',
 *   reason: string,                 // human-readable, always populated
 *   risk_level: 'Low'|'Medium'|'High'|null,
 *   average: float|null,            // arithmetic mean of final_grade
 *   min_grade: float|null,
 *   min_subject: string|null,
 *   subject_count: int,
 *   pass_mark: float,
 *   high_min_threshold: float,
 *   medium_avg_threshold: float,
 *   failed_subjects: array          // [['subject_name'=>..,'grade'=>..], ...] below pass mark, lowest first
 * }
 */
function calculateStudentRisk($student_id, $school_year_id = null) {
    if ($school_year_id === null) {
        $active_sy = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
        $school_year_id = $active_sy['sy_id'] ?? 0;
    }

    $pass_mark = getGradingPassMark();
    $high_min_threshold = $pass_mark - 5;
    $medium_avg_threshold = $pass_mark + 5;

    $base = [
        'pass_mark' => $pass_mark,
        'high_min_threshold' => $high_min_threshold,
        'medium_avg_threshold' => $medium_avg_threshold,
        'subject_count' => 0,
        'failed_subjects' => [],
        'average' => null,
        'min_grade' => null,
        'min_subject' => null,
        'risk_level' => null,
    ];

    // Pull every graded subject for this student/school year. Only rows that
    // already have a final_grade are counted — a subject the student hasn't
    // been graded in yet is simply absent here, never treated as a zero.
    $grades = getRecords("
        SELECT sub.subject_name, g.final_grade
        FROM grades g
        JOIN subjects sub ON g.subject_code = sub.subject_code
        WHERE g.student_id = ? AND g.school_year_id = ? AND g.final_grade IS NOT NULL
          AND " . gh_validTermSql('g') . "
        ORDER BY g.final_grade ASC
    ", [$student_id, $school_year_id]);

    if (!$grades || count($grades) === 0) {
        // Distinguish "nothing encoded" from "encoded but still incomplete".
        // Saying "no grades have been encoded" when the teacher has in fact
        // encoded them is misleading and sends people hunting for a bug.
        $encoded = getRecord(
            "SELECT COUNT(*) AS c FROM grades g
               WHERE g.student_id = ? AND g.school_year_id = ?
                 AND " . gh_encodedTermSql('g'),
            [$student_id, $school_year_id]
        );

        $reason = ((int)($encoded['c'] ?? 0) > 0)
            ? 'Insufficient Data — grades have been encoded, but none is complete yet. A record stays '
              . 'Incomplete until every component required by the subject (Written Works, Performance '
              . 'Tasks and, where applicable, Examinations) has a score. Incomplete records are never '
              . 'used to compute a risk level.'
            : 'Insufficient Data — no grades have been encoded yet for this school year, so a risk '
              . 'level cannot be determined.';

        return array_merge($base, [
            'status' => 'insufficient_data',
            'reason' => $reason,
        ]);
    }

    $values = array_map(function ($g) { return (float)$g['final_grade']; }, $grades);
    $average = round(array_sum($values) / count($values), 2);
    $min_grade = round(min($values), 2);
    $min_subject = $grades[0]['subject_name']; // list is ordered by final_grade ASC

    $failed_subjects = [];
    foreach ($grades as $g) {
        $g_val = round((float)$g['final_grade'], 2);
        if ($g_val < $pass_mark) {
            $failed_subjects[] = ['subject_name' => $g['subject_name'], 'grade' => $g_val];
        }
    }

    $avg_below_pass = $average < $pass_mark;
    $min_below_high = $min_grade < $high_min_threshold;
    $avg_below_medium = $average < $medium_avg_threshold;
    $min_below_pass = $min_grade < $pass_mark;

    if ($avg_below_pass || $min_below_high) {
        $risk_level = 'High';
    } elseif ($avg_below_medium || $min_below_pass) {
        $risk_level = 'Medium';
    } else {
        $risk_level = 'Low';
    }

    // Build the plain-language reason from the ACTUAL numbers that
    // triggered the classification (never a hard-coded sentence).
    $avg_s = number_format($average, 2);
    $min_s = number_format($min_grade, 2);
    $pass_s = number_format($pass_mark, 2);
    $high_min_s = number_format($high_min_threshold, 2);

    if ($risk_level === 'High') {
        if ($avg_below_pass && $min_below_high) {
            $reason = "Overall average is {$avg_s}, which is below the passing grade of {$pass_s}. "
                . "In addition, {$min_subject} has a grade of {$min_s}, which is below the high-risk threshold of {$high_min_s}.";
        } elseif ($avg_below_pass) {
            $reason = "Student's current average is {$avg_s}, which is below the passing grade of {$pass_s}.";
        } else {
            $reason = "Overall average is {$avg_s}, but the student has a grade of {$min_s} in {$min_subject}, "
                . "which is below the high-risk threshold of {$high_min_s} and triggered the High Risk classification.";
        }
    } elseif ($risk_level === 'Medium') {
        if ($avg_below_medium && $min_below_pass) {
            $reason = "Student is currently passing with an average of {$avg_s}, which is close to the passing grade of {$pass_s}. "
                . "{$min_subject} is also below the passing grade at {$min_s}.";
        } elseif ($avg_below_medium) {
            $reason = "Student is currently passing with an average of {$avg_s} but is close to the minimum passing grade of {$pass_s}.";
        } else {
            $reason = "Overall average is {$avg_s}, which is passing, but {$min_subject} has a grade of {$min_s}, "
                . "which is below the passing grade of {$pass_s}.";
        }
    } else {
        $reason = "Student's current average is {$avg_s} and all " . count($grades) . " subject(s) are at or above the passing grade of {$pass_s}.";
    }

    return array_merge($base, [
        'status' => 'ok',
        'reason' => $reason,
        'risk_level' => $risk_level,
        'average' => $average,
        'min_grade' => $min_grade,
        'min_subject' => $min_subject,
        'subject_count' => count($grades),
        'failed_subjects' => $failed_subjects,
    ]);
}

/**
 * Short, actionable recommendation bullets (kept separate from the
 * data-based "reason" text above, same spirit as the existing
 * generateRecommendations()/inline logic this replaces).
 * @param array $risk  Result of calculateStudentRisk() with status 'ok'
 * @return array
 */
function generateRiskRecommendations($risk) {
    $recs = [];
    if ($risk['risk_level'] === 'High') {
        $recs[] = 'Student needs immediate academic intervention.';
        $recs[] = 'Schedule a parent-teacher conference.';
        $recs[] = 'Additional remedial classes recommended.';
    } elseif ($risk['risk_level'] === 'Medium') {
        $recs[] = 'Student needs additional support.';
        if (!empty($risk['failed_subjects'])) {
            $recs[] = 'Focus on improving weakest subject(s).';
        }
    } elseif ($risk['average'] >= 90) {
        $recs[] = 'Excellent performance! Consider advanced work.';
    } else {
        $recs[] = 'Student is performing at the expected level.';
    }
    return $recs;
}

/**
 * Recompute a student's risk live AND persist it into ai_analysis, so:
 *  - any page a Teacher/Admin/Student visits always shows the current
 *    classification (risk level is never manually set, and never stale
 *    just because nobody opened the AI Analytics dashboard first), and
 *  - modules/tutorial/index.php, which reads ai_analysis directly for
 *    its own tutoring-plan list, keeps seeing up-to-date data too
 *    (that module itself is not touched by this fix).
 *
 * Students with insufficient data are left alone (no row written/updated),
 * matching the existing behavior of never classifying a no-grades student.
 *
 * @param int $student_id
 * @param int|null $school_year_id
 * @return array The calculateStudentRisk() result (status 'ok' or 'insufficient_data')
 */
function syncStudentRiskAnalysis($student_id, $school_year_id = null) {
    if ($school_year_id === null) {
        $active_sy = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
        $school_year_id = $active_sy['sy_id'] ?? 0;
    }

    $risk = calculateStudentRisk($student_id, $school_year_id);

    if ($risk['status'] !== 'ok') {
        return $risk;
    }

    $analysis_data = json_encode([
        'average' => $risk['average'],
        'min_grade' => $risk['min_grade'],
        'min_subject' => $risk['min_subject'],
        'subject_count' => $risk['subject_count'],
        'pass_mark' => $risk['pass_mark'],
        'reason' => $risk['reason'],
        'failed_subjects' => $risk['failed_subjects'],
    ]);
    $recommendations = implode(' ', generateRiskRecommendations($risk));

    $existing = getRecord(
        "SELECT analysis_id FROM ai_analysis WHERE student_id = ? AND school_year_id = ?",
        [$student_id, $school_year_id]
    );

    if ($existing) {
        executeQuery(
            "UPDATE ai_analysis SET performance_score = ?, risk_level = ?, analysis_data = ?, recommendations = ?, generated_at = NOW() WHERE analysis_id = ?",
            [$risk['average'], $risk['risk_level'], $analysis_data, $recommendations, $existing['analysis_id']]
        );
    } else {
        executeQuery(
            "INSERT INTO ai_analysis (student_id, school_year_id, term, performance_score, risk_level, analysis_data, recommendations) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$student_id, $school_year_id, 'Overall', $risk['average'], $risk['risk_level'], $analysis_data, $recommendations]
        );
    }

    return $risk;
}

/**
 * Per-SUBJECT-TERM at-risk detail for one student -- the granular list the
 * AI Analytics dashboard needs (subject, grade, missing assessments, reason)
 * per DepEd SY 2026-2027 policy: a student below the passing grade OR whose
 * term is still Incomplete is at risk in THAT subject, regardless of how
 * their other subjects average out. This is intentionally separate from
 * calculateStudentRisk()'s single aggregate High/Medium/Low classification
 * (used for tutoring-plan thresholds and Ask AI's overall framing) -- one
 * failing/incomplete subject must never be hidden by a passing average.
 *
 * Uses gh_encodedTermSql() (excludes only Draft/Not-configured) rather than
 * gh_validTermSql(), so an Incomplete term -- the record most in need of
 * intervention -- is never invisible here the way it would be everywhere
 * else that deliberately excludes incomplete records from computations.
 *
 * @return array<int,array{
 *   subject_code:string, subject_name:string, term:string,
 *   final_grade:float, remarks:string|null, teacher_id:int|null,
 *   section_id:int, missing_assessments:string[], reason:string
 * }>
 */
function getAtRiskSubjectRecords($student_id, $school_year_id) {
    $pass_mark = getGradingPassMark();

    $rows = getRecords("
        SELECT g.subject_code, sub.subject_name, g.term, g.final_grade, g.remarks, g.section_id,
               ts.teacher_id
        FROM grades g
        JOIN subjects sub ON g.subject_code = sub.subject_code
        LEFT JOIN teacher_subjects ts
            ON ts.subject_code = g.subject_code
           AND ts.section_id = g.section_id
           AND ts.school_year_id = g.school_year_id
        WHERE g.student_id = ? AND g.school_year_id = ?
          AND " . gh_encodedTermSql('g') . "
        ORDER BY g.term, sub.subject_name
    ", [$student_id, $school_year_id]);

    // Academic At-Risk in a subject-term when ANY of these holds:
    //   - the Term Grade is below the passing grade (74 or below);
    //   - 2 or more required assessments are missing (ONE missing item alone
    //     is not a risk -- the teacher simply has not recorded it yet, or the
    //     student can still make it up);
    //   - repeatedly low scores: 2 or more scored items of the same component
    //     below the passing percentage.
    $at_risk = [];
    foreach ($rows as $row) {
        $grade = (float) $row['final_grade'];
        $is_incomplete = ($row['remarks'] === 'Incomplete');
        $teacher_id = $row['teacher_id'] !== null ? (int) $row['teacher_id'] : null;

        $details = ($teacher_id !== null)
            ? gh_riskMissingItemDetails($teacher_id, $row['subject_code'], (int) $row['section_id'], $school_year_id, $row['term'], $student_id)
            : [];
        $low = ($teacher_id !== null)
            ? gh_riskLowScoreCategories($teacher_id, $row['subject_code'], (int) $row['section_id'], $school_year_id, $row['term'], $student_id, $pass_mark)
            : [];

        $below = !$is_incomplete && $grade < $pass_mark;
        $many_missing = count($details) >= 2;
        if (!$below && !$many_missing && empty($low)) {
            continue;
        }

        $reasons = [];
        if ($below) {
            $reasons[] = ['type' => 'grade', 'label' => 'Current Grade: ' . rtrim(rtrim(number_format($grade, 2), '0'), '.') . '%'];
        }
        if ($many_missing) {
            $reasons[] = ['type' => 'missing', 'label' => count($details) . ' Missing Assessments'];
        }
        foreach ($low as $lc) {
            $reasons[] = ['type' => 'low', 'label' => 'Low ' . $lc['label'] . ' Scores', 'category' => $lc['category']];
        }

        // Topics the student is weakest in: what the missing and low-scored items cover.
        $weak_topics = [];
        foreach ($details as $d) { if ($d['topic'] !== '') $weak_topics[$d['topic']] = true; }
        foreach ($low as $lc) { foreach ($lc['topics'] as $t) $weak_topics[$t] = true; }

        // Legacy "name (Category)" labels -- the catch-up quiz and Ask AI chat use them.
        $missing = array_map(function ($d) { return $d['name'] . ' (' . $d['category_label'] . ')'; }, $details);

        $at_risk[] = [
            'subject_code' => $row['subject_code'],
            'subject_name' => $row['subject_name'],
            'term' => $row['term'],
            'final_grade' => $grade,
            'remarks' => $row['remarks'],
            'teacher_id' => $teacher_id,
            'section_id' => (int) $row['section_id'],
            'missing_assessments' => $missing,
            'missing_details' => $details,
            'low_categories' => $low,
            'low_items' => $low ? array_merge(...array_column($low, 'items')) : [],
            'risk_reasons' => $reasons,
            'weak_topics' => array_keys($weak_topics),
            'reason' => implode(' · ', array_column($reasons, 'label'))
                . (!empty($missing) ? ' — missing: ' . implode(', ', $missing) . '.' : '.'),
        ];
    }

    // The ongoing term, before it is finalized (skips a subject-term that already has a grade row).
    $skip = [];
    foreach ($rows as $row) { $skip[$row['subject_code'] . '|' . $row['term']] = true; }
    foreach (gh_riskInProgressRecords($student_id, $school_year_id, $skip, $pass_mark) as $live) {
        $at_risk[] = $live;
    }
    return $at_risk;
}

/**
 * The student's MISSING assessments in one subject-term, from the actual
 * records: every active item of the class with no score for the student,
 * except one that is still open online (a Learning Material task or a
 * Classwork assignment whose deadline has not passed yet).
 *
 * @return array<int,array{item_id:int,name:string,category:string,category_label:string,
 *                         kind:string,kind_label:string,mode:string,topic:string,deadline:?string}>
 */
/**
 * One class's items, every student's scores and the items' online delivery,
 * read ONCE per request and shared by every student of the class (AI Analytics
 * checks many students of the same classes).
 * @return array{items:array, scores:array<int,array<int,float>>, online:array<int,array>}
 */
function gh_riskClassData($teacher_id, $subject_code, $section_id, $sy_id, $term) {
    static $cache = [];
    $key = (int) $teacher_id . '|' . $subject_code . '|' . (int) $section_id . '|' . (int) $sy_id . '|' . $term;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $items = getRecords(
        "SELECT * FROM assessment_items
         WHERE teacher_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ? AND is_active = 1
         ORDER BY FIELD(category, 'WW', 'PT', 'QA'), item_order, item_id",
        [(int) $teacher_id, $subject_code, (int) $section_id, (int) $sy_id, $term]
    );
    $data = ['items' => $items, 'scores' => [], 'online' => []];
    if (!empty($items)) {
        $ids = array_map('intval', array_column($items, 'item_id'));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        foreach (getRecords("SELECT item_id, student_id, raw_score FROM assessment_scores WHERE raw_score IS NOT NULL AND item_id IN ($ph)", $ids) as $r) {
            $data['scores'][(int) $r['student_id']][(int) $r['item_id']] = (float) $r['raw_score'];
        }
        // Online delivery of an item: its Learning Material task and/or Classwork.
        foreach (gh_riskSafeRows("SELECT item_id, topic, end_at FROM learning_material_tasks WHERE item_id IN ($ph)", $ids) as $t) {
            $data['online'][(int) $t['item_id']] = ['topic' => (string) $t['topic'], 'end' => $t['end_at']];
        }
        foreach (gh_riskSafeRows("SELECT assessment_item_id AS item_id, due_date FROM classwork_assignments WHERE assessment_item_id IN ($ph)", $ids) as $c) {
            $iid = (int) $c['item_id'];
            $end = $c['due_date'];
            if (!isset($data['online'][$iid])) {
                $data['online'][$iid] = ['topic' => '', 'end' => $end];
            } elseif ($end !== null && ($data['online'][$iid]['end'] === null || $end > $data['online'][$iid]['end'])) {
                $data['online'][$iid]['end'] = $end;
            }
        }
    }
    return $cache[$key] = $data;
}

function gh_riskMissingItemDetails($teacher_id, $subject_code, $section_id, $sy_id, $term, $student_id, $in_progress = false) {
    $class = gh_riskClassData($teacher_id, $subject_code, $section_id, $sy_id, $term);
    $items = $class['items'];
    if (empty($items)) {
        return [];
    }
    $scored = $class['scores'][(int) $student_id] ?? [];
    $online = $class['online'];
    $now = date('Y-m-d H:i:s');

    if (!function_exists('aim_assessmentKind')) {
        require_once __DIR__ . '/assessment_material_helpers.php';
    }
    $cat_labels = ['WW' => 'Written/Oral Works', 'PT' => 'Performance Tasks', 'QA' => 'Examinations'];
    $out = [];
    foreach ($items as $item) {
        $iid = (int) $item['item_id'];
        if (isset($scored[$iid])) continue;
        $on = $online[$iid] ?? null;
        if ($on && !empty($on['end']) && $on['end'] > $now) continue; // still open -- not missing yet
        if ($in_progress && !$on && !gh_riskItemHasAnyScore($class, $iid)) continue; // ongoing term: not recorded for anyone yet
        $kind = aim_assessmentKind($item);
        $meta = aim_kindMeta($kind);
        $topic = trim((string) ($item['topic'] ?? ''));
        if ($topic === '' && $on) $topic = trim($on['topic']);
        $out[] = [
            'item_id' => $iid,
            'name' => html_entity_decode((string) $item['item_name'], ENT_QUOTES, 'UTF-8'),
            'category' => $item['category'],
            'category_label' => $cat_labels[$item['category']] ?? $item['category'],
            'kind' => $kind,
            'kind_label' => $meta['label'],
            'component' => (string) ($item['component'] ?? ''),
            'mode' => $on ? 'Online' : 'Manual',
            'topic' => $topic,
            'deadline' => $on && !empty($on['end']) ? $on['end'] : null,
        ];
    }
    return $out;
}

/**
 * Components with REPEATEDLY low scores: 2 or more scored items below the
 * passing percentage of their HPS. Returns [{category, label, count, topics}].
 */
function gh_riskLowScoreCategories($teacher_id, $subject_code, $section_id, $sy_id, $term, $student_id, $pass_mark) {
    $class = gh_riskClassData($teacher_id, $subject_code, $section_id, $sy_id, $term);
    $mine = $class['scores'][(int) $student_id] ?? [];
    $rows = [];
    foreach ($class['items'] as $it) {
        $iid = (int) $it['item_id'];
        if (isset($mine[$iid])) {
            $rows[] = $it + ['raw_score' => $mine[$iid]];
        }
    }
    $cat_labels = ['WW' => 'Written Works', 'PT' => 'Performance Task', 'QA' => 'Examination'];
    $by = [];
    foreach ($rows as $r) {
        $hps = (float) $r['highest_score'];
        if ($hps <= 0) continue;
        if ((float) $r['raw_score'] / $hps * 100 < $pass_mark) {
            $c = $r['category'];
            $by[$c]['count'] = ($by[$c]['count'] ?? 0) + 1;
            $t = trim((string) ($r['topic'] ?? ''));
            if ($t === '') {
                $t = trim((string) ($class['online'][(int) $r['item_id']]['topic'] ?? ''));
            }
            if ($t !== '') $by[$c]['topics'][$t] = true;
            $by[$c]['items'][] = [
                'item_id' => (int) $r['item_id'],
                'name' => html_entity_decode((string) $r['item_name'], ENT_QUOTES, 'UTF-8'),
                'category' => $c,
                'category_label' => ['WW' => 'Written/Oral Works', 'PT' => 'Performance Tasks', 'QA' => 'Examinations'][$c] ?? $c,
                'score' => rtrim(rtrim(number_format((float) $r['raw_score'], 2), '0'), '.') . '/' . rtrim(rtrim(number_format($hps, 2), '0'), '.'),
                'topic' => $t,
            ];
        }
    }
    $out = [];
    foreach (['WW', 'PT', 'QA'] as $c) {
        if (($by[$c]['count'] ?? 0) >= 2) {
            $out[] = ['category' => $c, 'label' => $cat_labels[$c], 'count' => $by[$c]['count'],
                      'topics' => array_keys($by[$c]['topics'] ?? []), 'items' => $by[$c]['items'] ?? []];
        }
    }
    return $out;
}

/** Has the teacher recorded a score for this item for ANY student of the class? */
function gh_riskItemHasAnyScore(array $class, $item_id) {
    foreach ($class['scores'] as $by_item) {
        if (isset($by_item[(int) $item_id])) return true;
    }
    return false;
}

/**
 * At-Risk records for the ONGOING (not yet finalized) term of the student's
 * classes, computed live from Manage Assessment Items with the existing grade
 * computation (a preview -- nothing is saved). Same rules as finalized terms:
 *   - Current Grade below passing (only once the student has 2+ scores),
 *   - 2+ missing assessments (a manual item counts only once the teacher has
 *     recorded it for someone; an online one once its deadline has passed),
 *   - 2+ low scores in the same component.
 * Every reason is labeled "(in progress)".
 */
function gh_riskInProgressRecords($student_id, $school_year_id, array $skip_keys, $pass_mark) {
    $sy = getRecord("SELECT * FROM school_years WHERE sy_id = ?", [(int) $school_year_id]);
    if (!$sy) return [];
    $enr = getRecord(
        "SELECT section_id FROM enrollments WHERE student_id = ? AND school_year_id = ? AND enrollment_status = 'Approved' AND section_id IS NOT NULL
         ORDER BY enrollment_id DESC LIMIT 1",
        [(int) $student_id, (int) $school_year_id]
    );
    if (!$enr) return [];
    $section_id = (int) $enr['section_id'];

    // The section's current term = the first term not Completed yet.
    $done = array_column(gh_riskSafeRows("SELECT term FROM section_term_status WHERE section_id = ? AND school_year_id = ?", [$section_id, (int) $school_year_id]), 'term');
    $term = null;
    foreach (gh_threeTerms() as $t) { if (!in_array($t, $done, true)) { $term = $t; break; } }
    if ($term === null) return [];

    $out = [];
    $classes = getRecords(
        "SELECT ts.teacher_id, ts.subject_code, sub.subject_name
         FROM teacher_subjects ts JOIN subjects sub ON sub.subject_code = ts.subject_code
         WHERE ts.section_id = ? AND ts.school_year_id = ?",
        [$section_id, (int) $school_year_id]
    );
    foreach ($classes as $c) {
        if (isset($skip_keys[$c['subject_code'] . '|' . $term])) continue; // already finalized -> handled above
        $class = gh_riskClassData((int) $c['teacher_id'], $c['subject_code'], $section_id, (int) $school_year_id, $term);
        if (empty($class['items'])) continue;

        $mine = $class['scores'][(int) $student_id] ?? [];
        $details = gh_riskMissingItemDetails((int) $c['teacher_id'], $c['subject_code'], $section_id, (int) $school_year_id, $term, $student_id, true);
        $low = gh_riskLowScoreCategories((int) $c['teacher_id'], $c['subject_code'], $section_id, (int) $school_year_id, $term, $student_id, $pass_mark);

        // Live Term Grade preview with the existing computation.
        $grade = null;
        if (count($mine) >= 2) {
            $by_cat = ['WW' => [], 'PT' => [], 'QA' => []];
            foreach ($class['items'] as $it) { $by_cat[$it['category']][] = $it; }
            $map = [];
            foreach ($class['scores'] as $sid_s => $by_item) { foreach ($by_item as $iid => $raw) { $map[$iid][$sid_s] = $raw; } }
            $weights = getSubjectWeightsForStudent($c['subject_code'], (int) $student_id, $sy);
            if (empty($weights['blocked'])) {
                $weights['uses_domains'] = false;
                $components = gh_subjectComponents($c['subject_code'], $weights['category'] ?? null);
                if (count($components) > 1) {
                    $mc = computeMultiComponentTermGrade($weights, $components, $by_cat, $map, (int) $student_id, $sy);
                    $grade = $mc['transmuted_grade'];
                } else {
                    $tg = computeTermGrade($weights, [
                        'ww' => computeCategoryPercent($by_cat['WW'], $map, (int) $student_id),
                        'pt' => computeCategoryPercent($by_cat['PT'], $map, (int) $student_id),
                        'ex' => ($weights['exam_mode'] ?? 'none') === 'none' ? null : computeExamPercent($by_cat['QA'], $map, (int) $student_id, $weights['exam_mode'])['percent'],
                    ], $sy);
                    $grade = $tg['transmuted_grade'];
                }
            }
        }

        $below = $grade !== null && $grade < $pass_mark;
        if (!$below && count($details) < 2 && empty($low)) continue;

        $reasons = [];
        if ($below) $reasons[] = ['type' => 'grade', 'label' => 'Current Grade: ' . rtrim(rtrim(number_format((float) $grade, 2), '0'), '.') . '% (in progress)'];
        if (count($details) >= 2) $reasons[] = ['type' => 'missing', 'label' => count($details) . ' Missing Assessments (in progress)'];
        foreach ($low as $lc) $reasons[] = ['type' => 'low', 'label' => 'Low ' . $lc['label'] . ' Scores (in progress)', 'category' => $lc['category']];

        $weak = [];
        foreach ($details as $d) { if ($d['topic'] !== '') $weak[$d['topic']] = true; }
        foreach ($low as $lc) { foreach ($lc['topics'] as $t) $weak[$t] = true; }
        $missing = array_map(function ($d) { return $d['name'] . ' (' . $d['category_label'] . ')'; }, $details);

        $out[] = [
            'subject_code' => $c['subject_code'],
            'subject_name' => $c['subject_name'],
            'term' => $term,
            'final_grade' => $grade !== null ? (float) $grade : 0.0,
            'remarks' => 'In progress',
            'in_progress' => true,
            'teacher_id' => (int) $c['teacher_id'],
            'section_id' => $section_id,
            'missing_assessments' => $missing,
            'missing_details' => $details,
            'low_categories' => $low,
            'low_items' => $low ? array_merge(...array_column($low, 'items')) : [],
            'risk_reasons' => $reasons,
            'weak_topics' => array_keys($weak),
            'reason' => implode(' · ', array_column($reasons, 'label')) . (!empty($missing) ? ' — missing: ' . implode(', ', $missing) . '.' : '.'),
        ];
    }
    return $out;
}

/** A read that tolerates a table not created yet on this database. */
function gh_riskSafeRows($sql, array $params) {
    try {
        return getRecords($sql, $params) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}
