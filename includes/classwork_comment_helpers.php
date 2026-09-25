<?php
// includes/classwork_comment_helpers.php
// Comments on a Classroom classwork, like Google Classroom:
//   class   -- seen by the teacher and every student of the class
//   private -- between the teacher and ONE student (student_id = that student)
// Student comments start unseen by the teacher (badge on the classwork card);
// a teacher reply to a private comment reaches the student's portal bell.

require_once __DIR__ . '/../config/database.php';

function cwcEnsureSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('classwork_comments_v1')) return;
    executeQuery(
        "CREATE TABLE IF NOT EXISTS classwork_comments (
            comment_id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL,
            visibility ENUM('class','private') NOT NULL DEFAULT 'class',
            student_id INT NULL COMMENT 'private: the student of the thread; class: the student who wrote it (NULL = teacher)',
            author_role ENUM('Teacher','Student') NOT NULL,
            author_id INT NOT NULL COMMENT 'users.user_id or students.student_id',
            body TEXT NOT NULL,
            seen_by_teacher TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_cwc (assignment_id, visibility, student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    schemaMarkReady('classwork_comments_v1');
}

/** Save one comment. @return int|false new comment_id */
function cwcAdd($assignmentId, $visibility, $studentId, $role, $authorId, $body)
{
    cwcEnsureSchema();
    $body = trim(str_replace("\r\n", "\n", (string) $body));
    if ($body === '') return false;
    if (mb_strlen($body) > 2000) $body = mb_substr($body, 0, 2000);
    $visibility = $visibility === 'private' ? 'private' : 'class';
    $role = $role === 'Teacher' ? 'Teacher' : 'Student';
    return insertRecord(
        "INSERT INTO classwork_comments (assignment_id, visibility, student_id, author_role, author_id, body, seen_by_teacher)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [(int) $assignmentId, $visibility, $studentId ? (int) $studentId : null, $role, (int) $authorId, $body, $role === 'Teacher' ? 1 : 0]
    );
}

/**
 * Comments for many classworks, with author names.
 * @return array [aid => ['class' => [...], 'private' => [student_id => [...]], 'unseen' => n]]
 */
function cwcForAssignments(array $assignmentIds, $onlyStudentId = null)
{
    cwcEnsureSchema();
    $ids = array_values(array_filter(array_map('intval', $assignmentIds)));
    $out = [];
    if (!$ids) return $out;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $where = "c.assignment_id IN ($ph)";
    if ($onlyStudentId !== null) {
        // a student sees the class comments and only their own private thread
        $where .= " AND (c.visibility = 'class' OR c.student_id = ?)";
        $params[] = (int) $onlyStudentId;
    }
    $rows = getRecords(
        "SELECT c.*,
                CASE WHEN c.author_role = 'Teacher' THEN TRIM(CONCAT(u.first_name, ' ', u.last_name))
                     ELSE TRIM(CONCAT(s.first_name, ' ', s.last_name)) END AS author_name
           FROM classwork_comments c
           LEFT JOIN users u ON c.author_role = 'Teacher' AND u.user_id = c.author_id
           LEFT JOIN students s ON c.author_role = 'Student' AND s.student_id = c.author_id
          WHERE $where
          ORDER BY c.created_at ASC, c.comment_id ASC",
        $params
    );
    foreach ($rows as $r) {
        $aid = (int) $r['assignment_id'];
        if (!isset($out[$aid])) $out[$aid] = ['class' => [], 'private' => [], 'unseen' => 0];
        if ($r['visibility'] === 'private') {
            $out[$aid]['private'][(int) $r['student_id']][] = $r;
        } else {
            $out[$aid]['class'][] = $r;
        }
        if ($r['author_role'] === 'Student' && !(int) $r['seen_by_teacher']) $out[$aid]['unseen']++;
    }
    return $out;
}

/** The teacher opened the classwork's student work: its comments are now seen. */
function cwcMarkSeen($assignmentId)
{
    cwcEnsureSchema();
    executeQuery("UPDATE classwork_comments SET seen_by_teacher = 1 WHERE assignment_id = ? AND seen_by_teacher = 0", [(int) $assignmentId]);
}

/** "Mar 3, 4:05 PM" */
function cwcWhen($ts)
{
    return date('M j, g:i A', strtotime((string) $ts));
}
