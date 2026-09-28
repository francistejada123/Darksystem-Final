<?php
// includes/ai_document_generator.php
// Turns AI-generated (or teacher-edited) academic content into a REAL,
// physically-generated .pdf or .docx file, and stores it securely.
//
// This file never talks to Gemini and never decides WHAT to generate —
// includes/gemini_service.php's getGeminiResourceReply() produces the
// content (student_content / teacher_answer_key / title / resource_type),
// modules/ai/chatbot.php or modules/ai/generate_resource.php hand it here,
// and this file only ever:
//   1. Renders that already-produced text into a branded PDF (reusing
//      includes/pdf_helpers.php -> Dompdf, already a project dependency)
//      or a real DOCX (hand-rolled OOXML via ZipArchive -- PHPWord is not
//      installed and this session cannot run composer on the school's
//      server, so a minimal-but-real DOCX writer is used instead of
//      faking a file or adding a new dependency).
//   2. Writes the result to static/uploads/ai_resources/ under a random,
//      unguessable filename -- never the teacher's prompt, never the
//      display title -- mirroring the exact convention already used for
//      tutorial_plan_items.resource_file (see database/migrations/
//      2026_tutorial_pdf_resources.sql). That directory inherits the
//      shared static/uploads/.htaccess that blocks the server from ever
//      executing anything in it.
//   3. Returns file metadata for the caller to store in
//      ai_generated_resources — this file never touches the database
//      itself, and never trusts a path coming from the browser.
//
// SECURITY NOTE: nothing in this file (or its caller) ever serves a file
// by that stored filename directly. Every view/download goes through
// modules/ai/view_resource.php / download_resource.php, which check
// resource ownership by resource_id first (see those files).

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/pdf_helpers.php'; // renderBrandedPdfBytes(), pdfInfoBox(), pdfSectionTitle(), pdfListItems()

// ============================================================
// Supported resource types — the fixed vocabulary Gemini's structured
// response is constrained to (see gemini_service.php's response_format
// schema). This list is what lets natural-language requests ("PeTa",
// "gumawa ng reviewer", "20 multiple choice questions") work without any
// PHP keyword matching: the MODEL maps the teacher's own words onto one
// of these keys, and this file only uses the key for storage/display
// (heading text, icon, filename hint) — never to gate what the teacher
// is allowed to ask for.
// ============================================================
define('AI_RESOURCE_TYPES', [
    'quiz'                 => 'Quiz',
    'practice_quiz'        => 'Practice Quiz',
    'reviewer'              => 'Reviewer',
    'remedial_activity'    => 'Remedial Activity',
    'worksheet'            => 'Worksheet',
    'practice_exercise'    => 'Practice Exercise',
    'performance_task'     => 'Performance Task',
    'written_work'         => 'Written Work',
    'question_bank'        => 'Question Bank',
    'study_guide'          => 'Study Guide',
    'study_plan'           => 'Study Plan',
    'mini_lesson'          => 'Mini Lesson',
    'supportive_message'   => 'Supportive Message',
    'recovery_assessment'  => 'Recovery Assessment',
    'flashcards'           => 'Flashcards',
    'short_assessment'     => 'Short Assessment',
    'multiple_choice'      => 'Multiple Choice Questions',
    'true_false'           => 'True/False Questions',
    'identification'       => 'Identification Questions',
    'essay'                => 'Essay Questions',
    'reflection_activity'  => 'Reflection Activity',
    'reading_activity'     => 'Reading Activity',
    'intervention_activity' => 'Intervention Activity',
    'learning_activity'    => 'Learning Activity',
    'rubric'               => 'Rubric',
    'assignment'           => 'Assignment',
    'practice_test'        => 'Practice Test',
    'other'                => 'Learning Resource',
]);

function aiResourceTypeLabel($type) {
    return AI_RESOURCE_TYPES[$type] ?? AI_RESOURCE_TYPES['other'];
}

// ============================================================
// Storage
// ============================================================

