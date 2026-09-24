<?php
// includes/assessment_material_helpers.php
//
// Learning Materials (PDF / Word / link) attached to a single row in Manage
// Assessment Items (assessment_items) -- e.g. a reviewer PDF or a YouTube
// video for "Quiz 1". Unlike a Tutorial Plan item (one material per item),
// an assessment item can have SEVERAL materials at once.
//
// Reuses the exact same upload validation (real MIME/signature checks,
// size limit, random stored filenames) already used by the Tutorial Plan's
// Learning Material feature, so this does not introduce a second, weaker
// copy of that security logic.
//
// A Quiz / Examination item's materials can also be SENT to every student
// of that item's own class (subject + section) as a "task" with a Start and
// a Deadline -- see the LEARNING MATERIAL TASKS section at the bottom. The
// item's score (assessment_scores) is what marks a student Completed, so the
// result reaches Student Monitoring through the item's Topic exactly like
// every other scored assessment item.
require_once __DIR__ . '/tutorial_helpers.php';
require_once __DIR__ . '/classroom_helpers.php'; // classroomSectionStudents()

/** Directory (relative to the project root) where uploaded assessment item materials are stored. */
define('AIM_MATERIAL_UPLOAD_DIR', 'static/uploads/assessment_materials');

/**
 * Make sure `assessment_item_materials` exists. Additive and idempotent
 * (CREATE TABLE IF NOT EXISTS) -- same self-migrating pattern already used
 * throughout grading_helpers.php (e.g. gh_ensureTermFinalizationsTable()),
 * so there is nothing to manually import. Runs at most once per request.
 */
