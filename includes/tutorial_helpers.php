<?php
// includes/tutorial_helpers.php
//
// Tutorial recommendations are driven ONLY by valid, completed Term 1-3
// grades. gh_validTermSql() (includes/grading_helpers.php) is the single
// definition of what counts as a usable grade record.
require_once __DIR__ . '/grading_helpers.php';
// AI-based Tutorial Plan generation for at-risk students.
// Reuses the same "weak subject" definition (< 75) already used by
// modules/ai/index.php and modules/ai/chatbot.php, so a plan's contents
// always match what the AI Analytics page is already flagging.

// ============================================================
// AUTOMATIC VIDEO WATCH TRACKING
// ============================================================
// tutorial_plan_items.status ('Pending'|'In Progress'|'Completed') is the
// SAME column already used elsewhere in this module — for a video it is
// driven entirely by real YouTube playback progress instead of page opens
// or a manual "mark watched" click:
//   Pending     -> displayed as "Not Started"
//   In Progress -> displayed as "In Progress"
//   Completed   -> displayed as "Completed" (>= completion threshold)
// tutorial_plan_items.video_watched_at is reused as the "finished watching"
// timestamp (it already existed for this purpose, just previously set by
// a manual button click instead of verified playback). These labels are
// now general enough to also describe a PDF/Word/PowerPoint Learning
// Material's status — see the LEARNING MATERIALS section below.

/** Percentage of actual watched playback time required to count a video as finished. */
define('TUTORIAL_COMPLETION_THRESHOLD', 90);

/**
 * Extract a specific, embeddable YouTube video ID from a URL.
 * Returns null for anything that isn't a single specific video — most
 * importantly, YouTube *search-results* links (youtube.com/results?...),
 * which cannot be embedded or tracked per-video.
 *
 * @param string|null $url
 * @return string|null 11-character YouTube video ID, or null if not embeddable.
 */
