<?php
// includes/student_email_notify.php
// Email the student when the teacher publishes new work: Classwork (quiz,
// activity, assignment), a Learning Material task, a Teacher AI resource sent
// to the class, or an Ask AI / catch-up make-up.
//
//   senNotifyStudents($studentIds, $info)  queues one email per student and
//     sends them AFTER the teacher's page/JSON reply has gone out (nobody waits
//     for Gmail). $info: key (unique per published thing), subject_code,
//     teacher_id, title, kind (Quiz / Activity / ...), deadline (optional).
//
// Duplicates are impossible: student_email_notifications has a UNIQUE
// (student_id, notify_key), so a refresh / re-post never mails twice. The
// email goes to students.email -- the address verified in Online Enrollment
// and copied to the student's account when the application is approved.
// Settings > Email: "email_task_notifications" = 0 turns sending off (the
// rows are then kept as Skipped).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mail_config.php'; // SMTP settings (.env / environment first), clear error reasons

function senEnsureSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('student_email_notifications_v1')) return;
    executeQuery(
        "CREATE TABLE IF NOT EXISTS student_email_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            notify_key VARCHAR(100) NOT NULL COMMENT 'what was published, e.g. classwork:12 -- one email per student per key',
            email VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            status ENUM('Queued','Sent','Failed','Skipped') NOT NULL DEFAULT 'Queued',
            error VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            UNIQUE KEY uniq_sen (student_id, notify_key),
            KEY idx_sen_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    schemaMarkReady('student_email_notifications_v1');
}

/**
 * Full URL of a page in this app for an email button. Settings key
 * "portal_public_url" (e.g. https://school.example/watdahel/) wins, so the link
 * also works outside the school network; otherwise the current host is used.
 */
function senAppUrl($path)
{
    $row = getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'portal_public_url'");
    $base = trim((string) ($row['setting_value'] ?? ''));
    if ($base === '' || !preg_match('#^https?://#i', $base)) {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $base = ($https ? 'https' : 'http') . '://' . $host . (defined('BASE_URL') ? BASE_URL : '/');
    }
    return rtrim($base, '/') . '/' . ltrim((string) $path, '/');
}

/** What the last senNotifyStudents() call did: queued now / students with no registered email. */
function senLastResult(array $set = null)
{
    static $last = ['queued' => 0, 'no_email' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'reason' => ''];
    if ($set !== null) $last = $set + $last;
    return $last;
}

/** Is task email sending switched on? (default: on) */
function senEnabled()
{
    $row = getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'email_task_notifications'");
    return !$row || (string) $row['setting_value'] !== '0';
}

/**
 * Queue (and, after the response, send) one email per student.
 * @param int[] $studentIds
 * @param array $info key, subject_code, teacher_id, title, kind, deadline(optional),
 *                    instruction(optional short text), link(optional app path for the button),
 *                    grading (optional WW / PT / QA -- "New Written Work Posted - English 7"),
 *                    topic (optional), posted_at (optional, default now),
 *                    send_now (optional: send right away and report sent / failed, instead of after the reply),
 *                    announcement (optional: the full text of a Stream announcement -- the email is then
 *                    "New Announcement - English 7" with the message instead of an assessment summary),
 *                    attachments (optional: names of attached files / links, shown in the email),
 *                    updated (optional: the post was edited -- "Updated: ..." email; use a new key per update)
 * @return int emails queued now
 */
