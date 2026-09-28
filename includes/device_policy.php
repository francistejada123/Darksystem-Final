<?php
// includes/device_policy.php
// Where each part of EGradePro may be opened:
//   - Administrator / Registrar / Teacher portals: desktop or laptop only
//   - Student Portal, Student Login, the approval-email access link,
//     Online Enrollment and Enrollment Tracking: phones too
// Loaded by config/database.php, so it runs before every page. A phone that
// opens an Admin/Teacher page gets a short explanation with the links it CAN
// use. Set DESKTOP_ONLY=0 (environment / Apache SetEnv) to switch it off.
//
// Detection is by the browser's User-Agent. It keeps staff pages off phones in
// normal use; it is not a security boundary (the logins still protect them).

function devIsPhoneOrTablet()
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return $ua !== '' && preg_match('/Android|iPhone|iPad|iPod|Mobile|Windows Phone|BlackBerry|BB10|Opera Mini|IEMobile|Silk|Kindle|webOS/i', $ua);
}

/** Pages a phone may open (paths under BASE_URL). */
function devMobileAllowed($path)
{
    $base = defined('BASE_URL') ? BASE_URL : '/';
    $rel = strpos($path, $base) === 0 ? substr($path, strlen($base)) : ltrim($path, '/');
    $allowPrefixes = [
        'modules/students/portal/',          // Student Portal (dashboard, classwork, grades, ...)
        'student/',                          // approval-email access link
        'parent/',                           // Parent Portal (phones first)
    ];
    $allowFiles = [
        'student_login.php', 'online_enrollment.php', 'enrollment_status.php', 'logout.php',
        'teacher_register.php', 'm.php', 'parent_login.php',    // Parent Portal short link / login                                   // invited teacher's registration link (from email)
        'modules/attendance/student_qr.php',                      // QR code from the tracking page
        'modules/ai/download_resource.php', 'modules/ai/view_resource.php',   // student's own AI activities
        'modules/ai/download_submission_answer.php', 'modules/ai/view_submission_answer.php',
        'modules/tutorial/view_material.php',                     // learning materials
        'modules/teachers/classroom_download.php',                // class announcement files (own login check)
    ];
    foreach ($allowPrefixes as $p) {
        if (strpos($rel, $p) === 0) return true;
    }
    return in_array($rel, $allowFiles, true);
}

function devEnforceDesktopOnly()
{
    if (PHP_SAPI === 'cli' || getenv('DESKTOP_ONLY') === '0') return;
    if (!devIsPhoneOrTablet()) return;
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (devMobileAllowed($path)) return;

    $base = defined('BASE_URL') ? BASE_URL : '/';
    $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    http_response_code(403);
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'This part of EGradePro is available on a desktop or laptop computer only.']);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    $b = htmlspecialchars($base, ENT_QUOTES);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>Desktop only - EGradePro</title>'
        . '<style>body{margin:0;background:#eef2f7;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1f2937}'
        . '.box{max-width:440px;margin:40px auto;background:#fff;border-radius:16px;box-shadow:0 10px 40px rgba(13,71,161,.12);padding:28px 22px;text-align:center}'
        . 'h1{font-size:20px;margin:12px 0 8px}p{color:#64748b;font-size:14px;line-height:1.5}'
        . 'a.btn{display:block;margin:10px 0;padding:12px;border-radius:10px;background:#1565c0;color:#fff;text-decoration:none;font-weight:700}'
        . 'a.btn.alt{background:#fff;color:#1565c0;border:1px solid #cbd5e1}.ic{font-size:44px}</style></head><body>'
        . '<div class="box"><div class="ic">&#128421;&#65039;</div>'
        . '<h1>Please use a desktop or laptop</h1>'
        . '<p>The Administrator, Registrar and Teacher portals of EGradePro are available on a desktop or laptop computer only.</p>'
        . '<p>On your phone you can use:</p>'
        . '<a class="btn" href="' . $b . 'student_login.php">Student Portal</a>'
        . '<a class="btn alt" href="' . $b . 'online_enrollment.php">Online Enrollment</a>'
        . '<a class="btn alt" href="' . $b . 'enrollment_status.php">Track my Enrollment</a>'
        . '</div></body></html>';
    exit;
}

devEnforceDesktopOnly();
