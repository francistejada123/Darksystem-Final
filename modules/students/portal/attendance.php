<?php
// modules/students/portal/attendance.php
// Student Portal - Attendance: the student's own attendance records (what the
// teachers marked / scanned) and the QR code they show to the teacher's scanner.

session_start();
require_once '../../../config/database.php';
require_once '../../../includes/student_portal_data.php';
require_once '../../../includes/student_portal_page.php';

list($student_id, $student, $profile_image) = spRequireStudent();
$ctx = spdContext($student_id);
$sum = spdAttendance($student_id, $ctx['enrollment']['section_id'] ?? null);
$names = [];
foreach ($ctx['classes'] as $c) $names[$c['subject_code']] = $c['subject_name'];
$rows = getRecords(
    "SELECT subject_code, date, status, time_in FROM attendance WHERE student_id = ?" . (!empty($ctx['enrollment']['section_id']) ? " AND section_id = " . (int) $ctx['enrollment']['section_id'] : '') . " ORDER BY date DESC, time_in DESC LIMIT 60",
    [$student_id]
);
$cls = ['Present' => 'ok', 'Late' => 'warn', 'Absent' => 'bad', 'Excused' => 'info'];

spPageStart('Attendance', $sum['rate'] !== null ? $sum['rate'] . '% attendance · ' . $sum['total'] . ' record(s)' : 'No attendance records yet', $student, $profile_image);
?>
            <div class="sp-stats">
                <div class="stat-card"><h6>Rate</h6><div class="stat-value"><?php echo $sum['rate'] !== null ? $sum['rate'] . '%' : '—'; ?></div></div>
                <div class="stat-card"><h6>Present</h6><div class="stat-value" style="color:var(--portal-green)"><?php echo $sum['Present']; ?></div></div>
                <div class="stat-card"><h6>Late</h6><div class="stat-value" style="color:var(--portal-yellow)"><?php echo $sum['Late']; ?></div></div>
                <div class="stat-card"><h6>Absent</h6><div class="stat-value" style="color:var(--portal-red)"><?php echo $sum['Absent']; ?></div></div>
            </div>
            <a class="sp-item mb-3" href="../../attendance/student_qr.php">
                <span class="sp-item-icon"><i class="fas fa-qrcode"></i></span>
                <span class="sp-item-main"><strong>My QR Code</strong><small>Show it to your teacher's scanner to record your attendance.</small></span>
                <i class="fas fa-chevron-right sp-go"></i>
            </a>
            <?php if (!$rows): ?>
                <div class="card-portal"><div class="empty-state"><i class="fas fa-calendar-check"></i><h5>No attendance records yet</h5></div></div>
            <?php else: ?>
                <div class="sp-list">
                    <?php foreach ($rows as $r): ?>
                        <div class="sp-item">
                            <span class="sp-item-icon"><i class="fas fa-calendar-day"></i></span>
                            <span class="sp-item-main">
                                <strong><?php echo htmlspecialchars($names[$r['subject_code']] ?? $r['subject_code']); ?></strong>
                                <small><?php echo date('D, M j, Y', strtotime($r['date'])); ?><?php echo $r['time_in'] ? ' · ' . date('g:i A', strtotime($r['time_in'])) : ''; ?></small>
                            </span>
                            <span class="sp-tag <?php echo $cls[$r['status']] ?? ''; ?>"><?php echo htmlspecialchars($r['status']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
<?php spPageEnd();
