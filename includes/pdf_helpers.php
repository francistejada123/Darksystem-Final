<?php
// includes/pdf_helpers.php
// Shared branded PDF rendering used by every export in modules/reports/*
// (Quick Reports: SF1/SF5/SF9/Grade Summary/Report Card/At-Risk, and the
// Export Options card). Wraps Dompdf (vendor/dompdf/dompdf, installed via
// composer) with EGRADEPRO's own visual identity — header band with the
// school logo, accent bar, styled tables/badges, and a repeating footer
// with the generated date + real page numbers — so exports look like part
// of the system instead of a bare printed table.
//
// Usage from a report generator:
//   require_once __DIR__ . '/../../includes/pdf_helpers.php';
//   $body = pdfSectionTitle('Students') . pdfTable($headers, $rows);
//   streamReportPdf('SF1 - School Register', 'Grade 10 - Rizal', $body, 'sf1_grade10-rizal.pdf');
//
// Every value interpolated into the HTML this file builds must already be
// htmlspecialchars()'d by the caller (same rule as the rest of the app) —
// these helpers do not re-escape values passed to them, only the small
// bits of chrome (title/subtitle/meta) they own directly.

require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// ============================================================
// Branding
// ============================================================

// Base64 data-URI of the system logo. Cached per-request — every report
// that builds a PDF in the same request re-embeds the same string.
function pdfLogoDataUri() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $path = __DIR__ . '/../static/images/logo.png';
    if (is_readable($path)) {
        $cached = 'data:image/png;base64,' . base64_encode(file_get_contents($path));
    } else {
        $cached = '';
    }
    return $cached;
}

// ============================================================
// Small content builders — reused across the report templates so every
// PDF shares one look instead of each generator inventing its own.
// ============================================================

// A styled section heading with a colored accent underline.
function pdfSectionTitle($text) {
    return '<h2 class="pdf-section-title">' . $text . '</h2>';
}

// A bordered "info card" of label/value pairs — used for the student
// identity block on SF9/Report Card/At-Risk reports.
function pdfInfoBox(array $rows) {
    $html = '<div class="pdf-info-box"><table class="pdf-info-table">';
    foreach ($rows as $label => $value) {
        $html .= '<tr><td class="pdf-info-label">' . $label . '</td><td class="pdf-info-value">' . $value . '</td></tr>';
    }
    $html .= '</table></div>';
    return $html;
}

// Small colored status pill. $variant one of: success | warning | danger | info | gray.
function pdfBadge($text, $variant = 'gray') {
    $variant = in_array($variant, ['success', 'warning', 'danger', 'info', 'gray'], true) ? $variant : 'gray';
    return '<span class="pdf-badge pdf-badge-' . $variant . '">' . $text . '</span>';
}

// A bulleted list, built from plain <div> rows instead of <ul>/<li> —
// Dompdf 3.x mis-lays-out native list markup (items render shifted far
// right of the page instead of flush with the rest of the content), so
// this is the safe equivalent used everywhere a report needs a list.
function pdfListItems(array $items) {
    $html = '<div class="pdf-list">';
    foreach ($items as $item) {
        $html .= '<div class="pdf-list-item"><span class="pdf-list-bullet">&bull;</span>' . $item . '</div>';
    }
    $html .= '</div>';
    return $html;
}

