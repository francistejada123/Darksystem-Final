<?php
// File generation for the separate E-GRADEPRO Teacher AI feature.
require_once __DIR__ . '/pdf_helpers.php';

function teacherAiDocumentTitle($title) {
    $title = trim((string)$title);
    return $title !== '' ? $title : 'E-GRADEPRO Teacher AI Resource';
}

function teacherAiPdfBytes($title, $content) {
    $title = htmlspecialchars(teacherAiDocumentTitle($title), ENT_QUOTES, 'UTF-8');
    $html = '<h1 style="color:#14213D;font-size:22px;margin:0 0 16px">' . $title . '</h1>';
    foreach (preg_split('/\R/', trim((string)$content)) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $safe = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
        if (preg_match('/^(#{1,3}|\*\*)\s*(.+?)(\*\*)?$/', $line, $m)) {
            $html .= '<h2 style="color:#356EA8;font-size:15px;margin:14px 0 6px">' . htmlspecialchars(trim($m[2]), ENT_QUOTES, 'UTF-8') . '</h2>';
        } elseif (preg_match('/^[-*•]\s+(.+)/u', $line, $m)) {
            $html .= '<p style="margin:3px 0 3px 16px">&#8226; ' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</p>';
        } else {
            $html .= '<p style="margin:0 0 8px">' . $safe . '</p>';
        }
    }
    return renderBrandedPdfBytes('E-GRADEPRO Teacher AI', 'Teacher resource', $html, 'A4', 'portrait');
}

/**
 * Splits the AI reply into one slide per heading line ("# ...", "## ...",
 * or a "**bold**"-only line), each with a list of body lines (bullets
 * normalized to a leading "• "). Shared by teacherAiPptxBytes() below.
 */
function teacherAiSlideOutline($title, $content) {
    $title = teacherAiDocumentTitle($title);
    $slides = [];
    $current = ['title' => $title, 'body' => []];
    foreach (preg_split('/\R/', trim((string)$content)) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^(#{1,3}|\*\*)\s*(.+?)(\*\*)?$/', $line, $m)) {
            if (!empty($current['body']) || count($slides) > 0) $slides[] = $current;
            $current = ['title' => trim($m[2]), 'body' => []];
        } else {
            $current['body'][] = preg_replace('/^[-*•]\s+/u', '• ', $line);
        }
    }
    if (!empty($current['body']) || empty($slides)) $slides[] = $current;
    return $slides;
}

// ============================================================
// REAL .PPTX (OOXML) GENERATION — no external library.
// ============================================================
// A .pptx is just a zip of XML parts (Office Open XML / ECMA-376). Every
// part below is the minimal, spec-valid skeleton PowerPoint itself
// produces: one blank slide layout + master (slides draw their own
// explicitly-positioned text boxes rather than inheriting placeholder
// geometry from the layout, so the layout/master can stay minimal), a
// standard theme (fmtScheme's fill/line/effect/bgFill style lists are
// schema-required to have exactly 3 entries each), and one slideN.xml
// per outline slide. This replaced an earlier version of this file that
// served plain HTML mislabeled as a .ppt — real PowerPoint refuses that
// (wrong binary format for the extension), which is why the export
// previously did not open.

