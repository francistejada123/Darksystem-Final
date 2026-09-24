<?php
// ============================================================
// >>> THIS FILE GOES IN:  modules/subjects/add.php  <<<
// ============================================================
// modules/subjects/add.php
// Add new subject with strand support

require_once __DIR__ . '/../../config/database.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading_helpers.php';

if (!hasRole('Administrator')) {
    header('Location: ../../index.php');
    exit;
}

$page_title = 'Add Subject';
$current_page = 'subjects';
$base_url = '../../';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $subject_code = strtoupper(sanitize($_POST['subject_code'] ?? ''));
        $subject_name = sanitize($_POST['subject_name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $grade_level = sanitize($_POST['grade_level'] ?? '');
        $strand = sanitize($_POST['strand'] ?? '');
        
        // Validation
        if (empty($subject_code)) $errors[] = 'Subject code is required.';
        if (empty($subject_name)) $errors[] = 'Subject name is required.';
        if (empty($grade_level)) $errors[] = 'Grade level is required.';
        
        // If Grade 11 or 12, strand is required
        if (($grade_level === 'Grade 11' || $grade_level === 'Grade 12') && empty($strand)) {
            $errors[] = 'Strand is required for Grade 11 and 12 subjects.';
        }

        // The grading category decides the WW / PT / EX weights, so it must
        // belong to the right family for the grade level, and a Senior High
        // School subject must state its category (Core, Field Exposure,
        // Arts/Sports/Health, Research, TechPro, Work Immersion, ...) rather
        // than silently getting a general default.
        $posted_category = sanitize($_POST['subject_category'] ?? '');
        if (gh_isSeniorHigh($grade_level) && $posted_category === '') {
            $errors[] = 'Grading Category is required for Grade 11 and 12 subjects — each SHS subject/elective type has its own WW / PT / EX weights.';
        }
        if ($posted_category !== '' && !gh_categoryMatchesGradeLevel($posted_category, $grade_level)) {
            $errors[] = 'The selected Grading Category does not apply to ' . htmlspecialchars($grade_level) . '. Grades 7-10 use the Junior High categories; Grades 11-12 use the Senior High categories.';
        }

        // Check duplicate subject code
        if (empty($errors)) {
            $check = getRecord("SELECT subject_code FROM subjects WHERE subject_code = ?", [$subject_code]);
            if ($check) $errors[] = 'Subject code already exists.';
        }
        
        // If no errors, insert
        if (empty($errors)) {
            // Learning area type. The obsolete 'EsP' type is not offered, so a
            // newly created Three-Term subject can never use it.
            $subject_type = sanitize($_POST['subject_type'] ?? '');
            if (!in_array($subject_type, getSelectableSubjectTypes(), true)) {
                $subject_type = null;
            }

            // DepEd grading category. NULL falls back to derivation from the
            // subject type, or raises a configuration notice.
            $category = sanitize($_POST['subject_category'] ?? '');
            if (!in_array($category, array_keys(gh_categoryDefinitions()), true)) {
                $category = null;
            }

            // Components of a multi-component learning area, comma-separated.
            $components_val = implode(',', gh_parseComponents($_POST['components'] ?? ''));
            if ($components_val === '') $components_val = null;

            $sql = "INSERT INTO subjects (subject_code, subject_name, description, grade_level, strand,
                    subject_type, subject_category, components, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $params = [$subject_code, $subject_name, $description, $grade_level, $strand,
                       $subject_type, $category, $components_val, 1];
            $result = executeQuery($sql, $params);
            
            if ($result) {
                logActivity($_SESSION['user_id'], 'Added subject', 'subjects', "Added subject: $subject_code - $subject_name (Grade: $grade_level, Strand: $strand)");
                $_SESSION['success_message'] = 'Subject added successfully!';
                header('Location: index.php?success=1');
                exit;
            } else {
                $errors[] = 'Failed to add subject. Please try again.';
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
            <h2><i class="fas fa-book-open text-primary me-2"></i>Add Subject</h2>
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
                    <input type="text" name="subject_code" class="form-control" placeholder="e.g., MATH101" value="<?php echo htmlspecialchars($_POST['subject_code'] ?? ''); ?>" required>
                    <small class="text-muted">Unique identifier for the subject.</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Subject Name</label>
                    <input type="text" name="subject_name" class="form-control" placeholder="Enter subject name" value="<?php echo htmlspecialchars($_POST['subject_name'] ?? ''); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Grade Level</label>
                    <select name="grade_level" class="form-select" required id="gradeLevelSelect">
                        <option value="">Select Grade Level</option>
                        <?php
                        $grades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
                        foreach ($grades as $grade):
                        ?>
                            <option value="<?php echo $grade; ?>" <?php echo ($_POST['grade_level'] ?? '') == $grade ? 'selected' : ''; ?>>
                                <?php echo $grade; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4" id="strandDiv" style="display: <?php echo (isset($_POST['grade_level']) && ($_POST['grade_level'] === 'Grade 11' || $_POST['grade_level'] === 'Grade 12')) ? 'block' : 'none'; ?>;">
                    <label class="form-label">Strand (for SHS) <span class="text-danger" id="strandRequired"></span></label>
                    <select name="strand" class="form-select">
                        <option value="">Select Strand</option>
                        <option value="GAS" <?php echo ($_POST['strand'] ?? '') == 'GAS' ? 'selected' : ''; ?>>GAS - General Academic Strand</option>
                        <option value="ASSH" <?php echo ($_POST['strand'] ?? '') == 'ASSH' ? 'selected' : ''; ?>>ASSH Cluster (Arts, Social Sciences, and Humanities)</option>
                    </select>
                    <small class="text-muted">Only GAS and ASSH strands are available.</small>
                </div>
                
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Enter subject description"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>
            </div>
            
            <hr class="my-4">
            <h5 class="mb-3"><i class="fas fa-calculator text-primary me-2"></i>DepEd Grading Rules</h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Learning Area Type</label>
                    <select name="subject_type" class="form-select">
                        <option value="">&mdash; Select &mdash;</option>
                        <?php foreach (getSelectableSubjectTypes() as $stype): ?>
                            <option value="<?php echo htmlspecialchars($stype); ?>"><?php echo htmlspecialchars($stype); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">The obsolete &quot;EsP&quot; type is no longer offered. Key Stage 3 uses <strong>Values Education</strong>; Key Stage 2 uses <strong>GMRC</strong>.</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Grading Category</label>
                    <select name="subject_category" id="subjectCategorySelect" class="form-select">
                        <option value="" data-stage="">&mdash; Derive from learning area type &mdash;</option>
                        <?php
                        $grouped = [];
                        foreach (gh_categoryDefinitions() as $ckey => $cdef) { $grouped[$cdef['group']][$ckey] = $cdef; }
                        foreach ($grouped as $glabel => $defs): ?>
                            <optgroup label="<?php echo htmlspecialchars($glabel); ?>">
                                <?php foreach ($defs as $ckey => $cdef): ?>
                                    <option value="<?php echo $ckey; ?>" data-stage="<?php echo htmlspecialchars($cdef['stage']); ?>" <?php echo (($_POST['subject_category'] ?? '') === $ckey) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cdef['label']); ?> (WW <?php echo (int)round($cdef['ww']*100); ?>% / PT <?php echo (int)round($cdef['pt']*100); ?>% / EX <?php echo (int)round($cdef['ex']*100); ?>%)</option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">The list follows the grade level: Junior High categories for Grades 7-10, Strengthened SHS categories for Grades 11-12. <strong>Required for SHS subjects.</strong> Leave blank for Junior High to derive it from the learning area type.</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Components <span class="text-muted">(multi-component only)</span></label>
                    <input type="text" name="components" class="form-control" placeholder="e.g. Music,Arts,Physical Education,Health">
                    <small class="text-muted">Comma-separated. Blank for ordinary subjects.</small>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4">
                <a href="index.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Save Subject
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
    var blankOpt = sel.options[0];
    Array.prototype.forEach.call(sel.options, function (opt) {
        var os = opt.getAttribute('data-stage');
        var show = !os || !stage || os === stage;
        opt.hidden = !show;
        opt.disabled = !show;
    });
    if (sel.selectedIndex > 0 && sel.options[sel.selectedIndex].disabled) {
        sel.value = '';
    }
    // SHS: the blank "derive" choice is not valid, so relabel it.
    blankOpt.textContent = (stage === 'sshs')
        ? '— Select the SHS subject / elective type —'
        : '— Derive from learning area type —';
    sel.required = (stage === 'sshs');
}
filterCategoryByGrade();
</script>

<?php include '../../includes/footer.php'; ?>