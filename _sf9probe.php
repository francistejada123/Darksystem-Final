<?php
// TEMPORARY harness: assert the SF9 two-page FRONT/BACK split.
require 'config/database.php';
require 'includes/auth.php';
require 'modules/reports/report_functions.php';
require 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$student = findStudentByIdentifier((int) ($argv[1] ?? 95));
if (!$student) {
   fwrite(STDERR, "no student\n");
   exit(1);
}
$sy = rpActiveSy();
list($body) = rpSf9CardBody($student, $sy);

$extraCss = '';
$body = preg_replace_callback(
   '#<style>(.*?)</style>#s',
   function ($m) use (&$extraCss) {
      $extraCss .= $m[1];
      return ''; },
   $body
);
$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $extraCss . '</style>'
   . 'body{font-family:DejaVu Sans,sans-serif;font-size:12.5px;color:#172033;margin:0;}'
   . '</head><body>' . $body . '</body></html>';

$o = new Options();
$o->set('isHtml5ParserEnabled', true);
$o->set('isRemoteEnabled', false);
$o->set('defaultFont', 'DejaVu Sans');
$o->set('chroot', realpath(__DIR__));

$d = new Dompdf($o);
$d->loadHtml($html);
$d->setPaper('A4', 'portrait');
$d->render();
file_put_contents(__DIR__ . '/_sf9_out.pdf', $d->output());

echo 'student : ' . $student['lrn'] . PHP_EOL;
echo 'pages   : ' . $d->getCanvas()->get_page_count() . PHP_EOL;

// Order of the sections inside the rendered markup.
$iInfo = strpos($body, 'Student Personal Information');
$iAtt = strpos($body, 'Report on Attendance');
$iSign = strpos($body, 'Class Adviser');
$iGrade = strpos($body, 'Report on Learning Progress');
$iGA = strpos($body, 'General Average');

echo PHP_EOL . 'section order in markup:' . PHP_EOL;
echo '  personal info @ ' . $iInfo . PHP_EOL;
echo '  attendance     @ ' . $iAtt . PHP_EOL;
echo '  signatures     @ ' . $iSign . PHP_EOL;
echo '  grades         @ ' . $iGrade . PHP_EOL;
echo '  general avg    @ ' . $iGA . PHP_EOL;
echo PHP_EOL;
echo 'front page ends at signatures (before grades): '
   . ($iSign < $iGrade ? 'yes' : 'NO') . PHP_EOL;
echo 'page-break-after always declared: '
   . (strpos($extraCss, 'page-break-after: always') !== false ? 'yes' : 'NO') . PHP_EOL;
echo 'page-break-before always declared: '
   . (strpos($extraCss, 'page-break-before: always') !== false ? 'yes' : 'NO') . PHP_EOL;