function teacherAiPptxEscape($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function teacherAiPptxSlideXml($slide) {
    $title = teacherAiPptxEscape($slide['title']);
    $paragraphs = '';
    if (empty($slide['body'])) {
        $paragraphs = '<a:p><a:endParaRPr lang="en-US" sz="1800"/></a:p>';
    } else {
        foreach ($slide['body'] as $line) {
            $isBullet = mb_substr($line, 0, 2) === '• ';
            $text = teacherAiPptxEscape($isBullet ? mb_substr($line, 2) : $line);
            if ($isBullet) {
                $paragraphs .= '<a:p><a:pPr marL="285750" indent="-285750"><a:buFont typeface="Arial"/><a:buChar char="&#8226;"/></a:pPr><a:r><a:rPr lang="en-US" sz="1800" dirty="0"/><a:t>' . $text . '</a:t></a:r></a:p>';
            } else {
                $paragraphs .= '<a:p><a:pPr marL="0" indent="0"><a:buNone/></a:pPr><a:r><a:rPr lang="en-US" sz="1800" dirty="0"/><a:t>' . $text . '</a:t></a:r></a:p>';
            }
        }
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:cSld><p:spTree>'
        . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
        . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr/></p:nvSpPr>'
        . '<p:spPr><a:xfrm><a:off x="457200" y="274638"/><a:ext cx="11277600" cy="914400"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>'
        . '<p:txBody><a:bodyPr wrap="square"><a:normAutofit/></a:bodyPr><a:lstStyle/>'
        . '<a:p><a:r><a:rPr lang="en-US" sz="3200" b="1" dirty="0"><a:solidFill><a:srgbClr val="14213D"/></a:solidFill></a:rPr><a:t>' . $title . '</a:t></a:r></a:p>'
        . '</p:txBody></p:sp>'
        . '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Body"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr/></p:nvSpPr>'
        . '<p:spPr><a:xfrm><a:off x="457200" y="1371600"/><a:ext cx="11277600" cy="5140000"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>'
        . '<p:txBody><a:bodyPr wrap="square"><a:normAutofit/></a:bodyPr><a:lstStyle/>'
        . $paragraphs
        . '</p:txBody></p:sp>'
        . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
}

function teacherAiPptxTheme1Xml() {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="EGRADEPRO Theme">'
        . '<a:themeElements>'
        . '<a:clrScheme name="EGRADEPRO"><a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1><a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>'
        . '<a:dk2><a:srgbClr val="14213D"/></a:dk2><a:lt2><a:srgbClr val="EDF4FB"/></a:lt2>'
        . '<a:accent1><a:srgbClr val="356EA8"/></a:accent1><a:accent2><a:srgbClr val="14213D"/></a:accent2>'
        . '<a:accent3><a:srgbClr val="69C49F"/></a:accent3><a:accent4><a:srgbClr val="9EC7EF"/></a:accent4>'
        . '<a:accent5><a:srgbClr val="667085"/></a:accent5><a:accent6><a:srgbClr val="B94A48"/></a:accent6>'
        . '<a:hlink><a:srgbClr val="356EA8"/></a:hlink><a:folHlink><a:srgbClr val="14213D"/></a:folHlink></a:clrScheme>'
        . '<a:fontScheme name="EGRADEPRO"><a:majorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
        . '<a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme>'
        . '<a:fmtScheme name="EGRADEPRO">'
        . '<a:fillStyleLst>'
        . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
        . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
        . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
        . '</a:fillStyleLst>'
        . '<a:lnStyleLst>'
        . '<a:ln w="6350" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln>'
        . '<a:ln w="12700" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln>'
        . '<a:ln w="19050" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln>'
        . '</a:lnStyleLst>'
        . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
        . '<a:bgFillStyleLst>'
        . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
        . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
        . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
        . '</a:bgFillStyleLst>'
        . '</a:fmtScheme>'
        . '</a:themeElements>'
        . '</a:theme>';
}

/** @return array<string,string> published-part-path => XML/text content, ready to zip. */
function teacherAiPptxParts(array $slides) {
    $slideCount = count($slides);

    $parts = [];

    $slideOverrides = '';
    for ($i = 1; $i <= $slideCount; $i++) {
        $slideOverrides .= '<Override PartName="/ppt/slides/slide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
    }
    $parts['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
        . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
        . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
        . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . $slideOverrides
        . '</Types>';

    $parts['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $safeTitle = teacherAiPptxEscape($slides[0]['title'] ?? 'E-GRADEPRO Teacher AI Resource');
    $parts['docProps/core.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . $safeTitle . '</dc:title>'
        . '<dc:creator>E-GRADEPRO Teacher AI</dc:creator>'
        . '<cp:lastModifiedBy>E-GRADEPRO Teacher AI</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $parts['docProps/app.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
        . '<Application>E-GRADEPRO</Application><Slides>' . $slideCount . '</Slides>'
        . '</Properties>';

    $sldIdEntries = '';
    $presRelsEntries = '';
    for ($i = 1; $i <= $slideCount; $i++) {
        $sldIdEntries .= '<p:sldId id="' . (255 + $i) . '" r:id="rId' . (10 + $i) . '"/>';
        $presRelsEntries .= '<Relationship Id="rId' . (10 + $i) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $i . '.xml"/>';
    }
    $parts['ppt/presentation.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
        . '<p:sldIdLst>' . $sldIdEntries . '</p:sldIdLst>'
        . '<p:sldSz cx="12192000" cy="6858000" type="screen16x9"/>'
        . '<p:notesSz cx="6858000" cy="9144000"/>'
        . '</p:presentation>';

    $parts['ppt/_rels/presentation.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>'
        . $presRelsEntries
        . '</Relationships>';

    $parts['ppt/theme/theme1.xml'] = teacherAiPptxTheme1Xml();

    $parts['ppt/slideMasters/slideMaster1.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:cSld><p:spTree>'
        . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
        . '</p:spTree></p:cSld>'
        . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
        . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
        . '</p:sldMaster>';

    $parts['ppt/slideMasters/_rels/slideMaster1.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>'
        . '</Relationships>';

    $parts['ppt/slideLayouts/slideLayout1.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="blank" preserve="1">'
        . '<p:cSld name="Blank"><p:spTree>'
        . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
        . '</p:spTree></p:cSld>'
        . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>'
        . '</p:sldLayout>';

    $parts['ppt/slideLayouts/_rels/slideLayout1.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'
        . '</Relationships>';

    foreach ($slides as $i => $slide) {
        $n = $i + 1;
        $parts['ppt/slides/slide' . $n . '.xml'] = teacherAiPptxSlideXml($slide);
        $parts['ppt/slides/_rels/slide' . $n . '.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            . '</Relationships>';
    }

    return $parts;
}

/**
 * Zips an already-built slide list ([['title' => ..., 'body' => [lines]], ...])
 * into a real, PowerPoint-openable .pptx and returns its raw bytes. Shared by
 * teacherAiPptxBytes() (the original heading-based export) and by the
 * generate-then-send flow (includes/teacher_ai_resources.php), which builds
 * its slide list with teacherAiBuildSlides() below.
 */
function teacherAiPptxBytesFromSlides(array $slides) {
    if (empty($slides)) {
        $slides = [['title' => 'E-GRADEPRO Teacher AI Resource', 'body' => []]];
    }
    $parts = teacherAiPptxParts($slides);

    $tmpPath = tempnam(sys_get_temp_dir(), 'egp_pptx_');
    $zip = new ZipArchive();
    $zip->open($tmpPath, ZipArchive::OVERWRITE);
    foreach ($parts as $path => $xml) {
        $zip->addFromString($path, $xml);
    }
    $zip->close();

    $bytes = file_get_contents($tmpPath);
    unlink($tmpPath);
    return $bytes;
}

/** Builds a real, PowerPoint-openable .pptx and returns its raw bytes. */
function teacherAiPptxBytes($title, $content) {
    return teacherAiPptxBytesFromSlides(teacherAiSlideOutline($title, $content));
}

// ============================================================
// Slide building for generated resources (Teacher AI -> PowerPoint)
// ============================================================
// A lesson/quiz/activity written for reading is not a good deck as-is, so the
// PowerPoint output asks Gemini to reshape the SAME material into slides
// (teacherAiGeminiSlides()) and falls back to a deterministic local split
// (teacherAiSlidesLocal()) if Gemini is unavailable or returns nothing usable.
// Only student-facing content is ever used -- never the teacher answer key --
// because the deck is meant to be handed to students.

/** Max body lines on one slide before the rest continues on a "(cont.)" slide. */
const TEACHER_AI_SLIDE_MAX_LINES = 7;

/**
 * Splits any slide with too many body lines into "(cont.)" slides so text
 * stays on the slide. Input/output shape: [['title' => ..., 'body' => [...]], ...].
 */
function teacherAiChunkSlides(array $slides) {
    $out = [];
    foreach ($slides as $slide) {
        $title = trim((string)($slide['title'] ?? ''));
        $body = array_values($slide['body'] ?? []);
        if (count($body) <= TEACHER_AI_SLIDE_MAX_LINES) {
            $out[] = ['title' => $title, 'body' => $body];
            continue;
        }
        $chunks = array_chunk($body, TEACHER_AI_SLIDE_MAX_LINES);
        foreach ($chunks as $i => $chunk) {
            $out[] = ['title' => $i === 0 ? $title : $title . ' (cont.)', 'body' => $chunk];
        }
    }
    return $out;
}

/**
 * Deterministic fallback: builds slides straight from the plain-text content
 * convention used by every generated resource (aiParseContentLines() in
 * includes/ai_document_generator.php): a heading starts a slide, a numbered
 * item (a quiz question) starts its own slide with its choices under it.
 */
function teacherAiSlidesLocal($title, $content, array $titleSlideLines = []) {
    $title = teacherAiDocumentTitle($title);
    $slides = [['title' => $title, 'body' => $titleSlideLines]];

    $blocks = function_exists('aiParseContentLines') ? aiParseContentLines($content) : [];
    if (empty($blocks)) {
        foreach (preg_split('/\R/', trim((string)$content)) as $line) {
            $line = trim($line);
            $blocks[] = $line === '' ? ['type' => 'blank'] : ['type' => 'para', 'text' => $line];
        }
    }

    $strip = function ($text) {
        return trim(str_replace('*', '', (string)$text));
    };

    $current = null;
    $currentIsQuestion = false;
    $sectionTitle = $title;
    $flush = function () use (&$current, &$slides) {
        if ($current !== null && (!empty($current['body']) || $current['title'] !== '')) {
            $slides[] = $current;
        }
        $current = null;
    };

    foreach ($blocks as $b) {
        $type = $b['type'] ?? 'blank';
        if ($type === 'blank') continue;
        $text = $strip($b['text'] ?? '');
        if ($text === '') continue;

        if ($type === 'heading') {
            $flush();
            $sectionTitle = $text;
            $current = ['title' => $text, 'body' => []];
            $currentIsQuestion = false;
        } elseif ($type === 'numbered') {
            // Each question gets its own slide so it is readable from the back of the room.
            $flush();
            $current = ['title' => $sectionTitle, 'body' => [$text]];
            $currentIsQuestion = true;
        } else {
            if ($current === null) {
                $current = ['title' => $sectionTitle, 'body' => []];
                $currentIsQuestion = false;
            }
            if ($type === 'bullet') {
                $current['body'][] = '• ' . $text;
            } else {
                $current['body'][] = $text;
            }
        }
    }
    $flush();

    // Drop a heading-only slide that was immediately followed by its own content
    // (a heading with no body lines still makes a fine section slide, so keep it).
    return teacherAiChunkSlides($slides);
}

/**
 * Asks Gemini to reshape the material into presentation slides. Returns the
 * cleaned slide list (WITHOUT a title slide) or null when Gemini is not
 * available / returns nothing usable, in which case the caller falls back to
 * teacherAiSlidesLocal().
 */
function teacherAiGeminiSlides($title, $content) {
    if (!function_exists('callGeminiAPI') || !function_exists('extractGeminiRawText')) {
        return null;
    }
    $schema = [
        'type' => 'object',
        'properties' => [
            'slides' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'bullets' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['title', 'bullets'],
                ],
            ],
        ],
        'required' => ['slides'],
    ];
    $responseFormat = ['type' => 'text', 'mime_type' => 'application/json', 'schema' => $schema];

    $system = "You turn classroom material into a clear PowerPoint outline that a teacher will present to students.\n"
        . "RESPOND WITH ONLY A SINGLE JSON OBJECT: {\"slides\": [{\"title\": string, \"bullets\": [string, ...]}]}.\n"
        . "Rules: 6 to 16 slides. Do NOT include a title slide (one is added automatically). Each slide has a short title and at most 5 concise bullets (about 12 words each). "
        . "Cover every important idea in the material in the same order: objectives, key concepts with a simple example, a learner activity or guided practice, and a short recap. "
        . "For a quiz or set of questions, put ONE question per slide with its answer choices as the bullets (keep the letter labels, e.g. \"A. ...\") and never include an answer key or the correct answers. "
        . "Keep the SAME language and reading level as the material. Never mention any individual student.";

    $input = "TITLE: " . teacherAiDocumentTitle($title) . "\n\nMATERIAL:\n" . mb_substr((string)$content, 0, 9000);

    $timeout = defined('GEMINI_RESOURCE_TIMEOUT_SECONDS') ? GEMINI_RESOURCE_TIMEOUT_SECONDS : 60;
    $api = callGeminiAPI($system, $input, $responseFormat, $timeout);
    if (empty($api['success'])) {
        return null;
    }
    $text = extractGeminiRawText($api['response'] ?? null);
    if ($text === null) {
        return null;
    }
    $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
    $text = trim(preg_replace('/\s*```$/', '', $text));
    $decoded = json_decode($text, true);
    $rawSlides = is_array($decoded) ? ($decoded['slides'] ?? ($decoded[0] ?? null)) : null;
    if (!is_array($rawSlides)) {
        return null;
    }

    $slides = [];
    foreach ($rawSlides as $raw) {
        if (!is_array($raw)) continue;
        $slideTitle = trim(str_replace('*', '', (string)($raw['title'] ?? '')));
        if ($slideTitle === '') continue;
        $body = [];
        foreach ((array)($raw['bullets'] ?? []) as $bullet) {
            $bullet = trim(str_replace('*', '', (string)$bullet));
            $bullet = preg_replace('/^[-•]\s*/u', '', $bullet);
            if ($bullet === '') continue;
            if (mb_strlen($bullet) > 220) $bullet = mb_substr($bullet, 0, 217) . '...';
            // Keep "A. choice" lines plain; everything else is a bullet.
            $body[] = preg_match('/^[A-Ea-e][.)]\s/u', $bullet) ? $bullet : '• ' . $bullet;
            if (count($body) >= 8) break;
        }
        if (mb_strlen($slideTitle) > 90) $slideTitle = mb_substr($slideTitle, 0, 87) . '...';
        $slides[] = ['title' => $slideTitle, 'body' => $body];
        if (count($slides) >= 30) break;
    }
    return count($slides) >= 2 ? $slides : null;
}

/**
 * The slide list for a generated resource: a title slide, then either
 * Gemini's slide outline or the local fallback. Always returns at least one slide.
 *
 * @param array $titleSlideLines Small lines under the title (e.g. "Prepared by ...", date).
 */
function teacherAiBuildSlides($title, $content, array $titleSlideLines = []) {
    $title = teacherAiDocumentTitle($title);
    $gemini = teacherAiGeminiSlides($title, $content);
    if ($gemini !== null) {
        array_unshift($gemini, ['title' => $title, 'body' => $titleSlideLines]);
        return teacherAiChunkSlides($gemini);
    }
    return teacherAiSlidesLocal($title, $content, $titleSlideLines);
}

function teacherAiDownloadName($title, $format) {
    $base = strtolower(preg_replace('/[^a-z0-9]+/i', '_', teacherAiDocumentTitle($title)));
    $base = trim(substr($base ?: 'teacher_ai_resource', 0, 70), '_');
    return $base . ($format === 'pdf' ? '.pdf' : '.pptx');
}
