<?php
// modules/support/index.php
// TEACHER — Student Support Center: pick an at-risk subject for one
// student, optionally get a Gemini-drafted support message, review/edit
// it, and send it. See includes/gemini_service.php for everything that
// actually talks to Gemini and enforces the real validation; this page is
// just the UI + the thin AJAX/POST endpoints beside it
// (generate_draft.php, send_message.php).

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/tutorial_helpers.php';
require_once __DIR__ . '/../../includes/risk_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

// Teacher-owned feature only — see includes/gemini_service.php header note.
if (!hasRole('Teacher')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$teacher_id = (int) $_SESSION['user_id'];
$student_id = intval($_GET['student_id'] ?? 0);

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
if (!$active_sy) {
    $_SESSION['error_message'] = 'No active school year is set.';
    header('Location: ' . BASE_URL . 'modules/ai/index.php');
    exit;
}

$student = getRecord(
    "SELECT s.*, sec.section_name FROM students s LEFT JOIN sections sec ON s.section_id = sec.section_id WHERE s.student_id = ? AND s.is_active = 1",
    [$student_id]
);
if (!$student) {
    $_SESSION['error_message'] = 'Student not found.';
    header('Location: ' . BASE_URL . 'modules/ai/index.php');
    exit;
}

// IMPORTANT — same class of check as modules/grading/index.php: don't just
// trust the student_id in the URL. Only show this page for a student who
// has at least one subject THIS teacher is assigned to and that is
// actually currently at-risk for that student. Anything else 404s away
// quietly rather than leaking the student's name/grades to an unrelated
// teacher.
$weak_subjects = getWeakSubjectsForStudent($student_id, $active_sy['sy_id']);
$my_subjects = array_values(array_filter($weak_subjects, fn($w) => (int) ($w['teacher_id'] ?? 0) === $teacher_id));

if (empty($my_subjects)) {
    $_SESSION['error_message'] = 'This student has no at-risk subject currently assigned to you.';
    header('Location: ' . BASE_URL . 'modules/ai/index.php');
    exit;
}

$page_title = 'Student Support Center';
$current_page = 'ai';
$base_url = BASE_URL;
$csrf_token = generateCSRFToken();
$support_types = ['Academic Support', 'Study Plan', 'Encouragement', 'Progress Follow-Up', 'Learning Strategy'];

include '../../includes/header.php';
?>

<style>
.support-center .card-box { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
.support-center .student-banner { display: flex; align-items: center; gap: 12px; }
.support-center .student-banner .avatar { width: 44px; height: 44px; border-radius: 50%; background: #14213D; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; flex-shrink: 0; }
.support-center .subject-pick { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.support-center .subject-pick label { cursor: pointer; }
.support-center .subject-pick input { position: absolute; opacity: 0; width: 0; height: 0; }
.support-center .subject-pick span { display: inline-block; padding: 8px 14px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 13px; font-weight: 600; color: var(--text); }
.support-center .subject-pick input:checked + span { border-color: var(--ink); background: #14213D; color: #fff; }
.support-center .type-pick { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 16px; }
.support-center .type-pick button { padding: 8px 14px; border-radius: 8px; border: 2px solid #e5e7eb; background: var(--surface); font-size: 13px; font-weight: 600; color: var(--text); cursor: pointer; }
.support-center .type-pick button.active { border-color: var(--ink); background: #14213D; color: #fff; }
.support-center textarea#draftArea { width: 100%; min-height: 140px; border: 1px solid var(--border); border-radius: 8px; padding: 12px; font-size: 14px; }
.support-center .ai-note { font-size: 12px; color: #6b7085; margin-top: 6px; }
.support-center .draft-error { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px 12px; font-size: 13px; color: #991b1b; margin-top: 10px; display: none; }
.support-center .btn-generate { background: #14213D; color: #fff; border: none; padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 13px; }
.support-center .btn-generate:disabled { opacity: .6; cursor: not-allowed; }
.support-center .btn-send { background: #238863; color: #fff; border: none; padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 13px; }
.support-center .btn-write-own { background: var(--surface); border: 1px solid var(--border); padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 13px; color: var(--text); }
</style>

<div class="support-center" style="padding: 24px; max-width: 760px;">
    <div class="card-box student-banner">
        <div class="avatar"><?php echo htmlspecialchars(strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1))); ?></div>
        <div>
            <h5 class="mb-0"><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></h5>
            <small class="text-muted"><?php echo htmlspecialchars($student['grade_level']); ?> · <?php echo htmlspecialchars($student['section_name'] ?: 'No section'); ?></small>
        </div>
    </div>

    <div class="card-box">
        <label class="form-label fw-semibold">Support Subject</label>
        <?php if (count($my_subjects) === 1): ?>
            <div class="subject-pick">
                <span style="border-color: var(--ink);background:#14213D;color:#fff;"><?php echo htmlspecialchars($my_subjects[0]['subject_name']); ?> — <?php echo number_format($my_subjects[0]['final_grade'], 2); ?></span>
            </div>
            <input type="hidden" id="subjectCode" value="<?php echo htmlspecialchars($my_subjects[0]['subject_code']); ?>">
        <?php else: ?>
            <p class="ai-note mb-2">This student has more than one subject assigned to you that's currently at risk — pick one to focus this message on.</p>
            <div class="subject-pick">
                <?php foreach ($my_subjects as $i => $ws): ?>
                    <label>
                        <input type="radio" name="subjectPick" value="<?php echo htmlspecialchars($ws['subject_code']); ?>" <?php echo $i === 0 ? 'checked' : ''; ?>>
                        <span><?php echo htmlspecialchars($ws['subject_name']); ?> — <?php echo number_format($ws['final_grade'], 2); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card-box">
        <label class="form-label fw-semibold">Choose Support Type</label>
        <div class="type-pick" id="typePick">
            <?php foreach ($support_types as $i => $t): ?>
                <button type="button" class="type-btn <?php echo $i === 0 ? 'active' : ''; ?>" data-type="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></button>
            <?php endforeach; ?>
        </div>

        <label class="form-label fw-semibold">Optional Teacher Instruction</label>
        <input type="text" id="teacherInstruction" class="form-control" maxlength="200" placeholder='e.g. "Focus on improving multiplication and problem solving."'>

        <div class="mt-3 d-flex gap-2">
            <button type="button" class="btn-generate" id="generateBtn"><i class="fas fa-wand-magic-sparkles me-1"></i> Generate Draft</button>
            <button type="button" class="btn-write-own" id="writeOwnBtn">Write My Own Message</button>
        </div>
        <div class="draft-error" id="draftError"></div>
    </div>

    <div class="card-box" id="draftCard" style="display:none;">
        <label class="form-label fw-semibold">Message Draft <span class="text-muted fw-normal">(review and edit before sending)</span></label>
        <textarea id="draftArea" maxlength="2000"></textarea>
        <p class="ai-note"><i class="fas fa-circle-info me-1"></i>This is only a draft. Nothing is sent to the student until you click "Send to Student" below.</p>

        <form method="POST" action="send_message.php" id="sendForm" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
            <input type="hidden" name="subject_code" id="subjectCodeField">
            <input type="hidden" name="support_type" id="supportTypeField">
            <input type="hidden" name="teacher_instruction" id="teacherInstructionField">
            <input type="hidden" name="ai_draft" id="aiDraftField">
            <input type="hidden" name="source" id="sourceField" value="manual">
            <input type="hidden" name="final_message" id="finalMessageField">
            <button type="submit" class="btn-send" id="sendBtn" onclick="return prepareSend();"><i class="fas fa-paper-plane me-1"></i> Send to Student</button>
        </form>
    </div>
</div>

<script>
(function() {
    var selectedType = document.querySelector('#typePick .type-btn.active').dataset.type;
    var lastSource = 'manual';
    var lastAiDraft = '';

    document.querySelectorAll('#typePick .type-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('#typePick .type-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            selectedType = btn.dataset.type;
        });
    });

    function currentSubjectCode() {
        var single = document.getElementById('subjectCode');
        if (single) return single.value;
        var picked = document.querySelector('input[name="subjectPick"]:checked');
        return picked ? picked.value : '';
    }

    function showError(msg) {
        var el = document.getElementById('draftError');
        el.textContent = msg;
        el.style.display = 'block';
    }
    function hideError() {
        document.getElementById('draftError').style.display = 'none';
    }

    document.getElementById('generateBtn').addEventListener('click', function() {
        var btn = this;
        hideError();
        btn.disabled = true;
        var originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Generating...';

        var params = new URLSearchParams();
        params.set('csrf_token', <?php echo json_encode($csrf_token); ?>);
        params.set('student_id', <?php echo (int) $student_id; ?>);
        params.set('subject_code', currentSubjectCode());
        params.set('support_type', selectedType);
        params.set('teacher_instruction', document.getElementById('teacherInstruction').value);

        fetch('generate_draft.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                lastSource = 'ai';
                lastAiDraft = data.draft;
                document.getElementById('draftArea').value = data.draft;
                document.getElementById('draftCard').style.display = 'block';
            } else {
                showError(data.error || 'AI Draft Assistant is temporarily unavailable. You can still write and send a manual support message.');
            }
        })
        .catch(function() {
            showError('AI Draft Assistant is temporarily unavailable. You can still write and send a manual support message.');
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    });

    document.getElementById('writeOwnBtn').addEventListener('click', function() {
        lastSource = 'manual';
        lastAiDraft = '';
        document.getElementById('draftArea').value = '';
        document.getElementById('draftCard').style.display = 'block';
        document.getElementById('draftArea').focus();
    });

    window.prepareSend = function() {
        var message = document.getElementById('draftArea').value.trim();
        if (!message) {
            alert('Please write or generate a message first.');
            return false;
        }
        document.getElementById('subjectCodeField').value = currentSubjectCode();
        document.getElementById('supportTypeField').value = selectedType;
        document.getElementById('teacherInstructionField').value = document.getElementById('teacherInstruction').value;
        document.getElementById('aiDraftField').value = lastAiDraft;
        // 'ai' if a draft was generated at any point this session (even if
        // then edited) — final_message below still carries whatever's
        // actually in the textarea right now, edited or not.
        document.getElementById('sourceField').value = lastAiDraft ? 'ai' : 'manual';
        document.getElementById('finalMessageField').value = message;
        var btn = document.getElementById('sendBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Sending...';
        return true;
    };
})();
</script>

<?php include '../../includes/footer.php'; ?>
