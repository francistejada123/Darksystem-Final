<?php
// modules/ai/index.php
// AI Analytics Dashboard

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/risk_helpers.php';
require_once __DIR__ . '/../../includes/tutorial_helpers.php'; // getWeakSubjectsForStudent() — used to scope the AI chat to a real at-risk subject this teacher actually teaches
require_once __DIR__ . '/../../includes/gemini_service.php'; // AI_GFORM_ELIGIBLE_TYPES — exposed to JS below so the Create Google Form Quiz button knows when to show
require_once __DIR__ . '/../../includes/grading_helpers.php'; // getSubjectWeightsForStudent(), gh_subjectComponents(), gh_examSubtypes() — so each subject's Written Works/Performance Tasks/Examinations shape can be reflected in the "Create Google Form Quiz" assessment picker below
require_once __DIR__ . '/../../includes/google_forms_service.php'; // googleFormsIsConfigured() — so the chat only offers the Google Form path when the Google account is actually set up
require_once __DIR__ . '/../../includes/attendance_helpers.php'; // gh_getStudentAttendanceSummaryForTeacher()/gh_getStudentAttendanceSummaryAll() — Student Attendance card + an ADDITIONAL (never grade-altering) risk indicator

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Teacher'])) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'AI Analytics';
$current_page = 'ai';
$base_url = '../../';

// Get active school year
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");

// Attendance is tracked separately from grading (its own `attendance` table)
// and NEVER feeds into WW/PT/Examinations math -- see attendance_helpers.php.
// This dashboard shows it as an ADDITIONAL, independent risk signal covering
// the whole active school year so far (this page spans all terms/subjects
// for a student, not just one), from the school year's Start Date through
// today.
$attendance_start = $active_sy['start_date'] ?? date('Y-m-d', strtotime('-180 days'));
$attendance_end = date('Y-m-d');

// Get teacher's classes if teacher
$teacher_id = $_SESSION['user_id'];

// Name shown in the Ask AI welcome message.
$welcome_user = getCurrentUser();
$teacher_display_name = trim(($welcome_user['first_name'] ?? '') . ' ' . ($welcome_user['last_name'] ?? ''));
$is_teacher = hasRole('Teacher');

// Get students based on role
$students = [];
if ($is_teacher) {
    // Get students from teacher's classes
    $students = getRecords("
        SELECT DISTINCT s.student_id, s.lrn, s.first_name, s.last_name, s.grade_level
        FROM students s
        JOIN enrollments e ON s.student_id = e.student_id
        JOIN teacher_subjects ts ON e.section_id = ts.section_id
        WHERE ts.teacher_id = ? AND e.school_year_id = ? AND e.enrollment_status = 'Approved'
        ORDER BY s.last_name, s.first_name",
        [$teacher_id, $active_sy['sy_id'] ?? 0]
    );
} else {
    // Administrator - get all students
    $students = getRecords("
        SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.grade_level
        FROM students s
        JOIN enrollments e ON s.student_id = e.student_id
        WHERE e.school_year_id = ? AND e.enrollment_status = 'Approved'
        ORDER BY s.last_name, s.first_name",
        [$active_sy['sy_id'] ?? 0]
    );
}

// Run AI analysis for each student
$ai_results = [];
$passing = [];      // students with at least one encoded grade, no at-risk subject, and no attendance concern
$top_performers = [];

