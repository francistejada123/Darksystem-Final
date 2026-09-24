<?php
// includes/student_activity_helpers.php
//
// Student Activity / Engagement Monitoring (Section 4 of the improvement
// spec). Lightweight, additive, and scoped to real E-GRADEPRO academic
// engagement only:
//   - students.last_active_at  a "last seen" heartbeat, updated on every
//     Student Portal page load (see touchStudentActivity()). This alone
//     drives the Online / Recently Active / Inactive indicator.
//   - student_activity_log     specific engagement events, written only by
//     logStudentActivity() below, and only for events actually observed by
//     the app (a login, a page genuinely opened, a video progress report).
//
// This file NEVER touches grades, risk_level, or ai_analysis — activity
// here is display/context only. getStudentEngagementSummaryForAI() below
// is the one place this data is handed to Gemini, and it only ever lists
// facts pulled straight from these tables — it does not let the model add
// anything of its own.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/tutorial_helpers.php'; // getTutorialWatchStatusLabel()

/**
 * Update the lightweight "last seen" heartbeat for a student. Safe to call
 * on every Student Portal page load — it's a single UPDATE, no logging
 * noise, and is the only thing the Online/Recently Active/Inactive badge
 * is based on.
 */
function touchStudentActivity($student_id)
{
    $student_id = (int) $student_id;
    if (!$student_id) {
        return;
    }
    executeQuery("UPDATE students SET last_active_at = NOW() WHERE student_id = ?", [$student_id]);
}

/**
 * Record one specific, real engagement event for a student (also touches
 * last_active_at, so callers don't need to call both).
 *
 * @param int         $student_id
 * @param string      $event_type        e.g. 'login', 'tutorial_viewed', 'reviewer_viewed', 'quiz_viewed', 'message_viewed', 'video_progress'
 * @param string|null $description       Short, human-readable note (never AI-generated).
 * @param string|null $reference_table   Optional table the event relates to.
 * @param int|null    $reference_id      Optional row id in that table.
 * @param int         $throttle_minutes  If > 0, skip inserting a new row when the
 *   most recent row of the SAME event_type (+ same reference_id, if given)
 *   for this student is younger than this many minutes — avoids flooding the
 *   log with one entry per page reload for routine views. last_active_at is
 *   still updated either way.
 */
function logStudentActivity($student_id, $event_type, $description = null, $reference_table = null, $reference_id = null, $throttle_minutes = 0)
{
    $student_id = (int) $student_id;
    $event_type = trim((string) $event_type);
    if (!$student_id || $event_type === '') {
        return;
    }

    touchStudentActivity($student_id);

    if ($throttle_minutes > 0) {
        $params = [$student_id, $event_type];
        $ref_sql = '';
        if ($reference_id !== null) {
            $ref_sql = ' AND reference_id = ?';
            $params[] = (int) $reference_id;
        }
        $params[] = $throttle_minutes;
        $recent = getRecord(
            "SELECT activity_id FROM student_activity_log
             WHERE student_id = ? AND event_type = ?" . $ref_sql . "
               AND created_at >= (NOW() - INTERVAL ? MINUTE)
             ORDER BY created_at DESC LIMIT 1",
            $params
        );
        if ($recent) {
            return; // already logged recently enough — heartbeat above still ran
        }
    }

    executeQuery(
        "INSERT INTO student_activity_log (student_id, event_type, reference_table, reference_id, description)
         VALUES (?, ?, ?, ?, ?)",
        [$student_id, $event_type, $reference_table, $reference_id, $description]
    );
}

/**
 * Human-readable relative time ("Just now", "5 minutes ago", "2 hours ago",
 * "3 days ago", falling back to a plain date beyond ~30 days). No external
 * library — this app has no existing time-ago helper to reuse.
 *
 * @param string|null $datetime MySQL DATETIME/TIMESTAMP string, or null.
 */
