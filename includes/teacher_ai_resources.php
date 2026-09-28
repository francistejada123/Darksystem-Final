<?php
// includes/teacher_ai_resources.php
// Generate -> create a file/Form -> send to a class, for the separate
// E-GRADEPRO Teacher AI (modules/teachers/teacher_ai_chat.php).
//
// This mirrors what Ask AI (modules/ai/chatbot.php) already does for ONE
// at-risk student -- structured Gemini reply, real PDF / Word / Google Form
// output, Preview/Open, Download, Regenerate, Send -- but for general class
// use: the teacher generates first, then picks Grade Level / Section /
// Subject / Term and sends to selected students or the whole section.
//
// Nothing here is a new pipeline. It reuses:
//   - aiGenerateResourceFile()        PDF / Word rendering + safe storage
//   - teacherAiBuildSlides()          PowerPoint (includes/teacher_ai_documents.php)
//   - googleFormsCreateQuizForm()     the existing Google Forms integration
//   - classroomValidateAccess()       THE "is this teacher assigned to this
//                                     class/subject/term" check used by the
//                                     Classroom feature
// and delivers by fanning out into the existing per-student pipeline: one
// ai_generated_resources row + one student_support_messages row per student,
// exactly what modules/ai/send_to_student.php writes for one student. The
// student portal (modules/students/portal/subject.php), the view/download
// endpoints and their "must have a Sent message" authorization therefore all
// keep working unchanged.
//
// Design rules (every one is enforced HERE, on the server, never by the UI):
//   - The teacher's class list comes only from teacher_ai's own query over
//     teacher_subjects (this teacher, active school year, active section and
//     subject). Every send re-validates section + subject + term + grade level
//     from scratch and re-reads the roster; the browser's student ids are only
//     ever intersected with that roster, never trusted.
//   - Students only ever receive the student-facing content. The teacher answer
//     key stays in teacher_ai_resources and is never copied to a student row.
//   - Nothing is sent by generating or creating a file. Only teacherAiSendResource(),
//     on the teacher's explicit click.
//   - Every new table is created with IF NOT EXISTS and nothing existing is
//     dropped or rewritten. The one ALTER (adding 'pptx' to
//     ai_generated_resources.output_format) is guarded and additive.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/gemini_service.php';          // also loads ai_document_generator.php, classroom_helpers.php, grading helpers
require_once __DIR__ . '/ai_document_generator.php';   // aiGenerateResourceFile(), aiParseContentLines(), AI_RESOURCE_TYPES
require_once __DIR__ . '/google_forms_service.php';    // googleFormsIsConfigured(), googleFormsCreateQuizForm()
require_once __DIR__ . '/classroom_helpers.php';       // classroomValidateAccess(), classroomFileSizeLabel()
require_once __DIR__ . '/teacher_ai_documents.php';    // teacherAiBuildSlides(), teacherAiPptxBytesFromSlides()
require_once __DIR__ . '/ai_chat_outputs.php';         // aiIsConversionCommand()
require_once __DIR__ . '/ai_assessment_items.php';     // aiAssessmentEnsureItem(), aiAssessmentDefaultCategory() -- sent quizzes are saved to Manage Assessment Items
require_once __DIR__ . '/assessment_material_helpers.php'; // "<item> AI": a draft generated for an existing Manage Assessment Items row

/** Hard ceiling for one send -- far above any real section, blocks runaway requests. */
const TEACHER_AI_MAX_RECIPIENTS = 200;

const TEACHER_AI_PPTX_MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

// ============================================================
// Formats
// ============================================================

/** @return array{label:string,icon:string,mime:?string,ext:?string}|null */
function teacherAiFormatMeta($format)
{
    $meta = [
        'pdf'   => ['label' => 'PDF', 'icon' => 'fa-file-pdf', 'mime' => 'application/pdf', 'ext' => 'pdf'],
        'docx'  => ['label' => 'Word', 'icon' => 'fa-file-word', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'ext' => 'docx'],
        'pptx'  => ['label' => 'PowerPoint', 'icon' => 'fa-file-powerpoint', 'mime' => TEACHER_AI_PPTX_MIME, 'ext' => 'pptx'],
        'gform' => ['label' => 'Google Form', 'icon' => 'fa-square-poll-vertical', 'mime' => null, 'ext' => null],
    ];
    return $meta[$format] ?? null;
}

/**
 * Which output the teacher's typed words ask for -- 'gform', 'pdf', 'docx',
 * 'pptx' or null. Extends Ask AI's aiDetectOutputCommand() (Google Form / PDF /
 * Word, English + Filipino) with PowerPoint.
 */
function teacherAiDetectOutput($message)
{
    $found = aiDetectOutputCommand($message);
    if ($found !== null) {
        return $found;
    }
    $m = mb_strtolower(trim((string) $message));
    if ($m === '') {
        return null;
    }
    if (preg_match('/\b(?:pptx?|power\s*point|slide\s*deck|slideshow)\b/u', $m)) {
        return 'pptx';
    }
    if (preg_match('/\b(?:as|in|into|to)\s+(?:a\s+|an\s+)?(?:slides?|presentation)\b/u', $m)) {
        return 'pptx';
    }
    if (preg_match('/\b(?:create|make|generate|prepare|gumawa|gawa)\b[^.?!]{0,30}\b(?:presentation|slides)\b/u', $m)) {
        return 'pptx';
    }
    return null;
}

// ============================================================
// Schema (additive; auto-created, and also shipped as
// database/migrations/2026_teacher_ai_resources.sql)
// ============================================================

/** Enum values currently allowed in ai_generated_resources.output_format ([] if unreadable). */
function teacherAiStudentResourceFormats($fresh = false)
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    $row = getRecord(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_generated_resources' AND COLUMN_NAME = 'output_format'"
    );
    $values = [];
    if ($row && !empty($row['COLUMN_TYPE']) && preg_match_all("/'([^']+)'/", $row['COLUMN_TYPE'], $m)) {
        $values = $m[1];
    }
    $cache = $values;
    return $cache;
}

/**
 * Create the Teacher AI tables and allow 'pptx' on the per-student resource
 * rows. Safe to call on every request (cheap after the first call in a request,
 * every statement is idempotent). Returns false only if a table could not be
 * created.
 */
