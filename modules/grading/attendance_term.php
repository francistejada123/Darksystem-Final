<?php
// modules/grading/attendance_term.php
// Grade Management -> "Attendance" button. The whole-term attendance of one of
// the teacher's classes (Teacher only, and only for a class they teach):
//
//   ?format=json   the table shown in the Attendance window
//   ?format=xlsx   the same records as an Excel file (falls back to CSV when
//                  the server has no zip support)
//
// Read-only: nothing here writes to the database except the additive
// attendance columns that attQrEnsureSchema() makes sure exist.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';
require_once __DIR__ . '/../../includes/attendance_qr_helpers.php';

function attTermFail($message, $code = 403, $json = true)
{
    http_response_code($code);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $message]);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
    exit;
}

$format = (($_GET['format'] ?? 'json') === 'xlsx') ? 'xlsx' : 'json';
$asJson = ($format === 'json');

if (!isLoggedIn() || !hasRole('Teacher')) {
    attTermFail('Unauthorized.', 403, $asJson);
}

$teacherId = (int) ($_SESSION['user_id'] ?? 0);
$activeSy = getRecord("SELECT sy_id, sy_name FROM school_years WHERE is_active = 1");
if (!$activeSy) {
    attTermFail('No active school year.', 422, $asJson);
}
$syId = (int) $activeSy['sy_id'];

$subjectCode = trim((string) ($_GET['subject_code'] ?? ''));
$sectionId = (int) ($_GET['section_id'] ?? 0);
$term = (string) ($_GET['term'] ?? '');
if (!isValidTerm($term)) {
    $term = getDefaultTerm();
}
if ($subjectCode === '' || $sectionId <= 0 || !attQrTeacherOwnsClass($teacherId, $subjectCode, $sectionId, $syId)) {
    attTermFail('You do not teach this class.', 403, $asJson);
}
if (!attQrEnsureSchema()) {
    attTermFail('Attendance database update could not be applied. Please contact the administrator.', 500, $asJson);
}

$report = attQrTermReport($subjectCode, $sectionId, $syId, $term);

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['success' => true, 'report' => $report]);
    exit;
}

// ------------------------------------------------------------------
// Excel (.xlsx) -- a small hand-written OOXML workbook, same approach as
// includes/ai_document_generator.php (no Composer / PhpSpreadsheet needed).
// ------------------------------------------------------------------
$section = getRecord("SELECT grade_level, section_name FROM sections WHERE section_id = ?", [$sectionId]);
$subject = getRecord("SELECT subject_name FROM subjects WHERE subject_code = ?", [$subjectCode]);
$teacher = getRecord("SELECT first_name, last_name FROM users WHERE user_id = ?", [$teacherId]);
$sectionLabel = $section ? $section['grade_level'] . ' - ' . $section['section_name'] : 'Section ' . $sectionId;
$subjectLabel = $subject ? $subject['subject_name'] : $subjectCode;
$teacherLabel = $teacher ? $teacher['first_name'] . ' ' . $teacher['last_name'] : '';

$title = 'Attendance Record - ' . $sectionLabel . ' - ' . $subjectLabel . ' - ' . $term . ($report['sy_name'] !== '' ? ' (SY ' . $report['sy_name'] . ')' : '');
$period = 'Period: ' . date('M j, Y', strtotime($report['start'])) . ' to ' . date('M j, Y', strtotime($report['end']))
    . ($teacherLabel !== '' ? '   |   Teacher: ' . $teacherLabel : '')
    . '   |   Generated: ' . date('M j, Y g:i A');
$legend = 'P = Present, L = Late, A = Absent, E = Excused, blank = no record.  Attendance % counts a Late as half a day (Present 1, Late 0.5, Absent 0); Excused days are not counted.';

$fileBase = preg_replace('/[^A-Za-z0-9]+/', '_', $sectionLabel . '_' . $subjectLabel . '_' . $term);
$fileBase = 'Attendance_' . trim($fileBase, '_');

