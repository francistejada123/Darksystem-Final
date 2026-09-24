<?php if (hasRole('Teacher')): ?>
<?php
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>static/css/teacher-ai.css?v=<?php echo @filemtime(__DIR__ . '/../static/css/teacher-ai.css') ?: time(); ?>">
<style>
    .teacher-ai-launcher { right: 22px; bottom: 72px; width: 64px; height: 64px; display: grid; place-items: center; padding: 0; border-radius: 50%; }
    .teacher-ai-launcher span { display: none; }
    .teacher-ai-launcher i { font-size: 27px; }
    .teacher-ai-drawer { top: 50%; right: auto; left: 50%; width: min(800px, calc(100vw - 32px)) !important; height: calc(100vh - 48px) !important; min-height: 600px !important; max-height: calc(100vh - 48px); border-radius: 8px; overflow: hidden; transform: translate(-50%, -50%) scale(.96); opacity: 0; visibility: hidden; pointer-events: none; box-shadow: 0 20px 60px rgba(20,33,61,.24); }
    .teacher-ai-root.is-open .teacher-ai-drawer { transform: translate(-50%, -50%) scale(1); opacity: 1; visibility: visible; pointer-events: auto; }
    .teacher-ai-drawer { display: flex; flex-direction: column; }
    .teacher-ai-body { flex: 1 1 auto; min-height: 0; height: 0; overflow-y: scroll; overscroll-behavior: contain; }
    .teacher-ai-history-panel { flex: 0 0 auto; overflow-y: auto; overscroll-behavior: contain; }
    .teacher-ai-composer { flex: 0 0 auto; }
    body.teacher-ai-open { overflow: hidden; }
    @media (max-width: 600px) { .teacher-ai-launcher { right: 14px; bottom: 58px; width: 58px; height: 58px; } .teacher-ai-launcher i { font-size: 24px; } .teacher-ai-drawer { width: calc(100vw - 20px) !important; height: calc(100vh - 20px) !important; min-height: 0 !important; max-height: calc(100vh - 20px); } }