function getYouTubeVideoId($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '');
    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);

    if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)) {
        if (preg_match('~^/(?:embed|shorts)/([a-zA-Z0-9_-]{11})(?:/|$)~', $path, $m)) {
            return $m[1];
        }
        if (preg_match('~^/watch$~', $path) && !empty($query['v']) && preg_match('/^[a-zA-Z0-9_-]{11}$/', $query['v'])) {
            return $query['v'];
        }
    }
    if (in_array($host, ['youtu.be', 'www.youtu.be'], true) && preg_match('~^/([a-zA-Z0-9_-]{11})(?:/|$)~', $path, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Human-readable label for a tutorial_plan_items.status value.
 * @param string $status 'Pending'|'In Progress'|'Completed'
 * @return string
 */
function getTutorialWatchStatusLabel($status) {
    switch ($status) {
        case 'Completed':   return 'Completed';
        case 'In Progress': return 'In Progress';
        case 'Pending':
        default:            return 'Not Started';
    }
}

/**
 * Bootstrap badge color class for a tutorial_plan_items.status value.
 * @param string $status
 * @return string e.g. 'success', 'warning text-dark', 'secondary'
 */
function getTutorialWatchStatusBadgeClass($status) {
    switch ($status) {
        case 'Completed':   return 'success';
        case 'In Progress': return 'warning text-dark';
        case 'Pending':
        default:            return 'secondary';
    }
}

// ============================================================
// LEARNING MATERIALS (video / PDF / Word / PowerPoint)
// ============================================================
// A Tutorial Plan item's resource_type is one of:
//   'link'        - a video URL in resource_url (the original kind --
//                    every pre-existing row is this; still called "Video"
//                    in the UI and still fully tracked by the YouTube
//                    IFrame Player integration)
//   'pdf'         - an uploaded PDF file in resource_file
//   'word'        - an uploaded Word (.doc/.docx) file in resource_file
//   'powerpoint'  - an uploaded PowerPoint (.ppt/.pptx) file in resource_file
// resource_file always holds a random, unguessable STORED filename under
// TUTORIAL_MATERIAL_UPLOAD_DIR -- never the filename a teacher uploaded.
// resource_file_original_name holds that original name for DISPLAY only.

/** Directory (relative to the project root) where uploaded Learning Material files are stored. */
define('TUTORIAL_MATERIAL_UPLOAD_DIR', 'static/uploads/tutorial_resources');
/** Maximum allowed size, in bytes, for an uploaded Learning Material file. */
define('TUTORIAL_MATERIAL_MAX_BYTES', 15 * 1024 * 1024); // 15 MB

/**
 * Static per-type metadata: label, icon, badge styling, allowed file
 * extensions, and the MIME types considered valid for that type. Colors
 * are deliberately muted "subtle" Bootstrap variants (not overly bright),
 * consistent with the rest of the E-GradePro UI:
 *   PDF -> red, Word -> blue, PowerPoint -> orange, Video -> red/play.
 */
function tutorialMaterialTypes()
{
    static $types = null;
    if ($types !== null) {
        return $types;
    }
    $types = [
        'link' => [
            'label'       => 'Video',
            'icon'        => 'fa-circle-play',
            'badge_class' => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
            'extensions'  => [],
            'mimes'       => [],
        ],
        'pdf' => [
            'label'       => 'PDF',
            'icon'        => 'fa-file-pdf',
            'badge_class' => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
            'extensions'  => ['pdf'],
            'mimes'       => ['application/pdf'],
        ],
        'word' => [
            'label'       => 'Word',
            'icon'        => 'fa-file-word',
            'badge_class' => 'bg-primary-subtle text-primary-emphasis border border-primary-subtle',
            'extensions'  => ['doc', 'docx'],
            'mimes'       => [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                // Some servers only sniff as far as a generic container MIME;
                // docx/doc are cross-checked further in tutorialValidateUploadedMaterial().
                'application/zip',
                'application/x-zip-compressed',
                'application/octet-stream',
                'application/vnd.ms-office',
                'application/cdfv2',
                'application/x-cfb',
            ],
        ],
        'powerpoint' => [
            'label'       => 'PowerPoint',
            'icon'        => 'fa-file-powerpoint',
            'badge_class' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
            'extensions'  => ['ppt', 'pptx'],
            'mimes'       => [
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/zip',
                'application/x-zip-compressed',
                'application/octet-stream',
                'application/vnd.ms-office',
                'application/cdfv2',
                'application/x-cfb',
            ],
        ],
    ];
    return $types;
}

/**
 * Metadata for a single resource_type value. Always returns a usable array
 * (falls back to 'link' for any unrecognized value) so display code never
 * has to null-check this.
 */
function tutorialMaterialMeta($type)
{
    $types = tutorialMaterialTypes();
    return $types[$type] ?? $types['link'];
}

/**
 * Map a file extension to the resource_type it belongs to, for use once an
 * upload's extension has already been validated.
 * @return string|null 'pdf'|'word'|'powerpoint', or null if unrecognized.
 */
function tutorialMaterialTypeForExtension($ext)
{
    $ext = strtolower(ltrim((string) $ext, '.'));
    foreach (tutorialMaterialTypes() as $type => $meta) {
        if ($type !== 'link' && in_array($ext, $meta['extensions'], true)) {
            return $type;
        }
    }
    return null;
}

/**
 * Absolute filesystem path to the Learning Material upload directory,
 * creating it if it doesn't exist yet. Script execution anywhere under
 * static/uploads/ is already blocked site-wide by its existing .htaccess,
 * so no new .htaccess is needed here.
 */
function tutorialMaterialStorageDir()
{
    $dir = rtrim(dirname(__DIR__), '/\\') . '/' . TUTORIAL_MATERIAL_UPLOAD_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Generate a random, unguessable stored filename for an uploaded Learning
 * Material. Never derived from the original filename (so it can't be used
 * for path traversal and doesn't leak the original name to anyone who
 * guesses a URL) -- only its extension is carried over.
 *
 * @param string $originalName Original uploaded filename (used only for its extension).
 * @return string e.g. "3f9a1c...b2.pdf"
 */
function tutorialGenerateStoredFilename($originalName)
{
    $ext = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    $random = bin2hex(random_bytes(16));
    return $ext !== '' ? ($random . '.' . $ext) : $random;
}

/**
 * Strip any path information from a filename before it's ever displayed or
 * used to build a download header -- defense in depth on top of
 * resource_file_original_name never being used as an actual filesystem path.
 */
function tutorialSafeDisplayFilename($name)
{
    $name = basename(str_replace('\\', '/', (string) $name));
    return $name !== '' ? $name : 'file';
}

/**
 * Validate an uploaded Learning Material file against a declared target
 * type ('pdf'|'word'|'powerpoint'). Never relies on the file extension
 * alone: checks the PHP upload error code, enforces the size limit, checks
 * the extension against the type's allowed list, inspects the real
 * server-detected MIME type (finfo), verifies a binary signature (PDF
 * header / OLE2 header for legacy .doc/.ppt), and for modern OOXML
 * (.docx/.pptx) additionally confirms the ZIP container holds the marker
 * file only a genuine Word/PowerPoint package contains -- so a renamed
 * script or executable can't pass just by matching an extension or a
 * generic "application/zip" MIME.
 *
 * @param array  $file       One entry from $_FILES (caller must isset-check first).
 * @param string $targetType 'pdf'|'word'|'powerpoint'
 * @return array ['ok' => bool, 'mime' => string|null, 'error' => string|null]
 */
function tutorialValidateUploadedMaterial(array $file, $targetType)
{
    $friendly = 'This file type is not supported. Please upload a PDF, Word, or PowerPoint file.';

    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            $mb = round(TUTORIAL_MATERIAL_MAX_BYTES / (1024 * 1024));
            return ['ok' => false, 'mime' => null, 'error' => "That file is too large. The maximum size is {$mb}MB."];
        }
        if ($code === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'mime' => null, 'error' => 'Please choose a file to upload.'];
        }
        return ['ok' => false, 'mime' => null, 'error' => 'The file could not be uploaded. Please try again.'];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'mime' => null, 'error' => 'The file could not be uploaded. Please try again.'];
    }

    if ((int) $file['size'] <= 0) {
        return ['ok' => false, 'mime' => null, 'error' => 'That file appears to be empty.'];
    }
    if ((int) $file['size'] > TUTORIAL_MATERIAL_MAX_BYTES) {
        $mb = round(TUTORIAL_MATERIAL_MAX_BYTES / (1024 * 1024));
        return ['ok' => false, 'mime' => null, 'error' => "That file is too large. The maximum size is {$mb}MB."];
    }

    $meta = tutorialMaterialMeta($targetType);
    if (empty($meta['extensions'])) {
        return ['ok' => false, 'mime' => null, 'error' => 'Unsupported material type.'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $meta['extensions'], true)) {
        return ['ok' => false, 'mime' => null, 'error' => $friendly];
    }

    // Real, server-detected MIME type -- never trust $file['type'] (browser-supplied, spoofable).
    $detectedMime = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detectedMime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
    }
    if (!$detectedMime && function_exists('mime_content_type')) {
        $detectedMime = mime_content_type($file['tmp_name']);
    }
    if (!$detectedMime) {
        return ['ok' => false, 'mime' => null, 'error' => 'The file type could not be verified. Please try a different file.'];
    }
    $detectedMime = strtolower(trim($detectedMime));

    if (!in_array($detectedMime, $meta['mimes'], true)) {
        return ['ok' => false, 'mime' => null, 'error' => $friendly];
    }

    if ($targetType === 'pdf') {
        $head = @file_get_contents($file['tmp_name'], false, null, 0, 5);
        if ($head !== '%PDF-') {
            return ['ok' => false, 'mime' => null, 'error' => $friendly];
        }
    }

    // Modern OOXML (.docx/.pptx) are ZIP packages -- always confirm the
    // internal marker file that only a genuine Word/PowerPoint document
    // contains, regardless of what MIME the sniffer reported.
    if ($ext === 'docx' || $ext === 'pptx') {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'mime' => null, 'error' => 'The file type could not be verified. Please try a different file.'];
        }
        $marker = $ext === 'docx' ? 'word/document.xml' : 'ppt/presentation.xml';
        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            return ['ok' => false, 'mime' => null, 'error' => $friendly];
        }
        $hasMarker = $zip->locateName($marker) !== false;
        $zip->close();
        if (!$hasMarker) {
            return ['ok' => false, 'mime' => null, 'error' => $friendly];
        }
    }

    // Legacy .doc/.ppt use the shared OLE2/CFBF container signature --
    // pure MIME sniffing can't fully disambiguate legacy Office subtypes,
    // but this still reliably blocks a renamed script or executable.
    if ($ext === 'doc' || $ext === 'ppt') {
        $head = @file_get_contents($file['tmp_name'], false, null, 0, 8);
        $ole2Signature = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
        if ($head !== $ole2Signature) {
            return ['ok' => false, 'mime' => null, 'error' => $friendly];
        }
    }

    return ['ok' => true, 'mime' => $detectedMime, 'error' => null];
}

