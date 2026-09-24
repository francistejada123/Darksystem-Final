<?php
// modules/teachers/message_comments.php
//
// Student comments on messages this teacher sent (AI Review, Teacher AI
// activities, ...). Opened from the bell notification "New comment from
// <student>". With ?message_id= it shows that message and its thread with a
// reply box; without it, the latest comments from all of this teacher's
// students. See includes/message_comment_helpers.php.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/message_comment_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
if (!hasRole('Teacher')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$teacher_id = (int) $_SESSION['user_id'];
$message_id = (int) ($_GET['message_id'] ?? $_POST['message_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token.';
    } else {
        $r = mc_addTeacherReply($teacher_id, $message_id, $_POST['reply'] ?? '');
        if ($r['success']) {
            $_SESSION['success_message'] = 'Reply sent. The student will see it under the message on their subject page.';
        } else {
            $_SESSION['error_message'] = $r['error'];
        }
    }
    header('Location: message_comments.php?message_id=' . $message_id);
    exit;
}

$page_title = 'Student Comments';
$current_page = 'notifications';
$base_url = BASE_URL;
$csrf_token = generateCSRFToken();
mc_ensureTable();

$message = null;
$thread = [];
if ($message_id > 0) {
    $message = getRecord(
        "SELECT m.*, TRIM(CONCAT(s.first_name, ' ', s.last_name)) AS student_name, sub.subject_name
         FROM student_support_messages m
         INNER JOIN students s ON s.student_id = m.student_id
         LEFT JOIN subjects sub ON sub.subject_code = m.subject_code
         WHERE m.message_id = ? AND m.teacher_id = ?",
        [$message_id, $teacher_id]
    );
    if ($message) {
        $thread = mc_getComments([$message_id])[$message_id] ?? [];
    }
}
$recent = $message ? [] : getRecords(
    "SELECT c.comment_id, c.message_id, c.body, c.created_at, m.support_type, m.subject_code,
            TRIM(CONCAT(s.first_name, ' ', s.last_name)) AS student_name, sub.subject_name
     FROM student_message_comments c
     INNER JOIN student_support_messages m ON m.message_id = c.message_id AND m.teacher_id = ?
     INNER JOIN students s ON s.student_id = c.author_id
     LEFT JOIN subjects sub ON sub.subject_code = m.subject_code
     WHERE c.author_role = 'Student'
     ORDER BY c.created_at DESC
     LIMIT 50",
    [$teacher_id]
);

include __DIR__ . '/../../includes/header.php';
?>
<style>
    .mc-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 16px 18px; margin-bottom: 14px; }
    .mc-meta { font-size: 12px; color: var(--text-muted); }
    .mc-type { display:inline-block; font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px; background:#EDF4FB; color:#14213D; margin:6px 0; }
    .mc-text { font-size: 14px; color: var(--ink); white-space: pre-line; line-height: 1.6; }
    .mc-thread { margin-top: 14px; border-top: 1px solid var(--border-soft); padding-top: 10px; }
    .mc-comment { padding: 8px 10px; border-radius: 8px; margin-bottom: 8px; font-size: 13.5px; }
    .mc-comment span { white-space: pre-line; }
    .mc-comment.student { background: #F5F6FF; }
    .mc-comment.teacher { background: #E9F9F1; margin-left: 24px; }
    .mc-comment b { display:block; font-size:12px; margin-bottom:2px; }
    .mc-comment small { color: var(--text-muted); font-weight:400; margin-left:6px; }
    .mc-row { display:block; text-decoration:none; color:inherit; }
    .mc-row:hover .mc-card { border-color:#14213D; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 mt-3 flex-wrap gap-2">
    <h4 class="mb-0" style="color: var(--ink);font-weight:600;"><i class="fas fa-comments me-2"></i>Student Comments</h4>
    <?php if ($message_id): ?>
        <a href="message_comments.php" class="btn btn-sm" style="border:1px solid var(--border);color:var(--text-muted);background:var(--surface);"><i class="fas fa-arrow-left me-1"></i> All comments</a>
    <?php endif; ?>
</div>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($message_id && !$message): ?>
    <div class="mc-card">That message could not be found.</div>
<?php elseif ($message): ?>
    <div class="mc-card">
        <div class="mc-meta">To <strong><?php echo htmlspecialchars($message['student_name']); ?></strong> · <?php echo htmlspecialchars($message['subject_name'] ?: $message['subject_code']); ?> · <?php echo $message['sent_at'] ? date('M d, Y g:i A', strtotime($message['sent_at'])) : ''; ?></div>
        <span class="mc-type"><?php echo htmlspecialchars($message['support_type']); ?></span>
        <div class="mc-text"><?php echo htmlspecialchars($message['final_message']); ?></div>

        <div class="mc-thread">
            <?php if (!$thread): ?>
                <div class="mc-meta">No comments yet.</div>
            <?php endif; ?>
            <?php foreach ($thread as $c): ?>
                <div class="mc-comment <?php echo $c['author_role'] === 'Teacher' ? 'teacher' : 'student'; ?>">
                    <b><?php echo htmlspecialchars($c['author_role'] === 'Teacher' ? 'You' : $c['author_name']); ?><small><?php echo date('M d, Y g:i A', strtotime($c['created_at'])); ?></small></b><span><?php echo htmlspecialchars($c['body']); ?></span>
                </div>
            <?php endforeach; ?>

            <form method="POST" class="mt-2">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="message_id" value="<?php echo (int) $message_id; ?>">
                <textarea name="reply" class="form-control" rows="3" maxlength="<?php echo MC_MAX_LENGTH; ?>" placeholder="Reply to <?php echo htmlspecialchars($message['student_name']); ?>..." required style="font-size:13.5px;"></textarea>
                <div class="d-flex justify-content-end mt-2">
                    <button type="submit" class="btn btn-sm" style="background:#14213D;color:#fff;font-weight:600;"><i class="fas fa-reply me-1"></i>Send Reply</button>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <?php if (!$recent): ?>
        <div class="mc-card mc-meta">No student comments yet.</div>
    <?php endif; ?>
    <?php foreach ($recent as $c): ?>
        <a class="mc-row" href="message_comments.php?message_id=<?php echo (int) $c['message_id']; ?>">
            <div class="mc-card">
                <div class="mc-meta"><strong><?php echo htmlspecialchars($c['student_name']); ?></strong> · <?php echo htmlspecialchars($c['subject_name'] ?: $c['subject_code']); ?> · <?php echo htmlspecialchars($c['support_type']); ?> · <?php echo date('M d, Y g:i A', strtotime($c['created_at'])); ?></div>
                <div class="mc-text mt-1"><?php echo htmlspecialchars($c['body']); ?></div>
            </div>
        </a>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