function teacherAiEnsureSchema()
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    if (schemaIsReady('teacher_ai_resources_v1')) {
        return $ok = true;
    }
    $ok = true;

    $tables = [
        "CREATE TABLE IF NOT EXISTS teacher_ai_resources (
            resource_id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            parent_id INT NULL DEFAULT NULL,
            resource_type VARCHAR(40) NOT NULL DEFAULT 'other',
            title VARCHAR(150) NOT NULL DEFAULT '',
            source_prompt VARCHAR(500) NULL DEFAULT NULL,
            student_content MEDIUMTEXT NOT NULL,
            teacher_answer_key MEDIUMTEXT NULL DEFAULT NULL,
            quiz_questions_json MEDIUMTEXT NULL DEFAULT NULL,
            quiz_max_score INT NULL DEFAULT NULL,
            output_format ENUM('pdf','docx','pptx','gform') NULL DEFAULT NULL,
            stored_filename VARCHAR(255) NULL DEFAULT NULL,
            original_filename VARCHAR(255) NULL DEFAULT NULL,
            mime_type VARCHAR(100) NULL DEFAULT NULL,
            file_size INT NULL DEFAULT NULL,
            google_form_id VARCHAR(64) NULL DEFAULT NULL,
            google_form_edit_url VARCHAR(500) NULL DEFAULT NULL,
            google_form_view_url VARCHAR(500) NULL DEFAULT NULL,
            status ENUM('Draft','Generated','Sent') NOT NULL DEFAULT 'Draft',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            sent_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_tai_res_teacher (teacher_id, created_at),
            INDEX idx_tai_res_parent (parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS teacher_ai_resource_sends (
            send_id INT AUTO_INCREMENT PRIMARY KEY,
            resource_id INT NOT NULL,
            teacher_id INT NOT NULL,
            school_year_id INT NOT NULL,
            section_id INT NOT NULL,
            subject_code VARCHAR(20) NOT NULL,
            term VARCHAR(20) NULL DEFAULT NULL,
            grade_level VARCHAR(50) NULL DEFAULT NULL,
            scope ENUM('selected','all') NOT NULL DEFAULT 'selected',
            note VARCHAR(500) NULL DEFAULT NULL,
            recipient_count INT NOT NULL DEFAULT 0,
            skipped_count INT NOT NULL DEFAULT 0,
            failed_count INT NOT NULL DEFAULT 0,
            sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tai_sends_resource (resource_id),
            INDEX idx_tai_sends_teacher (teacher_id, sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS teacher_ai_resource_recipients (
            recipient_id INT AUTO_INCREMENT PRIMARY KEY,
            send_id INT NOT NULL,
            resource_id INT NOT NULL,
            student_id INT NOT NULL,
            subject_code VARCHAR(20) NOT NULL,
            student_resource_id INT NULL DEFAULT NULL,
            message_id INT NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tai_recipient (resource_id, student_id, subject_code),
            INDEX idx_tai_recipient_send (send_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];
    foreach ($tables as $sql) {
        if (executeQuery($sql) === false) {
            $ok = false;
        }
    }

    // Subject mode: a resource made while the teacher had one subject selected
    // remembers it, so it can only be sent to that subject's classes. Nullable
    // and additive; rows made without a subject keep working as before.
    $col = getRecord(
        "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_ai_resources' AND COLUMN_NAME = 'subject_code'"
    );
    if ($col && (int) $col['n'] === 0) {
        executeQuery("ALTER TABLE `teacher_ai_resources` ADD COLUMN `subject_code` VARCHAR(20) NULL DEFAULT NULL AFTER `teacher_id`");
    }
    teacherAiSubjectColumnReady(true);

    // Which Manage Assessment Item a send was recorded under (or 'none' when the
    // teacher chose not to record it). Additive and nullable: a send made before
    // automatic recording existed keeps NULL, which is how aiAssessmentBackfillLink()
    // tells an old send from one the teacher deliberately left unrecorded.
    foreach (['record_category' => "VARCHAR(8) NULL DEFAULT NULL", 'record_item_id' => "INT NULL DEFAULT NULL"] as $recCol => $recDdl) {
        $hasCol = getRecord(
            "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_ai_resource_sends' AND COLUMN_NAME = ?",
            [$recCol]
        );
        if ($hasCol && (int) $hasCol['n'] === 0) {
            executeQuery("ALTER TABLE `teacher_ai_resource_sends` ADD COLUMN `$recCol` $recDdl");
        }
    }

    // PowerPoint files sent to students are stored as ordinary per-student
    // ai_generated_resources rows, so that enum must accept 'pptx'. Purely
    // additive (appends one value; every existing row keeps its value).
    $formats = teacherAiStudentResourceFormats();
    if (!empty($formats) && !in_array('pptx', $formats, true)) {
        $list = array_map(function ($v) { return "'" . str_replace("'", "''", $v) . "'"; }, array_merge($formats, ['pptx']));
        executeQuery(
            "ALTER TABLE `ai_generated_resources` MODIFY COLUMN `output_format` enum(" . implode(',', $list) . ") DEFAULT NULL "
            . "COMMENT 'NULL until produced in final form. pdf/docx/pptx = a generated file under static/uploads/ai_resources/. gform = a Google Form (see google_form_* columns).'"
        );
        teacherAiStudentResourceFormats(true);
    }
    $__schema_ok = ($ok);
    if ($__schema_ok) {
        schemaMarkReady('teacher_ai_resources_v1');
    }
    return $__schema_ok;
}

/** Whether teacher_ai_resources.subject_code exists (so subject tagging is skipped, never fatal, if it could not be added). */
function teacherAiSubjectColumnReady($fresh = false)
{
    static $ready = null;
    if ($ready !== null && !$fresh) {
        return $ready;
    }
    $col = getRecord(
        "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_ai_resources' AND COLUMN_NAME = 'subject_code'"
    );
    $ready = $col && (int) $col['n'] > 0;
    return $ready;
}

/** Remember which subject a resource was made for (no-op without a subject / column). */
function teacherAiTagSubject($resourceId, $subjectCode)
{
    $subjectCode = trim((string) $subjectCode);
    if ($subjectCode === '' || !$resourceId || !teacherAiSubjectColumnReady()) {
        return;
    }
    executeQuery("UPDATE teacher_ai_resources SET subject_code = ? WHERE resource_id = ?", [$subjectCode, (int) $resourceId]);
}

/** Whether PowerPoint rows can be stored for students (the enum widening succeeded). */
function teacherAiPptxDeliverable()
{
    teacherAiEnsureSchema();
    return in_array('pptx', teacherAiStudentResourceFormats(), true);
}

// ============================================================
// Class scoping -- ONLY what this teacher is assigned to
// ============================================================

/** The teacher display name for document headers. */
function teacherAiTeacherName($teacherId)
{
    $u = getRecord("SELECT first_name, last_name FROM users WHERE user_id = ?", [(int) $teacherId]);
    return $u ? trim($u['first_name'] . ' ' . $u['last_name']) : '';
}

/**
 * Every class (section + subject) this teacher is assigned to in the ACTIVE
 * school year, for the Grade Level / Section / Subject / Term pickers.
 *
 * @return array{success:bool,error?:string,school_year?:array,terms?:array,current_term?:?string,classes?:array}
 */
function teacherAiClassOptions($teacherId)
{
    $sy = getRecord("SELECT sy_id, sy_name FROM school_years WHERE is_active = 1");
    if (!$sy) {
        return ['success' => false, 'error' => 'No active school year is set, so there are no classes to send to.'];
    }
    $rows = getRecords(
        "SELECT ts.section_id, ts.subject_code,
                sec.section_name, sec.grade_level AS section_grade_level, sec.strand AS section_strand,
                sub.subject_name, sub.term AS subject_term, sub.grade_level AS subject_grade_level,
                (SELECT COUNT(DISTINCT e.student_id)
                   FROM enrollments e JOIN students st ON st.student_id = e.student_id AND st.is_active = 1
                  WHERE e.section_id = ts.section_id AND e.school_year_id = ts.school_year_id
                    AND e.enrollment_status = 'Approved') AS student_count
         FROM teacher_subjects ts
         JOIN sections sec ON sec.section_id = ts.section_id AND sec.is_active = 1
         JOIN subjects sub ON sub.subject_code = ts.subject_code AND sub.is_active = 1
         WHERE ts.teacher_id = ? AND ts.school_year_id = ?
         ORDER BY sec.grade_level, sec.section_name, sub.subject_name",
        [(int) $teacherId, (int) $sy['sy_id']]
    );

    $allTerms = gh_threeTerms();
    $classes = [];
    foreach ($rows as $r) {
        $grade = trim((string) ($r['section_grade_level'] ?: $r['subject_grade_level']));
        $terms = (!empty($r['subject_term']) && in_array($r['subject_term'], $allTerms, true)) ? [$r['subject_term']] : $allTerms;
        $classes[] = [
            'grade_level'   => $grade,
            'section_id'    => (int) $r['section_id'],
            'section_name'  => (string) $r['section_name'],
            'strand'        => (string) ($r['section_strand'] ?? ''),
            'subject_code'  => (string) $r['subject_code'],
            'subject_name'  => (string) $r['subject_name'],
            'terms'         => $terms,
            'student_count' => (int) $r['student_count'],
        ];
    }
    return [
        'success'      => true,
        'school_year'  => ['sy_id' => (int) $sy['sy_id'], 'sy_name' => (string) $sy['sy_name']],
        'terms'        => $allTerms,
        'current_term' => gh_getCurrentTerm((int) $sy['sy_id']),
        'classes'      => $classes,
    ];
}

/**
 * One of THIS teacher's assigned subjects (active school year), with the
 * sections it is taught in. Null when the code is not theirs -- this is the gate
 * for Teacher AI's "subject mode".
 *
 * @return array{subject_code:string,subject_name:string,grades:array,sections:array,section_ids:array}|null
 */
function teacherAiTeacherSubject($teacherId, $subjectCode)
{
    $subjectCode = trim((string) $subjectCode);
    if ($subjectCode === '') {
        return null;
    }
    $opts = teacherAiClassOptions($teacherId);
    if (empty($opts['success'])) {
        return null;
    }
    $found = null;
    foreach ($opts['classes'] as $c) {
        if ($c['subject_code'] !== $subjectCode) {
            continue;
        }
        if ($found === null) {
            $found = ['subject_code' => $c['subject_code'], 'subject_name' => $c['subject_name'], 'grades' => [], 'sections' => [], 'section_ids' => []];
        }
        if (!in_array($c['grade_level'], $found['grades'], true)) {
            $found['grades'][] = $c['grade_level'];
        }
        $found['sections'][] = ['section_id' => $c['section_id'], 'section_name' => $c['section_name'], 'grade_level' => $c['grade_level']];
        $found['section_ids'][] = $c['section_id'];
    }
    return $found;
}

/**
 * Subject mode guard: the name of ANOTHER assigned subject the message asks
 * about (whole-name match), or null. If the message also names the selected
 * subject it is allowed. The model is told the same rule; this catches the
 * clear cases without spending a model call.
 */
function teacherAiOtherSubjectMentioned($teacherId, array $subject, $message)
{
    $opts = teacherAiClassOptions($teacherId);
    if (empty($opts['success'])) {
        return null;
    }
    $matches = function ($name) use ($message) {
        $name = trim((string) $name);
        if (mb_strlen($name) < 3) {
            return false;
        }
        return (bool) preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', $message);
    };
    if ($matches($subject['subject_name'])) {
        return null;
    }
    foreach ($opts['classes'] as $c) {
        if ($c['subject_code'] !== $subject['subject_code'] && $matches($c['subject_name'])) {
            return $c['subject_name'];
        }
    }
    return null;
}

/**
 * The one server-side gate for a class picked in the UI: grade level, section,
 * subject and term must all be a class this teacher is assigned to in the
 * active school year (classroomValidateAccess() re-reads teacher_subjects),
 * and both the section and the subject must still be active.
 *
 * @return array{success:bool,error?:string,class?:array,sy?:array,term?:string,grade_level?:string}
 */
function teacherAiValidateClass($teacherId, $sectionId, $subjectCode, $term, $gradeLevel = null)
{
    $notAssigned = ['success' => false, 'error' => 'You can only send to classes and subjects that are assigned to you.'];
    $sy = getRecord("SELECT sy_id, sy_name FROM school_years WHERE is_active = 1");
    if (!$sy) {
        return ['success' => false, 'error' => 'No active school year is set.'];
    }
    $term = trim((string) $term);
    if (!isValidTerm($term)) {
        return ['success' => false, 'error' => 'Please choose a valid term.'];
    }
    $check = classroomValidateAccess((int) $teacherId, (int) $sectionId, $subjectCode, (int) $sy['sy_id'], $term);
    if (empty($check['success'])) {
        return $notAssigned;
    }
    $class = $check['class'];

    $active = getRecord(
        "SELECT (SELECT is_active FROM sections WHERE section_id = ?) AS sec_active,
                (SELECT is_active FROM subjects WHERE subject_code = ?) AS sub_active",
        [(int) $sectionId, (string) $subjectCode]
    );
    if (!$active || !(int) $active['sec_active'] || !(int) $active['sub_active']) {
        return $notAssigned;
    }

    $sectionGrade = trim((string) ($class['section_grade_level'] ?: $class['grade_level']));
    if ($gradeLevel !== null && trim((string) $gradeLevel) !== '' && strcasecmp(trim((string) $gradeLevel), $sectionGrade) !== 0) {
        return $notAssigned;
    }
    return ['success' => true, 'class' => $class, 'sy' => $sy, 'term' => $term, 'grade_level' => $sectionGrade];
}

/** Approved, active students enrolled in the section this school year (no LRN -- not needed here). */
function teacherAiClassRoster($sectionId, $syId)
{
    return getRecords(
        "SELECT s.student_id, s.first_name, s.middle_name, s.last_name
         FROM students s
         JOIN enrollments e ON e.student_id = s.student_id
         WHERE e.section_id = ? AND e.school_year_id = ? AND e.enrollment_status = 'Approved' AND s.is_active = 1
         GROUP BY s.student_id, s.first_name, s.middle_name, s.last_name
         ORDER BY s.last_name, s.first_name",
        [(int) $sectionId, (int) $syId]
    );
}

function teacherAiStudentDisplayName(array $s)
{
    $mi = trim((string) ($s['middle_name'] ?? ''));
    $name = trim((string) $s['last_name']) . ', ' . trim((string) $s['first_name']);
    if ($mi !== '') {
        $name .= ' ' . mb_strtoupper(mb_substr($mi, 0, 1)) . '.';
    }
    return $name;
}

// ============================================================
// Drafts, outputs, cards
// ============================================================

function teacherAiGetResource($teacherId, $resourceId)
{
    if ((int) $resourceId <= 0) {
        return null;
    }
    $row = getRecord("SELECT * FROM teacher_ai_resources WHERE resource_id = ? AND teacher_id = ?", [(int) $resourceId, (int) $teacherId]);
    return $row ?: null;
}

/**
 * Save the model's resource (content only, no file yet) as this teacher's Draft.
 * @param array $parsed validateGeminiResourceResponse() output for a "resource"
 * @return int|false new resource_id
 */
function teacherAiSaveDraft($teacherId, array $parsed, $sourcePrompt = '', $subjectCode = '')
{
    if (!teacherAiEnsureSchema()) {
        return false;
    }
    $quiz = (!empty($parsed['quiz_questions']) && is_array($parsed['quiz_questions']))
        ? aiSanitizeQuizQuestions($parsed['quiz_questions']) : [];
    $maxScore = 0;
    foreach ($quiz as $q) {
        $maxScore += (int) $q['points'];
    }
    $id = insertRecord(
        "INSERT INTO teacher_ai_resources
            (teacher_id, parent_id, resource_type, title, source_prompt, student_content, teacher_answer_key, quiz_questions_json, quiz_max_score, status)
         VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 'Draft')",
        [
            (int) $teacherId,
            array_key_exists($parsed['resource_type'] ?? '', AI_RESOURCE_TYPES) ? $parsed['resource_type'] : 'other',
            mb_substr((string) ($parsed['title'] ?? ''), 0, 150),
            $sourcePrompt !== '' ? mb_substr($sourcePrompt, 0, 500) : null,
            (string) $parsed['student_content'],
            !empty($parsed['teacher_answer_key']) ? (string) $parsed['teacher_answer_key'] : null,
            $quiz ? json_encode($quiz) : null,
            $quiz ? $maxScore : null,
        ]
    );
    if ($id) {
        teacherAiTagSubject($id, $subjectCode);
    }
    return $id ? (int) $id : false;
}

/** What the browser needs to show the generated content and the create-file buttons. */
function teacherAiDraftPayload(array $r)
{
    $quiz = json_decode((string) ($r['quiz_questions_json'] ?? ''), true);
    return [
        'draft_id'       => (int) ($r['parent_id'] ?: $r['resource_id']),
        'title'          => (string) $r['title'],
        'resource_type'  => (string) $r['resource_type'],
        'type_label'     => aiResourceTypeLabel($r['resource_type']),
        'content'        => (string) $r['student_content'],
        'has_answer_key' => trim((string) ($r['teacher_answer_key'] ?? '')) !== '',
        'quiz_count'     => is_array($quiz) ? count($quiz) : 0,
        'subject_code'   => (string) ($r['subject_code'] ?? ''),
        'assessment'     => aim_draftAssessmentPayload($r, (int) $r['teacher_id']),
    ];
}

/** The Preview/Open - Download - Regenerate - Send card for one created file/Form. */
function teacherAiResourceCard(array $r)
{
    $meta = teacherAiFormatMeta($r['output_format'] ?? '') ?: ['label' => '', 'icon' => 'fa-file', 'ext' => null];
    $sent = getRecord("SELECT COUNT(*) AS n FROM teacher_ai_resource_recipients WHERE resource_id = ?", [(int) $r['resource_id']]);
    $quiz = json_decode((string) ($r['quiz_questions_json'] ?? ''), true);
    $isForm = ($r['output_format'] ?? '') === 'gform';
    return [
        'id'             => (int) $r['resource_id'],
        'draft_id'       => (int) ($r['parent_id'] ?: $r['resource_id']),
        'title'          => (string) $r['title'],
        'type_label'     => aiResourceTypeLabel($r['resource_type']),
        'format'         => (string) $r['output_format'],
        'format_label'   => $meta['label'],
        'icon'           => $meta['icon'],
        'file_name'      => (string) ($r['original_filename'] ?? ''),
        'file_size'      => classroomFileSizeLabel((int) ($r['file_size'] ?? 0)),
        'question_count' => is_array($quiz) ? count($quiz) : 0,
        'max_score'      => (int) ($r['quiz_max_score'] ?? 0),
        'form_view_url'  => $isForm ? (string) $r['google_form_view_url'] : '',
        'form_edit_url'  => $isForm ? (string) $r['google_form_edit_url'] : '',
        'has_answer_key' => trim((string) ($r['teacher_answer_key'] ?? '')) !== '',
        'sent_count'     => (int) ($sent['n'] ?? 0),
        'subject_code'   => (string) ($r['subject_code'] ?? ''),
        // What the send panel starts on for "Record in Grade Management".
        'record'         => teacherAiRecordDefault($r),
        // Made from "Send to <item> AI": locked to that item's class, and
        // scores go to that same item.
        'assessment'     => aim_draftAssessmentPayload($r, (int) $r['teacher_id']),
    ];
}

/**
 * The starting choice for "Record in Grade Management" in the send panel:
 * a quiz / activity / performance task / exam is recorded automatically under
 * its own category; study material (a PowerPoint lesson, a reviewer, ...) is not.
 * The teacher can change it before sending.
 *
 * @return array{category:string,subtype:?string,has_quiz:bool,max_score:int}
 */
function teacherAiRecordDefault(array $r)
{
    $quiz = json_decode((string) ($r['quiz_questions_json'] ?? ''), true);
    $default = aiAssessmentDefaultCategory($r['resource_type'] ?? '', $r['title'] ?? '');
    if (($r['output_format'] ?? '') === 'pptx') {
        $default = ['category' => 'none', 'subtype' => null];
    }
    return [
        'category'  => $default['category'],
        'subtype'   => $default['subtype'],
        'has_quiz'  => is_array($quiz) && count($quiz) > 0,
        'max_score' => (int) ($r['quiz_max_score'] ?? 0),
    ];
}

/**
 * Decide what to record for a send, from the teacher's choice in the panel.
 *
 * @return array  ['mode'=>'none'] | ['mode'=>'record','category','subtype','component','hps'] | ['error'=>string]
 */
function teacherAiResolveRecordPlan(array $res, array $req)
{
    $default = teacherAiRecordDefault($res);
    $raw = trim((string) ($req['record_category'] ?? ''));
    $choice = $raw === '' ? $default['category'] : (strtolower($raw) === 'none' ? 'none' : strtoupper($raw));
    if ($choice === 'none') {
        return ['mode' => 'none'];
    }
    if (!in_array($choice, ['WW', 'PT', 'QA'], true)) {
        return ['error' => 'Choose Written/Oral Works, Performance Tasks, Examinations or Don\'t record.'];
    }
    $subtype = null;
    if ($choice === 'QA') {
        $subtype = trim((string) ($req['record_subtype'] ?? ''));
        if ($subtype === '') {
            $subtype = (string) ($default['subtype'] ?? '');
        }
    }
    $hps = (float) ($res['quiz_max_score'] ?? 0);
    if ($hps <= 0) {
        $hps = is_numeric($req['record_hps'] ?? null) ? (float) $req['record_hps'] : 10.0;
    }
    return [
        'mode'      => 'record',
        'category'  => $choice,
        'subtype'   => $subtype !== '' ? $subtype : null,
        'component' => trim((string) ($req['record_component'] ?? '')),
        'hps'       => $hps,
    ];
}

/**
 * A student who already had this resource (sent before it was recorded) and
 * has already answered it: put that score into the item now.
 */
function teacherAiSyncExistingSubmission($studentResourceId)
{
    $row = getRecord("SELECT * FROM ai_generated_resources WHERE resource_id = ?", [(int) $studentResourceId]);
    if (!$row || empty($row['link_item_id'])) {
        return;
    }
    $sub = getRecord(
        "SELECT score, max_score FROM ai_resource_submissions
         WHERE resource_id = ? AND student_id = ? AND is_auto_graded = 1 AND score IS NOT NULL AND max_score > 0",
        [(int) $studentResourceId, (int) $row['student_id']]
    );
    if (!$sub) {
        return;
    }
    try {
        require_once __DIR__ . '/ai_quiz_grade_sync.php';
        aiQuizSyncGradeForResponse($row, (float) $sub['score'], (float) $sub['max_score']);
    } catch (\Throwable $e) {
        error_log('teacherAiSyncExistingSubmission: ' . $e->getMessage());
    }
}

/** Absolute path of a stored file, only if it really sits inside the resources folder. */
function teacherAiResourceFilePath(array $row)
{
    if (empty($row['stored_filename'])) {
        return null;
    }
    $dir = aiResourceStorageDir();
    $root = realpath($dir);
    $path = realpath($dir . $row['stored_filename']);
    if ($root === false || $path === false || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_readable($path)) {
        return null;
    }
    return $path;
}

/**
 * Turn a Draft (or an existing output's content) into a real PDF / Word /
 * PowerPoint file or Google Form and record it as a new output row.
 *
 * @return array{success:bool,card?:array,error?:string}
 */
function teacherAiCreateOutput($teacherId, $sourceId, $format)
{
    $teacherId = (int) $teacherId;
    if (!in_array($format, ['pdf', 'docx', 'pptx', 'gform'], true)) {
        return ['success' => false, 'error' => 'Choose PDF, Word, PowerPoint or Google Form.'];
    }
    if (!teacherAiEnsureSchema()) {
        return ['success' => false, 'error' => 'Teacher AI could not prepare its storage. Please try again, or ask an administrator to run database/migrations/2026_teacher_ai_resources.sql.'];
    }
    $src = teacherAiGetResource($teacherId, $sourceId);
    if (!$src) {
        return ['success' => false, 'error' => 'That resource could not be found. Generate it again.'];
    }
    $parentId = (int) ($src['parent_id'] ?: $src['resource_id']);
    $title = trim((string) $src['title']) !== '' ? (string) $src['title'] : aiResourceTypeLabel($src['resource_type']);
    $resource = [
        'title'              => $title,
        'resource_type'      => $src['resource_type'],
        'student_content'    => (string) $src['student_content'],
        'teacher_answer_key' => null, // files/decks built here are the student-facing version
    ];
    $teacherName = teacherAiTeacherName($teacherId);
    $file = null;
    $formRow = null;
    $questionsJson = null;
    $maxScore = null;

    if ($format === 'pdf' || $format === 'docx') {
        $ctx = ['student_name' => '', 'grade_level' => '', 'subject_name' => '', 'teacher_name' => $teacherName, 'date' => date('F j, Y'), 'omit_empty_rows' => true];
        $file = aiGenerateResourceFile($resource, $ctx, $format, 'student');
        if (empty($file['success'])) {
            return ['success' => false, 'error' => $file['error'] ?? 'The document could not be generated. Please try again.'];
        }
    } elseif ($format === 'pptx') {
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'error' => 'PowerPoint generation is not available on this server (PHP zip extension missing).'];
        }
        try {
            $titleLines = array_values(array_filter(['Prepared by ' . $teacherName, date('F j, Y')], function ($l) { return $l !== 'Prepared by '; }));
            $bytes = teacherAiPptxBytesFromSlides(teacherAiBuildSlides($title, $resource['student_content'], $titleLines));
        } catch (\Throwable $e) {
            error_log('Teacher AI pptx: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return ['success' => false, 'error' => 'The PowerPoint could not be generated. Please try again.'];
        }
        if (!is_string($bytes) || $bytes === '') {
            return ['success' => false, 'error' => 'The PowerPoint could not be generated. Please try again.'];
        }
        $stored = bin2hex(random_bytes(16)) . '.pptx';
        $full = aiResourceStorageDir() . $stored;
        if (@file_put_contents($full, $bytes) === false) {
            error_log('Teacher AI pptx: failed to write ' . $full);
            return ['success' => false, 'error' => 'The PowerPoint could not be saved. Please try again.'];
        }
        @chmod($full, 0644);
        $file = [
            'success' => true,
            'stored_filename' => $stored,
            'original_filename' => teacherAiDownloadName($title, 'pptx'),
            'mime_type' => TEACHER_AI_PPTX_MIME,
            'file_size' => strlen($bytes),
        ];
    } else { // gform
        $made = teacherAiCreateGoogleForm($src, $title);
        if (empty($made['success'])) {
            return ['success' => false, 'error' => $made['error']];
        }
        $formRow = $made['form'];
        $questionsJson = json_encode($made['questions']);
        $maxScore = (int) $formRow['max_score'];
    }

    $type = $src['resource_type'];
    if ($format === 'gform' && !in_array($type, AI_GFORM_ELIGIBLE_TYPES, true)) {
        $type = 'quiz';
    }

    $newId = insertRecord(
        "INSERT INTO teacher_ai_resources
            (teacher_id, parent_id, resource_type, title, source_prompt, student_content, teacher_answer_key,
             quiz_questions_json, quiz_max_score, output_format, stored_filename, original_filename, mime_type, file_size,
             google_form_id, google_form_edit_url, google_form_view_url, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Generated')",
        [
            $teacherId,
            $parentId,
            $type,
            mb_substr($title, 0, 150),
            $src['source_prompt'],
            $src['student_content'],
            $src['teacher_answer_key'],
            $format === 'gform' ? $questionsJson : $src['quiz_questions_json'],
            $format === 'gform' ? $maxScore : $src['quiz_max_score'],
            $format,
            $file['stored_filename'] ?? null,
            $file['original_filename'] ?? null,
            $file['mime_type'] ?? null,
            $file['file_size'] ?? null,
            $formRow['form_id'] ?? null,
            $formRow['edit_url'] ?? null,
            $formRow['view_url'] ?? null,
        ]
    );
    if (!$newId) {
        if (!empty($file['stored_filename'])) {
            @unlink(aiResourceStorageDir() . $file['stored_filename']);
        }
        return ['success' => false, 'error' => 'It was created, but could not be saved here. Please try again.'];
    }
    teacherAiTagSubject($newId, $src['subject_code'] ?? '');

    logActivity(
        $teacherId,
        'Teacher AI',
        $format === 'gform' ? 'Created Google Form quiz' : 'Generated ' . strtoupper($format) . ' resource',
        "Teacher AI resource #$newId ($title)" . ($format === 'gform' ? ' - ' . count(json_decode($questionsJson, true)) . ' questions, ' . $maxScore . ' points' : '')
    );

    return ['success' => true, 'card' => teacherAiResourceCard(teacherAiGetResource($teacherId, $newId))];
}

// ---- Google Form ------------------------------------------------------

/**
 * Same "turn this material into single-answer multiple-choice items" step Ask AI
 * uses (geminiConvertDraftToQuizQuestions), but for class material -- no
 * student context. Returns [] on any failure.
 */
function teacherAiConvertToQuizQuestions($title, $content, $answerKey = '')
{
    $schema = [
        'type' => 'object',
        'properties' => [
            'quiz_questions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string'],
                        'choices' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'correct_index' => ['type' => 'integer'],
                        'points' => ['type' => 'integer'],
                    ],
                    'required' => ['question', 'choices', 'correct_index'],
                ],
            ],
        ],
        'required' => ['quiz_questions'],
    ];
    $responseFormat = ['type' => 'text', 'mime_type' => 'application/json', 'schema' => $schema];

    $system = "You convert classroom material into an auto-graded multiple-choice quiz for a Google Form.\n"
        . "RESPOND WITH ONLY A SINGLE JSON OBJECT: {\"quiz_questions\": [{\"question\": string (no leading number), \"choices\": [2-6 short answer strings], \"correct_index\": 0-based index of the single correct choice, \"points\": integer, normally 1}]}.\n"
        . "Keep the SAME topic, language and reading level as the material. Keep every existing multiple-choice / true-false question as it is; write single-best-answer multiple-choice items that check the main ideas of any lesson or open-ended item. For true/false use exactly two choices. "
        . "Never mention any individual student.";
    $input = "MATERIAL TO CONVERT\nTitle: " . (string) $title . "\nContent:\n" . mb_substr((string) $content, 0, 7000) . "\n";
    if (trim((string) $answerKey) !== '') {
        $input .= "Answer key:\n" . mb_substr((string) $answerKey, 0, 3000) . "\n";
    }

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $api = $attempt === 1
            ? callGeminiAPI($system, $input, $responseFormat, GEMINI_RESOURCE_TIMEOUT_SECONDS)
            : geminiCallGenerateContentOnly($system, $input, $responseFormat, GEMINI_RESOURCE_TIMEOUT_SECONDS);
        if (empty($api['success'])) {
            return [];
        }
        $text = extractGeminiRawText($api['response'] ?? null);
        if ($text === null) {
            continue;
        }
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = trim(preg_replace('/\s*```$/', '', $text));
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            continue;
        }
        $questions = aiSanitizeQuizQuestions($decoded['quiz_questions'] ?? ($decoded[0] ?? []));
        if (!empty($questions)) {
            return $questions;
        }
    }
    return [];
}

