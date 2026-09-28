<?php
// modules/parents/index.php -- Parents list (Administrator / Registrar): who is
// linked to which child, last sign-in. Never shows a PIN.

require_once __DIR__ . '/common.php';
if (!hasAnyRole(['Administrator', 'Registrar'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}
ppEnsureSchema();
$page_title = 'Parents';
$current_page = 'parents';
$base_url = BASE_URL;
$q = trim((string) ($_GET['q'] ?? ''));
$params = [];
$where = '1=1';
if ($q !== '') {
    $where = "(p.full_name LIKE ? OR p.mobile LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ?)";
    $like = '%' . $q . '%';
    $params = [$like, '%' . preg_replace('/\D/', '', $q) . '%', $like, $like, $like];
}
$rows = getRecords(
    "SELECT p.parent_id, p.mobile, p.full_name, p.last_login, p.created_at, p.is_active,
            ps.relationship, ps.linked_via, s.student_id, s.first_name, s.last_name, s.grade_level
       FROM parents p
       JOIN parent_students ps ON ps.parent_id = p.parent_id
       JOIN students s ON s.student_id = ps.student_id
      WHERE $where
      ORDER BY p.created_at DESC, s.last_name LIMIT 300", $params);
$totals = getRecord("SELECT (SELECT COUNT(*) FROM parents) AS parents,
                            (SELECT COUNT(DISTINCT student_id) FROM parent_students) AS kids,
                            (SELECT COUNT(*) FROM students WHERE is_active = 1) AS students");
include __DIR__ . '/../../includes/header.php';
?>
<style>
    .pl h4 { font-size: 26px; font-weight: 700; }
    .pl .card-x { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; margin-bottom: 18px; }
    .pl .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 18px; }
    .pl .stat { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 16px; }
    .pl .stat b { font-size: 26px; display: block; }
    .pl table { width: 100%; border-collapse: collapse; }
    .pl th { background: var(--surface-hover); padding: 12px 16px; font-size: 14px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); text-align: left; }
    .pl td { padding: 13px 16px; border-top: 1px solid var(--border-soft); font-size: 15px; }
    .pl td small { display: block; color: var(--text-muted); font-size: 14.5px; }
</style>
<div class="pl" style="padding:24px">
    <h4><i class="fas fa-people-roof me-2"></i>Parents</h4>
    <p class="text-muted" style="font-size:15px">Parent Portal accounts (mobile number + PIN) and the children they can see. Set up from the student record (Send SMS / Print Slip).</p>
    <div class="stats">
        <div class="stat"><b><?php echo (int) $totals['parents']; ?></b>Parent accounts</div>
        <div class="stat"><b><?php echo (int) $totals['kids']; ?></b>Students with a linked parent</div>
        <div class="stat"><b><?php echo max(0, (int) $totals['students'] - (int) $totals['kids']); ?></b>Students without a parent yet</div>
    </div>
    <form class="d-flex gap-2 mb-3"><input class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search parent, mobile, student or LRN"><button class="btn btn-dark">Search</button></form>
    <div class="card-x"><div style="overflow-x:auto">
        <table>
            <tr><th>Parent</th><th>Mobile</th><th>Child</th><th>Linked via</th><th>Last sign-in</th><th></th></tr>
            <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No parent accounts yet.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['full_name'] ?: '—'); ?><small><?php echo htmlspecialchars($r['relationship'] ?: ''); ?></small></td>
                    <td><?php echo htmlspecialchars(smsMask($r['mobile'])); ?></td>
                    <td><?php echo htmlspecialchars($r['last_name'] . ', ' . $r['first_name']); ?><small><?php echo htmlspecialchars($r['grade_level']); ?></small></td>
                    <td><?php echo htmlspecialchars(ucfirst($r['linked_via'] ?: '—')); ?></td>
                    <td><?php echo $r['last_login'] ? date('M j, Y g:i A', strtotime($r['last_login'])) : 'Never'; ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="../students/view.php?id=<?php echo (int) $r['student_id']; ?>">Student record</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
