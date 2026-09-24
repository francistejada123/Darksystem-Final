/*
 * E-GRADEPRO Teacher AI - generated resources.
 *
 * Renders what Teacher AI generates (a lesson, quiz, activity, assessment or
 * learning material) and gives the teacher the same options as Ask AI:
 *   1. Create it as PDF / Word / PowerPoint / Google Form
 *   2. On each created file: Preview/Open, Download, Regenerate, Send to Students
 *   3. Send to Students: pick Grade Level -> Section -> Subject -> Term (only the
 *      teacher's own assigned classes are ever offered), then Send to Selected
 *      Students or Send to All Students in the Grade/Section.
 *
 * All rules are enforced on the server (modules/teachers/teacher_ai_resource.php);
 * this file only draws the interface. teacher-ai.js owns the conversation and
 * calls TeacherAiResources.createMessage() for each resource turn.
 */
(function () {
    'use strict';
    var root = document.getElementById('teacherAiRoot');
    if (!root) return;

    var FORMATS = [
        { key: 'pdf', label: 'PDF', icon: 'fa-file-pdf' },
        { key: 'docx', label: 'Word', icon: 'fa-file-word' },
        { key: 'pptx', label: 'PowerPoint', icon: 'fa-file-powerpoint' },
        { key: 'gform', label: 'Google Form', icon: 'fa-square-poll-vertical' }
    ];
    var classData = null;

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function el(tag, cls, html) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        if (html != null) node.innerHTML = html;
        return node;
    }
    function formatLabel(key) {
        for (var i = 0; i < FORMATS.length; i++) if (FORMATS[i].key === key) return FORMATS[i].label;
        return key;
    }
    function safeIcon(icon) { return /^fa-[a-z-]+$/.test(icon || '') ? icon : 'fa-file'; }
    function safeHttps(url) { return /^https:\/\//i.test(url || '') ? url : ''; }
    function fileUrl(id, mode) {
        return root.dataset.resourceFileEndpoint + '?id=' + encodeURIComponent(id) + '&mode=' + mode;
    }
    function unique(list, keyFn) {
        var seen = {}, out = [];
        list.forEach(function (item) {
            var key = keyFn(item);
            if (!seen[key]) { seen[key] = true; out.push(item); }
        });
        return out;
    }

    function post(payload) {
        payload.csrf_token = root.dataset.csrf;
        return fetch(root.dataset.resourceEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrf },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, error: 'The server sent an unexpected reply. Please try again.' };
            });
        }).catch(function () {
            return { success: false, error: 'Could not reach the server. Please check your connection and try again.' };
        });
    }

    function loadClasses() {
        if (classData) return Promise.resolve(classData);
        return post({ action: 'classes' }).then(function (data) {
            if (data && data.success) classData = data;
            return data;
        });
    }

    // ------------------------------------------------------------------
    // Send to Students panel (one per created file/Form)
    // ------------------------------------------------------------------
    function buildSendPanel(card, hooks) {
        var panel = el('div', 'tai-send');
        panel.hidden = true;
        var built = false;

        function init() {
            panel.innerHTML = '<p class="tai-muted"><i class="fas fa-spinner fa-spin"></i> Loading your classes...</p>';
            loadClasses().then(function (data) {
                if (!data || !data.success) {
                    built = false;
                    panel.innerHTML = '<p class="tai-error">' + esc((data && data.error) || 'Could not load your classes.') + '</p>';
                    return;
                }
                // Made in subject mode: only that subject's classes are offered
                // (the server enforces the same rule on send).
                if (card.subject_code) {
                    var only = data.classes.filter(function (c) { return c.subject_code === card.subject_code; });
                    data = { success: true, school_year: data.school_year, terms: data.terms, current_term: data.current_term, classes: only };
                    if (!only.length) {
                        panel.innerHTML = '<p class="tai-muted">You have no class for the subject this was made for, so there is nobody to send it to.</p>';
                        return;
                    }
                }
                // Made from "Send to <item> AI": only that item's own class and term.
                if (card.assessment) {
                    var a = card.assessment;
                    var mine = data.classes.filter(function (c) { return c.section_id === a.section_id && c.subject_code === a.subject_code; })
                        .map(function (c) { var copy = {}; for (var k in c) copy[k] = c[k]; copy.terms = (c.terms || []).filter(function (t) { return t === a.term; }); return copy; });
                    data = { success: true, school_year: data.school_year, terms: [a.term], current_term: a.term, classes: mine };
                    if (!mine.length) {
                        panel.innerHTML = '<p class="tai-muted">' + esc(a.label) + ' is not one of your classes this school year, so there is nobody to send it to.</p>';
                        return;
                    }
                }
                if (!data.classes.length) {
                    panel.innerHTML = '<p class="tai-muted">You have no classes assigned to you this school year, so there is nobody to send this to.</p>';
                    return;
                }
                render(data);
            });
        }

        function render(data) {
            var state = { grade: '', sectionId: 0, subject: '', term: '', students: [], selected: {}, busy: false, token: 0, armed: '' };
            var armTimer = null;

            panel.innerHTML =
                '<div class="tai-send-title"><i class="fas fa-paper-plane"></i> Send "' + esc(card.title) + '" to students</div>' +
                '<div class="tai-grid">' +
                '<label>Grade Level<select class="tai-select" data-f="grade"></select></label>' +
                '<label>Section<select class="tai-select" data-f="section"></select></label>' +
                '<label>Subject<select class="tai-select" data-f="subject"></select></label>' +
                '<label>Term<select class="tai-select" data-f="term"></select></label>' +
                '</div>' +
                '<div class="tai-roster" data-f="roster"><p class="tai-muted">Choose a grade level, section, subject and term to see your students.</p></div>' +
                '<div class="tai-record" data-f="record">' +
                '<div class="tai-record-title"><i class="fas fa-table-list"></i> Record in Grade Management</div>' +
                '<div class="tai-record-row">' +
                '<label>Save under<select class="tai-select" data-f="rcat"></select></label>' +
                '<label data-f="rsubwrap" hidden>Examination<select class="tai-select" data-f="rsub"></select></label>' +
                '<label data-f="rcompwrap" hidden>Component<select class="tai-select" data-f="rcomp"></select></label>' +
                '<label data-f="rhpswrap" hidden>Highest score<input type="number" class="tai-input tai-input-num" data-f="rhps" min="1" max="9999" step="1" value="10"></label>' +
                '</div>' +
                '<p class="tai-record-hint" data-f="rhint"></p>' +
                '</div>' +
                '<label class="tai-note-label">Message to students (optional)<textarea class="tai-input" data-f="note" rows="2" maxlength="500" placeholder="e.g. Please finish this before Friday."></textarea></label>' +
                '<div class="tai-send-actions">' +
                '<button type="button" class="tai-btn" data-f="sel"></button>' +
                '<button type="button" class="tai-btn tai-btn-primary" data-f="all"></button>' +
                '</div>' +
                '<div class="tai-status" data-f="status" role="status" aria-live="polite"></div>';

            function q(name) { return panel.querySelector('[data-f="' + name + '"]'); }
            var selGrade = q('grade'), selSection = q('section'), selSubject = q('subject'), selTerm = q('term');
            var rosterBox = q('roster'), noteBox = q('note'), btnSel = q('sel'), btnAll = q('all'), statusBox = q('status');
            var rcat = q('rcat'), rsub = q('rsub'), rcomp = q('rcomp'), rhps = q('rhps'), rhint = q('rhint');
            var rsubWrap = q('rsubwrap'), rcompWrap = q('rcompwrap'), rhpsWrap = q('rhpswrap');
            var rec = card.record || { category: 'none', subtype: null, has_quiz: false, max_score: 0 };
            var CAT_LABELS = { WW: 'Written/Oral Works', PT: 'Performance Tasks', QA: 'Examinations' };
            var SUB_LABELS = { ST1: 'ST1 - Summative Test 1', ST2: 'ST2 - Summative Test 2', TE: 'TE - Term Examination' };
            var recState = { category: rec.category || 'none', subtype: rec.subtype || '', component: '', meta: null, metaToken: 0 };

            function fill(select, options, placeholder, current) {
                select.innerHTML = '<option value="">' + esc(placeholder) + '</option>' + options.map(function (o) {
                    return '<option value="' + esc(o.value) + '">' + esc(o.label) + '</option>';
                }).join('');
                select.value = current ? String(current) : '';
            }
            function classRow() {
                for (var i = 0; i < data.classes.length; i++) {
                    var c = data.classes[i];
                    if (c.grade_level === state.grade && c.section_id === state.sectionId && c.subject_code === state.subject) return c;
                }
                return null;
            }
            function sectionName() {
                var c = classRow();
                return c ? c.section_name : '';
            }

            // ---- Record in Grade Management -------------------------------
            function renderRecord() {
                if (card.assessment) {
                    // Fixed: the item the teacher created in Manage Assessment Items.
                    var a = card.assessment;
                    q('record').querySelector('.tai-record-row').hidden = true;
                    recState.category = a.records_score ? 'WW' : 'none';
                    rhint.textContent = a.records_score
                        ? 'Scores go to ' + a.item_name + ' (' + a.category_label + ', HPS ' + a.hps + ') — the item you created in Manage Assessment Items. ' +
                          (rec.has_quiz ? 'Students answer online; the score is recorded automatically.' : 'Check the answers in Student Submissions; the score is then recorded.')
                        : a.item_name + ' (' + a.category_label + ') is scored by you: encode the score in Grade Management.';
                    return;
                }
                var meta = recState.meta;
                var opts = [{ v: 'WW', l: CAT_LABELS.WW }, { v: 'PT', l: CAT_LABELS.PT }];
                if (!meta || meta.has_exam) opts.push({ v: 'QA', l: CAT_LABELS.QA });
                opts.push({ v: 'none', l: "Don't record (practice / study material)" });
                if (!opts.some(function (o) { return o.v === recState.category; })) recState.category = 'none';
                rcat.innerHTML = opts.map(function (o) { return '<option value="' + o.v + '">' + esc(o.l) + '</option>'; }).join('');
                rcat.value = recState.category;

                var isRec = recState.category !== 'none';
                var subs = (meta && meta.required_subtypes && meta.required_subtypes.length) ? meta.required_subtypes : ['ST1', 'ST2', 'TE'];
                rsubWrap.hidden = !(recState.category === 'QA');
                if (recState.category === 'QA') {
                    if (subs.indexOf(recState.subtype) < 0) recState.subtype = subs.indexOf('ST1') >= 0 && !(meta && meta.required_subtypes && meta.required_subtypes.length === 1) ? 'ST1' : subs[0];
                    rsub.innerHTML = subs.map(function (t) { return '<option value="' + t + '">' + esc(SUB_LABELS[t] || t) + '</option>'; }).join('');
                    rsub.value = recState.subtype;
                }
                var multi = !!(meta && meta.is_multi && meta.components && meta.components.length);
                rcompWrap.hidden = !(isRec && multi);
                if (isRec && multi) {
                    if (meta.components.indexOf(recState.component) < 0) recState.component = '';
                    rcomp.innerHTML = '<option value="">Select component</option>' + meta.components.map(function (c) { return '<option value="' + esc(c) + '">' + esc(c) + '</option>'; }).join('');
                    rcomp.value = recState.component;
                }
                rhpsWrap.hidden = !(isRec && !rec.has_quiz);

                var hint;
                if (!isRec) {
                    hint = "Not recorded. Students can still open it, but no score is added to Manage Assessment Items or Grade Management.";
                } else if (rec.has_quiz) {
                    hint = 'Saved automatically in Manage Assessment Items under ' + CAT_LABELS[recState.category] + ' (' + (rec.max_score || 'quiz total') + ' pts). When a student submits, the actual score is recorded' +
                        (recState.category === 'PT' ? '. Performance Tasks stay teacher-encoded, so you encode the term score yourself.' : ' and the grade is updated. One attempt only unless you allow a retake.');
                } else {
                    hint = 'Saved automatically in Manage Assessment Items under ' + CAT_LABELS[recState.category] + '. This is not auto-graded: after you check a student\'s answer in Student Submissions, the score is recorded.';
                }
                rhint.textContent = hint;
            }
            function loadRecordMeta() {
                var c = classRow();
                recState.meta = null;
                recState.metaToken += 1;
                if (!c || !state.term) { renderRecord(); return; }
                var token = recState.metaToken;
                post({ action: 'record_meta', grade_level: state.grade, section_id: c.section_id, subject_code: c.subject_code, term: state.term }).then(function (r) {
                    if (token !== recState.metaToken) return;
                    recState.meta = (r && r.success) ? r : null;
                    renderRecord();
                });
            }
            rcat.addEventListener('change', function () { recState.category = rcat.value; setStatus(''); renderRecord(); });
            rsub.addEventListener('change', function () { recState.subtype = rsub.value; });
            rcomp.addEventListener('change', function () { recState.component = rcomp.value; setStatus(''); });

            function setStatus(html, kind) {
                statusBox.className = 'tai-status' + (kind ? ' is-' + kind : '');
                statusBox.innerHTML = html || '';
            }
            function selectedIds() {
                return state.students.filter(function (s) { return state.selected[s.student_id]; }).map(function (s) { return s.student_id; });
            }
            function disarm() {
                clearTimeout(armTimer);
                state.armed = '';
                updateButtons();
            }
            function updateButtons() {
                var ready = !!(classRow() && state.term && state.students.length);
                var count = selectedIds().length;
                var total = state.students.length;
                btnSel.disabled = state.busy || !ready || count === 0;
                btnAll.disabled = state.busy || !ready;
                btnSel.classList.toggle('is-confirm', state.armed === 'selected');
                btnAll.classList.toggle('is-confirm', state.armed === 'all');
                btnSel.textContent = state.armed === 'selected'
                    ? 'Confirm: send to ' + count + ' student' + (count === 1 ? '' : 's')
                    : 'Send to Selected Students' + (ready ? ' (' + count + ')' : '');
                btnAll.textContent = state.armed === 'all'
                    ? 'Confirm: send to all ' + total + ' student' + (total === 1 ? '' : 's')
                    : 'Send to All Students in ' + (state.grade ? state.grade + ' - ' + sectionName() : 'Grade/Section') + (ready ? ' (' + total + ')' : '');
            }

            function renderRoster() {
                if (!classRow() || !state.term) {
                    rosterBox.innerHTML = '<p class="tai-muted">Choose a grade level, section, subject and term to see your students.</p>';
                    return;
                }
                if (!state.students.length) {
                    rosterBox.innerHTML = '<p class="tai-muted">No enrolled students were found in this class yet.</p>';
                    return;
                }
                rosterBox.innerHTML =
                    '<div class="tai-roster-head"><span>' + state.students.length + ' student' + (state.students.length === 1 ? '' : 's') + ' in this class</span>' +
                    '<span><button type="button" class="tai-link" data-r="all">Select all</button> · <button type="button" class="tai-link" data-r="none">Clear</button></span></div>' +
                    '<div class="tai-students">' + state.students.map(function (s) {
                        return '<label class="tai-student"><input type="checkbox" value="' + s.student_id + '"' + (state.selected[s.student_id] ? ' checked' : '') + '> <span>' + esc(s.name) + '</span></label>';
                    }).join('') + '</div>';
            }
            function loadRoster() {
                var c = classRow();
                state.students = [];
                state.selected = {};
                state.token += 1;
                disarm();
                if (!c || !state.term) { renderRoster(); updateButtons(); return; }
                var token = state.token;
                rosterBox.innerHTML = '<p class="tai-muted"><i class="fas fa-spinner fa-spin"></i> Loading students...</p>';
                updateButtons();
                post({ action: 'roster', grade_level: state.grade, section_id: c.section_id, subject_code: c.subject_code, term: state.term }).then(function (r) {
                    if (token !== state.token) return;
                    if (!r.success) {
                        rosterBox.innerHTML = '<p class="tai-error">' + esc(r.error || 'Could not load the students.') + '</p>';
                        updateButtons();
                        return;
                    }
                    state.students = r.students || [];
                    renderRoster();
                    updateButtons();
                });
            }

            function refreshTerms() {
                var c = classRow();
                var terms = c ? c.terms : [];
                if (state.term && terms.indexOf(state.term) < 0) state.term = '';
                if (!state.term && terms.length) {
                    state.term = (data.current_term && terms.indexOf(data.current_term) >= 0) ? data.current_term : (terms.length === 1 ? terms[0] : '');
                }
                fill(selTerm, terms.map(function (t) { return { value: t, label: t }; }), 'Select term', state.term);
                loadRoster();
                loadRecordMeta();
            }
            function refreshSubjects() {
                var rows = data.classes.filter(function (c) { return c.grade_level === state.grade && c.section_id === state.sectionId; });
                var subjects = unique(rows, function (c) { return c.subject_code; });
                if (subjects.every(function (c) { return c.subject_code !== state.subject; })) state.subject = '';
                if (!state.subject && subjects.length === 1) state.subject = subjects[0].subject_code;
                fill(selSubject, subjects.map(function (c) { return { value: c.subject_code, label: c.subject_name }; }), 'Select subject', state.subject);
                refreshTerms();
            }
            function refreshSections() {
                var rows = data.classes.filter(function (c) { return c.grade_level === state.grade; });
                var sections = unique(rows, function (c) { return c.section_id; });
                if (sections.every(function (c) { return c.section_id !== state.sectionId; })) state.sectionId = 0;
                if (!state.sectionId && sections.length === 1) state.sectionId = sections[0].section_id;
                fill(selSection, sections.map(function (c) { return { value: c.section_id, label: c.section_name + (c.strand ? ' (' + c.strand + ')' : '') }; }), 'Select section', state.sectionId);
                refreshSubjects();
            }
            function refreshGrades() {
                var grades = unique(data.classes, function (c) { return c.grade_level; });
                if (!state.grade && grades.length === 1) state.grade = grades[0].grade_level;
                fill(selGrade, grades.map(function (c) { return { value: c.grade_level, label: c.grade_level }; }), 'Select grade level', state.grade);
                refreshSections();
            }

            selGrade.addEventListener('change', function () { state.grade = selGrade.value; state.sectionId = 0; state.subject = ''; state.term = ''; setStatus(''); refreshSections(); });
            selSection.addEventListener('change', function () { state.sectionId = parseInt(selSection.value, 10) || 0; state.subject = ''; state.term = ''; setStatus(''); refreshSubjects(); });
            selSubject.addEventListener('change', function () { state.subject = selSubject.value; state.term = ''; setStatus(''); refreshTerms(); });
            selTerm.addEventListener('change', function () { state.term = selTerm.value; setStatus(''); loadRoster(); loadRecordMeta(); });

            rosterBox.addEventListener('change', function (event) {
                if (event.target && event.target.type === 'checkbox') {
                    state.selected[event.target.value] = event.target.checked;
                    disarm();
                }
            });
            rosterBox.addEventListener('click', function (event) {
                var action = event.target && event.target.getAttribute('data-r');
                if (!action) return;
                state.students.forEach(function (s) { state.selected[s.student_id] = action === 'all'; });
                renderRoster();
                disarm();
            });

            function doSend(scope) {
                var c = classRow();
                if (!c) return;
                state.busy = true;
                disarm();
                setStatus('<i class="fas fa-spinner fa-spin"></i> Sending...');
                post({
                    action: 'send',
                    resource_id: card.id,
                    grade_level: state.grade,
                    section_id: c.section_id,
                    subject_code: c.subject_code,
                    term: state.term,
                    scope: scope,
                    student_ids: scope === 'selected' ? selectedIds() : [],
                    note: noteBox.value,
                    record_category: recState.category,
                    record_subtype: recState.category === 'QA' ? recState.subtype : '',
                    record_component: recState.category !== 'none' ? recState.component : '',
                    record_hps: recState.category !== 'none' && !rec.has_quiz ? (parseFloat(rhps.value) || 0) : 0
                }).then(function (r) {
                    state.busy = false;
                    updateButtons();
                    if (!r.success) {
                        setStatus(esc(r.error || 'Could not send. Please try again.'), 'error');
                        return;
                    }
                    setStatus('<i class="fas fa-circle-check"></i> ' + esc(r.message || 'Sent.'), 'ok');
                    card.sent_count = r.sent_count || card.sent_count;
                    hooks.cardSent(card);
                });
            }
            function arm(scope) {
                if (state.armed === scope) { doSend(scope); return; }
                if (recState.category !== 'none' && recState.meta && recState.meta.is_multi && !recState.component) {
                    setStatus('Select which component this belongs to (' + recState.meta.components.join(' / ') + ') before sending.', 'error');
                    rcomp.focus();
                    return;
                }
                if (recState.category !== 'none' && !rec.has_quiz && !(parseFloat(rhps.value) > 0)) {
                    setStatus('Enter the highest possible score for this activity.', 'error');
                    rhps.focus();
                    return;
                }
                clearTimeout(armTimer);
                state.armed = scope;
                updateButtons();
                armTimer = setTimeout(disarm, 7000);
            }
            btnSel.addEventListener('click', function () { arm('selected'); });
            btnAll.addEventListener('click', function () { arm('all'); });

            renderRecord();
            refreshGrades();
        }

        return {
            el: panel,
            toggle: function () {
                panel.hidden = !panel.hidden;
                if (!panel.hidden && !built) { built = true; init(); }
                return !panel.hidden;
            }
        };
    }

    // ------------------------------------------------------------------
    // File / Form card
    // ------------------------------------------------------------------
    function renderCard(card, hooks) {
        var wrap = el('div', 'tai-card');
        wrap.dataset.cardId = card.id;
        wrap.dataset.format = card.format;

        var meta = [card.type_label, card.format_label];
        if (card.file_size) meta.push(card.file_size);
        if (card.question_count) meta.push(card.question_count + ' questions');
        if (card.max_score) meta.push(card.max_score + ' pts');

        var head = el('div', 'tai-card-head',
            '<div class="tai-card-icon tai-fmt-' + esc(card.format) + '"><i class="fas ' + safeIcon(card.icon) + '"></i></div>' +
            '<div class="tai-card-info"><b>' + esc(card.title) + '</b><small>' + esc(meta.filter(Boolean).join(' • ')) + '</small></div>' +
            '<span class="tai-pill"></span>');
        wrap.appendChild(head);
        var pill = head.querySelector('.tai-pill');
        function refreshPill() {
            pill.textContent = card.sent_count > 0 ? 'Sent to ' + card.sent_count : 'Not sent yet';
            pill.classList.toggle('is-sent', card.sent_count > 0);
        }
        refreshPill();

        var actions = el('div', 'tai-card-actions');
        function link(label, icon, href, sameTab) {
            var a = el('a', 'tai-btn', '<i class="fas ' + icon + '"></i> ' + esc(label));
            a.href = href;
            if (!sameTab) { a.target = '_blank'; a.rel = 'noopener noreferrer'; }
            actions.appendChild(a);
            return a;
        }
        if (card.format === 'gform') {
            var viewUrl = safeHttps(card.form_view_url), editUrl = safeHttps(card.form_edit_url);
            if (viewUrl) link('Preview / Open Form', 'fa-arrow-up-right-from-square', viewUrl);
            if (editUrl) link('Edit in Google Forms', 'fa-pen', editUrl);
        } else {
            link('Preview / Open', 'fa-eye', fileUrl(card.id, 'view'));
            link('Download', 'fa-download', fileUrl(card.id, 'download'), true);
        }
        if (card.has_answer_key) link('Answer Key', 'fa-key', fileUrl(card.id, 'key'));

        var regen = el('button', 'tai-btn', '<i class="fas fa-rotate"></i> Regenerate');
        regen.type = 'button';
        regen.title = 'Write a fresh version and create it again';
        regen.addEventListener('click', function () { hooks.regenerate(card.id, card.format, card.title); });
        actions.appendChild(regen);

        var sendBtn = el('button', 'tai-btn tai-btn-primary', '<i class="fas fa-paper-plane"></i> Send to Students');
        sendBtn.type = 'button';
        actions.appendChild(sendBtn);
        wrap.appendChild(actions);

        var panel = buildSendPanel(card, {
            cardSent: function (c) { refreshPill(); hooks.persist(); }
        });
        wrap.appendChild(panel.el);
        sendBtn.addEventListener('click', function () {
            var open = panel.toggle();
            sendBtn.classList.toggle('is-open', open);
            if (open) panel.el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        });
        return wrap;
    }

    // ------------------------------------------------------------------
    // The chat message for one generated resource
    // ------------------------------------------------------------------
    /**
     * @param {Object} turn   {reply|text, resource:{draft:{draft_id,title,type_label,content?,quiz_count?}, cards:[], compact?}}
     * @param {Object} hooks  {format(text)->html, persist(), regenerate(resourceId, format, title), copy(text)}
     * @returns {HTMLElement} the message element; el.taiCreate(format) creates a file/Form for it
     */
    function createMessage(turn, hooks) {
        var res = turn.resource;
        res.cards = res.cards || [];
        var draft = res.draft;
        var msg = el('article', 'teacher-ai-message ai tai-resource');
        msg.appendChild(el('div', 'tai-intro', hooks.format(turn.reply || turn.text || '')));

        var statusBox = el('div', 'tai-create-status');
        var cardsBox = el('div', 'tai-cards');
        var createButtons = [];
        var busy = false;

        function setCreateStatus(text, isError) {
            statusBox.className = 'tai-create-status' + (isError ? ' is-error' : '');
            statusBox.textContent = text || '';
        }
        function addCard(card, scroll) {
            var node = renderCard(card, {
                persist: hooks.persist,
                regenerate: hooks.regenerate
            });
            cardsBox.appendChild(node);
            if (scroll) node.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            return node;
        }
        function create(format) {
            if (busy) return Promise.resolve();
            for (var i = 0; i < res.cards.length; i++) {
                if (res.cards[i].format === format) {
                    var existing = cardsBox.querySelector('[data-card-id="' + res.cards[i].id + '"]');
                    if (existing) {
                        existing.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                        existing.classList.add('is-flash');
                        setTimeout(function () { existing.classList.remove('is-flash'); }, 1200);
                    }
                    setCreateStatus('You already created the ' + formatLabel(format) + ' version below. Use Regenerate for a new one.');
                    return Promise.resolve();
                }
            }
            busy = true;
            createButtons.forEach(function (b) { b.disabled = true; });
            setCreateStatus('Creating ' + formatLabel(format) + '...' + ((format === 'pptx' || format === 'gform') ? ' this can take up to a minute.' : ''));
            statusBox.classList.add('is-working');
            return post({ action: 'create_output', resource_id: draft.draft_id, format: format }).then(function (r) {
                busy = false;
                statusBox.classList.remove('is-working');
                createButtons.forEach(function (b) { b.disabled = false; });
                if (!r.success) {
                    setCreateStatus(r.error || 'Could not create the ' + formatLabel(format) + '.', true);
                    return;
                }
                setCreateStatus('');
                res.cards.push(r.card);
                addCard(r.card, true);
                hooks.persist();
            });
        }

        if (!res.compact) {
            var doc = el('div', 'tai-doc');
            var long = (draft.content || '').length > 700;
            doc.appendChild(el('div', 'tai-doc-head',
                '<h3>' + esc(draft.title) + '</h3><span class="tai-type">' + esc(draft.type_label) + (draft.quiz_count ? ' • ' + draft.quiz_count + ' questions' : '') + '</span>'));
            var docBody = el('div', 'tai-doc-body' + (long ? ' is-collapsed' : ''), hooks.format(draft.content || ''));
            doc.appendChild(docBody);
            if (long) {
                var toggle = el('button', 'tai-link tai-doc-toggle', 'Show full content');
                toggle.type = 'button';
                toggle.addEventListener('click', function () {
                    var collapsed = docBody.classList.toggle('is-collapsed');
                    toggle.textContent = collapsed ? 'Show full content' : 'Show less';
                });
                doc.appendChild(toggle);
            }
            msg.appendChild(doc);

            var bar = el('div', 'tai-create');
            bar.appendChild(el('span', 'tai-create-label', 'Create as:'));
            FORMATS.forEach(function (f) {
                var b = el('button', 'tai-btn', '<i class="fas ' + f.icon + '"></i> ' + esc(f.label));
                b.type = 'button';
                b.title = f.key === 'gform' ? 'Create an auto-graded Google Form' : 'Create a ' + f.label + ' file';
                b.addEventListener('click', function () { create(f.key); });
                bar.appendChild(b);
                createButtons.push(b);
            });
            msg.appendChild(bar);
        }
        msg.appendChild(statusBox);

        res.cards.forEach(function (card) { addCard(card, false); });
        msg.appendChild(cardsBox);

        if (!res.compact) {
            var acts = el('div', 'teacher-ai-message-actions');
            var copy = el('button', null, '<i class="fas fa-copy"></i> Copy');
            copy.type = 'button';
            copy.addEventListener('click', function () { hooks.copy(draft.title + '\n\n' + (draft.content || '')); });
            acts.appendChild(copy);
            var again = el('button', null, '<i class="fas fa-rotate"></i> Regenerate');
            again.type = 'button';
            again.addEventListener('click', function () { hooks.regenerate(draft.draft_id, '', draft.title); });
            acts.appendChild(again);
            msg.appendChild(acts);
        }

        msg.taiCreate = create;
        return msg;
    }

    window.TeacherAiResources = { createMessage: createMessage, formatLabel: formatLabel };
})();