function aiResourceStorageDir() {
    $dir = __DIR__ . '/../static/uploads/ai_resources/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

// Random, unguessable stored filename — never derived from the teacher's
// prompt or the display title. Only ever a supported generated format.
function aiGenerateStoredFilename($format) {
    $format = $format === 'docx' ? 'docx' : 'pdf';
    return bin2hex(random_bytes(16)) . '.' . $format;
}

// Safe, display-only filename shown in the file card / Content-Disposition
// header. Never used as a filesystem path (the stored_filename is).
function aiSafeDisplayFilename($title, $resourceType, $format) {
    $base = trim((string) $title) !== '' ? $title : aiResourceTypeLabel($resourceType);
    $base = strtolower($base);
    $base = preg_replace('/[^a-z0-9]+/', '_', $base);
    $base = trim($base, '_');
    if ($base === '') $base = 'resource';
    if (strlen($base) > 60) $base = substr($base, 0, 60);
    $format = $format === 'docx' ? 'docx' : 'pdf';
    return $base . '.' . $format;
}

// ============================================================
// Plain-text content -> structured blocks -> HTML/DOCX
//
// Gemini is instructed (see gemini_service.php) to write student_content
// / teacher_answer_key as plain text using a light, predictable
// convention instead of markdown or HTML, but in practice it still
// occasionally reaches for markdown habits (a "**Label**" heading, a
// "* item" bullet, a "**bold**" phrase). Rather than showing the raw
// "*"/"**" characters to the teacher or student, this layer recognizes
// those patterns and turns them into real formatting:
//   - blank line                          -> paragraph break
//   - "## Heading" / a WHOLE line wrapped
//     in "**...**"                        -> subheading (bold, own spacing)
//   - line starting "- " / "•" / "* "     -> bullet item
//   - line starting "1. " / "1) "         -> numbered item (kept verbatim,
//                                            it already carries its own
//                                            number — works for quiz items,
//                                            steps, rubric criteria, etc.)
//   - line starting "A. " / "b) " (single
//     letter a–e)                         -> multiple-choice option,
//                                            indented under its question
//   - anything else                        -> plain paragraph line
// Within every line, "**text**" pairs become real bold and any leftover
// stray "*" is simply dropped instead of being shown literally.
// This one convention covers every resource type in AI_RESOURCE_TYPES
// without needing a different rigid schema per type.
// ============================================================

function aiParseContentLines($text) {
    $text = str_replace("\r\n", "\n", (string) $text);
    $lines = explode("\n", trim($text));
    $blocks = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $blocks[] = ['type' => 'blank'];
        } elseif (preg_match('/^#{1,6}\s+(.*)$/u', $trimmed, $m)) {
            $blocks[] = ['type' => 'heading', 'text' => trim($m[1])];
        } elseif (preg_match('/^\*\*(.+)\*\*$/u', $trimmed, $m) && substr_count($trimmed, '**') === 2) {
            // A whole line wrapped in ** ** ("**Bahagi I: Panuto**") is the
            // model using bold as a makeshift section label — treat it as
            // a subheading instead of leaving the "**" visible.
            $blocks[] = ['type' => 'heading', 'text' => trim($m[1])];
        } elseif (preg_match('/^[-•*]\s+(.*)$/u', $trimmed, $m)) {
            $blocks[] = ['type' => 'bullet', 'text' => $m[1]];
        } elseif (preg_match('/^\d+[.)]\s+(.*)$/u', $trimmed, $m)) {
            $blocks[] = ['type' => 'numbered', 'text' => $trimmed];
        } elseif (preg_match('/^[a-eA-E][.)]\s+(.*)$/u', $trimmed, $m)) {
            $blocks[] = ['type' => 'choice', 'text' => $trimmed];
        } else {
            $blocks[] = ['type' => 'para', 'text' => $trimmed];
        }
    }
    return $blocks;
}