/**
 * The canonical MIME type to send in a Content-Type header when serving an
 * uploaded Learning Material -- based on its stored extension, not the
 * (possibly generic, e.g. "application/zip") MIME that was detected at
 * upload time, so the browser always gets the correct, specific type.
 */
function tutorialMaterialCanonicalMime($resourceType, $storedFilename)
{
    $ext = strtolower(pathinfo((string) $storedFilename, PATHINFO_EXTENSION));
    $map = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

/**
 * Whether a Tutorial Plan item currently has any Learning Material assigned
 * -- either a video link or an uploaded file.
 */
function tutorialMaterialHasResource(array $item)
{
    return !empty($item['resource_url']) || !empty($item['resource_file']);
}

/**
 * The display title for a Tutorial Plan item's Learning Material: the
 * teacher-entered resource_title if set, else a sensible fallback (the
 * original uploaded filename, or "Tutorial Video" for a link).
 */
function tutorialMaterialDisplayTitle(array $item)
{
    if (!empty($item['resource_title'])) {
        return $item['resource_title'];
    }
    $type = $item['resource_type'] ?? 'link';
    if ($type === 'link') {
        return !empty($item['resource_url']) ? 'Tutorial Video' : '';
    }
    if (!empty($item['resource_file_original_name'])) {
        return tutorialSafeDisplayFilename($item['resource_file_original_name']);
    }
    return '';
}

/**
 * Fields to reset on a tutorial_plan_items row whenever its Learning
 * Material is newly assigned or replaced/removed -- progress recorded
 * against the PREVIOUS material no longer means anything against the new
 * one, so it starts fresh at "Not Started" rather than carrying over a
 * misleading percentage or timestamp. Returns [sqlFragment, params] for use
 * in an UPDATE ... SET clause built by the caller.
 */
function tutorialMaterialProgressResetSql()
{
    return "status = 'Pending', student_status = 'Not Started', watch_progress_percentage = 0,
            last_video_position_seconds = 0, watched_seconds = 0, video_duration_seconds = NULL,
            last_watched_at = NULL, video_watched_at = NULL, student_viewed_at = NULL, student_updated_at = NULL";
}

/**
 * Compute watch progress percentage from accumulated actual playback
 * seconds vs. the video's total duration.
 *
 * Watch Progress % = accumulated actual playback time / total video duration × 100
 *
 * @param float $watched_seconds  Actual accumulated playback time (never buffered/skip time).
 * @param float $duration_seconds Total video duration, from the YouTube player.
 * @return float Rounded to 2 decimals, clamped to [0, 100].
 */
function calculateWatchProgressPercent($watched_seconds, $duration_seconds) {
    if (empty($duration_seconds) || $duration_seconds <= 0) {
        return 0.0;
    }
    $pct = (max(0, floatval($watched_seconds)) / floatval($duration_seconds)) * 100;
    return round(max(0, min(100, $pct)), 2);
}

/**
 * Map a watch progress percentage to the automatic status value, never
 * allowing a downgrade from a status the item has already reached
 * (progress is stored as a running max, so this is mostly a formality,
 * but it keeps the rule explicit and safe against any future caller).
 *
 * @param float $progress_percent
 * @param string|null $current_status Existing status, if any.
 * @return string 'Pending'|'In Progress'|'Completed'
 */
function determineTutorialWatchStatus($progress_percent, $current_status = null) {
    if ($current_status === 'Completed') {
        return 'Completed';
    }
    if ($progress_percent >= TUTORIAL_COMPLETION_THRESHOLD) {
        return 'Completed';
    }
    if ($progress_percent > 0) {
        return 'In Progress';
    }
    return 'Pending';
}

/**
 * Overall progress for a Tutorial Plan, from its items' individual
 * progress — never a fabricated/AI-guessed number.
 *
 * Each item's individual progress is:
 *   - its own watch_progress_percentage, for items with real playback tracking
 *   - 100 if status is Completed but has no percentage on file (e.g. an
 *     older item, or a non-trackable resource a teacher marked done another way)
 *   - 0 otherwise (Pending / not trackable / not started)
 *
 * Plan progress % = average of each item's individual progress percentage.
 *
 * @param array $items Rows from tutorial_plan_items (must include 'status' and 'watch_progress_percentage').
 * @return float Rounded to 2 decimals, 0 if the plan has no items.
 */
function calculatePlanProgressPercent($items) {
    if (empty($items)) {
        return 0.0;
    }
    $total = 0.0;
    foreach ($items as $item) {
        $pct = floatval($item['watch_progress_percentage'] ?? 0);
        if ($pct <= 0 && ($item['status'] ?? '') === 'Completed') {
            $pct = 100.0;
        }
        $total += $pct;
    }
    return round($total / count($items), 2);
}

/**
 * Curated YouTube video links per subject — real, embeddable videos that the
 * YouTube IFrame Player API can track (unlike search-result pages).
 * Teachers can still replace these with a specific video from the Tutorial
 * Plan view page.
 */
function tutorialSubjectVideoLink($subject_name, $grade_level = '')
{
    $videos = [
        'math'      => 'SWFmt93w7FU',
        'science'   => 'zUuf6ryzy5c',
        'english'   => 'yOva1Uj-iqU',
        'filipino'  => 'l7_Dqe-TQPU',
        'araling panlipunan' => 'TTrPaOBCAN4',
        'mapeh'     => '-wSXUuUJ290',
        'tle'       => 'tgZFBhGpuFM',
        'values'    => 'b__3dKWpwpM',
    ];

    $s = strtolower(trim($subject_name));
    foreach ($videos as $key => $video_id) {
        if (strpos($s, $key) !== false) {
            return 'https://www.youtube.com/watch?v=' . $video_id;
        }
    }

    // Fallback — use the first video as a generic tutorial
    return 'https://www.youtube.com/watch?v=SWFmt93w7FU';
}

/**
 * Per-subject focus tip. Mirrors the tips used in modules/ai/chatbot.php
 * so the same subject always gets the same kind of guidance whether the
 * teacher is chatting with the AI Assistant or reading a Tutorial Plan.
 */
function tutorialSubjectTip($subject_name)
{
    $s = strtolower($subject_name);
    $tips = [
        'math'      => 'Practice a few problems daily, revisit the specific topics missed on the last exam, and try peer tutoring or short daily math drills.',
        'science'   => 'Review key concepts using diagrams and real-life examples, and use hands-on activities or simple experiments to reinforce lessons.',
        'english'   => 'Read short stories daily, keep a vocabulary journal, and practice writing short paragraphs with feedback.',
        'filipino'  => 'Magbasa ng maiikling kwento araw-araw at magsanay sa pagsulat ng maikling sanaysay o journal.',
        'araling panlipunan' => 'Use timelines and concept maps to connect events, and review with flashcards before quizzes.',
        'mapeh'     => 'Balance study time between physical practice and written concepts, and review performance-task rubrics beforehand.',
        'tle'       => 'Focus on hands-on, step-by-step practice and review procedures before each activity.',
        'values'    => 'Reflect on lessons through short journaling and class discussions.',
    ];
    foreach ($tips as $key => $tip) {
        if (strpos($s, $key) !== false) {
            return $tip;
        }
    }
    return 'Set aside focused review time after class, go over notes within 24 hours, and ask for extra practice materials.';
}

/**
 * Weak subjects (final_grade < pass_mark) for a student in a given school year,
 * with the subject's current teacher (if assigned) as the suggested tutor.
 */
function getWeakSubjectsForStudent($student_id, $sy_id)
{
    $pass_mark = getGradingPassMark();
    // Deliberately uses gh_encodedTermSql() (any finalized, non-Draft record)
    // rather than gh_validTermSql() here: a term marked 'Incomplete' is
    // exactly the kind of subject that needs intervention (missing
    // assessments) and must stay eligible for tutoring-plan sync and for
    // the Ask AI chat's subject picker -- otherwise a teacher could never
    // use Ask AI to generate a make-up quiz for a student whose term isn't
    // finished yet, which defeats the point.
    return getRecords("
        SELECT g.subject_code, sub.subject_name, g.final_grade, g.remarks, g.term,
               ts.teacher_id
        FROM grades g
        JOIN subjects sub ON g.subject_code = sub.subject_code
        LEFT JOIN teacher_subjects ts
            ON ts.subject_code = g.subject_code
           AND ts.section_id = g.section_id
           AND ts.school_year_id = g.school_year_id
        WHERE g.student_id = ? AND g.school_year_id = ?
          AND " . gh_encodedTermSql('g') . "
          AND (g.final_grade < ? OR g.remarks = 'Incomplete')
        GROUP BY g.subject_code
        ORDER BY g.final_grade ASC
    ", [$student_id, $sy_id, $pass_mark]);
}

/**
 * Subjects a student needs tutoring in -- the SAME list AI Analytics shows:
 *   - finalized terms below passing / Incomplete (getWeakSubjectsForStudent), plus
 *   - every subject-term getAtRiskSubjectRecords() flags, INCLUDING the ongoing
 *     term (2+ missing assessments, repeated low scores, current grade below passing).
 * One row per subject. $teacher_id limits it to the subjects that teacher handles.
 * @return array<int,array{subject_code:string, subject_name:string, final_grade:?float,
 *                          remarks:?string, term:?string, teacher_id:?int, reason:string}>
 */
function tutorialAtRiskSubjects($student_id, $sy_id, $teacher_id = null)
{
    $out = [];
    foreach (getWeakSubjectsForStudent($student_id, $sy_id) as $w) {
        $out[$w['subject_code']] = [
            'subject_code' => $w['subject_code'],
            'subject_name' => $w['subject_name'],
            'final_grade' => $w['final_grade'] !== null ? (float) $w['final_grade'] : null,
            'remarks' => $w['remarks'],
            'term' => $w['term'],
            'teacher_id' => $w['teacher_id'] !== null ? (int) $w['teacher_id'] : null,
            'reason' => ($w['remarks'] === 'Incomplete' ? 'Incomplete term' : 'Term Grade below passing') . ' (' . $w['term'] . ')',
        ];
    }
    if (function_exists('getAtRiskSubjectRecords')) {
        foreach (getAtRiskSubjectRecords($student_id, $sy_id) as $r) {
            $code = $r['subject_code'];
            $has_grade = empty($r['in_progress']) || (float) $r['final_grade'] > 0;
            $reason = trim(implode(' · ', array_column($r['risk_reasons'] ?? [], 'label')));
            if (!isset($out[$code])) {
                $out[$code] = [
                    'subject_code' => $code,
                    'subject_name' => $r['subject_name'],
                    'final_grade' => $has_grade ? (float) $r['final_grade'] : null,
                    'remarks' => $r['remarks'] ?? null,
                    'term' => $r['term'],
                    'teacher_id' => $r['teacher_id'] !== null ? (int) $r['teacher_id'] : null,
                    'reason' => ($reason !== '' ? $reason : 'At risk') . ' (' . $r['term'] . ')',
                ];
            } elseif ($reason !== '' && strpos($out[$code]['reason'], $reason) === false) {
                $out[$code]['reason'] .= '; ' . $reason . ' (' . $r['term'] . ')';
            }
            if (!empty($r['missing_assessments'])) {
                $out[$code]['missing'] = array_values(array_unique(array_merge($out[$code]['missing'] ?? [], $r['missing_assessments'])));
            }
            if (!empty($r['weak_topics'])) {
                $out[$code]['weak_topics'] = array_values(array_unique(array_merge($out[$code]['weak_topics'] ?? [], $r['weak_topics'])));
            }
        }
    }
    if ($teacher_id !== null) {
        $out = array_filter($out, function ($r) use ($teacher_id) { return (int) $r['teacher_id'] === (int) $teacher_id; });
    }
    return array_values($out);
}

/** Recommended action for a plan item: the subject tip + why the student is at risk. */
function tutorialRecommendedAction(array $subj)
{
    $txt = tutorialSubjectTip($subj['subject_name']);
    if (!empty($subj['reason'])) $txt .= "\nWhy: " . $subj['reason'] . '.';
    if (!empty($subj['missing'])) $txt .= "\nMissing: " . implode(', ', $subj['missing']) . '.';
    if (!empty($subj['weak_topics'])) $txt .= "\nFocus topics: " . implode(', ', $subj['weak_topics']) . '.';
    return $txt;
}

/**
 * Add any currently-weak subjects that aren't already on the plan yet.
 * Safe to call repeatedly (e.g. every time the plan is viewed) — subjects
 * already on the plan are left untouched so teacher/student progress on
 * them isn't overwritten. Returns the number of newly added items.
 */
function syncTutorialPlanItems($plan_id, $student_id, $sy_id)
{
    $weak_subjects = tutorialAtRiskSubjects($student_id, $sy_id);
    if (empty($weak_subjects)) {
        return 0;
    }

    $existing_codes = getRecords(
        "SELECT subject_code FROM tutorial_plan_items WHERE plan_id = ?",
        [$plan_id]
    );
    $existing_codes = array_column($existing_codes, 'subject_code');

    $student = getRecord("SELECT grade_level FROM students WHERE student_id = ?", [$student_id]);

    $added = 0;
    foreach ($weak_subjects as $subj) {
        if (in_array($subj['subject_code'], $existing_codes, true)) {
            continue;
        }
        executeQuery("
            INSERT INTO tutorial_plan_items (plan_id, subject_code, current_grade, target_grade, tutor_id, recommended_action, resource_url, status)
            VALUES (?, ?, ?, 75.00, ?, ?, NULL, 'Pending')
        ", [
            $plan_id,
            $subj['subject_code'],
            $subj['final_grade'],
            $subj['teacher_id'] ?: null,
            tutorialRecommendedAction($subj),
        ]);
        $added++;
    }

    return $added;
}

/**
 * Create (or reuse) a Tutorial Plan for a student, auto-populated from
 * their weak subjects. Returns ['plan_id' => int, 'created' => bool] or
 * false if the student has no weak subjects to build a plan from.
 */
function generateTutorialPlan($student_id, $sy_id, $created_by, $risk_level = 'High')
{
    $weak_subjects = tutorialAtRiskSubjects($student_id, $sy_id);
    if (empty($weak_subjects)) {
        return false;
    }

    $student = getRecord("SELECT grade_level FROM students WHERE student_id = ?", [$student_id]);

    // Reuse an existing Draft/Active plan for this student + school year if
    // one already exists, but sync it against the *current* list of weak
    // subjects first — a plan created earlier may have been generated when
    // fewer subjects were below 75, and new ones may have dropped below
    // the threshold since then.
    $existing = getRecord(
        "SELECT plan_id FROM tutorial_plans WHERE student_id = ? AND school_year_id = ? AND status IN ('Draft','Active') LIMIT 1",
        [$student_id, $sy_id]
    );

    if ($existing) {
        syncTutorialPlanItems($existing['plan_id'], $student_id, $sy_id);
        return ['plan_id' => $existing['plan_id'], 'created' => false];
    }

    executeQuery("
        INSERT INTO tutorial_plans (student_id, school_year_id, risk_level, target_average, status, created_by)
        VALUES (?, ?, ?, 75.00, 'Active', ?)
    ", [$student_id, $sy_id, $risk_level, $created_by]);

    $plan = getRecord("SELECT plan_id FROM tutorial_plans WHERE student_id = ? AND school_year_id = ? ORDER BY plan_id DESC LIMIT 1", [$student_id, $sy_id]);
    $plan_id = $plan['plan_id'];

    foreach ($weak_subjects as $subj) {
        executeQuery("
            INSERT INTO tutorial_plan_items (plan_id, subject_code, current_grade, target_grade, tutor_id, recommended_action, resource_url, status)
            VALUES (?, ?, ?, 75.00, ?, ?, NULL, 'Pending')
        ", [
            $plan_id,
            $subj['subject_code'],
            $subj['final_grade'],
            $subj['teacher_id'] ?: null,
            tutorialRecommendedAction($subj),
        ]);
    }

    return ['plan_id' => $plan_id, 'created' => true];
}