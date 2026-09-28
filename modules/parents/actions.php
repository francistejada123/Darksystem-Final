<?php
// modules/parents/actions.php (POST)
//   send_sms   : new setup code texted to the guardian number (or shown for staff to text)
//   unlink     : remove a parent from this child
//   reset_pin  : "Reset PIN" slip for a linked parent (they choose the new PIN)
//   revoke     : cancel all unused setup codes of this child
// Staff never see or set a PIN.

session_start();
require_once __DIR__ . '/common.php';

$sid = (int) ($_POST['student_id'] ?? 0);
$back = $_POST['back'] ?? ('../students/view.php?id=' . $sid);
if (!preg_match('#^\.\./(students|parents)/[a-z_]+\.php(\?[\w=&%-]*)?$#', $back)) $back = '../students/view.php?id=' . $sid;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '') || !$sid || !ppStaffCanStudent($sid)) {
    $_SESSION['pp_flash'] = ['danger', 'Not allowed or the request expired. Please try again.'];
    header('Location: ' . $back);
    exit;
}
ppEnsureSchema();
$st = ppStudentCard($sid);
$uid = (int) $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action === 'send_sms') {
    $num = smsNormalizeNumber($_POST['mobile'] ?? $st['guardian_contact'] ?? '');
    if ($num === '') {
        $_SESSION['pp_flash'] = ['danger', 'No valid parent/guardian mobile number. Add it in Edit, or print a slip instead.'];
    } else {
        $code = ppIssueCode($sid, 'sms', $num, $uid);
        $text = ppWelcomeText($st, $code);
        $r = smsSend($num, $text, ['kind' => 'welcome', 'student_id' => $sid, 'created_by' => $uid]);
        logActivity($uid, 'parents', 'Parent Portal SMS', "{$st['last_name']}, {$st['first_name']} -> " . smsMask($num) . " ({$r['status']})");
        $_SESSION['pp_flash'] = $r['success']
            ? ['success', 'Parent Portal SMS sent to ' . smsMask($num) . '.']
            : ['warning', 'SMS not sent automatically (' . rtrim($r['error'] ?: 'no SMS sender', '.') . '). Send this text from your phone to ' . $num . ':', $text];
    }
} elseif ($action === 'unlink') {
    $pid = (int) ($_POST['parent_id'] ?? 0);
    executeQuery("DELETE FROM parent_students WHERE parent_id = ? AND student_id = ?", [$pid, $sid]);
    logActivity($uid, 'parents', 'Unlinked parent', "Parent #$pid from {$st['last_name']}, {$st['first_name']}");
    $_SESSION['pp_flash'] = ['success', 'The parent no longer has access to this child.'];
} elseif ($action === 'reset_pin') {
    $pid = (int) ($_POST['parent_id'] ?? 0);
    $p = getRecord("SELECT p.mobile FROM parents p JOIN parent_students ps ON ps.parent_id = p.parent_id AND ps.student_id = ? WHERE p.parent_id = ?", [$sid, $pid]);
    if ($p) {
        $code = ppIssueCode($sid, 'reset', $p['mobile'], $uid);
        logActivity($uid, 'parents', 'Parent PIN reset slip', 'Parent ' . smsMask($p['mobile']) . " of {$st['last_name']}, {$st['first_name']}");
        $_SESSION['pp_slip_codes'] = [$sid => $code];
        header('Location: print_slips.php?student_id=' . $sid . '&reset=1');
        exit;
    }
} elseif ($action === 'revoke') {
    ppRevokeCodes($sid);
    logActivity($uid, 'parents', 'Revoked parent setup codes', "{$st['last_name']}, {$st['first_name']}");
    $_SESSION['pp_flash'] = ['success', 'All unused setup codes of this child were cancelled.'];
}
header('Location: ' . $back);