// Splits one line of text into [{text, bold}] runs on "**...**" pairs.
// Any "*" left over after that (unmatched single asterisks) is dropped —
// in this context it is always a stray markdown artifact, never content
// the teacher or student is meant to see.
function aiParseInlineRuns($text) {
    $text = (string) $text;
    $runs = [];
    $lastEnd = 0;
    if (preg_match_all('/\*\*(.+?)\*\*/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $i => $m) {
            $start = $m[1];
            if ($start > $lastEnd) {
                $runs[] = ['text' => substr($text, $lastEnd, $start - $lastEnd), 'bold' => false];
            }
            $runs[] = ['text' => $matches[1][$i][0], 'bold' => true];
            $lastEnd = $start + strlen($m[0]);
        }
    }
    if ($lastEnd < strlen($text)) {
        $runs[] = ['text' => substr($text, $lastEnd), 'bold' => false];
    }
    if (empty($runs)) {
        $runs[] = ['text' => $text, 'bold' => false];
    }
    foreach ($runs as &$r) {
        $r['text'] = str_replace('*', '', $r['text']);
    }
    unset($r);
    return $runs;
}

function aiInlineRunsToHtml($text) {
    $html = '';
    foreach (aiParseInlineRuns($text) as $r) {
        $safe = htmlspecialchars($r['text'], ENT_QUOTES);
        $html .= $r['bold'] ? '<strong>' . $safe . '</strong>' : $safe;
    }
    return $html;
}

function aiContentBlocksToHtml($text) {
    $blocks = aiParseContentLines($text);
    $html = '';
    $bulletBuffer = [];
    $flushBullets = function () use (&$bulletBuffer, &$html) {
        if (!empty($bulletBuffer)) {
            $html .= pdfListItems($bulletBuffer);
            $bulletBuffer = [];
        }
    };
    foreach ($blocks as $b) {
        if ($b['type'] === 'blank') {
            $flushBullets();
            continue;
        }
        if ($b['type'] === 'bullet') {
            $bulletBuffer[] = aiInlineRunsToHtml($b['text']);
            continue;
        }
        $flushBullets();
        $inner = aiInlineRunsToHtml($b['text']);
        if ($b['type'] === 'heading') {
            $html .= '<p style="margin:16px 0 6px 0; font-size:13px; font-weight:bold; color:#14213D;">' . $inner . '</p>';
        } elseif ($b['type'] === 'choice') {
            $html .= '<p style="margin:0 0 6px 0; padding-left:18px;">' . $inner . '</p>';
        } elseif ($b['type'] === 'numbered') {
            $html .= '<p style="margin:10px 0 6px 0;">' . $inner . '</p>';
        } else {
            $html .= '<p style="margin:0 0 8px 0;">' . $inner . '</p>';
        }
    }
    $flushBullets();
    return $html;
}

// ============================================================
// PDF generation
// ============================================================

/**
 * @param array $resource ['title','resource_type','student_content','teacher_answer_key']
 * @param array $context  ['student_name','grade_level','subject_name','teacher_name','date']
 * @param string $audience 'student' (default — content only) or 'teacher' (content + answer key)
 * @return string raw PDF bytes
 */