// Generic striped data table. $headers is a flat list of column labels.
// $rows is a list of rows, each a flat list of already-escaped cell HTML
// in the same order as $headers. $align (optional) maps a 0-based column
// index to 'left'|'center'|'right'.
function pdfTable(array $headers, array $rows, array $align = [], $emptyMessage = 'No data available.') {
    $html = '<table class="pdf-table"><thead><tr>';
    foreach ($headers as $i => $h) {
        $a = $align[$i] ?? 'left';
        $html .= '<th style="text-align:' . $a . ';">' . $h . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    if (empty($rows)) {
        $html .= '<tr><td colspan="' . count($headers) . '" class="pdf-table-empty">' . $emptyMessage . '</td></tr>';
    } else {
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $i => $cell) {
                $a = $align[$i] ?? 'left';
                $html .= '<td style="text-align:' . $a . ';">' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
    }
    $html .= '</tbody></table>';
    return $html;
}

// ============================================================
// Page shell — header band, accent bar, content, repeating footer.
// ============================================================

// $chrome = false: clean full-page document with no fixed branded header,
// accent bar, footer or page numbers (used by the SF9 reference layout,
// which is its own complete page). Defaults to true so every existing
// report renders exactly as before.
function pdfShell($reportTitle, $reportSubtitle, $bodyHtml, $chrome = true) {
    // A report may carry its own <style> block; it belongs in <head>
    // (Dompdf lays out a <style> inside <body> badly).
    $extraCss = '';
    $bodyHtml = preg_replace_callback('#<style>(.*?)</style>#s', function ($m) use (&$extraCss) { $extraCss .= $m[1]; return ''; }, $bodyHtml);
    $school = htmlspecialchars(function_exists('getSchoolName') ? getSchoolName() : 'EGRADEPRO', ENT_QUOTES);
    $logo = pdfLogoDataUri();
    $title = htmlspecialchars($reportTitle, ENT_QUOTES);
    $subtitle = htmlspecialchars($reportSubtitle, ENT_QUOTES);
    $logoImg = $logo ? '<img src="' . $logo . '" class="pdf-logo" alt="">' : '';

    $generatedBy = htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['username'] ?? 'System', ENT_QUOTES);
    $generatedAt = htmlspecialchars(date('F j, Y g:i A'), ENT_QUOTES);
    $footerLeft = "Generated by {$generatedBy} on {$generatedAt} &middot; EGRADEPRO";

    $page_margin = $chrome ? '118px 30px 60px 30px' : '26px 24px 28px 24px';
    $style = <<<CSS
        @page { margin: $page_margin; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12.5px; color: #172033; margin: 0; }

        header { position: fixed; top: -100px; left: 0; right: 0; height: 56px; background: #14213D; color: #fff; padding: 14px 26px; }   /* 56 + 2x14 padding = 84px: Dompdf adds the padding to a fixed box, and a taller band hid the first lines of every page */
        .pdf-accent { position: fixed; top: -16px; left: 0; right: 0; height: 4px; background: #5B5FEF; }
        .pdf-header-table { width: 100%; border-collapse: collapse; }
        .pdf-header-table td { border: none; padding: 0; vertical-align: middle; }
        .pdf-logo { height: 40px; width: 40px; vertical-align: middle; margin-right: 10px; }
        .pdf-school-name { font-size: 15px; font-weight: bold; color: #fff; margin: 0; }
        .pdf-school-tag { font-size: 8.5px; letter-spacing: 1.5px; color: #AEB4E8; margin: 2px 0 0 0; text-transform: uppercase; }
        .pdf-report-title { font-size: 16px; font-weight: bold; color: #fff; margin: 0; text-align: right; }
        .pdf-report-subtitle { font-size: 12px; color: #C7CAF5; margin: 3px 0 0 0; text-align: right; }

        footer { position: fixed; bottom: -46px; left: 0; right: 0; height: 40px; border-top: 1px solid #DCE3EC; padding-top: 8px; font-size: 8.5px; color: #667085; }
        .pdf-footer-left { display: block; }   /* no float: a float in the fixed footer leaks into the page flow in Dompdf */
        .pdf-footer-right { display: block; }

        h1, h2, h3, h4 { font-family: 'DejaVu Sans', sans-serif; }
        .pdf-section-title { font-size: 14.5px; font-weight: bold; color: #14213D; margin: 18px 0 8px 0; padding-bottom: 5px; border-bottom: 2px solid #5B5FEF; }
        .pdf-section-title:first-child { margin-top: 0; }

        .pdf-info-box { background: #F3F6F9; border: 1px solid #DCE3EC; border-radius: 8px; padding: 10px 16px; margin-bottom: 14px; }
        .pdf-info-table { width: 100%; border-collapse: collapse; }
        .pdf-info-table td { border: none; padding: 3px 0; font-size: 12.5px; }
        .pdf-info-label { color: #667085; width: 160px; font-weight: normal; }
        .pdf-info-value { color: #172033; font-weight: bold; }

        table.pdf-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.pdf-table th { background: #EEF0FF; color: #14213D; font-size: 12px; font-weight: bold; padding: 7px 8px; border: 1px solid #DCE3EC; text-transform: uppercase; letter-spacing: 0.3px; }
        table.pdf-table td { padding: 6px 8px; border: 1px solid #E7ECF2; font-size: 12px; }
        table.pdf-table tbody tr:nth-child(even) { background: #F8FAFC; }
        .pdf-table-empty { text-align: center; color: #98A2B3; padding: 14px; font-style: italic; }
        .pdf-table-foot td { font-weight: bold; background: #F3F6F9; }

        /* No border-radius here on purpose: Dompdf 3.x badly miscalculates
           the height of an inline element with border-radius sitting
           inside a table cell (it reserves a huge, wrong amount of
           vertical space for it), which was pushing everything after the
           table down by nearly a full page. A flat tag with a colored
           left rule reads just as clearly as a pill without tripping it. */
        .pdf-badge { padding: 2px 10px 2px 8px; border-left: 3px solid; font-size: 11px; font-weight: bold; white-space: nowrap; }
        .pdf-badge-success { background: #EAF7F1; color: #238863; border-left-color: #238863; }
        .pdf-badge-warning { background: #FFF7E6; color: #B7791F; border-left-color: #B7791F; }
        .pdf-badge-danger  { background: #FDEEEE; color: #C94A4A; border-left-color: #C94A4A; }
        .pdf-badge-info    { background: #EDF4FB; color: #356EA8; border-left-color: #356EA8; }
        .pdf-badge-gray    { background: #F3F6F9; color: #667085; border-left-color: #98A2B3; }

        .pdf-muted { color: #667085; }
        .pdf-list { margin: 6px 0 14px 0; }
        .pdf-list-item { padding-left: 16px; margin-bottom: 6px; }
        .pdf-list-bullet { color: #5B5FEF; font-weight: bold; margin-left: -16px; padding-right: 8px; }
CSS;

    $chrome_head = $chrome ? <<<HTML
    <header>
        <table class="pdf-header-table"><tr>
            <td style="width:60%;">
                {$logoImg}<span style="display:inline-block; vertical-align:middle;">
                    <p class="pdf-school-name">{$school}</p>
                    <p class="pdf-school-tag">EGRADEPRO School Management System</p>
                </span>
            </td>
            <td style="width:40%;">
                <p class="pdf-report-title">{$title}</p>
                <p class="pdf-report-subtitle">{$subtitle}</p>
            </td>
        </tr></table>
    </header>
    <div class="pdf-accent"></div>
    <footer>
        <span class="pdf-footer-left">{$footerLeft}</span>
    </footer>
    <script type="text/php">
    if (isset(\$pdf)) {
        \$font = \$fontMetrics->getFont("DejaVu Sans", "normal");
        \$w = \$pdf->get_width();
        \$h = \$pdf->get_height();
        \$text = "Page {PAGE_NUM} of {PAGE_COUNT}";
        \$size = 8.5;
        \$textWidth = \$fontMetrics->getTextWidth(\$text, \$font, \$size);
        \$pdf->page_text(\$w - 30 - \$textWidth, \$h - 42, \$text, \$font, \$size, array(0.4, 0.44, 0.53));
    }
    </script>
HTML
    : '';

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{$title}</title>
<style>{$style}{$extraCss}</style>
</head>
<body>
    {$chrome_head}
    {$bodyHtml}
</body>
</html>
HTML;
}

// ============================================================
// Streaming — builds the branded document and sends it as a real,
// downloadable PDF (not a browser print dialog). Ends the request.
// ============================================================

// $filename should already end in ".pdf" and be filesystem-safe (the
// callers build these from static report names / sanitized identifiers,
// never raw user input).
function streamReportPdf($reportTitle, $reportSubtitle, $bodyHtml, $filename, $paper = 'A4', $orientation = 'portrait', $chrome = true) {
    $html = pdfShell($reportTitle, $reportSubtitle, $bodyHtml, $chrome);

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true); // only for the page-number script above, which is author-controlled, not user input
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('chroot', realpath(__DIR__ . '/..'));

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper($paper, $orientation);
    $dompdf->render();

    if (ob_get_level()) {
        ob_end_clean();
    }
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

// Builds the exact same branded document as streamReportPdf() (same
// pdfShell(), same Dompdf options) but RETURNS the raw PDF bytes instead
// of streaming them to the current HTTP response and exiting. Added
// (streamReportPdf() itself is untouched, on purpose — every existing
// report export keeps using it exactly as before) for anything that
// needs to SAVE the PDF to disk first, e.g. includes/
// ai_document_generator.php writing a generated resource to
// static/uploads/ai_resources/ for later View/Download/Send to Student,
// rather than handing it straight to the current response.
function renderBrandedPdfBytes($reportTitle, $reportSubtitle, $bodyHtml, $paper = 'A4', $orientation = 'portrait') {
    $html = pdfShell($reportTitle, $reportSubtitle, $bodyHtml);

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('chroot', realpath(__DIR__ . '/..'));

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper($paper, $orientation);
    $dompdf->render();

    return $dompdf->output();
}