/** 0 -> A, 25 -> Z, 26 -> AA */
function attXCol($i)
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

function attXEsc($v)
{
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v);
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Cell xml. $v null = empty styled cell; numeric => number; else inline string. */
function attXCell($col, $row, $v, $style, $forceText = false)
{
    $ref = attXCol($col) . $row;
    if ($v === null || $v === '') {
        return '<c r="' . $ref . '" s="' . $style . '"/>';
    }
    if (!$forceText && (is_int($v) || is_float($v))) {
        return '<c r="' . $ref . '" s="' . $style . '"><v>' . $v . '</v></c>';
    }
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . attXEsc($v) . '</t></is></c>';
}

// Style ids (see styles.xml below)
const ATTX_TITLE = 1, ATTX_NOTE = 2, ATTX_HEAD = 3, ATTX_TEXT = 4, ATTX_CENTER = 5,
      ATTX_P = 6, ATTX_L = 7, ATTX_A = 8, ATTX_E = 9, ATTX_PCT = 10, ATTX_RISK = 11, ATTX_BLANK = 12, ATTX_OK = 13;

// ---- Sheet 1: attendance matrix
$rows = [];
$rows[1] = attXCell(0, 1, $title, ATTX_TITLE);
$rows[2] = attXCell(0, 2, $period, ATTX_NOTE);
$rows[3] = attXCell(0, 3, $legend, ATTX_NOTE);

$headers = ['No.', 'Student Name', 'LRN', 'Present', 'Late', 'Absent', 'Excused', 'Attendance %', 'Status'];
$hr = '';
foreach ($headers as $i => $h) {
    $hr .= attXCell($i, 5, $h, ATTX_HEAD);
}
foreach ($report['dates'] as $k => $d) {
    $hr .= attXCell(count($headers) + $k, 5, date('M j', strtotime($d)), ATTX_HEAD);
}
$rows[5] = $hr;

$r = 6;
$statusStyle = ['P' => ATTX_P, 'L' => ATTX_L, 'A' => ATTX_A, 'E' => ATTX_E];
foreach ($report['students'] as $n => $s) {
    $line = attXCell(0, $r, $n + 1, ATTX_CENTER)
        . attXCell(1, $r, $s['name'], ATTX_TEXT)
        . attXCell(2, $r, $s['lrn'], ATTX_TEXT, true)
        . attXCell(3, $r, $s['present'], ATTX_CENTER)
        . attXCell(4, $r, $s['late'], ATTX_CENTER)
        . attXCell(5, $r, $s['absent'], ATTX_CENTER)
        . attXCell(6, $r, $s['excused'], ATTX_CENTER)
        . ($s['pct'] === null ? attXCell(7, $r, '-', ATTX_CENTER) : attXCell(7, $r, (float) $s['pct'], ATTX_PCT))
        . attXCell(8, $r, $s['at_risk'] ? 'At risk' : ($s['pct'] === null ? '-' : 'OK'), $s['at_risk'] ? ATTX_RISK : ATTX_OK);
    foreach ($report['dates'] as $k => $d) {
        $c = $s['cells'][$d] ?? null;
        $line .= $c ? attXCell(count($headers) + $k, $r, $c['s'], $statusStyle[$c['s']] ?? ATTX_CENTER)
                    : attXCell(count($headers) + $k, $r, null, ATTX_BLANK);
    }
    $rows[$r++] = $line;
}
$lastRow = max($r - 1, 5);
$lastCol = attXCol(count($headers) + max(count($report['dates']), 1) - 1);

// Class totals row
$r++;
$t = $report['totals'];
$rows[$r] = attXCell(1, $r, 'Class totals (all students)', ATTX_HEAD) . attXCell(2, $r, '', ATTX_HEAD)
    . attXCell(3, $r, $t['Present'], ATTX_CENTER) . attXCell(4, $r, $t['Late'], ATTX_CENTER)
    . attXCell(5, $r, $t['Absent'], ATTX_CENTER) . attXCell(6, $r, $t['Excused'], ATTX_CENTER);