/**
 * Create the auto-graded Google Form through the existing integration
 * (googleFormsCreateQuizForm). Uses the quiz Gemini already returned with the
 * resource, or converts the content to multiple choice first.
 *
 * @return array{success:bool,form?:array,questions?:array,error?:string}
 */
function teacherAiCreateGoogleForm(array $src, $title)
{
    if (!googleFormsIsConfigured()) {
        return ['success' => false, 'error' => 'Google Forms is not set up yet, so I could not create the Form (an Administrator can finish it in Settings -> Google Forms Setup). You can still create a PDF, Word or PowerPoint file.'];
    }
    $questions = aiSanitizeQuizQuestions(json_decode((string) ($src['quiz_questions_json'] ?? ''), true));
    if (empty($questions)) {
        $questions = teacherAiConvertToQuizQuestions($title, $src['student_content'], (string) ($src['teacher_answer_key'] ?? ''));
    }
    if (empty($questions)) {
        return ['success' => false, 'error' => 'No gradable multiple-choice questions could be built from this resource, so a Google Form could not be created. Try regenerating it as a quiz, or use PDF / Word / PowerPoint.'];
    }
    $title = mb_substr(trim((string) $title), 0, 150);
    if ($title === '') {
        $title = aiResourceTypeLabel($src['resource_type']);
    }
    $description = $title . ' - auto-generated by E-GRADEPRO Teacher AI. Your teacher will share the link with you.';
    $form = googleFormsCreateQuizForm($title, $description, $questions);
    if (empty($form['success'])) {
        return ['success' => false, 'error' => $form['error'] ?? 'The Google Form could not be created. Please try again.'];
    }
    return ['success' => true, 'form' => $form, 'questions' => $questions];
}

