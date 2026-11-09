<?php
// modules/tutorial/view.php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/risk_helpers.php';
require_once __DIR__ . '/../../includes/tutorial_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasAnyRole(['Administrator', 'Teacher'])) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'Tutorial Plan';
$current_page = 'tutorial';
$base_url = '../../';

// Admins monitor this data; only Teachers may change it. This is enforced
// here, not just by hiding the controls — a hidden form is not a rule.
$can_edit_plan = hasRole('Teacher');

$plan_id = intval($_GET['id'] ?? 0);

$plan = getRecord("
    SELECT tp.*, s.lrn, s.first_name, s.last_name, s.grade_level, s.strand
    FROM tutorial_plans tp
    JOIN students s ON tp.student_id = s.student_id
    WHERE tp.plan_id = ?
", [$plan_id]);

if (!$plan) {
    $_SESSION['error_message'] = 'Tutorial plan not found.';
    header('Location: index.php');
    exit;
}

// Handle updates (video link, or overall plan status — subject status is now automatic)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_edit_plan) {
        $_SESSION['error_message'] = 'Tutorial plans are view-only for your role.';
        header('Location: view.php?id=' . $plan_id);
        exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'update_video') {
        $item_id = intval($_POST['item_id'] ?? 0);
        $url = trim($_POST['resource_url'] ?? '');
        executeQuery("UPDATE tutorial_plan_items SET resource_url = ? WHERE item_id = ? AND plan_id = ?", [$url, $item_id, $plan_id]);
        $_SESSION['success_message'] = 'Video link updated.';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_plan_status') {
        $new_status = in_array($_POST['status'] ?? '', ['Draft', 'Active', 'Completed', 'Cancelled']) ? $_POST['status'] : $plan['status'];
        executeQuery("UPDATE tutorial_plans SET status = ? WHERE plan_id = ?", [$new_status, $plan_id]);

        // Let the student's guardian-facing account know once the whole plan is done.
        if ($new_status === 'Completed') {
            $student_user = getRecord("SELECT user_id FROM students WHERE student_id = ?", [$plan['student_id']]);
            if (!empty($student_user['user_id'])) {
                createNotification(
                    $student_user['user_id'],
                    'Tutorial Plan Completed',
                    'Your tutorial plan has been marked as completed. Keep up the progress!',
                    'success',
                    ''
                );
            }
        }
        $_SESSION['success_message'] = 'Plan status updated.';
    }
    header('Location: view.php?id=' . $plan_id);
    exit;
}

// Pick up any newly-failing subjects since the plan was last generated or
// viewed — a plan is a living document while it's Draft/Active, so it
// should always reflect the student's current weak subjects, not just a
// frozen snapshot from whenever it was first created.
if (in_array($plan['status'], ['Draft', 'Active'], true)) {
    $newly_added = syncTutorialPlanItems($plan_id, $plan['student_id'], $plan['school_year_id']);
    if ($newly_added > 0) {
        $_SESSION['success_message'] = $newly_added === 1
            ? '1 newly at-risk subject was added to this plan.'
            : $newly_added . ' newly at-risk subjects were added to this plan.';
    }
}

