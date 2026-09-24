<?php
// includes/ai_quiz_grade_sync.php
// Wires an auto-graded Google Forms quiz score into the SAME grading
// computation the manual "Encode Grades" page already uses
// (modules/grading/index.php's save_grades handler) — the teacher never has
// to re-type a score the AI quiz already has.
//
// Ordinary subjects are recomputed with the standard branch of Grade
// Management; a multi-component learning area (MAPEH: Music and Arts /
// PE and Health) is recomputed with the same computeMultiComponentTermGrade()
// helper and the same grade_components / grades writes Grade Management's
// Finalize uses -- the score only counts inside the component its item is
// tagged with, so nothing is pooled across components.
//
// This is a REUSE, not a reimplementation: every computation call below
// (getSubjectWeightsForStudent, getAssessmentItems, getAssessmentScoresMap,
// computeCategoryPercent, computeExamPercent, computeTermGrade,
// computeMultiComponentTermGrade) is the exact same helper, called the exact
// same way, as modules/grading/index.php. Risk status is refreshed by calling
// syncStudentRiskAnalysis() — which recomputes purely from the `grades`
// table — so a student is never manually forced to "Passed"; they only stop
// appearing at-risk once their real computed grade clears the threshold.

require_once __DIR__ . '/grading_helpers.php';
require_once __DIR__ . '/risk_helpers.php';

/**
 * Record one Google Forms quiz score against its linked assessment item,
 * and — for an ordinary (non-multi-component, non-domain) subject — recompute
 * that student's Term Grade and at-risk status the same way a manual save in
 * Grade Management would.
 *
 * @param array $resource A row from ai_generated_resources. Must include:
 *                         resource_id, student_id, teacher_id, subject_code,
 *                         school_year_id, link_category, link_term, link_item_id.
 * @param float $score        The student's latest Google Forms score (raw points).
 * @param float|null $formMaxScore The Google Form's OWN total points. The
 *                          linked assessment item is a make-up/recovery for
 *                          an EXISTING item (see modules/ai/create_quiz_form.php)
 *                          whose own highest_score does not necessarily
 *                          match how many points the AI quiz happens to be
 *                          worth (e.g. an 8-question recovery quiz standing
 *                          in for a 10-point "Quiz 3"). When given, the raw
 *                          score is scaled proportionally onto the item's
 *                          own scale — so a PERFECT Form score lands as a
 *                          perfect score on that one item, never more —
 *                          before computeCategoryPercent() blends it with
 *                          every other preserved item. Pass null only when
 *                          the score is already on the item's own scale.
 * @return array ['success' => bool, 'status' => 'synced'|'skipped_manual_review'|'error', 'error' => string|null]
 */
/** Percent of the points an at-risk make-up earns (system setting, default 50). */
function aiMakeupCreditPercent()
{
    $row = getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'makeup_credit_percent'");
    $pct = $row ? (float) $row['setting_value'] : 50.0;
    return ($pct > 0 && $pct <= 100) ? $pct : 50.0;
}

/** Is this student resource an at-risk make-up of a missing item (AI Analytics)? */
function aiIsAtRiskMakeup(array $resource)
{
    $prompt = $resource['source_prompt'] ?? null;
    if ($prompt === null && !empty($resource['resource_id'])) {
        $r = getRecord("SELECT source_prompt FROM ai_generated_resources WHERE resource_id = ?", [(int) $resource['resource_id']]);
        $prompt = $r['source_prompt'] ?? '';
    }
    $prompt = (string) $prompt;
    return strpos($prompt, 'Catch-up session for missing') === 0 || strpos($prompt, 'Make-up for missing') === 0;
}