function aim_ensureTable()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (schemaIsReady('aim_tables_v1')) return;
    executeQuery(
        "CREATE TABLE IF NOT EXISTS assessment_item_materials (
            material_id INT(11) NOT NULL AUTO_INCREMENT,
            item_id INT(11) NOT NULL,
            teacher_id INT(11) NOT NULL,
            resource_type ENUM('pdf','word','powerpoint','link') NOT NULL DEFAULT 'link',
            resource_url VARCHAR(500) DEFAULT NULL,
            resource_file VARCHAR(255) DEFAULT NULL COMMENT 'Generated, unguessable stored filename under static/uploads/assessment_materials/ -- never the original uploaded filename.',
            resource_file_original_name VARCHAR(255) DEFAULT NULL,
            resource_file_size INT(11) DEFAULT NULL,
            resource_mime_type VARCHAR(150) DEFAULT NULL,
            title VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (material_id),
            KEY idx_aim_item (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // Per-file topic (an Examination can cover several topics, one file each).
    $hasTopic = getRecord(
        "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_item_materials' AND COLUMN_NAME = 'topic'"
    );
    if (!$hasTopic || (int) $hasTopic['n'] === 0) {
        executeQuery("ALTER TABLE assessment_item_materials
            ADD COLUMN topic VARCHAR(150) DEFAULT NULL COMMENT 'Optional topic this one file covers (Examinations may cover several topics).' AFTER title");
    }

    executeQuery(
        "CREATE TABLE IF NOT EXISTS learning_material_tasks (
            task_id INT(11) NOT NULL AUTO_INCREMENT,
            item_id INT(11) NOT NULL COMMENT 'assessment_items.item_id -- the Quiz/Examination whose score marks a student Completed.',
            teacher_id INT(11) NOT NULL,
            subject_code VARCHAR(20) NOT NULL,
            section_id INT(11) NOT NULL,
            school_year_id INT(11) NOT NULL,
            term VARCHAR(20) NOT NULL,
            assessment_type ENUM('Quiz','Examination') NOT NULL DEFAULT 'Quiz',
            exam_term VARCHAR(20) DEFAULT NULL COMMENT 'Examinations only: Term 1 / Term 2 / Term 3.',
            title VARCHAR(255) NOT NULL,
            topic VARCHAR(150) DEFAULT NULL,
            start_at DATETIME NOT NULL,
            end_at DATETIME NOT NULL,
            sent_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (task_id),
            UNIQUE KEY uniq_lmt_item (item_id),
            KEY idx_lmt_class (teacher_id, subject_code, section_id, school_year_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    executeQuery(
        "CREATE TABLE IF NOT EXISTS learning_material_task_students (
            id INT(11) NOT NULL AUTO_INCREMENT,
            task_id INT(11) NOT NULL,
            student_id INT(11) NOT NULL,
            status ENUM('Assigned','Completed','Missing') NOT NULL DEFAULT 'Assigned' COMMENT 'Upcoming/Active are not stored -- they come from start_at/end_at.',
            completed_at DATETIME DEFAULT NULL,
            missing_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_lmts (task_id, student_id),
            KEY idx_lmts_student (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    schemaMarkReady('aim_tables_v1');
}

/**
 * All materials for a set of assessment_items, grouped by item_id -- one
 * query for the whole Manage Assessment Items page instead of one per row.
 * @param int[] $item_ids
 * @return array<int, array> item_id => list of material rows (oldest first)
 */
function aim_getMaterialsForItems(array $item_ids)
{
    aim_ensureTable();
    $item_ids = array_values(array_unique(array_map('intval', $item_ids)));
    if (empty($item_ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
    $rows = getRecords(
        "SELECT * FROM assessment_item_materials WHERE item_id IN ($placeholders) ORDER BY created_at ASC, material_id ASC",
        $item_ids
    );
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(int) $row['item_id']][] = $row;
    }
    return $grouped;
}

/** A single material row, or null. */
function aim_getMaterial($material_id)
{
    aim_ensureTable();
    return getRecord("SELECT * FROM assessment_item_materials WHERE material_id = ?", [intval($material_id)]);
}

/**
 * Metadata for a material's resource_type -- reuses tutorialMaterialMeta()
 * so the icon/label/badge styling matches the Tutorial Plan feature exactly.
 */
function aim_materialMeta($type)
{
    return tutorialMaterialMeta($type);
}

/** Display title: the teacher-entered title if set, else a sensible fallback. */
function aim_materialDisplayTitle(array $material)
{
    if (!empty($material['title'])) {
        return $material['title'];
    }
    if ($material['resource_type'] === 'link') {
        return $material['resource_url'] ?? '';
    }
    if (!empty($material['resource_file_original_name'])) {
        return tutorialSafeDisplayFilename($material['resource_file_original_name']);
    }
    return '';
}

/**
 * Absolute filesystem path to the upload directory, creating it if needed.
 * static/uploads/ is already blocked from script execution site-wide (see
 * static/uploads/.htaccess), so no new .htaccess is needed here.
 */
function aim_materialStorageDir()
{
    $dir = rtrim(dirname(__DIR__), '/\\') . '/' . AIM_MATERIAL_UPLOAD_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Insert a new link-type material for an assessment item.
 * @return int|false new material_id, or false on failure
 */
function aim_addLinkMaterial($item_id, $teacher_id, $url, $title, $topic = null)
{
    aim_ensureTable();
    $result = executeQuery(
        "INSERT INTO assessment_item_materials (item_id, teacher_id, resource_type, resource_url, title, topic) VALUES (?, ?, 'link', ?, ?, ?)",
        [intval($item_id), intval($teacher_id), $url, $title, $topic]
    );
    if ($result === false) {
        return false;
    }
    global $db;
    return (int) $db->lastInsertId();
}

/**
 * Insert a new uploaded-file material for an assessment item.
 * @return int|false new material_id, or false on failure
 */
function aim_addFileMaterial($item_id, $teacher_id, $resourceType, $storedName, $originalName, $size, $mime, $title, $topic = null)
{
    aim_ensureTable();
    $result = executeQuery(
        "INSERT INTO assessment_item_materials
            (item_id, teacher_id, resource_type, resource_file, resource_file_original_name, resource_file_size, resource_mime_type, title, topic)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [intval($item_id), intval($teacher_id), $resourceType, $storedName, $originalName, (int) $size, $mime, $title, $topic]
    );
    if ($result === false) {
        return false;
    }
    global $db;
    return (int) $db->lastInsertId();
}

/** Delete a material row and, if it was an uploaded file, its file on disk. */
function aim_deleteMaterial(array $material)
{
    aim_ensureTable();
    executeQuery("DELETE FROM assessment_item_materials WHERE material_id = ?", [(int) $material['material_id']]);
    if (!empty($material['resource_file'])) {
        $path = aim_materialStorageDir() . '/' . basename($material['resource_file']);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}


// ============================================================
// LEARNING MATERIAL TASKS -- "Add to All Students"
// ============================================================
// One task per online assessment item (Start/Deadline window, set with
// "Send to <item> AI"). When Teacher AI sends the generated assessment to
// the class, those students become recipients (only students of the item's
// own section + school year). Examinations are manual and have no task.
// A student's status is:
//   Completed -- the item has a score for them in assessment_scores
//                (Encode Grades, or an auto-graded portal quiz synced there)
//   Upcoming  -- before start_at
//   Active    -- between start_at and end_at
//   Missing   -- end_at has passed with no score (stored automatically by
//                aim_syncTaskStatuses() so it is kept as a record)
// Nothing here writes to assessment_scores or grades.

/** 'Y-m-d H:i:s' in PHP's timezone (Asia/Manila) -- never the DB server clock. */
function aim_now()
{
    return date('Y-m-d H:i:s');
}

/** The task for one item, or null. */
function aim_getTaskForItem($item_id)
{
    aim_ensureTable();
    return getRecord("SELECT * FROM learning_material_tasks WHERE item_id = ?", [intval($item_id)]) ?: null;
}

/**
 * Tasks for many items at once, with per-status counts.
 * @return array<int, array> item_id => task row + 'counts' => [Upcoming, Active, Completed, Missing, total]
 */
function aim_getTasksForItems(array $item_ids)
{
    aim_ensureTable();
    $item_ids = array_values(array_unique(array_map('intval', $item_ids)));
    if (!$item_ids) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($item_ids), '?'));
    $tasks = getRecords("SELECT * FROM learning_material_tasks WHERE item_id IN ($ph)", $item_ids);
    if (!$tasks) {
        return [];
    }
    aim_syncTaskStatuses($tasks);

    $task_ids = array_map(function ($t) { return (int) $t['task_id']; }, $tasks);
    $ph2 = implode(',', array_fill(0, count($task_ids), '?'));
    $rows = getRecords(
        "SELECT task_id, status, COUNT(*) AS n FROM learning_material_task_students WHERE task_id IN ($ph2) GROUP BY task_id, status",
        $task_ids
    );
    $stored = [];
    foreach ($rows as $r) {
        $stored[(int) $r['task_id']][$r['status']] = (int) $r['n'];
    }

    $out = [];
    $now = aim_now();
    foreach ($tasks as $t) {
        $s = $stored[(int) $t['task_id']] ?? [];
        $assigned = $s['Assigned'] ?? 0;
        $counts = [
            'Upcoming'  => $now < $t['start_at'] ? $assigned : 0,
            'Active'    => $now >= $t['start_at'] ? $assigned : 0,
            'Completed' => $s['Completed'] ?? 0,
            'Missing'   => $s['Missing'] ?? 0,
        ];
        $counts['total'] = $assigned + $counts['Completed'] + $counts['Missing'];
        $t['counts'] = $counts;
        $out[(int) $t['item_id']] = $t;
    }
    return $out;
}

/**
 * Create or update the task for an item. $data: assessment_type, exam_term,
 * title, topic, start_at, end_at (all already validated by the caller).
 * @return int|false task_id
 */
function aim_saveTask(array $item, $teacher_id, array $data)
{
    aim_ensureTable();
    $existing = aim_getTaskForItem($item['item_id']);
    $params = [
        $data['assessment_type'], $data['exam_term'], $data['title'], $data['topic'],
        $data['start_at'], $data['end_at'],
    ];
    if ($existing) {
        $ok = executeQuery(
            "UPDATE learning_material_tasks SET assessment_type = ?, exam_term = ?, title = ?, topic = ?, start_at = ?, end_at = ?
             WHERE task_id = ? AND teacher_id = ?",
            array_merge($params, [(int) $existing['task_id'], (int) $teacher_id])
        );
        return $ok === false ? false : (int) $existing['task_id'];
    }
    $id = insertRecord(
        "INSERT INTO learning_material_tasks
            (assessment_type, exam_term, title, topic, start_at, end_at, item_id, teacher_id, subject_code, section_id, school_year_id, term)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        array_merge($params, [
            (int) $item['item_id'], (int) $teacher_id, $item['subject_code'], (int) $item['section_id'],
            (int) $item['school_year_id'], $item['term'],
        ])
    );
    return $id ? (int) $id : false;
}

/** "Sep 24, 2026 8:00 AM" */
function aim_formatDateTime($dt)
{
    return $dt ? date('M j, Y g:i A', strtotime($dt)) : '';
}

/**
 * Add students to the task: every Approved student of the item's own section
 * (same roster query as the rest of the Teacher Portal), or only $student_ids
 * when given (always intersected with that roster). Students already on the
 * task are kept as they are. With $notify, each newly added student also gets
 * a message in their subject page (student_support_messages); Teacher AI's
 * own send passes false because it already writes its message.
 * @return array{total:int, added:int}
 */
function aim_sendTaskToClass($task_id, $teacher_id, $student_ids = null, $notify = true)
{
    aim_ensureTable();
    $task = getRecord("SELECT * FROM learning_material_tasks WHERE task_id = ? AND teacher_id = ?", [(int) $task_id, (int) $teacher_id]);
    if (!$task) {
        return ['total' => 0, 'added' => 0];
    }
    $roster = classroomSectionStudents($task['section_id'], $task['school_year_id']);
    if (is_array($student_ids)) {
        $wanted = array_flip(array_map('intval', $student_ids));
        $roster = array_values(array_filter($roster, function ($st) use ($wanted) { return isset($wanted[(int) $st['student_id']]); }));
    }
    $added = 0;
    $now = aim_now();

    $message = 'New ' . $task['assessment_type'] . ': ' . $task['title']
        . ($task['assessment_type'] === 'Examination' && $task['exam_term'] ? ' (' . $task['exam_term'] . ')' : '') . "\n"
        . ($task['topic'] ? 'Topic: ' . $task['topic'] . "\n" : '')
        . 'Available from: ' . aim_formatDateTime($task['start_at']) . "\n"
        . 'Deadline: ' . aim_formatDateTime($task['end_at']) . "\n"
        . 'Open "Assessments & Lessons" on this page to see the lesson materials. Review them before the deadline.';

    foreach ($roster as $st) {
        $stmt = executeQuery(
            "INSERT IGNORE INTO learning_material_task_students (task_id, student_id, status) VALUES (?, ?, 'Assigned')",
            [(int) $task['task_id'], (int) $st['student_id']]
        );
        if ($stmt && $stmt->rowCount() > 0) {
            $added++;
            if (!$notify) {
                continue;
            }
            executeQuery(
                "INSERT INTO student_support_messages
                    (teacher_id, student_id, subject_code, school_year_id, support_type, final_message, source, status, is_seen, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'learning_material', 'Sent', 0, ?)",
                [(int) $teacher_id, (int) $st['student_id'], $task['subject_code'], (int) $task['school_year_id'],
                 $task['assessment_type'], $message, $now]
            );
        }
    }
    executeQuery("UPDATE learning_material_tasks SET sent_at = ? WHERE task_id = ?", [$now, (int) $task['task_id']]);
    aim_syncTaskStatuses([$task]);
    return ['total' => count($roster), 'added' => $added];
}

/**
 * Bring stored statuses up to date for the given task rows:
 *   scored      -> Completed (even after the deadline)
 *   score gone  -> back to Assigned
 *   past end_at and not scored -> Missing (automatic)
 *   deadline moved later       -> Missing goes back to Assigned
 */
function aim_syncTaskStatuses(array $tasks)
{
    aim_ensureTable();
    $now = aim_now();
    foreach ($tasks as $t) {
        $task_id = (int) $t['task_id'];
        $item_id = (int) $t['item_id'];
        executeQuery(
            "UPDATE learning_material_task_students SET status = 'Completed', completed_at = ?, missing_at = NULL
             WHERE task_id = ? AND status <> 'Completed'
               AND student_id IN (SELECT student_id FROM assessment_scores WHERE item_id = ? AND raw_score IS NOT NULL)",
            [$now, $task_id, $item_id]
        );
        executeQuery(
            "UPDATE learning_material_task_students SET status = 'Assigned', completed_at = NULL
             WHERE task_id = ? AND status = 'Completed'
               AND student_id NOT IN (SELECT student_id FROM assessment_scores WHERE item_id = ? AND raw_score IS NOT NULL)",
            [$task_id, $item_id]
        );
        if ($now > $t['end_at']) {
            executeQuery(
                "UPDATE learning_material_task_students SET status = 'Missing', missing_at = ? WHERE task_id = ? AND status = 'Assigned'",
                [$now, $task_id]
            );
        } else {
            executeQuery(
                "UPDATE learning_material_task_students SET status = 'Assigned', missing_at = NULL WHERE task_id = ? AND status = 'Missing'",
                [$task_id]
            );
        }
    }
}

/** What a student sees: Completed / Missing / Upcoming / Active. */
function aim_displayStatus($stored_status, array $task)
{
    if ($stored_status === 'Completed' || $stored_status === 'Missing') {
        return $stored_status;
    }
    $now = aim_now();
    if ($now < $task['start_at']) {
        return 'Upcoming';
    }
    return $now > $task['end_at'] ? 'Missing' : 'Active';
}

/**
 * A student's tasks for one class (subject + section + school year), newest
 * first, each with 'display_status' and its 'materials'.
 */
function aim_getStudentTasks($student_id, $section_id, $subject_code, $sy_id)
{
    aim_ensureTable();
    $sql = "SELECT t.*, ts.status AS student_status, ai.item_name, ai.category, ai.item_type
            FROM learning_material_tasks t
            INNER JOIN learning_material_task_students ts ON ts.task_id = t.task_id AND ts.student_id = ?
            INNER JOIN assessment_items ai ON ai.item_id = t.item_id
            WHERE t.section_id = ? AND t.subject_code = ? AND t.school_year_id = ?
            ORDER BY t.start_at DESC, t.task_id DESC";
    $params = [(int) $student_id, (int) $section_id, $subject_code, (int) $sy_id];
    $tasks = getRecords($sql, $params);
    if (!$tasks) {
        return [];
    }
    aim_syncTaskStatuses($tasks);
    $tasks = getRecords($sql, $params); // re-read the statuses the sync just stored
    $materials = aim_getMaterialsForItems(array_column($tasks, 'item_id'));
    foreach ($tasks as &$t) {
        $t['display_status'] = aim_displayStatus($t['student_status'], $t);
        $t['kind_label'] = aim_kindMeta(aim_assessmentKind($t))['label'];
        $t['materials'] = $materials[(int) $t['item_id']] ?? [];
    }
    unset($t);
    return $tasks;
}

/**
 * May this student open this material? Only when its item was sent to them
 * and the task's start time has passed.
 */
function aim_studentCanViewMaterial($student_id, array $material)
{
    aim_ensureTable();
    $row = getRecord(
        "SELECT t.start_at FROM learning_material_tasks t
         INNER JOIN learning_material_task_students ts ON ts.task_id = t.task_id AND ts.student_id = ?
         WHERE t.item_id = ?",
        [(int) $student_id, (int) $material['item_id']]
    );
    return $row && aim_now() >= $row['start_at'];
}

/**
 * For Student Monitoring: every Missing task per student for a teacher
 * (optionally one subject) in a school year.
 * @return array<int, array> student_id => [ ['title','topic','type','deadline'], ... ]
 */
function aim_getMissingTasksByStudent($teacher_id, $sy_id, $subject_code = null)
{
    aim_ensureTable();
    $sql = "SELECT * FROM learning_material_tasks WHERE teacher_id = ? AND school_year_id = ?";
    $params = [(int) $teacher_id, (int) $sy_id];
    if ($subject_code) {
        $sql .= " AND subject_code = ?";
        $params[] = $subject_code;
    }
    $tasks = getRecords($sql, $params);
    if (!$tasks) {
        return [];
    }
    aim_syncTaskStatuses($tasks);
    $byId = [];
    foreach ($tasks as $t) {
        $byId[(int) $t['task_id']] = $t;
    }
    $ph = implode(',', array_fill(0, count($byId), '?'));
    $rows = getRecords(
        "SELECT task_id, student_id FROM learning_material_task_students WHERE status = 'Missing' AND task_id IN ($ph)",
        array_keys($byId)
    );
    $out = [];
    foreach ($rows as $r) {
        $t = $byId[(int) $r['task_id']];
        $out[(int) $r['student_id']][] = [
            'title'    => $t['title'],
            'topic'    => (string) $t['topic'],
            'type'     => $t['assessment_type'],
            'deadline' => aim_formatDateTime($t['end_at']),
        ];
    }
    return $out;
}

/**
 * What an item's learning materials cover: the task topic plus every
 * per-file topic (an Examination may cover several), and the material
 * titles. Used by the Student Monitoring AI recommendation.
 * @return array{type:?string, topics:string[], materials:string[]}
 */
function aim_itemCoverage($item_id)
{
    aim_ensureTable();
    $topics = [];
    $task = aim_getTaskForItem($item_id);
    if ($task && trim((string) $task['topic']) !== '') {
        $topics[] = trim($task['topic']);
    }
    $materials = [];
    foreach (aim_getMaterialsForItems([$item_id])[(int) $item_id] ?? [] as $m) {
        $mTopic = trim((string) ($m['topic'] ?? ''));
        $materials[] = aim_materialDisplayTitle($m) . ($mTopic !== '' ? ' (topic: ' . $mTopic . ')' : '');
        if ($mTopic !== '') {
            $topics[] = $mTopic;
        }
    }
    return [
        'type'      => $task ? $task['assessment_type'] : null,
        'topics'    => array_values(array_unique($topics)),
        'materials' => $materials,
    ];
}

// ============================================================
// ASSESSMENT CONTEXT -- Manage Assessment Items is the source
// ============================================================
// Everything below reads the assessment item the teacher already created
// (name, category, HPS, subject, section) instead of asking for it again.

/**
 * What kind of assessment an item is, from its category and name:
 *   exam (Examinations -- manual only), performance_task, quiz, oral,
 *   activity, written_work.
 */
function aim_assessmentKind(array $item)
{
    $category = $item['category'] ?? '';
    if ($category === 'QA') {
        return 'exam';
    }
    if ($category === 'PT') {
        return 'performance_task';
    }
    $name = mb_strtolower(trim(($item['item_name'] ?? '') . ' ' . ($item['item_type'] ?? '')));
    if (preg_match('/\b(recit\w*|oral\w*|reporting|interview)\b/u', $name)) {
        return 'oral';
    }
    if (preg_match('/\b(quiz\w*|test|long test|q\s*\d+|qz\s*\d+)\b/u', $name) || preg_match('/^(q|qz)[\s_-]*\d+$/u', trim($name))) {
        return 'quiz';
    }
    if (preg_match('/\b(activit\w*|worksheet|seatwork|exercise|drill|act\s*\d+)\b/u', $name)) {
        return 'activity';
    }
    return 'written_work';
}

/**
 * Per kind: label, the Teacher AI resource_type to generate, and whether
 * students can answer it online (auto-graded portal quiz / Google Form).
 */
function aim_kindMeta($kind)
{
    $map = [
        'quiz'             => ['label' => 'Quiz', 'resource_type' => 'quiz', 'online' => true],
        'activity'         => ['label' => 'Activity', 'resource_type' => 'worksheet', 'online' => false],
        'written_work'     => ['label' => 'Written Work', 'resource_type' => 'written_work', 'online' => false],
        'oral'             => ['label' => 'Oral Work / Recitation', 'resource_type' => 'question_bank', 'online' => false],
        'performance_task' => ['label' => 'Performance Task', 'resource_type' => 'performance_task', 'online' => false],
        'exam'             => ['label' => 'Examination', 'resource_type' => null, 'online' => false],
    ];
    return $map[$kind] ?? $map['written_work'];
}

/** "Grade 8-A" / "Grade 7 7B" -- the section already carries the grade number most of the time. */
function aim_gradeSectionLabel($gradeLevel, $sectionName)
{
    $gradeLevel = trim((string) $gradeLevel);
    $sectionName = trim((string) $sectionName);
    $num = preg_replace('/\D+/', '', $gradeLevel);
    if ($num !== '' && strpos($sectionName, $num) === 0) {
        return 'Grade ' . $sectionName;
    }
    return trim($gradeLevel . ' ' . $sectionName);
}

/**
 * The teacher's own item with its class details, or null when the item is
 * not this teacher's.
 */
function aim_itemContext($item_id, $teacher_id)
{
    $row = getRecord(
        "SELECT ai.*, sec.section_name, sec.grade_level, sub.subject_name
         FROM assessment_items ai
         INNER JOIN sections sec ON sec.section_id = ai.section_id
         INNER JOIN subjects sub ON sub.subject_code = ai.subject_code
         WHERE ai.item_id = ? AND ai.teacher_id = ? AND ai.is_active = 1",
        [(int) $item_id, (int) $teacher_id]
    );
    if (!$row) {
        return null;
    }
    $kind = aim_assessmentKind($row);
    $gradeSection = aim_gradeSectionLabel($row['grade_level'], $row['section_name']);
    $categoryLabels = ['WW' => 'Written/Oral Works', 'PT' => 'Performance Tasks', 'QA' => 'Examinations'];
    return [
        'item'           => $row,
        'kind'           => $kind,
        'kind_meta'      => aim_kindMeta($kind),
        'category_label' => $categoryLabels[$row['category']] ?? $row['category'],
        'grade_section'  => $gradeSection,
        'hps'            => (float) $row['highest_score'],
        'button_label'   => 'Send to ' . $row['item_name'] . ' AI – ' . $gradeSection . ' ' . $row['subject_name'],
    ];
}

// ============================================================
// READING A LESSON FILE FOR THE AI
// ============================================================
// Same approach as the chat "+" attachment (includes/ai_chat_attachment.php):
// a PDF goes to Gemini as a file part, a .docx / .pptx is read locally.

/** Plain text of a .pptx (every slide, in order), or null. */
function aim_pptxText($bytes)
{
    if (!class_exists('ZipArchive')) {
        return null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'aim');
    if ($tmp === false) {
        return null;
    }
    $slides = [];
    try {
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        if ($zip->open($tmp) === true) {
            $slideFiles = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false && preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                    $slideFiles[(int) $m[1]] = $name;
                }
            }
            ksort($slideFiles);
            foreach ($slideFiles as $num => $name) {
                $xml = $zip->getFromName($name);
                if (is_string($xml) && preg_match_all('#<a:t>(.*?)</a:t>#s', $xml, $mm)) {
                    $text = implode(' ', array_map(function ($t) {
                        return html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }, $mm[1]));
                    $text = trim(preg_replace('/\s+/', ' ', $text));
                    if ($text !== '') {
                        $slides[] = 'Slide ' . $num . ': ' . $text;
                    }
                }
            }
            $zip->close();
        }
    } catch (Throwable $e) {
        $slides = [];
    }
    @unlink($tmp);
    return empty($slides) ? null : implode("\n", $slides);
}

/**
 * A lesson file as Gemini input: ['ok'=>true,'file_part'=>...] for a PDF,
 * ['ok'=>true,'text'=>...] for .docx/.pptx, or ok=false with a plain error.
 * $type is pdf|word|powerpoint; $ext the real extension.
 */
function aim_readFileForAi($path, $type, $ext)
{
    require_once __DIR__ . '/ai_chat_attachment.php'; // aiChatDocxText(), AI_ATTACH_TEXT_MAX_CHARS
    if (!is_file($path)) {
        return ['ok' => false, 'error' => 'The file is not available.'];
    }
    $ext = strtolower((string) $ext);
    if ($type === 'pdf') {
        if (filesize($path) > TUTORIAL_MATERIAL_MAX_BYTES) {
            return ['ok' => false, 'error' => 'This PDF is too large for the AI to read.'];
        }
        return ['ok' => true, 'file_part' => ['type' => 'document', 'data' => base64_encode(file_get_contents($path)), 'mime_type' => 'application/pdf']];
    }
    if ($type === 'word' && $ext === 'docx') {
        $text = aiChatDocxText(file_get_contents($path));
    } elseif ($type === 'powerpoint' && $ext === 'pptx') {
        $text = aim_pptxText(file_get_contents($path));
    } else {
        return ['ok' => false, 'error' => 'Only PDF, .docx and .pptx files can be read by the AI (older .doc/.ppt files cannot).'];
    }
    if ($text === null || trim($text) === '') {
        return ['ok' => false, 'error' => 'No readable text was found in this file.'];
    }
    return ['ok' => true, 'text' => mb_substr($text, 0, AI_ATTACH_TEXT_MAX_CHARS)];
}

/** aim_readFileForAi() for a stored material row (links cannot be read). */
function aim_materialAiInput(array $material)
{
    if ($material['resource_type'] === 'link' || empty($material['resource_file'])) {
        return ['ok' => false, 'error' => 'Links cannot be read by the AI.'];
    }
    $path = aim_materialStorageDir() . '/' . basename($material['resource_file']);
    return aim_readFileForAi($path, $material['resource_type'], pathinfo($material['resource_file'], PATHINFO_EXTENSION));
}

/**
 * Everything the AI should know about an item's lesson materials: combined
 * text of every readable .docx/.pptx, and the first PDF as a file part.
 * @return array{text:string, file_part:?array, names:string[]}
 */
function aim_itemLessonForAi($item_id, $maxChars = 12000)
{
    $text = '';
    $filePart = null;
    $names = [];
    foreach (aim_getMaterialsForItems([$item_id])[(int) $item_id] ?? [] as $m) {
        $names[] = aim_materialDisplayTitle($m) . (!empty($m['topic']) ? ' (topic: ' . $m['topic'] . ')' : '');
        $in = aim_materialAiInput($m);
        if (!$in['ok']) {
            continue;
        }
        if (isset($in['text']) && mb_strlen($text) < $maxChars) {
            $text .= "\n--- " . aim_materialDisplayTitle($m) . " ---\n" . $in['text'];
        } elseif (isset($in['file_part']) && $filePart === null) {
            $filePart = $in['file_part'];
        }
    }
    return ['text' => mb_substr(trim($text), 0, $maxChars), 'file_part' => $filePart, 'names' => $names];
}

/**
 * Ask Gemini for the main topic of a lesson file. Only a short label comes
 * back; the teacher can still edit it.
 * @return array{ok:bool, topic?:string, error?:string}
 */
function aim_detectTopic(array $aiInput, $context = '')
{
    require_once __DIR__ . '/gemini_service.php';
    if (empty($aiInput['ok'])) {
        return ['ok' => false, 'error' => $aiInput['error'] ?? 'The file could not be read.'];
    }
    $schema = [
        'type' => 'object',
        'properties' => ['topic' => ['type' => 'string']],
        'required' => ['topic'],
    ];
    $system = "You read a teacher's lesson material and name its MAIN lesson topic.\n"
        . "RESPOND WITH ONLY A SINGLE JSON OBJECT: {\"topic\": \"...\"}.\n"
        . "The topic is a short title of 2-8 words taken from what the material actually teaches (e.g. 'Pros and Cons – Seeing Both Sides', 'Fact vs Opinion'), "
        . "in the same language as the material. Never add a topic the material does not cover. No grade level, no subject name, no quotes.";
    $input = ($context !== '' ? $context . "\n" : '') . (isset($aiInput['text']) ? "LESSON MATERIAL TEXT:\n" . mb_substr($aiInput['text'], 0, 8000) : 'The lesson material is attached.');
    $api = callGeminiAPI($system, $input, ['type' => 'text', 'mime_type' => 'application/json', 'schema' => $schema], 45, $aiInput['file_part'] ?? null);
    if (empty($api['success'])) {
        return ['ok' => false, 'error' => 'The AI could not read the topic right now. Please type it instead.'];
    }
    $raw = extractGeminiRawText($api['response'] ?? null);
    $raw = trim(preg_replace('/\s*```$/', '', preg_replace('/^```(?:json)?\s*/i', '', trim((string) $raw))));
    $decoded = json_decode($raw, true);
    $topic = trim(preg_replace('/\s+/', ' ', (string) ($decoded['topic'] ?? '')), " \t\"'.");
    if ($topic === '') {
        return ['ok' => false, 'error' => 'No topic could be detected. Please type it instead.'];
    }
    return ['ok' => true, 'topic' => mb_substr($topic, 0, 150)];
}

// ============================================================
// TEACHER AI DRAFT <-> ASSESSMENT ITEM
// ============================================================
// A draft generated from "Send to <item> AI" remembers its item
// (teacher_ai_resources.assessment_item_id, additive column). Sending it to
// the class then records scores on THAT item -- never a new, duplicate one.

function aim_ensureDraftLinkColumn()
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (schemaIsReady('aim_draft_link_v1')) {
        return $ready = true;
    }
    $table = getRecord("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_ai_resources'");
    if (!$table || (int) $table['n'] === 0) {
        return $ready = false;
    }
    $col = getRecord("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_ai_resources' AND COLUMN_NAME = 'assessment_item_id'");
    if (!$col || (int) $col['n'] === 0) {
        executeQuery("ALTER TABLE teacher_ai_resources ADD COLUMN assessment_item_id INT(11) DEFAULT NULL COMMENT 'assessment_items.item_id this draft was generated for (Send to <item> AI).'");
        $col = getRecord("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_ai_resources' AND COLUMN_NAME = 'assessment_item_id'");
    }
    $__schema_ok = ($ready = ($col && (int) $col['n'] > 0));
    if ($__schema_ok) {
        schemaMarkReady('aim_draft_link_v1');
    }
    return $__schema_ok;
}

function aim_linkDraftToItem($resource_id, $item_id)
{
    if ($resource_id && $item_id && aim_ensureDraftLinkColumn()) {
        executeQuery("UPDATE teacher_ai_resources SET assessment_item_id = ? WHERE resource_id = ?", [(int) $item_id, (int) $resource_id]);
    }
}

/** The item a Teacher AI resource (or its parent draft) was generated for, or 0. */
function aim_draftItemId(array $res)
{
    if (!aim_ensureDraftLinkColumn()) {
        return 0;
    }
    if (!empty($res['assessment_item_id'])) {
        return (int) $res['assessment_item_id'];
    }
    if (!empty($res['parent_id'])) {
        $p = getRecord("SELECT assessment_item_id FROM teacher_ai_resources WHERE resource_id = ?", [(int) $res['parent_id']]);
        return (int) ($p['assessment_item_id'] ?? 0);
    }
    return 0;
}

/** Card/draft payload for the browser: which item and class a resource is locked to. */
function aim_draftAssessmentPayload(array $res, $teacher_id)
{
    $itemId = aim_draftItemId($res);
    $ctx = $itemId ? aim_itemContext($itemId, $teacher_id) : null;
    if (!$ctx) {
        return null;
    }
    return [
        'item_id'        => (int) $ctx['item']['item_id'],
        'item_name'      => $ctx['item']['item_name'],
        'section_id'     => (int) $ctx['item']['section_id'],
        'subject_code'   => $ctx['item']['subject_code'],
        'grade_level'    => $ctx['item']['grade_level'],
        'term'           => $ctx['item']['term'],
        'category'       => $ctx['item']['category'],
        'category_label' => $ctx['category_label'],
        'hps'            => $ctx['hps'],
        'records_score'  => $ctx['item']['category'] === 'WW',
        'label'          => $ctx['item']['item_name'] . ' – ' . $ctx['grade_section'] . ' ' . $ctx['item']['subject_name'],
    ];
}

/**
 * Portal guard: an online assessment linked to an item with a task can only
 * be answered inside its Start -> Deadline window. null = allowed.
 */
function aim_taskWindowError($item_id)
{
    if (!$item_id) {
        return null;
    }
    $task = aim_getTaskForItem($item_id);
    if (!$task) {
        return null;
    }
    $now = aim_now();
    if ($now < $task['start_at']) {
        return 'This assessment opens on ' . aim_formatDateTime($task['start_at']) . '.';
    }
    if ($now > $task['end_at']) {
        return 'The deadline for this assessment (' . aim_formatDateTime($task['end_at']) . ') has passed, so it can no longer be answered.';
    }
    return null;
}

// ============================================================
// STUDENT MONITORING -- where each score came from
// ============================================================

/**
 * "item_id|student_id" => true for every score that came from an online,
 * auto-graded submission (portal quiz / Google Form linked to the item).
 */
function aim_onlineScoreKeys($teacher_id, $sy_id)
{
    $rows = getRecords(
        "SELECT DISTINCT r.link_item_id, s.student_id
         FROM ai_generated_resources r
         INNER JOIN ai_resource_submissions s ON s.resource_id = r.resource_id AND s.student_id = r.student_id AND s.is_auto_graded = 1
         WHERE r.teacher_id = ? AND r.school_year_id = ? AND r.link_item_id IS NOT NULL",
        [(int) $teacher_id, (int) $sy_id]
    );
    $keys = [];
    foreach ($rows ?: [] as $r) {
        $keys[(int) $r['link_item_id'] . '|' . (int) $r['student_id']] = true;
    }
    return $keys;
}

/** Manual Written Work / Manual Oral Work / Manual Performance Task / Manual Examination. */
function aim_manualSourceLabel(array $item)
{
    $kind = aim_assessmentKind($item);
    if ($kind === 'exam') return 'Manual Examination';
    if ($kind === 'performance_task') return 'Manual Performance Task';
    if ($kind === 'oral') return 'Manual Oral Work';
    return 'Manual Written Work';
}