$items = getRecords("
    SELECT tpi.*, sub.subject_name, u.first_name AS tutor_first, u.last_name AS tutor_last
    FROM tutorial_plan_items tpi
    JOIN subjects sub ON tpi.subject_code = sub.subject_code
    LEFT JOIN users u ON tpi.tutor_id = u.user_id
    WHERE tpi.plan_id = ?
    ORDER BY tpi.current_grade ASC
", [$plan_id]);

$plan_progress = calculatePlanProgressPercent($items);

include '../../includes/header.php';
?>

<style>
/* "Open link" and "Back" are links, so they get a border, a pointer and a
   colour change on hover — the same treatment as the View buttons on the
   Tutorial System list, so a pressable thing always looks pressable. */
.tut-page .btn-view-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 20px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
    color: var(--ink);
    font-size: 15px;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
    transition: all 0.15s;
}
.tut-page .btn-view-link:hover { background: #14213D; color: #fff; border-color: var(--ink); }
.tut-page .btn-view-link:focus-visible { outline: 2px solid #14213D; outline-offset: 2px; }

/* Learning Material cell */
.tut-page .material-empty { color:#B7791F; font-size:15px; font-weight:600; }
.tut-page .btn-add-material {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border: 1px dashed var(--border);
    border-radius: 6px;
    background: var(--surface);
    color: #14213D;
    font-size: 15px;
    font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
}
.tut-page .btn-add-material:hover { background: #EDF4FB; border-color: #14213D; }
.tut-page .material-card {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    background: var(--surface-hover);
    border: 1px solid var(--border-soft);
    border-radius: 8px;
    padding: 10px 12px;
    max-width: 260px;
}
.tut-page .material-card .material-icon { font-size: 20px; line-height:1; margin-top:2px; }
.tut-page .material-card .material-title {
    font-weight: 700;
    font-size: 15.5px;
    color: var(--ink);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 175px;
    display: inline-block;
    vertical-align: bottom;
}
.tut-page .material-card .material-type-badge {
    font-size: 12.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    padding: 2px 8px;
    border-radius: 20px;
    display: inline-block;
    margin-top: 3px;
}
.tut-page .material-card .material-actions { margin-top: 6px; display:flex; gap:10px; flex-wrap:wrap; }
.tut-page .material-card .material-actions a,
.tut-page .material-card .material-actions button {
    background: none;
    border: none;
    padding: 0;
    font-size: 14.5px;
    font-weight: 600;
    color: #14213D;
    text-decoration: none;
    cursor: pointer;
}
.tut-page .material-card .material-actions button.remove-link { color: #C94A4A; }
.tut-page .material-card .material-actions a:hover,
.tut-page .material-card .material-actions button:hover { text-decoration: underline; }

/* Add/Replace Material modal */
.material-modal-tabs { display:flex; gap:8px; margin-bottom:16px; }
.material-modal-tabs button {
    flex: 1;
    padding: 10px 12px;
    border: 1px solid var(--border);
    background: var(--surface);
    border-radius: 8px;
    font-weight: 600;
    font-size: 15.5px;
    color: var(--text-muted);
    cursor: pointer;
}
.material-modal-tabs button.active { border-color:#14213D; background:#EDF4FB; color:#14213D; }
.material-modal-form label { font-weight:600; font-size:15px; color: var(--ink); margin-bottom:4px; display:block; }
.material-modal-form .form-control, .material-modal-form .form-select { margin-bottom:14px; }

@media (max-width: 767px) {
    .tut-page table.table-hover thead { display:none; }
    .tut-page table.table-hover, .tut-page table.table-hover tbody, .tut-page table.table-hover tr, .tut-page table.table-hover td { display:block; width:100%; }
    .tut-page table.table-hover tr { border-bottom: 8px solid var(--surface-hover); padding: 10px 0; }
    .tut-page table.table-hover td { border-bottom:none !important; padding: 6px 16px !important; }
    .tut-page table.table-hover td:before {
        content: attr(data-label);
        display:block;
        font-size:12.5px;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:0.04em;
        color: var(--text-muted);
        margin-bottom:3px;
    }
    .tut-page .material-card { max-width:none; }
}
</style>

<div class="container-fluid py-4 tut-page">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-1" style="color: var(--ink);font-size:24px;">
                Tutorial Plan — <?php echo htmlspecialchars($plan['last_name'] . ', ' . $plan['first_name']); ?>
            </h4>
            <p style="color: var(--text-muted);font-size:16px;margin-bottom:0;">
                LRN <?php echo htmlspecialchars($plan['lrn']); ?> ·
                <?php echo htmlspecialchars($plan['grade_level']); ?><?php echo $plan['strand'] ? ' - ' . htmlspecialchars($plan['strand']) : ''; ?> ·
                Target: <?php echo number_format($plan['target_average'], 2); ?>%
            </p>
        </div>
        <a href="index.php" class="btn-view-link">
            Back
        </a>
    </div>

    <div class="card border-0 shadow-sm" style="margin-bottom:16px;">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2" style="padding:18px 20px;">
            <div>
                <span style="font-size:15px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color: var(--text-muted);display:block;margin-bottom:4px;">Plan Status</span>
                <?php
                $sb = ['Draft' => '#f3f4f6,#667085', 'Active' => '#EDF4FB,#14213D', 'Completed' => '#EAF7F1,#238863', 'Cancelled' => '#f3f4f6,#667085'][$plan['status']] ?? '#f3f4f6,#667085';
                list($sbg, $scl) = explode(',', $sb);
                ?>
                <span style="background:<?php echo $sbg; ?>;color:<?php echo $scl; ?>;padding:6px 16px;border-radius:20px;font-size:15px;font-weight:700;"><?php echo $plan['status']; ?></span>
            </div>
            <div style="min-width:200px;">
                <span style="font-size:15px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color: var(--text-muted);display:block;margin-bottom:4px;">Progress</span>
                <div class="d-flex align-items-center gap-2">
                    <div style="flex:1;height:10px;background: var(--surface-hover);border-radius:5px;">
                        <div style="height:100%;width:<?php echo $plan_progress; ?>%;background:#238863;border-radius:5px;"></div>
                    </div>
                    <strong style="font-size:17px;color: var(--ink);"><?php echo number_format($plan_progress, 0); ?>%</strong>
                </div>
            </div>
            <?php if ($can_edit_plan): ?>
            <form method="POST" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="action" value="update_plan_status">
                <select name="status" class="form-select form-select-sm" style="width:auto;border-radius:6px;border: 1px solid var(--border);font-size:15px;padding:9px 38px 9px 12px;height:44px;">
                    <?php foreach (['Draft', 'Active', 'Completed', 'Cancelled'] as $opt): ?>
                        <option value="<?php echo $opt; ?>" <?php echo $plan['status'] === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn" style="background:#14213D;color:#fff;border:none;font-size:15px;font-weight:600;padding:0 24px;height:44px;border-radius:6px;">Update</button>
            </form>
            <?php else: ?>
            <span style="background:#eef4fb;color:#1565c0;font-weight:700;padding:8px 16px;border-radius:20px;font-size:15px;">
                <i class="fas fa-eye me-1"></i> View Only
            </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header" style="background: var(--surface);border-bottom: 1px solid var(--border);padding:14px 18px;">
            <h5 class="mb-0" style="font-weight:600;color: var(--ink);font-size:19px;">Subjects Needing Attention</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle" style="font-size:16px;">
                    <thead style="background: var(--surface-hover);">
                        <tr>
                            <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Subject</th>
                            <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Current</th>
                            <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Target</th>
                            <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Teacher</th>
                            <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Focus</th>
                            <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Learning Material</th>
                            <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Status</th>
                            <th style="font-size:14.5px;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;color: var(--text-muted);border-bottom: 1px solid var(--border);padding:14px 16px;">Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item):
                            $has_material = tutorialMaterialHasResource($item);
                            $mat_type = $item['resource_type'] ?? 'link';
                            $mat_meta = tutorialMaterialMeta($mat_type);
                            $mat_title = tutorialMaterialDisplayTitle($item);
                            $is_file_material = in_array($mat_type, ['pdf', 'word', 'powerpoint'], true);
                            $view_href = $is_file_material
                                ? 'view_material.php?id=' . (int) $item['item_id']
                                : ($item['resource_url'] ?? '#');
                        ?>
                            <tr>
                                <td data-label="Subject" style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;font-weight:600;color: var(--ink);"><?php echo htmlspecialchars($item['subject_name']); ?></td>
                                <td data-label="Current" style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;color:#C94A4A;font-weight:700;"><?php echo $item['current_grade'] !== null && (float) $item['current_grade'] > 0 ? number_format((float) $item['current_grade'], 2) . '%' : '<span style="color: var(--text-muted);font-weight:500;">In progress</span>'; ?></td>
                                <td data-label="Target" style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;font-weight:600;"><?php echo number_format($item['target_grade'], 2); ?>%</td>
                                <td data-label="Teacher" style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;">
                                    <?php echo $item['tutor_first']
                                        ? htmlspecialchars($item['tutor_last'] . ', ' . $item['tutor_first'])
                                        : '<span style="color: var(--text-muted);">Not assigned</span>'; ?>
                                </td>
                                <td data-label="Focus" style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;color: var(--ink);font-size:16px;line-height:1.55;min-width:280px;"><?php echo nl2br(htmlspecialchars((string) $item['recommended_action'])); ?></td>
                                <td data-label="Learning Material" style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;min-width:220px;">
                                    <?php if ($has_material): ?>
                                        <div class="material-card">
                                            <span class="material-icon"><i class="fas <?php echo htmlspecialchars($mat_meta['icon']); ?>"></i></span>
                                            <div>
                                                <span class="material-title" title="<?php echo htmlspecialchars($mat_title); ?>"><?php echo htmlspecialchars($mat_title); ?></span><br>
                                                <span class="material-type-badge <?php echo htmlspecialchars($mat_meta['badge_class']); ?>"><?php echo htmlspecialchars($mat_meta['label']); ?></span>
                                                <div class="material-actions">
                                                    <a href="<?php echo htmlspecialchars($view_href); ?>" target="_blank" rel="noopener">View</a>
                                                    <?php if ($can_edit_plan): ?>
                                                        <button type="button" class="replace-material-btn" data-item-id="<?php echo (int) $item['item_id']; ?>" data-subject="<?php echo htmlspecialchars($item['subject_name']); ?>" data-mode="replace">Replace</button>
                                                        <button type="button" class="remove-link remove-material-btn" data-item-id="<?php echo (int) $item['item_id']; ?>" data-title="<?php echo htmlspecialchars($mat_title); ?>">Remove</button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="material-empty mb-1">No material assigned</div>
                                        <?php if ($can_edit_plan): ?>
                                            <button type="button" class="btn-add-material add-material-btn" data-item-id="<?php echo (int) $item['item_id']; ?>" data-subject="<?php echo htmlspecialchars($item['subject_name']); ?>" data-mode="add">
                                                <i class="fas fa-plus"></i> Add Material
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status" style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;white-space:nowrap;">
                                    <?php
                                    $is_trackable = getYouTubeVideoId($item['resource_url'] ?? '') !== null;
                                    $status_badge = getTutorialWatchStatusBadgeClass($item['status']);
                                    $status_label = getTutorialWatchStatusLabel($item['status']);
                                    $sw = match(trim($status_badge)) {
                                        'success' => '#EAF7F1,#238863',
                                        'info' => '#EDF4FB,#14213D',
                                        'warning text-dark' => '#FFF7E6,#B7791F',
                                        default => '#f3f4f6,#667085',
                                    };
                                    list($sbg, $scl) = explode(',', $sw);
                                    ?>
                                    <span style="display:inline-block;background:<?php echo $sbg; ?>;color:<?php echo $scl; ?>;padding:6px 16px;border-radius:20px;font-size:15px;font-weight:700;white-space:nowrap;"><?php echo htmlspecialchars($status_label); ?></span>
                                </td>
                                <td data-label="Progress" style="border-bottom: 1px solid var(--border-soft);padding:14px 16px;min-width:150px;">
                                    <?php if ($is_trackable): ?>
                                        <div style="display:flex;align-items:center;gap:6px;">
                                            <div style="flex:1;min-width:70px;height:9px;background: var(--surface-hover);border-radius:5px;">
                                                <div style="height:100%;width:<?php echo (float)($item['watch_progress_percentage'] ?? 0); ?>%;background:<?php echo $item['status'] === 'Completed' ? '#238863' : '#14213D'; ?>;border-radius:5px;"></div>
                                            </div>
                                            <span style="font-size:15px;font-weight:700;color: var(--ink);white-space:nowrap;"><?php echo number_format($item['watch_progress_percentage'] ?? 0, 0); ?>%</span>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($can_edit_plan): ?>
<!-- Add / Replace Learning Material modal -->
<div class="modal fade" id="addMaterialModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px;border:none;">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="addMaterialModalTitle">Add Learning Material</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="material-modal-tabs">
                    <button type="button" id="tabUploadBtn" class="active" onclick="tutMatSwitchTab('upload')">
                        <i class="fas fa-upload me-1"></i> Upload File
                    </button>
                    <button type="button" id="tabLinkBtn" onclick="tutMatSwitchTab('link')">
                        <i class="fas fa-link me-1"></i> Video Link
                    </button>
                </div>

                <form method="POST" action="save_material.php" enctype="multipart/form-data" id="uploadMaterialForm" class="material-modal-form">
                    <input type="hidden" name="plan_id" value="<?php echo (int) $plan_id; ?>">
                    <input type="hidden" name="item_id" id="uploadItemId" value="">
                    <input type="hidden" name="resource_kind" value="upload">

                    <label>Material Title</label>
                    <input type="text" name="material_title" id="uploadMaterialTitle" class="form-control" placeholder="e.g. Mathematics Reviewer">

                    <label>File (PDF, Word, or PowerPoint)</label>
                    <input type="file" name="material_file" id="uploadMaterialFile" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx" required>
                    <div class="form-text mb-2">Accepted: PDF, Word (.doc/.docx), PowerPoint (.ppt/.pptx). Max size 15MB.</div>

                    <label>Instruction <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea name="material_description" class="form-control" rows="2" placeholder="Any notes or instructions for the student"></textarea>

                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn" style="background:#14213D;color:#fff;font-weight:600;">Add Material</button>
                    </div>
                </form>

                <form method="POST" action="save_material.php" id="linkMaterialForm" class="material-modal-form" style="display:none;">
                    <input type="hidden" name="plan_id" value="<?php echo (int) $plan_id; ?>">
                    <input type="hidden" name="item_id" id="linkItemId" value="">
                    <input type="hidden" name="resource_kind" value="link">

                    <label>Video Title</label>
                    <input type="text" name="material_title" id="linkMaterialTitle" class="form-control" placeholder="e.g. Fractions Explained">

                    <label>YouTube / Video URL</label>
                    <input type="url" name="video_url" id="linkVideoUrl" class="form-control" placeholder="https://www.youtube.com/watch?v=..." required>

                    <label>Instruction <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea name="material_description" class="form-control" rows="2" placeholder="Any notes or instructions for the student"></textarea>

                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn" style="background:#14213D;color:#fff;font-weight:600;">Add Material</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Remove Learning Material confirmation modal -->
<div class="modal fade" id="removeMaterialModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px;border:none;">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Remove Learning Material?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="removeMaterialText" style="font-size:15.5px;color: var(--ink);"></p>
                <form method="POST" action="remove_material.php" id="removeMaterialForm">
                    <input type="hidden" name="plan_id" value="<?php echo (int) $plan_id; ?>">
                    <input type="hidden" name="item_id" id="removeItemId" value="">
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger fw-bold">Remove</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function tutMatSwitchTab(which) {
    var uploadForm = document.getElementById('uploadMaterialForm');
    var linkForm = document.getElementById('linkMaterialForm');
    var uploadBtn = document.getElementById('tabUploadBtn');
    var linkBtn = document.getElementById('tabLinkBtn');
    if (which === 'upload') {
        uploadForm.style.display = '';
        linkForm.style.display = 'none';
        uploadBtn.classList.add('active');
        linkBtn.classList.remove('active');
    } else {
        uploadForm.style.display = 'none';
        linkForm.style.display = '';
        uploadBtn.classList.remove('active');
        linkBtn.classList.add('active');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    // bootstrap.bundle.min.js is loaded lower down (includes/footer.php) --
    // waiting for DOMContentLoaded (same pattern as modules/ai/index.php)
    // guarantees it has already run before we touch `bootstrap` here.
    if (typeof bootstrap === 'undefined') return;

    var addMaterialModalEl = document.getElementById('addMaterialModal');
    var addMaterialModal = addMaterialModalEl ? new bootstrap.Modal(addMaterialModalEl) : null;
    var removeMaterialModalEl = document.getElementById('removeMaterialModal');
    var removeMaterialModal = removeMaterialModalEl ? new bootstrap.Modal(removeMaterialModalEl) : null;

    document.querySelectorAll('.add-material-btn, .replace-material-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var itemId = btn.getAttribute('data-item-id');
            var subject = btn.getAttribute('data-subject') || '';
            window.tutMatSubject = subject;   // read by the warning-only file check below
            var mode = btn.getAttribute('data-mode') || 'add';

            document.getElementById('addMaterialModalTitle').textContent =
                (mode === 'replace' ? 'Replace' : 'Add') + ' Learning Material' + (subject ? ' — ' + subject : '');
            document.getElementById('uploadItemId').value = itemId;
            document.getElementById('linkItemId').value = itemId;
            document.getElementById('uploadMaterialTitle').value = '';
            document.getElementById('uploadMaterialFile').value = '';
            document.getElementById('linkMaterialTitle').value = '';
            document.getElementById('linkVideoUrl').value = '';
            tutMatSwitchTab('upload');

            if (addMaterialModal) { addMaterialModal.show(); }
        });
    });

    // Auto-fill Material Title from the chosen filename when left empty.
    var fileInput = document.getElementById('uploadMaterialFile');
    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var titleInput = document.getElementById('uploadMaterialTitle');
            if (titleInput && !titleInput.value && fileInput.files && fileInput.files[0]) {
                var name = fileInput.files[0].name.replace(/\.[^.]+$/, '');
                titleInput.value = name;
            }
        });
    }

    document.querySelectorAll('.remove-material-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var itemId = btn.getAttribute('data-item-id');
            var title = btn.getAttribute('data-title') || 'this material';
            document.getElementById('removeItemId').value = itemId;
            document.getElementById('removeMaterialText').textContent =
                "Are you sure you want to remove \"" + title + "\"?";
            if (removeMaterialModal) { removeMaterialModal.show(); }
        });
    });
});
</script>
<?php endif; ?>

<script src="<?php echo BASE_URL; ?>static/js/file-relevance.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var f = document.getElementById('uploadMaterialFile');
    if (!f || !window.FileRelevance) return;
    FileRelevance.attach({ input: f, form: f.form, url: <?php echo json_encode(BASE_URL . 'modules/teachers/check_file_relevance.php'); ?>,
        csrf: <?php echo json_encode(generateCSRFToken()); ?>,
        getSubject: function () { return window.tutMatSubject || ''; },
        getContext: function () { var t = document.getElementById('uploadMaterialTitle'); return t ? t.value : ''; } });
});
</script>

<?php include '../../includes/footer.php'; ?>