<?php
// includes/message_comment_helpers.php
//
// Comments under a message on a student's subject page (an AI Review, a
// Teacher AI activity, a quiz notice, ...). The student can ask about it;
// the teacher who sent the message is notified in the topbar bell
// (notifications table) and can reply from modules/teachers/message_comments.php.
// A teacher reply marks the message unseen again so the student's class card
// shows "New". Self-migrating like the rest of the codebase; additive only.

require_once __DIR__ . '/../config/database.php';

const MC_MAX_LENGTH = 1000;

function mc_ensureTable()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('message_comments_v1')) return;
    executeQuery(
        "CREATE TABLE IF NOT EXISTS student_message_comments (
            comment_id INT(11) NOT NULL AUTO_INCREMENT,
            message_id INT(11) NOT NULL COMMENT 'student_support_messages.message_id',
            author_role ENUM('Student','Teacher') NOT NULL,
            author_id INT(11) NOT NULL COMMENT 'students.student_id or users.user_id',
            body TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (comment_id),
            KEY idx_smc_message (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    schemaMarkReady('message_comments_v1');
}

/** Comments for many messages at once: message_id => list (oldest first), with author names. */
function mc_getComments(array $message_ids)
{
    mc_ensureTable();
    $message_ids = array_values(array_unique(array_filter(array_map('intval', $message_ids))));
    if (!$message_ids) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($message_ids), '?'));
    $rows = getRecords(
        "SELECT c.*,
                CASE WHEN c.author_role = 'Student' THEN TRIM(CONCAT(s.first_name, ' ', s.last_name))
                     ELSE TRIM(CONCAT(u.first_name, ' ', u.last_name)) END AS author_name
         FROM student_message_comments c
         LEFT JOIN students s ON c.author_role = 'Student' AND s.student_id = c.author_id
         LEFT JOIN users u ON c.author_role = 'Teacher' AND u.user_id = c.author_id
         WHERE c.message_id IN ($ph)
         ORDER BY c.created_at ASC, c.comment_id ASC",
        $message_ids
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['message_id']][] = $r;
    }
    return $out;
}

/** Clean a comment body; null when empty. */
function mc_cleanBody($body)
{
    $body = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $body));
    if ($body === '') {
        return null;
    }
    return mb_substr($body, 0, MC_MAX_LENGTH);
}

/**
 * A student comments on one of their own messages; the teacher who sent it
 * is notified.
 * @return array{success:bool, error?:string, comment_id?:int}
 */
function mc_addStudentComment($student_id, $message_id, $body)
{
    mc_ensureTable();
    $body = mc_cleanBody($body);
    if ($body === null) {
        return ['success' => false, 'error' => 'Please write your comment first.'];
    }
    $msg = getRecord(
        "SELECT m.message_id, m.teacher_id, m.subject_code, m.support_type, sub.subject_name,
                TRIM(CONCAT(s.first_name, ' ', s.last_name)) AS student_name
         FROM student_support_messages m
         INNER JOIN students s ON s.student_id = m.student_id
         LEFT JOIN subjects sub ON sub.subject_code = m.subject_code
         WHERE m.message_id = ? AND m.student_id = ? AND m.status = 'Sent'",
        [(int) $message_id, (int) $student_id]
    );
    if (!$msg) {
        return ['success' => false, 'error' => 'That message could not be found.'];
    }
    $id = insertRecord(
        "INSERT INTO student_message_comments (message_id, author_role, author_id, body) VALUES (?, 'Student', ?, ?)",
        [(int) $message_id, (int) $student_id, $body]
    );
    if (!$id) {
        return ['success' => false, 'error' => 'Your comment could not be sent. Please try again.'];
    }
    $excerpt = mb_strlen($body) > 120 ? mb_substr($body, 0, 117) . '...' : $body;
    executeQuery(
        "INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (?, ?, ?, 'info', ?, NOW())",
        [
            (int) $msg['teacher_id'],
            'New comment from ' . $msg['student_name'],
            ($msg['subject_name'] ?: $msg['subject_code']) . ' · ' . $msg['support_type'] . ': "' . $excerpt . '"',
            (defined('BASE_URL') ? BASE_URL : '/watdahel/') . 'modules/teachers/message_comments.php?message_id=' . (int) $message_id,
        ]
    );
    return ['success' => true, 'comment_id' => (int) $id];
}

/**
 * The teacher who sent a message replies under it; the student's class card
 * shows "New" again.
 * @return array{success:bool, error?:string}
 */
function mc_addTeacherReply($teacher_id, $message_id, $body)
{
    mc_ensureTable();
    $body = mc_cleanBody($body);
    if ($body === null) {
        return ['success' => false, 'error' => 'Please write your reply first.'];
    }
    $msg = getRecord("SELECT message_id FROM student_support_messages WHERE message_id = ? AND teacher_id = ?", [(int) $message_id, (int) $teacher_id]);
    if (!$msg) {
        return ['success' => false, 'error' => 'That message could not be found.'];
    }
    $id = insertRecord(
        "INSERT INTO student_message_comments (message_id, author_role, author_id, body) VALUES (?, 'Teacher', ?, ?)",
        [(int) $message_id, (int) $teacher_id, $body]
    );
    if (!$id) {
        return ['success' => false, 'error' => 'Your reply could not be sent. Please try again.'];
    }
    executeQuery("UPDATE student_support_messages SET is_seen = 0, seen_at = NULL WHERE message_id = ?", [(int) $message_id]);
    return ['success' => true];
}
