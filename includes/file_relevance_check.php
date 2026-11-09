<?php
// includes/file_relevance_check.php
//
// WARNING-ONLY check for teacher uploads (Classroom classwork / Stream posts, Learning
// Materials): does the chosen file look like it belongs to the subject? It never blocks an
// upload -- when the AI is busy, or the file cannot be read (video, zip, old .doc/.ppt), the
// answer is 'unknown'.
//
// The decision is based ONLY on the real body of the file. The header that this system writes
// into its own exported documents (school name, "Subject: ...", teacher, date) is removed first,
// and files with no real content (empty, random letters) are reported without asking the AI.

require_once __DIR__ . '/assessment_material_helpers.php';   // aim_readFileForAi()

/**
 * Remove the header block of a document exported by this system (school name, system name,
 * title, type label and the Student / Grade Level / Subject / Teacher / Date table), so the
 * subject name printed there is not mistaken for the file's content. Text that is not one of
 * our own exports is returned unchanged.
 */
function frcStripExportedHeader($text)
{
    $text = (string) $text;
    if (stripos($text, 'EGRADEPRO SCHOOL MANAGEMENT SYSTEM') === false) {
        return $text;
    }
    $lines = [];
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    $marker = -1;
    foreach ($lines as $i => $line) {
        if ($i > 40) {
            break;
        }
        if (stripos($line, 'EGRADEPRO SCHOOL MANAGEMENT SYSTEM') !== false) {
            $marker = $i;
            break;
        }
    }
    if ($marker < 0) {
        return $text;
    }
    // school name (line before) + system name + title + type label ("Assignment", ...)
    $end = $marker + 2;
    $j = $end + 1;
    $count = count($lines);
    while ($j < $count && $j <= $end + 14) {
        if (preg_match('/^(Student|Grade Level|Subject|Teacher|Date)\s*:\s*(.*)$/iu', $lines[$j], $m)) {
            // the value sits on the next line when the label stands alone in its table cell
            $j += (trim($m[2]) === '') ? 2 : 1;
            $end = $j - 1;
            continue;
        }
        break;
    }
    return implode("\n", array_slice($lines, $end + 1));
}

/**
 * True when the body has no real content: empty, a few characters, or random letters
 * (keyboard mashing such as "hfdfdkjffd").
 */
function frcBodyIsMeaningless($body)
{
    $body = trim((string) $body);
    if ($body === '') {
        return true;
    }
    if (preg_match_all('/\d/', $body) >= 8) {
        return false;   // numbers / equations present (e.g. a math quiz) -- let the AI decide
    }
    preg_match_all('/\p{L}{2,}/u', $body, $m);
    $words = $m[0];
    if (count($words) < 6 && mb_strlen($body) < 60) {
        return true;
    }
    $long = array_values(array_filter($words, function ($w) { return mb_strlen($w) >= 3; }));
    if (count($long) < 3) {
        return false;   // mostly numbers / equations -- let the AI decide
    }
    $noVowel = 0;
    foreach ($long as $w) {
        if (!preg_match('/[aeiouyáéíóúàèìòùäëïöü]/iu', $w)) {
            $noVowel++;
        }
    }
    return ($noVowel / count($long)) >= 0.5;
}

/**
 * @return array{status:string, reason:string}  status: related | unrelated | unknown
 */