function timeAgoHuman($datetime)
{
    if (empty($datetime)) {
        return null;
    }
    $then = strtotime($datetime);
    if ($then === false) {
        return null;
    }
    $diff = time() - $then;
    if ($diff < 0) $diff = 0;

    if ($diff < 60) return 'Just now';
    if ($diff < 3600) {
        $m = (int) floor($diff / 60);
        return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $h = (int) floor($diff / 3600);
        return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 2592000) { // ~30 days
        $d = (int) floor($diff / 86400);
        return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
    }
    return date('M j, Y', $then);
}

/**
 * Online/Recently Active/Inactive status for a student, based purely on
 * students.last_active_at (the lightweight heartbeat) — never invasive
 * click-by-click tracking, just "was a Student Portal page opened recently".
 *
 * Thresholds: Online <= 5 minutes, Recently Active <= 24 hours, otherwise
 * Inactive. A student who has never been active (NULL) is reported as
 * 'none' so callers can show a neutral "No activity yet" instead of a
 * misleading "Inactive".
 *
 * @return array{code:string, label:string, badge_class:string, last_active_human:?string, last_active_at:?string}
 */
function getStudentActivityStatus($student_id)
{
    $student_id = (int) $student_id;
    $row = $student_id ? getRecord("SELECT last_active_at FROM students WHERE student_id = ?", [$student_id]) : null;
    $last_active_at = $row['last_active_at'] ?? null;

    if (empty($last_active_at)) {
        return [
            'code' => 'none',
            'label' => 'No Activity Yet',
            'badge_class' => 'secondary',
            'last_active_human' => null,
            'last_active_at' => null,
        ];
    }

    $diff = time() - strtotime($last_active_at);
    if ($diff <= 300) {
        $code = 'online';
        $label = 'Online';
        $badge_class = 'success';
    } elseif ($diff <= 86400) {
        $code = 'recent';
        $label = 'Recently Active';
        $badge_class = 'warning text-dark';
    } else {
        $code = 'inactive';
        $label = 'Inactive';
        $badge_class = 'secondary';
    }

    return [
        'code' => $code,
        'label' => $label,
        'badge_class' => $badge_class,
        'last_active_human' => timeAgoHuman($last_active_at),
        'last_active_at' => $last_active_at,
    ];
}

/**
 * Recent engagement events for a student, newest first — for the staff-side
 * "Recent Activity" panel (modules/students/view.php).
 */
function getRecentStudentActivity($student_id, $limit = 10)
{
    $student_id = (int) $student_id;
    $limit = max(1, min(50, (int) $limit));
    if (!$student_id) {
        return [];
    }
    return getRecords(
        "SELECT * FROM student_activity_log WHERE student_id = ? ORDER BY created_at DESC LIMIT $limit",
        [$student_id]
    );
}

/** Plain-language label for an event_type, for staff-facing display. */
function getStudentActivityEventLabel($event_type)
{
    $labels = [
        'login'            => 'Logged into the Student Portal',
        'tutorial_viewed'  => 'Viewed the Tutorial Plan',
        'reviewer_viewed'  => 'Opened an AI Study Reviewer',
        'quiz_viewed'      => 'Opened an AI Practice Quiz',
        'class_content_viewed' => 'Opened class material from a teacher',
        'message_viewed'   => 'Viewed a teacher support message',
        'video_progress'   => 'Watched a tutorial video',
    ];
    return $labels[$event_type] ?? ucfirst(str_replace('_', ' ', $event_type));
}

/**
 * Real, recorded engagement facts for ONE student, scoped to ONE subject —
 * built for Ask AI (includes/gemini_service.php) so it can reference actual
 * activity ("hasn't opened the reviewer yet") without ever inventing any.
 * Every line here comes straight from a table row; nothing is guessed.
 *
 * @return string[] Plain-text bullet lines, already student/subject-scoped. Empty array if nothing relevant is on file yet.
 */
