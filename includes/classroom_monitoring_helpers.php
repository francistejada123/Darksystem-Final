<?php
// includes/classroom_monitoring_helpers.php
//
// Read-only data for the teacher's Classroom page (modules/teachers/classroom.php):
//   - classroomOnlineWork(): every quiz / activity sent to this class from
//     Teacher AI ("Send to <item> AI" or a normal Teacher AI send), with its
//     Start/Deadline window, per-student status (Upcoming / Active /
//     Completed / Missing, or Submitted / Not submitted) and score -- plus
//     items whose lesson was prepared ("Send to <item> AI") but not sent yet.
//   - classroomStudentComments(): students' comments on messages this teacher
//     sent in this subject (see includes/message_comment_helpers.php).
// Nothing here writes to any table except the automatic Missing status that
// aim_getTasksForItems() keeps up to date.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/assessment_material_helpers.php';
require_once __DIR__ . '/message_comment_helpers.php';

/** True when a table exists (Teacher AI / quiz tables are created on first use). */
function classroomTableExists($table)
{
    $row = getRecord("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]);
    return $row && (int) $row['n'] > 0;
}

/**
 * @param array $roster classroomSectionStudents() rows
 * @return array{sent: array, prepared: array}
 */
function classroomOnlineWork($teacherId, $sectionId, $subjectCode, $syId, array $roster)
{
    $out = ['sent' => [], 'prepared' => []];
    $names = [];
    foreach ($roster as $st) {
        $names[(int) $st['student_id']] = trim($st['last_name'] . ', ' . $st['first_name']);
    }
    $fmt = function ($n) { return rtrim(rtrim(number_format((float) $n, 2), '0'), '.'); };
    $sentItemIds = [];

    if (classroomTableExists('teacher_ai_resource_sends') && classroomTableExists('teacher_ai_resource_recipients')) {
        $sends = getRecords(
            "SELECT s.resource_id, MIN(s.sent_at) AS first_sent, MAX(s.record_item_id) AS item_id,
                    r.title, r.resource_type, r.output_format, r.quiz_max_score
             FROM teacher_ai_resource_sends s
             INNER JOIN teacher_ai_resources r ON r.resource_id = s.resource_id
             WHERE s.teacher_id = ? AND s.section_id = ? AND s.subject_code = ? AND s.school_year_id = ?
             GROUP BY s.resource_id, r.title, r.resource_type, r.output_format, r.quiz_max_score
             ORDER BY first_sent DESC",
            [(int) $teacherId, (int) $sectionId, $subjectCode, (int) $syId]
        );
        $itemIds = array_values(array_filter(array_map('intval', array_column($sends, 'item_id'))));
        $tasks = $itemIds ? aim_getTasksForItems($itemIds) : [];
        $items = [];
        $itemScores = [];
        if ($itemIds) {
            $ph = implode(',', array_fill(0, count($itemIds), '?'));
            foreach (getRecords("SELECT item_id, item_name, category, highest_score, topic FROM assessment_items WHERE item_id IN ($ph)", $itemIds) as $it) {
                $items[(int) $it['item_id']] = $it;
            }
            foreach (getRecords("SELECT item_id, student_id, raw_score FROM assessment_scores WHERE raw_score IS NOT NULL AND item_id IN ($ph)", $itemIds) as $sc) {
                $itemScores[(int) $sc['item_id']][(int) $sc['student_id']] = (float) $sc['raw_score'];
            }
        }

        foreach ($sends as $send) {
            $rid = (int) $send['resource_id'];
            $itemId = (int) $send['item_id'];
            if ($itemId) {
                $sentItemIds[$itemId] = true;
            }
            $recips = getRecords(
                "SELECT rc.student_id, rc.student_resource_id,
                        sub.submission_id, sub.score, sub.max_score, sub.status AS sub_status, sub.submitted_at
                 FROM teacher_ai_resource_recipients rc
                 INNER JOIN teacher_ai_resource_sends s ON s.send_id = rc.send_id AND s.section_id = ?
                 LEFT JOIN ai_resource_submissions sub ON sub.resource_id = rc.student_resource_id AND sub.student_id = rc.student_id
                 WHERE rc.resource_id = ? AND rc.subject_code = ?",
                [(int) $sectionId, $rid, $subjectCode]
            );
            $task = $tasks[$itemId] ?? null;
            $taskStatus = [];
            if ($task) {
                foreach (getRecords("SELECT student_id, status FROM learning_material_task_students WHERE task_id = ?", [(int) $task['task_id']]) as $ts) {
                    $taskStatus[(int) $ts['student_id']] = aim_displayStatus($ts['status'], $task);
                }
            }
            $item = $items[$itemId] ?? null;
            $students = [];
            $submitted = 0;
            $pctSum = 0;
            $pctN = 0;
            foreach ($recips as $rc) {
                $sid = (int) $rc['student_id'];
                if (!isset($names[$sid])) {
                    continue; // no longer in this section
                }
                $score = '';
                $pct = null;
                if ($item && isset($itemScores[$itemId][$sid])) {
                    $pct = (float) $item['highest_score'] > 0 ? $itemScores[$itemId][$sid] / (float) $item['highest_score'] * 100 : null;
                    $score = $fmt($itemScores[$itemId][$sid]) . '/' . $fmt($item['highest_score']);
                } elseif ($rc['score'] !== null && (float) $rc['max_score'] > 0) {
                    $pct = (float) $rc['score'] / (float) $rc['max_score'] * 100;
                    $score = $fmt($rc['score']) . '/' . $fmt($rc['max_score']);
                }
                $isSubmitted = !empty($rc['submission_id']);
                if ($isSubmitted) {
                    $submitted++;
                }
                if ($pct !== null) {
                    $pctSum += $pct;
                    $pctN++;
                }
                $status = $taskStatus[$sid] ?? ($isSubmitted ? ($rc['sub_status'] === 'Submitted' && $rc['score'] === null ? 'Submitted – to check' : 'Completed') : 'Not submitted');
                $students[] = [
                    'name'         => $names[$sid],
                    'status'       => $status,
                    'score'        => $score,
                    'percent'      => $pct === null ? null : round($pct),
                    'submitted_at' => $rc['submitted_at'] ? date('M j, g:i A', strtotime($rc['submitted_at'])) : '',
                ];
            }
            usort($students, function ($a, $b) { return strcmp($a['name'], $b['name']); });
            $counts = ['Upcoming' => 0, 'Active' => 0, 'Completed' => 0, 'Missing' => 0];
            foreach ($students as $s) {
                if (isset($counts[$s['status']])) {
                    $counts[$s['status']]++;
                }
            }
            $out['sent'][] = [
                'title'       => $send['title'],
                'type_label'  => $item ? aim_kindMeta(aim_assessmentKind($item))['label'] : aiResourceTypeLabelSafe($send['resource_type']),
                'format'      => strtoupper((string) $send['output_format']) === 'GFORM' ? 'Online quiz' : strtoupper((string) $send['output_format']),
                'sent_at'     => date('M j, Y g:i A', strtotime($send['first_sent'])),
                'item'        => $item ? $item['item_name'] . ($item['topic'] ? ' · ' . $item['topic'] : '') : null,
                'window'      => $task ? aim_formatDateTime($task['start_at']) . ' – ' . aim_formatDateTime($task['end_at']) : null,
                'end_at'      => $task ? $task['end_at'] : null,
                'sent_raw'    => $send['first_sent'],
                'has_task'    => (bool) $task,
                'counts'      => $counts,
                'total'       => count($students),
                'submitted'   => $submitted,
                'avg_percent' => $pctN ? round($pctSum / $pctN) : null,
                'students'    => $students,
            ];
        }
    }

    // Lesson prepared with "Send to <item> AI" but not sent to students yet.
    aim_ensureTable();
    foreach (getRecords(
        "SELECT t.*, ai.item_name, ai.category, ai.topic AS item_topic FROM learning_material_tasks t
         INNER JOIN assessment_items ai ON ai.item_id = t.item_id
         WHERE t.teacher_id = ? AND t.section_id = ? AND t.subject_code = ? AND t.school_year_id = ?
         ORDER BY t.updated_at DESC",
        [(int) $teacherId, (int) $sectionId, $subjectCode, (int) $syId]
    ) as $t) {
        if (isset($sentItemIds[(int) $t['item_id']])) {
            continue;
        }
        // Students may already have the lesson (sent before the online
        // assessment itself) -- show that instead of "not sent yet".
        $withLesson = getRecord("SELECT COUNT(*) AS n FROM learning_material_task_students WHERE task_id = ?", [(int) $t['task_id']]);
        $out['prepared'][] = [
            'item_id'     => (int) $t['item_id'],
            'title'       => $t['item_name'] . ($t['topic'] ? ' – ' . $t['topic'] : ''),
            'window'      => aim_formatDateTime($t['start_at']) . ' – ' . aim_formatDateTime($t['end_at']),
            'end_at'      => $t['end_at'],
            'lesson_sent' => (int) ($withLesson['n'] ?? 0),
        ];
    }
    return $out;
}

/** aiResourceTypeLabel() when the AI document helper is loaded, else a plain label. */
function aiResourceTypeLabelSafe($type)
{
    return function_exists('aiResourceTypeLabel') ? aiResourceTypeLabel($type) : ucwords(str_replace('_', ' ', (string) $type));
}

/**
 * Students' comments (newest first) on messages this teacher sent to
 * students of this section in this subject.
 */
function classroomStudentComments($teacherId, $sectionId, $subjectCode, $syId, $limit = 30)
{
    mc_ensureTable();
    return getRecords(
        "SELECT c.comment_id, c.message_id, c.body, c.created_at, m.support_type, m.final_message,
                TRIM(CONCAT(s.first_name, ' ', s.last_name)) AS student_name,
                (SELECT COUNT(*) FROM student_message_comments r WHERE r.message_id = c.message_id AND r.author_role = 'Teacher' AND r.created_at >= c.created_at) AS replied
         FROM student_message_comments c
         INNER JOIN student_support_messages m ON m.message_id = c.message_id AND m.teacher_id = ? AND m.subject_code = ?
         INNER JOIN enrollments e ON e.student_id = m.student_id AND e.section_id = ? AND e.school_year_id = ? AND e.enrollment_status = 'Approved'
         INNER JOIN students s ON s.student_id = c.author_id
         WHERE c.author_role = 'Student'
         ORDER BY c.created_at DESC
         LIMIT " . (int) $limit,
        [(int) $teacherId, $subjectCode, (int) $sectionId, (int) $syId]
    );
}