$sheetData1 = '';
ksort($rows);
foreach ($rows as $rn => $cells) {
    $sheetData1 .= '<row r="' . $rn . '"' . ($rn === 5 ? ' ht="22" customHeight="1"' : '') . '>' . $cells . '</row>';
}
$cols1 = '<cols><col min="1" max="1" width="6" customWidth="1"/><col min="2" max="2" width="34" customWidth="1"/>'
    . '<col min="3" max="3" width="16" customWidth="1"/><col min="4" max="7" width="9.5" customWidth="1"/>'
    . '<col min="8" max="8" width="14" customWidth="1"/><col min="9" max="9" width="10" customWidth="1"/>'
    . (count($report['dates']) ? '<col min="10" max="' . (9 + count($report['dates'])) . '" width="7.5" customWidth="1"/>' : '')
    . '</cols>';
$sheet1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
    . '<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane xSplit="3" ySplit="5" topLeftCell="D6" activePane="bottomRight" state="frozen"/>'
    . '<selection pane="topRight"/><selection pane="bottomLeft"/><selection pane="bottomRight" activeCell="D6" sqref="D6"/></sheetView></sheetViews>'
    . '<sheetFormatPr defaultRowHeight="15"/>' . $cols1
    . '<sheetData>' . $sheetData1 . '</sheetData>'
    . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
    . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
    . '</worksheet>';

// ---- Sheet 2: time-in log (one line per recorded day)
$viaLabel = ['qr' => 'QR scan', 'lrn' => 'Typed LRN', 'session_close' => 'Session ended (no scan)', null => 'Manual'];
$log = [];
foreach ($report['dates'] as $d) {
    foreach ($report['students'] as $s) {
        $c = $s['cells'][$d] ?? null;
        if ($c) {
            $log[] = [$d, $s['name'], $s['lrn'], $c['status'], $c['t'], $viaLabel[$c['v']] ?? (string) $c['v']];
        }
    }
}
$lr = [];
$lr[1] = attXCell(0, 1, 'Time-in log - ' . $sectionLabel . ' - ' . $subjectLabel . ' - ' . $term, ATTX_TITLE);
$h2 = '';
foreach (['Date', 'Student Name', 'LRN', 'Status', 'Time In', 'Recorded By'] as $i => $h) {
    $h2 .= attXCell($i, 3, $h, ATTX_HEAD);
}
$lr[3] = $h2;
$rn = 4;
$stStyle = ['Present' => ATTX_P, 'Late' => ATTX_L, 'Absent' => ATTX_A, 'Excused' => ATTX_E];
foreach ($log as $l) {
    $lr[$rn] = attXCell(0, $rn, date('M j, Y', strtotime($l[0])), ATTX_TEXT)
        . attXCell(1, $rn, $l[1], ATTX_TEXT)
        . attXCell(2, $rn, $l[2], ATTX_TEXT, true)
        . attXCell(3, $rn, $l[3], $stStyle[$l[3]] ?? ATTX_CENTER)
        . attXCell(4, $rn, $l[4] !== '' ? $l[4] : '-', ATTX_CENTER)
        . attXCell(5, $rn, $l[5], ATTX_TEXT);
    $rn++;
}
$sheetData2 = '';
foreach ($lr as $n => $cells) {
    $sheetData2 .= '<row r="' . $n . '">' . $cells . '</row>';
}
$sheet2 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/>'
    . '<selection pane="bottomLeft" activeCell="A4" sqref="A4"/></sheetView></sheetViews>'
    . '<sheetFormatPr defaultRowHeight="15"/>'
    . '<cols><col min="1" max="1" width="14" customWidth="1"/><col min="2" max="2" width="34" customWidth="1"/><col min="3" max="3" width="16" customWidth="1"/>'
    . '<col min="4" max="4" width="11" customWidth="1"/><col min="5" max="5" width="11" customWidth="1"/><col min="6" max="6" width="24" customWidth="1"/></cols>'
    . '<sheetData>' . $sheetData2 . '</sheetData>'
    . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/></worksheet>';

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0&quot;%&quot;"/></numFmts>'
    . '<fonts count="6">'
    . '<font><sz val="11"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="14"/><color rgb="FF14213D"/><name val="Calibri"/></font>'
    . '<font><i/><sz val="10"/><color rgb="FF5B6B7F"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="11"/><color rgb="FFC0303F"/><name val="Calibri"/></font>'
    . '</fonts>'
    . '<fills count="8">'
    . '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FF14213D"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFDDF3E7"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF0CC"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFFBDDE0"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCEAFB"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F4F7"/><bgColor indexed="64"/></patternFill></fill>'
    . '</fills>'
    . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
    . '<border><left style="thin"><color rgb="FFC9D3E0"/></left><right style="thin"><color rgb="FFC9D3E0"/></right>'
    . '<top style="thin"><color rgb="FFC9D3E0"/></top><bottom style="thin"><color rgb="FFC9D3E0"/></bottom><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="14">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                                                   // 0
    . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                                                     // 1 title
    . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                                                     // 2 note
    . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 3 head
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'                              // 4 text
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'          // 5 center
    . '<xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 6 P
    . '<xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 7 L
    . '<xf numFmtId="0" fontId="1" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 8 A
    . '<xf numFmtId="0" fontId="1" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 9 E
    . '<xf numFmtId="164" fontId="1" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 10 pct
    . '<xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 11 at risk
    . '<xf numFmtId="0" fontId="0" fillId="7" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'                                                                     // 12 blank
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'          // 13 ok
    . '</cellXfs>'
    . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
    . '</styleSheet>';