function aiRenderResourcePdf(array $resource, array $context, $audience = 'student') {
    $title = htmlspecialchars($resource['title'] ?: aiResourceTypeLabel($resource['resource_type']), ENT_QUOTES);
    $typeLabel = htmlspecialchars(aiResourceTypeLabel($resource['resource_type']), ENT_QUOTES);

    $infoRows = [
        'Student:' => htmlspecialchars($context['student_name'] ?? '', ENT_QUOTES),
        'Grade Level:' => htmlspecialchars($context['grade_level'] ?? '', ENT_QUOTES),
        'Subject:' => htmlspecialchars($context['subject_name'] ?? '', ENT_QUOTES),
        'Teacher:' => htmlspecialchars($context['teacher_name'] ?? '', ENT_QUOTES),
        'Date:' => htmlspecialchars($context['date'] ?? date('F j, Y'), ENT_QUOTES),
    ];
    // Teacher AI documents are written for a class picked later, so they have
    // no student/grade/subject yet -- drop those blank rows instead of printing
    // empty labels. Every existing caller leaves this flag unset (unchanged).
    if (!empty($context['omit_empty_rows'])) {
        $infoRows = array_filter($infoRows, function ($value) { return trim((string) $value) !== ''; });
    }

    $body = pdfInfoBox($infoRows);
    $body .= pdfSectionTitle($title);
    $body .= aiContentBlocksToHtml($resource['student_content']);

    if ($audience === 'teacher' && !empty($resource['teacher_answer_key'])) {
        $body .= '<div style="page-break-before: always;"></div>';
        $body .= pdfSectionTitle('Answer Key / Teacher Notes');
        $body .= '<div style="background:#FFF7E6;border:1px solid #F0DFAE;border-radius:8px;padding:10px 14px;margin-bottom:10px;font-size:11.5px;color:#8a6d1f;">For teacher use only — do not distribute to the student.</div>';
        $body .= aiContentBlocksToHtml($resource['teacher_answer_key']);
    }

    $subtitle = $typeLabel . ($audience === 'teacher' ? ' — Teacher Copy' : '');
    return renderBrandedPdfBytes($title, $subtitle, $body);
}

// ============================================================
// DOCX generation — minimal, valid OOXML via ZipArchive.
//
// PHPWord is not installed in vendor/ and this session cannot run
// composer against the school's server, so this hand-writes just enough
// of the Word Open XML package (word/document.xml + the mandatory rels/
// content-types/styles parts, plus one embedded image for the school
// logo) to produce a real .docx that opens correctly in Microsoft Word /
// LibreOffice / Google Docs. Lists are rendered as indented paragraphs
// with a literal bullet/number prefix rather than native numbering.xml —
// simpler, still opens correctly and reads clearly, at the cost of the
// list not being "real" auto-numbering.
// ============================================================