function getStudentEngagementSummaryForAI($student_id, $subject_code)
{
    $student_id = (int) $student_id;
    $subject_code = trim((string) $subject_code);
    if (!$student_id || $subject_code === '') {
        return [];
    }

    $lines = [];

    // Overall portal activity.
    $status = getStudentActivityStatus($student_id);
    if ($status['code'] === 'none') {
        $lines[] = 'Has never logged into the Student Portal yet.';
    } else {
        $lines[] = 'Last active in the Student Portal: ' . $status['last_active_human'] . '.';
    }

    // Tutorial video status for THIS subject, if the student has a
    // tutorial plan item for it (reuses the same status already shown on
    // the Tutorial Plan / Student Portal — never a separate source of truth).
    $tutorial_item = getRecord(
        "SELECT tpi.status, tpi.watch_progress_percentage
         FROM tutorial_plan_items tpi
         JOIN tutorial_plans tp ON tpi.plan_id = tp.plan_id
         WHERE tp.student_id = ? AND tpi.subject_code = ? AND tp.status IN ('Draft','Active')
         ORDER BY tpi.item_id DESC LIMIT 1",
        [$student_id, $subject_code]
    );
    if ($tutorial_item) {
        $label = getTutorialWatchStatusLabel($tutorial_item['status']);
        $lines[] = 'Tutorial video for this subject: ' . $label .
            ($tutorial_item['status'] === 'In Progress' ? ' (' . round((float) $tutorial_item['watch_progress_percentage']) . '% watched)' : '') . '.';
    }

    // Published AI study reviewers for this student+subject — viewed or not.
    $reviewers = getRecords(
        "SELECT reviewer_id, published_at FROM ai_reviewers WHERE student_id = ? AND subject_code = ? AND status = 'Published' ORDER BY published_at DESC LIMIT 3",
        [$student_id, $subject_code]
    );
    foreach ($reviewers as $rev) {
        $viewed = getRecord(
            "SELECT created_at FROM student_activity_log WHERE student_id = ? AND event_type = 'reviewer_viewed' AND reference_id = ? ORDER BY created_at DESC LIMIT 1",
            [$student_id, $rev['reviewer_id']]
        );
        if ($viewed) {
            $lines[] = 'Opened the study reviewer for this subject ' . timeAgoHuman($viewed['created_at']) . '.';
        } else {
            $lines[] = 'Has NOT opened the study reviewer for this subject yet (published ' . timeAgoHuman($rev['published_at']) . ').';
        }
    }

    // Published AI practice quizzes — same viewed/not-viewed check.
    $quizzes = getRecords(
        "SELECT quiz_id, published_at FROM ai_practice_quizzes WHERE student_id = ? AND subject_code = ? AND status = 'Published' ORDER BY published_at DESC LIMIT 3",
        [$student_id, $subject_code]
    );
    foreach ($quizzes as $quiz) {
        $viewed = getRecord(
            "SELECT created_at FROM student_activity_log WHERE student_id = ? AND event_type = 'quiz_viewed' AND reference_id = ? ORDER BY created_at DESC LIMIT 1",
            [$student_id, $quiz['quiz_id']]
        );
        if ($viewed) {
            $lines[] = 'Opened the practice quiz for this subject ' . timeAgoHuman($viewed['created_at']) . '.';
        } else {
            $lines[] = 'Has NOT opened the practice quiz for this subject yet (published ' . timeAgoHuman($quiz['published_at']) . ').';
        }
    }

    // Most recent support message for this subject — seen or not.
    $last_msg = getRecord(
        "SELECT is_seen, seen_at, sent_at FROM student_support_messages WHERE student_id = ? AND subject_code = ? AND status = 'Sent' ORDER BY sent_at DESC LIMIT 1",
        [$student_id, $subject_code]
    );
    if ($last_msg) {
        $lines[] = (int) $last_msg['is_seen'] === 1
            ? 'Viewed the last support message for this subject ' . timeAgoHuman($last_msg['seen_at']) . '.'
            : 'Has NOT yet viewed the last support message sent for this subject (sent ' . timeAgoHuman($last_msg['sent_at']) . ').';
    }

    return $lines;
}