$files = [
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>',
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>',
    'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Attendance" sheetId="1" r:id="rId1"/><sheet name="Time-In Log" sheetId="2" r:id="rId2"/></sheets></workbook>',
    'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>',
    'xl/styles.xml' => $styles,
    'xl/worksheets/sheet1.xml' => $sheet1,
    'xl/worksheets/sheet2.xml' => $sheet2,
];

$tmp = class_exists('ZipArchive') ? tempnam(sys_get_temp_dir(), 'att') : false;
$zip = $tmp ? new ZipArchive() : null;
if ($zip && $zip->open($tmp, ZipArchive::OVERWRITE) === true) {
    foreach ($files as $name => $xml) {
        $zip->addFromString($name, $xml);
    }
    $zip->close();
    logActivity($teacherId, 'attendance', 'Exported term attendance', "$subjectCode / section $sectionId / $term (Excel)");
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileBase . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}
if ($tmp) {
    @unlink($tmp);
}

// No zip support on this server: CSV opens in Excel too.
logActivity($teacherId, 'attendance', 'Exported term attendance', "$subjectCode / section $sectionId / $term (CSV)");
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileBase . '.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, [$title]);
fputcsv($out, [$period]);
fputcsv($out, []);
fputcsv($out, array_merge($headers, array_map(function ($d) { return date('M j', strtotime($d)); }, $report['dates'])));
foreach ($report['students'] as $n => $s) {
    $line = [$n + 1, $s['name'], "'" . $s['lrn'], $s['present'], $s['late'], $s['absent'], $s['excused'],
        $s['pct'] === null ? '' : $s['pct'] . '%', $s['at_risk'] ? 'At risk' : 'OK'];
    foreach ($report['dates'] as $d) {
        $line[] = isset($s['cells'][$d]) && $s['cells'][$d] ? $s['cells'][$d]['s'] : '';
    }
    fputcsv($out, $line);
}
fclose($out);