function aiDocxEscape($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function aiDocxRunXml($text, $bold = false, $size = null, $color = null) {
    $props = '';
    if ($bold) $props .= '<w:b/>';
    if ($size) $props .= '<w:sz w:val="' . (int) $size . '"/>';
    if ($color) $props .= '<w:color w:val="' . $color . '"/>';
    $rPr = $props ? '<w:rPr>' . $props . '</w:rPr>' : '';
    return '<w:r>' . $rPr . '<w:t xml:space="preserve">' . aiDocxEscape($text) . '</w:t></w:r>';
}

// $runs is a list of ['text' => ..., 'bold' => bool] (see aiParseInlineRuns).
function aiDocxParagraphRuns(array $runs, $opts = []) {
    $indent = isset($opts['indent']) ? ' w:left="' . (int) $opts['indent'] . '"' : '';
    $spacingAfter = isset($opts['spacingAfter']) ? (int) $opts['spacingAfter'] : 160;
    $spacingBefore = isset($opts['spacingBefore']) ? ' w:before="' . (int) $opts['spacingBefore'] . '"' : '';
    $align = isset($opts['align']) ? '<w:jc w:val="' . $opts['align'] . '"/>' : '';
    $pPr = '<w:pPr><w:spacing w:after="' . $spacingAfter . '"' . $spacingBefore . '/>' . $align . ($indent ? '<w:ind' . $indent . '/>' : '') . '</w:pPr>';
    $size = $opts['size'] ?? null;
    $color = $opts['color'] ?? null;
    $xml = '<w:p>' . $pPr;
    foreach ($runs as $r) {
        $bold = !empty($r['bold']) || !empty($opts['bold']);
        $xml .= aiDocxRunXml($r['text'], $bold, $size, $color);
    }
    $xml .= '</w:p>';
    return $xml;
}

// Convenience wrapper for a single plain (non-markdown) run — used for
// chrome text this file writes itself (title, labels), not model content.
function aiDocxParagraph($text, $opts = []) {
    return aiDocxParagraphRuns([['text' => $text, 'bold' => false]], $opts);
}

function aiDocxDivider($color = '5B5FEF') {
    return '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="12" w:space="4" w:color="' . $color . '"/></w:pBdr><w:spacing w:after="200"/></w:pPr></w:p>';
}

// A borderless two-column label/value table — the DOCX equivalent of the
// PDF's pdfInfoBox(), so the student/grade/subject/teacher/date block
// lines up the same way in both formats instead of reading as loose text.
function aiDocxInfoTable(array $rows) {
    $noBorder = '<w:tcBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/></w:tcBorders>';
    $trs = '';
    foreach ($rows as $label => $value) {
        $trs .= '<w:tr>'
            . '<w:tc><w:tcPr><w:tcW w:w="2400" w:type="dxa"/>' . $noBorder . '</w:tcPr>'
            . '<w:p><w:pPr><w:spacing w:after="60"/></w:pPr><w:r><w:rPr><w:color w:val="667085"/></w:rPr><w:t xml:space="preserve">' . aiDocxEscape($label) . '</w:t></w:r></w:p></w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="6600" w:type="dxa"/>' . $noBorder . '</w:tcPr>'
            . '<w:p><w:pPr><w:spacing w:after="60"/></w:pPr><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">' . aiDocxEscape($value) . '</w:t></w:r></w:p></w:tc>'
            . '</w:tr>';
    }
    return '<w:tbl>'
        . '<w:tblPr><w:tblW w:w="9000" w:type="dxa"/><w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders></w:tblPr>'
        . '<w:tblGrid><w:gridCol w:w="2400"/><w:gridCol w:w="6600"/></w:tblGrid>'
        . $trs
        . '</w:tbl>';
}

// One centered inline image paragraph, referencing a relationship id
// already declared in word/_rels/document.xml.rels (see aiRenderResourceDocx).
function aiDocxImageParagraph($relId, $cx, $cy) {
    return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="40"/></w:pPr><w:r><w:drawing>'
        . '<wp:inline distT="0" distB="0" distL="0" distR="0">'
        . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
        . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
        . '<wp:docPr id="1" name="SchoolLogo"/>'
        . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
        . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:pic><pic:nvPicPr><pic:cNvPr id="1" name="SchoolLogo"/><pic:cNvPicPr/></pic:nvPicPr>'
        . '<pic:blipFill><a:blip r:embed="' . $relId . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
        . '</pic:pic>'
        . '</a:graphicData></a:graphic>'
        . '</wp:inline>'
        . '</w:drawing></w:r></w:p>';
}

function aiContentBlocksToDocx($text) {
    $blocks = aiParseContentLines($text);
    $xml = '';
    foreach ($blocks as $b) {
        if ($b['type'] === 'blank') continue;
        $runs = aiParseInlineRuns($b['text']);
        if ($b['type'] === 'bullet') {
            $runs[0]['text'] = '•  ' . $runs[0]['text'];
            $xml .= aiDocxParagraphRuns($runs, ['indent' => 360, 'spacingAfter' => 80]);
        } elseif ($b['type'] === 'choice') {
            $xml .= aiDocxParagraphRuns($runs, ['indent' => 300, 'spacingAfter' => 80]);
        } elseif ($b['type'] === 'heading') {
            $xml .= aiDocxParagraphRuns($runs, ['bold' => true, 'size' => 22, 'spacingBefore' => 200, 'spacingAfter' => 100]);
        } elseif ($b['type'] === 'numbered') {
            $xml .= aiDocxParagraphRuns($runs, ['spacingBefore' => 160, 'spacingAfter' => 100]);
        } else {
            $xml .= aiDocxParagraphRuns($runs, ['spacingAfter' => 120]);
        }
    }
    return $xml;
}

function aiRenderResourceDocx(array $resource, array $context, $audience = 'student') {
    $title = $resource['title'] ?: aiResourceTypeLabel($resource['resource_type']);
    $typeLabel = aiResourceTypeLabel($resource['resource_type']) . ($audience === 'teacher' ? ' — Teacher Copy' : '');
    $schoolName = function_exists('getSchoolName') ? getSchoolName() : 'EGRADEPRO';

    $bodyXml = '';
    $mediaFiles = [];
    $relsExtra = '';

    // Header: same branding as the PDF (logo + school name) plus an accent
    // divider, so a generated Word file doesn't read as a bare, unbranded
    // text dump next to the PDF version.
    $logoPath = __DIR__ . '/../static/images/logo.png';
    if (is_readable($logoPath)) {
        $info = @getimagesize($logoPath);
        $maxCx = 620000; // ~0.68in wide — proportioned to match the PDF header logo
        if ($info && $info[0] > 0 && $info[1] > 0) {
            $cx = $maxCx;
            $cy = (int) round($maxCx * $info[1] / $info[0]);
        } else {
            $cx = $maxCx;
            $cy = $maxCx;
        }
        $bodyXml .= aiDocxImageParagraph('rId2', $cx, $cy);
        $mediaFiles['word/media/logo.png'] = file_get_contents($logoPath);
        $relsExtra .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.png"/>';
    }
    $bodyXml .= aiDocxParagraph($schoolName, ['bold' => true, 'size' => 28, 'spacingAfter' => 20, 'align' => 'center']);
    $bodyXml .= aiDocxParagraph('EGRADEPRO SCHOOL MANAGEMENT SYSTEM', ['size' => 16, 'color' => '667085', 'spacingAfter' => 160, 'align' => 'center']);
    $bodyXml .= aiDocxDivider();

    $bodyXml .= aiDocxParagraph($title, ['bold' => true, 'size' => 32, 'spacingAfter' => 40]);
    $bodyXml .= aiDocxParagraph($typeLabel, ['size' => 20, 'color' => '667085', 'spacingAfter' => 200]);

    $docxInfoRows = [
        'Student:' => $context['student_name'] ?? '',
        'Grade Level:' => $context['grade_level'] ?? '',
        'Subject:' => $context['subject_name'] ?? '',
        'Teacher:' => $context['teacher_name'] ?? '',
        'Date:' => $context['date'] ?? date('F j, Y'),
    ];
    if (!empty($context['omit_empty_rows'])) {
        $docxInfoRows = array_filter($docxInfoRows, function ($value) { return trim((string) $value) !== ''; });
    }
    if (!empty($docxInfoRows)) {
        $bodyXml .= aiDocxInfoTable($docxInfoRows);
    }
    $bodyXml .= '<w:p><w:pPr><w:spacing w:after="160"/></w:pPr></w:p>';

    $bodyXml .= aiContentBlocksToDocx($resource['student_content']);

    if ($audience === 'teacher' && !empty($resource['teacher_answer_key'])) {
        $bodyXml .= aiDocxDivider('F0DFAE');
        $bodyXml .= aiDocxParagraph('Answer Key / Teacher Notes', ['bold' => true, 'size' => 26, 'spacingAfter' => 100]);
        $bodyXml .= aiDocxParagraph('For teacher use only — do not distribute to the student.', ['size' => 18, 'color' => 'B7791F', 'spacingAfter' => 160]);
        $bodyXml .= aiContentBlocksToDocx($resource['teacher_answer_key']);
    }

    $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<w:document ' .
        'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" ' .
        'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" ' .
        'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" ' .
        'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" ' .
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<w:body>' . $bodyXml .
        '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>' .
        '</w:body></w:document>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Default Extension="png" ContentType="image/png"/>' .
        '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>' .
        '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>' .
        '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' .
        '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' .
        '</Types>';

    $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' .
        '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' .
        '</Relationships>';

    $docRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        $relsExtra .
        '</Relationships>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' .
        '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/></w:rPr></w:rPrDefault></w:docDefaults>' .
        '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>' .
        '</w:styles>';

    $now = date('Y-m-d\TH:i:s\Z');
    $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
        '<dc:title>' . aiDocxEscape($title) . '</dc:title>' .
        '<dc:creator>E-GRADEPRO</dc:creator>' .
        '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>' .
        '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>' .
        '</cp:coreProperties>';

    $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>E-GRADEPRO</Application></Properties>';

    $tmpPath = tempnam(sys_get_temp_dir(), 'egp_docx_');
    if ($tmpPath === false) {
        throw new RuntimeException('Could not create a temporary file for DOCX generation.');
    }
    // ZipArchive needs a real filesystem path to write to; tempnam() gives
    // us one that's cleaned up right after we read the bytes back below.
    $zip = new ZipArchive();
    if ($zip->open($tmpPath, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpPath);
        throw new RuntimeException('Could not create the DOCX package.');
    }
    $zip->addEmptyDir('_rels');
    $zip->addEmptyDir('word');
    $zip->addEmptyDir('word/_rels');
    $zip->addEmptyDir('docProps');
    if (!empty($mediaFiles)) {
        $zip->addEmptyDir('word/media');
    }
    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $rootRelsXml);
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->addFromString('word/styles.xml', $stylesXml);
    $zip->addFromString('word/_rels/document.xml.rels', $docRelsXml);
    $zip->addFromString('docProps/core.xml', $coreXml);
    $zip->addFromString('docProps/app.xml', $appXml);
    foreach ($mediaFiles as $path => $bytes) {
        $zip->addFromString($path, $bytes);
    }
    $zip->close();

    $bytes = file_get_contents($tmpPath);
    @unlink($tmpPath);
    if ($bytes === false) {
        throw new RuntimeException('Could not read back the generated DOCX.');
    }
    return $bytes;
}

