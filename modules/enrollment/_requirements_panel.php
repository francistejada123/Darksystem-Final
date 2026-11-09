<?php
// modules/enrollment/_requirements_panel.php
// Requirements Status box (display + Admin actions). Included by view_application.php and the
// admin student record. Expects:
//   $rp_app        online_applications row (application_id, application_type, status)
//   $rp_csrf       CSRF token
//   $rp_student_id (optional) return to this student record after an update
// Shows the Requirements Status and the pending items. "Mark as Submitted" / "Verify Requirement" are
// only offered once the application is Approved, and only to Administrator / Registrar / Enrollment Officer.
$rp_pending  = oeRequirementsPreview($rp_app['application_id'], $rp_app['application_type']);
$rp_approved = ($rp_app['status'] === 'Approved');
$rp_can_edit = $rp_approved && hasAnyRole(['Administrator', 'Registrar', 'Enrollment Officer']);
$rp_incomplete = !empty($rp_pending);
?>
<?php $rp_solo = isset($rp_student_id); /* inside the student record the Review page styles are not loaded */ ?>
<div class="<?php echo $rp_solo ? 'card shadow-sm rounded-3 mt-3' : 'ar-card'; ?>">
    <div class="<?php echo $rp_solo ? 'card-header bg-white border-0 pt-3' : 'ar-card-head'; ?>"><h5 class="mb-0"><i class="fas fa-clipboard-check <?php echo $rp_solo ? 'text-primary me-2' : 'head-ic'; ?>"></i>Requirements Status</h5></div>
    <div class="<?php echo $rp_solo ? 'card-body' : 'ar-decided'; ?>">
        <?php if ($rp_approved): ?>
            <div class="mb-1"><small style="color:var(--text-muted);">Enrollment:</small> <strong>Approved</strong></div>
        <?php endif; ?>
        <div class="mb-2">
            <small style="color:var(--text-muted);"><?php echo $rp_approved ? 'Requirements:' : 'Requirements Status:'; ?></small>
            <?php if ($rp_incomplete): ?>
                <strong style="color:#B45309;">&#9888; Incomplete Requirements</strong>
            <?php else: ?>
                <strong style="color:#0F7B4E;"><i class="fas fa-circle-check me-1"></i>Complete</strong>
            <?php endif; ?>
        </div>
        <?php if ($rp_incomplete): ?>
            <div class="mb-1"><small style="color:var(--text-muted);"><?php echo $rp_approved ? 'Pending:' : 'Missing / To Follow:'; ?></small></div>
            <?php foreach ($rp_pending as $rp): ?>
                <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                    <span>&bull; <?php echo htmlspecialchars($rp['label']); ?> &mdash; <strong><?php echo htmlspecialchars($rp['state']); ?></strong></span>
                    <?php if ($rp_can_edit): ?>
                        <form method="POST" action="<?php echo (isset($rp_student_id) ? '../enrollment/' : ''); ?>requirement_update.php" class="d-inline-flex gap-1 m-0">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($rp_csrf); ?>">
                            <input type="hidden" name="application_id" value="<?php echo (int) $rp_app['application_id']; ?>">
                            <input type="hidden" name="item" value="<?php echo htmlspecialchars($rp['key']); ?>">
                            <?php if (!empty($rp_student_id)): ?><input type="hidden" name="student_id" value="<?php echo (int) $rp_student_id; ?>"><?php endif; ?>
                            <?php if ($rp['state'] === 'To Follow'): ?>
                                <button type="submit" name="new_status" value="Received" class="btn btn-sm btn-outline-primary">Mark as Submitted</button>
                            <?php endif; ?>
                            <button type="submit" name="new_status" value="Verified" class="btn btn-sm btn-success">Verify Requirement</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($rp_approved): ?>
                <p class="ar-hint mb-0"><i class="fas fa-circle-info me-1"></i>The student is already enrolled. The status becomes Complete once every pending requirement is verified.</p>
            <?php else: ?>
                <p class="ar-hint mb-0"><i class="fas fa-circle-info me-1"></i>You can still approve; the student stays <em>Incomplete Requirements</em> until these are submitted and verified.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
