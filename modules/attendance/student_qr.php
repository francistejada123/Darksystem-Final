<?php
// modules/attendance/student_qr.php
// A student's personal attendance QR code: the QR itself, the LRN printed
// below it, and Return / Save QR Code buttons.
//
// Reached by clicking an LRN once the student's enrollment is approved
// (attQrLrnLink() in includes/attendance_qr_helpers.php) and from the Student
// Portal profile. The QR is drawn in the browser from a signed payload, so
// nothing is stored per student and it is generated automatically on open.
//
// Access: the student themself; Administrator / Registrar / Enrollment Officer;
// or a Teacher who teaches the student's section. Never before approval.
// Also public for a NEW applicant: enrollment_status.php links the approved
// applicant's LRN here as ?ref=<reference no.>. The reference number is what
// authorizes that view (same as the public tracker), and only once the
// application is Approved -- no login needed.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/attendance_qr_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

$public_ref = isset($_GET['ref']) ? trim((string) $_GET['ref']) : '';
$is_public = $public_ref !== '';
$is_staff = !$is_public && isLoggedIn();
$is_student_portal = !$is_public && !$is_staff && !empty($_SESSION['student_id']) && ($_SESSION['role'] ?? '') === 'Student';

if (!$is_public && !$is_staff && !$is_student_portal) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$fail = function ($message, $status = 403) use ($is_student_portal, $is_public, $public_ref) {
    http_response_code($status);
    if ($is_public) {
        $home = BASE_URL . 'enrollment_status.php' . (preg_match('/^[A-Za-z0-9-]{4,40}$/', $public_ref) ? '?ref=' . urlencode($public_ref) : '');
    } else {
        $home = BASE_URL . ($is_student_portal ? 'modules/students/portal/dashboard.php' : 'index.php');
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Student QR Code</title>'
        . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#eef1f5;font-family:system-ui,Segoe UI,Roboto,sans-serif;color:#243447}'
        . '.c{max-width:380px;margin:20px;padding:30px;background:#fff;border-radius:16px;box-shadow:0 8px 28px rgba(20,33,61,.1);text-align:center}'
        . 'a{display:inline-block;margin-top:14px;padding:9px 18px;border-radius:8px;background:#14213d;color:#fff;text-decoration:none;font-size:15px}</style></head>'
        . '<body><div class="c"><p style="margin:0;font-size:15px;line-height:1.5">' . htmlspecialchars($message) . '</p><a href="' . htmlspecialchars($home) . '">Go back</a></div></body></html>';
    exit;
};

$active_sy = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
$enrollment = null;

if ($is_public) {
    // New applicant: authorized by the reference number of an APPROVED application.
    header('Cache-Control: no-store');
    $app = preg_match('/^[A-Za-z0-9-]{4,40}$/', $public_ref)
        ? getRecord("SELECT student_id, status FROM online_applications WHERE reference_no = ?", [$public_ref])
        : null;
    if (!$app || $app['status'] !== 'Approved' || empty($app['student_id'])) {
        $fail('The QR code becomes available once your application has been approved.', 404);
    }
    $student_id = (int) $app['student_id'];
} elseif ($is_student_portal) {
    $student_id = (int) $_SESSION['student_id'];       // a student only ever sees their own
} else {
    $student_id = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;
    if (!hasAnyRole(['Administrator', 'Registrar', 'Enrollment Officer', 'Teacher'])) {
        $fail('You do not have access to this page.');
    }
}

$student = $student_id > 0 ? getRecord(
    "SELECT student_id, lrn, first_name, last_name, middle_name FROM students WHERE student_id = ? AND is_active = 1",
    [$student_id]
) : null;
if (!$student) {
    $fail('Student not found.', 404);
}

if (!$is_public) {
    // Only after the enrollment has been approved.
    $enrollment = $active_sy ? getRecord(
        "SELECT section_id FROM enrollments WHERE student_id = ? AND school_year_id = ? AND enrollment_status = 'Approved'",
        [$student_id, $active_sy['sy_id']]
    ) : null;
    if (!$enrollment) {
        $fail('The QR code becomes available once this student\'s enrollment has been approved.');
    }
    if (!$is_student_portal) {
        require_once __DIR__ . '/../../includes/eo_scope.php';
        $eo_row = getRecord("SELECT grade_level FROM enrollments WHERE student_id = ? AND school_year_id = ?", [$student_id, $active_sy['sy_id']]);
        if (!eoCanGrade($eo_row['grade_level'] ?? '')) $fail('You can only open students of your assigned grade level.');
    }

    // A teacher may only open the QR of a student in one of their own sections.
    if (!$is_student_portal && !hasAnyRole(['Administrator', 'Registrar', 'Enrollment Officer'])) {
        $mine = getRecord(
            "SELECT ts_id FROM teacher_subjects WHERE teacher_id = ? AND section_id = ? AND school_year_id = ? LIMIT 1",
            [$_SESSION['user_id'], $enrollment['section_id'], $active_sy['sy_id']]
        );
        if (!$mine) {
            $fail('This student is not in one of your sections.');
        }
    }
}

$display_name = strtoupper($student['last_name'] . ', ' . $student['first_name']
    . ($student['middle_name'] ? ' ' . attQrInitial($student['middle_name']) . '.' : ''));
$payload = attQrPayload($student['lrn']);

// Where "Return" goes when there is no page to go back to.
$fallback = $is_public
    ? BASE_URL . 'enrollment_status.php?ref=' . urlencode($public_ref)
    : BASE_URL . ($is_student_portal ? 'modules/students/portal/profile.php' : 'modules/students/index.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student QR Code — <?php echo htmlspecialchars($student['lrn']); ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #eef1f5; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #14213d; }
        .qr-card { width: min(360px, calc(100vw - 32px)); margin: 24px 16px; padding: 30px 26px 24px; background: #fff; border-radius: 18px; box-shadow: 0 10px 34px rgba(20,33,61,.12); text-align: center; }
        .qr-card canvas { display: block; width: 100%; max-width: 260px; height: auto; margin: 0 auto; image-rendering: pixelated; }
        .qr-lrn { margin: 16px 0 4px; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 22px; font-weight: 700; letter-spacing: .06em; }
        .qr-name { margin: 0 0 20px; font-size: 15px; font-weight: 700; color: #3a4b63; }
        .qr-note { margin: -8px 0 18px; font-size: 13px; color: #8593a6; line-height: 1.45; }
        .qr-actions { display: flex; gap: 10px; justify-content: center; }
        .qr-actions button, .qr-actions a { flex: 1; padding: 10px 12px; border-radius: 9px; border: 1px solid #c9d3e0; background: #f6f8fb; color: #14213d; font-size: 15px; font-weight: 650; cursor: pointer; text-decoration: none; font-family: inherit; }
        .qr-actions button.primary { background: #14213d; border-color: #14213d; color: #fff; }
        .qr-actions button:hover, .qr-actions a:hover { filter: brightness(.96); }
        .qr-err { color: #c0303f; font-size: 14.5px; }
    </style>
</head>
<body>
    <main class="qr-card">
        <canvas id="qrCanvas" width="260" height="260" aria-label="QR code for LRN <?php echo htmlspecialchars($student['lrn']); ?>"></canvas>
        <div class="qr-lrn" id="qrLrn"><?php echo htmlspecialchars($student['lrn']); ?></div>
        <p class="qr-name"><?php echo htmlspecialchars($display_name); ?></p>
        <p class="qr-note">Show this QR code to your teacher's scanner to record your attendance. Each student has one unique code.</p>
        <p class="qr-err" id="qrErr" hidden>The QR code could not be drawn. Please reload the page.</p>
        <div class="qr-actions">
            <button type="button" id="btnReturn">Return</button>
            <button type="button" id="btnSave" class="primary">Save QR Code</button>
        </div>
    </main>

    <script src="<?php echo BASE_URL; ?>static/js/vendor/qrcode.min.js"></script>
    <script>
    (function () {
        var PAYLOAD = <?php echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var LRN = <?php echo json_encode((string) $student['lrn'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var NAME = <?php echo json_encode($display_name, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
        var FALLBACK = <?php echo json_encode($fallback, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        // Draw the QR (with a 4-module quiet zone, as scanners expect) onto any canvas.
        function drawQr(canvas, size, x, y) {
            var qr = qrcode(0, 'M');
            qr.addData(PAYLOAD);
            qr.make();
            var n = qr.getModuleCount(), quiet = 4, total = n + quiet * 2;
            var cell = Math.floor(size / total);
            var px = cell * total, ox = x + Math.floor((size - px) / 2), oy = y + Math.floor((size - px) / 2);
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff'; ctx.fillRect(x, y, size, size);
            ctx.fillStyle = '#000000';
            for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) {
                if (qr.isDark(r, c)) ctx.fillRect(ox + (c + quiet) * cell, oy + (r + quiet) * cell, cell, cell);
            }
        }

        var view = document.getElementById('qrCanvas');
        try {
            // Render at a high internal resolution so it stays sharp when scaled.
            view.width = view.height = 520;
            drawQr(view, 520, 0, 0);
        } catch (e) {
            document.getElementById('qrErr').hidden = false;
        }

        document.getElementById('btnReturn').addEventListener('click', function () {
            var sameOrigin = document.referrer && document.referrer.indexOf(location.origin) === 0;
            if (sameOrigin && history.length > 1) history.back(); else location.href = FALLBACK;
        });

        // Save QR Code: a PNG with the QR, the LRN and the student's name.
        document.getElementById('btnSave').addEventListener('click', function () {
            try {
                var W = 640, H = 770, out = document.createElement('canvas');
                out.width = W; out.height = H;
                var ctx = out.getContext('2d');
                ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, W, H);
                drawQr(out, 560, 40, 40);
                ctx.fillStyle = '#14213d'; ctx.textAlign = 'center';
                ctx.font = 'bold 46px Consolas, "Courier New", monospace';
                ctx.fillText(LRN, W / 2, 665);
                ctx.font = 'bold 28px Arial, sans-serif';
                ctx.fillStyle = '#3a4b63';
                ctx.fillText(NAME, W / 2, 715);
                out.toBlob(function (blob) {
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = 'QR_' + LRN + '.png';
                    document.body.appendChild(a); a.click(); a.remove();
                    setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
                }, 'image/png');
            } catch (e) {
                document.getElementById('qrErr').hidden = false;
            }
        });
    })();
    </script>
</body>
</html>