function aiQuizSyncGradeForResponse($resource, $score, $formMaxScore = null)
{
    $item_id      = (int) ($resource['link_item_id'] ?? 0);
    $student_id   = (int) ($resource['student_id'] ?? 0);
    $teacher_id   = (int) ($resource['teacher_id'] ?? 0);
    $subject_code = (string) ($resource['subject_code'] ?? '');
    $sy_id        = (int) ($resource['school_year_id'] ?? 0);
    $term         = (string) ($resource['link_term'] ?? '');
    $category     = (string) ($resource['link_category'] ?? '');

    if (!$item_id || !$student_id || !$teacher_id || $subject_code === '' || !$sy_id || $term === '' || $category === '') {
        // Quiz created before this feature existed, or never linked — the
        // score simply isn't auto-synced; nothing to record or compute.
        return ['success' => false, 'status' => 'error', 'error' => 'This quiz is not linked to an assessment item.'];
    }

    $item = getRecord("SELECT * FROM assessment_items WHERE item_id = ?", [$item_id]);
    if (!$item) {
        return ['success' => false, 'status' => 'error', 'error' => 'The linked assessment item no longer exists.'];
    }
    $section_id = (int) $item['section_id'];

    // ---- Guard: a Completed term is locked, read-only history (Term
    // Transition). A late AI quiz score must never rewrite its scores or
    // grades; it is left for the teacher to review, exactly like a Performance
    // Task link. Nothing is recorded or recomputed. ----
    if (gh_isSectionTermCompleted($section_id, $sy_id, $term)) {
        executeQuery("UPDATE ai_generated_resources SET grade_sync_status = 'skipped_manual_review' WHERE resource_id = ?", [$resource['resource_id']]);
        return ['success' => true, 'status' => 'skipped_manual_review', 'error' => null];
    }

    // ---- Guard: Performance Tasks are NEVER written or computed by the AI.
    // They are encoded manually by the teacher (PETA, projects, group
    // performance/output, demonstrations, ...). New links to a PT item are refused at the
    // endpoints, so this only catches a link made before that rule existed:
    // nothing is recorded, and the teacher encodes the PT score by hand. ----
    if (!gh_isAiAssistedCategory($item['category'] ?? '')) {
        executeQuery("UPDATE ai_generated_resources SET grade_sync_status = 'skipped_manual_review' WHERE resource_id = ?", [$resource['resource_id']]);
        return ['success' => true, 'status' => 'skipped_manual_review', 'error' => null];
    }

    // ---- Step 1: always record the raw score — the exact same
    // check-then-write pattern modules/grading/index.php uses for
    // assessment_scores. This alone makes the score visible in Manage
    // Items even for subjects where the Term Grade below is skipped. ----
    $item_hps = (float) $item['highest_score'];
    $raw_score = (float) $score;

    // Proportional scale onto the LINKED item's own HPS when the Google
    // Form's own point total differs from it (the common case — the AI
    // quiz's question count rarely matches the original item's HPS
    // exactly). Never let a "perfect quiz" become more than a perfect
    // score on the one item it recovers.
    if ($formMaxScore !== null && (float) $formMaxScore > 0 && $item_hps > 0) {
        $raw = round(($raw_score / (float) $formMaxScore) * $item_hps, 2);
    } else {
        $raw = round($raw_score, 2);
    }
    if ($raw < 0) { $raw = 0; }
    if ($item_hps > 0 && $raw > $item_hps) { $raw = $item_hps; } // never exceed the item's own HPS, whatever the inputs were

    // At-risk MAKE-UP of a missing assessment (AI Analytics catch-up quiz or
    // make-up activity): it earns only part of the points -- by default half
    // (system setting makeup_credit_percent) -- so it helps the grade without
    // being worth the same as work done on time.
    if (aiIsAtRiskMakeup($resource)) {
        $raw = round($raw * aiMakeupCreditPercent() / 100, 2);
    }

    $existing_score = getRecord("SELECT score_id FROM assessment_scores WHERE item_id = ? AND student_id = ?", [$item_id, $student_id]);
    if ($existing_score) {
        executeQuery("UPDATE assessment_scores SET raw_score = ?, updated_at = NOW() WHERE score_id = ?", [$raw, $existing_score['score_id']]);
    } else {
        executeQuery("INSERT INTO assessment_scores (item_id, student_id, raw_score) VALUES (?, ?, ?)", [$item_id, $student_id, $raw]);
    }

    $active_sy = getRecord("SELECT * FROM school_years WHERE sy_id = ?", [$sy_id]);
    if (!$active_sy) {
        executeQuery("UPDATE ai_generated_resources SET grade_sync_status = 'skipped_manual_review' WHERE resource_id = ?", [$resource['resource_id']]);
        return ['success' => true, 'status' => 'skipped_manual_review', 'error' => null];
    }

    $weights = getSubjectWeightsForStudent($subject_code, $student_id, $active_sy);
    if (!empty($weights['blocked'])) {
        executeQuery("UPDATE ai_generated_resources SET grade_sync_status = 'skipped_manual_review' WHERE resource_id = ?", [$resource['resource_id']]);
        return ['success' => true, 'status' => 'skipped_manual_review', 'error' => null];
    }

    $components_list = gh_subjectComponents($subject_code, $weights['category'] ?? null);
    $is_multi     = count($components_list) > 1;
    // Grade Management no longer uses the GMRC / Values Education domain
    // split (modules/grading/index.php sets uses_domains = false before it
    // computes anything), so this follows it: every non-multi-component
    // subject is computed the ordinary way.
    $weights['uses_domains'] = false;

    // ---- Step 2: read every item and score for this class + term, the
    // same way Grade Management does. ----
    $assessment_items = getAssessmentItems($teacher_id, $subject_code, $section_id, $sy_id, $term);
    $all_item_ids = array_merge(
        array_column($assessment_items['WW'], 'item_id'),
        array_column($assessment_items['PT'], 'item_id'),
        array_column($assessment_items['QA'], 'item_id')
    );
    $assessment_scores_map = getAssessmentScoresMap($all_item_ids);

    // ---- Step 2b: MULTI-COMPONENT learning area (MAPEH: Music and Arts /
    // PE and Health). Each component is computed and transmuted on its own
    // and the Term Grade is the AVERAGE of the component grades -- the exact
    // helper and the exact writes Grade Management's Finalize uses
    // (modules/grading/index.php, "MULTI-COMPONENT LEARNING AREA"). The score
    // only ever counts inside the component its item is tagged with. ----
    if ($is_multi) {
        $mc = computeMultiComponentTermGrade($weights, $components_list, $assessment_items, $assessment_scores_map, $student_id, $active_sy);
        if ($mc['transmuted_grade'] === null) {
            // Nothing usable for any component (e.g. an item with no component tag).
            executeQuery("UPDATE ai_generated_resources SET grade_sync_status = 'skipped_manual_review' WHERE resource_id = ?", [$resource['resource_id']]);
            return ['success' => true, 'status' => 'skipped_manual_review', 'error' => null];
        }
        foreach ($mc['components'] as $comp_name => $cg) {
            if ($cg['transmuted_grade'] === null) { continue; }
            executeQuery(
                "INSERT INTO grade_components
                    (student_id, subject_code, section_id, school_year_id, term, component,
                     written_work, performance_task, examinations,
                     initial_grade, transmuted_grade, remarks, encoded_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    written_work=VALUES(written_work), performance_task=VALUES(performance_task),
                    examinations=VALUES(examinations), initial_grade=VALUES(initial_grade),
                    transmuted_grade=VALUES(transmuted_grade), remarks=VALUES(remarks),
                    encoded_by=VALUES(encoded_by)",
                [$student_id, $subject_code, $section_id, $sy_id, $term, $comp_name,
                 $cg['ww'], $cg['pt'], $cg['ex'],
                 $cg['initial_grade'], $cg['transmuted_grade'], $cg['remarks'], $teacher_id]
            );
        }
        // Same as Finalize: the learning-area row keeps NULL category
        // percentages, and a component still missing a score never produces
        // a stored Initial / Term Grade.
        $mc_initial = $mc['complete'] ? $mc['initial_grade'] : 0;
        $mc_final   = $mc['complete'] ? $mc['transmuted_grade'] : 0;
        $mc_remarks = $mc['complete'] ? $mc['remarks'] : 'Incomplete';

        $existing_grade = getRecord(
            "SELECT grade_id FROM grades WHERE student_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ?",
            [$student_id, $subject_code, $section_id, $sy_id, $term]
        );
        if ($existing_grade) {
            executeQuery(
                "UPDATE grades SET written_work = ?, performance_task = ?, examinations = ?,
                    initial_grade = ?, transmuted_grade = ?, final_grade = ?, remarks = ?, updated_at = NOW()
                 WHERE grade_id = ?",
                [null, null, null, $mc_initial, $mc_final, $mc_final, $mc_remarks, $existing_grade['grade_id']]
            );
        } else {
            executeQuery(
                "INSERT INTO grades (student_id, subject_code, teacher_id, section_id, school_year_id, term,
                    written_work, performance_task, examinations, initial_grade, transmuted_grade, final_grade, remarks, encoded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$student_id, $subject_code, $teacher_id, $section_id, $sy_id, $term,
                 null, null, null, $mc_initial, $mc_final, $mc_final, $mc_remarks, $teacher_id]
            );
        }
        syncStudentRiskAnalysis($student_id, $sy_id);
        executeQuery("UPDATE ai_generated_resources SET grade_sync_status = 'synced' WHERE resource_id = ?", [$resource['resource_id']]);
        return ['success' => true, 'status' => 'synced', 'error' => null];
    }

    // ---- Step 3: recompute the SAME WAY modules/grading/index.php's
    // standard save branch does, reusing its exact helper calls in its exact
    // sequence. ----

    $components = ['ww' => null, 'pt' => null, 'ex' => null];

    $components['ww'] = !empty($assessment_items['WW'])
        ? computeCategoryPercent($assessment_items['WW'], $assessment_scores_map, $student_id)
        : null;
    $components['pt'] = !empty($assessment_items['PT'])
        ? computeCategoryPercent($assessment_items['PT'], $assessment_scores_map, $student_id)
        : null;
    if ($weights['exam_mode'] === 'none') {
        $components['ex'] = null;
    } elseif (!empty($assessment_items['QA'])) {
        $exam = computeExamPercent($assessment_items['QA'], $assessment_scores_map, $student_id, $weights['exam_mode']);
        $components['ex'] = $exam['percent'];
    }

    $computed = computeTermGrade($weights, $components, $active_sy);

    $initial_grade    = $computed['initial_grade'] === null ? 0 : $computed['initial_grade'];
    $transmuted_grade = $computed['transmuted_grade'] === null ? 0 : $computed['transmuted_grade'];
    $final_grade      = $transmuted_grade;
    $remarks          = $computed['remarks'];

    $written_work     = $components['ww'] === null ? 0 : $components['ww'];
    $performance_task = $components['pt'] === null ? 0 : $components['pt'];
    $examinations     = $components['ex'] === null ? 0 : $components['ex'];

    $existing_grade = getRecord(
        "SELECT grade_id FROM grades WHERE student_id = ? AND subject_code = ? AND section_id = ? AND school_year_id = ? AND term = ?",
        [$student_id, $subject_code, $section_id, $sy_id, $term]
    );

    if ($existing_grade) {
        executeQuery(
            "UPDATE grades SET written_work = ?, performance_task = ?, examinations = ?,
                initial_grade = ?, transmuted_grade = ?, final_grade = ?, remarks = ?, updated_at = NOW()
             WHERE grade_id = ?",
            [$written_work, $performance_task, $examinations, $initial_grade, $transmuted_grade, $final_grade, $remarks, $existing_grade['grade_id']]
        );
    } else {
        executeQuery(
            "INSERT INTO grades (student_id, subject_code, teacher_id, section_id, school_year_id, term,
                written_work, performance_task, examinations, initial_grade, transmuted_grade, final_grade, remarks, encoded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $student_id, $subject_code, $teacher_id, $section_id, $sy_id, $term,
                $written_work, $performance_task, $examinations, $initial_grade, $transmuted_grade, $final_grade, $remarks, $teacher_id,
            ]
        );
    }

    // Recomputes purely from the grades table just written above — this is
    // what auto-clears (or keeps) the student's At-Risk listing. Nothing
    // here ever sets a status directly; syncStudentRiskAnalysis() is the
    // single source of truth for risk, exactly as it is everywhere else in
    // the app (see modules/ai/index.php, modules/tutorial/index.php).
    syncStudentRiskAnalysis($student_id, $sy_id);

    executeQuery("UPDATE ai_generated_resources SET grade_sync_status = 'synced' WHERE resource_id = ?", [$resource['resource_id']]);

    return ['success' => true, 'status' => 'synced', 'error' => null];
}