function frcCheckFile($tmpPath, $originalName, $subjectName, $context = '')
{
    // $originalName and $context are deliberately NOT sent to the AI as evidence: a file name
    // or post title says nothing about what is really inside the file.
    $subjectName = trim((string) $subjectName);
    if ($subjectName === '' || !is_file($tmpPath) || filesize($tmpPath) > 15 * 1024 * 1024) {
        return ['status' => 'unknown', 'reason' => 'Hindi nasuri ang file na ito.'];
    }
    $ext = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
    $in = null;
    if ($ext === 'pdf') {
        $in = aim_readFileForAi($tmpPath, 'pdf', $ext);
    } elseif ($ext === 'docx') {
        $in = aim_readFileForAi($tmpPath, 'word', $ext);
    } elseif ($ext === 'pptx') {
        $in = aim_readFileForAi($tmpPath, 'powerpoint', $ext);
    } elseif ($ext === 'txt') {
        $in = ['ok' => true, 'text' => mb_substr((string) file_get_contents($tmpPath), 0, 8000)];
    } elseif (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        $in = ['ok' => true, 'file_part' => ['type' => 'image', 'data' => base64_encode(file_get_contents($tmpPath)),
                'mime_type' => $ext === 'png' ? 'image/png' : 'image/jpeg']];
    }
    if (!$in || empty($in['ok'])) {
        return ['status' => 'unknown', 'reason' => 'Hindi mababasa ng AI ang ganitong uri ng file (o walang mabasang text).'];
    }

    // Text files: drop our own export header, then refuse to call a file "related" when nothing real is inside.
    if (isset($in['text'])) {
        $in['text'] = frcStripExportedHeader($in['text']);
        if (frcBodyIsMeaningless($in['text'])) {
            return ['status' => 'unrelated',
                    'reason' => 'Halos walang laman ang file (walang malinaw na aralin o gawain sa loob), kaya hindi ito masasabing tugma sa subject.'];
        }
    }

    require_once __DIR__ . '/gemini_service.php';
    $schema = [
        'type' => 'object',
        'properties' => [
            'body_summary' => ['type' => 'string'],
            'is_related' => ['type' => 'boolean'],
            'reason' => ['type' => 'string'],
        ],
        'required' => ['body_summary', 'is_related', 'reason'],
    ];
    $system = "You check whether a teacher's uploaded file belongs to a Philippine DepEd class SUBJECT.\n"
        . "RESPOND WITH ONLY A SINGLE JSON OBJECT: {\"body_summary\": \"...\", \"is_related\": true|false, \"reason\": \"...\"}.\n"
        . "Judge ONLY the real body content: the lesson, activity, questions, reading text or pictures. "
        . "IGNORE all header / metadata: the logo, school name, system name, the document title, and lines such as "
        . "Student, Grade Level, Subject, Teacher or Date. The subject name appearing in such a header is NOT evidence.\n"
        . "Step 1 - body_summary: ONE short English sentence saying what the body content is actually about. "
        . "If the body is empty, random letters or keyboard mashing, or only a placeholder with no lesson or activity, say exactly that.\n"
        . "Step 2 - is_related: true ONLY when the body content is about a topic that is taught in the SUBJECT, or is a clearly "
        . "usable general study resource for that class. false when the body is about a different subject, unrelated, personal or "
        . "random content, or has no meaningful content. Cross-subject material may be true, but empty or meaningless content is NEVER related.\n"
        . "reason: ONE short sentence in Taglish for the teacher saying what the file is about; when is_related is false, say why it does not match the subject.";
    $input = 'SUBJECT: ' . $subjectName . "\n"
        . (isset($in['text'])
            ? "FILE BODY TEXT (header already removed):\n" . mb_substr($in['text'], 0, 6000)
            : 'The file (image or PDF) is attached. Judge only its real content; ignore any header, logo, school name or Subject/Teacher/Date lines.');
    $api = callGeminiAPI($system, $input, ['type' => 'text', 'mime_type' => 'application/json', 'schema' => $schema], 40, $in['file_part'] ?? null);
    if (empty($api['success'])) {
        return ['status' => 'unknown', 'reason' => 'Hindi maabot ang AI ngayon. Subukan ulit mamaya.'];
    }
    $raw = extractGeminiRawText($api['response'] ?? null);
    $raw = trim(preg_replace('/\s*```$/', '', preg_replace('/^```(?:json)?\s*/i', '', trim((string) $raw))));
    $d = json_decode($raw, true);
    if (!is_array($d) || !array_key_exists('is_related', $d)) {
        return ['status' => 'unknown', 'reason' => 'Hindi malinaw ang sagot ng AI. Subukan ulit.'];
    }
    $related = !empty($d['is_related']);
    // Safety net: an "empty / random / placeholder" summary can never be a match.
    if ($related && preg_match('/\b(empty|blank|random letters?|gibberish|keyboard|placeholder|no (real|meaningful|actual) content|nonsense)\b/i', (string) ($d['body_summary'] ?? ''))) {
        $related = false;
    }
    return [
        'status' => $related ? 'related' : 'unrelated',
        'reason' => mb_substr(trim((string) ($d['reason'] ?? '')), 0, 240),
    ];
}
