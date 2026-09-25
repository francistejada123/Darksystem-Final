<?php
// includes/classroom_post_manage.php
// Teacher Portal > Classroom: the (...) menu on every post the teacher made --
// Edit, Delete, Move to another class/section, View details -- for Stream
// announcements (class_stream_posts) and Classwork (classwork_assignments).
//
// Safety rules:
//   - only the teacher who posted it (checked by every caller with teacher_id)
//   - delete is soft (announcement: is_deleted = 1, classwork: status = 'Deleted');
//     turned-in work and scores are never deleted with a post
//   - a classwork moves to another class only while nobody has turned in work
//     or has a score
//   - an edit never emails students unless the teacher ticks "Notify students"

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/classroom_helpers.php';
require_once __DIR__ . '/classwork_helpers.php';

/** 'Deleted' classwork status (hidden from teacher and students; records kept). */
function cpmEnsureSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    classworkEnsureSchema();
    if (schemaIsReady('classwork_deleted_v1')) return;
    executeQuery("ALTER TABLE classwork_assignments MODIFY status ENUM('Draft','Published','Deleted') NOT NULL DEFAULT 'Published'");
    schemaMarkReady('classwork_deleted_v1');
}

/** Every class (section + subject) this teacher handles in a school year -- for "Move to". */
function cpmTeacherClasses($teacherId, $syId)
{
    return getRecords(
        "SELECT ts.section_id, ts.subject_code, sec.section_name, sec.grade_level, sub.subject_name
           FROM teacher_subjects ts
           JOIN sections sec ON sec.section_id = ts.section_id
           JOIN subjects sub ON sub.subject_code = ts.subject_code
          WHERE ts.teacher_id = ? AND ts.school_year_id = ?
          ORDER BY sec.grade_level, sec.section_name, sub.subject_name",
        [(int) $teacherId, (int) $syId]
    );
}

/** Is (section, subject) one of this teacher's classes this school year? */
function cpmOwnsClass($teacherId, $syId, $sectionId, $subjectCode)
{
    return (bool) getRecord(
        "SELECT 1 FROM teacher_subjects WHERE teacher_id = ? AND school_year_id = ? AND section_id = ? AND subject_code = ?",
        [(int) $teacherId, (int) $syId, (int) $sectionId, (string) $subjectCode]
    );
}

/**
 * What is connected to a classwork: turned-in work, scores, emails.
 * @return array submitted, files, scored (classwork or Grade Management), roster, emailed, item_scores
 */
function cpmClassworkLinks(array $a)
{
    $aid = (int) $a['assignment_id'];
    $r = getRecord(
        "SELECT COUNT(*) AS roster,
                SUM(status IN ('Submitted','Reviewed') OR submitted_at IS NOT NULL) AS submitted,
                SUM(score IS NOT NULL) AS scored
           FROM classwork_assignment_students WHERE assignment_id = ?",
        [$aid]
    );
    $files = getRecord("SELECT COUNT(*) AS n FROM classwork_submission_files WHERE assignment_id = ?", [$aid]);
    $itemScores = !empty($a['assessment_item_id'])
        ? getRecord("SELECT COUNT(*) AS n FROM assessment_scores WHERE item_id = ? AND raw_score IS NOT NULL", [(int) $a['assessment_item_id']])
        : null;
    $emailed = getRecord("SELECT COUNT(*) AS n FROM student_email_notifications WHERE notify_key = ? AND status = 'Sent'", ['classwork:' . $aid]);
    return [
        'roster' => (int) ($r['roster'] ?? 0),
        'submitted' => (int) ($r['submitted'] ?? 0),
        'scored' => (int) ($r['scored'] ?? 0),
        'files' => (int) ($files['n'] ?? 0),
        'item_scores' => (int) ($itemScores['n'] ?? 0),
        'emailed' => (int) ($emailed['n'] ?? 0),
    ];
}

/** Save uploaded files ($_FILES[$field]) as attachments of a post or classwork. @return int saved */
function cpmSaveUploads($field, $kind, $ownerId)
{
    if (empty($_FILES[$field]['name'][0] ?? '')) return 0;
    $saved = 0;
    foreach ($_FILES[$field]['name'] as $i => $name) {
        if (($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $r = classroomSaveUpload([
            'name' => $name, 'type' => $_FILES[$field]['type'][$i], 'tmp_name' => $_FILES[$field]['tmp_name'][$i],
            'error' => $_FILES[$field]['error'][$i], 'size' => $_FILES[$field]['size'][$i],
        ], $kind === 'classwork' ? 'classwork' : 'stream');
        if (!$r['success']) continue;
        if ($kind === 'classwork') {
            insertRecord(
                "INSERT INTO classwork_assignment_attachments (assignment_id, original_filename, stored_filename, mime_type, file_size, attachment_type) VALUES (?, ?, ?, ?, ?, 'file')",
                [(int) $ownerId, $r['original_filename'], $r['stored_filename'], $r['mime_type'], $r['file_size']]
            );
        } else {
            insertRecord(
                "INSERT INTO class_stream_attachments (post_id, original_filename, stored_filename, mime_type, file_size, attachment_type) VALUES (?, ?, ?, ?, ?, 'file')",
                [(int) $ownerId, $r['original_filename'], $r['stored_filename'], $r['mime_type'], $r['file_size']]
            );
        }
        $saved++;
    }
    return $saved;
}

/** Remove the chosen attachments (only this post's own). Files on disk are removed too. */
function cpmRemoveAttachments($kind, $ownerId, array $attachmentIds)
{
    $ids = array_values(array_filter(array_map('intval', $attachmentIds)));
    if (!$ids) return 0;
    $table = $kind === 'classwork' ? 'classwork_assignment_attachments' : 'class_stream_attachments';
    $col = $kind === 'classwork' ? 'assignment_id' : 'post_id';
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = getRecords("SELECT attachment_id, stored_filename, attachment_type FROM $table WHERE $col = ? AND attachment_id IN ($ph)", array_merge([(int) $ownerId], $ids));
    foreach ($rows as $r) {
        executeQuery("DELETE FROM $table WHERE attachment_id = ?", [(int) $r['attachment_id']]);
        if ($r['attachment_type'] === 'file' && !empty($r['stored_filename'])) {
            @unlink(classroomUploadDirFor($kind === 'classwork' ? 'classwork' : 'stream') . basename($r['stored_filename']));
        }
    }
    return count($rows);
}
