<?php
// includes/classroom_helpers.php
// Shared helpers for the Google-Classroom-style Teacher Portal redesign
// (Stream / Classwork / People tabs on modules/teachers/classroom.php).
//
//   - classroomValidateAccess() is THE ownership check for the whole
//     feature -- the classroom page itself, its AJAX AI-chat endpoint
//     (includes/gemini_service.php's getGeminiClassResourceReply()), and
//     the attachment download endpoint all call this rather than each
//     re-deriving their own. It re-reads teacher_subjects fresh on every
//     call (never trusts a client-supplied section/subject/term) -- the
//     exact same source-of-truth table already used by
//     modules/grading/index.php and modules/teachers/my_sections.php, so
//     a teacher can never reach a class here that they couldn't already
//     reach there.
//   - The attachment-upload helpers below follow the same convention used
//     everywhere else in this codebase (ai_generated_resources,
//     ai_resource_submissions, tutorial_plan_items): a random, unguessable
//     stored_filename on disk under static/uploads/ (which already has a
//     site-wide .htaccess blocking execution), with the real filename kept
//     only in the database for display, and every read gated by an
//     ownership check in the endpoint that serves it
//     (modules/teachers/classroom_download.php).
//
// This file only ever READS teacher_subjects/subjects/sections/students/
// enrollments and WRITES to the five new tables from config/database.php's
// migration_2026_classroom_ui_v1 block (class_stream_posts,
// class_stream_attachments, classwork_assignments,
// classwork_assignment_attachments, classwork_assignment_students). It
// never touches any existing table.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/teacher_department.php';

define('CLASS_STREAM_UPLOAD_DIR', __DIR__ . '/../static/uploads/class_stream/');
define('CLASSWORK_UPLOAD_DIR', __DIR__ . '/../static/uploads/classwork/');
define('CLASSROOM_ATTACHMENT_MAX_BYTES', 20 * 1024 * 1024); // 20 MB

const CLASSROOM_ATTACHMENT_ALLOWED_EXT = [
    'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx',
    'jpg', 'jpeg', 'png', 'gif', 'mp4', 'mov', 'txt',
];

// The category vocabulary the user asked for on the Classwork tab. Kept as
// a simple free-text column (classwork_assignments.category) rather than
// an ENUM so a future wording tweak here never needs a schema migration.
const CLASSWORK_CATEGORIES = [
    'Assignment', 'Written Work', 'Performance Task', 'Quiz',
    'Reviewer', 'Activity', 'Term Examination', 'Learning Material',
];

/**
 * The single "is this teacher allowed into this class" check for the whole
 * Classroom feature. $term may be null/empty (Junior High subjects have no
 * term) -- a subject with subjects.term = NULL matches any $term passed in,
 * exactly like every other term-aware query in this codebase.
 *
 * @return array{success:bool,class?:array,error?:string}
 */
function classroomValidateAccess($teacherId, $sectionId, $subjectCode, $syId, $term)
{
    $teacherId = (int) $teacherId;
    $sectionId = (int) $sectionId;
    $subjectCode = trim((string) $subjectCode);
    $syId = (int) $syId;

    if (!$teacherId || !$sectionId || $subjectCode === '' || !$syId) {
        return ['success' => false, 'error' => 'You are not assigned to this class/subject for the selected term.'];
    }

    $row = getRecord(
        "SELECT ts.teacher_id, ts.section_id, ts.subject_code, ts.school_year_id,
                sub.subject_name, sub.term AS subject_term, sub.grade_level, sub.strand,
                sec.section_name, sec.grade_level AS section_grade_level, sec.strand AS section_strand
         FROM teacher_subjects ts
         JOIN subjects sub ON ts.subject_code = sub.subject_code
         JOIN sections sec ON ts.section_id = sec.section_id
         WHERE ts.teacher_id = ? AND ts.section_id = ? AND ts.subject_code = ? AND ts.school_year_id = ?
         AND (sub.term IS NULL OR sub.term = ?)",
        [$teacherId, $sectionId, $subjectCode, $syId, $term]
    );

    if (!$row) {
        return ['success' => false, 'error' => 'You are not assigned to this class/subject for the selected term.'];
    }

    // Department / Level (saved at teacher registration) is the source of
    // truth: a JHS teacher may only enter Grade 7-10 classes, an SHS teacher
    // only Grade 11-12 ones. This closes the Classroom pages off for any
    // assignment that sits outside its owner's department, so nothing is
    // reachable even by calling the endpoints directly.
    $dept = tdDepartmentFor($teacherId);
    if (!tdGradeAllowed($dept, $row['section_grade_level'])) {
        return ['success' => false, 'error' => tdCheckAssignment($dept, $row['section_grade_level'])['message']];
    }

    return ['success' => true, 'class' => $row];
}