// ============================================================
// Preview (teacher only)
// ============================================================

/** Read slides back out of a .pptx we generated: [['title'=>..., 'body'=>[...]], ...]. [] if unreadable. */
function teacherAiPptxSlidesFromFile($path)
{
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
        return [];
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [];
    }
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', (string) $name, $m)) {
            $names[(int) $m[1]] = $name;
        }
    }
    ksort($names);

    $slides = [];
    $prev = libxml_use_internal_errors(true);
    foreach ($names as $name) {
        $xml = $zip->getFromName($name);
        if ($xml === false) {
            continue;
        }
        $dom = new DOMDocument();
        if (!$dom->loadXML($xml, LIBXML_NONET)) {
            continue;
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xp->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $slide = ['title' => '', 'body' => []];
        foreach ($xp->query('//p:sp') as $sp) {
            $shapeName = (string) $xp->evaluate('string(p:nvSpPr/p:cNvPr/@name)', $sp);
            foreach ($xp->query('.//a:p', $sp) as $para) {
                $text = '';
                foreach ($xp->query('.//a:t', $para) as $t) {
                    $text .= $t->textContent;
                }
                $text = trim($text);
                if ($text === '') {
                    continue;
                }
                if ($shapeName === 'Title') {
                    $slide['title'] = $slide['title'] === '' ? $text : $slide['title'] . ' ' . $text;
                } else {
                    $slide['body'][] = ($xp->query('a:pPr/a:buChar', $para)->length > 0 ? '• ' : '') . $text;
                }
            }
        }
        $slides[] = $slide;
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $zip->close();
    return $slides;
}

/** Plain-text convention (aiParseContentLines) -> safe HTML for a browser preview. */
function teacherAiContentToHtml($text)
{
    $html = '';
    $inList = false;
    foreach (aiParseContentLines($text) as $b) {
        if ($b['type'] === 'bullet') {
            if (!$inList) { $html .= '<ul>'; $inList = true; }
            $html .= '<li>' . aiInlineRunsToHtml($b['text']) . '</li>';
            continue;
        }
        if ($inList) { $html .= '</ul>'; $inList = false; }
        if ($b['type'] === 'blank') continue;
        $inner = aiInlineRunsToHtml($b['text']);
        if ($b['type'] === 'heading')       $html .= '<h3>' . $inner . '</h3>';
        elseif ($b['type'] === 'choice')    $html .= '<p class="c">' . $inner . '</p>';
        elseif ($b['type'] === 'numbered')  $html .= '<p class="q">' . $inner . '</p>';
        else                                $html .= '<p>' . $inner . '</p>';
    }
    if ($inList) $html .= '</ul>';
    return $html;
}

/** A standalone, escaped HTML page previewing a Word or PowerPoint output. */
function teacherAiPreviewHtml(array $row, $downloadUrl)
{
    $format = (string) $row['output_format'];
    $title = htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars((teacherAiFormatMeta($format)['label'] ?? '') . ' preview - ' . aiResourceTypeLabel($row['resource_type']), ENT_QUOTES, 'UTF-8');
    $body = '';
    if ($format === 'pptx') {
        $path = teacherAiResourceFilePath($row);
        $slides = $path ? teacherAiPptxSlidesFromFile($path) : [];
        if (empty($slides)) {
            $slides = teacherAiSlidesLocal($row['title'], $row['student_content']);
        }
        foreach ($slides as $i => $s) {
            $body .= '<section class="slide"><span class="n">Slide ' . ($i + 1) . ' of ' . count($slides) . '</span><h2>' . htmlspecialchars((string) $s['title'], ENT_QUOTES, 'UTF-8') . '</h2>';
            foreach ($s['body'] as $line) {
                $line = (string) $line;
                $isBullet = mb_substr($line, 0, 2) === '• ';
                $body .= '<p class="' . ($isBullet ? 'b' : 't') . '">' . htmlspecialchars($isBullet ? mb_substr($line, 2) : $line, ENT_QUOTES, 'UTF-8') . '</p>';
            }
            $body .= '</section>';
        }
    } else {
        $body = '<section class="doc"><h2>' . $title . '</h2>' . teacherAiContentToHtml($row['student_content']) . '</section>';
    }
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $title . '</title><style>'
        . ':root{color-scheme:light}body{margin:0;background:#eef2f7;color:#172033;font:15px/1.6 Arial,Helvetica,sans-serif}'
        . 'header{position:sticky;top:0;background:#14213d;color:#fff;padding:12px 20px;display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap}'
        . 'header b{font-size:15px}header small{display:block;color:#c5d1e1;font-size:13.5px}'
        . 'header a{background:#fff;color:#14213d;text-decoration:none;font-weight:600;font-size:14.5px;padding:7px 14px;border-radius:6px}'
        . 'main{max-width:860px;margin:22px auto;padding:0 16px}'
        . '.doc{background:#fff;border:1px solid #dce3ec;border-radius:8px;padding:28px 34px}.doc h2{margin:0 0 14px;color:#14213d}'
        . '.doc h3{margin:18px 0 6px;color:#356ea8;font-size:16px}.doc p{margin:0 0 8px}.doc p.q{margin:14px 0 5px;font-weight:600}.doc p.c{margin:0 0 4px 22px}.doc ul{margin:4px 0 10px;padding-left:24px}'
        . '.slide{background:#fff;border:1px solid #dce3ec;border-radius:8px;padding:22px 30px;margin-bottom:16px;min-height:170px}'
        . '.slide .n{float:right;font-size:12.5px;color:#667085}.slide h2{margin:0 0 12px;color:#14213d;font-size:22px}'
        . '.slide p{margin:0 0 7px;font-size:16px}.slide p.b:before{content:"\2022";color:#356ea8;margin-right:9px}'
        . '</style></head><body><header><div><b>' . $title . '</b><small>' . $label . '</small></div>'
        . '<a href="' . htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') . '">Download ' . htmlspecialchars(teacherAiFormatMeta($format)['label'] ?? '', ENT_QUOTES, 'UTF-8') . '</a></header>'
        . '<main>' . $body . '</main></body></html>';
}

// ============================================================
// Sending to a class
// ============================================================

/**
 * student_support_messages.risk_level / risk_reason / current_grade describe an
 * at-risk intervention (Ask AI fills them from the student's grade). A class-wide
 * resource has no such thing, so they are left NULL wherever the live table
 * allows it. A database whose columns are NOT NULL gets a neutral value instead
 * so the insert still succeeds. Nothing student-facing ever reads these columns.
 */
function teacherAiSupportRiskValues()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = ['risk_level' => null, 'risk_reason' => null, 'current_grade' => null];
    $rows = getRecords(
        "SELECT COLUMN_NAME, IS_NULLABLE, DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_support_messages'
           AND COLUMN_NAME IN ('risk_level', 'risk_reason', 'current_grade')"
    );
    foreach ($rows as $r) {
        if ($r['IS_NULLABLE'] === 'YES') {
            continue;
        }
        $col = $r['COLUMN_NAME'];
        if ($col === 'current_grade') {
            $cache[$col] = 0;
        } elseif ($col === 'risk_reason') {
            $cache[$col] = 'Class-wide resource from Teacher AI.';
        } elseif (strtolower($r['DATA_TYPE']) === 'enum' && preg_match_all("/'([^']+)'/", $r['COLUMN_TYPE'], $m) && !empty($m[1])) {
            $pick = $m[1][0];
            foreach ($m[1] as $v) {
                if (preg_match('/low|none|n\/a|no\s*risk/i', $v)) { $pick = $v; break; }
            }
            $cache[$col] = $pick;
        } else {
            $cache[$col] = 'N/A';
        }
    }
    return $cache;
}

