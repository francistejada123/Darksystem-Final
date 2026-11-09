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
$where = 'p.is_active = 1';
if ($q !== '') {
    $where .= " AND (p.full_name LIKE ? OR p.mobile LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ?)";
    $like = '%' . $q . '%';
    $digits = preg_replace('/\D/', '', $q);
    $params = [$like, $digits !== '' ? '%' . $digits . '%' : '-', $like, $like, $like];   // a name search must not match every mobile
}
ppEmailEnsureSchema();
$rows = getRecords(
    "SELECT p.parent_id, p.mobile, p.email, p.email_verified_at, p.full_name, p.last_login, p.created_at, p.is_active,
            ps.relationship, ps.linked_via, s.student_id, s.first_name, s.last_name, s.grade_level, s.lrn,
            (SELECT sec.section_name
               FROM enrollments e JOIN sections sec ON sec.section_id = e.section_id
              WHERE e.student_id = s.student_id
                AND e.school_year_id = (SELECT sy_id FROM school_years WHERE is_active = 1)
              ORDER BY e.enrollment_id DESC LIMIT 1) AS section_name
       FROM parents p
       LEFT JOIN parent_students ps ON ps.parent_id = p.parent_id
       LEFT JOIN students s ON s.student_id = ps.student_id
      WHERE $where
      ORDER BY p.created_at DESC, s.last_name LIMIT 300", $params);
// Accounts without a child are listed too (e.g. the child's record was deleted); the parent can
// sign in and use "Add a child".
$totals = getRecord("SELECT (SELECT COUNT(*) FROM parents WHERE is_active = 1) AS parents,
                            (SELECT COUNT(DISTINCT ps.student_id) FROM parent_students ps JOIN students s ON s.student_id = ps.student_id AND s.is_active = 1) AS kids,
                            (SELECT COUNT(*) FROM students WHERE is_active = 1) AS students");
$parent_details = [];
foreach ($rows as $r) {
    $parent_id = (int) $r['parent_id'];
    if (!isset($parent_details[$parent_id])) {
        $parent_details[$parent_id] = [
            'name' => (string) ($r['full_name'] ?? ''),
            'mobile' => (string) ($r['mobile'] ?? ''),
            'email' => (string) ($r['email'] ?? ''),
            'is_active' => (int) $r['is_active'],
            'last_login' => !empty($r['last_login']) ? date('M j, Y g:i A', strtotime($r['last_login'])) : '',
            'linked_via' => [],
            'children' => [],
        ];
    }
    if (!empty($r['student_id'])) {
        if (!empty($r['linked_via']) && !in_array((string) $r['linked_via'], $parent_details[$parent_id]['linked_via'], true)) {
            $parent_details[$parent_id]['linked_via'][] = (string) $r['linked_via'];
        }
        $parent_details[$parent_id]['children'][] = [
            'name' => trim((string) $r['last_name'] . ', ' . (string) $r['first_name'], ', '),
            'lrn' => (string) ($r['lrn'] ?? ''),
            'grade' => (string) ($r['grade_level'] ?? ''),
            'section' => (string) ($r['section_name'] ?? ''),
            'relationship' => (string) ($r['relationship'] ?? ''),
            'linked_via' => (string) ($r['linked_via'] ?? ''),
        ];
    }
}
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
    .pl th:last-child { text-align: center; }
    .pl .actions-cell { vertical-align: middle; white-space: nowrap; }
    .pl .parent-actions { display: inline-flex; align-items: center; justify-content: center; gap: 7px; }
    .pl .parent-actions .btn { display: inline-flex; align-items: center; justify-content: center; gap: 5px; min-height: 32px; padding: 5px 9px; border-radius: 7px; font-size: 13px; line-height: 1.2; }
    .parent-details-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .parent-detail { padding: 10px 12px; background: var(--surface-hover); border: 1px solid var(--border-soft); border-radius: 8px; min-width: 0; }
    .parent-detail small { display: block; margin-bottom: 3px; color: var(--text-muted); }
    .parent-detail-value { overflow-wrap: anywhere; }
    .parent-children { margin-top: 18px; }
    .parent-children table { min-width: 520px; }
    .parent-delete-warning { padding: 12px; border-radius: 8px; }
    @media (max-width: 600px) {
        .parent-details-grid { grid-template-columns: 1fr; }
        .pl .parent-actions { gap: 5px; }
        .pl .parent-actions .btn { padding: 5px 7px; font-size: 12px; }
    }
</style>
<div class="pl" style="padding:24px">
    <h4><i class="fas fa-people-roof me-2"></i>Parents</h4>
    <p class="text-muted" style="font-size:15px">Parent Portal accounts (mobile number or email + PIN; the email also gets the Forgot PIN code) and the children they can see. Set up from the student record (Send SMS / Print Slip).</p>
    <?php if (!empty($_SESSION['pp_flash'])): $ppf = $_SESSION['pp_flash']; unset($_SESSION['pp_flash']); ?>
        <div class="alert alert-<?php echo htmlspecialchars($ppf[0], ENT_QUOTES, 'UTF-8'); ?> py-2" role="status"><?php echo htmlspecialchars($ppf[1], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <div class="stats">
        <div class="stat"><b><?php echo (int) $totals['parents']; ?></b>Parent accounts</div>
        <div class="stat"><b><?php echo (int) $totals['kids']; ?></b>Students with a linked parent</div>
        <div class="stat"><b><?php echo max(0, (int) $totals['students'] - (int) $totals['kids']); ?></b>Students without a parent yet</div>
    </div>
    <form class="d-flex gap-2 mb-3"><input class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search parent, mobile, student or LRN"><button class="btn btn-dark">Search</button></form>
    <div class="card-x"><div style="overflow-x:auto">
        <table>
            <tr><th>Parent</th><th>Mobile</th><th>Email</th><th>Child</th><th>Linked via</th><th>Last sign-in</th><th>Actions</th></tr>
            <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No parent accounts yet.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['full_name'] ?: '—'); ?><small><?php echo htmlspecialchars($r['relationship'] ?: ''); ?></small></td>
                    <td><?php echo htmlspecialchars(smsMask($r['mobile'])); ?></td>
                    <td><?php echo !empty($r['email']) ? '<span class="badge bg-success">Yes</span><small>' . htmlspecialchars(ppMaskEmail($r['email'])) . '</small>' : '<span class="badge bg-secondary">None</span>'; ?></td>
                    <td><?php if ($r['student_id']): ?><?php echo htmlspecialchars($r['last_name'] . ', ' . $r['first_name']); ?><small><?php echo htmlspecialchars($r['grade_level']); ?></small>
                        <?php else: ?><span class="badge bg-warning text-dark">No child linked</span><small>The child's record was removed, or no child was added yet.</small><?php endif; ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($r['linked_via'] ?: '—')); ?></td>
                    <td><?php echo $r['last_login'] ? date('M j, Y g:i A', strtotime($r['last_login'])) : 'Never'; ?></td>
                    <td class="actions-cell"><div class="parent-actions">
                        <button type="button" class="btn btn-sm btn-primary js-parent-view" data-parent-id="<?php echo (int) $r['parent_id']; ?>"><i class="fas fa-eye" aria-hidden="true"></i><span>View</span></button>
                        <button type="button" class="btn btn-sm btn-danger js-parent-delete" data-parent-id="<?php echo (int) $r['parent_id']; ?>" data-parent-name="<?php echo htmlspecialchars($r['full_name'] ?: $r['mobile'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-trash" aria-hidden="true"></i><span>Delete</span></button>
                        <?php if ($r['student_id']): ?><a class="btn btn-sm btn-outline-primary" href="../students/view.php?id=<?php echo (int) $r['student_id']; ?>">Student record</a><?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div></div>
</div>
<div class="modal fade" id="parentViewModal" tabindex="-1" aria-labelledby="parentViewTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="parentViewTitle"><i class="fas fa-user me-2 text-primary"></i>Parent Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="parent-details-grid" id="parentDetailFields"></div>
                <div class="parent-children" id="parentDetailChildren"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>
<div class="modal fade" id="parentDeleteModal" tabindex="-1" aria-labelledby="parentDeleteTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="parentDeleteTitle"><i class="fas fa-user-xmark me-2 text-danger"></i>Delete Parent Account</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <p>Selected parent: <strong id="parentDeleteName"></strong></p>
                <div id="parentDeleteChoices">
                    <div class="alert alert-warning parent-delete-warning">Soft delete will deactivate this parent account but keep its record for history and possible recovery. The student record will not be deleted.</div>
                    <button type="button" class="btn btn-warning w-100 mb-3" id="chooseSoftDelete">Soft Delete</button>
                    <div class="alert alert-danger parent-delete-warning">Permanent deletion cannot be undone. This will permanently remove the selected parent account. The linked student record and academic records will remain unchanged.</div>
                    <button type="button" class="btn btn-danger w-100" id="choosePermanentDelete">Permanently Delete</button>
                </div>
                <div id="parentDeleteConfirmation" class="d-none">
                    <div class="alert mb-3 parent-delete-warning" id="parentDeleteConfirmWarning"></div>
                    <p id="parentDeleteConfirmPrompt"></p>
                    <button type="button" class="btn btn-outline-secondary" id="backToDeleteChoices">Back</button>
                    <button type="button" class="btn ms-2" id="confirmParentDelete"></button>
                </div>
            </div>
            <form method="post" action="manage.php" id="parentDeleteForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="parent_id" id="deleteParentId">
                <input type="hidden" name="action" id="deleteParentAction">
                <input type="hidden" name="confirmation" id="deleteParentConfirmationText">
                <input type="hidden" name="q" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const parentDetails = <?php echo json_encode($parent_details, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const viewModal = new bootstrap.Modal(document.getElementById('parentViewModal'));
    const deleteModal = new bootstrap.Modal(document.getElementById('parentDeleteModal'));
    const detailFields = document.getElementById('parentDetailFields');
    const detailChildren = document.getElementById('parentDetailChildren');
    const deleteChoices = document.getElementById('parentDeleteChoices');
    const deleteConfirmation = document.getElementById('parentDeleteConfirmation');
    const deleteForm = document.getElementById('parentDeleteForm');

    function addDetail(label, value) {
        const item = document.createElement('div');
        item.className = 'parent-detail';
        const title = document.createElement('small');
        title.textContent = label;
        const content = document.createElement('div');
        content.className = 'parent-detail-value';
        content.textContent = value || '—';
        item.append(title, content);
        detailFields.append(item);
    }

    document.querySelectorAll('.js-parent-view').forEach(function (button) {
        button.addEventListener('click', function () {
            const parent = parentDetails[button.dataset.parentId];
            if (!parent) return;
            detailFields.replaceChildren();
            detailChildren.replaceChildren();
            addDetail('Parent Name', parent.name);
            addDetail('Mobile Number', parent.mobile);
            addDetail('Email', parent.email);
            addDetail('Linked Via', parent.linked_via.join(', '));
            addDetail('Account Status', Number(parent.is_active) === 1 ? 'Active' : 'Inactive');
            addDetail('Last Sign-In', parent.last_login || 'Never');

            const heading = document.createElement('h6');
            heading.className = 'mb-2';
            heading.textContent = 'Linked Child/Children';
            detailChildren.append(heading);
            if (!parent.children.length) {
                const empty = document.createElement('p');
                empty.className = 'text-muted mb-0';
                empty.textContent = 'No child linked.';
                detailChildren.append(empty);
            } else {
                const tableWrap = document.createElement('div');
                tableWrap.className = 'table-responsive';
                const table = document.createElement('table');
                table.className = 'table table-sm table-bordered align-middle mb-0';
                const head = document.createElement('thead');
                const headerRow = document.createElement('tr');
                ['Child', 'LRN', 'Grade Level', 'Section', 'Relationship', 'Linked Via'].forEach(function (label) {
                    const cell = document.createElement('th');
                    cell.textContent = label;
                    headerRow.append(cell);
                });
                head.append(headerRow);
                const body = document.createElement('tbody');
                parent.children.forEach(function (child) {
                    const row = document.createElement('tr');
                    [child.name, child.lrn, child.grade, child.section, child.relationship, child.linked_via].forEach(function (value) {
                        const cell = document.createElement('td');
                        cell.textContent = value || '—';
                        row.append(cell);
                    });
                    body.append(row);
                });
                table.append(head, body);
                tableWrap.append(table);
                detailChildren.append(tableWrap);
            }
            viewModal.show();
        });
    });

    document.querySelectorAll('.js-parent-delete').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('deleteParentId').value = button.dataset.parentId;
            document.getElementById('parentDeleteName').textContent = button.dataset.parentName;
            document.getElementById('deleteParentAction').value = '';
            deleteChoices.classList.remove('d-none');
            deleteConfirmation.classList.add('d-none');
            deleteModal.show();
        });
    });

    function showDeleteConfirmation(action) {
        const soft = action === 'soft_delete';
        document.getElementById('deleteParentAction').value = action;
        document.getElementById('parentDeleteConfirmWarning').className = 'alert mb-3 parent-delete-warning ' + (soft ? 'alert-warning' : 'alert-danger');
        document.getElementById('parentDeleteConfirmWarning').textContent = soft
            ? 'Soft delete will deactivate this parent account but keep its record for history and possible recovery. The student record will not be deleted.'
            : 'Permanent deletion cannot be undone. This will permanently remove the selected parent account. The linked student record and academic records will remain unchanged.';
        document.getElementById('parentDeleteConfirmPrompt').textContent = soft
            ? 'Confirm that you want to deactivate this parent account.'
            : 'Are you sure you want to permanently delete this parent account?';
        const confirmButton = document.getElementById('confirmParentDelete');
        confirmButton.textContent = soft ? 'Confirm Soft Delete' : 'Confirm Permanent Delete';
        confirmButton.className = 'btn ms-2 ' + (soft ? 'btn-warning' : 'btn-danger');
        deleteChoices.classList.add('d-none');
        deleteConfirmation.classList.remove('d-none');
    }

    document.getElementById('chooseSoftDelete').addEventListener('click', function () { showDeleteConfirmation('soft_delete'); });
    document.getElementById('choosePermanentDelete').addEventListener('click', function () { showDeleteConfirmation('permanent_delete'); });
    document.getElementById('backToDeleteChoices').addEventListener('click', function () {
        deleteConfirmation.classList.add('d-none');
        deleteChoices.classList.remove('d-none');
        document.getElementById('deleteParentAction').value = '';
    });
    document.getElementById('confirmParentDelete').addEventListener('click', function () {
        document.getElementById('deleteParentConfirmationText').value = this.textContent.trim();
        deleteForm.submit();
    });
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
