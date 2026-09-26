<?php
// includes/student_portal_data.php
// Read-only data for the Student Portal mobile pages (My Classes, Tasks &
// Activities, Attendance, Updates) and the mobile dashboard summary. Only
// SELECTs over the tables the existing pages already use -- no new logic.

require_once __DIR__ . '/../config/database.php';

/** Active school year, enrollment (with section + grade) and the classes of a student. */
function spdContext($studentId)
{
    $sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
    $syId = (int) ($sy['sy_id'] ?? 0);
    $enrollment = getRecord(
        "SELECT e.*, sec.section_name, sec.grade_level AS section_grade
           FROM enrollments e LEFT JOIN sections sec ON sec.section_id = e.section_id
          WHERE e.student_id = ? AND e.school_year_id = ?",
        [(int) $studentId, $syId]
    );
    $classes = getRecords(
        "SELECT DISTINCT sub.subject_code, sub.subject_name, ts.teacher_id, e.section_id, e.school_year_id,
                u.first_name AS teacher_first, u.last_name AS teacher_last
           FROM enrollments e
           JOIN teacher_subjects ts ON ts.section_id = e.section_id AND ts.school_year_id = e.school_year_id
           JOIN subjects sub ON sub.subject_code = ts.subject_code
           JOIN users u ON u.user_id = ts.teacher_id
          WHERE e.student_id = ? AND e.school_year_id = ?
          ORDER BY sub.subject_name",
        [(int) $studentId, $syId]
    );
    return ['sy' => $sy, 'sy_id' => $syId, 'enrollment' => $enrollment, 'classes' => $classes];
}

/** Average of the student's final grades this school year (0 when none). */
function spdAverage($studentId, $syId)
{
    $r = getRecord("SELECT AVG(final_grade) AS a FROM grades WHERE student_id = ? AND school_year_id = ? AND final_grade > 0", [(int) $studentId, (int) $syId]);
    return round((float) ($r['a'] ?? 0), 2);
}

/** Attendance summary: counts per status and the rate (present + late over all). */
function spdAttendance($studentId, $sectionId = null)
{
    $params = [(int) $studentId];
    $where = 'student_id = ?';
    if ($sectionId) { $where .= ' AND section_id = ?'; $params[] = (int) $sectionId; }
    $out = ['Present' => 0, 'Late' => 0, 'Absent' => 0, 'Excused' => 0, 'total' => 0, 'rate' => null];
    foreach (getRecords("SELECT status, COUNT(*) AS n FROM attendance WHERE $where GROUP BY status", $params) as $r) {
        $out[$r['status']] = (int) $r['n'];
        $out['total'] += (int) $r['n'];
    }
    if ($out['total'] > 0) $out['rate'] = round(100 * ($out['Present'] + $out['Late']) / $out['total'], 1);
    return $out;
}

/**
 * All classwork + assessment tasks of the student (this school year), newest
 * first: kind, subject, title, due, status (To do / Turned in / Missing /
 * Graded / Upcoming / Active / Completed), link.
 */