/**
 * Deliver one created file/Form to the chosen students of ONE of this teacher's
 * classes. See the file header for the guarantees.
 *
 * @param array $req resource_id, grade_level, section_id, subject_code, term, scope ('selected'|'all'), student_ids[], note
 * @return array{success:bool,error?:string,sent?:int,already_sent?:int,failed?:int,message?:string,sent_count?:int}
 */
function teacherAiSendResource($teacherId, array $req)
{
    global $db;
    $teacherId = (int) $teacherId;
    $fail = function ($msg) { return ['success' => false, 'error' => $msg]; };

    if (!teacherAiEnsureSchema()) {
        return $fail('Teacher AI could not prepare its storage. Please try again, or ask an administrator to run database/migrations/2026_teacher_ai_resources.sql.');
    }

    // ---- 1. The resource: this teacher's own, with a real file / Form. ----
    $res = teacherAiGetResource($teacherId, (int) ($req['resource_id'] ?? 0));
    if (!$res || empty($res['output_format'])) {
        return $fail('Create a PDF, Word, PowerPoint or Google Form first, then send it.');
    }
    $format = (string) $res['output_format'];
    if ($format === 'gform') {
        if (empty($res['google_form_id']) || empty($res['google_form_view_url'])) {
            return $fail('This Google Form is missing its link. Regenerate it and try again.');
        }
    } elseif (teacherAiResourceFilePath($res) === null) {
        return $fail('The file is no longer available on the server. Regenerate it and try again.');
    }
    if ($format === 'pptx' && !teacherAiPptxDeliverable()) {
        return $fail('Sending PowerPoint files needs a one-time database update that could not be applied automatically. Ask an administrator to run database/migrations/2026_teacher_ai_resources.sql, or send it as PDF or Word.');
    }

    // ---- 2. The class: only one this teacher is assigned to. ----
    $class = teacherAiValidateClass($teacherId, (int) ($req['section_id'] ?? 0), (string) ($req['subject_code'] ?? ''), (string) ($req['term'] ?? ''), $req['grade_level'] ?? null);
    if (empty($class['success'])) {
        return $fail($class['error']);
    }
    $sectionId = (int) $req['section_id'];
    $subjectCode = (string) $req['subject_code'];
    // Made in subject mode? Then it goes only to classes of that same subject.
    $madeFor = trim((string) ($res['subject_code'] ?? ''));
    if ($madeFor !== '' && $madeFor !== $subjectCode) {
        $madeForRow = getRecord("SELECT subject_name FROM subjects WHERE subject_code = ?", [$madeFor]);
        return $fail('This material was made for ' . ($madeForRow['subject_name'] ?? $madeFor) . ' and can only be sent to that subject\'s classes.');
    }
    $syId = (int) $class['sy']['sy_id'];
    $term = $class['term'];
    $subjectName = (string) $class['class']['subject_name'];
    $sectionName = (string) $class['class']['section_name'];

    // Made from "Send to <item> AI": only that item's own class, and the
    // score is recorded on that item (never a new, duplicate one).
    $linked = aim_draftAssessmentPayload($res, $teacherId);
    if ($linked && ((int) $linked['section_id'] !== $sectionId || $linked['subject_code'] !== $subjectCode || $linked['term'] !== $term)) {
        return $fail('This was made for ' . $linked['label'] . ' (' . $linked['term'] . ') and can only be sent to that class.');
    }

    // ---- 3. The students: the roster is re-read; posted ids are only ever
    //         intersected with it. ----
    $roster = teacherAiClassRoster($sectionId, $syId);
    $rosterIds = [];
    foreach ($roster as $s) {
        $rosterIds[(int) $s['student_id']] = true;
    }
    $scope = ($req['scope'] ?? '') === 'all' ? 'all' : 'selected';
    if ($scope === 'all') {
        $targetIds = array_keys($rosterIds);
    } else {
        $raw = is_array($req['student_ids'] ?? null) ? array_filter($req['student_ids'], 'is_scalar') : [];
        $targetIds = array_values(array_unique(array_filter(array_map('intval', $raw), function ($id) { return $id > 0; })));
        if (empty($targetIds)) {
            return $fail('Select at least one student, or choose Send to All Students.');
        }
        foreach ($targetIds as $id) {
            if (!isset($rosterIds[$id])) {
                return $fail('One or more selected students are not enrolled in this class.');
            }
        }
    }
    if (empty($targetIds)) {
        return $fail('There are no enrolled students in this class yet.');
    }
    if (count($targetIds) > TEACHER_AI_MAX_RECIPIENTS) {
        return $fail('That is more students than one send allows. Send to a smaller group.');
    }

    // ---- 4. The message the student sees. ----
    $note = trim((string) ($req['note'] ?? ''));
    $note = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $note);
    if (mb_strlen($note) > 500) {
        $note = mb_substr($note, 0, 500);
    }
    $typeLabel = aiResourceTypeLabel($res['resource_type']);
    $message = 'Your teacher shared a new ' . $typeLabel . ': "' . $res['title'] . '" for ' . $subjectName . ' (' . $term . ').';
    $linkedTask = $linked ? aim_getTaskForItem((int) $linked['item_id']) : null;
    if ($linkedTask) {
        $message .= "\nAvailable from: " . aim_formatDateTime($linkedTask['start_at']) . "\nDeadline: " . aim_formatDateTime($linkedTask['end_at'])
            . "\nThe lesson material is under \"Assessments & Lessons\" on this page.";
    }
    if ($note !== '') {
        $message .= "\n\n" . $note;
    }

    // ---- 4b. Record it in Manage Assessment Items (Written/Oral Works,
    //          Performance Tasks or Examinations) BEFORE anything is sent, so a
    //          problem with the choice (e.g. an examination with no ST1/ST2/TE
    //          tag) is fixed by the teacher instead of half-sending. ----
    $plan = $linked ? ['mode' => 'linked'] : teacherAiResolveRecordPlan($res, $req);
    if (!empty($plan['error'])) {
        return $fail($plan['error']);
    }
    $item = null;
    $itemCreated = false;
    if ($linked && $linked['records_score']) {
        // Written/Oral Works item: the online score syncs straight into it.
        // A Performance Task stays teacher-encoded, so nothing is linked.
        if (!aiAssessmentLinkColumnsReady()) {
            return $fail('Recording this in Manage Assessment Items needs a one-time database update that could not be applied automatically. Ask an administrator to run database/migrations/2026_ai_quiz_assessment_link.sql.');
        }
        $item = getRecord("SELECT * FROM assessment_items WHERE item_id = ? AND teacher_id = ?", [(int) $linked['item_id'], $teacherId]);
    }
    if ($plan['mode'] === 'record') {
        if (!aiAssessmentLinkColumnsReady()) {
            return $fail('Recording this in Manage Assessment Items needs a one-time database update that could not be applied automatically. Ask an administrator to run database/migrations/2026_ai_quiz_assessment_link.sql, or choose "Don\'t record".');
        }
        $ensured = aiAssessmentEnsureItem(
            $teacherId, $sectionId, $subjectCode, $syId, $term,
            $plan['category'], $plan['subtype'], $plan['component'], (string) $res['title'], $plan['hps']
        );
        if (empty($ensured['success'])) {
            return $fail($ensured['error']);
        }
        $item = $ensured['item'];
        $itemCreated = !empty($ensured['created']);
    }

    $sendId = insertRecord(
        "INSERT INTO teacher_ai_resource_sends (resource_id, teacher_id, school_year_id, section_id, subject_code, term, grade_level, scope, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [(int) $res['resource_id'], $teacherId, $syId, $sectionId, $subjectCode, $term, $class['grade_level'], $scope, $note !== '' ? $note : null]
    );
    if (!$sendId) {
        return $fail('Could not record the send. Please try again.');
    }
    executeQuery(
        "UPDATE teacher_ai_resource_sends SET record_category = ?, record_item_id = ? WHERE send_id = ?",
        [$item ? $item['category'] : 'none', $item ? (int) $item['item_id'] : null, (int) $sendId]
    );

    $risk = teacherAiSupportRiskValues();
    $sent = 0;
    $emailIds = []; // students who got it -> emailed after the loop
    $already = 0;
    $failed = 0;
    $relinked = 0;

    foreach ($targetIds as $studentId) {
        $dup = getRecord(
            "SELECT recipient_id, student_resource_id FROM teacher_ai_resource_recipients WHERE resource_id = ? AND student_id = ? AND subject_code = ?",
            [(int) $res['resource_id'], $studentId, $subjectCode]
        );
        if ($dup) {
            $already++;
            // They got it earlier (perhaps before recording existed): make sure
            // it counts toward the item now, and pick up an answer they already gave.
            if ($item && !empty($dup['student_resource_id'])) {
                $prev = getRecord("SELECT link_item_id FROM ai_generated_resources WHERE resource_id = ? AND student_id = ?", [(int) $dup['student_resource_id'], $studentId]);
                if ($prev && empty($prev['link_item_id']) && aiAssessmentLinkResource((int) $dup['student_resource_id'], $item, $term)) {
                    $relinked++;
                    teacherAiSyncExistingSubmission((int) $dup['student_resource_id']);
                }
            }
            continue;
        }

        $inTx = false;
        try {
            $inTx = $db->beginTransaction();
        } catch (\Throwable $e) {
            $inTx = false;
        }

        if ($format === 'gform') {
            $studentResourceId = insertRecord(
                "INSERT INTO ai_generated_resources
                    (teacher_id, student_id, subject_code, school_year_id, resource_type, title, source_prompt,
                     student_content, teacher_answer_key, audience, output_format,
                     google_form_id, google_form_edit_url, google_form_view_url, quiz_questions_json, quiz_max_score, quiz_one_attempt,
                     status, generated_at, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 'student', 'gform', ?, ?, ?, ?, ?, 0, 'Sent', NOW(), NOW())",
                [
                    $teacherId, $studentId, $subjectCode, $syId, $res['resource_type'], $res['title'], $res['source_prompt'],
                    trim((string) $res['student_content']) !== '' ? $res['student_content'] : $res['title'],
                    $res['google_form_id'], $res['google_form_edit_url'], $res['google_form_view_url'],
                    $res['quiz_questions_json'], $res['quiz_max_score'],
                ]
            );
        } else {
            $studentResourceId = insertRecord(
                "INSERT INTO ai_generated_resources
                    (teacher_id, student_id, subject_code, school_year_id, resource_type, title, source_prompt,
                     student_content, teacher_answer_key, audience, output_format, stored_filename, original_filename,
                     mime_type, file_size, status, generated_at, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 'student', ?, ?, ?, ?, ?, 'Sent', NOW(), NOW())",
                [
                    $teacherId, $studentId, $subjectCode, $syId, $res['resource_type'], $res['title'], $res['source_prompt'],
                    $res['student_content'], $format, $res['stored_filename'], $res['original_filename'],
                    $res['mime_type'], $res['file_size'],
                ]
            );
        }

        // Count toward the assessment item: this is what lets the student's real
        // score reach Grade Management the moment they submit.
        $linkedOk = true;
        if ($studentResourceId && $item) {
            $linkedOk = aiAssessmentLinkResource((int) $studentResourceId, $item, $term);
        }

        $messageId = ($studentResourceId && $linkedOk) ? insertRecord(
            "INSERT INTO student_support_messages
                (teacher_id, student_id, subject_code, school_year_id, risk_level, risk_reason, current_grade,
                 support_type, ai_draft, final_message, resource_id, source, status, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Teacher AI', NULL, ?, ?, 'ai', 'Sent', NOW())",
            [$teacherId, $studentId, $subjectCode, $syId, $risk['risk_level'], $risk['risk_reason'], $risk['current_grade'], $message, (int) $studentResourceId]
        ) : false;

        $recipientId = $messageId ? insertRecord(
            "INSERT INTO teacher_ai_resource_recipients (send_id, resource_id, student_id, subject_code, student_resource_id, message_id)
             VALUES (?, ?, ?, ?, ?, ?)",
            [(int) $sendId, (int) $res['resource_id'], $studentId, $subjectCode, (int) $studentResourceId, (int) $messageId]
        ) : false;

        if ($studentResourceId && $linkedOk && $messageId && $recipientId) {
            if ($inTx && $db->inTransaction()) {
                $db->commit();
            }
            $sent++;
            $emailIds[] = (int) $studentId;
        } else {
            if ($inTx && $db->inTransaction()) {
                $db->rollBack();
            }
            // Non-transactional engines: remove whatever half made it in.
            if ($messageId) executeQuery("DELETE FROM student_support_messages WHERE message_id = ?", [(int) $messageId]);
            if ($studentResourceId) executeQuery("DELETE FROM ai_generated_resources WHERE resource_id = ?", [(int) $studentResourceId]);
            $failed++;
        }
    }

    executeQuery(
        "UPDATE teacher_ai_resource_sends SET recipient_count = ?, skipped_count = ?, failed_count = ? WHERE send_id = ?",
        [$sent, $already, $failed, (int) $sendId]
    );
    // The same students now get the item's lesson material and Start/Deadline
    // window (Upcoming / Active / Completed / Missing). Teacher AI's message
    // above already told them, so no second message.
    if ($linkedTask && ($sent > 0 || $already > 0)) {
        aim_sendTaskToClass((int) $linkedTask['task_id'], $teacherId, $targetIds, false);
    }
    if (!empty($emailIds)) {
        require_once __DIR__ . '/student_email_notify.php';
        $taiItem = !empty($linkedTask['item_id']) ? getRecord("SELECT category, topic FROM assessment_items WHERE item_id = ?", [(int) $linkedTask['item_id']]) : null;
        senNotifyStudents($emailIds, [
            'key' => 'tai_send:' . (int) $sendId,
            'subject_code' => $subjectCode, 'teacher_id' => (int) $teacherId,
            'title' => $res['title'], 'kind' => aiResourceTypeLabel($res['resource_type']),
            'deadline' => $linkedTask['end_at'] ?? null,
            'grading' => $taiItem['category'] ?? null,
            'topic' => ($linkedTask['topic'] ?? '') !== '' ? $linkedTask['topic'] : ($taiItem['topic'] ?? ''),
            'link' => 'modules/students/portal/subject.php?subject=' . rawurlencode((string) $subjectCode),
        ]);
    }
    if ($sent > 0) {
        executeQuery("UPDATE teacher_ai_resources SET status = 'Sent', sent_at = NOW() WHERE resource_id = ?", [(int) $res['resource_id']]);
        logActivity(
            $teacherId,
            'Teacher AI',
            'Sent resource to class',
            "Teacher AI resource #" . (int) $res['resource_id'] . " (" . $res['title'] . ") sent to $sent student(s) in section #$sectionId ($sectionName), subject $subjectCode, $term. Scope: $scope."
        );
    }

    if ($sent === 0 && $failed > 0) {
        return $fail('The resource could not be delivered to the students. Please try again.');
    }
    $parts = [];
    if ($sent > 0) {
        $parts[] = 'Sent to ' . $sent . ' student' . ($sent === 1 ? '' : 's');
    }
    if ($already > 0) {
        $parts[] = $already . ' already had it' . ($relinked > 0 ? ' (' . $relinked . ' now recorded in Manage Assessment Items)' : '');
    }
    if ($failed > 0) {
        $parts[] = $failed . ' could not be reached';
    }
    if ($sent === 0 && $already > 0) {
        $parts = ['Everyone selected already has this resource'];
    }
    $total = getRecord("SELECT COUNT(*) AS n FROM teacher_ai_resource_recipients WHERE resource_id = ?", [(int) $res['resource_id']]);
    $message = implode(', ', $parts) . ' - ' . $subjectName . ', ' . $class['grade_level'] . ' ' . $sectionName . ', ' . $term . '.';
    $record = null;
    if ($item) {
        $catLabels = aiAssessmentCategoryLabels();
        $record = [
            'created'        => $itemCreated,
            'item_id'        => (int) $item['item_id'],
            'item_name'      => html_entity_decode((string) $item['item_name'], ENT_QUOTES, 'UTF-8'),
            'category'       => $item['category'],
            'category_label' => $catLabels[$item['category']] ?? $item['category'],
            'subtype'        => $item['assessment_subtype'] ?? null,
            'component'      => $item['component'] ?? null,
            'highest_score'  => (float) $item['highest_score'],
            'term'           => $term,
        ];
        $message .= ' Saved in Manage Assessment Items as "' . $record['item_name'] . '" (' . $record['category_label']
            . ($record['subtype'] ? ' - ' . $record['subtype'] : '') . ', ' . $term . ($itemCreated ? '' : ', already there') . ').';
    }
    return [
        'success' => true,
        'sent' => $sent,
        'already_sent' => $already,
        'failed' => $failed,
        'sent_count' => (int) ($total['n'] ?? 0),
        'message' => $message,
        'record' => $record,
    ];
}