foreach ($students as $student) {
    $sid = $student['student_id'];

    // Aggregate risk (average across VALID -- i.e. complete -- subjects
    // only). Risk level, reason, and performance figures are computed AND
    // persisted to ai_analysis by this one shared helper — see
    // includes/risk_helpers.php. This keeps every page that shows a risk
    // badge (this dashboard, Data Analytics, the student's own record, the
    // Student Portal, and Grading's student view) using the exact same
    // rule-based logic and the same live data, and it auto-refreshes here
    // every time this page is opened — no Teacher/Admin has to set a risk
    // level by hand. status is 'insufficient_data' when nothing complete
    // has been encoded yet -- handled below, never treated as an error.
    $risk = syncStudentRiskAnalysis($sid, $active_sy['sy_id'] ?? 0);
    $has_valid_grades = ($risk['status'] === 'ok');

    // Per-SUBJECT-TERM at-risk detail — includes a term still marked
    // Incomplete, which the aggregate above deliberately leaves out of the
    // average entirely (see getAtRiskSubjectRecords() docblock). This is
    // what actually decides membership in the At-Risk Students table below:
    // one failing/incomplete subject must never be hidden by a passing
    // overall average.
    $at_risk_subjects = getAtRiskSubjectRecords($sid, $active_sy['sy_id'] ?? 0);

    // Nothing encoded at all yet for this student (no valid grade AND no
    // at-risk subject) — skip entirely, same as before this fix.
    if (!$has_valid_grades && empty($at_risk_subjects)) {
        continue;
    }

    $max_grade = null;
    if ($has_valid_grades) {
        $valid_grades = getRecords("
            SELECT g.final_grade FROM grades g
            WHERE g.student_id = ? AND g.school_year_id = ? AND " . gh_validTermSql('g') . "
        ", [$sid, $active_sy['sy_id'] ?? 0]);
        if (!empty($valid_grades)) {
            $max_grade = max(array_column($valid_grades, 'final_grade'));
        }
    }

    // Which of this student's at-risk subjects does the CURRENT teacher
    // actually teach? This is what the AI chat is allowed to discuss —
    // same "one definition of at risk" query includes/gemini_service.php
    // re-checks server-side before every chat message, so a teacher
    // can never end up chatting about a subject they don't own. Now also
    // catches an Incomplete term (see getWeakSubjectsForStudent()), so a
    // teacher can generate a recovery quiz for a student whose term isn't
    // finished yet — not just an already-Failed one.
    $my_subjects = [];
    if ($is_teacher) {
        // Same records as the At-Risk list below (getAtRiskSubjectRecords()),
        // one entry per subject.
        $my_seen = [];
        foreach ($at_risk_subjects as $ws) {
            if (isset($my_seen[$ws['subject_code']])) continue;
            $my_seen[$ws['subject_code']] = true;
            if ((int) ($ws['teacher_id'] ?? 0) === $teacher_id) {
                // Grading shape for THIS subject/student — reflected into the
                // "Create Google Form Quiz" assessment picker in the chat
                // below, so the teacher only ever sees the WW/PT/Examinations
                // (and, when applicable, ST1/ST2/Term Exam) choices that are
                // actually valid for this subject, and a plain-language note
                // when the subject's grade needs to stay a manual step
                // (MAPEH-style multi-component / GMRC-Values domain grading —
                // see includes/ai_quiz_grade_sync.php for why those are
                // skipped rather than guessed at).
                $sw = getSubjectWeightsForStudent($ws['subject_code'], $sid, $active_sy);
                $sw_is_multi = count(gh_subjectComponents($ws['subject_code'], $sw['category'] ?? null)) > 1;
                $my_subjects[] = [
                    'code' => $ws['subject_code'],
                    'name' => $ws['subject_name'],
                    'grade' => (float) $ws['final_grade'],
                    'term' => $ws['term'] ?? '',
                    'missing_assessments' => [],
                    'exam_mode' => $sw['exam_mode'] ?? 'none',
                    'uses_domains' => !empty($sw['uses_domains']),
                    'is_multi' => $sw_is_multi,
                ];
            }
        }
    }

    // Attendance summary -- Present/Late/Absent/%/risk -- scoped to subjects
    // THIS teacher actually teaches (Administrator sees it across all of the
    // student's subjects, since there is no single owning teacher to scope
    // by). This is read-only reporting: it never touches $grades or the
    // WW/PT/Examinations computation above.
    $attendance_summary = $is_teacher
        ? gh_getStudentAttendanceSummaryForTeacher($teacher_id, $sid, $attendance_start, $attendance_end)
        : gh_getStudentAttendanceSummaryAll($sid, $attendance_start, $attendance_end);
    $last_support = getRecord(
        "SELECT sent_at AS activity_at FROM student_support_messages WHERE student_id = ? ORDER BY sent_at DESC LIMIT 1",
        [$sid]
    );
    $last_resource = getRecord(
        "SELECT generated_at AS activity_at FROM ai_generated_resources WHERE student_id = ? ORDER BY generated_at DESC LIMIT 1",
        [$sid]
    );
    $activity_dates = array_filter([
        $last_support['activity_at'] ?? null,
        $last_resource['activity_at'] ?? null,
    ]);
    rsort($activity_dates);
    $last_activity = $activity_dates[0] ?? null;

    // Keep the intervention workspace grounded in the same at-risk records
    // already used by this page. No new grades or assessment records are
    // created here.
    foreach ($my_subjects as &$my_subject) {
        foreach ($at_risk_subjects as $risk_subject) {
            if ($risk_subject['subject_code'] === $my_subject['code']) {
                $my_subject['term'] = $risk_subject['term'];
                $my_subject['missing_assessments'] = array_values($risk_subject['missing_assessments'] ?? []);
                break;
            }
        }
    }
    unset($my_subject);

    $ai_results[$sid] = [
        'student' => $student,
        'average' => $has_valid_grades ? $risk['average'] : null,
        'min_grade' => $has_valid_grades ? $risk['min_grade'] : null,
        'max_grade' => $max_grade,
        'risk_level' => $has_valid_grades ? $risk['risk_level'] : null,
        'reason' => $has_valid_grades ? $risk['reason'] : null,
        'recommendations' => $has_valid_grades ? implode(' ', generateRiskRecommendations($risk)) : '',
        'subject_count' => $has_valid_grades ? $risk['subject_count'] : 0,
        'at_risk_subjects' => $at_risk_subjects,
        'my_subjects' => $my_subjects,
        'attendance' => $attendance_summary,
        'last_activity' => $last_activity,
    ];


    // Clear, mutually-exclusive separation: a student is At-Risk the moment
    // ANY subject this term is below the passing grade or still Incomplete,
    // OR their attendance trips a school-configured threshold -- never
    // averaged away — otherwise they're Passing. Top Performers (>=90
    // average) is a subset of Passing, kept as its own highlight; a
    // student with an attendance concern is never called a Top Performer
    // even with a 90+ average, since they still need intervention.
    if (empty($at_risk_subjects) && empty($attendance_summary['at_risk']) && $has_valid_grades) {
        $passing[] = $sid;
        if ($risk['average'] >= 90) {
            $top_performers[] = $sid;
        }
    }
}

// At-Risk Students is now a UNIFIED per-student list: Risk Type is Academic,
// Attendance, or Both -- never two separate tables, so a student in both
// conditions shows up exactly once. A student can land here purely on
// attendance even with zero grades encoded yet at all (the loop above
// skips building $ai_results for those), so this second pass computes
// attendance fresh for anyone the first pass didn't already cover.
$risk_students = [];
foreach ($students as $student) {
    $sid = $student['student_id'];
    $result = $ai_results[$sid] ?? null;

    $academic_subjects = $result['at_risk_subjects'] ?? [];
    $attendance = $result['attendance'] ?? ($is_teacher
        ? gh_getStudentAttendanceSummaryForTeacher($teacher_id, $sid, $attendance_start, $attendance_end)
        : gh_getStudentAttendanceSummaryAll($sid, $attendance_start, $attendance_end));

    $has_academic_risk = !empty($academic_subjects);
    $has_attendance_risk = !empty($attendance['at_risk']);
    if (!$has_academic_risk && !$has_attendance_risk) {
        continue;
    }

    $risk_students[$sid] = [
        'student' => $student,
        'risk_type' => $has_academic_risk && $has_attendance_risk ? 'Both' : ($has_academic_risk ? 'Academic' : 'Attendance'),
        'has_academic_risk' => $has_academic_risk,
        'has_attendance_risk' => $has_attendance_risk,
        'academic_subjects' => $academic_subjects,
        'attendance' => $attendance,
        'my_subjects' => $result['my_subjects'] ?? [],
    ];
}

// ---- Grouped At-Risk view: Grade Level -> Subject -> shared problem ----
// Built from the SAME $risk_students above (nothing is recomputed or saved).
//   Academic tab   : a student joins one group per shared problem in a
//                    subject/term -- each missing assessment (e.g. "Quiz 3"),
//                    otherwise "Term grade incomplete" or "Below passing grade".
//   Attendance tab : groups are Grade Level -> kind of attendance concern.
//                    Attendance risk itself is judged per school day (not per
//                    subject), so the Subject filter here narrows the list to
//                    students who were Absent/Late in that subject's class.
$risk_groups = ['academic' => [], 'attendance' => []];
$att_thresholds = gh_getAttendanceRiskThresholds();
$attendance_concern_labels = [
    'consecutive' => 'Consecutive absences',
    'absences' => 'Frequent absences / low attendance',
    'late' => 'Frequent lateness',
];

// Per-student, per-subject Absent/Late counts (same scoping as the summary
// above: a teacher sees only the classes they teach). Display/filter only.
$att_subject_map = [];
$att_risk_ids = [];
foreach ($risk_students as $sid_a => $ra) {
    if ($ra['has_attendance_risk']) $att_risk_ids[] = (int) $sid_a;
}
if (!empty($att_risk_ids)) {
    try {
        $ph_a = implode(',', array_fill(0, count($att_risk_ids), '?'));
        $params_a = $att_risk_ids;
        $scope_a = '';
        if ($is_teacher) {
            $scope_a = " AND EXISTS (SELECT 1 FROM teacher_subjects ts WHERE ts.subject_code = a.subject_code AND ts.section_id = a.section_id AND ts.teacher_id = ?)";
            $params_a[] = $teacher_id;
        }
        $params_a[] = $attendance_start;
        $params_a[] = $attendance_end;
        $rows_a = getRecords(
            "SELECT a.student_id, a.subject_code, sub.subject_name,
                    SUM(a.status = 'Absent') AS absent_n, SUM(a.status = 'Late') AS late_n
             FROM attendance a
             JOIN subjects sub ON sub.subject_code = a.subject_code
             WHERE a.student_id IN ($ph_a) $scope_a
               AND a.date >= ? AND a.date <= ? AND a.status IN ('Absent', 'Late')
             GROUP BY a.student_id, a.subject_code, sub.subject_name",
            $params_a
        );
        foreach ($rows_a as $row_a) {
            $att_subject_map[(int) $row_a['student_id']][$row_a['subject_code']] = [
                'name' => $row_a['subject_name'],
                'absent' => (int) $row_a['absent_n'],
                'late' => (int) $row_a['late_n'],
            ];
        }
    } catch (\Throwable $e) {
        error_log('AI Analytics: attendance-by-subject lookup failed: ' . $e->getMessage());
        $att_subject_map = [];
    }
}

foreach ($risk_students as $sid_g => $rg) {
    $stu_g = $rg['student'];
    $grade_key = trim((string) $stu_g['grade_level']) !== '' ? (string) $stu_g['grade_level'] : 'No grade level';
    $my_codes_g = array_column($rg['my_subjects'], 'code');

    if ($rg['has_academic_risk']) {
        foreach ($rg['academic_subjects'] as $ar_g) {
            $mine_g = $is_teacher && $ar_g['teacher_id'] !== null
                && (int) $ar_g['teacher_id'] === (int) $teacher_id
                && in_array($ar_g['subject_code'], $my_codes_g, true);
            // One entry per student per subject-term. Students are grouped only
            // when they share the SAME subject, term and risk reasons -- the same
            // missing assessments, the same low components, both below passing.
            $reason_types = [];
            foreach ($ar_g['risk_reasons'] as $rr) {
                $reason_types[] = $rr['type'] === 'low' ? 'low:' . $rr['category'] : $rr['type'];
            }
            sort($reason_types);
            $missing_ids = array_map('intval', array_column($ar_g['missing_details'], 'item_id'));
            sort($missing_ids);
            $gkey = $ar_g['term'] . '|' . implode(',', $reason_types) . '|' . implode(',', $missing_ids);

            $subj_bucket = &$risk_groups['academic'][$grade_key][$ar_g['subject_code']];
            if (!isset($subj_bucket)) {
                $subj_bucket = ['subject_name' => $ar_g['subject_name'], 'groups' => []];
            }
            if (!isset($subj_bucket['groups'][$gkey])) {
                // Group-level reason labels (a grade differs per student, so a group says "Below passing grade").
                $g_reasons = [];
                foreach ($ar_g['risk_reasons'] as $rr) {
                    $g_reasons[] = $rr['type'] === 'grade' ? 'Below passing grade' : $rr['label'];
                }
                $subj_bucket['groups'][$gkey] = [
                    'kind' => 'risk',
                    'item' => '',
                    'term' => $ar_g['term'],
                    'subject_code' => $ar_g['subject_code'],
                    'subject_name' => $ar_g['subject_name'],
                    'reasons' => $g_reasons,
                    'missing_details' => $ar_g['missing_details'],
                    'low_items' => $ar_g['low_items'] ?? [],
                    'weak_topics' => $ar_g['weak_topics'],
                    'members' => [],
                ];
            } else {
                $subj_bucket['groups'][$gkey]['weak_topics'] = array_values(array_unique(array_merge(
                    $subj_bucket['groups'][$gkey]['weak_topics'], $ar_g['weak_topics']
                )));
            }
            $subj_bucket['groups'][$gkey]['members'][] = [
                'student_id' => (int) $stu_g['student_id'],
                'can_send' => $mine_g,
                'send_subject' => $mine_g ? $ar_g['subject_code'] : '',
                'status' => $ar_g['remarks'] === 'Incomplete' ? 'Incomplete'
                    : (!empty($ar_g['in_progress']) && (float) $ar_g['final_grade'] <= 0 ? 'In progress' : number_format((float) $ar_g['final_grade'], 1) . '%' . (!empty($ar_g['in_progress']) ? ' · in progress' : '')),
                'status_type' => $ar_g['remarks'] === 'Incomplete' || (!empty($ar_g['in_progress']) && (float) $ar_g['final_grade'] <= 0) ? 'incomplete' : 'grade',
                'reasons' => array_column($ar_g['risk_reasons'], 'label'),
                'other_missing' => [],
                'subjects' => [],
            ];
            unset($subj_bucket);
        }
    }

    if ($rg['has_attendance_risk']) {
        $att_g = $rg['attendance'];
        if ((int) ($att_g['consecutive_absent'] ?? 0) >= $att_thresholds['consecutive']) {
            $concern_g = 'consecutive';
        } elseif (($att_g['percentage'] !== null && $att_g['percentage'] < $att_thresholds['pct']) || (int) $att_g['absent'] >= $att_thresholds['absences']) {
            $concern_g = 'absences';
        } else {
            $concern_g = 'late';
        }
        $can_send_g = $is_teacher && !empty($rg['my_subjects']);
        $risk_groups['attendance'][$grade_key][$concern_g]['members'][] = [
            'student_id' => (int) $stu_g['student_id'],
            'can_send' => $can_send_g,
            'send_subject' => $can_send_g ? $rg['my_subjects'][0]['code'] : '',
            'status' => $att_g['percentage'] !== null ? number_format((float) $att_g['percentage'], 1) . '% attendance' : 'No records',
            'status_type' => 'attendance',
            'other_missing' => [],
            'absent' => (int) $att_g['absent'],
            'late' => (int) $att_g['late'],
            'subjects' => $att_subject_map[(int) $stu_g['student_id']] ?? [],
        ];
    }
}
// Stable, readable order: Grade 7 -> 12, subjects A-Z, bigger groups first.
$risk_grade_sort = function ($a, $b) {
    $na = (int) preg_replace('/\D/', '', $a);
    $nb = (int) preg_replace('/\D/', '', $b);
    return $na === $nb ? strnatcasecmp($a, $b) : $na <=> $nb;
};
uksort($risk_groups['academic'], $risk_grade_sort);
uksort($risk_groups['attendance'], $risk_grade_sort);
foreach ($risk_groups['academic'] as &$subjects_g) {
    uasort($subjects_g, function ($a, $b) { return strcasecmp($a['subject_name'], $b['subject_name']); });
    foreach ($subjects_g as &$subj_g) {
        uasort($subj_g['groups'], function ($a, $b) {
            return count($b['members']) <=> count($a['members']) ?: strcasecmp($a['term'] . $a['item'], $b['term'] . $b['item']);
        });
    }
    unset($subj_g);
}
unset($subjects_g);
foreach ($risk_groups['attendance'] as &$concerns_g) {
    uasort($concerns_g, function ($a, $b) { return count($b['members']) <=> count($a['members']); });
}
unset($concerns_g);

// Data for the Grade Level / Subject dropdowns (one list per tab).
$risk_filter_data = ['academic' => [], 'attendance' => []];
foreach ($risk_groups['academic'] as $fg => $fsubjects) {
    $list = [];
    foreach ($fsubjects as $fcode => $fs) $list[] = ['code' => $fcode, 'name' => $fs['subject_name']];
    $risk_filter_data['academic'][] = ['grade' => $fg, 'subjects' => $list];
}
foreach ($risk_groups['attendance'] as $fg => $fconcerns) {
    $seen = [];
    foreach ($fconcerns as $fc) {
        foreach ($fc['members'] as $fm) {
            foreach ($fm['subjects'] as $code_f => $sub_f) $seen[$code_f] = $sub_f['name'];
        }
    }
    $list = [];
    foreach ($seen as $code_f => $name_f) $list[] = ['code' => $code_f, 'name' => $name_f];
    $risk_filter_data['attendance'][] = ['grade' => $fg, 'subjects' => $list];
}

// Calculate overall statistics
$total_students = count($students);
$at_risk_count = count($risk_students);
$academic_risk_count = count(array_filter($risk_students, function ($r) { return $r['risk_type'] === 'Academic'; }));
$attendance_risk_count = count(array_filter($risk_students, function ($r) { return $r['risk_type'] === 'Attendance'; }));
$both_risk_count = count(array_filter($risk_students, function ($r) { return $r['risk_type'] === 'Both'; }));
$passing_count = count($passing);
$top_count = count($top_performers);

$csrf_token = generateCSRFToken();

include '../../includes/header.php';
?>

<div class="ai-analytics-page">

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="analytics-title"><i class="fas fa-brain me-2"></i>AI Analytics Dashboard</h2>
            <button class="btn analytics-refresh" onclick="location.reload()">
                <i class="fas fa-sync me-2"></i>Refresh Analysis
            </button>
        </div>
    </div>
</div>
<style>
    .ai-analytics-page {
        --analytics-navy: #14213D;
        --analytics-blue: #356EA8;
        --analytics-blue-soft: #EDF4FB;
        --analytics-risk: #B94A48;
        --analytics-risk-soft: #FBEDEE;
        --analytics-good: #2F7564;
        --analytics-good-soft: #EAF5F1;
        --analytics-border: #DCE3EC;
        --analytics-muted: #667085;
    }
    .ai-analytics-page .analytics-title {
        color: var(--analytics-navy);
        font-size: 26px;
        font-weight: 700;
        letter-spacing: -.01em;
    }
    .ai-analytics-page .analytics-title i { color: var(--analytics-blue); }
    .ai-analytics-page .analytics-refresh {
        border: 1px solid var(--analytics-border);
        background: #fff;
        color: var(--analytics-navy);
        border-radius: 8px;
        font-weight: 600;
    }
    .ai-analytics-page .analytics-refresh:hover { background: var(--analytics-blue-soft); }
    .ai-analytics-page .stats-card,
    .ai-analytics-page > .card {
        border: 1px solid var(--analytics-border) !important;
        border-radius: 10px !important;
        box-shadow: 0 2px 8px rgba(20,33,61,.045) !important;
    }
    .ai-analytics-page .stats-card .card-body { padding: 20px; }
    .ai-analytics-page .stats-card h6 { color: var(--analytics-muted) !important; font-size: 12px; font-weight: 600; }
    .ai-analytics-page .stats-card h2 { color: var(--analytics-navy); font-size: 28px; }
    .ai-analytics-page .stats-card .text-success { color: var(--analytics-good) !important; }
    .ai-analytics-page .stats-card .text-danger { color: var(--analytics-risk) !important; }
    .ai-analytics-page .stats-card .text-warning { color: var(--analytics-blue) !important; }
    .ai-analytics-page .stats-icon {
        width: 42px;
        height: 42px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: var(--analytics-blue-soft) !important;
    }
    .ai-analytics-page .stats-icon i { color: var(--analytics-blue) !important; }
    .ai-analytics-page .card-header { background: #fff !important; border-bottom: 1px solid var(--analytics-border) !important; }
    .ai-analytics-page .card-header h5 { color: var(--analytics-navy); font-size: 17px; font-weight: 700; }
    .ai-analytics-page .card-header h5 i { color: var(--analytics-risk) !important; }
    .ai-analytics-page .table thead th {
        background: #F5F7FA !important;
        color: #667085;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
        border-bottom: 1px solid var(--analytics-border);
        white-space: nowrap;
    }
    .ai-analytics-page .table td { color: #344054; border-color: #E9EDF2; vertical-align: middle; }
    .ai-analytics-page .table-hover tbody tr:hover { background: #F7FAFC !important; }
    .ai-analytics-page .table-danger,
    .ai-analytics-page .table-warning,
    .ai-analytics-page .table-success { --bs-table-bg: transparent; }
    .ai-analytics-page .table-danger td { background: var(--analytics-risk-soft) !important; }
    .ai-analytics-page .table-warning td { background: #FFF8EC !important; }
    .ai-analytics-page .table-success td { background: var(--analytics-good-soft) !important; }
    .ai-analytics-page .badge.bg-danger,
    .ai-analytics-page .badge.bg-info,
    .ai-analytics-page .badge.bg-warning,
    .ai-analytics-page .badge.bg-success { background: #E9EEF5 !important; color: var(--analytics-navy) !important; }
    .ai-analytics-page .badge.bg-danger { color: var(--analytics-risk) !important; }
    .ai-analytics-page .badge.bg-success { color: var(--analytics-good) !important; }
    .ai-analytics-page .badge.bg-warning { color: #916A25 !important; }
    .ai-analytics-page .badge[style*="5B2A86"] { background: #E9EEF5 !important; color: var(--analytics-navy) !important; }
    .ai-analytics-page .at-risk-toggle { background: #F5F7FA; border-color: var(--analytics-border); }
    .ai-analytics-page .at-risk-toggle-btn.active { background: var(--analytics-navy); }
    .ai-analytics-page .ai-chat-btn,
    .ai-analytics-page .ai-action-icon { border-color: var(--analytics-border); color: var(--analytics-navy); background: #fff; }
    .ai-analytics-page .ai-chat-btn:hover,
    .ai-analytics-page .ai-action-icon:hover { background: var(--analytics-blue-soft); border-color: #B8CDE3; color: var(--analytics-blue); }
    .ai-analytics-page .text-danger { color: var(--analytics-risk) !important; }
    .ai-analytics-page .text-success { color: var(--analytics-good) !important; }
    .ai-analytics-page .ai-analytics-empty { color: var(--analytics-muted); padding: 32px 20px; }
    @media (max-width: 768px) {
        .ai-analytics-page .analytics-title { font-size: 22px; }
        .ai-analytics-page .stats-card .card-body { padding: 16px; }
    }
</style>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card stats-card border-0 shadow-sm rounded-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Students Analyzed</h6>
                        <h2 class="mb-0 fw-bold"><?php echo number_format($total_students); ?></h2>
                    </div>
                    <div class="stats-icon bg-primary bg-opacity-10">
                        <i class="fas fa-users text-primary"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stats-card border-0 shadow-sm rounded-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Passing Students</h6>
                        <h2 class="mb-0 fw-bold text-success"><?php echo number_format($passing_count); ?></h2>
                    </div>
                    <div class="stats-icon bg-success bg-opacity-10">
                        <i class="fas fa-check-circle text-success"></i>
                    </div>
                </div>
                <div class="mt-2">
                    <small class="text-muted"><?php echo $total_students > 0 ? round(($passing_count / $total_students) * 100, 1) : 0; ?>% of total</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stats-card border-0 shadow-sm rounded-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">At-Risk Students</h6>
                        <h2 class="mb-0 fw-bold text-danger"><?php echo number_format($at_risk_count); ?></h2>
                    </div>
                    <div class="stats-icon bg-danger bg-opacity-10">
                        <i class="fas fa-exclamation-triangle text-danger"></i>
                    </div>
                </div>
                <div class="mt-2">
                    <small class="text-muted"><?php echo $total_students > 0 ? round(($at_risk_count / $total_students) * 100, 1) : 0; ?>% of total</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stats-card border-0 shadow-sm rounded-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Top Performers</h6>
                        <h2 class="mb-0 fw-bold text-warning"><?php echo number_format($top_count); ?></h2>
                    </div>
                    <div class="stats-icon bg-warning bg-opacity-10">
                        <i class="fas fa-star text-warning"></i>
                    </div>
                </div>
                <div class="mt-2">
                    <small class="text-muted">Students with 90%+ average</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- At-Risk Students — ONE row per STUDENT, unified across two INDEPENDENT
     conditions: Academic Risk (a finalized Term Grade below the passing
     mark, or a term still Incomplete/missing requirements -- see
     getAtRiskSubjectRecords()) and Attendance Risk (repeated absences,
     consecutive absences, frequent lateness, or attendance % below the
     school-configured warning threshold in Settings -> Attendance Schedule
     -- see gh_getAttendanceRiskThresholds()). Risk Type shows which
     condition(s) apply (Academic / Attendance / Both). Attendance is
     reporting only: it never adds to or subtracts from Written Works,
     Performance Tasks, Examinations, or the Term Grade -- see
     includes/attendance_helpers.php. Both conditions are recomputed live
     on every page load, so a student drops off automatically the moment
     the relevant condition is resolved (a finalized/re-finalized grade
     rises to passing/complete, or attendance recovers above threshold) --
     nothing here is cached or needs a manual removal. -->
<div class="card shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white border-0 pt-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="mb-0"><i class="fas fa-exclamation-triangle text-danger me-2"></i>At-Risk Students</h5>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-danger"><?php echo $at_risk_count; ?> total</span>
                <span class="badge bg-danger bg-opacity-75"><?php echo $academic_risk_count; ?> Academic</span>
                <span class="badge bg-info"><?php echo $attendance_risk_count; ?> Attendance</span>
                <span class="badge" style="background:#5B2A86;"><?php echo $both_risk_count; ?> Both</span>
            </div>
        </div>
        <?php if ($at_risk_count > 0): ?>
        <div class="at-risk-toggle" id="atRiskToggle" role="tablist" aria-label="At-risk view">
            <button type="button" class="at-risk-toggle-btn active" data-view="academic" role="tab" aria-selected="true">
                <i class="fas fa-book me-1"></i> Academic At-Risk
            </button>
            <button type="button" class="at-risk-toggle-btn" data-view="attendance" role="tab" aria-selected="false">
                <i class="fas fa-calendar-day me-1"></i> Attendance At-Risk
            </button>
        </div>
        <div class="arg-filters">
            <div class="arg-filter">
                <label for="argGrade">Grade Level</label>
                <select id="argGrade" class="form-select"><option value="">All grade levels</option></select>
            </div>
            <div class="arg-filter">
                <label for="argSubject">Subject</label>
                <select id="argSubject" class="form-select"><option value="">All subjects</option></select>
            </div>
            <div class="arg-summary" id="argSummary" aria-live="polite"></div>
        </div>
        <script type="application/json" id="argFilterData"><?php echo json_encode($risk_filter_data, JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if ($at_risk_count > 0): ?>
            <?php
            $arg_seq = 0;
            foreach (['academic', 'attendance'] as $arg_view):
                $arg_data = $risk_groups[$arg_view];
            ?>
            <div class="arg-wrap d-none" id="atRiskGroups<?php echo ucfirst($arg_view); ?>" data-view="<?php echo $arg_view; ?>">
                <?php if (empty($arg_data)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-check-circle fa-2x text-success d-block mb-2"></i>
                        <?php echo $arg_view === 'academic' ? 'No students currently have an Academic At-Risk concern.' : 'No students currently have an Attendance At-Risk concern.'; ?>
                    </div>
                <?php endif; ?>
                <?php foreach ($arg_data as $arg_grade => $arg_bucket): ?>
                    <?php
                        // Normalise the two shapes to: [ ['code' => subject code|null, 'title' => subject|null, 'groups' => [...]], ... ]
                        $arg_sections = [];
                        if ($arg_view === 'academic') {
                            foreach ($arg_bucket as $subj_code_g => $subj_g) {
                                $arg_sections[] = ['code' => $subj_code_g, 'title' => $subj_g['subject_name'], 'groups' => $subj_g['groups']];
                            }
                        } else {
                            $arg_sections[] = ['code' => null, 'title' => null, 'groups' => $arg_bucket];
                        }
                    ?>
                    <section class="arg-grade" data-grade="<?php echo htmlspecialchars($arg_grade); ?>">
                        <button type="button" class="arg-grade-head" aria-expanded="true">
                            <span class="arg-grade-title"><i class="fas fa-graduation-cap me-2"></i><?php echo htmlspecialchars($arg_grade); ?></span>
                            <span class="arg-grade-meta">
                                <span class="badge bg-danger bg-opacity-75 arg-grade-students"></span>
                                <span class="badge bg-secondary bg-opacity-75 arg-grade-groups"></span>
                                <i class="fas fa-chevron-down arg-chev" aria-hidden="true"></i>
                            </span>
                        </button>
                        <div class="arg-grade-body">
                        <?php foreach ($arg_sections as $sec_g): ?>
                            <div class="arg-subj-block" data-subject-code="<?php echo htmlspecialchars((string) $sec_g['code']); ?>">
                            <?php if ($sec_g['title'] !== null): ?>
                                <div class="arg-subject"><i class="fas fa-book-open me-2"></i><?php echo htmlspecialchars($sec_g['title']); ?></div>
                            <?php endif; ?>
                            <?php foreach ($sec_g['groups'] as $arg_key => $grp): ?>
                                <?php
                                    $arg_seq++;
                                    $members_g = $grp['members'];
                                    $n_members = count($members_g);
                                    $any_sendable = false;
                                    foreach ($members_g as $mem_g) { if ($mem_g['can_send']) { $any_sendable = true; break; } }
                                    if ($arg_view === 'academic') {
                                        $g_kind = $grp['kind'];
                                        $g_concern = '';
                                        $g_subject_code = $grp['subject_code'];
                                        $g_subject_name = $grp['subject_name'];
                                        $g_term = $grp['term'];
                                        $g_item = $grp['item'];
                                        $g_single = ($n_members === 1);
                                        $g_missing = $grp['missing_details'] ?? [];
                                        $g_topics = $grp['weak_topics'] ?? [];
                                        $g_low = $grp['low_items'] ?? [];
                                        // One student: an individual card (their name, their exact reasons).
                                        // Several students: a group that shares the same reasons.
                                        if ($g_single) {
                                            $one = $risk_students[$members_g[0]['student_id']]['student'];
                                            $g_title = $one['last_name'] . ', ' . $one['first_name'];
                                            $g_reasons = $members_g[0]['reasons'] ?? [];
                                            $g_icon = 'fa-user';
                                        } else {
                                            $g_reasons = $grp['reasons'] ?? [];
                                            $g_title = implode(' · ', $g_reasons);
                                            $g_icon = 'fa-users';
                                        }
                                        $has_panel = $is_teacher && $any_sendable;
                                    } else {
                                        $g_kind = 'attendance';
                                        $g_concern = $arg_key;
                                        $g_subject_code = '';
                                        $g_subject_name = '';
                                        $g_term = '';
                                        $g_item = '';
                                        $g_title = $attendance_concern_labels[$arg_key] ?? 'Attendance concern';
                                        $g_icon = 'fa-calendar-xmark';
                                        $g_single = false;
                                        $g_missing = [];
                                        $g_topics = [];
                                        $g_low = [];
                                        $g_reasons = [];
                                        $has_panel = $is_teacher;
                                    }
                                ?>
                                <article class="arg-card" id="arg-<?php echo $arg_view . '-' . $arg_seq; ?>"
                                    data-kind="<?php echo htmlspecialchars($g_kind); ?>"
                                    data-concern="<?php echo htmlspecialchars($g_concern); ?>"
                                    data-grade="<?php echo htmlspecialchars($arg_grade); ?>"
                                    data-subject-code="<?php echo htmlspecialchars($g_subject_code); ?>"
                                    data-subject-name="<?php echo htmlspecialchars($g_subject_name); ?>"
                                    data-term="<?php echo htmlspecialchars($g_term); ?>"
                                    data-item="<?php echo htmlspecialchars($g_item); ?>"
                                    data-single="<?php echo $g_single ? '1' : '0'; ?>"
                                    data-reasons="<?php echo htmlspecialchars(json_encode(array_values($g_reasons), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>"
                                    data-missing="<?php echo htmlspecialchars(json_encode(array_values($g_missing), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>"
                                    data-topics="<?php echo htmlspecialchars(json_encode(array_values($g_topics), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>"
                                    data-low="<?php echo htmlspecialchars(json_encode(array_values($g_low), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>"
                                    data-panel="<?php echo $has_panel ? '1' : '0'; ?>">
                                    <header class="arg-card-head">
                                        <div class="arg-card-title">
                                            <i class="fas <?php echo $g_icon; ?>"></i>
                                            <strong><?php echo htmlspecialchars($g_title); ?></strong>
                                            <?php if ($g_term !== ''): ?><span class="arg-chip"><?php echo htmlspecialchars($g_term); ?></span><?php endif; ?>
                                            <?php if (!$g_single): ?><span class="badge bg-danger arg-count"><?php echo $n_members; ?> student<?php echo $n_members === 1 ? '' : 's'; ?></span><?php endif; ?>
                                        </div>
                                        <?php if ($has_panel): ?>
                                            <button type="button" class="btn btn-sm arg-ask-btn" aria-haspopup="dialog"
                                                    title="<?php echo $g_single ? 'Ask AI about this student' : 'Ask AI about the students in this group'; ?>">
                                                <i class="fas fa-robot me-1" aria-hidden="true"></i><span>Ask AI</span>
                                            </button>
                                        <?php endif; ?>
                                    </header>
                                    <div class="arg-card-body">
                                        <?php if ($arg_view === 'academic'): ?>
                                            <?php if (!empty($g_reasons)): ?>
                                                <div class="arg-why">
                                                    <?php foreach ($g_reasons as $rsn): ?>
                                                        <span class="arg-why-chip"><i class="fas fa-circle-exclamation"></i><?php echo htmlspecialchars($rsn); ?></span>
                                                    <?php endforeach; ?>
                                                    <?php if (!empty($g_topics)): ?>
                                                        <span class="arg-why-chip is-topic"><i class="fas fa-book"></i>Weak Topic: <?php echo htmlspecialchars(implode(', ', array_slice($g_topics, 0, 3))); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($g_missing)): ?>
                                                <div class="arg-missing">
                                                    <div class="arg-missing-head">Missing Assessments<?php if ($has_panel): ?> <small>— click one to prepare its make-up</small><?php endif; ?></div>
                                                    <ul>
                                                    <?php foreach ($g_missing as $md):
                                                        $md_line = $md['name'] . (!empty($md['component']) ? ' (' . $md['component'] . ')' : '') . ' — ' . $md['category_label'] . ' — ' . $md['mode'] . ($md['topic'] !== '' ? ' — Topic: ' . $md['topic'] : '');
                                                        $md_icon = in_array($md['kind'], ['quiz', 'exam'], true) ? 'fa-list-check' : ($md['kind'] === 'performance_task' ? 'fa-person-chalkboard' : 'fa-file-lines');
                                                    ?>
                                                        <li>
                                                            <?php if ($has_panel): ?>
                                                                <button type="button" class="arg-remedy" data-item-id="<?php echo (int) $md['item_id']; ?>"
                                                                        title="Prepare a make-up for <?php echo htmlspecialchars($md['name']); ?>">
                                                                    <i class="fas <?php echo $md_icon; ?>"></i><span><?php echo htmlspecialchars($md_line); ?></span>
                                                                </button>
                                                            <?php else: ?>
                                                                <span class="arg-remedy is-static"><i class="fas <?php echo $md_icon; ?>"></i><span><?php echo htmlspecialchars($md_line); ?></span></span>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($g_low)): ?>
                                                <div class="arg-missing">
                                                    <div class="arg-missing-head">Low-Scored Assessments<?php if ($has_panel): ?> <small>— click one to prepare a review activity on its topic</small><?php endif; ?></div>
                                                    <ul>
                                                    <?php foreach ($g_low as $lw):
                                                        $lw_line = $lw['name'] . ' — ' . $lw['category_label'] . ($g_single ? ' — Score ' . $lw['score'] : '') . ($lw['topic'] !== '' ? ' — Topic: ' . $lw['topic'] : '');
                                                    ?>
                                                        <li>
                                                            <?php if ($has_panel): ?>
                                                                <button type="button" class="arg-remedy is-low" data-low-id="<?php echo (int) $lw['item_id']; ?>"
                                                                        title="Prepare a review activity for <?php echo htmlspecialchars($lw['name']); ?>">
                                                                    <i class="fas fa-arrow-trend-down"></i><span><?php echo htmlspecialchars($lw_line); ?></span>
                                                                </button>
                                                            <?php else: ?>
                                                                <span class="arg-remedy is-static"><i class="fas fa-arrow-trend-down"></i><span><?php echo htmlspecialchars($lw_line); ?></span></span>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <ul class="arg-member-list<?php echo $g_single ? ' is-single' : ''; ?>">
                                        <?php foreach ($members_g as $mem_g): ?>
                                            <?php
                                                $mr = $risk_students[$mem_g['student_id']];
                                                $ms = $mr['student'];
                                                $mem_subjects = [];
                                                foreach ($mem_g['subjects'] as $code_m => $sub_m) {
                                                    $mem_subjects[$code_m] = [$sub_m['absent'], $sub_m['late'], $sub_m['name']];
                                                }
                                            ?>
                                            <li class="arg-member" data-sid="<?php echo (int) $mem_g['student_id']; ?>"
                                                data-mine="<?php echo $mem_g['can_send'] ? '1' : '0'; ?>"
                                                data-att-subjects='<?php echo htmlspecialchars(json_encode($mem_subjects), ENT_QUOTES); ?>'>
                                                <?php if ($mem_g['can_send']): ?>
                                                    <input type="checkbox" class="arg-check" value="<?php echo (int) $mem_g['student_id']; ?>"
                                                           data-subject="<?php echo htmlspecialchars($mem_g['send_subject']); ?>"
                                                           aria-label="Select <?php echo htmlspecialchars($ms['last_name'] . ', ' . $ms['first_name']); ?>">
                                                <?php elseif ($is_teacher): ?>
                                                    <input type="checkbox" class="arg-check" disabled
                                                           title="<?php echo $arg_view === 'academic' ? 'This subject is not assigned to you, so you cannot message this student from here.' : 'No at-risk subject of yours to attach a message to.'; ?>">
                                                <?php endif; ?>
                                                <div class="arg-member-main">
                                                    <div class="arg-member-name"><?php echo htmlspecialchars($ms['last_name'] . ', ' . $ms['first_name']); ?></div>
                                                    <div class="arg-member-sub">
                                                        <span class="text-muted"><?php echo htmlspecialchars($ms['lrn']); ?></span>
                                                        <?php if ($mem_g['status_type'] === 'incomplete'): ?>
                                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($mem_g['status']); ?></span>
                                                        <?php elseif ($mem_g['status_type'] === 'grade'): ?>
                                                            <span class="badge arg-badge-grade"><?php echo htmlspecialchars($mem_g['status']); ?></span>
                                                        <?php else: ?>
                                                            <span class="badge arg-badge-grade"><?php echo htmlspecialchars($mem_g['status']); ?></span>
                                                            <span class="text-muted"><?php echo (int) $mem_g['absent']; ?> absent · <?php echo (int) $mem_g['late']; ?> late</span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($mem_g['other_missing'])): ?>
                                                            <span class="arg-more" tabindex="0" role="button"
                                                                  data-items="<?php echo htmlspecialchars(json_encode(array_values($mem_g['other_missing']), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>"
                                                                  aria-label="Also missing: <?php echo htmlspecialchars(implode(', ', $mem_g['other_missing'])); ?>">+<?php echo count($mem_g['other_missing']); ?> more missing</span>
                                                        <?php endif; ?>
                                                        <span class="arg-subj-att d-none"></span>
                                                        <span class="arg-sent d-none"><i class="fas fa-check-circle"></i> Sent</span>
                                                        <span class="arg-fail d-none"></span>
                                                    </div>
                                                </div>
                                                <div class="arg-member-actions">
                                                    <?php if ($is_teacher && !empty($mr['my_subjects']) && $arg_view !== 'academic'): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-primary ai-chat-btn"
                                                            data-tooltip="Ask AI about this student only" aria-label="Ask AI about this student only"
                                                            data-student-id="<?php echo (int) $ms['student_id']; ?>"
                                                            data-student-name="<?php echo htmlspecialchars($ms['last_name'] . ', ' . $ms['first_name']); ?>"
                                                            data-risk-type="<?php echo htmlspecialchars($mr['risk_type']); ?>"
                                                            data-attendance='<?php echo htmlspecialchars(json_encode($mr['attendance']), ENT_QUOTES); ?>'
                                                            data-last-activity="<?php echo htmlspecialchars($ai_results[(int) $ms['student_id']]['last_activity'] ?? ''); ?>"
                                                            data-subjects='<?php echo htmlspecialchars(json_encode($mr['my_subjects']), ENT_QUOTES); ?>'>
                                                            <i class="fas fa-robot" aria-hidden="true"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <a href="<?php echo BASE_URL; ?>modules/tutorial/index.php" class="btn btn-sm btn-outline-dark ai-action-icon"
                                                       data-tooltip="Tutorial Plan" aria-label="Tutorial Plan">
                                                        <i class="fas fa-person-chalkboard" aria-hidden="true"></i>
                                                    </a>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                        </ul>
                                        <?php if ($has_panel): ?>
                                            <?php /* The group's Ask AI opens the "Ask AI - Group session" dialog (#argModal),
                                                     so there is nothing to expand inline here. */ ?>
                                        <?php elseif ($is_teacher): ?>
                                            <div class="arg-rec-note"><i class="fas fa-circle-info me-1"></i> This subject is assigned to another teacher, so the recommendation is prepared by them.</div>
                                        <?php else: ?>
                                            <div class="arg-rec-note"><i class="fas fa-circle-info me-1"></i> The assigned subject teacher prepares and sends the group recommendation.</div>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            <div class="text-center py-4 text-muted d-none" id="argEmpty">
                <i class="fas fa-filter-circle-xmark fa-2x d-block mb-2"></i>
                No at-risk groups match this Grade Level and Subject.
            </div>
        <?php else: ?>
            <div class="text-center py-4 text-muted">
                <i class="fas fa-check-circle fa-2x text-success d-block mb-2"></i>
                No at-risk students identified. Great job!
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
    .ai-action-icon {
        position: relative;
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        margin-left: 4px;
    }
    .ai-chat-btn[data-tooltip] { position: relative; }
    .ai-action-icon[data-tooltip]::after,
    .ai-chat-btn[data-tooltip]::after {
        content: attr(data-tooltip);
        position: absolute;
        right: 0;
        bottom: calc(100% + 7px);
        z-index: 20;
        padding: 5px 8px;
        border-radius: 5px;
        background: #14213D;
        color: #fff;
        font-size: 11px;
        font-weight: 600;
        line-height: 1.2;
        white-space: nowrap;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transform: translateY(3px);
        transition: opacity .15s ease, transform .15s ease;
    }
    .ai-action-icon[data-tooltip]:hover::after,
    .ai-chat-btn[data-tooltip]:hover::after,
    .ai-action-icon[data-tooltip]:focus-visible::after,
    .ai-chat-btn[data-tooltip]:focus-visible::after {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }
    .at-risk-toggle { display:inline-flex; background: var(--surface, #F1F3F6); border: 1px solid var(--border, #DDE2E8); border-radius:8px; padding:3px; gap:2px; }
    .at-risk-toggle-btn { border:none; background:transparent; padding:7px 16px; font-size:13px; font-weight:500; color: var(--text-muted, #6B7280); border-radius:6px; cursor:pointer; transition: background .15s ease, color .15s ease; white-space:nowrap; }
    .at-risk-toggle-btn:hover { color: var(--ink, #14213D); }
    .at-risk-toggle-btn.active { background:#14213D; color:#fff; box-shadow: 0 1px 3px rgba(0,0,0,.15); }
    @media (max-width: 480px) {
        .at-risk-toggle { width:100%; }
        .at-risk-toggle-btn { flex:1; text-align:center; }
    }
    /* ---- Grouped At-Risk view (Grade Level > Subject > shared problem) ---- */
    .arg-filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px 16px; margin-top: 14px; }
    .arg-filter { min-width: 210px; flex: 0 1 260px; }
    .arg-filter label { display: block; margin-bottom: 4px; font-size: 13px; font-weight: 600; color: var(--ink, #14213D); }
    .arg-filter .form-select { font-size: 14px; }
    .arg-summary { margin-left: auto; padding-bottom: 8px; font-size: 13px; color: var(--text-muted, #667085); }
    .arg-wrap { padding: 4px 16px 16px; }
    .arg-grade { margin-top: 14px; border: 1px solid var(--border, #DCE3EC); border-radius: 10px; overflow: hidden; background: var(--surface, #FCFDFE); }
    .arg-grade-head { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 11px 14px; border: 0; background: var(--surface-muted, #F3F6F9); color: var(--ink, #14213D); font-size: 15px; font-weight: 700; text-align: left; cursor: pointer; }
    .arg-grade-head:hover { background: var(--surface-hover, #EEF3F8); }
    .arg-grade-meta { display: inline-flex; align-items: center; gap: 6px; }
    .arg-chev { color: var(--text-muted, #667085); font-size: 12px; margin-left: 4px; transition: transform .18s ease; }
    .arg-grade.is-collapsed .arg-chev { transform: rotate(-90deg); }
    .arg-grade.is-collapsed .arg-grade-body { display: none; }
    .arg-grade-body { padding: 4px 14px 14px; }
    .arg-subject { margin: 14px 0 8px; font-size: 13px; font-weight: 700; letter-spacing: .02em; color: var(--text-muted, #667085); text-transform: uppercase; }
    .arg-card { margin-bottom: 12px; border: 1px solid var(--border, #DCE3EC); border-left: 4px solid var(--danger, #B94A48); border-radius: 10px; background: var(--surface, #fff); }
    .arg-subj-block:first-child .arg-card:first-of-type { margin-top: 12px; }
    .arg-card-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 14px; border-bottom: 1px solid var(--border-soft, #E7ECF2); }
    .arg-card-title { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; min-width: 0; color: var(--ink, #14213D); }
    .arg-card-title i { color: var(--danger, #B94A48); }
    .arg-card-title strong { overflow-wrap: anywhere; }
    .arg-chip { padding: 1px 8px; border-radius: 999px; background: var(--surface-muted, #F3F6F9); border: 1px solid var(--border, #DCE3EC); color: var(--text-muted, #667085); font-size: 11px; font-weight: 600; }
    .arg-ask-btn { flex: 0 0 auto; display: inline-flex; align-items: center; border: 1px solid var(--primary, #5B5FEF); background: var(--primary, #5B5FEF); color: #fff; font-weight: 600; }
    .arg-ask-btn:hover, .arg-ask-btn:focus-visible { filter: brightness(.94); color: #fff; }
    .arg-card-body { padding: 4px 14px 12px; }
    .arg-why { display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 0 4px; }
    .arg-why-chip { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 4px 10px; font-size: 12.5px; font-weight: 600; background: #FDECEC; color: #B42318; }
    .arg-why-chip.is-topic { background: #EEF4FD; color: #1D5FBF; }
    .arg-missing { margin: 8px 0 6px; }
    .arg-missing-head { font-size: 12.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #667085); margin-bottom: 4px; }
    .arg-missing-head small { text-transform: none; letter-spacing: 0; font-weight: 500; }
    .arg-missing ul { list-style: none; padding: 0; margin: 0; display: grid; gap: 5px; }
    .arg-remedy { display: flex; align-items: flex-start; gap: 8px; width: 100%; text-align: left; border: 1px solid var(--border, #DCE3EC); border-radius: 8px; background: var(--surface, #fff); padding: 7px 10px; font-size: 13.5px; color: var(--ink, #14213D); }
    button.arg-remedy:hover { border-color: #5B5FEF; background: #F4F4FF; }
    .arg-remedy i { color: #5B5FEF; margin-top: 3px; }
    .arg-remedy.is-static i { color: var(--text-muted, #667085); }
    .arg-remedy.is-low i { color: #C2410C; }
    .arg-member-list.is-single .arg-member-name { display: none; }
    html[data-theme="dark"] .arg-why-chip { background: rgba(217,45,32,.16); color: #f4a7a0; }
    html[data-theme="dark"] .arg-why-chip.is-topic { background: rgba(47,127,224,.16); color: #8ab4f8; }
    html[data-theme="dark"] button.arg-remedy:hover { background: rgba(91,95,239,.14); }
    .arg-member-list { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); column-gap: 24px; }
    .arg-member { display: flex; align-items: flex-start; gap: 9px; padding: 9px 0; border-bottom: 1px solid var(--border-soft, #E7ECF2); }
    .arg-member > input[type="checkbox"] { margin-top: 4px; flex: 0 0 auto; }
    /* The card's own checkboxes only carry each student's id/subject for the group dialog; picking students happens there. */
    .arg-member > input[type="checkbox"] { display: none; }
    .arg-member-main { flex: 1 1 auto; min-width: 0; }
    .arg-member-name { font-weight: 600; color: var(--ink, #14213D); font-size: 14px; }
    .arg-member-sub { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 2px; font-size: 12px; }
    .arg-badge-grade { background: var(--danger-tint, #FBEDEE) !important; color: var(--danger, #B94A48) !important; }
    .arg-more { color: #916A25; background: rgba(224,168,61,.16); padding: 0 7px; border-radius: 999px; font-weight: 600; cursor: help; }
    .arg-more:focus-visible { outline: 2px solid #916A25; outline-offset: 1px; }
    /* Hover / focus / tap card that lists the other missing items (fixed-position, so no card can clip it). */
    .arg-more-tip { position: fixed; z-index: 2000; display: none; max-width: 300px; min-width: 170px; padding: 9px 12px; border-radius: 9px; background: #14213D; color: #fff; font-size: 12.5px; line-height: 1.4; box-shadow: 0 8px 24px rgba(20,33,61,.28); pointer-events: none; }
    .arg-more-tip.is-open { display: block; }
    .arg-more-tip strong { display: block; margin-bottom: 4px; font-size: 11.5px; letter-spacing: .03em; text-transform: uppercase; color: #F2C86B; }
    .arg-more-tip ul { margin: 0; padding-left: 16px; }
    .arg-more-tip li { margin: 1px 0; overflow-wrap: anywhere; }
    .arg-subj-att { color: var(--text-muted, #667085); font-style: italic; }
    .arg-sent { color: var(--success, #2F7564); font-weight: 600; }
    .arg-fail { color: var(--danger, #B94A48); font-weight: 600; }
    .arg-member-actions { display: inline-flex; gap: 4px; flex: 0 0 auto; }
    .arg-member-actions .ai-action-icon { margin-left: 0; }
    .arg-member-actions .ai-chat-btn { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; }
    .arg-rec { margin-top: 12px; padding: 12px 14px; border: 1px solid var(--border, #DCE3EC); border-radius: 10px; background: var(--surface-muted, #F3F6F9); }
    .arg-ask { display: flex; gap: 8px; margin-bottom: 10px; }
    .arg-ask .arg-note { flex: 1 1 auto; min-width: 0; }
    .arg-ask .arg-gen { flex: 0 0 auto; white-space: nowrap; }
    .arg-rec-note { margin-top: 8px; color: var(--text-muted, #667085); font-size: 13px; }
    .arg-rec-loading { color: var(--text-muted, #667085); font-size: 13px; padding: 6px 0; }
    .arg-rec-title { font-size: 15px; font-weight: 700; color: var(--ink, #14213D); margin-bottom: 4px; }
    .arg-rec-insight { font-size: 13px; color: var(--text-muted, #667085); margin-bottom: 8px; }
    .arg-rec-steps { margin: 0 0 12px 18px; padding: 0; font-size: 13px; color: var(--ink, #14213D); }
    .arg-rec-steps li { margin-bottom: 3px; }
    .arg-msg-label { display: flex; justify-content: space-between; align-items: baseline; font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted, #667085); margin-bottom: 4px; }
    .arg-msg-count { font-weight: 500; text-transform: none; letter-spacing: 0; }
    .arg-msg { width: 100%; min-height: 96px; resize: vertical; font-size: 13px; line-height: 1.45; }
    .arg-source { display: inline-block; margin-top: 6px; font-size: 11px; color: var(--text-muted, #667085); }
    .arg-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 10px; }
    .arg-selectall { font-size: 12px; color: var(--text-muted, #667085); cursor: pointer; display: inline-flex; align-items: center; gap: 6px; margin: 0 6px 0 0; }
    .arg-confirm { margin-top: 10px; padding: 10px 12px; border: 1px solid var(--border, #DCE3EC); border-radius: 8px; background: var(--surface, #fff); font-size: 13px; color: var(--ink, #14213D); }
    .arg-confirm .btn { margin-top: 8px; margin-right: 6px; }
    .arg-result { margin-top: 10px; font-size: 13px; }
    .arg-result.ok { color: var(--success, #2F7564); }
    .arg-result.err { color: var(--danger, #B94A48); }
    /* ---- Group Ask AI dialog ---- */
    #argModal .argm-why, #argModal .argm-rec { border: 1px solid var(--border, #DCE3EC); border-radius: 10px; padding: 10px 12px; margin-bottom: 10px; background: var(--surface, #fff); }
    #argModal .argm-why { border-left: 4px solid #D92D20; }
    #argModal .argm-rec { border-left: 4px solid #1F9D6E; }
    #argModal .argm-why-head { font-size: 12.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--ink, #14213D); margin-bottom: 4px; }
    #argModal .argm-why ul, #argModal .argm-rec ol { margin: 0; padding-left: 20px; font-size: 13.5px; }
    #argModal .argm-items { margin-bottom: 10px; display: grid; gap: 6px; }
    #argModal .argm-item { display: grid; grid-template-columns: 1fr auto; gap: 2px 10px; text-align: left; border: 1px solid var(--border, #DCE3EC); border-radius: 8px; background: var(--surface, #fff); padding: 8px 12px; color: var(--ink, #14213D); }
    #argModal .argm-item span { grid-column: 1; font-size: 12.5px; color: var(--text-muted, #667085); }
    #argModal .argm-item em { grid-column: 2; grid-row: 1 / span 2; align-self: center; font-style: normal; font-size: 12px; font-weight: 600; color: #5B5FEF; }
    #argModal .argm-item:hover, #argModal .argm-item.is-active { border-color: #5B5FEF; background: #F4F4FF; }
    #argModal .argm-summary { margin: 0 0 6px; font-size: 14px; color: var(--ink, #14213D); }
    #argModal .argm-item.is-low em { color: #C2410C; }
    #argModal .argm-hint { font-size: 13.5px; color: var(--text-muted, #667085); padding: 8px 2px; }
    #argModal .argm-preview { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 13px; color: var(--text-muted, #667085); }
    html[data-theme="dark"] #argModal .argm-item:hover, html[data-theme="dark"] #argModal .argm-item.is-active { background: rgba(91,95,239,.14); }
    .argm-intro { padding: 10px 12px; border: 1px solid #cfe0ff; border-radius: 8px; background: #eef4ff; color: #14213D; font-size: 13px; line-height: 1.5; }
    .argm-students { margin-top: 12px; border: 1px solid var(--border, #DCE3EC); border-radius: 10px; overflow: hidden; }
    .argm-students-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 12px; background: var(--surface-muted, #F3F6F9); font-size: 12px; font-weight: 700; color: var(--ink, #14213D); }
    .argm-student { display: flex; align-items: center; gap: 10px; margin: 0; padding: 9px 12px; border-top: 1px solid var(--border-soft, #E7ECF2); cursor: pointer; }
    .argm-student.is-off { cursor: default; opacity: .7; }
    .argm-student-main { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; }
    .argm-student-main b { font-size: 14px; color: var(--ink, #14213D); }
    .argm-student-main small { color: var(--text-muted, #667085); font-size: 12px; }
    .argm-tag { flex: 0 0 auto; font-size: 11px; font-weight: 700; padding: 1px 8px; border-radius: 999px; background: #f1f3f6; color: #667085; }
    .argm-tag.is-sent { background: rgba(47,117,100,.12); color: var(--success, #2F7564); }
    .argm-body { margin-top: 12px; }
    .argm-quiz { padding: 12px 14px; border: 1px solid var(--border, #DCE3EC); border-radius: 10px; background: var(--surface-muted, #F3F6F9); }
    .argm-qlist summary { cursor: pointer; font-size: 13px; font-weight: 600; color: var(--ink, #14213D); }
    .argm-qlist > ol { max-height: 260px; overflow: auto; margin: 8px 0 0; padding-left: 20px; font-size: 13px; }
    .argm-qlist > ol > li { margin-bottom: 8px; }
    .argm-q { font-weight: 600; color: var(--ink, #14213D); }
    .argm-choices { list-style: none; margin: 3px 0 0; padding: 0; color: var(--text-muted, #667085); }
    .argm-choices li.is-correct { color: var(--success, #2F7564); font-weight: 600; }
    .argm-how { margin-top: 10px; padding: 9px 12px; border-radius: 8px; background: #eef4ff; border: 1px solid #cfe0ff; color: #14213D; font-size: 12.5px; line-height: 1.45; }
    .argm-record { margin-top: 10px; padding: 9px 12px; border-radius: 8px; background: #fff8e6; border: 1px solid #f1dfae; color: #6b5314; font-size: 12.5px; line-height: 1.45; }
    .argm-forms { font-size: 12.5px; line-height: 1.6; }
    .argm-forms a { color: var(--primary, #5B5FEF); font-weight: 600; text-decoration: none; }
    .argm-warn { margin-top: 8px; font-size: 12.5px; color: #916A25; }
    .argm-footer .arg-confirm { margin: 0 0 6px; }
    .argm-footer .arg-result { margin: 0; min-height: 0; }
    @media (max-width: 640px) { #argModal .modal-footer { flex-wrap: wrap; } }
    @media (max-width: 640px) {
        .arg-filter { flex: 1 1 100%; }
        .arg-summary { margin-left: 0; padding-bottom: 0; }
        .arg-ask { flex-direction: column; }
        .arg-card-head { flex-wrap: wrap; }
    }
    @media (max-width: 480px) { .arg-wrap { padding: 4px 8px 12px; } .arg-grade-body { padding: 4px 8px 10px; } .arg-member-list { grid-template-columns: 1fr; } }
</style>

<script>
// At-Risk Students: Academic / Attendance tabs, Grade Level + Subject filters,
// students grouped by shared problem, and ONE Ask AI button per group that
// opens a catch-up session for everyone in it (see "Group Ask AI" below).
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('atRiskToggle');
    if (!toggle) return;

    var CSRF = '<?php echo $csrf_token; ?>';
    var REC_URL = '<?php echo $base_url; ?>modules/ai/group_recommendation.php';
    var SEND_URL = '<?php echo $base_url; ?>modules/ai/send_to_student.php';
    var CACHE_PREFIX = 'atRiskRec:v2:';
    var CACHE_TTL_MS = 2 * 60 * 60 * 1000;

    var wraps = {
        academic: document.getElementById('atRiskGroupsAcademic'),
        attendance: document.getElementById('atRiskGroupsAttendance')
    };
    var gradeSel = document.getElementById('argGrade');
    var subjectSel = document.getElementById('argSubject');
    var summaryEl = document.getElementById('argSummary');
    var emptyEl = document.getElementById('argEmpty');
    var filterData = { academic: [], attendance: [] };
    try { filterData = JSON.parse(document.getElementById('argFilterData').textContent) || filterData; } catch (e) {}
    var currentView = 'academic';

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function plural(n, word) { return n + ' ' + word + (n === 1 ? '' : 's'); }
    function gradeNum(g) { var n = parseInt(String(g).replace(/\D/g, ''), 10); return isNaN(n) ? 0 : n; }

    // ---------- Dropdowns ----------
    function fillGrades() {
        var keep = gradeSel.value;
        var grades = (filterData[currentView] || []).map(function (e) { return e.grade; });
        grades.sort(function (a, b) { return gradeNum(a) - gradeNum(b) || String(a).localeCompare(String(b)); });
        gradeSel.innerHTML = '<option value="">All grade levels</option>' + grades.map(function (g) {
            return '<option value="' + esc(g) + '">' + esc(g) + '</option>';
        }).join('');
        gradeSel.value = grades.indexOf(keep) !== -1 ? keep : '';
    }
    function fillSubjects() {
        var keep = subjectSel.value;
        var seen = {}, list = [];
        (filterData[currentView] || []).forEach(function (e) {
            if (gradeSel.value && e.grade !== gradeSel.value) return;
            e.subjects.forEach(function (s) { if (!seen[s.code]) { seen[s.code] = true; list.push(s); } });
        });
        list.sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); });
        subjectSel.innerHTML = '<option value="">All subjects</option>' + list.map(function (s) {
            return '<option value="' + esc(s.code) + '">' + esc(s.name) + '</option>';
        }).join('');
        subjectSel.value = seen[keep] ? keep : '';
    }

    // ---------- Filtering ----------
    function memberSubjects(li) {
        try { return JSON.parse(li.getAttribute('data-att-subjects') || '{}'); } catch (e) { return {}; }
    }
    function applyFilters() {
        var wrap = wraps[currentView];
        var grade = gradeSel.value, subject = subjectSel.value;
        Object.keys(wraps).forEach(function (k) { if (wraps[k]) wraps[k].classList.toggle('d-none', k !== currentView); });
        if (!wrap) return;

        var totalGroups = 0, totalCards = 0, totalStudents = {};
        wrap.querySelectorAll('.arg-grade').forEach(function (sec) {
            var gradeOk = !grade || sec.dataset.grade === grade;
            var secStudents = {}, secGroups = 0, secCards = 0;

            sec.querySelectorAll('.arg-subj-block').forEach(function (block) {
                var blockOk = currentView !== 'academic' || !subject || block.dataset.subjectCode === subject;
                var blockVisible = 0;
                block.querySelectorAll('.arg-card').forEach(function (card) {
                    var visible = 0;
                    card.querySelectorAll('.arg-member').forEach(function (li) {
                        var ok = gradeOk && blockOk;
                        var extra = li.querySelector('.arg-subj-att');
                        if (ok && currentView === 'attendance' && subject) {
                            var subs = memberSubjects(li);
                            ok = !!subs[subject];
                            if (ok && extra) {
                                extra.textContent = 'In ' + subs[subject][2] + ': ' + subs[subject][0] + ' absent · ' + subs[subject][1] + ' late';
                                extra.classList.remove('d-none');
                            }
                        } else if (extra) {
                            extra.classList.add('d-none');
                        }
                        li.classList.toggle('d-none', !ok);
                        if (ok) { visible++; secStudents[li.dataset.sid] = true; totalStudents[li.dataset.sid] = true; }
                    });
                    card.classList.toggle('d-none', visible === 0);
                    var cnt = card.querySelector('.arg-count');
                    if (cnt) cnt.textContent = plural(visible, 'student');
                    if (visible > 0) { blockVisible++; if (card.dataset.single !== '1' && visible > 1) secGroups++; secCards++; }
                });
                block.classList.toggle('d-none', blockVisible === 0);
            });

            sec.classList.toggle('d-none', secCards === 0);
            var bs = sec.querySelector('.arg-grade-students'), bg = sec.querySelector('.arg-grade-groups');
            if (bs) bs.textContent = plural(Object.keys(secStudents).length, 'student');
            if (bg) { bg.textContent = plural(secGroups, 'group'); bg.classList.toggle('d-none', secGroups === 0); }
            totalGroups += secGroups;
            totalCards += secCards;
        });

        var hasSections = wrap.querySelectorAll('.arg-grade').length > 0;
        if (emptyEl) emptyEl.classList.toggle('d-none', !(hasSections && totalCards === 0));
        if (summaryEl) {
            summaryEl.textContent = hasSections
                ? 'Showing ' + plural(Object.keys(totalStudents).length, 'student') + (totalGroups ? ' · ' + plural(totalGroups, 'group') : '')
                : '';
        }
    }

    toggle.querySelectorAll('.at-risk-toggle-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            toggle.querySelectorAll('.at-risk-toggle-btn').forEach(function (b) {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');
            currentView = this.dataset.view;
            fillGrades(); fillSubjects(); applyFilters();
        });
    });
    gradeSel.addEventListener('change', function () { fillSubjects(); applyFilters(); });
    subjectSel.addEventListener('change', applyFilters);

    // Grade level collapse
    document.addEventListener('click', function (e) {
        var head = e.target.closest ? e.target.closest('.arg-grade-head') : null;
        if (!head) return;
        var collapsed = head.parentNode.classList.toggle('is-collapsed');
        head.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    });

    // "+N more missing": hover (or keyboard focus / tap) shows WHICH items are still missing.
    (function () {
        var tip = document.createElement('div');
        tip.className = 'arg-more-tip';
        tip.setAttribute('role', 'tooltip');
        document.body.appendChild(tip);
        var current = null;
        function items(el) {
            try { var v = JSON.parse(el.getAttribute('data-items') || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; }
        }
        function show(el) {
            var list = items(el);
            if (!list.length) return;
            current = el;
            tip.innerHTML = '';
            var h = document.createElement('strong'); h.textContent = 'Also missing:'; tip.appendChild(h);
            var ul = document.createElement('ul');
            list.forEach(function (t) { var li = document.createElement('li'); li.textContent = t; ul.appendChild(li); });
            tip.appendChild(ul);
            tip.classList.add('is-open');
            var r = el.getBoundingClientRect(), tw = tip.offsetWidth, th = tip.offsetHeight;
            var left = Math.min(Math.max(8, r.left), window.innerWidth - tw - 8);
            var top = r.bottom + 6;
            if (top + th > window.innerHeight - 8 && r.top - th - 6 > 8) top = r.top - th - 6;
            tip.style.left = left + 'px'; tip.style.top = top + 'px';
        }
        function hide() { current = null; tip.classList.remove('is-open'); }
        function target(e) { return e.target && e.target.closest ? e.target.closest('.arg-more') : null; }
        document.addEventListener('mouseover', function (e) { var el = target(e); if (el) show(el); });
        document.addEventListener('mouseout', function (e) { if (target(e)) hide(); });
        document.addEventListener('focusin', function (e) { var el = target(e); if (el) show(el); });
        document.addEventListener('focusout', function (e) { if (target(e)) hide(); });
        document.addEventListener('click', function (e) {          // tap on touch screens
            var el = target(e);
            if (el) { if (current === el) hide(); else show(el); } else if (current) hide();
        });
        window.addEventListener('scroll', function () { if (current) hide(); }, true);
    })();

    // ---------- Group Ask AI: catch-up session dialog ----------
    // The group's Ask AI button opens ONE dialog for the whole group (everyone
    // who shares the same problem, together):
    //   - a group that is missing a Written/Oral Works item or an Examination:
    //     the AI writes ONE make-up quiz. Sending it puts a private copy in each
    //     selected student's portal, linked to the item they are missing, so the
    //     actual score (e.g. 5/10) reaches Manage Assessment Items and Grade
    //     Management on submit (modules/ai/catchup.php);
    //   - any other group (Performance Task, incomplete term, below passing,
    //     attendance): the AI prepares one plan + message, sent through
    //     modules/ai/send_to_student.php one student per request.
    // The server re-validates teacher, subject, students and the item every time.
    var CATCHUP_URL = '<?php echo $base_url; ?>modules/ai/catchup.php';
    var REMEDY_URL = '<?php echo $base_url; ?>modules/ai/remediation.php';
    var MAKEUP_PCT = <?php require_once __DIR__ . '/../../includes/ai_quiz_grade_sync.php'; echo (int) aiMakeupCreditPercent(); ?>;
    var QUIZ_CATEGORIES = ['Written/Oral Works', 'Examinations'];
    var mEl = document.getElementById('argModal');
    var mTitle = document.getElementById('argModalTitle');
    var mSub = document.getElementById('argModalSub');
    var mIntro = document.getElementById('argModalIntro');
    var mStudents = document.getElementById('argModalStudents');
    var mNote = document.getElementById('argModalNote');
    var mGen = document.getElementById('argModalGen');
    var mBody = document.getElementById('argModalBody');
    var mConfirm = document.getElementById('argModalConfirm');
    var mResult = document.getElementById('argModalResult');
    var mSend = document.getElementById('argModalSend');
    var modalInst = null;
    var G = null; // { card, state } of the group open in the dialog

    function getModal() {
        if (!modalInst && mEl && window.bootstrap) modalInst = window.bootstrap.Modal.getOrCreateInstance(mEl);
        return modalInst;
    }
    function itemCategory(label) {
        var m = /\(([^()]+)\)\s*$/.exec(label || '');
        return m ? m[1].trim() : '';
    }
    function itemName(label) { return String(label || '').replace(/\s*\([^()]*\)\s*$/, ''); }
    function visibleMembers(card) {
        return Array.prototype.slice.call(card.querySelectorAll('.arg-member:not(.d-none)'));
    }
    function recIds(card) {
        var out = [];
        visibleMembers(card).forEach(function (li) {
            if (card.dataset.kind === 'attendance' || li.dataset.mine === '1') out.push(parseInt(li.dataset.sid, 10));
        });
        return out;
    }
    function cacheKey(card, note) {
        var d = card.dataset;
        return CACHE_PREFIX + [d.kind, d.concern, d.grade, d.subjectCode, d.term, d.item, recIds(card).join(','), note].join('|');
    }
    function readCache(key) {
        try {
            var o = JSON.parse(sessionStorage.getItem(key) || 'null');
            return o && o.t && Date.now() - o.t <= CACHE_TTL_MS ? o.data : null;
        } catch (e) { return null; }
    }
    function writeCache(key, data) {
        try { sessionStorage.setItem(key, JSON.stringify({ t: Date.now(), data: data })); } catch (e) {}
    }
    function liFor(card, sid) { return card.querySelector('.arg-member[data-sid="' + sid + '"]'); }
    function markSent(card, sid) {
        var li = liFor(card, sid);
        if (!li) return;
        var sent = li.querySelector('.arg-sent'), fail = li.querySelector('.arg-fail');
        if (sent) sent.classList.remove('d-none');
        if (fail) fail.classList.add('d-none');
    }

    function groupModeOf(card) {
        var d = card.dataset;
        return d.kind === 'missing' && QUIZ_CATEGORIES.indexOf(itemCategory(d.item)) !== -1 ? 'catchup' : 'message';
    }
    function pickedChecks() {
        return Array.prototype.slice.call(mStudents.querySelectorAll('.argm-check:checked:not(:disabled)'));
    }
    function allChecks() {
        return Array.prototype.slice.call(mStudents.querySelectorAll('.argm-check:not(:disabled)'));
    }
    function setResult(cls, html) {
        mResult.className = 'arg-result ' + cls;
        mResult.innerHTML = html;
    }
    function hideConfirm() { mConfirm.classList.add('d-none'); mConfirm.innerHTML = ''; }

    function renderStudents() {
        var card = G.card, state = G.state;
        var rows = visibleMembers(card).filter(function (li) {
            return card.dataset.kind === 'attendance' || li.dataset.mine === '1';
        }).map(function (li) {
            var chk = li.querySelector('.arg-check');
            var sid = parseInt(li.dataset.sid, 10);
            var sent = li.querySelector('.arg-sent') && !li.querySelector('.arg-sent').classList.contains('d-none');
            var off = state.off && state.off[sid];
            var name = (li.querySelector('.arg-member-name') || {}).textContent || 'Student';
            var lrn = (li.querySelector('.arg-member-sub .text-muted') || {}).textContent || '';
            var tag = sent ? '<span class="argm-tag is-sent"><i class="fas fa-check-circle"></i> Sent</span>'
                : (off ? '<span class="argm-tag is-off" title="' + esc(off) + '">Not eligible</span>' : '');
            return '<label class="argm-student' + (sent || off ? ' is-off' : '') + '">' +
                '<input type="checkbox" class="argm-check" value="' + sid + '" data-subject="' + esc(chk ? chk.dataset.subject : '') + '"' +
                (sent || off ? ' disabled' : ' checked') + '>' +
                '<span class="argm-student-main"><b>' + esc(name) + '</b><small>' + esc(lrn) + '</small></span>' + tag + '</label>';
        });
        var single = rows.length === 1;
        mStudents.innerHTML =
            '<div class="argm-students-head"><span><i class="fas ' + (single ? 'fa-user' : 'fa-users') + ' me-1"></i>' +
            (single ? 'Send to' : plural(rows.length, 'student') + ' in this group') + '</span>' +
            (single ? '' : '<label class="arg-selectall mb-0"><input type="checkbox" class="argm-all"> Select all</label>') + '</div>' + rows.join('');
        syncStudentControls();
    }
    function syncStudentControls() {
        var all = allChecks(), n = pickedChecks().length;
        var master = mStudents.querySelector('.argm-all');
        if (master) { master.checked = all.length > 0 && n === all.length; master.disabled = all.length === 0; }
        updateSend();
    }
    function updateSend() {
        if (!G) return;
        var n = pickedChecks().length, st = G.state;
        var isForm = st.mode === 'catchup' && st.gen && st.gen.delivery === 'gform';
        var label = st.mode === 'catchup' ? (isForm ? 'Create Google Forms &amp; Send to ' : 'Send Quiz to ')
            : (st.mode === 'activity' ? 'Send Activity to ' : (st.mode === 'task' ? 'Send Task to ' : 'Send Message to '));
        mSend.disabled = n === 0 || !st.gen || st.busy === true || st.mode === 'overview';
        mSend.innerHTML = '<i class="fas ' + (isForm ? 'fa-file-circle-plus' : 'fa-paper-plane') + ' me-1"></i>' + label + plural(n, 'Student');
    }

    // ---------- Academic At-Risk card: overview first, make-up per missing item ----------
    function jsonData(card, key) {
        try { return JSON.parse(card.getAttribute('data-' + key) || '[]') || []; } catch (e) { return []; }
    }
    function remedyKindOf(m) {
        if (m.kind === 'quiz' || m.kind === 'exam') return 'quiz';
        if (m.kind === 'performance_task') return 'task';
        return 'activity';
    }
    var REMEDY_TEXT = {
        quiz: 'a make-up quiz (Google Form)',
        activity: 'a make-up activity (PDF + Word)',
        task: 'make-up performance task instructions with a rubric'
    };
    function overviewHtml(card) {
        var reasons = jsonData(card, 'reasons'), missing = jsonData(card, 'missing'), topics = jsonData(card, 'topics'), low = jsonData(card, 'low');
        var single = card.dataset.single === '1';
        var who = single ? ((card.querySelector('.arg-card-title strong') || {}).textContent || 'This student') : 'These students';
        var gradeR = reasons.filter(function (r) { return /^(Current Grade|Below passing)/.test(r); })[0];
        // Plain summary first: who, what is missing / low, on which topic.
        var summary = esc(who) + (gradeR ? ' (' + esc(gradeR) + ')' : '') + ' ' + (single ? 'is' : 'are') + ' At-Risk in ' + esc(card.dataset.subjectName || 'this subject') + ', ' + esc(card.dataset.term || '') + '.';
        if (missing.length) summary += ' Missing: ' + missing.map(function (m) { return '<b>' + esc(m.name) + '</b>' + (m.topic ? ' (' + esc(m.topic) + ')' : ''); }).join(', ') + '.';
        if (low.length) summary += ' Low scores: ' + low.map(function (m) { return '<b>' + esc(m.name) + '</b>' + (single ? ' ' + esc(m.score) : '') + (m.topic ? ' (' + esc(m.topic) + ')' : ''); }).join(', ') + '.';
        var why = '<div class="argm-why"><div class="argm-why-head"><i class="fas fa-circle-exclamation me-1"></i>Why ' +
            (single ? 'this student is' : 'these students are') + ' At-Risk</div><p class="argm-summary">' + summary + '</p><ul>' +
            reasons.map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') +
            (topics.length ? '<li>Weak Topic: ' + esc(topics.join(', ')) + '</li>' : '') + '</ul></div>';
        var recs = missing.map(function (m) {
            return 'Give ' + REMEDY_TEXT[remedyKindOf(m)] + ' for <b>' + esc(m.name) + '</b>' + (m.topic ? ' on ' + esc(m.topic) : '') + '.';
        });
        low.forEach(function (m) {
            recs.push('Re-teach ' + (m.topic ? '<b>' + esc(m.topic) + '</b>' : 'the lesson of <b>' + esc(m.name) + '</b>') + ' and give a review activity (PDF + Word) — ' +
                esc(m.name) + (single ? ' was ' + esc(m.score) : ' was low') + '.');
        });
        if (missing.length) recs.push('Make-up scores count at <b>' + MAKEUP_PCT + '% of the points</b> (at-risk make-up), and are added to the grade automatically once scored.');
        if (gradeR) recs.push('After the make-ups are scored, re-finalize the term so the Term Grade reflects the new scores.');
        var html = why + '<div class="argm-rec"><div class="argm-why-head"><i class="fas fa-list-check me-1"></i>Recommended Action</div><ol>' +
            recs.map(function (x) { return '<li>' + x + '</li>'; }).join('') + '</ol></div>';
        if (missing.length) {
            html += '<div class="argm-items"><div class="argm-why-head">Prepare a make-up — pick the missing assessment</div>' +
                missing.map(function (m) {
                    return '<button type="button" class="argm-item" data-item-id="' + m.item_id + '">' +
                        '<b>' + esc(m.name + (m.component ? ' (' + m.component + ')' : '')) + '</b><span>' + esc(m.category_label + ' · ' + m.mode + (m.topic ? ' · ' + m.topic : '')) + '</span>' +
                        '<em>' + esc(REMEDY_TEXT[remedyKindOf(m)]) + '</em></button>';
                }).join('') + '</div>';
        }
        if (low.length) {
            html += '<div class="argm-items"><div class="argm-why-head">Review a low-scored topic</div>' +
                low.map(function (m) {
                    return '<button type="button" class="argm-item is-low" data-low-id="' + m.item_id + '">' +
                        '<b>' + esc(m.name) + (single ? ' — ' + esc(m.score) : '') + '</b><span>' + esc(m.category_label + (m.topic ? ' · ' + m.topic : '')) + '</span>' +
                        '<em>a review activity (PDF + Word) · not graded</em></button>';
                }).join('') + '</div>';
        }
        return html;
    }
    function openRisk(card, itemId, practice) {
        var d = card.dataset;
        var state = card._g || (card._g = { mode: 'overview', gen: null, msg: '', note: '', off: {}, busy: false, token: 0, item: null });
        G = { card: card, state: state };
        var title = (card.querySelector('.arg-card-title strong') || {}).textContent || '';
        mTitle.textContent = 'Ask AI';
        mSub.textContent = [title, d.subjectName, d.grade, d.term].filter(Boolean).join(' · ');
        mIntro.innerHTML = overviewHtml(card);
        mNote.placeholder = 'Optional: tell the AI what you want, e.g. 10 items, easier, or in Filipino';
        mNote.value = state.note || '';
        mGen.innerHTML = '<i class="fas fa-wand-magic-sparkles me-1"></i>' + (state.item ? 'Regenerate' : 'General plan');
        hideConfirm();
        setResult('', '');
        renderStudents();
        var m = getModal();
        if (m) m.show();
        if (itemId) { startItem(itemId, practice); return; }
        if (state.gen) { renderResult(); }
        else {
            mBody.innerHTML = '<div class="argm-hint"><i class="fas fa-hand-pointer me-1"></i>Pick a missing assessment above to prepare its make-up, ' +
                'or press <b>General plan</b> for a review plan and message. Nothing is generated until you choose.</div>';
        }
        updateSend();
    }
    function startItem(itemId, practice) {
        var list = jsonData(G.card, practice ? 'low' : 'missing');
        var m = null;
        list.forEach(function (x) { if (String(x.item_id) === String(itemId)) m = x; });
        if (!m) return;
        var state = G.state;
        state.item = m;
        state.practice = !!practice;
        state.mode = practice ? 'activity' : (remedyKindOf(m) === 'quiz' ? 'catchup' : remedyKindOf(m));
        state.gen = null;
        mIntro.querySelectorAll('.argm-item').forEach(function (b) {
            b.classList.toggle('is-active', String(practice ? b.dataset.lowId : b.dataset.itemId) === String(itemId));
        });
        mGen.innerHTML = '<i class="fas fa-wand-magic-sparkles me-1"></i>Regenerate';
        generateItem();
    }
    function generateItem() {
        var card = G.card, state = G.state, m = state.item;
        var note = mNote.value.trim();
        state.note = note;
        var ids = recIds(card);
        if (!ids.length) { mBody.innerHTML = '<div class="arg-result err">No student in view.</div>'; return; }
        hideConfirm();
        setResult('', '');
        state.token += 1;
        var token = state.token;
        state.busy = true;
        mGen.disabled = true;
        state.gen = null;
        updateSend();
        mBody.innerHTML = '<div class="arg-rec-loading"><span class="spinner-border spinner-border-sm me-2" role="status"></span>Preparing ' +
            (state.practice ? 'a review activity' : esc(REMEDY_TEXT[remedyKindOf(m)])) + ' for ' + esc(m.name) + (m.topic ? ' (' + esc(m.topic) + ')' : '') + '…</div>';
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', 'generate');
        fd.append('item_id', m.item_id);
        if (state.practice) fd.append('purpose', 'practice');
        fd.append('teacher_note', note);
        fd.append('student_ids', JSON.stringify(ids));
        fetch(REMEDY_URL, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (token !== state.token) return;
                if (!res || !res.success) throw new Error((res && res.error) || 'Could not prepare the make-up.');
                // A quiz reuses the catch-up quiz send (linked to the item by its label).
                state.gen = res;
                state.msg = res.message || '';
                state.off = {};
                ids.forEach(function (sid) {
                    if (res.eligible_ids.indexOf(sid) === -1) state.off[sid] = (res.skipped && res.skipped[sid]) || 'Cannot receive this make-up.';
                });
                renderStudents();
                renderResult();
            })
            .catch(function (err) {
                if (token !== state.token) return;
                mBody.innerHTML = '<div class="arg-result err"><i class="fas fa-circle-exclamation me-1"></i>' + esc(err.message) + '</div>';
            })
            .then(function () {
                if (token !== state.token) return;
                state.busy = false;
                mGen.disabled = false;
                updateSend();
            });
    }

    function openGroup(card) {
        if (card.dataset.kind === 'risk') { openRisk(card, null); return; }
        mGen.innerHTML = '<i class="fas fa-wand-magic-sparkles me-1"></i>Generate';
        var d = card.dataset;
        var state = card._g || (card._g = { mode: groupModeOf(card), gen: null, msg: '', note: '', off: {}, busy: false, token: 0 });
        G = { card: card, state: state };

        var title = (card.querySelector('.arg-card-title strong') || {}).textContent || 'Group';
        mTitle.textContent = 'Ask AI';
        mSub.textContent = [title, d.subjectName, d.grade, d.term].filter(Boolean).join(' · ');
        var n = recIds(card).length;
        if (state.mode === 'catchup') {
            mIntro.innerHTML = '<i class="fas fa-file-circle-check me-2"></i>These <b>' + plural(n, 'student') + '</b> are missing the same task, so Ask AI writes <b>one catch-up quiz</b> for all of them. ' +
                'When you send it, it appears in each student\'s Student Portal and their <b>actual score</b> (for example 5/10) is saved to <b>' + esc(itemName(d.item)) + '</b> in Manage Assessment Items, ' +
                'so Grade Management updates by itself. One attempt only unless you allow a retake.';
            mNote.placeholder = 'Optional: tell the AI what you want, e.g. the topic, 15 items, easier, or in Filipino';
        } else {
            var isPT = d.kind === 'missing';
            mIntro.innerHTML = '<i class="fas fa-lightbulb me-2"></i>' + (isPT
                ? 'Performance Tasks are scored by you, so Ask AI prepares <b>one catch-up plan and one message</b> for the ' + plural(n, 'student') + ' in this group.'
                : 'Ask AI prepares <b>one plan and one message</b> for all ' + plural(n, 'student') + ' in this group. The message goes to each selected student\'s portal.');
            mNote.placeholder = 'Optional: tell the AI what you want, e.g. a 10-item quiz, easier, or in Filipino';
        }
        mNote.value = state.note || '';
        hideConfirm();
        setResult('', '');
        renderStudents();
        if (state.gen) {
            renderResult();
        } else {
            mBody.innerHTML = '';
            var m = getModal();
            if (m) m.show();
            generate(false);
            return;
        }
        var m2 = getModal();
        if (m2) m2.show();
    }

    function generate(force) {
        if (!G) return;
        if (G.card.dataset.kind === 'risk') {
            if (G.state.item) { generateItem(); return; }
            G.state.mode = 'message';   // "General plan": one review plan + message
        }
        var card = G.card, state = G.state, d = card.dataset;
        var note = mNote.value.trim();
        state.note = note;
        var ids = recIds(card);
        if (!ids.length) {
            mBody.innerHTML = '<div class="arg-result err">There are no students in view for this group.</div>';
            return;
        }
        hideConfirm();
        setResult('', '');
        state.token += 1;
        var token = state.token;
        state.busy = true;
        mGen.disabled = true;
        state.gen = null;
        updateSend();
        mBody.innerHTML = '<div class="arg-rec-loading"><span class="spinner-border spinner-border-sm me-2" role="status"></span>' +
            (state.mode === 'catchup' ? 'Writing a catch-up quiz for ' : 'Preparing a recommendation for ') + plural(ids.length, 'student') + '…</div>';

        function done() {
            if (token !== state.token) return;
            state.busy = false;
            mGen.disabled = false;
            updateSend();
        }
        function fail(msg) {
            if (token !== state.token) return;
            mBody.innerHTML = '<div class="arg-result err"><i class="fas fa-circle-exclamation me-1"></i>' + esc(msg || 'Could not prepare this. Please try again.') + '</div>';
        }
        function base(fd) {
            fd.append('csrf_token', CSRF);
            fd.append('subject_code', d.subjectCode || '');
            fd.append('term', d.term || '');
            fd.append('item', d.item || '');
            fd.append('teacher_note', note);
            fd.append('student_ids', JSON.stringify(ids));
            return fd;
        }

        if (state.mode === 'catchup') {
            var fd = base(new FormData());
            fd.append('action', 'generate');
            fetch(CATCHUP_URL, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (token !== state.token) return;
                    if (!res || !res.success) throw new Error((res && res.error) || 'Could not write the quiz.');
                    state.gen = res;
                    state.msg = res.message || '';
                    state.off = {};
                    ids.forEach(function (sid) {
                        if (res.eligible_ids.indexOf(sid) === -1) state.off[sid] = (res.skipped && res.skipped[sid]) || 'Cannot receive this quiz.';
                    });
                    renderStudents();
                    renderResult();
                })
                .catch(function (err) { fail(err.message); })
                .then(done);
            return;
        }

        var key = cacheKey(card, note);
        if (!force) {
            var cached = readCache(key);
            if (cached) { state.gen = cached; state.msg = (cached.recommendation || {}).student_message || ''; renderResult(); done(); return; }
        }
        var fd2 = base(new FormData());
        var recKind = d.kind;
        if (d.kind === 'risk') {
            // Ground the plan in this card's exact reasons, missing items and weak topics.
            var rs = jsonData(card, 'reasons'), ms = jsonData(card, 'missing'), ts = jsonData(card, 'topics'), ls = jsonData(card, 'low');
            // "incomplete" (missing work) only when that is the actual reason; low scores / low grade are "below".
            recKind = (ms.length && !rs.some(function (r) { return /^(Current Grade|Below passing|Low )/.test(r); })) ? 'incomplete' : 'below';
            var ctx = 'Focus on: ' + rs.join('; ') + (ms.length ? '; missing ' + ms.map(function (m) { return m.name; }).join(', ') : '') +
                (ls.length ? '; low scores in ' + ls.map(function (m) { return m.name + (m.topic ? ' (' + m.topic + ')' : ''); }).join(', ') : '') +
                (ts.length ? '; weak topic ' + ts.join(', ') : '') + '.';
            fd2.set('teacher_note', (ctx + (note ? ' ' + note : '')).slice(0, 300));
        }
        fd2.append('kind', recKind);
        fd2.append('concern', d.concern || '');
        fd2.append('grade_level', d.grade || '');
        fetch(REC_URL, { method: 'POST', body: fd2 })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (token !== state.token) return;
                if (!res || !res.success) throw new Error((res && res.error) || 'Could not prepare a recommendation.');
                writeCache(key, res);
                state.gen = res;
                state.msg = (res.recommendation || {}).student_message || '';
                renderResult();
            })
            .catch(function (err) { fail(err.message); })
            .then(done);
    }

    function renderResult() {
        var state = G.state, res = state.gen;
        if (!res) return;
        var html = '';
        if (state.mode === 'activity' || state.mode === 'task') {
            var rc = res.record || {};
            var isTask = state.mode === 'task';
            html += '<div class="argm-how"><i class="fas fa-user-graduate me-2"></i><b>How the student gets it:</b> after you press Send, ' +
                (isTask ? 'the task is sent as a <b>PDF</b>' : 'the activity is sent as a <b>PDF and a Word file</b>') +
                ' to each selected student\'s portal, with your message. ' + (res.practice
                    ? 'It is a <b>review</b> of the topic of <b>' + esc(rc.item_name) + '</b> — practice only, not graded.'
                    : 'It replaces <b>' + esc(rc.item_name) + '</b> (' + esc(rc.category_label) + ', ' + esc(rc.term) + ', ' + esc(rc.hps) + ' pts)' +
                      (isTask ? '; you score it yourself in Manage Assessment Items (at-risk make-up: ' + MAKEUP_PCT + '% of the points).'
                              : '; when you confirm their submitted score it is recorded to that item at <b>' + MAKEUP_PCT + '% of the points</b> (at-risk make-up).')) +
                (res.lesson_used ? ' Based on the lesson material attached to it' : ' Based on the topic') + (rc.topic ? ' (' + esc(rc.topic) + ')' : '') + '.</div>' +
                '<label class="arg-msg-label"><span>Title</span></label><input type="text" class="form-control argm-doc-title" maxlength="150">' +
                '<label class="arg-msg-label mt-2"><span>' + (isTask ? 'Task instructions &amp; rubric' : 'Activity') + ' (review and edit)</span></label>' +
                '<textarea class="form-control argm-doc-body" rows="12"></textarea>' +
                '<details class="argm-qlist mt-2"><summary>' + (isTask ? 'Rubric notes for you' : 'Answer key (for you only)') + '</summary>' +
                '<textarea class="form-control argm-doc-key mt-1" rows="6"></textarea></details>' +
                '<div class="argm-preview mt-2"><span>Preview the file first:</span>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary argm-prev" data-format="pdf"><i class="fas fa-file-pdf me-1"></i>PDF</button>' +
                (isTask ? '' : '<button type="button" class="btn btn-sm btn-outline-secondary argm-prev" data-format="docx"><i class="fas fa-file-word me-1"></i>Word</button>') +
                '<span class="argm-prev-status"></span></div>' +
                '<label class="arg-msg-label mt-3"><span>Message to student</span><span class="arg-msg-count"></span></label>' +
                '<textarea class="form-control arg-msg" maxlength="2000" rows="4"></textarea>' +
                '<span class="arg-source"><i class="fas fa-wand-magic-sparkles me-1"></i>Prepared by AI for ' + esc(rc.item_name) + '. Review and edit before sending.</span>';
            mBody.innerHTML = html;
            mBody.querySelector('.argm-doc-title').value = res.title || '';
            mBody.querySelector('.argm-doc-body').value = res.student_content || '';
            mBody.querySelector('.argm-doc-key').value = res.teacher_answer_key || '';
            mBody.querySelector('.arg-msg').value = state.msg || '';
            updateMsgCount();
            updateSend();
            return;
        }
        if (state.mode === 'catchup') {
            var qs = res.questions || [];
            var rec = res.record || {};
            html += '<div class="argm-how"><i class="fas fa-user-graduate me-2"></i><b>How students take it:</b> ' + (res.delivery === 'gform'
                    ? 'after you press Send, <b>each student gets their own Google Form</b> of this quiz. It shows in their Student Portal under ' + esc(G.card.dataset.subjectName || 'the subject') +
                      ' as <b>Open Quiz</b>. They can answer it only once (the form closes after they submit) and the score is read from Google automatically.'
                    : 'Google Forms is not connected yet, so after you press Send the quiz appears in their Student Portal under ' + esc(G.card.dataset.subjectName || 'the subject') +
                      ' with an <b>Answer Now</b> button. They answer it right there, one attempt only, and it is checked automatically.') + '</div>' +
                '<div class="argm-quiz">' +
                '<div class="arg-rec-title">' + esc(res.title) + '</div>' +
                '<div class="arg-rec-insight">' + plural(qs.length, 'question') + ' · ' + res.total_points + ' point' + (res.total_points === 1 ? '' : 's') + ' · multiple choice · checked automatically</div>' +
                '<details class="argm-qlist" open><summary>Review the questions</summary><ol>' + qs.map(function (q) {
                    return '<li><div class="argm-q">' + esc(q.question) + '</div><ul class="argm-choices">' + q.choices.map(function (c, i) {
                        return '<li class="' + (i === q.correct_index ? 'is-correct' : '') + '">' + (i === q.correct_index ? '<i class="fas fa-check me-1"></i>' : '') + esc(c) + '</li>';
                    }).join('') + '</ul></li>';
                }).join('') + '</ol></details></div>' +
                '<div class="argm-record"><i class="fas fa-table-list me-2"></i>The actual score goes to <b>' + esc(rec.item_name) + '</b> (' + esc(rec.category_label) + ', ' + esc(rec.term) + ', ' +
                esc(rec.hps) + ' pts)' + (Number(rec.hps) !== Number(res.total_points) ? ', scaled from the quiz total of ' + res.total_points : '') +
                ' as soon as the student submits. Nothing is sent until you press Send.</div>';
            var skipped = Object.keys(state.off || {});
            if (skipped.length) {
                html += '<div class="argm-warn"><i class="fas fa-circle-info me-1"></i>Not included: ' + skipped.map(function (sid) {
                    var li = liFor(G.card, sid);
                    return esc(((li && li.querySelector('.arg-member-name')) || {}).textContent || 'Student') + ' (' + esc(state.off[sid]) + ')';
                }).join('; ') + '</div>';
            }
            html += '<label class="arg-msg-label mt-3"><span>Message to students</span><span class="arg-msg-count"></span></label>' +
                '<textarea class="form-control arg-msg" maxlength="500" rows="4"></textarea>' +
                '<span class="arg-source"><i class="fas fa-wand-magic-sparkles me-1"></i>Prepared by AI for the whole group. Review and edit before sending.</span>';
        } else {
            var r = res.recommendation || {};
            var steps = (r.steps || []).map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('');
            html += '<div class="arg-rec-title">' + esc(r.title) + '</div>' +
                '<div class="arg-rec-insight">' + esc(r.insight) + '</div>' +
                '<div class="arg-msg-label"><span>Recommended plan</span></div>' +
                '<ol class="arg-rec-steps">' + steps + '</ol>' +
                '<label class="arg-msg-label"><span>Message to students</span><span class="arg-msg-count"></span></label>' +
                '<textarea class="form-control arg-msg" maxlength="2000" rows="5"></textarea>' +
                '<span class="arg-source">' + (res.source === 'ai'
                    ? '<i class="fas fa-wand-magic-sparkles me-1"></i>Prepared by AI for the whole group. Review and edit before sending.'
                    : '<i class="fas fa-circle-info me-1"></i>Standard recommendation (the AI was not available). Review and edit before sending.') + '</span>';
        }
        mBody.innerHTML = html;
        var ta = mBody.querySelector('.arg-msg');
        ta.value = state.msg || '';
        updateMsgCount();
        updateSend();
    }
    function updateMsgCount() {
        var ta = mBody.querySelector('.arg-msg'), c = mBody.querySelector('.arg-msg-count');
        if (ta && c) c.textContent = ta.value.length + ' / ' + ta.maxLength;
    }

    function askConfirm() {
        if (!G) return;
        var ta = mBody.querySelector('.arg-msg');
        var picked = pickedChecks();
        if (!ta || !picked.length) return;
        var msg = ta.value.trim();
        if (G.state.mode !== 'catchup' && !msg) { setResult('err', 'The message is empty.'); return; }
        setResult('', '');
        mConfirm.classList.remove('d-none');
        var isForm = G.state.mode === 'catchup' && G.state.gen && G.state.gen.delivery === 'gform';
        mConfirm.innerHTML = (G.state.mode === 'activity' || G.state.mode === 'task'
            ? 'Create the ' + (G.state.mode === 'task' ? 'PDF' : 'PDF and Word files') + ' and send them to <strong>' + plural(picked.length, 'student') + '</strong> now? They will see it in their portal.'
            : G.state.mode === 'catchup'
            ? (isForm
                ? 'Create a Google Form for each of the <strong>' + plural(picked.length, 'student') + '</strong> and send it now? Each student gets their own form in the Student Portal (<strong>Open Quiz</strong>) and can answer it <strong>once</strong>. This can take a few seconds per student.'
                : 'Send this quiz to <strong>' + plural(picked.length, 'student') + '</strong> now? It appears in their Student Portal and can be answered <strong>once</strong>.')
            : 'Send this message to <strong>' + plural(picked.length, 'student') + '</strong> now? They will see it in their portal.') +
            '<br><button type="button" class="btn btn-sm btn-primary argm-confirm-yes">Yes, send</button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary argm-confirm-cancel">Cancel</button>';
    }

    function sendOne(check, message, draft) {
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('student_id', check.value);
        fd.append('subject_code', check.dataset.subject || '');
        fd.append('message', message);
        fd.append('ai_draft', draft || '');
        fd.append('source', 'ai');
        return fetch(SEND_URL, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) { return d && d.success ? { ok: true } : { ok: false, error: (d && d.error) || 'Could not send.' }; })
            .catch(function () { return { ok: false, error: 'Network error. Please try again.' }; });
    }

    // ---- Make-up activity / performance task: build the file(s) per student, then send ----
    var RES_URL = '<?php echo $base_url; ?>modules/ai/generate_resource.php';
    function docFields() {
        var st = G.state, rc = (st.gen && st.gen.record) || {};
        return {
            title: ((mBody.querySelector('.argm-doc-title') || {}).value || st.gen.title || '').trim(),
            body: ((mBody.querySelector('.argm-doc-body') || {}).value || '').trim(),
            key: ((mBody.querySelector('.argm-doc-key') || {}).value || '').trim(),
            record: rc,
            type: st.gen.resource_type || 'remedial_activity'
        };
    }
    function buildFile(sid, subject, f, format) {
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('student_id', sid);
        fd.append('subject_code', subject);
        fd.append('resource_type', f.type);
        fd.append('title', f.title);
        fd.append('student_content', f.body);
        fd.append('teacher_answer_key', f.key);
        fd.append('output_format', format);
        fd.append('source_prompt', (G.state.practice ? 'Review activity for ' : 'Make-up for missing ') + (f.record.item_name || ''));
        if (f.record.category && f.record.category !== 'PT' && !G.state.practice) {   // Performance Tasks stay teacher-scored; a review is practice only
            fd.append('assessment_item_id', f.record.item_id);
            fd.append('assessment_term', f.record.term || '');
        }
        return fetch(RES_URL, { method: 'POST', body: fd }).then(function (r) { return r.json(); })
            .then(function (d) { if (!d || !d.success) throw new Error((d && d.error) || 'Could not create the file.'); return d; });
    }
    function sendFile(sid, subject, message, resourceId) {
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('student_id', sid);
        fd.append('subject_code', subject);
        fd.append('message', message);
        fd.append('ai_draft', (G.state.gen && G.state.gen.message) || '');
        fd.append('source', 'ai');
        fd.append('resource_id', resourceId);
        return fetch(SEND_URL, { method: 'POST', body: fd }).then(function (r) { return r.json(); })
            .then(function (d) { if (!d || !d.success) throw new Error((d && d.error) || 'Could not send.'); return d; });
    }
    function sendDocs(card, state, checks, message, finish) {
        var f = docFields();
        if (!f.body) { setResult('err', 'The ' + (state.mode === 'task' ? 'task' : 'activity') + ' is empty.'); finish(); return; }
        var formats = state.gen.formats || ['pdf'];
        var okCount = 0, failed = [], i = 0;
        function next() {
            if (i >= checks.length) return end();
            var check = checks[i++], sid = check.value, subject = check.dataset.subject || card.dataset.subjectCode || '';
            setResult('', '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Creating ' + (formats.length > 1 ? 'PDF + Word' : 'PDF') +
                ' and sending ' + i + ' of ' + checks.length + '…');
            var chain = Promise.resolve([]);
            formats.forEach(function (fmt) {
                chain = chain.then(function (ids) { return buildFile(sid, subject, f, fmt).then(function (d) { return ids.concat([d.resource_id]); }); });
            });
            return chain.then(function (ids) {
                var s = sendFile(sid, subject, message, ids[0]);
                if (ids[1]) s = s.then(function () { return sendFile(sid, subject, 'Word copy of "' + f.title + '".', ids[1]); });
                return s;
            }).then(function () { okCount++; markSent(card, sid); })
              .catch(function (err) {
                  failed.push({ name: ((liFor(card, sid) || document).querySelector('.arg-member-name') || {}).textContent || 'Student', error: err.message });
              })
              .then(next);
        }
        function end() {
            setResult(failed.length ? 'err' : 'ok', '<i class="fas ' + (failed.length ? 'fa-triangle-exclamation' : 'fa-check-circle') + ' me-1"></i>Sent to ' + okCount + ' of ' + plural(checks.length, 'student') + '.' +
                (failed.length ? '<div class="mt-1 small">' + failed.map(function (x) { return esc(x.name) + ': ' + esc(x.error); }).join('<br>') + '</div>' : ''));
            finish();
        }
        next();
    }
    function previewDoc(format, btn) {
        var picked = pickedChecks();
        var st = mBody.querySelector('.argm-prev-status');
        if (!picked.length) { if (st) st.textContent = 'Select a student first.'; return; }
        var f = docFields();
        btn.disabled = true;
        if (st) st.textContent = 'Creating…';
        // Opened right away so the browser does not block it as a pop-up.
        var win = window.open('', '_blank');
        buildFile(picked[0].value, picked[0].dataset.subject || G.card.dataset.subjectCode || '', f, format)
            .then(function (d) {
                var url = '<?php echo $base_url; ?>modules/ai/download_resource.php?id=' + d.resource_id;
                if (win) win.location = url; else window.open(url, '_blank');
                if (st) st.textContent = 'Opened ' + d.original_filename + ' (not sent yet).';
            })
            .catch(function (err) { if (win) win.close(); if (st) st.textContent = err.message; })
            .then(function () { btn.disabled = false; });
    }

    function doSend() {
        if (!G || G.state.busy) return;
        var card = G.card, state = G.state, d = card.dataset;
        var checks = pickedChecks();
        var ta = mBody.querySelector('.arg-msg');
        if (!checks.length || !ta) return;
        var message = ta.value.trim();
        state.msg = ta.value;
        hideConfirm();
        state.busy = true;
        updateSend();

        function finish() {
            state.busy = false;
            renderStudents();
        }

        if (state.mode === 'activity' || state.mode === 'task') {
            sendDocs(card, state, checks, message, finish);
            return;
        }
        if (state.mode === 'catchup') {
            setResult('', '<span class="spinner-border spinner-border-sm me-2" role="status"></span>' + (state.gen.delivery === 'gform'
                ? 'Creating Google Forms and sending to ' : 'Sending the quiz to ') + plural(checks.length, 'student') + '…');
            var fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('action', 'send');
            fd.append('subject_code', d.subjectCode || '');
            fd.append('term', d.term || '');
            fd.append('item', state.item && state.gen.record && state.gen.record.label ? state.gen.record.label : (d.item || ''));
            if (state.item && state.gen.record && state.gen.record.item_id) fd.append('item_id', state.gen.record.item_id);
            fd.append('student_ids', JSON.stringify(checks.map(function (c) { return parseInt(c.value, 10); })));
            fd.append('title', state.gen.title || '');
            fd.append('message', message);
            fd.append('questions_json', JSON.stringify(state.gen.questions || []));
            fetch(CATCHUP_URL, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success) {
                        setResult('err', '<i class="fas fa-triangle-exclamation me-1"></i>' + esc((res && res.error) || 'Could not send the quiz.'));
                        return;
                    }
                    (res.sent_ids || []).concat(res.already_ids || []).forEach(function (sid) { markSent(card, sid); });
                    var links = (res.forms || []).filter(function (f) { return /^https:\/\//i.test(f.view_url || ''); }).map(function (f) {
                        return '<a href="' + esc(f.view_url) + '" target="_blank" rel="noopener noreferrer">' + esc(f.name || 'Student') + ' <i class="fas fa-arrow-up-right-from-square"></i></a>';
                    });
                    setResult('ok', '<i class="fas fa-check-circle me-1"></i>' + esc(res.message) +
                        (links.length ? '<div class="argm-forms mt-1"><b>Google Forms created:</b> ' + links.join(' · ') + '</div>' : ''));
                })
                .catch(function () { setResult('err', 'Network error. Please try again.'); })
                .then(finish);
            return;
        }

        var okCount = 0, failed = [], i = 0;
        function next() {
            if (i >= checks.length) return end();
            var check = checks[i++];
            setResult('', '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Sending ' + i + ' of ' + checks.length + '…');
            return sendOne(check, message, (state.gen.recommendation || {}).student_message || '').then(function (res) {
                var li = liFor(card, check.value);
                var sent = li && li.querySelector('.arg-sent'), fail = li && li.querySelector('.arg-fail');
                if (res.ok) {
                    okCount++;
                    markSent(card, check.value);
                } else {
                    failed.push({ name: (li && li.querySelector('.arg-member-name') || {}).textContent || 'Student', error: res.error });
                    if (sent) sent.classList.add('d-none');
                    if (fail) { fail.classList.remove('d-none'); fail.textContent = 'Not sent'; fail.title = res.error; }
                }
                return next();
            });
        }
        function end() {
            var html = '<i class="fas ' + (failed.length ? 'fa-triangle-exclamation' : 'fa-check-circle') + ' me-1"></i>Sent to ' + okCount + ' of ' + plural(checks.length, 'student') + '.';
            if (failed.length) {
                html += '<div class="mt-1 small">' + failed.map(function (f) { return esc(f.name) + ': ' + esc(f.error); }).join('<br>') + '</div>';
            }
            setResult(failed.length ? 'err' : 'ok', html);
            finish();
        }
        next();
    }

    if (mEl) {
        document.addEventListener('click', function (e) {
            var remedy = e.target.closest ? e.target.closest('button.arg-remedy') : null;
            if (remedy) {
                var rcard = remedy.closest('.arg-card');
                if (rcard) openRisk(rcard, remedy.dataset.lowId || remedy.dataset.itemId, !!remedy.dataset.lowId);
                return;
            }
            var pick = e.target.closest ? e.target.closest('.argm-item') : null;
            if (pick && G && !G.state.busy) { startItem(pick.dataset.lowId || pick.dataset.itemId, !!pick.dataset.lowId); return; }
            var prev = e.target.closest ? e.target.closest('.argm-prev') : null;
            if (prev && G) { previewDoc(prev.dataset.format, prev); return; }
            var ask = e.target.closest ? e.target.closest('.arg-ask-btn') : null;
            if (ask) {
                var card = ask.closest('.arg-card');
                if (card) openGroup(card);
                return;
            }
        });
        mGen.addEventListener('click', function () { generate(true); });
        mNote.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); generate(true); }
        });
        mSend.addEventListener('click', askConfirm);
        mConfirm.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('button') : null;
            if (!b) return;
            if (b.classList.contains('argm-confirm-cancel')) hideConfirm();
            else if (b.classList.contains('argm-confirm-yes')) doSend();
        });
        mStudents.addEventListener('change', function (e) {
            var t = e.target;
            if (t.classList.contains('argm-all')) allChecks().forEach(function (c) { c.checked = t.checked; });
            if (t.classList.contains('argm-check') || t.classList.contains('argm-all')) { hideConfirm(); syncStudentControls(); }
        });
        mBody.addEventListener('input', function (e) {
            if (e.target.classList && e.target.classList.contains('arg-msg')) {
                if (G) G.state.msg = e.target.value;
                updateMsgCount();
            }
        });
        mEl.addEventListener('hidden.bs.modal', function () {
            if (G) G.state.note = mNote.value.trim();
            hideConfirm();
        });
    }

    // Start
    fillGrades();
    fillSubjects();
    applyFilters();
});
</script>

<!-- Student Attendance — tracked in its own `attendance` table, completely
     separate from Manage Assessment Items / WW-PT-Examinations grading.
     Never adds or subtracts from a grade; shown here only as an ADDITIONAL
     signal (frequent absences/lateness) alongside the grade-based At-Risk
     table above. Covers the active school year to date. -->
<div class="card shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-user-check text-info me-2"></i>Student Attendance</h5>
        <?php if ($is_teacher): ?>
            <a href="../attendance/take.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-clipboard-check me-1"></i> Take Attendance</a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php
        $attendance_rows = array_filter($ai_results, function ($r) {
            return $r['attendance']['total'] > 0;
        });
        usort($attendance_rows, function ($a, $b) {
            return ($a['attendance']['percentage'] ?? 100) <=> ($b['attendance']['percentage'] ?? 100);
        });
        ?>
        <?php if (!empty($attendance_rows)): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>LRN</th>
                            <th>Student Name</th>
                            <th>Grade Level</th>
                            <th class="text-center">Present</th>
                            <th class="text-center">Late</th>
                            <th class="text-center">Absent</th>
                            <th class="text-center">Attendance %</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendance_rows as $result): ?>
                            <?php $att = $result['attendance']; ?>
                            <tr class="<?php echo $att['at_risk'] ? 'table-warning' : ''; ?>">
                                <td><?php echo htmlspecialchars($result['student']['lrn']); ?></td>
                                <td><?php echo htmlspecialchars($result['student']['last_name'] . ', ' . $result['student']['first_name']); ?></td>
                                <td><?php echo htmlspecialchars($result['student']['grade_level']); ?></td>
                                <td class="text-center"><span class="badge bg-success bg-opacity-25 text-success"><?php echo (int) $att['present']; ?></span></td>
                                <td class="text-center"><span class="badge bg-warning bg-opacity-25 text-warning-emphasis"><?php echo (int) $att['late']; ?></span></td>
                                <td class="text-center"><span class="badge bg-danger bg-opacity-25 text-danger"><?php echo (int) $att['absent']; ?></span></td>
                                <td class="text-center">
                                    <strong class="<?php echo $att['at_risk'] ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo $att['percentage'] !== null ? number_format($att['percentage'], 1) . '%' : '—'; ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php if ($att['at_risk']): ?>
                                        <span class="badge bg-danger"><i class="fas fa-triangle-exclamation me-1"></i>At-Risk (Attendance)</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Good Standing</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-4 text-muted">
                <i class="fas fa-calendar-xmark fa-2x d-block mb-2"></i>
                No attendance has been recorded yet<?php echo $is_teacher ? ' — use Take Attendance above to start tracking.' : '.'; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Passing / Not At-Risk Students — kept clearly SEPARATE from the
     At-Risk table above: zero subjects below the passing grade or
     Incomplete this term. -->
<div class="card shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-circle-check text-success me-2"></i>Not At-Risk / Passing Students</h5>
        <span class="badge bg-success"><?php echo $passing_count; ?> students</span>
    </div>
    <div class="card-body p-0">
        <?php if ($passing_count > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>LRN</th>
                            <th>Student Name</th>
                            <th>Grade Level</th>
                            <th>Average</th>
                            <th>Subjects Passed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($passing as $psid): ?>
                            <?php $result = $ai_results[$psid]; ?>
                            <tr class="table-success">
                                <td><?php echo htmlspecialchars($result['student']['lrn']); ?></td>
                                <td><?php echo htmlspecialchars($result['student']['last_name'] . ', ' . $result['student']['first_name']); ?></td>
                                <td><?php echo htmlspecialchars($result['student']['grade_level']); ?></td>
                                <td><strong class="text-success"><?php echo number_format($result['average'], 2); ?>%</strong></td>
                                <td><?php echo (int) $result['subject_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-4 text-muted">
                <i class="fas fa-circle-info fa-2x d-block mb-2"></i>
                No passing students yet for the current data.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Top Performers -->
<div class="card shadow-sm rounded-3">
    <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-star text-warning me-2"></i>Top Performers</h5>
        <span class="badge bg-warning"><?php echo $top_count; ?> students</span>
    </div>
    <div class="card-body p-0">
        <?php if ($top_count > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>LRN</th>
                            <th>Student Name</th>
                            <th>Grade Level</th>
                            <th>Average</th>
                            <th>Highest Grade</th>
                            <th>Recommendations</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sorted_results = $ai_results;
                        usort($sorted_results, function ($a, $b) {
                            return $b['average'] <=> $a['average'];
                        });
                        $top_10 = array_slice($sorted_results, 0, 10);
                        ?>
                        <?php foreach ($top_10 as $result): ?>
                            <?php if ($result['average'] >= 90): ?>
                                <tr class="table-success">
                                    <td><?php echo htmlspecialchars($result['student']['lrn']); ?></td>
                                    <td><?php echo htmlspecialchars($result['student']['last_name'] . ', ' . $result['student']['first_name']); ?></td>
                                    <td><?php echo htmlspecialchars($result['student']['grade_level']); ?></td>
                                    <td><strong><?php echo number_format($result['average'], 2); ?>%</strong></td>
                                    <td><?php echo number_format($result['max_grade'], 2); ?>%</td>
                                    <td><span class="text-success"><i class="fas fa-award me-1"></i><?php echo htmlspecialchars($result['recommendations']); ?></span></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-4 text-muted">
                <i class="fas fa-star fa-2x d-block mb-2"></i>
                No top performers identified yet.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Group Ask AI: one catch-up session for everyone who shares the same problem -->
<div class="modal fade" id="argModal" tabindex="-1" aria-labelledby="argModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <div class="d-flex align-items-center">
                    <div class="ai-avatar me-2"><i class="fas fa-robot"></i></div>
                    <div>
                        <h6 class="modal-title mb-0" id="argModalTitle">Ask AI</h6>
                        <small class="ai-chat-subtitle" id="argModalSub"></small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="argm-intro" id="argModalIntro"></div>
                <div class="argm-students" id="argModalStudents"></div>
                <div class="arg-ask mt-3">
                    <input type="text" class="form-control form-control-sm arg-note" id="argModalNote" maxlength="300" placeholder="Optional: tell the AI what you want">
                    <button type="button" class="btn btn-sm btn-primary arg-gen" id="argModalGen"><i class="fas fa-wand-magic-sparkles me-1"></i>Generate</button>
                </div>
                <div class="argm-body" id="argModalBody" aria-live="polite"></div>
            </div>
            <div class="modal-footer argm-footer">
                <div class="w-100">
                    <div class="arg-confirm d-none" id="argModalConfirm"></div>
                    <div class="arg-result" id="argModalResult" aria-live="polite"></div>
                </div>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="argModalSend" disabled><i class="fas fa-paper-plane me-1"></i>Send</button>
            </div>
        </div>
    </div>
</div>

<!-- Ask AI / AI Intervention Workspace -->
<div class="modal fade" id="aiChatModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-xl ai-chat-modal-dialog">
        <div class="modal-content ai-chat-modal-content">
            <div class="modal-header bg-primary text-white">
                <div class="d-flex align-items-center">
                    <div class="ai-avatar me-2"><i class="fas fa-robot"></i></div>
                    <div>
                        <h6 class="modal-title mb-0">Ask AI</h6>
                        <small class="ai-chat-subtitle" id="aiChatStudentName"></small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Only shown when the student has more than one at-risk subject
                 this teacher owns — a single subject is used automatically. -->
            <div class="ai-subject-bar px-3 py-2 d-none" id="aiSubjectBar">
                <span class="ai-subject-label">Talking about:</span>
                <div class="d-inline-flex flex-wrap" id="aiSubjectChips"></div>
            </div>

            <div class="ai-workspace-grid">
                <button type="button" class="ai-context-toggle" id="aiContextToggle" aria-expanded="false" aria-controls="aiContextPanel">
                    <i class="fas fa-bars me-2"></i><span>Student Context</span>
                </button>
                <aside class="ai-workspace-panel ai-context-panel" id="aiContextPanel" hidden>
                    <div class="ai-panel-heading"><i class="fas fa-user-shield me-2"></i>Student Risk Summary</div>
                    <div id="aiRiskSummary" class="ai-risk-summary">
                        <div class="ai-summary-empty">Select an at-risk student to view the intervention context.</div>
                    </div>
                </aside>

                <section class="ai-workspace-panel ai-conversation-panel">
                    <div class="ai-panel-heading d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-comments me-2"></i>AI Conversation</span>
                        <span class="d-flex align-items-center gap-2">
                            <label class="ai-lang-select" title="Language of AI replies, quizzes, activities, files and messages. Auto follows the subject.">
                                <i class="fas fa-language" aria-hidden="true"></i>
                                <span>Language</span>
                                <select id="aiLanguageSelect" class="form-select form-select-sm" aria-label="AI response language">
                                    <option value="auto" selected>Auto</option>
                                    <option value="english">English</option>
                                    <option value="filipino">Filipino</option>
                                </select>
                            </label>
                            <button type="button" class="btn btn-sm btn-outline-primary ai-new-chat" id="aiNewChat">
                                <i class="fas fa-plus me-1"></i>New Chat
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary ai-history-toggle" id="aiHistoryToggle" aria-expanded="false">
                                <i class="fas fa-clock-rotate-left me-1"></i>History
                            </button>
                        </span>
                    </div>
                    <div id="aiHistoryPanel" class="ai-history-panel" hidden>
                        <div class="ai-history-title">Conversation history</div>
                        <div id="aiHistoryList" class="ai-history-list"></div>
                    </div>
                    <div class="modal-body p-0" id="aiChatMessages">
                        <!-- messages, resource preview cards, and file cards appended here -->
                    </div>
                    <div class="ai-attach-row d-none" id="aiAttachRow"></div>
                    <form id="aiChatForm" class="d-flex w-100 gap-2 mt-2" autocomplete="off">
                        <button type="button" class="btn ai-attach-btn" id="aiAttachBtn" title="Add photos &amp; files" aria-label="Add photos and files" disabled><i class="fas fa-plus"></i></button>
                        <input type="file" id="aiAttachFile" class="d-none" accept="image/png,image/jpeg,image/webp,application/pdf,.pdf,.docx,.txt,.md,.csv">
                        <input type="text" class="form-control" id="aiChatInput" placeholder="Select a subject to start chatting..." disabled>
                        <button type="button" class="btn btn-outline-primary text-nowrap" id="aiSendCurrentBtn" disabled title="Send the latest approved AI response to the student">
                            <i class="fas fa-paper-plane me-1"></i><span class="d-none d-md-inline">Send to Student</span>
                        </button>
                        <button type="submit" class="btn btn-primary" id="aiChatSendBtn" disabled><i class="fas fa-paper-plane"></i></button>
                    </form>
                </section>

            </div>
        </div>
    </div>
</div>

<style>
.ai-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255,255,255,.18);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}
.ai-chat-subtitle { opacity: .85; }
/* "+" attach button (Ask AI composer) */
.ai-attach-btn { flex: 0 0 auto; width: 38px; height: 38px; padding: 0; display: inline-grid; place-items: center; border-radius: 50%; border: 1px solid #d0d5dd; background: #fff; color: #14213d; }
.ai-attach-btn:hover:not(:disabled) { background: #eaf2ff; border-color: #9ec3ff; color: #0d6efd; }
.ai-attach-btn:disabled { opacity: .5; cursor: not-allowed; }
.ai-attach-row { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.ai-attach-row:empty { display: none; }
.ai-attach-chip { display: inline-flex; align-items: center; gap: 8px; max-width: 100%; padding: 5px 6px; border: 1px solid #d0e0f5; border-radius: 10px; background: #f0f6ff; color: #14213d; font-size: .78rem; }
.ai-attach-chip .ai-attach-thumb { flex: 0 0 30px; width: 30px; height: 30px; border-radius: 7px; display: grid; place-items: center; background: #fff; color: #0d6efd; overflow: hidden; }
.ai-attach-chip .ai-attach-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ai-attach-chip .ai-attach-name { min-width: 0; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
.ai-attach-chip .ai-attach-size { color: #667085; font-size: .7rem; white-space: nowrap; }
.ai-attach-chip button { width: 22px; height: 22px; flex: 0 0 22px; display: grid; place-items: center; padding: 0; border: 0; border-radius: 50%; background: transparent; color: #667085; }
.ai-attach-chip button:hover { background: #fff; color: #b42318; }
.ai-attach-error { color: #b42318; font-size: .78rem; }
.ai-chat-bubble .ai-msg-attach { display: flex; width: fit-content; align-items: center; gap: 6px; margin-top: 6px; padding: 2px 9px; border-radius: 999px; background: rgba(255,255,255,.2); font-size: .72rem; max-width: 100%; }
.ai-chat-bubble .ai-msg-attach span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ai-chat-modal-dialog {
    height: calc(100vh - 1.5rem);
    max-height: calc(100vh - 1.5rem);
}
.ai-chat-modal-content {
    height: 100%;
    max-height: 100%;
    border: 0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.ai-workspace-grid {
    position: relative;
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 0;
    flex: 1 1 auto;
    min-height: 0;
    background: #f6f8fb;
    overflow: hidden;
}
.ai-context-toggle {
    position: absolute;
    z-index: 3;
    top: 12px;
    left: 12px;
    border: 1px solid #d8e2f1;
    border-radius: 8px;
    background: #fff;
    color: #24558b;
    padding: 7px 10px;
    font-size: .76rem;
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(20,33,61,.08);
    cursor: pointer;
}
.ai-context-toggle:hover { background: #f4f7fc; }
.ai-workspace-grid.context-open {
    grid-template-columns: 300px minmax(0, 1fr);
}
.ai-workspace-grid.context-open .ai-context-toggle {
    left: 12px;
}
.ai-workspace-grid.context-open .ai-context-panel {
    padding-top: 58px;
}
.ai-workspace-panel {
    min-width: 0;
    padding: 14px;
    background: #fff;
    overflow-y: auto;
}
.ai-context-panel { border-right: 1px solid #e7ebf1; }
.ai-conversation-panel {
    display: flex;
    flex-direction: column;
    min-width: 0;
    overflow: hidden;
}
.ai-panel-heading {
    color: #14213d;
    font-size: .82rem;
    font-weight: 700;
    letter-spacing: .01em;
    margin-bottom: 10px;
}
.ai-history-toggle, .ai-new-chat { font-size: .72rem; }
.ai-lang-select { display: inline-flex; align-items: center; gap: 5px; margin: 0; font-size: .72rem; font-weight: 600; color: #667085; white-space: nowrap; }
.ai-lang-select i { color: #356ea8; }
.ai-lang-select .form-select { width: auto; min-width: 92px; padding: .15rem 1.6rem .15rem .5rem; font-size: .72rem; background-position: right .4rem center; }
@media (max-width: 575.98px) { .ai-lang-select > span { display: none; } }
.ai-history-panel {
    border: 1px solid #e7ebf1;
    border-radius: 8px;
    background: #f8fafc;
    padding: 8px 10px;
    margin-bottom: 8px;
    max-height: 150px;
    overflow-y: auto;
}
.ai-history-title { color: #667085; font-size: .7rem; font-weight: 700; margin-bottom: 5px; }
.ai-history-item { display: flex; align-items: center; gap: 8px; padding: 6px 0; border-bottom: 1px solid #eef0f3; font-size: .72rem; cursor: pointer; }
.ai-history-item:last-child { border-bottom: 0; }
.ai-history-item.active { background: #eaf2ff; border-radius: 6px; padding-left: 5px; }
.ai-history-time { color: #98a2b3; white-space: nowrap; }
.ai-history-text { color: #344054; overflow-wrap: anywhere; }
.ai-history-main { min-width: 0; flex: 1; }
.ai-history-name { color: #344054; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ai-history-preview { color: #98a2b3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ai-history-actions { display: flex; gap: 2px; flex-shrink: 0; }
.ai-history-actions button { border: 0; background: transparent; color: #667085; padding: 2px 4px; cursor: pointer; }
.ai-history-actions button:hover { color: #0d6efd; }
.ai-risk-summary {
    display: grid;
    gap: 7px;
    font-size: .78rem;
}
.ai-summary-item {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    border-bottom: 1px solid #f0f2f5;
    padding-bottom: 6px;
    align-items: flex-start;
}
.ai-summary-item span:first-child { color: #667085; }
.ai-summary-item strong {
    color: #1d2939;
    text-align: right;
    min-width: 0;
    overflow-wrap: anywhere;
}
.ai-summary-empty, .ai-draft-preview {
    color: #98a2b3;
    font-size: .8rem;
    text-align: center;
}
.ai-summary-empty { padding: 12px 4px; }
.ai-quick-actions { display: flex; flex-wrap: wrap; gap: 5px; }
.ai-quick-action {
    border: 1px solid #d8e2f1;
    background: #f4f7fc;
    color: #24558b;
    border-radius: 7px;
    padding: 5px 7px;
    font-size: .7rem;
    cursor: pointer;
    transition: .16s ease;
}
.ai-quick-action:hover { background: #e4efff; border-color: #8bb5e8; transform: translateY(-1px); }
.ai-generation-options { display: grid; gap: 7px; }
.ai-generation-options label { color: #667085; font-size: .7rem; font-weight: 600; }
.ai-generation-options select, .ai-generation-options input { margin-top: 3px; font-size: .75rem; }
.ai-question-types {
    border: 1px solid #e4e7ec;
    border-radius: 7px;
    padding: 7px 8px 6px;
    margin: 0;
}
.ai-question-types legend {
    float: none;
    width: auto;
    margin: 0 0 4px;
    color: #667085;
    font-size: .7rem;
    font-weight: 600;
}
.ai-question-types legend span { font-weight: 400; color: #98a2b3; }
.ai-question-type-select-label { color: #667085 !important; font-size: .7rem !important; font-weight: 600 !important; margin: 0 0 3px !important; }
.ai-question-type-choices { margin-top: 7px; }
.ai-question-type-choices[hidden] { display: none; }
.ai-question-types label {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #344054;
    font-size: .73rem;
    font-weight: 500;
    margin: 4px 0;
}
.ai-question-types input { margin: 0; }
.ai-question-types small { display: block; color: #98a2b3; font-size: .66rem; line-height: 1.25; margin-top: 5px; }
.ai-option-check { display: flex; align-items: center; gap: 6px; color: #344054 !important; }
.ai-option-check input { margin: 0; }
.ai-draft-preview {
    border: 1px dashed #d5dce8;
    border-radius: 9px;
    padding: 28px 12px;
    min-height: 170px;
}
.ai-draft-preview i { font-size: 1.5rem; display: block; margin-bottom: 8px; color: #9aa8bc; }
.ai-draft-preview-content { text-align: left; color: #344054; max-height: 275px; overflow-y: auto; font-size: .78rem; white-space: pre-wrap; }
.ai-intervention-progress { margin-top: 12px; font-size: .75rem; color: #667085; }
.ai-subject-bar {
    background: #f8f9fb;
    border-bottom: 1px solid #eef0f3;
    font-size: .82rem;
}
.ai-subject-label { color: #6c757d; margin-right: 6px; }
.ai-subject-chip {
    display: inline-block;
    background: var(--surface);
    border: 1px solid #dee2e6;
    color: #495057;
    border-radius: 999px;
    padding: 3px 12px;
    font-size: .78rem;
    margin: 0 4px 4px 0;
    cursor: pointer;
    transition: all .15s;
}
.ai-subject-chip:hover { border-color: #0d6efd; color: #0d6efd; }
.ai-subject-chip.active {
    background: #0d6efd;
    border-color: #0d6efd;
    color: #fff;
}
#aiChatMessages {
    height: auto !important;
    min-height: 0;
    flex: 1 1 auto;
    overflow-y: auto;
    background: #fbfbfd;
    border: 1px solid #eef0f3;
    border-radius: 9px;
    padding: 12px;
}
.ai-chat-row {
    display: flex;
    align-items: flex-end;
    margin-bottom: 14px;
}
.ai-chat-row.user { justify-content: flex-end; }
.ai-chat-col {
    max-width: 82%;
    display: flex;
    flex-direction: column;
}
.ai-chat-row.user .ai-chat-col { align-items: flex-end; }
.ai-chat-bubble {
    padding: 10px 14px;
    border-radius: 14px;
    font-size: 0.92rem;
    line-height: 1.55;
    box-shadow: 0 1px 2px rgba(0,0,0,.05);
}
.ai-message-time {
    color: #98a2b3;
    font-size: .65rem;
    margin-top: 3px;
}
.ai-chat-row.user .ai-message-time { text-align: right; }
.ai-rich-content p {
    margin: 0 0 10px;
}
.ai-rich-content p:last-child {
    margin-bottom: 0;
}
.ai-rich-content h6 {
    margin: 0 0 7px;
    color: #174ea6;
    font-size: .96rem;
    font-weight: 700;
    line-height: 1.35;
}
.ai-rich-content ul,
.ai-rich-content ol {
    margin: 5px 0 11px;
    padding-left: 21px;
}
.ai-rich-content li {
    margin: 4px 0;
    padding-left: 2px;
}
.ai-rich-content strong {
    font-weight: 700;
    color: #172b4d;
}
.ai-chat-bubble.bot {
    background: var(--surface);
    color: #212529;
    border: 1px solid #eef0f3;
    border-bottom-left-radius: 4px;
}
.ai-chat-bubble.user {
    background: #0d6efd;
    color: #fff;
    border-bottom-right-radius: 4px;
}
.ai-chat-icon {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #0d6efd;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .78rem;
    margin-right: 8px;
    flex-shrink: 0;
}
.ai-typing-dots span {
    display: inline-block;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #adb5bd;
    margin-right: 3px;
    animation: aiTypingBounce 1.2s infinite;
}
.ai-typing-dots span:nth-child(2) { animation-delay: .2s; }
.ai-typing-dots span:nth-child(3) { animation-delay: .4s; }
@keyframes aiTypingBounce {
    0%, 60%, 100% { transform: translateY(0); opacity: .5; }
    30% { transform: translateY(-4px); opacity: 1; }
}
.ai-chat-starters { border-top: 1px solid #f1f2f4; }
.ai-suggestion-chip {
    display: inline-block;
    background: #eef2ff;
    color: #3b5bdb;
    border: 1px solid #d7dffc;
    border-radius: 999px;
    padding: 4px 12px;
    font-size: .78rem;
    margin: 6px 6px 0 0;
    cursor: pointer;
    transition: background .15s;
}
.ai-suggestion-chip:hover { background: #dbe4ff; }
.ai-bubble-actions { margin-top: 4px; }
.ai-send-to-student-btn {
    background: none;
    border: none;
    color: #0d6efd;
    font-size: .76rem;
    padding: 2px 0;
    cursor: pointer;
}
.ai-send-to-student-btn:hover { text-decoration: underline; }
.ai-send-to-student-btn:disabled { color: #adb5bd; cursor: default; text-decoration: none; }
.ai-sent-confirm {
    font-size: .76rem;
    color: #2f9e44;
    display: inline-block;
    margin-top: 4px;
}

/* ---- Progress states while a request is in flight ---- */
.ai-progress-label {
    font-size: .78rem;
    color: #868e96;
    margin-left: 8px;
    display: inline-block;
}

/* ---- Resource content-preview card (Mode B, no file requested yet) ---- */
.ai-resource-card, .ai-file-card {
    background: var(--surface);
    border: 1px solid #e3e6ed;
    border-radius: 12px;
    box-shadow: 0 2px 6px rgba(20,33,61,.06);
    margin-top: 6px;
    max-width: 92%;
    overflow: hidden;
}
.ai-resource-card-head {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: #f5f7ff;
    border-bottom: 1px solid #e3e6ed;
}
.ai-resource-card-head i { color: #3b5bdb; }
.ai-resource-card-title { font-weight: 700; font-size: .86rem; color: #14213D; flex: 1; }
.ai-resource-card-type { font-size: .72rem; color: #6c757d; }
.ai-resource-card-body {
    padding: 12px 14px;
    max-height: 260px;
    overflow-y: auto;
    font-size: .85rem;
    line-height: 1.55;
    color: #212529;
    white-space: pre-line;
}
.ai-resource-card-body textarea {
    width: 100%;
    min-height: 200px;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 8px 10px;
    font-size: .84rem;
    font-family: inherit;
}
.ai-resource-card-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 10px 14px;
    border-top: 1px solid #eef0f3;
    background: #fbfbfd;
}
.ai-resource-card-actions .btn {
    font-size: .76rem;
    padding: 4px 11px;
}
.ai-resource-card-note {
    font-size: .74rem;
    color: #B7791F;
    background: #FFF7E6;
    border-top: 1px solid #F0DFAE;
    padding: 6px 14px;
}

/* ---- Generated file card (Mode B with a real PDF/DOCX) ---- */
.ai-file-card-head { padding: 14px; display: flex; align-items: center; gap: 12px; background: #f5f7ff; }
.ai-file-icon { font-size: 1.7rem; flex-shrink: 0; }
.ai-file-icon.pdf { color: #c92a2a; }
.ai-file-icon.docx { color: #1864ab; }
.ai-file-name { font-weight: 700; font-size: .88rem; color: #14213D; word-break: break-word; }
.ai-file-meta { font-size: .76rem; color: #6c757d; margin-top: 2px; }
.ai-file-generated-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: .72rem;
    color: #2f9e44;
    margin-top: 4px;
}
.ai-file-card-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 10px 14px;
    border-top: 1px solid #eef0f3;
    background: #fff;
}
.ai-file-card-actions .btn { font-size: .78rem; padding: 5px 13px; }
.ai-file-card-send-row { padding: 10px 14px; border-top: 1px solid #eef0f3; background: #fbfbfd; }

.ai-confirm-box {
    font-size: .8rem;
    color: #212529;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}
.ai-confirm-box .btn { font-size: .74rem; padding: 3px 10px; }

@media (max-width: 576px) {
    .ai-resource-card, .ai-file-card { max-width: 100%; }
    .ai-chat-modal-dialog {
        height: calc(100vh - .5rem);
        max-height: calc(100vh - .5rem);
        margin: .25rem;
    }
}
@media (max-width: 1000px) {
    .ai-workspace-grid {
        grid-template-columns: minmax(0, 1fr);
        overflow-y: auto;
    }
    .ai-workspace-grid.context-open { grid-template-columns: 220px minmax(0, 1fr); }
}
@media (max-width: 700px) {
    .ai-workspace-grid {
        display: block;
        overflow-y: auto;
    }
    .ai-workspace-panel { border: 0; border-bottom: 1px solid #e7ebf1; }
    .ai-context-panel { max-height: none; overflow: visible; }
    .ai-workspace-grid.context-open { display: block; }
    .ai-workspace-grid.context-open .ai-context-panel { padding-top: 52px; }
    .ai-conversation-panel { min-height: 500px; height: 500px; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('aiChatModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;

    const modal = new bootstrap.Modal(modalEl);
    const messagesEl = document.getElementById('aiChatMessages');
    const workspaceGrid = document.querySelector('.ai-workspace-grid');
    const contextToggle = document.getElementById('aiContextToggle');
    const contextPanel = document.getElementById('aiContextPanel');
    const historyToggle = document.getElementById('aiHistoryToggle');
    const historyPanel = document.getElementById('aiHistoryPanel');
    const historyList = document.getElementById('aiHistoryList');
    const newChatBtn = document.getElementById('aiNewChat');
    const subjectBar = document.getElementById('aiSubjectBar');
    const subjectChipsEl = document.getElementById('aiSubjectChips');
    const form = document.getElementById('aiChatForm');
    const input = document.getElementById('aiChatInput');
    const sendBtn = document.getElementById('aiChatSendBtn');
    const sendCurrentBtn = document.getElementById('aiSendCurrentBtn');
    const attachBtn = document.getElementById('aiAttachBtn');
    const attachFile = document.getElementById('aiAttachFile');
    const attachRow = document.getElementById('aiAttachRow');
    const nameEl = document.getElementById('aiChatStudentName');
    const riskSummaryEl = document.getElementById('aiRiskSummary');
    const languageSelect = document.getElementById('aiLanguageSelect');

    const CSRF_TOKEN = '<?php echo $csrf_token; ?>';
    const TEACHER_NAME = <?php echo json_encode($teacher_display_name); ?>;
    // False until an Administrator finishes Settings -> Google Forms Setup.
    // While false the chat never offers the Google Form path (it would only
    // fail) and a quiz is sent to the student as a PDF instead.
    const GOOGLE_FORMS_READY = <?php echo googleFormsIsConfigured() ? 'true' : 'false'; ?>;
    const CHATBOT_URL = '<?php echo $base_url; ?>modules/ai/chatbot.php';
    const SEND_URL = '<?php echo $base_url; ?>modules/ai/send_to_student.php';
    const GENERATE_URL = '<?php echo $base_url; ?>modules/ai/generate_resource.php';
    const VIEW_URL = '<?php echo $base_url; ?>modules/ai/view_resource.php';
    const DOWNLOAD_URL = '<?php echo $base_url; ?>modules/ai/download_resource.php';
    const CREATE_QUIZ_FORM_URL = '<?php echo $base_url; ?>modules/ai/create_quiz_form.php';
    const SYNC_QUIZ_SCORES_URL = '<?php echo $base_url; ?>modules/ai/sync_quiz_scores.php';
    const LIST_ASSESSMENT_ITEMS_URL = '<?php echo $base_url; ?>modules/ai/list_assessment_items.php';
    // AI-checking a student's submitted free-response answer, right inside
    // this chat, instead of only on the separate Student Submissions page
    // (modules/ai/resources.php) -- see checkPendingSubmissionsForStudentSubject().
    const LIST_PENDING_SUBMISSIONS_URL = '<?php echo $base_url; ?>modules/ai/list_pending_submissions.php';
    const CHECK_SUBMISSION_URL = '<?php echo $base_url; ?>modules/ai/check_submission.php';
    const MARK_SUBMISSION_REVIEWED_URL = '<?php echo $base_url; ?>modules/ai/mark_submission_reviewed.php';
    const LINK_RESOURCE_ITEM_URL = '<?php echo $base_url; ?>modules/ai/link_resource_item.php';
    const VIEW_SUBMISSION_ANSWER_URL = '<?php echo $base_url; ?>modules/ai/view_submission_answer.php';
    // Which resource_type values can become a Google Form quiz — mirrors
    // AI_GFORM_ELIGIBLE_TYPES in includes/gemini_service.php exactly (one
    // definition, server-side, just reflected here for the UI's own use).
    const GFORM_ELIGIBLE_TYPES = <?php echo json_encode(AI_GFORM_ELIGIBLE_TYPES); ?>;
    // Fixed vocabulary for the Examinations sub-tag picker (ST1/ST2/Term
    // Exam) — mirrors gh_examSubtypes() in includes/grading_helpers.php.
    // Only shown when the selected subject's exam_mode requires it (see
    // currentSubjectMeta() / renderResourceCard()).
    const EXAM_SUBTYPES = <?php echo json_encode(gh_examSubtypes()); ?>;

    let currentStudentId = null;
    let currentStudentName = '';
    let currentActivityDate = '';
    let currentRiskType = 'Academic';
    let currentAttendance = {};
    let currentSubjects = [];
    let currentSubjectCode = null;
    let history = []; // client-side transcript: [{role:'teacher'|'ai', text, timestamp}]
    let currentChatId = null;
    let currentDraft = null; // the latest generated resource in this conversation: {resource_type, resource_type_label, title, student_content, teacher_answer_key}
    let currentSendText = '';
    let busy = false;

    function historyIndexKey() {
        return 'egradepro-ai-chats-' + String(currentStudentId || '') + '-' + String(currentSubjectCode || '');
    }

    function formatMessageTime(timestamp) {
        const date = timestamp ? new Date(timestamp) : new Date();
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function saveHistory() {
        if (!currentStudentId || !currentSubjectCode || !currentChatId) return;
        try {
            const chats = readChatIndex();
            const chat = chats.find(function (item) { return item.id === currentChatId; });
            if (chat) {
                chat.messages = history.slice(-50);
                chat.updatedAt = new Date().toISOString();
                chat.preview = history.length ? history[history.length - 1].text : 'New conversation';
                if (chat.title === 'New conversation') {
                    const firstTeacherMessage = history.find(function (turn) { return turn.role === 'teacher'; });
                    if (firstTeacherMessage) chat.title = String(firstTeacherMessage.text).slice(0, 48);
                }
                localStorage.setItem(historyIndexKey(), JSON.stringify(chats));
            }
        } catch (e) {
            // Conversation display remains available when browser storage is unavailable.
        }
        renderHistory();
    }

    function renderHistory() {
        if (!historyList) return;
        historyList.innerHTML = '';
        const chats = readChatIndex().sort(function (a, b) {
            return Number(b.pinned) - Number(a.pinned) || new Date(b.updatedAt) - new Date(a.updatedAt);
        });
        if (!chats.length) {
            historyList.innerHTML = '<div class="text-muted small">No previous chats. Click New Chat to start.</div>';
            return;
        }
        chats.forEach(function (chat) {
            const item = document.createElement('div');
            item.className = 'ai-history-item' + (chat.id === currentChatId ? ' active' : '');
            item.addEventListener('click', function () { loadChat(chat.id); });
            const main = document.createElement('div');
            main.className = 'ai-history-main';
            const name = document.createElement('div');
            name.className = 'ai-history-name';
            name.textContent = (chat.pinned ? 'Pinned - ' : '') + chat.title;
            const preview = document.createElement('div');
            preview.className = 'ai-history-preview';
            preview.textContent = chat.preview || 'New conversation';
            main.appendChild(name);
            main.appendChild(preview);
            item.appendChild(main);
            const actions = document.createElement('div');
            actions.className = 'ai-history-actions';
            [
                ['fa-thumbtack', 'Pin chat', function () { chat.pinned = !chat.pinned; writeChatIndex(chats); renderHistory(); }],
                ['fa-pen', 'Rename chat', function () {
                    const title = window.prompt('Chat name:', chat.title);
                    if (title && title.trim()) { chat.title = title.trim().slice(0, 80); writeChatIndex(chats); renderHistory(); }
                }],
                ['fa-trash', 'Delete chat', function () {
                    if (!window.confirm('Delete this chat history?')) return;
                    const remaining = chats.filter(function (entry) { return entry.id !== chat.id; });
                    writeChatIndex(remaining);
                    if (chat.id === currentChatId) {
                        if (remaining.length) loadChat(remaining[0].id); else startNewChat();
                    } else renderHistory();
                }]
            ].forEach(function (action) {
                const button = document.createElement('button');
                button.type = 'button';
                button.title = action[1];
                button.innerHTML = '<i class="fas ' + action[0] + '"></i>';
                button.addEventListener('click', function (event) { event.stopPropagation(); action[2](); });
                actions.appendChild(button);
            });
            item.appendChild(actions);
            historyList.appendChild(item);
        });
    }

    function readChatIndex() {
        try {
            const saved = JSON.parse(localStorage.getItem(historyIndexKey()) || '[]');
            return Array.isArray(saved) ? saved : [];
        } catch (e) { return []; }
    }

    function writeChatIndex(chats) {
        try { localStorage.setItem(historyIndexKey(), JSON.stringify(chats.slice(-50))); } catch (e) {}
    }

    // Re-renders one saved/live turn — a plain text bubble, or (for a
    // resource-type AI turn) the actual resource/file card, so reopening a
    // chat from History looks exactly like it did when it was generated
    // instead of collapsing to the short "Generated Quiz: ..." label that
    // gets sent back to the server as this turn's compact `text`.
    function renderStoredTurn(turn) {
        if (!turn || typeof turn !== 'object') return;
        if (turn.role === 'teacher') {
            addBubble(turn.text, 'user', { timestamp: turn.timestamp });
            return;
        }
        if (turn.resource) {
            if (turn.reply) addBubble(turn.reply, 'bot', { timestamp: turn.timestamp });
            currentDraft = {
                resource_type: turn.resource.resource_type,
                resource_type_label: turn.resource.resource_type_label,
                title: turn.resource.title,
                student_content: turn.resource.student_content,
                teacher_answer_key: turn.resource.teacher_answer_key,
                quiz_questions: Array.isArray(turn.resource.quiz_questions) ? turn.resource.quiz_questions : null,
            };
            if (turn.resource.resource_id && turn.resource.output_format === 'gform' && turn.resource.gform) {
                renderGformCard(turn.resource.gform, currentDraft);
            } else if (turn.resource.resource_id && turn.resource.output_format) {
                renderFileCard({
                    resource_id: turn.resource.resource_id,
                    title: turn.resource.title,
                    resource_type_label: turn.resource.resource_type_label,
                    output_format: turn.resource.output_format,
                    original_filename: turn.resource.original_filename,
                    file_size: turn.resource.file_size,
                });
            } else {
                renderResourceCard(currentDraft, turn.resource.file_error || null);
            }
            return;
        }
        addBubble(turn.text, 'bot', { timestamp: turn.timestamp, allowSend: !!turn.allowSend });
        currentSendText = turn.allowSend ? (turn.text || '') : '';
        sendCurrentBtn.disabled = !currentSendText;
    }

    // Welcome message from Ask AI, addressed to the logged-in teacher. It is a
    // display-only bubble: never part of `history`, never sent to the
    // server. The teacher simply types what to create -- nothing to click.
    function removeEmptyHint() {
        const old = document.getElementById('aiEmptyHint');
        if (old) old.remove();
    }

    function studentFirstLast() {
        const parts = String(currentStudentName || '').split(',');
        return parts.length > 1 ? (parts.slice(1).join(',').trim() + ' ' + parts[0].trim()).trim() : String(currentStudentName || '').trim();
    }

    // ---- Language: Auto follows the subject (mirrors geminiAskAiAutoLanguage()
    // in includes/gemini_service.php); the selector overrides it. The server
    // decides the reply language itself -- this is only for the greeting and
    // the short fixed lines sent with a resource. ----
    function askAiAutoLanguage(subjectName) {
        const n = String(subjectName || '').toLowerCase();
        if (/filipino|araling\s+panlipunan|\bap\s*\d|edukasyon\s+sa\s+pagpapakatao|\besp\b|\bgmrc\b|values\s+education|komunikasyon|pananaliksik|panitikan|pilipino|\bwika\b/.test(n)) return 'Filipino';
        return 'English';
    }
    function askAiLanguage() {
        const pref = languageSelect ? languageSelect.value : 'auto';
        if (pref === 'filipino') return 'Filipino';
        if (pref === 'english') return 'English';
        return currentSubjectCode ? askAiAutoLanguage(currentSubjectName()) : 'English';
    }
    function sharingResourceMessage(title) {
        return (askAiLanguage() === 'Filipino' ? 'Ibinabahagi ko sa iyo ang learning resource na ito: ' : 'Sharing a learning resource with you: ') + title;
    }

    function renderWelcome() {
        const name = TEACHER_NAME ? 'Teacher ' + TEACHER_NAME : 'Teacher';
        const student = studentFirstLast();
        const fil = askAiLanguage() === 'Filipino';
        let text;
        if (currentSubjectCode) {
            text = fil
                ? 'Hello, ' + name + '! Ako ang Ask AI assistant mo para kay ' + student + ' sa ' + currentSubjectName() + '. ' +
                  'Maaari mo akong ipagawa ng quiz, activity, remediation task, o learning material batay sa performance at pangangailangan ng mag-aaral. ' +
                  'Pwede mo ring sabihin ang "Generate this as PDF", "Make this a Word file" o "Create this as Google Form".'
                : 'Hello, ' + name + '! I\'m your Ask AI assistant for ' + student + ' in ' + currentSubjectName() + '. ' +
                  'You can ask me to create a quiz, activity, remediation task, or learning material based on the student\'s performance and needs. ' +
                  'You can also say "Generate this as PDF", "Make this a Word file", or "Create this as Google Form".';
        } else {
            text = fil
                ? 'Hello, ' + name + '! Ako ang Ask AI assistant mo para kay ' + student + '. Pumili muna ng subject sa itaas, tapos sabihin mo lang kung ano ang ipapagawa mo.'
                : 'Hello, ' + name + '! I\'m your Ask AI assistant for ' + student + '. Choose a subject above first, then tell me what you would like to create.';
        }
        addBubble(text, 'bot');
        // Tag the welcome bubble so a Language change can refresh it in place.
        const rows = messagesEl.querySelectorAll('.ai-chat-row.bot');
        if (rows.length) rows[rows.length - 1].classList.add('ai-welcome-row');
    }

    if (languageSelect) {
        languageSelect.addEventListener('change', function () {
            const old = messagesEl.querySelector('.ai-welcome-row');
            if (!old) return;
            const scroll = messagesEl.scrollTop;
            renderWelcome(); // appended at the end...
            const fresh = messagesEl.querySelectorAll('.ai-welcome-row');
            const neu = fresh[fresh.length - 1];
            if (neu && neu !== old) { old.replaceWith(neu); } // ...then moved into the old one's place
            messagesEl.scrollTop = scroll;
        });
    }

    function startNewChat() {
        if (!currentSubjectCode) return;
        currentChatId = 'chat-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
        history = [];
        currentDraft = null;
        currentSendText = '';
        sendCurrentBtn.disabled = true;
        messagesEl.innerHTML = '';
        renderWelcome();
        const chats = readChatIndex();
        chats.push({ id: currentChatId, title: 'New conversation', preview: 'New conversation', pinned: false, createdAt: new Date().toISOString(), updatedAt: new Date().toISOString(), messages: [] });
        writeChatIndex(chats);
        renderHistory();
        historyPanel.hidden = true;
        historyToggle.setAttribute('aria-expanded', 'false');
    }

    function loadChat(chatId) {
        const chat = readChatIndex().find(function (item) { return item.id === chatId; });
        if (!chat) return;
        currentChatId = chat.id;
        history = Array.isArray(chat.messages) ? chat.messages : [];
        currentDraft = null;
        currentSendText = '';
        sendCurrentBtn.disabled = true;
        messagesEl.innerHTML = '';
        renderWelcome();
        history.forEach(renderStoredTurn);
        renderHistory();
        historyPanel.hidden = true;
        historyToggle.setAttribute('aria-expanded', 'false');
    }

    function loadHistory() {
        const chats = readChatIndex();
        if (chats.length) {
            const newest = chats.sort(function (a, b) { return new Date(b.updatedAt) - new Date(a.updatedAt); })[0];
            loadChat(newest.id);
            return;
        }
        startNewChat();
        renderHistory();
    }

    historyToggle.addEventListener('click', function () {
        const open = historyPanel.hidden;
        historyPanel.hidden = !open;
        historyToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        renderHistory();
    });
    newChatBtn.addEventListener('click', startNewChat);

    contextToggle.addEventListener('click', function () {
        const isOpen = workspaceGrid.classList.toggle('context-open');
        contextPanel.hidden = !isOpen;
        contextToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        contextToggle.querySelector('i').className = isOpen ? 'fas fa-chevron-left me-2' : 'fas fa-bars me-2';
        contextToggle.querySelector('span').textContent = isOpen ? 'Hide Context' : 'Student Context';
    });

    function renderRiskSummary(attendance, riskType) {
        const subject = currentSubjects.find(function (item) { return item.code === currentSubjectCode; }) || currentSubjects[0] || {};
        const missing = (subject.missing_assessments || []).join(', ') || 'None recorded';
        const riskTypeLabel = riskType || 'Academic';
        const activity = currentActivityDate || 'No recorded activity';
        const rows = [
            ['Student', currentStudentName],
            ['Subject', subject.name || '—'],
            ['Current term grade', subject.grade !== undefined ? Number(subject.grade).toFixed(2) + '%' : '—'],
            ['Risk type', riskTypeLabel],
            ['Missing assessments', missing],
            ['Absences / Late / Excuse', (attendance.absent || 0) + ' / ' + (attendance.late || 0) + ' / ' + (attendance.excused || 0)],
            ['Last student activity', activity],
            ['Suggested next intervention', missing !== 'None recorded' ? 'Create remediation for a missing assessment' : 'Start a short guided practice'],
        ];
        riskSummaryEl.innerHTML = rows.map(function (row) {
            return '<div class="ai-summary-item"><span>' + escapeHtml(row[0]) + '</span><strong>' + escapeHtml(String(row[1])) + '</strong></div>';
        }).join('');
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[char];
        });
    }

    function updateDraftPreview() {
        // Draft content remains available in the conversation resource card.
    }

    function currentSubjectName() {
        const s = currentSubjects.find(function (x) { return x.code === currentSubjectCode; });
        return s ? s.name : '';
    }

    // Full subject record (incl. exam_mode/uses_domains/is_multi) for the
    // "Create Google Form Quiz" assessment picker — see renderResourceCard().
    function currentSubjectMeta() {
        return currentSubjects.find(function (x) { return x.code === currentSubjectCode; }) || null;
    }

    function renderStarters() {
        // The teacher starts the conversation by typing a question.
    }

    function renderSubjectChips() {
        subjectChipsEl.innerHTML = '';
        if (currentSubjects.length <= 1) {
            subjectBar.classList.add('d-none');
            return;
        }
        subjectBar.classList.remove('d-none');
        currentSubjects.forEach(function (s) {
            const chip = document.createElement('span');
            chip.className = 'ai-subject-chip' + (s.code === currentSubjectCode ? ' active' : '');
            chip.textContent = s.name;
            chip.addEventListener('click', function () {
                if (busy || s.code === currentSubjectCode) return;
                selectSubject(s.code);
            });
            subjectChipsEl.appendChild(chip);
        });
    }

    function appendInlineFormatting(parent, text) {
        const parts = String(text).split(/(\*\*[^*]+\*\*)/g);
        parts.forEach(function (part) {
            if (/^\*\*[^*]+\*\*$/.test(part)) {
                const strong = document.createElement('strong');
                strong.textContent = part.slice(2, -2);
                parent.appendChild(strong);
            } else if (part) {
                parent.appendChild(document.createTextNode(part));
            }
        });
    }

    function renderAiResponse(text) {
        const content = document.createElement('div');
        content.className = 'ai-rich-content';
        const lines = String(text || '').replace(/\r\n?/g, '\n').split('\n');
        let paragraph = [];
        let activeList = null;
        let activeListType = null;

        function flushParagraph() {
            if (!paragraph.length) return;
            const p = document.createElement('p');
            appendInlineFormatting(p, paragraph.join(' '));
            content.appendChild(p);
            paragraph = [];
        }

        function closeList() {
            if (activeList) {
                content.appendChild(activeList);
                activeList = null;
                activeListType = null;
            }
        }

        lines.forEach(function (rawLine) {
            const line = rawLine.trim();
            if (!line) {
                flushParagraph();
                closeList();
                return;
            }

            const heading = line.match(/^#{1,3}\s+(.+)$/);
            if (heading) {
                flushParagraph();
                closeList();
                const h = document.createElement('h6');
                appendInlineFormatting(h, heading[1]);
                content.appendChild(h);
                return;
            }

            const numbered = line.match(/^\d+[.)]\s+(.+)$/);
            const bullet = line.match(/^[-*•]\s+(.+)$/);
            if (numbered || bullet) {
                flushParagraph();
                const listType = numbered ? 'ol' : 'ul';
                if (!activeList || activeListType !== listType) {
                    closeList();
                    activeList = document.createElement(listType);
                    activeListType = listType;
                }
                const li = document.createElement('li');
                appendInlineFormatting(li, (numbered || bullet)[1]);
                activeList.appendChild(li);
                return;
            }

            closeList();
            paragraph.push(line);
        });

        flushParagraph();
        closeList();
        return content;
    }

    function selectSubject(code) {
        currentSubjectCode = code;
        loadHistory(); // renders the chat's turns itself, incl. any resource/file cards
        workspaceGrid.classList.remove('context-open');
        contextPanel.hidden = true;
        contextToggle.setAttribute('aria-expanded', 'false');
        contextToggle.querySelector('i').className = 'fas fa-bars me-2';
        contextToggle.querySelector('span').textContent = 'Student Context';
        renderRiskSummary(currentAttendance, currentRiskType);
        updateDraftPreview();
        renderSubjectChips();
        input.placeholder = 'Message the AI about ' + currentSubjectName() + '...';
        input.disabled = false;
        sendBtn.disabled = false;
    }

    function addBubble(text, sender, opts) {
        opts = opts || {};
        const row = document.createElement('div');
        row.className = 'ai-chat-row ' + sender;

        if (sender === 'bot') {
            const icon = document.createElement('div');
            icon.className = 'ai-chat-icon';
            icon.innerHTML = '<i class="fas fa-robot"></i>';
            row.appendChild(icon);
        }

        const col = document.createElement('div');
        col.className = 'ai-chat-col';

        const bubble = document.createElement('div');
        bubble.className = 'ai-chat-bubble ' + sender;
        if (sender === 'bot') {
            bubble.appendChild(renderAiResponse(text));
        } else {
            bubble.textContent = text;
        }
        col.appendChild(bubble);
        const timestamp = document.createElement('div');
        timestamp.className = 'ai-message-time';
        timestamp.textContent = formatMessageTime(opts.timestamp);
        col.appendChild(timestamp);

        if (sender === 'bot' && opts.allowSend) {
            const actions = document.createElement('div');
            actions.className = 'ai-bubble-actions';
            const sendLink = document.createElement('button');
            sendLink.type = 'button';
            sendLink.className = 'ai-send-to-student-btn';
            sendLink.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Send to Student';
            sendLink.addEventListener('click', function () {
                sendToStudent(text, sendLink);
            });
            actions.appendChild(sendLink);
            col.appendChild(actions);
        }

        row.appendChild(col);
        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function sendToStudent(text, btnEl) {
        btnEl.disabled = true;
        btnEl.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Sending...';

        const formData = new FormData();
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('student_id', currentStudentId);
        formData.append('subject_code', currentSubjectCode);
        formData.append('message', text);
        formData.append('ai_draft', text);
        formData.append('source', 'ai');

        fetch(SEND_URL, { method: 'POST', body: formData })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    const confirm = document.createElement('span');
                    confirm.className = 'ai-sent-confirm';
                    confirm.innerHTML = '<i class="fas fa-check-circle me-1"></i>Sent to student';
                    btnEl.replaceWith(confirm);
                } else {
                    btnEl.disabled = false;
                    btnEl.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Send to Student';
                    addBubble(data.error || 'Could not send that message. Please try again.', 'bot');
                }
            })
            .catch(function () {
                btnEl.disabled = false;
                btnEl.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Send to Student';
                addBubble('Something went wrong sending that message. Please try again.', 'bot');
            });
    }

    let progressTimers = [];

    // A lightweight, purely cosmetic progress sequence — the real request
    // is one round trip (no server-sent progress events), but content
    // generation + document rendering genuinely can take a few seconds, so
    // this keeps the modal feeling alive instead of a single frozen
    // "typing..." for the whole wait. Cancelled the moment a response
    // (or error) actually arrives.
    function addTyping() {
        const row = document.createElement('div');
        row.className = 'ai-chat-row bot';
        row.id = 'aiTypingRow';

        const icon = document.createElement('div');
        icon.className = 'ai-chat-icon';
        icon.innerHTML = '<i class="fas fa-robot"></i>';
        row.appendChild(icon);

        const bubble = document.createElement('div');
        bubble.className = 'ai-chat-bubble bot';
        bubble.innerHTML = '<span class="ai-typing-dots"><span></span><span></span><span></span></span><span class="ai-progress-label" id="aiProgressLabel"></span>';
        row.appendChild(bubble);

        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;

        const label = document.getElementById('aiProgressLabel');
        const stages = ['AI is thinking...', 'Creating your content...', 'Formatting document...', 'Almost ready...'];
        let i = 0;
        if (label) label.textContent = stages[0];
        stages.slice(1).forEach(function (text, idx) {
            progressTimers.push(window.setTimeout(function () {
                const el = document.getElementById('aiProgressLabel');
                if (el) el.textContent = text;
            }, 1400 * (idx + 1)));
        });
    }

    function removeTyping() {
        progressTimers.forEach(function (t) { window.clearTimeout(t); });
        progressTimers = [];
        const el = document.getElementById('aiTypingRow');
        if (el) el.remove();
    }

    // ---- "+" button: attach a photo / PDF / Word / text file to the NEXT message
    // (used for that one request only, never stored). ----
    let pendingAttachment = null; // File
    let attachThumbUrl = '';
    const ATTACH_MAX = 4 * 1024 * 1024;
    const ATTACH_EXT = /\.(png|jpe?g|webp|pdf|docx|txt|md|csv)$/i;
    function attachSizeLabel(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
    function attachEsc(t) { return String(t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function renderAttachment() {
        if (!pendingAttachment) { attachRow.classList.add('d-none'); attachRow.innerHTML = ''; return; }
        const f = pendingAttachment;
        const isImg = /^image\//.test(f.type);
        const icon = /\.pdf$/i.test(f.name) ? 'fa-file-pdf' : (/\.docx$/i.test(f.name) ? 'fa-file-word' : 'fa-file-lines');
        attachRow.classList.remove('d-none');
        attachRow.innerHTML = '<span class="ai-attach-chip"><span class="ai-attach-thumb">' + (isImg && attachThumbUrl ? '<img alt="" src="' + attachThumbUrl + '">' : '<i class="fas ' + icon + '"></i>') + '</span>'
            + '<span class="ai-attach-name" title="' + attachEsc(f.name) + '">' + attachEsc(f.name) + '</span><span class="ai-attach-size">' + attachSizeLabel(f.size) + '</span>'
            + '<button type="button" aria-label="Remove attachment" title="Remove"><i class="fas fa-xmark"></i></button></span>';
        attachRow.querySelector('button').addEventListener('click', function () { clearAttachment(); input.focus(); });
    }
    function clearAttachment() {
        pendingAttachment = null;
        if (attachThumbUrl) { try { URL.revokeObjectURL(attachThumbUrl); } catch (e) {} attachThumbUrl = ''; }
        attachFile.value = '';
        renderAttachment();
    }
    function attachmentError(msg) {
        attachRow.classList.remove('d-none');
        attachRow.innerHTML = '<span class="ai-attach-error"><i class="fas fa-circle-exclamation me-1"></i>' + attachEsc(msg) + '</span>';
        window.setTimeout(function () { if (!pendingAttachment) renderAttachment(); }, 4500);
    }
    function setAttachment(file) {
        if (!file) return;
        if (!ATTACH_EXT.test(file.name)) { attachmentError('That file type is not supported. Use an image, PDF, Word (.docx) or text file.'); return; }
        if (file.size > ATTACH_MAX) { attachmentError('That file is too large. Please choose one under 4 MB.'); return; }
        if (file.size <= 0) { attachmentError('That file is empty.'); return; }
        if (attachThumbUrl) { try { URL.revokeObjectURL(attachThumbUrl); } catch (e) {} attachThumbUrl = ''; }
        if (/^image\//.test(file.type)) { try { attachThumbUrl = URL.createObjectURL(file); } catch (e) { attachThumbUrl = ''; } }
        pendingAttachment = file;
        renderAttachment();
        input.focus();
    }
    // The "+" is usable exactly when the message box is (a subject is chosen and no request is running).
    function syncAttachBtn() { attachBtn.disabled = input.disabled; }
    new MutationObserver(syncAttachBtn).observe(input, { attributes: true, attributeFilter: ['disabled'] });
    syncAttachBtn();
    attachBtn.addEventListener('click', function () { if (!input.disabled) attachFile.click(); });
    attachFile.addEventListener('change', function () { setAttachment(attachFile.files && attachFile.files[0]); });
    input.addEventListener('paste', function (e) {
        const files = e.clipboardData && e.clipboardData.files;
        if (files && files.length && /^image\//.test(files[0].type) && !input.disabled) { e.preventDefault(); setAttachment(files[0]); }
    });
    function addAttachmentNote(name) {
        const bubbles = messagesEl.querySelectorAll('.ai-chat-row.user .ai-chat-bubble');
        const last = bubbles[bubbles.length - 1];
        if (!last) return;
        const n = document.createElement('div');
        n.className = 'ai-msg-attach';
        n.innerHTML = '<i class="fas fa-paperclip"></i><span>' + attachEsc(name) + '</span>';
        last.appendChild(n);
    }

    // Guards against duplicate/rapid-fire calls: the input + send button
    // (and starter/subject chips) are disabled for the whole round trip.
    function sendMessage(message, extra) {
        if (!currentSubjectCode || busy) return;
        busy = true;
        input.disabled = true;
        sendBtn.disabled = true;
        removeEmptyHint();

        // A file attached with "+" travels with this one message only.
        const attachedFile = (extra && extra.regenerate) ? null : pendingAttachment;
        if (attachedFile && !message) message = 'Please review the attached file.';

        if (message) {
            addBubble(message, 'user');
            if (attachedFile) addAttachmentNote(attachedFile.name);
            history.push({ role: 'teacher', text: message + (attachedFile ? ' [attached file: ' + attachedFile.name + ']' : ''), timestamp: new Date().toISOString() });
            saveHistory();
        }
        addTyping();

        const formData = new FormData();
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('student_id', currentStudentId);
        formData.append('subject_code', currentSubjectCode);
        formData.append('message', message || '');
        formData.append('language', languageSelect ? languageSelect.value : 'auto');
        if (attachedFile) formData.append('attachment', attachedFile, attachedFile.name);
        // Only role/text go to the server — history keeps richer local-only
        // fields (resource/reply/allowSend, for reopening from History
        // later) that would otherwise bloat every request's prompt size.
        formData.append('history', JSON.stringify(history.map(function (turn) {
            return { role: turn.role, text: turn.text };
        })));
        formData.append('current_draft', currentDraft ? JSON.stringify(currentDraft) : '');
        // Regenerate on a PDF / Word / Google Form card: same format again,
        // fresh content (decided server-side in chatbot.php).
        if (extra && extra.output_format) {
            formData.append('output_format', extra.output_format);
            formData.append('regenerate', extra.regenerate ? '1' : '0');
        }

        if (attachedFile) clearAttachment();
        fetch(CHATBOT_URL, { method: 'POST', body: formData })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                removeTyping();
                if (!data.success) {
                    addBubble(data.error || 'AI generation is temporarily unavailable. Please try again.', 'bot');
                    return;
                }

                if (data.response_type === 'resource') {
                    // `text` stays a short label — that's what's sent back
                    // to the server as this turn's history (see the
                    // CURRENT DRAFT note in gemini_service.php). The full
                    // content lives in `resource`/`reply` below, which are
                    // local-only (stripped before the history is POSTed)
                    // and exist purely so reopening this chat from History
                    // can rebuild the actual resource/file card.
                    history.push({
                        role: 'ai',
                        text: 'Generated ' + (data.resource_type_label || 'resource') + ': ' + data.title,
                        timestamp: new Date().toISOString(),
                        reply: data.reply || '',
                        resource: {
                            resource_type: data.resource_type,
                            resource_type_label: data.resource_type_label,
                            title: data.title,
                            student_content: data.student_content,
                            teacher_answer_key: data.teacher_answer_key,
                            quiz_questions: Array.isArray(data.quiz_questions) ? data.quiz_questions : null,
                            output_format: data.output_format || null,
                            resource_id: data.resource_id || null,
                            original_filename: data.original_filename || null,
                            file_size: data.file_size || null,
                            file_error: data.file_error || null,
                            gform: data.gform || null,
                        },
                    });
                    saveHistory();

                    currentDraft = {
                        resource_type: data.resource_type,
                        resource_type_label: data.resource_type_label,
                        title: data.title,
                        student_content: data.student_content,
                        teacher_answer_key: data.teacher_answer_key,
                        quiz_questions: Array.isArray(data.quiz_questions) ? data.quiz_questions : null,
                    };
                    updateDraftPreview();

                    if (data.reply) addBubble(data.reply, 'bot');

                    if (data.resource_id && data.output_format === 'gform' && data.gform) {
                        renderGformCard(data.gform, currentDraft);
                    } else if (data.resource_id && data.output_format) {
                        renderFileCard({
                            resource_id: data.resource_id,
                            title: data.title,
                            resource_type_label: data.resource_type_label,
                            output_format: data.output_format,
                            original_filename: data.original_filename,
                            file_size: data.file_size,
                        });
                    } else {
                        renderResourceCard(currentDraft, data.file_error || null);
                    }
                } else {
                    // A clarification/suggestion is not a finished student
                    // message. Keep Send to Student hidden until the teacher
                    // confirms the plan and the AI returns a resource/draft.
                    const needsConfirmation = /[?؟]\s*$/.test(String(data.reply || ''));
                    addBubble(data.reply, 'bot', { allowSend: !needsConfirmation });
                    currentSendText = data.reply || '';
                    sendCurrentBtn.disabled = needsConfirmation || !currentSendText;
                    history.push({ role: 'ai', text: data.reply, timestamp: new Date().toISOString(), allowSend: !needsConfirmation });
                    saveHistory();
                }
            })
            .catch(function () {
                removeTyping();
                addBubble('Something went wrong. Please try again.', 'bot');
            })
            .finally(function () {
                busy = false;
                input.disabled = false;
                sendBtn.disabled = false;
                input.focus();
            });
    }

    // ---- Resource content-preview card: shown when a resource was
    // generated but no file format was requested yet (Edit / Regenerate /
    // Generate PDF / Generate Word / Send to Student). ----
    function renderResourceCard(draft, fileError) {
        const row = document.createElement('div');
        row.className = 'ai-chat-row bot';

        const icon = document.createElement('div');
        icon.className = 'ai-chat-icon';
        icon.innerHTML = '<i class="fas fa-robot"></i>';
        row.appendChild(icon);

        const col = document.createElement('div');
        col.className = 'ai-chat-col';

        const card = document.createElement('div');
        card.className = 'ai-resource-card';

        const head = document.createElement('div');
        head.className = 'ai-resource-card-head';
        head.innerHTML = '<i class="fas fa-file-lines"></i>' +
            '<span class="ai-resource-card-title"></span>' +
            '<span class="ai-resource-card-type"></span>';
        head.querySelector('.ai-resource-card-title').textContent = draft.title;
        head.querySelector('.ai-resource-card-type').textContent = draft.resource_type_label || '';
        card.appendChild(head);

        const body = document.createElement('div');
        body.className = 'ai-resource-card-body';
        body.textContent = draft.student_content;
        card.appendChild(body);

        if (fileError) {
            const note = document.createElement('div');
            note.className = 'ai-resource-card-note';
            note.innerHTML = '<i class="fas fa-triangle-exclamation me-1"></i>' + fileError;
            card.appendChild(note);
        }

        const actions = document.createElement('div');
        actions.className = 'ai-resource-card-actions';

        const editBtn = actionButton('Edit', 'fa-pen');
        const regenBtn = actionButton('Regenerate', 'fa-rotate');
        const hasQuizQuestions = GOOGLE_FORMS_READY && Array.isArray(draft.quiz_questions) && draft.quiz_questions.length > 0
            && GFORM_ELIGIBLE_TYPES.indexOf(draft.resource_type) !== -1;
        currentSendText = hasQuizQuestions ? '' : (draft.student_content || '');
        sendCurrentBtn.disabled = !currentSendText;
        const gformBtn = hasQuizQuestions ? actionButton('Create Google Form Quiz', 'fa-square-poll-vertical', 'btn-primary') : null;
        const pdfBtn = actionButton('Generate PDF', 'fa-file-pdf');
        const wordBtn = actionButton('Generate Word', 'fa-file-word');
        // A quiz can NEVER go out as a plain chat message: the student would
        // get a wall of unanswerable text instead of something they can
        // actually open and submit, and the full question set easily runs
        // past send_to_student.php's 2000-character limit (that mismatch is
        // exactly what used to surface as a confusing "Message is too long."
        // error here). For a quiz-eligible draft, "Create Google Form Quiz"
        // above is the ONLY send path — its own card (renderGformCard) gets
        // the real "Send to Student" button, which sends the short Form
        // link instead of the raw questions.
        const sendBtnEl = hasQuizQuestions ? null : actionButton('Send to Student', 'fa-paper-plane', 'btn-primary');
        actions.appendChild(editBtn);
        actions.appendChild(regenBtn);
        if (gformBtn) actions.appendChild(gformBtn);
        actions.appendChild(pdfBtn);
        actions.appendChild(wordBtn);
        if (sendBtnEl) actions.appendChild(sendBtnEl);
        card.appendChild(actions);

        // Which REAL Grade Management assessment item this quiz's score will
        // sync into. An AI quiz for an At-Risk student is a MAKE-UP /
        // RECOVERY assessment — e.g. the student missed the class's
        // "Quiz 3" — so the default (and recommended) path is picking an
        // EXISTING item from Manage Assessment Items; the quiz's score then
        // lands on that exact item, exactly like everyone else's Quiz 3
        // score, instead of becoming a brand-new item only this one
        // student has. "Brand-new assessment" stays available as a
        // fallback for when nothing suitable exists yet in Manage Items.
        // A Google Form quiz for an At-Risk student is a RECOVERY / MAKE-UP
        // assessment — e.g. the student missed the class's "Quiz 3" — never
        // a replacement for the whole Term Grade. So the teacher must map
        // it to that EXISTING Assessment Item; the quiz's score then lands
        // on that exact item, exactly like everyone else's Quiz 3 score,
        // and every other preserved WW/PT/EX score is left untouched. There
        // is deliberately no "create a new item" option here — an item to
        // recover has to already exist in Manage Assessment Items first.
        if (hasQuizQuestions) {
            const pickerWrap = document.createElement('div');
            pickerWrap.className = 'ai-assessment-picker';
            pickerWrap.style.cssText = 'margin-top:10px;padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#F8FAFC;';

            const pickerLabel = document.createElement('div');
            pickerLabel.style.cssText = 'font-size:12px;font-weight:600;color:#14213D;margin-bottom:6px;';
            pickerLabel.textContent = 'Recover Missing Assessment (required)';
            pickerWrap.appendChild(pickerLabel);

            const termSelect = document.createElement('select');
            termSelect.style.cssText = 'font-size:12.5px;padding:5px 8px;border:1px solid #d0d5dd;border-radius:6px;width:100%;';
            [['', 'Term…'], ['Term 1', 'Term 1'], ['Term 2', 'Term 2'], ['Term 3', 'Term 3']].forEach(function (pair) {
                const opt = document.createElement('option');
                opt.value = pair[0];
                opt.textContent = pair[1];
                termSelect.appendChild(opt);
            });
            pickerWrap.appendChild(termSelect);

            const itemSelect = document.createElement('select');
            itemSelect.style.cssText = 'font-size:12.5px;padding:5px 8px;border:1px solid #d0d5dd;border-radius:6px;width:100%;margin-top:8px;';
            itemSelect.disabled = true;
            const itemPlaceholder = document.createElement('option');
            itemPlaceholder.value = '';
            itemPlaceholder.textContent = 'Select a term first…';
            itemSelect.appendChild(itemPlaceholder);
            pickerWrap.appendChild(itemSelect);

            const itemHint = document.createElement('div');
            itemHint.style.cssText = 'font-size:11px;color:#98A2B3;margin-top:3px;';
            itemHint.textContent = 'The quiz score is saved onto whichever existing item you pick here — every other item\'s score is preserved untouched. No item to recover yet? Add it first in Manage Assessment Items.';
            pickerWrap.appendChild(itemHint);

            const gformOnlyHint = document.createElement('div');
            gformOnlyHint.style.cssText = 'font-size:11px;color:#98A2B3;margin-top:6px;';
            gformOnlyHint.textContent = 'Quizzes are only sent as an interactive Google Form the student can open and answer — pick a Term and item, then use "Create Google Form Quiz" above.';
            pickerWrap.appendChild(gformOnlyHint);

            card.appendChild(pickerWrap);

            function updateGformBtnState() {
                if (!gformBtn) return;
                const ready = !!(termSelect.value && itemSelect.value);
                gformBtn.disabled = !ready;
                gformBtn.style.opacity = ready ? '' : '0.5';
            }

            function loadItemsForTerm() {
                const term = termSelect.value;
                itemSelect.innerHTML = '';
                const placeholder = document.createElement('option');
                placeholder.value = '';
                if (!term) {
                    placeholder.textContent = 'Select a term first…';
                    itemSelect.appendChild(placeholder);
                    itemSelect.disabled = true;
                    updateGformBtnState();
                    return;
                }
                placeholder.textContent = 'Loading…';
                itemSelect.appendChild(placeholder);
                itemSelect.disabled = true;
                updateGformBtnState();

                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('student_id', currentStudentId);
                formData.append('subject_code', currentSubjectCode);
                formData.append('term', term);

                fetch(LIST_ASSESSMENT_ITEMS_URL, { method: 'POST', body: formData })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (termSelect.value !== term) return; // teacher already changed term again
                        itemSelect.innerHTML = '';
                        const opt0 = document.createElement('option');
                        opt0.value = '';
                        if (!data.success) {
                            opt0.textContent = 'Could not load items';
                            itemSelect.appendChild(opt0);
                            itemSelect.disabled = true;
                            updateGformBtnState();
                            return;
                        }
                        const items = data.items || [];
                        if (!items.length) {
                            opt0.textContent = 'No items yet for this term — add one in Manage Assessment Items first';
                            itemSelect.appendChild(opt0);
                            itemSelect.disabled = true;
                            updateGformBtnState();
                            return;
                        }
                        opt0.textContent = 'Select which assessment this recovers…';
                        itemSelect.appendChild(opt0);
                        const groups = {};
                        items.forEach(function (it) {
                            if (!groups[it.category_label]) {
                                groups[it.category_label] = document.createElement('optgroup');
                                groups[it.category_label].label = it.category_label;
                                itemSelect.appendChild(groups[it.category_label]);
                            }
                            const opt = document.createElement('option');
                            opt.value = String(it.item_id);
                            const hps = (it.highest_score % 1 === 0) ? it.highest_score.toFixed(0) : it.highest_score;
                            const status = it.has_score ? ('Current: ' + it.current_score + '/' + hps) : 'Missing score';
                            let label = it.item_name + (it.assessment_subtype ? ' [' + it.assessment_subtype + ']' : '') + ' — ' + hps + ' pts — ' + status;
                            opt.textContent = label;
                            opt.dataset.hasScore = it.has_score ? '1' : '0';
                            groups[it.category_label].appendChild(opt);
                        });
                        itemSelect.disabled = false;
                        // Missing-score items are the common case (recovery for a
                        // missed assessment) — default the picker to the first one
                        // so the teacher doesn't have to hunt for it.
                        const firstMissing = items.find(function (it) { return !it.has_score; });
                        if (firstMissing) itemSelect.value = String(firstMissing.item_id);
                        updateGformBtnState();
                    })
                    .catch(function () {
                        itemSelect.innerHTML = '';
                        const opt0 = document.createElement('option');
                        opt0.value = '';
                        opt0.textContent = 'Could not load items';
                        itemSelect.appendChild(opt0);
                        itemSelect.disabled = true;
                        updateGformBtnState();
                    });
            }

            itemSelect.addEventListener('change', updateGformBtnState);
            termSelect.addEventListener('change', loadItemsForTerm);

            draft._termSelect = termSelect;
            draft._itemSelect = itemSelect;
        }

        // A PDF/Word activity (never sent as a Google Form) can ALSO
        // optionally recover an existing missing assessment -- e.g. a
        // "Missing: Act 2 (Written Works)" recovery worksheet -- so a
        // confirmed score on the student's later attached-file submission
        // (see modules/ai/resources.php) syncs automatically into Grade
        // Management and clears it from Missing Assessments, exactly like
        // the Google Form quiz path above. UNLIKE that path, linking here
        // is OPTIONAL -- not every PDF/Word activity is a graded recovery
        // for one specific item (some are just extra practice) -- so
        // "No gradebook item" is always a valid, selectable choice and
        // never blocks Generate PDF/Word.
        if (!hasQuizQuestions) {
            const pdfPickerWrap = document.createElement('div');
            pdfPickerWrap.className = 'ai-assessment-picker';
            pdfPickerWrap.style.cssText = 'margin-top:10px;padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#F8FAFC;';

            const pdfPickerLabel = document.createElement('div');
            pdfPickerLabel.style.cssText = 'font-size:12px;font-weight:600;color:#14213D;margin-bottom:6px;';
            pdfPickerLabel.textContent = 'Link to Gradebook Item (optional)';
            pdfPickerWrap.appendChild(pdfPickerLabel);

            const pdfTermSelect = document.createElement('select');
            pdfTermSelect.style.cssText = 'font-size:12.5px;padding:5px 8px;border:1px solid #d0d5dd;border-radius:6px;width:100%;';
            [['', 'Term…'], ['Term 1', 'Term 1'], ['Term 2', 'Term 2'], ['Term 3', 'Term 3']].forEach(function (pair) {
                const opt = document.createElement('option');
                opt.value = pair[0];
                opt.textContent = pair[1];
                pdfTermSelect.appendChild(opt);
            });
            pdfPickerWrap.appendChild(pdfTermSelect);

            const pdfItemSelect = document.createElement('select');
            pdfItemSelect.style.cssText = 'font-size:12.5px;padding:5px 8px;border:1px solid #d0d5dd;border-radius:6px;width:100%;margin-top:8px;';
            pdfItemSelect.disabled = true;
            const pdfItemPlaceholder = document.createElement('option');
            pdfItemPlaceholder.value = '';
            pdfItemPlaceholder.textContent = 'Select a term first…';
            pdfItemSelect.appendChild(pdfItemPlaceholder);
            pdfPickerWrap.appendChild(pdfItemSelect);

            const pdfItemHint = document.createElement('div');
            pdfItemHint.style.cssText = 'font-size:11px;color:#98A2B3;margin-top:3px;';
            pdfItemHint.textContent = 'Optional: pick which existing assessment this activity recovers, so a confirmed score on the student\'s attached-file submission is recorded automatically and clears from Missing Assessments. Leave as "No gradebook item" for a plain practice resource.';
            pdfPickerWrap.appendChild(pdfItemHint);

            card.appendChild(pdfPickerWrap);

            function loadPdfItemsForTerm() {
                const term = pdfTermSelect.value;
                pdfItemSelect.innerHTML = '';
                if (!term) {
                    const ph = document.createElement('option');
                    ph.value = '';
                    ph.textContent = 'Select a term first…';
                    pdfItemSelect.appendChild(ph);
                    pdfItemSelect.disabled = true;
                    return;
                }
                const loading = document.createElement('option');
                loading.value = '';
                loading.textContent = 'Loading…';
                pdfItemSelect.appendChild(loading);
                pdfItemSelect.disabled = true;

                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('student_id', currentStudentId);
                formData.append('subject_code', currentSubjectCode);
                formData.append('term', term);

                fetch(LIST_ASSESSMENT_ITEMS_URL, { method: 'POST', body: formData })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (pdfTermSelect.value !== term) return; // teacher already changed term again
                        pdfItemSelect.innerHTML = '';
                        const skipOpt = document.createElement('option');
                        skipOpt.value = '';
                        skipOpt.textContent = 'No gradebook item (just a practice resource)';
                        pdfItemSelect.appendChild(skipOpt);
                        if (!data.success || !(data.items || []).length) {
                            pdfItemSelect.disabled = false; // "No gradebook item" is still a valid choice
                            return;
                        }
                        const groups = {};
                        data.items.forEach(function (it) {
                            if (!groups[it.category_label]) {
                                groups[it.category_label] = document.createElement('optgroup');
                                groups[it.category_label].label = it.category_label;
                                pdfItemSelect.appendChild(groups[it.category_label]);
                            }
                            const opt = document.createElement('option');
                            opt.value = String(it.item_id);
                            const hps = (it.highest_score % 1 === 0) ? it.highest_score.toFixed(0) : it.highest_score;
                            opt.textContent = it.item_name + ' — ' + hps + ' pts — ' + (it.has_score ? ('Current: ' + it.current_score + '/' + hps) : 'Missing score');
                            groups[it.category_label].appendChild(opt);
                        });
                        pdfItemSelect.disabled = false;
                        const firstMissing = data.items.find(function (it) { return !it.has_score; });
                        if (firstMissing) pdfItemSelect.value = String(firstMissing.item_id);
                    })
                    .catch(function () {
                        pdfItemSelect.innerHTML = '<option value="">Could not load items</option>';
                        pdfItemSelect.disabled = true;
                    });
            }

            pdfTermSelect.addEventListener('change', loadPdfItemsForTerm);

            draft._pdfTermSelect = pdfTermSelect;
            draft._pdfItemSelect = pdfItemSelect;
        }

        // Teacher's own choice, per quiz: "one attempt only" actually closes
        // the Google Form to further responses (via Google's own
        // setPublishSettings API — see googleFormsSetAcceptingResponses())
        // the instant the student's first submission is recorded. Read by
        // createQuizFormFromDraft() below at click time — kept as a plain
        // DOM checkbox rather than more draft state, since it only matters
        // for the one moment the teacher clicks "Create Google Form Quiz".
        if (hasQuizQuestions) {
            const oneAttemptRow = document.createElement('label');
            oneAttemptRow.className = 'ai-one-attempt-toggle';
            oneAttemptRow.style.cssText = 'display:flex;align-items:center;gap:6px;font-size:12.5px;color:#667085;margin-top:8px;cursor:pointer;';
            const oneAttemptCheckbox = document.createElement('input');
            oneAttemptCheckbox.type = 'checkbox';
            oneAttemptCheckbox.style.cssText = 'margin:0;';
            const oneAttemptText = document.createElement('span');
            oneAttemptText.textContent = 'Allow only one attempt (Form closes automatically once the student submits)';
            oneAttemptRow.appendChild(oneAttemptCheckbox);
            oneAttemptRow.appendChild(oneAttemptText);
            card.appendChild(oneAttemptRow);
            draft._oneAttemptCheckbox = oneAttemptCheckbox;
        }

        editBtn.addEventListener('click', function () {
            if (body.querySelector('textarea')) return; // already editing
            const textarea = document.createElement('textarea');
            textarea.value = draft.student_content;
            body.innerHTML = '';
            body.appendChild(textarea);
            const saveRow = document.createElement('div');
            saveRow.className = 'mt-2';
            const saveBtn = actionButton('Save', 'fa-check', 'btn-primary');
            saveRow.appendChild(saveBtn);
            body.appendChild(saveRow);
            saveBtn.addEventListener('click', function () {
                draft.student_content = textarea.value;
                if (currentDraft === draft) currentDraft.student_content = textarea.value;
                body.innerHTML = '';
                body.textContent = draft.student_content;
            });
        });

        regenBtn.addEventListener('click', function () {
            if (busy) return;
            sendMessage('Please regenerate this, with a fresh version.');
        });

        pdfBtn.addEventListener('click', function () { generateFileFromDraft(draft, 'pdf', pdfBtn); });
        wordBtn.addEventListener('click', function () { generateFileFromDraft(draft, 'docx', wordBtn); });
        if (gformBtn) {
            gformBtn.addEventListener('click', function () { createQuizFormFromDraft(draft, gformBtn); });
        }

        if (sendBtnEl) {
            sendBtnEl.addEventListener('click', function () {
                showInlineConfirm(actions, 'Send this ' + (needsFileSend(draft) ? 'quiz/activity (as a PDF)' : 'content') + ' to ' + currentStudentName + '?', function (confirmBtn) {
                    sendDraftToStudent(draft, confirmBtn);
                });
            });
        }

        col.appendChild(card);
        row.appendChild(col);
        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    // ---- Generated file card: shown once a real PDF/DOCX exists
    // (View / Download / Send to Student). ----
    function renderFileCard(res) {
        const row = document.createElement('div');
        row.className = 'ai-chat-row bot';

        const icon = document.createElement('div');
        icon.className = 'ai-chat-icon';
        icon.innerHTML = '<i class="fas fa-robot"></i>';
        row.appendChild(icon);

        const col = document.createElement('div');
        col.className = 'ai-chat-col';

        const card = document.createElement('div');
        card.className = 'ai-file-card';

        const isDocx = res.output_format === 'docx';
        const head = document.createElement('div');
        head.className = 'ai-file-card-head';
        head.innerHTML =
            '<i class="fas ' + (isDocx ? 'fa-file-word docx' : 'fa-file-pdf pdf') + ' ai-file-icon"></i>' +
            '<div><div class="ai-file-name"></div><div class="ai-file-meta"></div>' +
            '<div class="ai-file-generated-badge"><i class="fas fa-circle-check"></i> Generated successfully</div></div>';
        head.querySelector('.ai-file-name').textContent = res.original_filename || res.title;
        const sizeKb = res.file_size ? Math.max(1, Math.round(res.file_size / 1024)) + ' KB' : '';
        head.querySelector('.ai-file-meta').textContent = [res.resource_type_label, isDocx ? 'Microsoft Word' : 'PDF', sizeKb, res.link_item_id ? 'Linked to gradebook item (' + res.link_category + ', ' + res.link_term + ')' : null].filter(Boolean).join(' • ');
        card.appendChild(head);

        const actions = document.createElement('div');
        actions.className = 'ai-file-card-actions';
        const viewBtn = actionLink('Preview / Open', 'fa-eye', VIEW_URL + '?id=' + res.resource_id);
        const downloadBtn = actionLink('Download', 'fa-download', DOWNLOAD_URL + '?id=' + res.resource_id);
        const regenBtn = actionButton('Regenerate', 'fa-rotate');
        const sendBtnEl = actionButton('Send to Student', 'fa-paper-plane', 'btn-primary');
        actions.appendChild(viewBtn);
        actions.appendChild(downloadBtn);
        actions.appendChild(regenBtn);
        actions.appendChild(sendBtnEl);
        card.appendChild(actions);

        // Same format, fresh content: the server re-generates the material
        // (using the current draft as context) and rebuilds this file type.
        regenBtn.addEventListener('click', function () {
            if (busy) return;
            sendMessage('Please regenerate this, with a fresh version.', { output_format: res.output_format, regenerate: true });
        });

        sendBtnEl.addEventListener('click', function () {
            showInlineConfirm(actions, 'Send this resource to ' + currentStudentName + '?', function (confirmBtn) {
                sendResourceToStudent(res, confirmBtn);
            });
        });

        col.appendChild(card);
        row.appendChild(col);
        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function actionButton(label, icon, extraClass) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline-secondary ' + (extraClass || '');
        btn.innerHTML = '<i class="fas ' + icon + ' me-1"></i>' + label;
        return btn;
    }

    function actionLink(label, icon, href) {
        const a = document.createElement('a');
        a.href = href;
        a.target = '_blank';
        a.rel = 'noopener';
        a.className = 'btn btn-outline-secondary';
        a.innerHTML = '<i class="fas ' + icon + ' me-1"></i>' + label;
        return a;
    }

    // Inline "are you sure" — replaces the action row's content with a
    // confirm/cancel prompt instead of a second stacked modal or a native
    // confirm() dialog (which would block the whole page).
    //
    // IMPORTANT: onConfirm receives the freshly-created okBtn — the original
    // "Send to Student" button that was clicked to get here was just
    // removed from the DOM (actionsEl.innerHTML = '' below), so a callback
    // that closed over that original button reference would end up calling
    // .disabled/.innerHTML on a detached, invisible node, and later
    // btnEl.closest('.ai-confirm-box') would find nothing (the original
    // button was never inside this newly-created box) and throw — silently
    // breaking the "Sent to student" confirmation even when the send
    // itself succeeded server-side. Always use the button passed into
    // onConfirm(), never the one closed over from outside.
    function showInlineConfirm(actionsEl, message, onConfirm) {
        const original = actionsEl.innerHTML;
        actionsEl.innerHTML = '';
        const box = document.createElement('div');
        box.className = 'ai-confirm-box';
        const text = document.createElement('span');
        text.textContent = message;
        const cancelBtn = actionButton('Cancel', 'fa-xmark');
        const okBtn = actionButton('Send to Student', 'fa-paper-plane', 'btn-primary');
        box.appendChild(text);
        box.appendChild(cancelBtn);
        box.appendChild(okBtn);
        actionsEl.appendChild(box);

        cancelBtn.addEventListener('click', function () {
            actionsEl.innerHTML = original;
        });
        okBtn.addEventListener('click', function () {
            onConfirm(okBtn);
        });
    }

    function generateFileFromDraft(draft, format, btnEl) {
        if (busy) return;
        const originalHtml = btnEl.innerHTML;
        btnEl.disabled = true;
        btnEl.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Generating...';

        const formData = new FormData();
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('student_id', currentStudentId);
        formData.append('subject_code', currentSubjectCode);
        formData.append('resource_type', draft.resource_type || 'other');
        formData.append('title', draft.title || '');
        formData.append('student_content', draft.student_content || '');
        formData.append('teacher_answer_key', draft.teacher_answer_key || '');
        formData.append('output_format', format);
        formData.append('audience', 'student');
        // Optional gradebook link (see the picker added just above this
        // draft's action buttons) -- only sent when the teacher actually
        // picked a term AND an item; "No gradebook item"/blank term simply
        // leaves this resource unlinked, exactly like before this existed.
        if (draft._pdfTermSelect && draft._pdfItemSelect && draft._pdfTermSelect.value && draft._pdfItemSelect.value) {
            formData.append('assessment_term', draft._pdfTermSelect.value);
            formData.append('assessment_item_id', draft._pdfItemSelect.value);
        }

        fetch(GENERATE_URL, { method: 'POST', body: formData })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                btnEl.disabled = false;
                btnEl.innerHTML = originalHtml;
                if (data.success) {
                    renderFileCard(data);
                } else {
                    addBubble(data.error || ('The ' + (format === 'docx' ? 'Word file' : 'PDF') + ' could not be generated. Please try again.'), 'bot');
                }
            })
            .catch(function () {
                btnEl.disabled = false;
                btnEl.innerHTML = originalHtml;
                addBubble('Something went wrong generating the file. Please try again.', 'bot');
            });
    }

    function createQuizFormFromDraft(draft, btnEl) {
        if (busy) return;

        // Defense-in-depth mirror of updateGformBtnState() above: the button
        // is already disabled until these are filled in, but never trust
        // only a disabled attribute — re-check the actual values here too.
        // Only one mode exists: link this quiz to an EXISTING assessment
        // item (it is a make-up/recovery quiz for something the student
        // already missed) — there is no "create a brand-new item" fallback.
        const termVal = draft._termSelect ? draft._termSelect.value : '';
        const itemIdVal = draft._itemSelect ? draft._itemSelect.value : '';

        if (!termVal) {
            addBubble('Please select a Term before creating the Google Form quiz.', 'bot');
            return;
        }
        if (!itemIdVal) {
            addBubble('Please select which existing assessment this quiz recovers.', 'bot');
            return;
        }

        const originalHtml = btnEl.innerHTML;
        btnEl.disabled = true;
        btnEl.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Creating Google Form...';

        const formData = new FormData();
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('student_id', currentStudentId);
        formData.append('subject_code', currentSubjectCode);
        formData.append('resource_type', draft.resource_type || 'quiz');
        formData.append('title', draft.title || '');
        formData.append('student_content', draft.student_content || '');
        formData.append('teacher_answer_key', draft.teacher_answer_key || '');
        formData.append('quiz_questions', JSON.stringify(draft.quiz_questions || []));
        formData.append('one_attempt', (draft._oneAttemptCheckbox && draft._oneAttemptCheckbox.checked) ? '1' : '0');
        formData.append('assessment_term', termVal);
        formData.append('assessment_item_id', itemIdVal);

        fetch(CREATE_QUIZ_FORM_URL, { method: 'POST', body: formData })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                btnEl.disabled = false;
                btnEl.innerHTML = originalHtml;
                if (data.success) {
                    renderGformCard(data, draft);
                } else {
                    addBubble(data.error || 'The Google Form quiz could not be created. Please try again.', 'bot');
                }
            })
            .catch(function () {
                btnEl.disabled = false;
                btnEl.innerHTML = originalHtml;
                addBubble('Something went wrong creating the Google Form. Please try again.', 'bot');
            });
    }

    // ---- Google Form quiz card: shown once a real auto-graded Form exists
    // (Preview/Edit in Google Forms / Open Quiz / Send to Student). The
    // student never sees the edit link — only the fill-out (view) link ever
    // travels through Send to Student, via the same resource_id attachment
    // mechanism used for a PDF/DOCX (send_to_student.php only requires a
    // non-empty output_format, which 'gform' already satisfies). ----
    function renderGformCard(res, draft) {
        const row = document.createElement('div');
        row.className = 'ai-chat-row bot';

        const icon = document.createElement('div');
        icon.className = 'ai-chat-icon';
        icon.innerHTML = '<i class="fas fa-robot"></i>';
        row.appendChild(icon);

        const col = document.createElement('div');
        col.className = 'ai-chat-col';

        const card = document.createElement('div');
        card.className = 'ai-file-card';

        const head = document.createElement('div');
        head.className = 'ai-file-card-head';
        head.innerHTML =
            '<i class="fas fa-square-poll-vertical ai-file-icon" style="color:#238863;"></i>' +
            '<div><div class="ai-file-name"></div><div class="ai-file-meta"></div>' +
            '<div class="ai-file-generated-badge"><i class="fas fa-circle-check"></i> Google Form created — auto-graded</div></div>';
        head.querySelector('.ai-file-name').textContent = res.title;
        head.querySelector('.ai-file-meta').textContent = [res.resource_type_label, res.question_count + ' items', res.max_score + ' points', res.one_attempt ? 'One attempt only' : null, res.link_item_name ? 'Recovers: ' + res.link_item_name + (res.link_term ? ' (' + res.link_term + ')' : '') : 'Linked to existing item (recovery/make-up)'].filter(Boolean).join(' • ');
        card.appendChild(head);

        const actions = document.createElement('div');
        actions.className = 'ai-file-card-actions';
        const editBtn = actionLink('Preview / Open in Google Forms', 'fa-pen-to-square', res.edit_url);
        const downloadBtn = actionButton('Download', 'fa-download');
        const regenBtn = actionButton('Regenerate', 'fa-rotate');
        const sendBtnEl = actionButton('Send to Student', 'fa-paper-plane', 'btn-primary');
        actions.appendChild(editBtn);
        actions.appendChild(downloadBtn);
        actions.appendChild(regenBtn);
        actions.appendChild(sendBtnEl);
        card.appendChild(actions);

        // A Google Form itself lives in Google, so "Download" gives a
        // printable PDF copy of the same questions (student version, no
        // answer key) -- made once, then reused on every later click.
        downloadBtn.addEventListener('click', function () {
            if (res._copyResourceId) {
                window.open(DOWNLOAD_URL + '?id=' + res._copyResourceId, '_blank', 'noopener');
                return;
            }
            const source = draft || currentDraft;
            if (!source || !source.student_content) {
                addBubble('There is no quiz content available to download a copy from. Open the form in Google Forms instead.', 'bot');
                return;
            }
            const originalHtml = downloadBtn.innerHTML;
            downloadBtn.disabled = true;
            downloadBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Preparing...';
            const fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('student_id', currentStudentId);
            fd.append('subject_code', currentSubjectCode);
            fd.append('resource_type', source.resource_type || res.resource_type || 'quiz');
            fd.append('title', source.title || res.title || '');
            fd.append('student_content', source.student_content);
            fd.append('teacher_answer_key', '');
            fd.append('output_format', 'pdf');
            fd.append('audience', 'student');
            fetch(GENERATE_URL, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    downloadBtn.disabled = false;
                    downloadBtn.innerHTML = originalHtml;
                    if (data.success) {
                        res._copyResourceId = data.resource_id;
                        window.open(DOWNLOAD_URL + '?id=' + data.resource_id, '_blank', 'noopener');
                    } else {
                        addBubble(data.error || 'The printable copy could not be generated. Please try again.', 'bot');
                    }
                })
                .catch(function () {
                    downloadBtn.disabled = false;
                    downloadBtn.innerHTML = originalHtml;
                    addBubble('Something went wrong preparing the download. Please try again.', 'bot');
                });
        });

        regenBtn.addEventListener('click', function () {
            if (busy) return;
            sendMessage('Please regenerate this quiz with fresh questions, as a Google Form.', { output_format: 'gform', regenerate: true });
        });

        sendBtnEl.addEventListener('click', function () {
            showInlineConfirm(actions, 'Send this quiz link to ' + currentStudentName + '?', function (confirmBtn) {
                sendResourceToStudent(res, confirmBtn);
            });
        });

        col.appendChild(card);
        row.appendChild(col);
        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    // Quietly checks Google Forms for any newly-answered quizzes THIS
    // teacher sent to THIS student, the moment the chat opens for them —
    // no button, no loading state. Renders one persistent "Quiz History"
    // card (Status / Score / Submitted per quiz, per the feature spec)
    // whenever this student has at least one Sent Google Form quiz.
    // Best-effort: a failure here never blocks the chat itself (it isn't
    // even awaited by anything else — see the modal.show() call above).
    function syncQuizScoresForStudent(studentId) {
        const formData = new FormData();
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('student_id', studentId);

        fetch(SYNC_QUIZ_SCORES_URL, { method: 'POST', body: formData })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                // The teacher may have already switched to a different
                // student's chat by the time this resolves — don't drop a
                // stale history card into the wrong conversation.
                if (!data.success || studentId !== currentStudentId) return;
                renderQuizHistoryCard(data.quizzes || []);
            })
            .catch(function () { /* silent — this is a background check, not a user action */ });
    }

    function quizStatusBadge(q) {
        if (q.attempt_count === 0) return { label: 'Sent', color: '#667085' };
        if (q.one_attempt) return { label: 'Completed', color: '#238863' };
        return { label: 'Answered', color: '#238863' };
    }

    function formatQuizDate(iso) {
        if (!iso) return '';
        const d = new Date(iso.replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) +
            ' ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    }

    function renderQuizHistoryCard(quizzes) {
        if (!quizzes.length) return;

        const row = document.createElement('div');
        row.className = 'ai-chat-row bot';

        const icon = document.createElement('div');
        icon.className = 'ai-chat-icon';
        icon.innerHTML = '<i class="fas fa-robot"></i>';
        row.appendChild(icon);

        const col = document.createElement('div');
        col.className = 'ai-chat-col';

        const card = document.createElement('div');
        card.className = 'ai-file-card';
        card.style.cssText = 'max-width:420px;';

        const head = document.createElement('div');
        head.className = 'ai-file-card-head';
        head.innerHTML = '<i class="fas fa-clock-rotate-left ai-file-icon" style="color:#14213D;"></i>' +
            '<div><div class="ai-file-name">Quiz History</div><div class="ai-file-meta">' + quizzes.length + ' quiz' + (quizzes.length === 1 ? '' : 'zes') + ' sent to ' + currentStudentName + '</div></div>';
        card.appendChild(head);

        const list = document.createElement('div');
        list.style.cssText = 'margin-top:8px;display:flex;flex-direction:column;gap:8px;';

        quizzes.forEach(function (q) {
            const badge = quizStatusBadge(q);
            const item = document.createElement('div');
            item.style.cssText = 'border:1px solid #e5e7eb;border-radius:8px;padding:8px 10px;font-size:12.5px;';

            const topLine = document.createElement('div');
            topLine.style.cssText = 'display:flex;justify-content:space-between;align-items:center;gap:8px;';
            const titleEl = document.createElement('span');
            titleEl.style.cssText = 'font-weight:600;color:#14213D;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
            titleEl.textContent = q.title;
            const badgeEl = document.createElement('span');
            badgeEl.style.cssText = 'flex-shrink:0;font-size:11px;font-weight:600;padding:1px 8px;border-radius:10px;color:#fff;background:' + badge.color + ';';
            badgeEl.textContent = badge.label + (q.new_this_check ? ' • New' : '');
            topLine.appendChild(titleEl);
            topLine.appendChild(badgeEl);
            item.appendChild(topLine);

            const detailLine = document.createElement('div');
            detailLine.style.cssText = 'color:#667085;margin-top:3px;';
            const pct = (q.latest_score !== null && q.max_score) ? Math.round((q.latest_score / q.max_score) * 1000) / 10 : null;
            const scoreText = q.latest_score !== null ? (q.max_score ? (q.latest_score + '/' + q.max_score + (pct !== null ? ' (' + pct + '%)' : '')) : String(q.latest_score)) : '—';
            const submittedText = q.latest_submitted_at ? formatQuizDate(q.latest_submitted_at) : (q.attempt_count === 0 ? 'Not answered yet' : '');
            detailLine.textContent = 'Score: ' + scoreText + (submittedText ? '  •  Submitted: ' + submittedText : '') + (q.attempt_count > 1 ? '  •  ' + q.attempt_count + ' attempts' : '');
            item.appendChild(detailLine);

            list.appendChild(item);
        });

        card.appendChild(list);
        col.appendChild(card);
        row.appendChild(col);
        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    // ---- Pending submission check-and-record card ----
    // Quietly checks, the moment a subject's chat opens, whether THIS
    // student has any free-response (manual-check) submission for THIS
    // subject still waiting on the teacher -- 'Submitted' (never checked)
    // or 'AI-Checked' (AI already left a suggested score as a draft). This
    // is what lets a teacher already chatting about an at-risk student
    // check and record a submitted activity right here, instead of having
    // to separately remember to open Student Submissions
    // (modules/ai/resources.php). Best-effort and silent, same convention
    // as syncQuizScoresForStudent() above.
    function checkPendingSubmissionsForStudentSubject(studentId, subjectCode) {
        const formData = new FormData();
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('student_id', studentId);
        formData.append('subject_code', subjectCode);

        fetch(LIST_PENDING_SUBMISSIONS_URL, { method: 'POST', body: formData })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                // The teacher may have switched student/subject by the time
                // this resolves -- never drop a stale card into the wrong
                // conversation.
                if (!data.success || studentId !== currentStudentId || subjectCode !== currentSubjectCode) return;
                (data.submissions || []).forEach(function (sub) { renderPendingSubmissionCard(sub); });
            })
            .catch(function () { /* silent — background check, not a user action */ });
    }

    function formatSubDate(iso) {
        if (!iso) return '';
        const d = new Date(iso.replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) +
            ' ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    }

    // One card per pending submission. Internally moves through up to three
    // states as the teacher acts on it: "needs checking" (Check with AI) ->
    // "AI suggested a score" (editable score/feedback + Confirm & Record) ->
    // "recorded" (final confirmation, including whether it synced into
    // Grade Management). Never records or scores anything on its own —
    // every state change here is the direct result of the teacher's own
    // click, same as modules/ai/resources.php's review modal.
    function renderPendingSubmissionCard(sub) {
        const row = document.createElement('div');
        row.className = 'ai-chat-row bot';

        const icon = document.createElement('div');
        icon.className = 'ai-chat-icon';
        icon.innerHTML = '<i class="fas fa-robot"></i>';
        row.appendChild(icon);

        const col = document.createElement('div');
        col.className = 'ai-chat-col';

        const card = document.createElement('div');
        card.className = 'ai-file-card';
        card.style.cssText = 'max-width:440px;';

        const head = document.createElement('div');
        head.className = 'ai-file-card-head';
        head.innerHTML = '<i class="fas fa-clipboard-check ai-file-icon" style="color:#B45309;"></i>' +
            '<div><div class="ai-file-name"></div><div class="ai-file-meta"></div></div>';
        head.querySelector('.ai-file-name').textContent = sub.title + ' — sinagutan na';
        head.querySelector('.ai-file-meta').textContent = [sub.type_label, sub.answer_original_filename, 'Isinumite: ' + formatSubDate(sub.submitted_at)].filter(Boolean).join(' • ');
        card.appendChild(head);

        const linkArea = document.createElement('div');
        linkArea.style.cssText = 'margin-top:10px;';
        card.appendChild(linkArea);

        const viewActions = document.createElement('div');
        viewActions.className = 'ai-file-card-actions';
        viewActions.appendChild(actionLink('Tingnan ang Sagot', 'fa-eye', VIEW_SUBMISSION_ANSWER_URL + '?id=' + sub.submission_id));
        card.appendChild(viewActions);

        const body = document.createElement('div');
        body.style.cssText = 'margin-top:10px;';
        card.appendChild(body);

        function renderLinkArea() {
            linkArea.innerHTML = '';
            if (sub.is_linked) {
                const linked = document.createElement('div');
                linked.style.cssText = 'font-size:12px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:8px 10px;color:#166534;';
                linked.innerHTML = '<i class="fas fa-link me-1"></i>Naka-link sa gradebook: <strong>' + (sub.link_category_label || '') + ', ' + (sub.link_term || '') + '</strong> — awtomatikong mare-record ang score na i-co-confirm at mawawala ito sa Missing Assessments.';
                linkArea.appendChild(linked);
                return;
            }

            const warn = document.createElement('div');
            warn.style.cssText = 'font-size:12px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:10px;';
            warn.innerHTML = '<div style="font-weight:600;color:#92400E;margin-bottom:6px;"><i class="fas fa-triangle-exclamation me-1"></i>Hindi pa naka-link sa isang gradebook item</div>' +
                '<div style="color:#92400E;margin-bottom:8px;">I-link muna dito para awtomatikong ma-record sa Encode Grades ang score na i-co-confirm mo sa ibaba.</div>';
            const pickerRow = document.createElement('div');
            pickerRow.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;';

            const termSel = document.createElement('select');
            termSel.className = 'form-select form-select-sm';
            termSel.style.maxWidth = '120px';
            termSel.innerHTML = '<option value="">Term…</option><option value="Term 1">Term 1</option><option value="Term 2">Term 2</option><option value="Term 3">Term 3</option>';

            const itemSel = document.createElement('select');
            itemSel.className = 'form-select form-select-sm';
            itemSel.style.maxWidth = '220px';
            itemSel.disabled = true;
            itemSel.innerHTML = '<option value="">Piliin muna ang term…</option>';

            const linkBtn = document.createElement('button');
            linkBtn.type = 'button';
            linkBtn.className = 'btn btn-sm btn-outline-primary';
            linkBtn.textContent = 'I-link';
            linkBtn.disabled = true;

            pickerRow.appendChild(termSel);
            pickerRow.appendChild(itemSel);
            pickerRow.appendChild(linkBtn);
            warn.appendChild(pickerRow);
            linkArea.appendChild(warn);

            termSel.addEventListener('change', function () {
                const term = termSel.value;
                itemSel.innerHTML = '';
                linkBtn.disabled = true;
                if (!term) {
                    itemSel.innerHTML = '<option value="">Piliin muna ang term…</option>';
                    itemSel.disabled = true;
                    return;
                }
                itemSel.innerHTML = '<option>Loading…</option>';
                itemSel.disabled = true;
                const fd = new FormData();
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('student_id', currentStudentId);
                fd.append('subject_code', currentSubjectCode);
                fd.append('term', term);
                fetch(LIST_ASSESSMENT_ITEMS_URL, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (termSel.value !== term) return;
                        itemSel.innerHTML = '';
                        if (!data.success || !data.items || !data.items.length) {
                            itemSel.innerHTML = '<option value="">' + (data.success ? 'Wala pang item sa term na ito' : 'Hindi ma-load ang mga item') + '</option>';
                            itemSel.disabled = true;
                            return;
                        }
                        const opt0 = document.createElement('option');
                        opt0.value = '';
                        opt0.textContent = 'Piliin kung anong assessment ang sinasakop nito…';
                        itemSel.appendChild(opt0);
                        const groups = {};
                        data.items.forEach(function (it) {
                            if (!groups[it.category_label]) {
                                groups[it.category_label] = document.createElement('optgroup');
                                groups[it.category_label].label = it.category_label;
                                itemSel.appendChild(groups[it.category_label]);
                            }
                            const opt = document.createElement('option');
                            opt.value = String(it.item_id);
                            const hps = (it.highest_score % 1 === 0) ? it.highest_score.toFixed(0) : it.highest_score;
                            opt.textContent = it.item_name + ' — ' + hps + ' pts — ' + (it.has_score ? ('Current: ' + it.current_score + '/' + hps) : 'Missing score');
                            groups[it.category_label].appendChild(opt);
                        });
                        itemSel.disabled = false;
                    })
                    .catch(function () {
                        itemSel.innerHTML = '<option value="">Hindi ma-load ang mga item</option>';
                        itemSel.disabled = true;
                    });
            });

            itemSel.addEventListener('change', function () {
                linkBtn.disabled = !itemSel.value;
            });

            linkBtn.addEventListener('click', function () {
                linkBtn.disabled = true;
                linkBtn.textContent = 'Nili-link…';
                const fd = new FormData();
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('resource_id', sub.resource_id);
                fd.append('assessment_item_id', itemSel.value);
                fd.append('assessment_term', termSel.value);
                fetch(LINK_RESOURCE_ITEM_URL, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) {
                            sub.is_linked = true;
                            sub.link_term = data.link_term;
                            sub.link_category_label = ({ WW: 'Written Works', PT: 'Performance Tasks', QA: 'Examinations' })[data.link_category] || data.link_category;
                            renderLinkArea();
                        } else {
                            alert(data.error || 'Hindi ma-link ang activity na ito.');
                            linkBtn.disabled = false;
                            linkBtn.textContent = 'I-link';
                        }
                    })
                    .catch(function () {
                        alert('Hindi ma-link ang activity na ito.');
                        linkBtn.disabled = false;
                        linkBtn.textContent = 'I-link';
                    });
            });
        }

        function renderCheckState() {
            body.innerHTML = '';
            const errEl = document.createElement('div');
            errEl.className = 'text-danger';
            errEl.style.cssText = 'font-size:12.5px;display:none;margin-bottom:8px;';
            body.appendChild(errEl);

            if (!sub.ai_checkable) {
                const note = document.createElement('div');
                note.style.cssText = 'font-size:12.5px;color:#667085;';
                note.textContent = 'Hindi pa suportado ang AI checking para sa lumang .doc file — buksan at suriin na lang nang manu-mano.';
                body.appendChild(note);
                return;
            }

            const checkBtn = document.createElement('button');
            checkBtn.type = 'button';
            checkBtn.className = 'btn btn-sm btn-outline-primary';
            checkBtn.innerHTML = '<i class="fas fa-wand-magic-sparkles me-1"></i>I-check Gamit ang AI';
            body.appendChild(checkBtn);

            checkBtn.addEventListener('click', function () {
                checkBtn.disabled = true;
                errEl.style.display = 'none';
                const original = checkBtn.innerHTML;
                checkBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Chinicheck…';
                const fd = new FormData();
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('submission_id', sub.submission_id);
                fetch(CHECK_SUBMISSION_URL, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) {
                            checkBtn.disabled = false;
                            checkBtn.innerHTML = original;
                            errEl.textContent = data.error || 'Hindi ma-check ang submission na ito.';
                            errEl.style.display = 'block';
                            return;
                        }
                        sub.status = 'AI-Checked';
                        sub.score = data.score;
                        sub.max_score = data.max_score;
                        sub.feedback = data.feedback;
                        sub.answer_source_type = data.source_type;
                        renderScoreState();
                    })
                    .catch(function () {
                        checkBtn.disabled = false;
                        checkBtn.innerHTML = original;
                        errEl.textContent = 'Hindi ma-check ang submission na ito. Subukan ulit.';
                        errEl.style.display = 'block';
                    });
            });
        }

        function renderScoreState() {
            body.innerHTML = '';

            const banner = document.createElement('div');
            banner.style.cssText = 'font-size:12px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:8px 10px;margin-bottom:10px;color:#1E40AF;';
            let bannerText = '<i class="fas fa-wand-magic-sparkles me-1"></i><strong>Panukalang score ng AI — suriin bago i-record.</strong>';
            if (sub.answer_source_type === 'image') {
                bannerText += ' Binasa ito ng AI mula sa larawan/scan — i-double-check laban sa mismong file.';
            }
            banner.innerHTML = bannerText;
            body.appendChild(banner);

            const errEl = document.createElement('div');
            errEl.className = 'text-danger';
            errEl.style.cssText = 'font-size:12.5px;display:none;margin-bottom:8px;';
            body.appendChild(errEl);

            const scoreRow = document.createElement('div');
            scoreRow.style.cssText = 'display:flex;gap:10px;margin-bottom:8px;';

            const scoreWrap = document.createElement('div');
            scoreWrap.innerHTML = '<label style="font-size:12px;font-weight:600;display:block;">Score</label>';
            const scoreInput = document.createElement('input');
            scoreInput.type = 'number';
            scoreInput.step = '0.01';
            scoreInput.min = '0';
            scoreInput.className = 'form-control form-control-sm';
            scoreInput.style.width = '90px';
            scoreInput.value = sub.score !== null ? sub.score : '';
            scoreWrap.appendChild(scoreInput);

            const maxWrap = document.createElement('div');
            maxWrap.innerHTML = '<label style="font-size:12px;font-weight:600;display:block;">Sa Labas ng</label>';
            const maxInput = document.createElement('input');
            maxInput.type = 'number';
            maxInput.step = '0.01';
            maxInput.min = '0';
            maxInput.className = 'form-control form-control-sm';
            maxInput.style.width = '90px';
            maxInput.value = sub.max_score !== null ? sub.max_score : '';
            maxWrap.appendChild(maxInput);

            scoreRow.appendChild(scoreWrap);
            scoreRow.appendChild(maxWrap);
            body.appendChild(scoreRow);

            const fbLabel = document.createElement('label');
            fbLabel.style.cssText = 'font-size:12px;font-weight:600;display:block;';
            fbLabel.textContent = 'Feedback';
            body.appendChild(fbLabel);
            const fbInput = document.createElement('textarea');
            fbInput.className = 'form-control form-control-sm';
            fbInput.rows = 3;
            fbInput.style.marginBottom = '8px';
            fbInput.value = sub.feedback || '';
            body.appendChild(fbInput);

            const btnRow = document.createElement('div');
            btnRow.style.cssText = 'display:flex;gap:8px;';
            const recheckBtn = document.createElement('button');
            recheckBtn.type = 'button';
            recheckBtn.className = 'btn btn-sm btn-outline-secondary';
            recheckBtn.textContent = 'I-check Ulit';
            const recordBtn = document.createElement('button');
            recordBtn.type = 'button';
            recordBtn.className = 'btn btn-sm';
            recordBtn.style.cssText = 'background:#14213D;color:#fff;';
            recordBtn.innerHTML = '<i class="fas fa-check me-1"></i>' + (sub.is_linked ? 'I-confirm at I-record sa Grade' : 'I-confirm ang Review');
            btnRow.appendChild(recheckBtn);
            btnRow.appendChild(recordBtn);
            body.appendChild(btnRow);

            recheckBtn.addEventListener('click', renderCheckState);

            recordBtn.addEventListener('click', function () {
                recordBtn.disabled = true;
                recheckBtn.disabled = true;
                errEl.style.display = 'none';
                const original = recordBtn.innerHTML;
                recordBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Ini-record…';
                const fd = new FormData();
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('submission_id', sub.submission_id);
                fd.append('score', scoreInput.value);
                fd.append('max_score', maxInput.value);
                fd.append('teacher_feedback', fbInput.value);
                fetch(MARK_SUBMISSION_REVIEWED_URL, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) {
                            recordBtn.disabled = false;
                            recheckBtn.disabled = false;
                            recordBtn.innerHTML = original;
                            errEl.textContent = data.error || 'Hindi ma-record ang grade.';
                            errEl.style.display = 'block';
                            return;
                        }
                        body.innerHTML = '';
                        const done = document.createElement('div');
                        done.style.cssText = 'font-size:13px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 12px;color:#166534;';
                        let msg = '<i class="fas fa-circle-check me-1"></i>Na-record ang grade';
                        if (scoreInput.value !== '' && maxInput.value !== '') {
                            msg += ': <strong>' + scoreInput.value + '/' + maxInput.value + '</strong>';
                        }
                        if (data.grade_sync_status === 'synced') {
                            msg += '<br>Awtomatiko na itong naitala sa Encode Grades — wala na ito sa Missing Assessments.';
                        } else if (sub.is_linked) {
                            msg += '<br>Naka-link na ito pero hindi na-sync ang score — pakitingnan sa Encode Grades.';
                        } else {
                            msg += '<br>Hindi pa naka-link sa isang gradebook item, kaya hindi pa ito lumalabas sa Encode Grades.';
                        }
                        done.innerHTML = msg;
                        body.appendChild(done);
                    })
                    .catch(function () {
                        recordBtn.disabled = false;
                        recheckBtn.disabled = false;
                        recordBtn.innerHTML = original;
                        errEl.textContent = 'Hindi ma-record ang grade. Subukan ulit.';
                        errEl.style.display = 'block';
                    });
            });
        }

        renderLinkArea();
        if (sub.status === 'AI-Checked') {
            renderScoreState();
        } else {
            renderCheckState();
        }

        col.appendChild(card);
        row.appendChild(col);
        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function sendResourceToStudent(res, btnEl) {
        btnEl.disabled = true;
        btnEl.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Sending...';

        const formData = new FormData();
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('student_id', currentStudentId);
        formData.append('subject_code', currentSubjectCode);
        formData.append('resource_id', res.resource_id);
        formData.append('message', sharingResourceMessage(res.title));
        formData.append('source', 'ai');

        fetch(SEND_URL, { method: 'POST', body: formData })
            .then(function (res2) { return res2.json(); })
            .then(function (data) {
                if (data.success) {
                    const confirm = document.createElement('span');
                    confirm.className = 'ai-sent-confirm';
                    confirm.innerHTML = '<i class="fas fa-check-circle me-1"></i>Sent to student';
                    const box = btnEl.closest('.ai-confirm-box');
                    if (box) box.replaceWith(confirm);
                } else {
                    btnEl.disabled = false;
                    btnEl.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Send to Student';
                    addBubble(data.error || 'Could not send that resource. Please try again.', 'bot');
                }
            })
            .catch(function () {
                btnEl.disabled = false;
                btnEl.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Send to Student';
                addBubble('Something went wrong sending that resource. Please try again.', 'bot');
            });
    }

    // A quiz (or anything longer than a chat message allows) cannot go out
    // as plain text -- the student would get an unanswerable wall of text and
    // send_to_student.php caps messages at 2000 characters. Those are turned
    // into the student's PDF copy and sent as an attachment instead, which
    // needs no Google account at all. Short plain drafts still go as text.
    function needsFileSend(draft) {
        const hasQuestions = Array.isArray(draft.quiz_questions) && draft.quiz_questions.length > 0;
        return hasQuestions || String(draft.student_content || '').length > 1800;
    }

    function sendDraftToStudent(draft, btnEl) {
        if (!needsFileSend(draft)) {
            sendTextToStudent(draft.student_content, btnEl);
            return;
        }
        sendDraftAsFile(draft, btnEl);
    }

    function sendDraftAsFile(draft, btnEl) {
        const originalHtml = btnEl.innerHTML;
        btnEl.disabled = true;
        btnEl.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Preparing PDF...';

        function fail(message) {
            btnEl.disabled = false;
            btnEl.innerHTML = originalHtml;
            addBubble(message, 'bot');
        }

        function sendResource(resourceId, title) {
            btnEl.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Sending...';
            const fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('student_id', currentStudentId);
            fd.append('subject_code', currentSubjectCode);
            fd.append('resource_id', resourceId);
            fd.append('message', sharingResourceMessage(title));
            fd.append('source', 'ai');
            fetch(SEND_URL, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) { fail(data.error || 'Could not send that resource. Please try again.'); return; }
                    const confirm = document.createElement('span');
                    confirm.className = 'ai-sent-confirm';
                    confirm.innerHTML = '<i class="fas fa-check-circle me-1"></i>Sent to student';
                    const box = btnEl.closest('.ai-confirm-box');
                    if (box) {
                        box.replaceWith(confirm);
                    } else {
                        btnEl.disabled = false;
                        btnEl.innerHTML = originalHtml;
                        addBubble('Sent to ' + studentFirstLast() + ' as a PDF: ' + title, 'bot');
                    }
                })
                .catch(function () { fail('Something went wrong sending that resource. Please try again.'); });
        }

        // Reuse the PDF if it was already made for this draft (retry / second click).
        if (draft._sendResourceId) {
            sendResource(draft._sendResourceId, draft.title || 'Learning resource');
            return;
        }
        const fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('student_id', currentStudentId);
        fd.append('subject_code', currentSubjectCode);
        fd.append('resource_type', draft.resource_type || 'other');
        fd.append('title', draft.title || '');
        fd.append('student_content', draft.student_content || '');
        fd.append('teacher_answer_key', draft.teacher_answer_key || '');
        fd.append('output_format', 'pdf');
        fd.append('audience', 'student');
        fetch(GENERATE_URL, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { fail(data.error || 'The PDF could not be generated. Please try again.'); return; }
                draft._sendResourceId = data.resource_id;
                sendResource(data.resource_id, draft.title || data.title || 'Learning resource');
            })
            .catch(function () { fail('Something went wrong generating the PDF. Please try again.'); });
    }

    function sendTextToStudent(text, btnEl) {
        btnEl.disabled = true;
        btnEl.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Sending...';

        const formData = new FormData();
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('student_id', currentStudentId);
        formData.append('subject_code', currentSubjectCode);
        formData.append('message', text);
        formData.append('ai_draft', text);
        formData.append('source', 'ai');

        fetch(SEND_URL, { method: 'POST', body: formData })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    const confirm = document.createElement('span');
                    confirm.className = 'ai-sent-confirm';
                    confirm.innerHTML = '<i class="fas fa-check-circle me-1"></i>Sent to student';
                    const box = btnEl.closest('.ai-confirm-box');
                    if (box) box.replaceWith(confirm);
                } else {
                    btnEl.disabled = false;
                    btnEl.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Send to Student';
                    addBubble(data.error || 'Could not send that message. Please try again.', 'bot');
                }
            })
            .catch(function () {
                btnEl.disabled = false;
                btnEl.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Send to Student';
                addBubble('Something went wrong sending that message. Please try again.', 'bot');
            });
    }

    document.querySelectorAll('.ai-chat-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            currentStudentId = btn.getAttribute('data-student-id');
            currentStudentName = btn.getAttribute('data-student-name') || '';
            if (languageSelect) languageSelect.value = 'auto'; // default Auto for every newly opened student
            currentActivityDate = btn.getAttribute('data-last-activity') || '';
            nameEl.textContent = currentStudentName;
            try {
                currentSubjects = JSON.parse(btn.getAttribute('data-subjects') || '[]');
            } catch (e) {
                currentSubjects = [];
            }

            history = [];
            currentDraft = null;
            currentSendText = '';
            sendCurrentBtn.disabled = true;
            messagesEl.innerHTML = '';
            historyPanel.hidden = true;
            historyToggle.setAttribute('aria-expanded', 'false');
            updateDraftPreview();
            currentRiskType = btn.getAttribute('data-risk-type') || 'Academic';
            try {
                currentAttendance = JSON.parse(btn.getAttribute('data-attendance') || '{}');
            } catch (e) {
                currentAttendance = {};
            }
            renderRiskSummary(currentAttendance, currentRiskType);
            renderStarters();
            renderSubjectChips();
            modal.show();
            syncQuizScoresForStudent(currentStudentId);

            if (currentSubjects.length === 1) {
                selectSubject(currentSubjects[0].code);
            } else {
                currentSubjectCode = null;
                renderWelcome();
                renderHistory();
                input.placeholder = 'Select a subject to start chatting...';
                input.disabled = true;
                sendBtn.disabled = true;
            }
        });
    });

    // A pending attachment never carries over to another chat or student.
    newChatBtn.addEventListener('click', clearAttachment);
    const aiChatModalEl = document.getElementById('aiChatModal');
    if (aiChatModalEl) aiChatModalEl.addEventListener('hidden.bs.modal', clearAttachment);

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const message = input.value.trim();
        if ((!message && !pendingAttachment) || !currentSubjectCode || busy) return;
        sendMessage(message);
        input.value = '';
    });

    sendCurrentBtn.addEventListener('click', function () {
        if (!currentSendText || !currentStudentId || !currentSubjectCode) return;
        // The latest generated quiz/activity is too long for a chat message:
        // send its PDF copy instead (same as the card's Send to Student).
        if (currentDraft && currentSendText === currentDraft.student_content && needsFileSend(currentDraft)) {
            sendDraftAsFile(currentDraft, sendCurrentBtn);
            return;
        }
        sendToStudent(currentSendText, sendCurrentBtn);
    });
});
</script>

</div>

<?php include '../../includes/footer.php'; ?>