</style>
<div id="teacherAiRoot" class="teacher-ai-root" data-endpoint="<?php echo BASE_URL; ?>modules/teachers/teacher_ai_chat.php" data-file-endpoint="<?php echo BASE_URL; ?>modules/teachers/teacher_ai_file.php" data-resource-endpoint="<?php echo BASE_URL; ?>modules/teachers/teacher_ai_resource.php" data-resource-file-endpoint="<?php echo BASE_URL; ?>modules/teachers/teacher_ai_resource_file.php" data-csrf="<?php echo htmlspecialchars(generateCSRFToken(), ENT_QUOTES); ?>">
    <button type="button" class="teacher-ai-launcher" id="teacherAiLauncher" aria-label="Open E-GRADEPRO Teacher AI" title="Open E-GRADEPRO Teacher AI">
        <i class="fas fa-robot" aria-hidden="true"></i><span>E-GRADEPRO Teacher AI</span>
    </button>
    <div class="teacher-ai-scrim" id="teacherAiScrim"></div>
    <aside class="teacher-ai-drawer" id="teacherAiDrawer" aria-label="E-GRADEPRO Teacher AI" aria-hidden="true">
        <header class="teacher-ai-header">
            <div class="teacher-ai-brand"><span class="teacher-ai-brand-icon"><i class="fas fa-robot"></i></span><div><strong>E-GRADEPRO Teacher AI</strong><small>Your intelligent teaching and school assistant</small><em><span></span> AI Ready</em></div></div>
            <div class="tai-header-deco" aria-hidden="true"><i class="fas fa-book-open"></i><span>Better<br>Teachers<br>Brighter Futures</span></div>
            <button type="button" class="teacher-ai-icon-btn" id="teacherAiClose" aria-label="Close Teacher AI" onclick="window.closeTeacherAiPanel(event)"><i class="fas fa-xmark"></i></button>
        </header>
        <div class="teacher-ai-toolbar"><button type="button" id="teacherAiNewChat"><i class="fas fa-plus"></i> New Chat</button><button type="button" id="teacherAiHistory"><i class="fas fa-clock-rotate-left"></i> History</button><div class="tai-toolbar-tag" aria-hidden="true"><i class="fas fa-graduation-cap"></i><span>Teaching today.<br>A brighter tomorrow.</span></div><button type="button" id="teacherAiMinimize" aria-label="Minimize" onclick="window.closeTeacherAiPanel(event)"><i class="fas fa-minus"></i></button></div>
        <div class="teacher-ai-history-panel" id="teacherAiHistoryPanel" hidden></div>
        <main class="teacher-ai-body" id="teacherAiBody">
            <section class="teacher-ai-welcome" id="teacherAiWelcome"><div class="teacher-ai-welcome-icon"><i class="fas fa-robot"></i></div><h2>E-GRADEPRO Teacher AI</h2><p>Your intelligent teaching and school assistant.</p><p class="teacher-ai-welcome-copy">Ask about teaching, classes, or your authorized students' attendance, activity, missing work, grades, and at-risk indicators. Optionally analyze topic-level performance from your recorded quiz, activity, and exam scores. Create lessons, quizzes, and activities, turn them into PDF, Word, PowerPoint, or Google Forms, and send them to your own classes.</p><div class="teacher-ai-suggestions"><button type="button" data-chat-type="lesson"><i class="fas fa-book-open"></i><span><b>Lesson Planning</b>Create a lesson plan for your subject and grade level</span></button><button type="button" data-chat-type="assessment"><i class="fas fa-pen-to-square"></i><span><b>Assessment</b>Create a quiz about any topic you choose</span></button><button type="button" data-chat-type="explain"><i class="fas fa-lightbulb"></i><span><b>Explain a Topic</b>Explain any topic you choose, in simple terms</span></button><button type="button" data-chat-type="strategy"><i class="fas fa-bullseye"></i><span><b>Teaching Strategy</b>Give me strategies for struggling learners</span></button><button type="button" data-prompt="How does the grading module work in E-GRADEPRO?"><i class="fas fa-school"></i><span><b>E-GRADEPRO Help</b>How does the grading module work?</span></button><button type="button" data-dashboard="student_monitoring"><i class="fas fa-user-check"></i><span><b>Student Monitoring</b>Performance analytics: strong/weak topics, trends, and who to watch</span></button><button type="button" data-prompt="Analyze my students' assessment performance by topic. Which topics are strong or weak, class-wide and for individual students, and which students may need monitoring?"><i class="fas fa-chart-line"></i><span><b>Student Performance Analysis</b>Strong/weak topics from actual quiz, activity, and exam scores</span></button></div></section>
        </main>
        <form class="teacher-ai-composer" id="teacherAiForm">
            <div class="tai-attach-row" id="teacherAiAttachRow" hidden></div>
            <div class="tai-plus-menu" id="teacherAiPlusMenu" role="menu" aria-label="Add to chat" hidden>
                <button type="button" role="menuitem" data-tai-attach="1"><i class="fas fa-paperclip"></i><span><b>Add photos &amp; files</b>Upload an image, PDF, Word or text file</span></button>
                <div class="tai-plus-sep" role="separator"></div>
                <button type="button" role="menuitem" data-tai-prefill="lesson"><i class="fas fa-book-open"></i><span><b>Lesson plan</b>Objectives, activities and assessment</span></button>
                <button type="button" role="menuitem" data-tai-prefill="quiz"><i class="fas fa-circle-question"></i><span><b>Quiz / Google Form</b>Multiple-choice or true/false questions</span></button>
                <button type="button" role="menuitem" data-tai-prefill="activity"><i class="fas fa-pencil"></i><span><b>Activity or worksheet</b>Classroom practice for your learners</span></button>
                <button type="button" role="menuitem" data-tai-prefill="task"><i class="fas fa-clipboard-check"></i><span><b>Performance task &amp; rubric</b>PETA, project or output with scoring guide</span></button>
                <button type="button" role="menuitem" data-tai-prefill="slides"><i class="fas fa-display"></i><span><b>Slide presentation</b>Lesson slides you can download as PowerPoint</span></button>
                <button type="button" role="menuitem" data-tai-prefill="explain"><i class="fas fa-lightbulb"></i><span><b>Explain a topic</b>Simple explanation for your students</span></button>
                <div class="tai-plus-sep" role="separator"></div>
                <button type="button" role="menuitem" data-tai-send="monitor"><i class="fas fa-user-check"></i><span><b>Student monitoring</b>Attendance, missing work and at-risk learners</span></button>
                <button type="button" role="menuitem" data-tai-send="grading"><i class="fas fa-calculator"></i><span><b>Grading help</b>How grades are computed (DO 015, s. 2026)</span></button>
            </div>
            <button type="button" class="tai-plus-btn" id="teacherAiPlus" aria-label="Add a file or choose a tool" aria-haspopup="true" aria-expanded="false" title="Add photos, files and tools"><i class="fas fa-plus"></i></button>
            <input type="file" id="teacherAiFile" hidden accept="image/png,image/jpeg,image/webp,application/pdf,.pdf,.docx,.txt,.md,.csv">
            <textarea id="teacherAiInput" rows="1" placeholder="Ask E-GRADEPRO Teacher AI anything..." maxlength="2000"></textarea>
            <button type="submit" id="teacherAiSend" aria-label="Send"><i class="fas fa-paper-plane"></i></button>
        </form>
    </aside>
</div>
<script src="<?php echo BASE_URL; ?>static/js/teacher-ai-resources.js?v=<?php echo @filemtime(__DIR__ . '/../static/js/teacher-ai-resources.js') ?: time(); ?>"></script>
<script src="<?php echo BASE_URL; ?>static/js/teacher-ai.js?v=<?php echo @filemtime(__DIR__ . '/../static/js/teacher-ai.js') ?: time(); ?>"></script>
<script src="<?php echo BASE_URL; ?>static/js/teacher-ai-exports.js?v=<?php echo @filemtime(__DIR__ . '/../static/js/teacher-ai-exports.js') ?: time(); ?>"></script>
<script>
    window.closeTeacherAiPanel = function (event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        var root = document.getElementById('teacherAiRoot');
        var drawer = document.getElementById('teacherAiDrawer');
        if (root) root.classList.remove('is-open');
        if (drawer) drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('teacher-ai-open');
    };
    (function () {
        var teacherAiRoot = document.getElementById('teacherAiRoot');
        if (!teacherAiRoot || !window.MutationObserver) return;
        new MutationObserver(function () {
            document.body.classList.toggle('teacher-ai-open', teacherAiRoot.classList.contains('is-open'));
        }).observe(teacherAiRoot, { attributes: true, attributeFilter: ['class'] });
        function forceCloseTeacherAi() {
            teacherAiRoot.classList.remove('is-open');
            var drawer = document.getElementById('teacherAiDrawer');
            if (drawer) drawer.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('teacher-ai-open');
        }
        document.getElementById('teacherAiClose').addEventListener('click', forceCloseTeacherAi);
        document.getElementById('teacherAiMinimize').addEventListener('click', forceCloseTeacherAi);
        document.getElementById('teacherAiScrim').addEventListener('click', forceCloseTeacherAi);
    })();
</script>
<?php endif; ?>