// ============================================================
// Orchestration — render + store. Returns file metadata; never touches
// the database (the caller — modules/ai/chatbot.php or
// modules/ai/generate_resource.php — owns the ai_generated_resources
// row).
// ============================================================

/**
 * @param array  $resource  ['title','resource_type','student_content','teacher_answer_key']
 * @param array  $context   ['student_name','grade_level','subject_name','teacher_name','date']
 * @param string $format    'pdf' | 'docx'
 * @param string $audience  'student' | 'teacher'
 * @return array{success:bool, stored_filename?:string, original_filename?:string, mime_type?:string, file_size?:int, error?:string}
 */
function aiGenerateResourceFile(array $resource, array $context, $format, $audience = 'student') {
    $format = $format === 'docx' ? 'docx' : 'pdf';
    $audience = $audience === 'teacher' ? 'teacher' : 'student';

    try {
        if ($format === 'pdf') {
            $bytes = aiRenderResourcePdf($resource, $context, $audience);
            $mime = 'application/pdf';
        } else {
            if (!class_exists('ZipArchive')) {
                return ['success' => false, 'error' => 'Word generation is not available on this server (PHP zip extension missing).'];
            }
            $bytes = aiRenderResourceDocx($resource, $context, $audience);
            $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }
    } catch (\Throwable $e) {
        error_log('AI document generator [' . $format . ']: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return ['success' => false, 'error' => 'The document could not be generated. Please try again.'];
    }

    if ($bytes === '' || $bytes === false || $bytes === null) {
        return ['success' => false, 'error' => 'The document could not be generated. Please try again.'];
    }

    $dir = aiResourceStorageDir();
    $stored = aiGenerateStoredFilename($format);
    $fullPath = $dir . $stored;

    if (@file_put_contents($fullPath, $bytes) === false) {
        error_log('AI document generator: failed to write ' . $fullPath);
        return ['success' => false, 'error' => 'The document could not be saved. Please try again.'];
    }
    @chmod($fullPath, 0644);

    return [
        'success' => true,
        'stored_filename' => $stored,
        'original_filename' => aiSafeDisplayFilename($resource['title'] ?? '', $resource['resource_type'] ?? 'other', $format),
        'mime_type' => $mime,
        'file_size' => strlen($bytes),
    ];
}