function spdTasks($studentId, array $ctx)
{
    $items = [];
    $names = [];
    foreach ($ctx['classes'] as $c) $names[$c['subject_code']] = $c['subject_name'];
    $has = getRecord("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classwork_assignment_students'");
    if ($has && (int) $has['n'] > 0) {
        foreach (getRecords(
            "SELECT ca.assignment_id, ca.subject_code, ca.title, ca.category, ca.due_date, ca.max_points, ca.assigned_at, ca.created_at,
                    cs.status, cs.score, cs.is_late
               FROM classwork_assignment_students cs
               JOIN classwork_assignments ca ON ca.assignment_id = cs.assignment_id AND ca.status = 'Published' AND ca.school_year_id = ?
              WHERE cs.student_id = ?",
            [(int) $ctx['sy_id'], (int) $studentId]
        ) as $r) {
            $overdue = !empty($r['due_date']) && strtotime($r['due_date']) < time();
            if ($r['status'] === 'Reviewed' && $r['score'] !== null) $state = 'Graded';
            elseif (in_array($r['status'], ['Submitted', 'Reviewed'], true)) $state = 'Turned in';
            else $state = $overdue ? 'Missing' : 'To do';
            $items[] = [
                'kind' => $r['category'] ?: 'Classwork', 'subject_code' => $r['subject_code'], 'subject' => $names[$r['subject_code']] ?? $r['subject_code'],
                'title' => $r['title'], 'due' => $r['due_date'], 'state' => $state,
                'score' => $r['score'] !== null ? rtrim(rtrim(number_format((float) $r['score'], 2), '0'), '.') . (!empty($r['max_points']) ? '/' . rtrim(rtrim(number_format((float) $r['max_points'], 2), '0'), '.') : '') : null,
                'posted' => $r['assigned_at'] ?: $r['created_at'],
                'link' => 'subject.php?subject=' . rawurlencode($r['subject_code']) . '&cw=' . (int) $r['assignment_id'],
            ];
        }
    }
    if (function_exists('aim_getStudentTasks')) {
        foreach ($ctx['classes'] as $c) {
            foreach (aim_getStudentTasks($studentId, (int) $c['section_id'], $c['subject_code'], (int) $c['school_year_id']) as $t) {
                $items[] = [
                    'kind' => $t['kind_label'] ?? $t['assessment_type'], 'subject_code' => $c['subject_code'], 'subject' => $c['subject_name'],
                    'title' => $t['title'], 'due' => $t['end_at'], 'state' => $t['display_status'], 'score' => null,
                    'posted' => $t['start_at'], 'link' => 'subject.php?subject=' . rawurlencode($c['subject_code']),
                ];
            }
        }
    }
    usort($items, function ($a, $b) { return strcmp((string) $b['posted'], (string) $a['posted']); });
    return $items;
}

/** Tasks still to do (not turned in, not missing), soonest due first. */
function spdUpcoming(array $tasks, $limit = 5)
{
    $up = array_values(array_filter($tasks, function ($t) { return in_array($t['state'], ['To do', 'Active', 'Upcoming'], true); }));
    usort($up, function ($a, $b) {
        $da = $a['due'] ?: '9999'; $db = $b['due'] ?: '9999';
        return strcmp($da, $db);
    });
    return array_slice($up, 0, $limit);
}

/** Class announcements of the student's classes and the teachers' messages to the student. */
function spdUpdates($studentId, array $ctx, $limit = 40)
{
    $names = [];
    foreach ($ctx['classes'] as $c) $names[$c['subject_code']] = $c['subject_name'];
    $announcements = [];
    if (!empty($ctx['enrollment']['section_id'])) {
        $posts = getRecords(
            "SELECT p.post_id, p.subject_code, p.body, p.created_at, p.updated_at, u.first_name, u.last_name
               FROM class_stream_posts p JOIN users u ON u.user_id = p.teacher_id
              WHERE p.section_id = ? AND p.school_year_id = ? AND p.is_deleted = 0
              ORDER BY p.created_at DESC LIMIT " . (int) $limit,
            [(int) $ctx['enrollment']['section_id'], (int) $ctx['sy_id']]
        );
        $atts = [];
        if ($posts) {
            $ids = array_map('intval', array_column($posts, 'post_id'));
            foreach (getRecords("SELECT * FROM class_stream_attachments WHERE post_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", $ids) as $a) $atts[(int) $a['post_id']][] = $a;
        }
        foreach ($posts as $p) {
            $p['subject'] = $names[$p['subject_code']] ?? $p['subject_code'];
            $p['attachments'] = $atts[(int) $p['post_id']] ?? [];
            $announcements[] = $p;
        }
    }
    $messages = getRecords(
        "SELECT m.message_id, m.subject_code, m.final_message, m.support_type, m.is_seen, m.sent_at, u.first_name, u.last_name
           FROM student_support_messages m LEFT JOIN users u ON u.user_id = m.teacher_id
          WHERE m.student_id = ? AND m.status = 'Sent'
          ORDER BY m.sent_at DESC LIMIT " . (int) $limit,
        [(int) $studentId]
    );
    foreach ($messages as &$m) $m['subject'] = $names[$m['subject_code']] ?? $m['subject_code'];
    unset($m);
    return ['announcements' => $announcements, 'messages' => $messages,
            'unread' => count(array_filter($messages, function ($m) { return !(int) $m['is_seen']; }))];
}