function senNotifyStudents(array $studentIds, array $info)
{
    try {
        senEnsureSchema();
        senLastResult(['queued' => 0, 'no_email' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'reason' => '']);
        $ids = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
        $key = mb_substr(trim((string) ($info['key'] ?? '')), 0, 100);
        if (!$ids || $key === '') return 0;

        $subject = getRecord("SELECT subject_name FROM subjects WHERE subject_code = ?", [(string) ($info['subject_code'] ?? '')]);
        $subjectName = $subject['subject_name'] ?? (string) ($info['subject_code'] ?? 'your class');
        $teacher = getRecord("SELECT first_name, last_name FROM users WHERE user_id = ?", [(int) ($info['teacher_id'] ?? 0)]);
        $teacherName = $teacher ? trim($teacher['first_name'] . ' ' . $teacher['last_name']) : 'Your teacher';
        $title = trim(html_entity_decode((string) ($info['title'] ?? 'New task'), ENT_QUOTES, 'UTF-8'));
        $kind = trim((string) ($info['kind'] ?? 'Task')) ?: 'Task';
        $deadline = !empty($info['deadline']) && strtotime($info['deadline']) ? date('F j, Y g:i A', strtotime($info['deadline'])) : '';
        $school = function_exists('getSchoolName') ? getSchoolName() : 'EGradePro';
        $instruction = trim(preg_replace('/\s+/u', ' ', html_entity_decode((string) ($info['instruction'] ?? ''), ENT_QUOTES, 'UTF-8')));
        if (mb_strlen($instruction) > 300) $instruction = mb_substr($instruction, 0, 297) . '...';
        $link = senAppUrl(!empty($info['link']) ? (string) $info['link'] : 'student_login.php');
        $topic = trim(html_entity_decode((string) ($info['topic'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $posted = date('F j, Y g:i A', !empty($info['posted_at']) && strtotime($info['posted_at']) ? strtotime($info['posted_at']) : time());
        // Written Works / Performance Task / Examination name the email; other work uses its own kind.
        $gradingNames = ['WW' => 'Written Work', 'PT' => 'Performance Task', 'QA' => 'Examination',
                         'Written Works' => 'Written Work', 'Performance Task' => 'Performance Task', 'Examination' => 'Examination'];
        $postedAs = $gradingNames[(string) ($info['grading'] ?? '')] ?? $kind;
        $typeLabel = $postedAs === $kind ? $kind : $postedAs . ($kind !== '' && stripos($postedAs, $kind) === false ? ' (' . $kind . ')' : '');
        $article = preg_match('/^[AEIOU]/i', $postedAs) ? 'an' : 'a';

        $ph = implode(',', array_fill(0, count($ids), '?'));
        // students.email = the address given in (online) enrollment, never one typed by the teacher
        $students = getRecords("SELECT student_id, first_name, last_name, email FROM students WHERE is_active = 1 AND student_id IN ($ph)", $ids);
        $queued = 0;
        $noEmail = 0;
        foreach ($students as $s) {
            $to = trim((string) $s['email']);
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                // Recorded so the teacher / admin can see who was not emailed and why (the portal notice still shows).
                $noEmail++;
                executeQuery(
                    "INSERT IGNORE INTO student_email_notifications (student_id, notify_key, email, subject, body, status, error) VALUES (?, ?, ?, ?, '', 'Failed', ?)",
                    [(int) $s['student_id'], $key, mb_substr($to, 0, 255), mb_substr(isset($info['announcement']) ? "New Announcement - {$subjectName}" : "New {$postedAs} Posted - {$subjectName}", 0, 250),
                     $to === '' ? 'Missing student email (no email saved in the enrollment / student record)' : 'Invalid recipient email: ' . mb_substr($to, 0, 200)]
                );
                continue;
            }
            $mailSubject = mb_substr("New {$postedAs} Posted - {$subjectName}", 0, 250);
            $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
            $row = function ($label, $value, $strong = false) use ($e) {
                return '<tr><td style="color:#64748b;vertical-align:top;white-space:nowrap;">' . $e($label) . '</td><td>'
                    . ($strong ? '<strong>' . $e($value) . '</strong>' : nl2br($e($value))) . '</td></tr>';
            };
            $body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;max-width:560px;">'
                . '<p>Hi ' . $e(trim((string) $s['first_name'])) . ',</p>'
                . '<p>' . (!empty($info['updated']) ? 'Your teacher has <strong>updated</strong> the <strong>' . $e($postedAs) . '</strong>' : 'Your teacher has posted ' . $article . ' new <strong>' . $e($postedAs) . '</strong>') . ' for <strong>' . $e($subjectName) . '</strong> titled '
                . '&lsquo;<strong>' . $e($title) . '</strong>&rsquo;. Due date: <strong>' . $e($deadline !== '' ? $deadline : 'no due date') . '</strong>. '
                . 'Please log in to your EGradePro Student Portal to view the complete instructions and submit your work.</p>'
                . '<table cellpadding="6" style="border-collapse:collapse;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">'
                . $row('Student', trim($s['first_name'] . ' ' . ($s['last_name'] ?? '')))
                . $row('Teacher', $teacherName)
                . $row('Subject', $subjectName, true)
                . $row('Assessment type', $typeLabel)
                . $row('Title', $title, true)
                . ($topic !== '' ? $row('Topic', $topic) : '')
                . ($instruction !== '' ? $row('Instructions', $instruction) : '')
                . $row('Date posted', $posted)
                . $row('Due date', $deadline !== '' ? $deadline : 'No due date', true)
                . '</table>'
                . '<p style="margin:18px 0;"><a href="' . $e($link) . '" style="background:#2563eb;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;display:inline-block;font-weight:bold;">Log in to EGradePro to view &amp; submit</a></p>'
                . '<p style="font-size:12px;color:#64748b;">If the button does not work, copy this link: ' . $e($link) . '<br>Sign in with your LRN, then open ' . $e($subjectName) . ' &rsaquo; Classwork to view/download the activity and submit your work.</p>'
                . '<p style="color:#64748b;font-size:12px;">This is an automatic message from ' . $e($school) . ' EGradePro. Please do not reply.</p>'
                . '</div>';
            if (isset($info['announcement'])) {
                // A Stream announcement: the teacher's message itself, not an assessment summary.
                $annText = trim(html_entity_decode((string) $info['announcement'], ENT_QUOTES, 'UTF-8'));
                $files = array_values(array_filter(array_map('strval', (array) ($info['attachments'] ?? []))));
                $mailSubject = mb_substr("New Announcement - {$subjectName}", 0, 250);
                $body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;max-width:560px;">'
                    . '<p>Hi ' . $e(trim((string) $s['first_name'])) . ',</p>'
                    . '<p>Your teacher <strong>' . $e($teacherName) . '</strong> ' . (!empty($info['updated']) ? '<strong>updated</strong> an announcement' : 'posted a new announcement') . ' in <strong>' . $e($subjectName) . '</strong>:</p>'
                    . ($annText !== '' ? '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #2563eb;border-radius:8px;padding:12px 14px;white-space:normal;">' . nl2br($e(mb_strimwidth($annText, 0, 3000, '…'))) . '</div>' : '')
                    . ($files ? '<p style="margin-top:12px;"><strong>Attached:</strong><br>' . implode('<br>', array_map(function ($f) use ($e) { return '&bull; ' . $e($f); }, array_slice($files, 0, 10))) . '</p>' : '')
                    . '<p style="color:#64748b;font-size:12px;">Posted ' . $e($posted) . '</p>'
                    . '<p style="margin:18px 0;"><a href="' . $e($link) . '" style="background:#2563eb;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;display:inline-block;font-weight:bold;">Log in to EGradePro to view</a></p>'
                    . '<p style="font-size:12px;color:#64748b;">If the button does not work, copy this link: ' . $e($link) . '<br>Sign in with your LRN, then open ' . $e($subjectName) . ' &rsaquo; Stream.</p>'
                    . '<p style="color:#64748b;font-size:12px;">This is an automatic message from ' . $e($school) . ' EGradePro. Please do not reply.</p>'
                    . '</div>';
            }
            if (!empty($info['updated'])) $mailSubject = mb_substr('Updated: ' . $mailSubject, 0, 250);
            $stmt = executeQuery(
                "INSERT IGNORE INTO student_email_notifications (student_id, notify_key, email, subject, body) VALUES (?, ?, ?, ?, ?)",
                [(int) $s['student_id'], $key, $to, $mailSubject, $body]
            );
            if ($stmt && $stmt->rowCount() > 0) $queued++;
        }
        $result = ['queued' => $queued, 'no_email' => $noEmail + max(0, count($ids) - count($students)), 'sent' => 0, 'failed' => 0, 'pending' => $queued, 'reason' => ''];
        if ($queued > 0) {
            if (!empty($info['send_now'])) {
                // Send while the teacher waits, so the page can say "sent to 34 of 35".
                @set_time_limit(60 + 5 * $queued);
                $d = senDispatchQueued(500, $key);
                $result['sent'] = $d['sent'];
                $result['failed'] = $d['failed'];
                $result['pending'] = max(0, $queued - $d['sent'] - $d['failed']);
                $result['reason'] = $d['reason'] ?? '';
                $result['switched_off'] = !empty($d['skipped']);
            } else {
                senScheduleDispatch();
            }
        }
        senLastResult($result);
        return $queued;
    } catch (\Throwable $e) {
        error_log('Student email notification could not be queued: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Send the queued emails once the current response has been delivered.
 * The reply (page, redirect or JSON) is buffered, sent with Content-Length
 * and Connection: close, then the mail goes out -- the teacher never waits.
 */
function senScheduleDispatch()
{
    static $scheduled = false;
    if ($scheduled) return;
    $scheduled = true;
    if (!headers_sent()) {
        ob_start();
    }
    register_shutdown_function(function () {
        ignore_user_abort(true);
        @set_time_limit(300);
        if (!headers_sent() && PHP_SAPI !== 'cli') {
            $out = '';
            while (ob_get_level() > 0) { $out = ob_get_clean() . $out; }
            header('Content-Length: ' . strlen($out));
            header('Connection: close');
            echo $out;
            flush();
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        senDispatchQueued();
    });
}

/**
 * Send (or skip when switched off) up to $limit queued emails over ONE SMTP
 * connection; $onlyKey limits it to one post. Every failure is saved with a
 * clear reason (SMTP authentication failed, connection timeout, ...).
 * @return array sent, failed, skipped, reason (first failure reason)
 */
function senDispatchQueued($limit = 200, $onlyKey = null)
{
    senEnsureSchema();
    $rows = $onlyKey !== null
        ? getRecords("SELECT * FROM student_email_notifications WHERE status = 'Queued' AND notify_key = ? ORDER BY id LIMIT " . (int) $limit, [(string) $onlyKey])
        : getRecords("SELECT * FROM student_email_notifications WHERE status = 'Queued' ORDER BY id LIMIT " . (int) $limit);
    $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'reason' => ''];
    if (!$rows) return $out;
    $fail = function ($row, $reason) use (&$out) {
        executeQuery("UPDATE student_email_notifications SET status = 'Failed', error = ? WHERE id = ?", [mb_substr($reason, 0, 500), (int) $row['id']]);
        $out['failed']++;
        if ($out['reason'] === '') $out['reason'] = $reason;
    };
    if (!senEnabled()) {
        foreach ($rows as $r) executeQuery("UPDATE student_email_notifications SET status = 'Skipped', error = 'Email notifications are switched off in Settings › Email Settings' WHERE id = ?", [(int) $r['id']]);
        $out['skipped'] = count($rows);
        $out['reason'] = 'Email notifications are switched off in Settings › Email Settings.';
        return $out;
    }
    $cfg = mailConfig();
    if (($problem = mailConfigProblem($cfg)) !== null) {
        error_log('Student email notifications not sent: ' . $problem);
        foreach ($rows as $r) $fail($r, $problem);
        return $out;
    }
    $mail = null;
    try {
        $mail = mailNewMailer($cfg);
        $mail->SMTPKeepAlive = true;   // one connection for the whole batch
        // Log in once first: a wrong App Password / blocked port fails every email the same way.
        if (!$mail->smtpConnect()) throw new \RuntimeException($mail->ErrorInfo ?: 'Could not connect');
    } catch (\Throwable $e) {
        $reason = mailFriendlyError($mail ? ($mail->ErrorInfo ?: $e->getMessage()) : $e->getMessage(), $cfg);
        error_log('Student email notifications: ' . $reason);
        foreach ($rows as $r) $fail($r, $reason);
        return $out;
    }
    foreach ($rows as $r) {
        $to = trim((string) $r['email']);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            // an email may have been added to the student record since
            $st = getRecord("SELECT email FROM students WHERE student_id = ?", [(int) $r['student_id']]);
            $to = trim((string) ($st['email'] ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $fail($r, $to === '' ? 'Missing student email (no email saved in the enrollment / student record)' : 'Invalid recipient email: ' . $to);
                continue;
            }
            executeQuery("UPDATE student_email_notifications SET email = ? WHERE id = ?", [$to, (int) $r['id']]);
        }
        try {
            $mail->clearAddresses();
            $mail->addAddress($to);
            $mail->Subject = $r['subject'];
            $mail->Body = $r['body'];
            $mail->AltBody = trim(strip_tags(str_replace(['</tr>', '</p>'], ["\n", "\n\n"], $r['body'])));
            $mail->send();
            executeQuery("UPDATE student_email_notifications SET status = 'Sent', sent_at = NOW(), error = NULL WHERE id = ?", [(int) $r['id']]);
            $out['sent']++;
        } catch (\Throwable $e) {
            $reason = mailFriendlyError($mail->ErrorInfo ?: $e->getMessage(), $cfg);
            error_log('Student email to ' . $to . ' failed: ' . $reason);
            $fail($r, $reason);
            try { $mail->getSMTPInstance()->reset(); } catch (\Throwable $e2) {}
        }
    }
    try { $mail->smtpClose(); } catch (\Throwable $e) {}
    return $out;
}
