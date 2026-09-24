<?php
// modules/subjects/edit.php
// Edit subject with strand support

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'Edit Subject';
$current_page = 'subjects';
$base_url = BASE_URL;

$subject_code = isset($_GET['code']) ? urldecode($_GET['code']) : '';
$errors = [];

if (empty($subject_code)) {
    header('Location: index.php');
    exit;
}

// Get subject data
$subject = getRecord("SELECT * FROM subjects WHERE subject_code = ? AND is_active = 1", [$subject_code]);
if (!$subject) {
    $_SESSION['error_message'] = 'Subject not found.';
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $new_code = strtoupper(sanitize($_POST['subject_code'] ?? ''));
        $subject_name = sanitize($_POST['subject_name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $grade_level = sanitize($_POST['grade_level'] ?? '');
        $strand = sanitize($_POST['strand'] ?? '');
        
        // Validation
        if (empty($new_code)) $errors[] = 'Subject code is required.';
        if (empty($subject_name)) $errors[] = 'Subject name is required.';
        if (empty($grade_level)) $errors[] = 'Grade level is required.';
        
        // If Grade 11 or 12, strand is required
        if (($grade_level === 'Grade 11' || $grade_level === 'Grade 12') && empty($strand)) {
            $errors[] = 'Strand is required for Grade 11 and 12 subjects.';
        }

        // A chosen grading category must belong to the right family for the
        // grade level (Junior High vs Senior High). A blank category is still
        // accepted here so an older subject can be edited without being
        // blocked — it keeps showing the "Not set" warning until it is set.
        $posted_category = sanitize($_POST['subject_category'] ?? '');
        if ($posted_category !== '' && !gh_categoryMatchesGradeLevel($posted_category, $grade_level)) {
            $errors[] = 'The selected Grading Category does not apply to ' . htmlspecialchars($grade_level) . '. Grades 7-10 use the Junior High categories; Grades 11-12 use the Senior High categories.';
        }

        // Check duplicate subject code (excluding current)
        if (empty($errors) && $new_code !== $subject_code) {
            $check = getRecord("SELECT subject_code FROM subjects WHERE subject_code = ?", [$new_code]);
            if ($check) $errors[] = 'Subject code already exists.';
        }
        
        // If no errors, update
        if (empty($errors)) {
            // DepEd grading category. Empty is stored as NULL so the subject
            // falls back to derivation, or raises a configuration notice.
            $category = sanitize($_POST['subject_category'] ?? '');
            if (!in_array($category, array_keys(gh_categoryDefinitions()), true)) {
                $category = null;
            }

            // Components of a multi-component learning area, comma-separated.
            $components_val = implode(',', gh_parseComponents($_POST['components'] ?? ''));
            if ($components_val === '') $components_val = null;

            $sql = "UPDATE subjects SET 
                    subject_code = ?, subject_name = ?, description = ?, grade_level = ?, strand = ?,
                    subject_category = ?, components = ?
                    WHERE subject_code = ?";
            
            $params = [$new_code, $subject_name, $description, $grade_level, $strand,
                       $category, $components_val, $subject_code];
            $result = executeQuery($sql, $params);
            
            if ($result) {
                logActivity($_SESSION['user_id'], 'Updated subject', 'subjects', "Updated subject: $new_code - $subject_name");
                $_SESSION['success_message'] = 'Subject updated successfully!';
                header('Location: index.php?success=1');
                exit;
            } else {
                $errors[] = 'Failed to update subject. Please try again.';
            }
        }
    }
}

$csrf_token = generateCSRFToken();
include '../../includes/header.php';
?>

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-edit text-primary me-2"></i>Edit Subject</h2>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to List
            </a>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo $error; ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm rounded-3">
    <div class="card-body">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Subject Code</label>
                    <input type="text" name="subject_code" class="form-control" 
                           value="<?php echo htmlspecialchars($subject['subject_code']); ?>" required>
                    <small class="text-muted">Unique identifier for the subject.</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Subject Name</label>
                    <input type="text" name="subject_name" class="form-control" 
                           value="<?php echo htmlspecialchars($subject['subject_name']); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Grade Level</label>
                    <select name="grade_level" class="form-select" required id="gradeLevelSelect">
                        <option value="">Select Grade Level</option>
                        <?php
                        $grades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
                        foreach ($grades as $grade):
                        ?>
                            <option value="<?php echo $grade; ?>" <?php echo $subject['grade_level'] == $grade ? 'selected' : ''; ?>>
                                <?php echo $grade; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4" id="strandDiv" style="display: <?php echo ($subject['grade_level'] === 'Grade 11' || $subject['grade_level'] === 'Grade 12') ? 'block' : 'none'; ?>;">
                    <label class="form-label">Strand (for SHS) <span class="text-danger" id="strandRequired"></span></label>
                    <select name="strand" class="form-select">
                        <option value="">Select Strand</option>
                        <option value="GAS" <?php echo ($subject['strand'] ?? '') == 'GAS' ? 'selected' : ''; ?>>GAS - General Academic Strand</option>
                        <option value="ASSH" <?php echo ($subject['strand'] ?? '') == 'ASSH' ? 'selected' : ''; ?>>ASSH Cluster (Arts, Social Sciences, and Humanities)</option>
                    </select>
                </div>
                
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3" 
                              placeholder="Enter subject description"><?php echo htmlspecialchars($subject['description'] ?? ''); ?></textarea>
                </div>
            </div>
            
            <hr class="my-4">
            <h5 class="mb-3"><i class="fas fa-calculator text-primary me-2"></i>DepEd Grading Rules</h5>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">
                        Grading Category
                        <?php if (empty($subject['subject_category'])): ?>
                            <span class="badge bg-warning text-dark ms-1">Not set</span>
                        <?php endif; ?>
                    </label>
                    <select name="subject_category" id="subjectCategorySelect" class="form-select">
                        <option value="" data-stage="">&mdash; Not set (derive from subject type, or block if unclear) &mdash;</option>
                        <?php
                        $grouped = [];
                        foreach (gh_categoryDefinitions() as $ckey => $cdef) {
                            $grouped[$cdef['group']][$ckey] = $cdef;
                        }
                        foreach ($grouped as $group_label => $defs): ?>
                            <optgroup label="<?php echo htmlspecialchars($group_label); ?>">
                                <?php foreach ($defs as $ckey => $cdef): ?>
                                    <option value="<?php echo $ckey; ?>" data-stage="<?php echo htmlspecialchars($cdef['stage']); ?>" <?php echo (($subject['subject_category'] ?? '') === $ckey) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cdef['label']); ?> (WW <?php echo (int)round($cdef['ww']*100); ?>% / PT <?php echo (int)round($cdef['pt']*100); ?>% / EX <?php echo (int)round($cdef['ex']*100); ?>%)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Decides the WW / PT / EX weights. Required for SHS subjects and for any subject still using the old &quot;EsP&quot; type &mdash; those are never guessed.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Components <span class="text-muted">(multi-component learning areas only)</span></label>
                    <input type="text" name="components" class="form-control" value="<?php echo htmlspecialchars($subject['components'] ?? ''); ?>" placeholder="e.g. Music,Arts,Physical Education,Health">
                    <small class="text-muted">Comma-separated. Leave blank for ordinary subjects. Each component is graded separately and the subject grade is their average.</small>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4">
                <a href="index.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Update Subject
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Show/hide strand based on grade level
document.getElementById('gradeLevelSelect').addEventListener('change', function() {
    var strandDiv = document.getElementById('strandDiv');
    var strandRequired = document.getElementById('strandRequired');
    if (this.value === 'Grade 11' || this.value === 'Grade 12') {
        strandDiv.style.display = 'block';
        strandRequired.style.display = 'inline';
    } else {
        strandDiv.style.display = 'none';
        strandRequired.style.display = 'none';
    }
    filterCategoryByGrade();
});

// Only offer the grading categories that belong to the selected grade level:
// Grades 7-10 -> Junior High ('ks'), Grades 11-12 -> Strengthened SHS ('sshs').
function filterCategoryByGrade() {
    var grade = document.getElementById('gradeLevelSelect').value;
    var n = parseInt((grade.match(/\d+/) || [''])[0], 10);
    var stage = '';
    if (n >= 11 && n <= 12) stage = 'sshs';
    else if (n >= 4 && n <= 10) stage = 'ks';

    var sel = document.getElementById('subjectCategorySelect');
    Array.prototype.forEach.call(sel.options, function (opt) {
        var os = opt.getAttribute('data-stage');
        var show = !os || !stage || os === stage;
        opt.hidden = !show;
        opt.disabled = !show;
    });
    if (sel.selectedIndex > 0 && sel.options[sel.selectedIndex].disabled) {
        sel.value = '';
    }
}
filterCategoryByGrade();
</script>

<?php include '../../includes/footer.php'; ?>