/**
 * Enrolled, approved students for a section+school year -- same query
 * pattern already used by modules/teachers/my_sections.php and
 * modules/grading/index.php. Used by the People tab and by Classwork's
 * per-student assignment roster.
 */
function classroomSectionStudents($sectionId, $syId)
{
    return getRecords(
        "SELECT s.student_id, s.lrn, s.first_name, s.last_name, s.middle_name, s.email
         FROM students s
         JOIN enrollments e ON s.student_id = e.student_id
         WHERE e.section_id = ? AND e.school_year_id = ? AND e.enrollment_status = 'Approved'
         ORDER BY s.last_name, s.first_name",
        [$sectionId, $syId]
    );
}

function classroomUploadDirFor($kind)
{
    $dir = $kind === 'classwork' ? CLASSWORK_UPLOAD_DIR : CLASS_STREAM_UPLOAD_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Saves one uploaded file ($_FILES[...] single entry) using the same
 * random-stored-filename convention as ai_generated_resources /
 * ai_resource_submissions / tutorial_plan_items.
 *
 * @param string $kind 'stream' | 'classwork'
 * @return array{success:bool, stored_filename?:string, original_filename?:string, mime_type?:string, file_size?:int, error?:string}
 */
function classroomSaveUpload(array $file, $kind)
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'error' => 'No file was uploaded.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'The file could not be uploaded. Please try again.'];
    }
    if ($file['size'] > CLASSROOM_ATTACHMENT_MAX_BYTES) {
        return ['success' => false, 'error' => 'File is too large (max 20 MB).'];
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, CLASSROOM_ATTACHMENT_ALLOWED_EXT, true)) {
        return ['success' => false, 'error' => 'That file type is not allowed.'];
    }

    $dir = classroomUploadDirFor($kind);
    if (!is_dir($dir)) {
        return ['success' => false, 'error' => 'Could not save the file. Please try again.'];
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . $stored;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => false, 'error' => 'Could not save the file. Please try again.'];
    }
    @chmod($dest, 0644);

    return [
        'success' => true,
        'stored_filename' => $stored,
        'original_filename' => basename((string) $file['name']),
        'mime_type' => $file['type'] ?: null,
        'file_size' => (int) $file['size'],
    ];
}

/** Font Awesome icon class for a file's extension/mime -- display only. */
function classroomMimeIcon($mime, $filename = '')
{
    $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
    if ($ext === 'pdf' || $mime === 'application/pdf') return 'fa-file-pdf';
    if (in_array($ext, ['doc', 'docx'], true)) return 'fa-file-word';
    if (in_array($ext, ['ppt', 'pptx'], true)) return 'fa-file-powerpoint';
    if (in_array($ext, ['xls', 'xlsx'], true)) return 'fa-file-excel';
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) return 'fa-file-image';
    if (in_array($ext, ['mp4', 'mov'], true)) return 'fa-file-video';
    return 'fa-file';
}

/** Human-readable file size, e.g. "1.2 MB". Display only. */
function classroomFileSizeLabel($bytes)
{
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

/**
 * Best-guess Classwork category (one of CLASSWORK_CATEGORIES) for an AI
 * resource_type key (includes/ai_document_generator.php's AI_RESOURCE_TYPES).
 * Display/pre-select convenience only -- the teacher can always change the
 * category dropdown before posting, this never gates or validates anything.
 */
function classroomCategoryForResourceType($resourceType)
{
    $map = [
        'quiz' => 'Quiz', 'practice_quiz' => 'Quiz', 'multiple_choice' => 'Quiz',
        'true_false' => 'Quiz', 'short_assessment' => 'Quiz', 'question_bank' => 'Quiz',
        'reviewer' => 'Reviewer', 'study_guide' => 'Reviewer', 'study_plan' => 'Reviewer', 'flashcards' => 'Reviewer',
        'performance_task' => 'Performance Task', 'practice_test' => 'Performance Task',
        'written_work' => 'Written Work', 'essay' => 'Written Work', 'identification' => 'Written Work',
        'remedial_activity' => 'Activity', 'worksheet' => 'Activity', 'practice_exercise' => 'Activity',
        'reflection_activity' => 'Activity', 'reading_activity' => 'Activity',
        'intervention_activity' => 'Activity', 'learning_activity' => 'Activity',
        'rubric' => 'Assignment', 'assignment' => 'Assignment', 'other' => 'Assignment',
    ];
    return $map[$resourceType] ?? 'Activity';
}